<?php

namespace App\Http\Controllers;

use App\Models\Guru;
use App\Models\Kelas;
use App\Services\PdfCacheService;
use App\Services\SiswaKelasSemesterResolver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Throwable;

class GuruSignatureController extends Controller
{
    private const DISK = 'local';

    private const DIRECTORY = 'private/guru-signatures';

    private const ERROR_BAG = 'signatureUpload';

    public function show(Guru $guru): BinaryFileResponse
    {
        abort_if(blank($guru->signature_path), 404);
        abort_unless(Storage::disk(self::DISK)->exists($guru->signature_path), 404);

        $path = Storage::disk(self::DISK)->path($guru->signature_path);
        abort_unless(is_file($path) && is_readable($path), 404);

        $mime = mime_content_type($path) ?: 'application/octet-stream';
        abort_unless(in_array($mime, ['image/png', 'image/jpeg', 'image/webp'], true), 404);

        return response()->file($path, [
            'Content-Type' => $mime,
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function store(Request $request, Guru $guru): RedirectResponse
    {
        $file = $request->file('signature');

        if (! $file instanceof UploadedFile) {
            return $this->backWithSignatureError('File tanda tangan tidak diterima. Silakan pilih gambar terlebih dahulu.');
        }

        if (! $file->isValid()) {
            $this->logSignatureUploadFailure('Upload tanda tangan guru tidak valid.', null, $request, $file);

            return $this->backWithSignatureError($this->uploadErrorMessage($file->getError()));
        }

        if (! $this->temporaryUploadIsReadable($file)) {
            $this->logSignatureUploadFailure('File sementara tanda tangan guru tidak dapat dibaca.', null, $request, $file);

            return $this->backWithSignatureError('File tanda tangan tidak dapat dibaca dari penyimpanan sementara. Silakan pilih ulang file atau periksa konfigurasi folder temporary PHP.');
        }

        try {
            $request->validateWithBag(
                self::ERROR_BAG,
                [
                    'signature' => [
                        'required',
                        'file',
                        'image',
                        'mimes:png,jpg,jpeg,webp',
                        'mimetypes:image/png,image/jpeg,image/webp',
                        'max:1024',
                    ],
                ],
                [
                    'signature.required' => 'Pilih gambar tanda tangan terlebih dahulu.',
                    'signature.file' => 'File tanda tangan tidak valid.',
                    'signature.image' => 'File tanda tangan harus berupa gambar.',
                    'signature.mimes' => 'Format tanda tangan harus PNG, JPG, JPEG, atau WebP.',
                    'signature.mimetypes' => 'Format tanda tangan harus PNG, JPG, JPEG, atau WebP.',
                    'signature.max' => 'Ukuran tanda tangan maksimal 1 MB.',
                    'signature.uploaded' => 'Upload tanda tangan gagal. Silakan pilih file lain.',
                ]
            );
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            $this->logSignatureUploadFailure('Validasi tanda tangan guru gagal.', $exception, $request, $file);

            return $this->backWithSignatureError('File tanda tangan tidak dapat divalidasi. Silakan pilih ulang file.');
        }

        $mime = $this->detectMimeType($file);
        $extension = $this->extensionForMime($mime);

        if (! $extension) {
            $this->logSignatureUploadFailure('Format MIME tanda tangan guru tidak didukung.', null, $request, $file);

            return $this->backWithSignatureError('Format tanda tangan harus PNG, JPG, JPEG, atau WebP.');
        }

        $newPath = self::DIRECTORY.'/'.Str::uuid().'.'.$extension;

        $sourcePath = $this->safePathname($file);

        if (! is_string($sourcePath) || $sourcePath === '' || ! is_file($sourcePath) || ! is_readable($sourcePath)) {
            $this->logSignatureUploadFailure('File sementara tanda tangan guru tidak dapat dibaca sebelum penyimpanan.', null, $request, $file);

            return $this->backWithSignatureError('File tanda tangan tidak dapat dibaca dari penyimpanan sementara. Silakan pilih ulang file atau periksa konfigurasi folder temporary PHP.');
        }

        $stream = @fopen($sourcePath, 'rb');

        if ($stream === false) {
            $this->logSignatureUploadFailure('Gagal membuka stream file sementara tanda tangan guru.', null, $request, $file);

            return $this->backWithSignatureError('File tanda tangan tidak dapat dibaca dari penyimpanan sementara. Silakan pilih ulang file atau periksa konfigurasi folder temporary PHP.');
        }

        try {
            $stored = Storage::disk(self::DISK)->writeStream($newPath, $stream);
        } catch (Throwable $exception) {
            $this->logSignatureUploadFailure('Gagal menyimpan file tanda tangan guru.', $exception, $request, $file);

            return $this->backWithSignatureError('Tanda tangan gagal disimpan ke penyimpanan privat. Silakan pilih ulang file.');
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        if ($stored === false) {
            $this->logSignatureUploadFailure('Penyimpanan tanda tangan guru mengembalikan hasil kosong.', null, $request, $file);

            return $this->backWithSignatureError('Tanda tangan gagal disimpan ke penyimpanan privat. Silakan coba lagi.');
        }

        $oldPath = $guru->signature_path;

        try {
            DB::transaction(function () use ($guru, $newPath) {
                $guru->forceFill(['signature_path' => $newPath])->save();
            });
        } catch (Throwable $exception) {
            Storage::disk(self::DISK)->delete($newPath);

            $this->logSignatureUploadFailure('Gagal memperbarui data tanda tangan guru.', $exception, $request, $file);

            return $this->backWithSignatureError('Tanda tangan gagal disimpan ke data guru. Silakan coba lagi.');
        }

        if ($oldPath && $oldPath !== $newPath && Storage::disk(self::DISK)->exists($oldPath)) {
            if (! Storage::disk(self::DISK)->delete($oldPath)) {
                Log::warning('Gagal menghapus file tanda tangan guru lama setelah penggantian.', [
                    'path_hash' => sha1($oldPath),
                ]);
            }
        }

        $this->invalidateRelatedReportCachesSafely($guru->fresh());

        return back()
            ->with('success', 'Tanda tangan guru berhasil disimpan.')
            ->with('signatureUploadSuccess', 'Tanda tangan guru berhasil disimpan.');
    }

    private function extensionForMime(?string $mime): ?string
    {
        return match ($mime) {
            'image/png' => 'png',
            'image/jpeg' => 'jpg',
            'image/webp' => 'webp',
            default => null,
        };
    }

    private function temporaryUploadIsReadable(UploadedFile $file): bool
    {
        $pathname = $this->safePathname($file);

        return is_string($pathname) && $pathname !== '' && is_file($pathname) && is_readable($pathname);
    }

    private function detectMimeType(UploadedFile $file): ?string
    {
        $pathname = $this->safePathname($file);

        if (! is_string($pathname) || $pathname === '') {
            return null;
        }

        try {
            $finfo = new \finfo(FILEINFO_MIME_TYPE);
            $mime = $finfo->file($pathname);

            return is_string($mime) ? $mime : null;
        } catch (Throwable $exception) {
            $this->logSignatureUploadFailure('Deteksi MIME tanda tangan guru gagal.', $exception, request(), $file);

            return null;
        }
    }

    private function safePathname(UploadedFile $file): ?string
    {
        try {
            return $file->getPathname();
        } catch (Throwable) {
            return null;
        }
    }

    private function backWithSignatureError(string $message): RedirectResponse
    {
        return back()->withErrors(['signature' => $message], self::ERROR_BAG);
    }

    private function uploadErrorMessage(int $errorCode): string
    {
        return match ($errorCode) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'Ukuran tanda tangan maksimal 1 MB.',
            UPLOAD_ERR_NO_FILE => 'Pilih gambar tanda tangan terlebih dahulu.',
            default => 'Upload tanda tangan tidak valid. Silakan pilih file lain.',
        };
    }

    private function logSignatureUploadFailure(
        string $message,
        ?Throwable $exception,
        Request $request,
        ?UploadedFile $file
    ): void {
        $context = $this->uploadDiagnostics($request, $file);

        if ($exception) {
            $context['exception'] = $exception::class;
            $context['message'] = $this->sanitizeDiagnosticMessage($exception->getMessage(), $file);
            $context['source_file'] = basename($exception->getFile());
            $context['source_line'] = $exception->getLine();
        }

        Log::warning($message, $context);
    }

    private function uploadDiagnostics(Request $request, ?UploadedFile $file): array
    {
        $pathname = $file ? $this->safePathname($file) : null;
        $pathnamePresent = is_string($pathname) && $pathname !== '';

        return [
            'has_file' => $request->hasFile('signature'),
            'is_valid' => $file ? $this->safeIsValid($file) : null,
            'upload_error' => $file?->getError(),
            'size' => $file ? $this->safeFileSize($file) : null,
            'mime' => $file && $pathnamePresent && is_file($pathname) && is_readable($pathname)
                ? $this->safeDetectedMime($pathname)
                : null,
            'pathname_present' => $pathnamePresent,
            'pathname_exists' => $pathnamePresent ? is_file($pathname) : false,
            'pathname_readable' => $pathnamePresent ? is_readable($pathname) : false,
            'upload_tmp_dir_configured' => filled((string) ini_get('upload_tmp_dir')),
            'sys_temp_dir_exists' => is_dir(sys_get_temp_dir()),
            'sys_temp_dir_writable' => is_writable(sys_get_temp_dir()),
            'php_ini_loaded' => filled(php_ini_loaded_file()),
        ];
    }

    private function safeIsValid(UploadedFile $file): ?bool
    {
        try {
            return $file->isValid();
        } catch (Throwable) {
            return null;
        }
    }

    private function safeFileSize(UploadedFile $file): ?int
    {
        try {
            $size = $file->getSize();

            return is_int($size) ? $size : null;
        } catch (Throwable) {
            return null;
        }
    }

    private function safeDetectedMime(string $pathname): ?string
    {
        try {
            $finfo = new \finfo(FILEINFO_MIME_TYPE);
            $mime = $finfo->file($pathname);

            return is_string($mime) ? $mime : null;
        } catch (Throwable) {
            return null;
        }
    }

    private function sanitizeDiagnosticMessage(string $message, ?UploadedFile $file = null): string
    {
        $sensitiveFragments = [
            base_path(),
            storage_path(),
            public_path(),
            sys_get_temp_dir(),
        ];
        $safeReplacements = [
            '[base_path]',
            '[storage_path]',
            '[public_path]',
            '[temp_dir]',
        ];

        if ($file instanceof UploadedFile) {
            $originalName = $file->getClientOriginalName();

            if ($originalName !== '') {
                $sensitiveFragments[] = $originalName;
                $safeReplacements[] = '[original_filename]';
            }
        }

        return str_replace(
            $sensitiveFragments,
            $safeReplacements,
            $message
        );
    }

    public function destroy(Guru $guru): RedirectResponse
    {
        $oldPath = $guru->signature_path;

        if (! $oldPath) {
            return back()
                ->with('success', 'Tanda tangan guru sudah kosong.')
                ->with('signatureUploadSuccess', 'Tanda tangan guru sudah kosong.');
        }

        DB::transaction(function () use ($guru) {
            $guru->forceFill(['signature_path' => null])->save();
        });

        if (Storage::disk(self::DISK)->exists($oldPath)) {
            if (! Storage::disk(self::DISK)->delete($oldPath)) {
                Log::warning('Gagal menghapus file tanda tangan guru.', [
                    'path_hash' => sha1($oldPath),
                ]);
            }
        }

        $this->invalidateRelatedReportCachesSafely($guru->fresh());

        return back()
            ->with('success', 'Tanda tangan guru berhasil dihapus.')
            ->with('signatureUploadSuccess', 'Tanda tangan guru berhasil dihapus.');
    }

    private function invalidateRelatedReportCachesSafely(Guru $guru): void
    {
        try {
            $this->invalidateRelatedReportCaches($guru);
        } catch (Throwable $exception) {
            Log::warning('Gagal membersihkan cache rapor setelah perubahan tanda tangan guru.', [
                'exception' => $exception::class,
            ]);
        }
    }

    private function invalidateRelatedReportCaches(Guru $guru): void
    {
        $classes = Kelas::query()
            ->select('kelas.*')
            ->join('guru_kelas', 'guru_kelas.kelas_id', '=', 'kelas.id')
            ->where('guru_kelas.guru_id', $guru->id)
            ->where('guru_kelas.is_wali_kelas', true)
            ->where('guru_kelas.role', 'wali_kelas')
            ->with('tahunAjaran')
            ->get();

        if ($classes->isEmpty()) {
            return;
        }

        $resolver = app(SiswaKelasSemesterResolver::class);

        foreach ($classes as $kelas) {
            $tahunAjaranId = $kelas->tahun_ajaran_id;
            $semester = $kelas->tahunAjaran?->semester;

            if (! $tahunAjaranId || ! $semester) {
                continue;
            }

            $resolver
                ->studentsForClass((int) $kelas->id, (int) $tahunAjaranId, (int) $semester, true)
                ->each(fn ($student) => PdfCacheService::clearStudentCache($student, (int) $tahunAjaranId, true));
        }
    }
}

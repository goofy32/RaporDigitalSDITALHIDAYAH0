<?php

namespace App\Jobs;

use App\Models\ReportTemplate;
use App\Models\Siswa;
use App\Models\TahunAjaran;
use App\Services\DocumentConversionService;
use App\Services\PdfCacheService;
use App\Services\RaporTemplateProcessor;
use App\Services\ReportPerformanceTracker;
use App\Services\ReportPeriodService;
use App\Services\ReportScoreEligibilityService;
use App\Services\SiswaKelasSemesterResolver;
use Exception;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;

class GeneratePdfReportJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 300; // 5 minutes

    public $tries = 3;

    public $maxExceptions = 3;

    protected $siswa;

    protected $type;

    protected $tahunAjaranId;

    protected $requestId;

    protected $userId;

    protected ?int $reportClassId = null;

    protected ?int $reportSemester = null;

    protected ?ReportTemplate $validatedTemplate = null;

    public function __construct(
        Siswa $siswa,
        $type,
        $tahunAjaranId,
        $requestId,
        $userId = null,
        ?int $semester = null
    )
    {
        $this->siswa = $siswa;
        $this->type = $type;
        $this->tahunAjaranId = $tahunAjaranId;
        $this->requestId = $requestId;
        $this->userId = $userId;
        $this->reportSemester = $semester;

        // Set queue name untuk PDF processing
        $this->onQueue('pdf');
    }

    public function handle()
    {
        $startTime = microtime(true);
        $memoryStart = memory_get_usage(true);
        $generationLock = null;
        $generationLockAcquired = false;
        $performance = ReportPerformanceTracker::startFlowIfEnabled(
            'pdf_job_pending',
            $this->type,
            'queue.generate_pdf_report'
        );

        Log::info('=== PDF JOB STARTED ===', [
            'request_id' => $this->requestId,
            'type' => $this->type,
            'queue_attempts' => $this->attempts(),
        ]);

        try {
            $this->validateGenerationContext();
            $freshnessVersion = PdfCacheService::currentFreshnessVersion(
                $this->siswa,
                $this->type,
                (int) $this->tahunAjaranId,
                $this->reportSemester
            );

            // Update progress: Started
            $this->updateProgress(null, 'Menyiapkan data rapor', [
                'status' => 'processing',
                'stage' => 'preparing',
            ]);

            // Step 1: Check if PDF already cached
            $cacheKey = PdfCacheService::getCacheKey(
                $this->siswa,
                $this->type,
                $this->tahunAjaranId,
                $this->reportSemester
            );
            $cachedPdf = PdfCacheService::getCachedPdf(
                $this->siswa,
                $this->type,
                $this->tahunAjaranId,
                $this->reportSemester
            );

            if ($cachedPdf && PdfCacheService::freshnessIsCurrent(
                $this->siswa,
                $this->type,
                (int) $this->tahunAjaranId,
                $freshnessVersion,
                $this->reportSemester
            )) {
                ReportPerformanceTracker::setFlowTypeIfEnabled('pdf_job_cache_hit');

                Log::info('PDF found in cache', [
                    'request_id' => $this->requestId,
                    'cache_key' => $cacheKey,
                ]);

                $this->updateProgress(100, 'PDF siap dibuka', [
                    'status' => 'ready',
                    'stage' => 'ready',
                    'filename' => $cachedPdf['filename'],
                    'file_size' => $cachedPdf['file_size'],
                    'cached' => true,
                ]);

                return;
            }

            $generationLock = Cache::lock(
                PdfCacheService::getGenerationLockKey(
                    $this->siswa,
                    $this->type,
                    $this->tahunAjaranId,
                    $this->reportSemester
                ),
                180
            );

            if (! $generationLock->get()) {
                $this->updateProgress(null, 'PDF sedang diproses oleh permintaan lain. Coba cek kembali sebentar lagi.', [
                    'status' => 'processing',
                    'stage' => 'waiting',
                    'processing' => true,
                    'cached' => false,
                ]);

                return;
            }
            $generationLockAcquired = true;

            $cachedPdf = PdfCacheService::getCachedPdf(
                $this->siswa,
                $this->type,
                $this->tahunAjaranId,
                $this->reportSemester
            );

            if ($cachedPdf && PdfCacheService::freshnessIsCurrent(
                $this->siswa,
                $this->type,
                (int) $this->tahunAjaranId,
                $freshnessVersion,
                $this->reportSemester
            )) {
                ReportPerformanceTracker::setFlowTypeIfEnabled('pdf_job_cache_hit');

                $this->updateProgress(100, 'PDF siap dibuka', [
                    'status' => 'ready',
                    'stage' => 'ready',
                    'filename' => $cachedPdf['filename'],
                    'file_size' => $cachedPdf['file_size'],
                    'cached' => true,
                ]);

                return;
            }

            ReportPerformanceTracker::setFlowTypeIfEnabled('pdf_job_cache_miss');

            // Update progress: Template processing
            $this->updateProgress(null, 'Menyiapkan data rapor', [
                'status' => 'processing',
                'stage' => 'preparing',
            ]);

            // Step 2: Get template
            $template = $this->validatedTemplate ?: $this->getTemplateForSiswa();
            if (! $template) {
                throw new Exception('Template rapor tidak ditemukan untuk tipe '.$this->type);
            }

            // Update progress: DOCX generation
            $this->updateProgress(null, 'Menyusun dokumen rapor', [
                'status' => 'processing',
                'stage' => 'document',
            ]);

            // Step 3: Generate DOCX
            $processor = new RaporTemplateProcessor($template, $this->siswa, $this->type, $this->tahunAjaranId);
            $result = $processor->generate();

            if (! $result['success'] || ! isset($result['path'])) {
                throw new Exception('Gagal generate file DOCX: '.($result['message'] ?? 'Unknown error'));
            }

            $docxPath = $result['path'];
            $fullDocxPath = storage_path('app/public/'.$docxPath);

            if (! file_exists($fullDocxPath)) {
                throw new Exception("DOCX file tidak ditemukan: $fullDocxPath");
            }

            // Update progress: PDF conversion
            $this->updateProgress(null, 'Mengonversi PDF', [
                'status' => 'processing',
                'stage' => 'conversion',
            ]);

            // Step 4: Convert to PDF
            $conversionService = app(DocumentConversionService::class);
            $pdfResult = $conversionService->convertStorageDocxToPdf($docxPath, 'pdf_reports');

            if (! $pdfResult['success']) {
                throw new Exception('Konversi ke PDF gagal: '.$pdfResult['message']);
            }

            // Update progress: Finalizing
            $this->updateProgress(null, 'Finalisasi PDF', [
                'status' => 'processing',
                'stage' => 'finalizing',
            ]);

            // Step 5: Store in cache and prepare response
            $pdfPath = $pdfResult['storage_path'];
            $fullPdfPath = storage_path('app/public/'.$pdfPath);

            if (! file_exists($fullPdfPath)) {
                throw new Exception("PDF file tidak ditemukan: $fullPdfPath");
            }

            $fileSize = filesize($fullPdfPath);
            $cleanName = preg_replace('/[^\w\s-]/', '', $this->siswa->nama);
            $cleanName = preg_replace('/\s+/', '_', $cleanName);
            $filename = "Rapor_{$this->type}_{$cleanName}_{$this->siswa->nis}.pdf";

            // Cache the result for 24 hours
            $cachedResult = PdfCacheService::cachePdf(
                $this->siswa,
                $this->type,
                $this->tahunAjaranId,
                $pdfPath,
                $filename,
                $fileSize,
                $freshnessVersion,
                $this->reportSemester
            );

            if (! $cachedResult) {
                Storage::disk(PdfCacheService::STORAGE_DISK)->delete($pdfPath);
                throw new Exception('Data rapor berubah selama PDF diproses. PDF akan dibuat ulang.');
            }

            // Update progress: Completed
            $this->updateProgress(100, 'PDF siap dibuka', [
                'status' => 'ready',
                'stage' => 'ready',
                'filename' => $filename,
                'file_size' => $fileSize,
                'cached' => false,
            ]);

            // Log success metrics
            $endTime = microtime(true);
            $duration = ($endTime - $startTime) * 1000;
            $memoryUsed = memory_get_usage(true) - $memoryStart;

            Log::info('=== PDF JOB COMPLETED ===', [
                'request_id' => $this->requestId,
                'duration_ms' => round($duration, 2),
                'memory_used_mb' => round($memoryUsed / 1024 / 1024, 2),
                'file_size_mb' => round($fileSize / 1024 / 1024, 2),
                'cache_key' => $cacheKey,
            ]);

        } catch (Exception $e) {
            Log::error('=== PDF JOB FAILED ===', [
                'request_id' => $this->requestId,
                'error' => $e->getMessage(),
                'attempts' => $this->attempts(),
                'trace' => $e->getTraceAsString(),
            ]);

            $this->forgetGenerationRequestIfCurrent();

            $this->updateProgress(-1, 'PDF gagal disiapkan. Silakan coba lagi atau hubungi administrator.', [
                'status' => 'failed',
                'stage' => 'failed',
                'error' => true,
                'attempts' => $this->attempts(),
                'max_attempts' => $this->tries,
            ]);

            throw $e; // Re-throw untuk retry mechanism
        } finally {
            if ($generationLock && $generationLockAcquired) {
                $generationLock->release();
            }

            ReportPerformanceTracker::finishIfEnabled($performance);
        }
    }

    public function failed(Exception $exception)
    {
        Log::error('=== PDF JOB PERMANENTLY FAILED ===', [
            'request_id' => $this->requestId,
            'error' => $exception->getMessage(),
            'attempts' => $this->attempts(),
        ]);

        $this->forgetGenerationRequestIfCurrent();

        $this->updateProgress(-1, 'PDF gagal disiapkan. Silakan coba lagi atau hubungi administrator.', [
            'status' => 'failed',
            'stage' => 'failed',
            'error' => true,
            'final_failure' => true,
        ]);
    }

    private function getTemplateForSiswa()
    {
        $kelasId = $this->reportClassId ?: $this->resolveReportClassId();

        if (! $kelasId) {
            return null;
        }

        // First look for class-specific template using many-to-many relationship
        $template = ReportTemplate::where('type', $this->type)
            ->where('is_active', true)
            ->when($this->tahunAjaranId, function ($query) {
                return $query->where('tahun_ajaran_id', $this->tahunAjaranId);
            })
            ->whereHas('kelasList', function ($query) use ($kelasId) {
                $query->where('kelas_id', $kelasId);
            })
            ->first();

        if ($template) {
            return $template;
        }

        // Try old relationship
        $template = ReportTemplate::where('type', $this->type)
            ->where('kelas_id', $kelasId)
            ->where('is_active', true)
            ->when($this->tahunAjaranId, function ($query) {
                return $query->where('tahun_ajaran_id', $this->tahunAjaranId);
            })
            ->first();

        if ($template) {
            return $template;
        }

        // Global template
        return ReportTemplate::where('type', $this->type)
            ->whereNull('kelas_id')
            ->where('is_active', true)
            ->when($this->tahunAjaranId, function ($query) {
                return $query->where('tahun_ajaran_id', $this->tahunAjaranId);
            })
            ->first();
    }

    private function resolveReportClassId(): ?int
    {
        if (! $this->tahunAjaranId) {
            return null;
        }

        $tahunAjaran = TahunAjaran::find($this->tahunAjaranId);

        if (! $tahunAjaran) {
            return null;
        }

        try {
            return app(SiswaKelasSemesterResolver::class)
                ->resolveClass($this->siswa, (int) $this->tahunAjaranId, (int) $tahunAjaran->semester, true)?->id;
        } catch (\RuntimeException $exception) {
            Log::warning('Unable to resolve PDF report class context', [
                'siswa_id' => $this->siswa->id,
                'tahun_ajaran_id' => $this->tahunAjaranId,
                'semester' => $tahunAjaran->semester,
                'request_id' => $this->requestId,
                'error' => $exception->getMessage(),
            ]);

            return null;
        }
    }

    private function validateGenerationContext(): void
    {
        $normalizedType = app(ReportPeriodService::class)->normalizeType($this->type);
        if (! $normalizedType) {
            throw new Exception('Jenis rapor PDF tidak valid.');
        }
        $this->type = $normalizedType;

        if (! Schema::hasTable('tahun_ajarans')) {
            return;
        }

        $tahunAjaran = TahunAjaran::find($this->tahunAjaranId);

        if (! $tahunAjaran) {
            throw new Exception('Konteks tahun ajaran PDF tidak valid.');
        }

        $currentSemester = (int) $tahunAjaran->semester;
        if ($this->reportSemester !== null && $this->reportSemester !== $currentSemester) {
            throw new Exception('Konteks semester PDF sudah berubah.');
        }
        $this->reportSemester = $currentSemester;

        if (! app(ReportPeriodService::class)->isOpened($this->type, $tahunAjaran, (int) $this->tahunAjaranId)) {
            throw new Exception('Periode rapor PDF tidak sedang dibuka.');
        }

        $this->reportClassId = $this->resolveReportClassId();
        if (! $this->reportClassId) {
            throw new Exception('Konteks kelas PDF tidak valid.');
        }

        if ($this->userId
            && Schema::hasTable('guru_kelas')
            && ! $this->siswa->isInKelasWali($this->userId, $this->tahunAjaranId, (int) $tahunAjaran->semester)) {
            throw new Exception('Konteks wali kelas PDF tidak valid.');
        }

        if (Schema::hasTable('nilais') && Schema::hasTable('mata_pelajarans')) {
            $scoreQuery = $this->siswa->nilais()
                ->where('tahun_ajaran_id', $this->tahunAjaranId)
                ->whereHas('mataPelajaran', function ($query) use ($tahunAjaran) {
                    $query->where('kelas_id', $this->reportClassId)
                        ->where('tahun_ajaran_id', $this->tahunAjaranId)
                        ->where('semester', (int) $tahunAjaran->semester);
                });

            if (! app(ReportScoreEligibilityService::class)
                ->apply($scoreQuery, $this->type, null, $this->reportClassId)
                ->exists()) {
                throw new Exception('Data nilai untuk PDF belum memenuhi syarat.');
            }
        }

        if (Schema::hasTable('report_templates')) {
            $this->validatedTemplate = $this->getTemplateForSiswa();
            if (! $this->validatedTemplate) {
                throw new Exception('Template rapor PDF tidak tersedia.');
            }
        }
    }

    private function updateProgress($percentage, $message, $data = [])
    {
        $progressKey = PdfCacheService::getProgressKey($this->requestId);
        $existingProgress = Cache::get($progressKey, []);

        $progressData = array_merge($existingProgress, [
            'status' => $percentage >= 100 ? 'ready' : ($percentage < 0 ? 'failed' : ($existingProgress['status'] ?? 'processing')),
            'percentage' => $percentage,
            'message' => $message,
            'completed' => $percentage >= 100 || $percentage < 0,
            'error' => $percentage < 0,
            'timestamp' => now()->toISOString(),
            'request_id' => $this->requestId,
            'siswa_id' => $this->siswa->id,
            'type' => $this->type,
            'tahun_ajaran_id' => $this->tahunAjaranId,
            'semester' => $this->reportSemester,
            'user_id' => $this->userId,
            'updated_at' => time(), // Add timestamp for debugging
        ]);

        if (! empty($data)) {
            $progressData = array_merge($progressData, $data);
        }

        unset($progressData['download_url']);

        // Store for 30 minutes
        Cache::put($progressKey, $progressData, now()->addMinutes(PdfCacheService::PROGRESS_TTL_MINUTES));

        // TAMBAHAN: Track semua progress keys untuk debugging
        $allKeys = Cache::get('all_progress_keys', []);
        if (! in_array($this->requestId, $allKeys)) {
            $allKeys[] = $this->requestId;
            Cache::put('all_progress_keys', $allKeys, now()->addHours(1));
        }

        Log::info('Progress updated', [
            'request_id' => $this->requestId,
            'percentage' => $percentage,
            'message' => $message,
            'progress_key' => $progressKey,
            'cache_stored' => Cache::has($progressKey),
        ]);
    }

    private function forgetGenerationRequestIfCurrent(): void
    {
        if (! $this->reportSemester) {
            return;
        }

        $requestKey = PdfCacheService::getGenerationRequestKey(
            $this->siswa,
            $this->type,
            $this->tahunAjaranId,
            $this->reportSemester
        );

        if (Cache::get($requestKey) === $this->requestId) {
            Cache::forget($requestKey);
        }
    }

    private function createSecureDownloadUrl(string $relativePath, string $filename): string
    {
        return URL::temporarySignedRoute(
            'wali_kelas.rapor.secure-file',
            now()->addMinutes(30),
            [
                'path' => ltrim(str_replace('\\', '/', $relativePath), '/'),
                'filename' => $filename,
                'disposition' => 'attachment',
                'user' => $this->userId,
            ]
        );
    }
}

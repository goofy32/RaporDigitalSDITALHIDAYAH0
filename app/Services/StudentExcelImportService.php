<?php

namespace App\Services;

use App\Models\TahunAjaran;
use Carbon\Carbon;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use Throwable;

class StudentExcelImportService
{
    private const REQUIRED_COLUMNS = [
        'nis',
        'nisn',
        'nama',
        'tanggal_lahir',
        'jenis_kelamin',
        'agama',
        'alamat',
        'kelas',
    ];

    /**
     * @return array{success: bool, imported_count: int, skipped_count: int, errors: array<int, string>}
     */
    public function import(UploadedFile $file, TahunAjaran $tahunAjaran): array
    {
        Log::info('Student import started', [
            'user_id' => auth()->id(),
            'tahun_ajaran_id' => $tahunAjaran->id,
            'semester' => $tahunAjaran->semester,
        ]);

        $worksheet = $this->readWorksheet($file->getRealPath());
        $prepared = $this->validateRows($worksheet['headers'], $worksheet['rows'], $tahunAjaran);

        if ($prepared['errors'] !== []) {
            Log::warning('Student import rejected during validation', [
                'user_id' => auth()->id(),
                'tahun_ajaran_id' => $tahunAjaran->id,
                'semester' => $tahunAjaran->semester,
                'error_count' => count($prepared['errors']),
                'valid_row_count' => count($prepared['rows']),
                'skipped_row_count' => $prepared['skipped_count'],
            ]);

            return [
                'success' => false,
                'imported_count' => 0,
                'skipped_count' => $prepared['skipped_count'],
                'errors' => $prepared['errors'],
            ];
        }

        if ($prepared['rows'] === []) {
            return [
                'success' => false,
                'imported_count' => 0,
                'skipped_count' => $prepared['skipped_count'],
                'errors' => ['Tidak ada baris siswa yang dapat diimpor.'],
            ];
        }

        $importedCount = DB::transaction(function () use ($prepared, $tahunAjaran) {
            $count = 0;

            foreach ($prepared['rows'] as $row) {
                $siswaId = DB::table('siswas')->insertGetId($this->studentPayload($row, $tahunAjaran));

                DB::table('siswa_kelas_semester')->updateOrInsert(
                    [
                        'siswa_id' => $siswaId,
                        'tahun_ajaran_id' => $tahunAjaran->id,
                        'semester' => (int) $tahunAjaran->semester,
                    ],
                    [
                        'kelas_id' => $row['kelas_id'],
                        'updated_at' => now(),
                        'created_at' => now(),
                    ]
                );

                $count++;
            }

            return $count;
        });

        Log::info('Student import completed', [
            'user_id' => auth()->id(),
            'tahun_ajaran_id' => $tahunAjaran->id,
            'semester' => $tahunAjaran->semester,
            'imported_count' => $importedCount,
            'skipped_row_count' => $prepared['skipped_count'],
        ]);

        return [
            'success' => true,
            'imported_count' => $importedCount,
            'skipped_count' => $prepared['skipped_count'],
            'errors' => [],
        ];
    }

    /**
     * @return array{headers: array<string, string>, rows: array<int, array<string, mixed>>}
     */
    private function readWorksheet(string $path): array
    {
        $sheet = IOFactory::load($path)->getActiveSheet();
        $highestRow = $sheet->getHighestDataRow();
        $highestColumn = $sheet->getHighestDataColumn();

        $rawHeaders = $sheet->rangeToArray("A1:{$highestColumn}1", null, true, true, true)[1] ?? [];
        $headers = [];

        foreach ($rawHeaders as $column => $header) {
            $normalized = $this->normalizeHeader((string) $header);

            if ($normalized !== '') {
                $headers[$column] = $normalized;
            }
        }

        $rows = [];

        for ($rowNumber = 2; $rowNumber <= $highestRow; $rowNumber++) {
            $row = [];

            foreach ($headers as $column => $header) {
                $cell = $sheet->getCell("{$column}{$rowNumber}");
                $value = $header === 'tanggal_lahir'
                    ? $cell->getValue()
                    : $cell->getFormattedValue();

                $row[$header] = is_string($value) ? trim($value) : $value;
            }

            $rows[$rowNumber] = $row;
        }

        return compact('headers', 'rows');
    }

    private function normalizeHeader(string $header): string
    {
        $normalized = strtolower(trim($header));
        $normalized = preg_replace('/[^a-z0-9]+/i', '_', $normalized) ?? '';
        $normalized = trim($normalized, '_');

        return match ($normalized) {
            'tanggal_lahir', 'tgl_lahir' => 'tanggal_lahir',
            'jenis_kelamin', 'jk' => 'jenis_kelamin',
            'nama_ayah', 'ayah' => 'nama_ayah',
            'nama_ibu', 'ibu' => 'nama_ibu',
            'pekerjaan_ayah' => 'pekerjaan_ayah',
            'pekerjaan_ibu' => 'pekerjaan_ibu',
            'alamat_orangtua', 'alamat_orang_tua' => 'alamat_orangtua',
            default => $normalized,
        };
    }

    /**
     * @param  array<string, string>  $headers
     * @param  array<int, array<string, mixed>>  $rows
     * @return array{rows: array<int, array<string, mixed>>, errors: array<int, string>, skipped_count: int}
     */
    private function validateRows(array $headers, array $rows, TahunAjaran $tahunAjaran): array
    {
        $errors = [];
        $validRows = [];
        $skippedCount = 0;

        foreach (array_diff(self::REQUIRED_COLUMNS, array_values($headers)) as $missingColumn) {
            $errors[] = "Kolom wajib tidak ditemukan: {$missingColumn}.";
        }

        if ($errors !== []) {
            return ['rows' => [], 'errors' => $errors, 'skipped_count' => count($rows)];
        }

        $seenNis = [];
        $seenNisn = [];

        foreach ($rows as $rowNumber => $row) {
            if ($this->isEmptyRow($row)) {
                $skippedCount++;

                continue;
            }

            foreach (self::REQUIRED_COLUMNS as $column) {
                if ($this->blank($row[$column] ?? null)) {
                    $errors[] = "Baris {$rowNumber}: kolom {$column} wajib diisi.";
                }
            }

            if ($errors !== [] && ! isset($row['nis'], $row['nisn'], $row['kelas'], $row['tanggal_lahir'])) {
                continue;
            }

            $nis = trim((string) ($row['nis'] ?? ''));
            $nisn = trim((string) ($row['nisn'] ?? ''));

            if ($nis !== '') {
                if (isset($seenNis[$nis])) {
                    $errors[] = "Baris {$rowNumber}: NIS duplikat dalam file.";
                }

                $seenNis[$nis] = true;

                if (DB::table('siswas')->where('nis', $nis)->exists()) {
                    $errors[] = "Baris {$rowNumber}: NIS sudah digunakan.";
                }
            }

            if ($nisn !== '') {
                if (isset($seenNisn[$nisn])) {
                    $errors[] = "Baris {$rowNumber}: NISN duplikat dalam file.";
                }

                $seenNisn[$nisn] = true;

                if (DB::table('siswas')->where('nisn', $nisn)->exists()) {
                    $errors[] = "Baris {$rowNumber}: NISN sudah digunakan.";
                }
            }

            $kelas = $this->resolveClass((string) ($row['kelas'] ?? ''), $tahunAjaran);
            if (! $kelas) {
                $errors[] = "Baris {$rowNumber}: kelas tidak ditemukan pada tahun ajaran aktif.";
            }

            $birthDate = $this->parseDate($row['tanggal_lahir'] ?? null);
            if (! $birthDate) {
                $errors[] = "Baris {$rowNumber}: tanggal lahir tidak valid.";
            }

            if ($kelas && $birthDate && $this->hasRequiredValues($row)) {
                $validRows[] = [
                    'nis' => $nis,
                    'nisn' => $nisn,
                    'nama' => trim((string) $row['nama']),
                    'tanggal_lahir' => $birthDate,
                    'jenis_kelamin' => trim((string) $row['jenis_kelamin']),
                    'agama' => trim((string) $row['agama']),
                    'alamat' => trim((string) $row['alamat']),
                    'kelas_id' => (int) $kelas->id,
                    'nama_ayah' => $this->nullableString($row['nama_ayah'] ?? null),
                    'nama_ibu' => $this->nullableString($row['nama_ibu'] ?? null),
                    'pekerjaan_ayah' => $this->nullableString($row['pekerjaan_ayah'] ?? null),
                    'pekerjaan_ibu' => $this->nullableString($row['pekerjaan_ibu'] ?? null),
                    'alamat_orangtua' => $this->nullableString($row['alamat_orangtua'] ?? null),
                    'photo' => $this->nullableString($row['photo'] ?? null),
                ];
            }
        }

        if ($errors !== []) {
            return ['rows' => [], 'errors' => $errors, 'skipped_count' => $skippedCount];
        }

        return ['rows' => $validRows, 'errors' => [], 'skipped_count' => $skippedCount];
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function isEmptyRow(array $row): bool
    {
        foreach ($row as $value) {
            if (! $this->blank($value)) {
                return false;
            }
        }

        return true;
    }

    private function blank(mixed $value): bool
    {
        return $value === null || (is_string($value) && trim($value) === '');
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function hasRequiredValues(array $row): bool
    {
        foreach (self::REQUIRED_COLUMNS as $column) {
            if ($this->blank($row[$column] ?? null)) {
                return false;
            }
        }

        return true;
    }

    private function resolveClass(string $classLabel, TahunAjaran $tahunAjaran): ?object
    {
        $parsed = $this->parseClassLabel($classLabel);

        if (! $parsed) {
            return null;
        }

        return DB::table('kelas')
            ->where('tahun_ajaran_id', $tahunAjaran->id)
            ->whereRaw('LOWER(nomor_kelas) = ?', [strtolower($parsed['nomor_kelas'])])
            ->whereRaw('LOWER(nama_kelas) = ?', [strtolower($parsed['nama_kelas'])])
            ->first();
    }

    /**
     * @return array{nomor_kelas: string, nama_kelas: string}|null
     */
    private function parseClassLabel(string $classLabel): ?array
    {
        $normalized = trim(preg_replace('/\s+/', ' ', $classLabel) ?? '');
        $normalized = trim(preg_replace('/^kelas\s+/i', '', $normalized) ?? '');

        foreach ([
            '/^(\d+)\s*-\s*(.+)$/u',
            '/^(\d+)\s+(.+)$/u',
            '/^(\d+)([^\d].+)$/u',
        ] as $pattern) {
            if (preg_match($pattern, $normalized, $matches)) {
                return [
                    'nomor_kelas' => trim($matches[1]),
                    'nama_kelas' => trim($matches[2]),
                ];
            }
        }

        return null;
    }

    private function parseDate(mixed $value): ?string
    {
        if ($this->blank($value)) {
            return null;
        }

        try {
            if ($value instanceof \DateTimeInterface) {
                return Carbon::instance($value)->toDateString();
            }

            if (is_numeric($value)) {
                return Carbon::instance(ExcelDate::excelToDateTimeObject((float) $value))->toDateString();
            }

            return Carbon::parse((string) $value)->toDateString();
        } catch (Throwable) {
            return null;
        }
    }

    private function nullableString(mixed $value): ?string
    {
        if ($this->blank($value)) {
            return null;
        }

        return trim((string) $value);
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function studentPayload(array $row, TahunAjaran $tahunAjaran): array
    {
        $payload = [
            'nis' => $row['nis'],
            'nisn' => $row['nisn'],
            'nama' => $row['nama'],
            'tanggal_lahir' => $row['tanggal_lahir'],
            'jenis_kelamin' => $row['jenis_kelamin'],
            'agama' => $row['agama'],
            'alamat' => $row['alamat'],
            'kelas_id' => $row['kelas_id'],
            'tahun_ajaran_id' => $tahunAjaran->id,
            'nama_ayah' => $row['nama_ayah'],
            'nama_ibu' => $row['nama_ibu'],
            'pekerjaan_ayah' => $row['pekerjaan_ayah'],
            'pekerjaan_ibu' => $row['pekerjaan_ibu'],
            'alamat_orangtua' => $row['alamat_orangtua'],
            'photo' => $row['photo'],
            'created_at' => now(),
            'updated_at' => now(),
        ];

        if (Schema::hasColumn('siswas', 'status')) {
            $payload['status'] = 'aktif';
        }

        return $payload;
    }
}

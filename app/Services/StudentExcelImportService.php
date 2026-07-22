<?php

namespace App\Services;

use App\Models\TahunAjaran;
use App\Services\SiswaKelasSemesterResolver;
use App\Support\StudentIdentifier;
use Carbon\Carbon;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use Throwable;

class StudentExcelImportService
{
    private const TEMPLATE_FORMAT_MESSAGE = 'Format template tidak sesuai atau sudah berubah. Silakan download ulang template siswa terbaru.';

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

    private const FIELD_LABELS = [
        'nis' => 'NIS',
        'nisn' => 'NISN',
        'nama' => 'Nama siswa',
        'tanggal_lahir' => 'Tanggal lahir',
        'jenis_kelamin' => 'Jenis kelamin',
        'agama' => 'Agama',
        'alamat' => 'Alamat',
        'kelas' => 'Kelas',
        'nama_ayah' => 'Nama ayah',
        'nama_ibu' => 'Nama ibu',
        'pekerjaan_ayah' => 'Pekerjaan ayah',
        'pekerjaan_ibu' => 'Pekerjaan ibu',
        'alamat_orangtua' => 'Alamat orang tua',
        'photo' => 'Foto',
    ];

    private const IDENTIFIER_CELL_ERRORS_KEY = '__identifier_cell_errors';

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

        if (app()->resolved(SiswaKelasSemesterResolver::class)) {
            app(SiswaKelasSemesterResolver::class)->resetMemoization();
        }

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
                if (in_array($header, ['nis', 'nisn'], true)) {
                    $identifierCell = $this->readStudentIdentifierCell($cell, $header);
                    $row[$header] = is_string($identifierCell['value'])
                        ? trim($identifierCell['value'])
                        : $identifierCell['value'];

                    if ($identifierCell['error'] !== null) {
                        $row[self::IDENTIFIER_CELL_ERRORS_KEY][$header] = $identifierCell['error'];
                    }

                    continue;
                }

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

        if (array_diff(self::REQUIRED_COLUMNS, array_values($headers)) !== []) {
            $errors[] = self::TEMPLATE_FORMAT_MESSAGE;
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
                    $errors[] = $this->rowMessage($rowNumber, $row, "{$this->fieldLabel($column)} belum diisi.");
                }
            }

            $nis = trim((string) ($row['nis'] ?? ''));
            $nisn = trim((string) ($row['nisn'] ?? ''));
            $identifierCellErrors = $row[self::IDENTIFIER_CELL_ERRORS_KEY] ?? [];

            if (isset($identifierCellErrors['nis'])) {
                $errors[] = $this->rowMessage($rowNumber, $row, $identifierCellErrors['nis']);
            } elseif ($nis !== '' && $this->validateStudentIdentifier('nis', $nis, $rowNumber, $row, $errors)) {
                if (isset($seenNis[$nis])) {
                    $errors[] = $this->rowMessage($rowNumber, $row, "NIS {$nis} muncul lebih dari satu kali dalam file.");
                }

                $seenNis[$nis] = true;

                if (DB::table('siswas')->where('nis', $nis)->exists()) {
                    $errors[] = $this->rowMessage($rowNumber, $row, "NIS {$nis} sudah digunakan siswa lain.");
                }
            }

            if (isset($identifierCellErrors['nisn'])) {
                $errors[] = $this->rowMessage($rowNumber, $row, $identifierCellErrors['nisn']);
            } elseif ($nisn !== '' && $this->validateStudentIdentifier('nisn', $nisn, $rowNumber, $row, $errors)) {
                if (isset($seenNisn[$nisn])) {
                    $errors[] = $this->rowMessage($rowNumber, $row, "NISN {$nisn} muncul lebih dari satu kali dalam file.");
                }

                $seenNisn[$nisn] = true;

                if (DB::table('siswas')->where('nisn', $nisn)->exists()) {
                    $errors[] = $this->rowMessage($rowNumber, $row, "NISN {$nisn} sudah digunakan siswa lain.");
                }
            }

            $kelasLabel = trim((string) ($row['kelas'] ?? ''));
            $classResolution = $this->resolveClass($kelasLabel, $tahunAjaran);
            $kelas = $classResolution['kelas'];
            if ($kelasLabel !== '') {
                if ($classResolution['ambiguous']) {
                    $errors[] = $this->rowMessage($rowNumber, $row, 'kelas "'.$kelasLabel.'" ambigu karena cocok dengan lebih dari satu data kelas. Periksa data kelas pada tahun ajaran aktif.');
                } elseif (! $kelas) {
                    $errors[] = $this->rowMessage($rowNumber, $row, 'kelas "'.$kelasLabel.'" tidak ditemukan. Gunakan nama kelas sesuai template.');
                }
            }

            $birthDate = $this->parseDate($row['tanggal_lahir'] ?? null);
            if (! $this->blank($row['tanggal_lahir'] ?? null) && ! $birthDate) {
                $errors[] = $this->rowMessage($rowNumber, $row, 'tanggal lahir harus menggunakan format YYYY-MM-DD, contoh 2017-05-21.');
            }

            if ($kelas && $birthDate && $this->hasRequiredValues($row)) {
                $validRows[] = [
                    'nis' => $nis,
                    'nisn' => $nisn,
                    'nama' => trim((string) $row['nama']),
                    'tanggal_lahir' => $birthDate,
                    'jenis_kelamin' => $this->normalizeGender($row['jenis_kelamin']),
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
     * @return array{value: mixed, error: ?string}
     */
    private function readStudentIdentifierCell(Cell $cell, string $field): array
    {
        $type = $cell->getDataType();
        $invalidCellMessage = "{$this->fieldLabel($field)} tidak boleh berupa tanggal, formula, boolean, atau error cell.";
        $ambiguousNumberMessage = "{$this->fieldLabel($field)} harus berupa teks atau angka bulat maksimal 10 digit.";

        if ($cell->getDataType() === DataType::TYPE_FORMULA) {
            return [
                'value' => trim((string) $cell->getValue()),
                'error' => $invalidCellMessage,
            ];
        }

        if (ExcelDate::isDateTime($cell)) {
            return [
                'value' => $cell->getValue(),
                'error' => $invalidCellMessage,
            ];
        }

        if (in_array($type, [DataType::TYPE_BOOL, DataType::TYPE_ERROR], true)) {
            return [
                'value' => $cell->getValue(),
                'error' => $invalidCellMessage,
            ];
        }

        $rawValue = $cell->getValue();

        if ($rawValue === null || $rawValue === '') {
            return ['value' => $rawValue, 'error' => null];
        }

        if (is_int($rawValue) || is_float($rawValue)) {
            $numericValue = (float) $rawValue;

            if (! is_finite($numericValue) || floor($numericValue) !== $numericValue) {
                return [
                    'value' => trim((string) $rawValue),
                    'error' => $ambiguousNumberMessage,
                ];
            }

            return ['value' => number_format($numericValue, 0, '', ''), 'error' => null];
        }

        if (in_array($type, [DataType::TYPE_STRING, DataType::TYPE_INLINE], true)) {
            return ['value' => trim((string) $cell->getFormattedValue()), 'error' => null];
        }

        return [
            'value' => $rawValue,
            'error' => $invalidCellMessage,
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<int, string>  $errors
     */
    private function validateStudentIdentifier(string $field, string $value, int $rowNumber, array $row, array &$errors): bool
    {
        $label = $this->fieldLabel($field);

        if (! StudentIdentifier::hasOnlyDigits($value)) {
            $errors[] = $this->rowMessage($rowNumber, $row, "{$label} hanya boleh berisi angka.");

            return false;
        }

        if (strlen($value) > StudentIdentifier::MAX_DIGITS) {
            $errors[] = $this->rowMessage($rowNumber, $row, "{$label} maksimal 10 digit.");

            return false;
        }

        return true;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function rowMessage(int $rowNumber, array $row, string $message): string
    {
        $studentName = trim((string) ($row['nama'] ?? ''));

        if ($studentName !== '') {
            return "Baris {$rowNumber}, {$studentName}: {$message}";
        }

        return "Baris {$rowNumber}: {$message}";
    }

    private function fieldLabel(string $field): string
    {
        return self::FIELD_LABELS[$field] ?? Str::headline(str_replace('_', ' ', $field));
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

    /**
     * @return array{kelas: ?object, ambiguous: bool}
     */
    private function resolveClass(string $classLabel, TahunAjaran $tahunAjaran): array
    {
        $parsed = $this->parseClassLabel($classLabel);

        if (! $parsed) {
            return ['kelas' => null, 'ambiguous' => false];
        }

        $candidates = DB::table('kelas')
            ->where('tahun_ajaran_id', $tahunAjaran->id)
            ->whereNull('deleted_at')
            ->whereRaw('LOWER(TRIM(nomor_kelas)) = ?', [$this->normalizeClassLookupValue($parsed['nomor_kelas'])])
            ->whereRaw('LOWER(TRIM(nama_kelas)) = ?', [$this->normalizeClassLookupValue($parsed['nama_kelas'])])
            ->get();

        return [
            'kelas' => $candidates->count() === 1 ? $candidates->first() : null,
            'ambiguous' => $candidates->count() > 1,
        ];
    }

    /**
     * @return array{nomor_kelas: string, nama_kelas: string}|null
     */
    private function parseClassLabel(string $classLabel): ?array
    {
        $normalized = trim(preg_replace('/\s+/u', ' ', $classLabel) ?? '');
        $normalized = trim(preg_replace('/^kelas\s+/i', '', $normalized) ?? '');

        foreach ([
            '/^(\d+)\s*-\s*(.+)$/u',
            '/^(\d+)\s+(.+)$/u',
            '/^(\d+)([^\d].*)$/u',
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

    private function normalizeClassLookupValue(string $value): string
    {
        return Str::lower(trim($value));
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

    private function normalizeGender(mixed $value): string
    {
        $normalized = strtolower(trim((string) $value));

        return match ($normalized) {
            'l', 'laki', 'laki-laki', 'laki laki' => 'Laki-laki',
            'p', 'perempuan' => 'Perempuan',
            default => trim((string) $value),
        };
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

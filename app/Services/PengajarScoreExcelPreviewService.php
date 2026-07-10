<?php

namespace App\Services;

use App\Models\MataPelajaran;
use App\Models\Siswa;
use App\Models\TahunAjaran;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;

class PengajarScoreExcelPreviewService
{
    public function __construct(
        private readonly PengajarScoreExcelTemplateService $templateService
    ) {
    }

    /**
     * @param Collection<int, Siswa> $siswas
     * @param array<int, array<string, mixed>> $existingScores
     * @return array<string, mixed>
     */
    public function preview(
        UploadedFile $file,
        MataPelajaran $mataPelajaran,
        Collection $siswas,
        TahunAjaran $tahunAjaran,
        array $existingScores
    ): array {
        $spreadsheet = IOFactory::load($file->getRealPath());

        try {
            $sheet = $spreadsheet->getSheetByName(PengajarScoreExcelTemplateService::SHEET_NILAI)
                ?? $spreadsheet->getActiveSheet();

            $scoreColumns = $this->templateService->scoreColumns($mataPelajaran);
            $requiredKeys = collect(PengajarScoreExcelTemplateService::BASE_COLUMNS)
                ->merge($scoreColumns)
                ->pluck('key')
                ->values()
                ->all();
            $columnMap = $this->readColumnMap($sheet);
            $contextErrors = $this->validateWorkbookContext($sheet, $mataPelajaran, $tahunAjaran);

            foreach ($requiredKeys as $requiredKey) {
                if (! array_key_exists($requiredKey, $columnMap)) {
                    $contextErrors[] = "Kolom {$requiredKey} tidak ditemukan pada template.";
                }
            }

            $allowedStudents = $siswas->keyBy('id');
            $seenStudentIds = [];
            $previewRows = [];
            $highestRow = $sheet->getHighestDataRow();

            for ($rowNumber = PengajarScoreExcelTemplateService::DATA_START_ROW; $rowNumber <= $highestRow; $rowNumber++) {
                if ($this->isBlankRow($sheet, $rowNumber, $columnMap, $requiredKeys)) {
                    continue;
                }

                $row = $this->previewRow(
                    $sheet,
                    $rowNumber,
                    $columnMap,
                    $scoreColumns,
                    $mataPelajaran,
                    $allowedStudents,
                    $existingScores,
                    $seenStudentIds
                );

                $previewRows[] = $row;

                if ($row['siswa_id']) {
                    $seenStudentIds[] = (int) $row['siswa_id'];
                }
            }

            $validRows = collect($previewRows)->where('valid', true)->count();
            $invalidRows = count($previewRows) - $validRows;

            return [
                'valid' => empty($contextErrors) && $invalidRows === 0,
                'context_errors' => $contextErrors,
                'rows' => $previewRows,
                'columns' => $scoreColumns,
                'summary' => [
                    'rows' => count($previewRows),
                    'valid_rows' => $validRows,
                    'invalid_rows' => $invalidRows,
                ],
            ];
        } finally {
            $spreadsheet->disconnectWorksheets();
        }
    }

    /**
     * @return array<string, int>
     */
    private function readColumnMap($sheet): array
    {
        $columnMap = [];
        $highestColumnIndex = Coordinate::columnIndexFromString($sheet->getHighestDataColumn());

        for ($columnIndex = 1; $columnIndex <= $highestColumnIndex; $columnIndex++) {
            $key = trim((string) $this->cellValue($sheet, $columnIndex, PengajarScoreExcelTemplateService::KEY_ROW));

            if ($key !== '') {
                $columnMap[$key] = $columnIndex;
            }
        }

        return $columnMap;
    }

    /**
     * @return array<int, string>
     */
    private function validateWorkbookContext($sheet, MataPelajaran $mataPelajaran, TahunAjaran $tahunAjaran): array
    {
        $errors = [];
        $expected = [
            'tahun_ajaran_id' => (int) $tahunAjaran->id,
            'semester' => (int) $tahunAjaran->semester,
            'kelas_id' => (int) $mataPelajaran->kelas_id,
            'mata_pelajaran_id' => (int) $mataPelajaran->id,
        ];
        $actual = [
            'tahun_ajaran_id' => (int) $this->cellValue($sheet, 1, 3),
            'semester' => (int) $this->cellValue($sheet, 2, 3),
            'kelas_id' => (int) $this->cellValue($sheet, 3, 3),
            'mata_pelajaran_id' => (int) $this->cellValue($sheet, 4, 3),
        ];

        foreach ($expected as $key => $value) {
            if (($actual[$key] ?? null) !== $value) {
                $errors[] = "Template tidak sesuai konteks {$key}.";
            }
        }

        return $errors;
    }

    private function isBlankRow($sheet, int $rowNumber, array $columnMap, array $keys): bool
    {
        foreach ($keys as $key) {
            if (! isset($columnMap[$key])) {
                continue;
            }

            $value = $this->cellValue($sheet, $columnMap[$key], $rowNumber);

            if ($value !== null && trim((string) $value) !== '') {
                return false;
            }
        }

        return true;
    }

    /**
     * @param Collection<int, Siswa> $allowedStudents
     * @param array<int, array<string, mixed>> $existingScores
     * @param array<int, int> $seenStudentIds
     * @return array<string, mixed>
     */
    private function previewRow(
        $sheet,
        int $rowNumber,
        array $columnMap,
        array $scoreColumns,
        MataPelajaran $mataPelajaran,
        Collection $allowedStudents,
        array $existingScores,
        array $seenStudentIds
    ): array {
        $errors = [];
        $warnings = [];
        $rawSiswaId = trim((string) $this->valueForKey($sheet, $columnMap, 'siswa_id', $rowNumber));
        $siswaId = ctype_digit($rawSiswaId) ? (int) $rawSiswaId : null;
        $siswa = $siswaId ? $allowedStudents->get($siswaId) : null;

        if ($rawSiswaId === '') {
            $errors[] = 'siswa_id wajib diisi.';
        } elseif (! ctype_digit($rawSiswaId)) {
            $errors[] = 'siswa_id harus berupa angka.';
        } elseif (in_array($siswaId, $seenStudentIds, true)) {
            $errors[] = 'siswa_id duplikat di file.';
        } elseif (! $siswa) {
            $errors[] = 'Siswa tidak termasuk kelas/konteks mata pelajaran ini.';
        }

        if ($siswa) {
            $this->validateIdentityColumns($sheet, $columnMap, $rowNumber, $siswa, $mataPelajaran, $errors, $warnings);
        }

        $uploadedValues = [];
        $existingValues = [];
        $fieldErrors = [];

        foreach ($scoreColumns as $column) {
            $key = $column['key'];
            $value = $this->valueForKey($sheet, $columnMap, $key, $rowNumber);
            $normalizedValue = $this->normalizeScoreCell($value);

            $uploadedValues[] = [
                'key' => $key,
                'label' => $column['label'],
                'value' => $normalizedValue,
                'raw_value' => $this->normalizeRawCellValue($value),
                'editable' => (bool) ($column['editable'] ?? false),
                'type' => $column['type'] ?? null,
                'lingkup_materi_id' => $column['lingkup_materi_id'] ?? null,
                'tujuan_pembelajaran_id' => $column['tujuan_pembelajaran_id'] ?? null,
            ];

            $existingValues[] = [
                'key' => $key,
                'label' => $column['label'],
                'value' => $siswaId ? $this->existingValueForColumn($existingScores[$siswaId] ?? [], $column) : null,
                'editable' => (bool) ($column['editable'] ?? false),
            ];

            if ($value === null || trim((string) $value) === '') {
                continue;
            }

            if (! is_numeric($value)) {
                $message = "{$column['label']} harus berupa angka.";
                $errors[] = $message;
                $fieldErrors[$key][] = $message;
                continue;
            }

            $numericValue = (float) $value;
            if ($numericValue < 0 || $numericValue > 100) {
                $message = "{$column['label']} harus antara 0 sampai 100.";
                $errors[] = $message;
                $fieldErrors[$key][] = $message;
            }
        }

        return [
            'row_number' => $rowNumber,
            'siswa_id' => $siswaId,
            'student_name' => $siswa?->nama ?? trim((string) $this->valueForKey($sheet, $columnMap, 'nama_siswa', $rowNumber)),
            'existing_values' => $existingValues,
            'uploaded_values' => $uploadedValues,
            'errors' => $errors,
            'warnings' => $warnings,
            'field_errors' => $fieldErrors,
            'valid' => empty($errors),
            'status' => empty($errors) ? 'Valid' : 'Tidak valid',
        ];
    }

    /**
     * @param array<int, string> $errors
     * @param array<int, string> $warnings
     */
    private function validateIdentityColumns(
        $sheet,
        array $columnMap,
        int $rowNumber,
        Siswa $siswa,
        MataPelajaran $mataPelajaran,
        array &$errors,
        array &$warnings
    ): void {
        $nis = trim((string) $this->valueForKey($sheet, $columnMap, 'nis', $rowNumber));
        $nisn = trim((string) $this->valueForKey($sheet, $columnMap, 'nisn', $rowNumber));
        $nama = trim((string) $this->valueForKey($sheet, $columnMap, 'nama_siswa', $rowNumber));
        $kelas = trim((string) $this->valueForKey($sheet, $columnMap, 'kelas', $rowNumber));
        $mataPelajaranLabel = trim((string) $this->valueForKey($sheet, $columnMap, 'mata_pelajaran', $rowNumber));

        if ($nis !== '' && (string) $siswa->nis !== $nis) {
            $errors[] = 'NIS tidak sesuai dengan siswa_id.';
        }

        if ($nisn !== '' && (string) $siswa->nisn !== $nisn) {
            $errors[] = 'NISN tidak sesuai dengan siswa_id.';
        }

        if ($nama !== '' && $siswa->nama !== $nama) {
            $warnings[] = 'Nama siswa berbeda dari data saat ini. Identifikasi tetap memakai siswa_id.';
        }

        if ($kelas !== '' && $kelas !== ($mataPelajaran->kelas?->label_kelas ?? '')) {
            $errors[] = 'Kelas pada baris tidak sesuai dengan konteks template.';
        }

        if ($mataPelajaranLabel !== '' && $mataPelajaranLabel !== $mataPelajaran->nama_pelajaran) {
            $errors[] = 'Mata pelajaran pada baris tidak sesuai dengan konteks template.';
        }
    }

    private function existingValueForColumn(array $existingScores, array $column): mixed
    {
        return match ($column['type']) {
            'tp' => $existingScores['tp'][$column['lingkup_materi_id']][$column['tujuan_pembelajaran_id']] ?? null,
            'lm' => $existingScores['lm'][$column['lingkup_materi_id']] ?? null,
            default => $existingScores[$column['key']] ?? null,
        };
    }

    private function valueForKey($sheet, array $columnMap, string $key, int $rowNumber): mixed
    {
        if (! isset($columnMap[$key])) {
            return null;
        }

        return $this->cellValue($sheet, $columnMap[$key], $rowNumber);
    }

    private function cellValue($sheet, int $columnIndex, int $rowNumber): mixed
    {
        $cell = Coordinate::stringFromColumnIndex($columnIndex).$rowNumber;

        return $sheet->getCell($cell)->getCalculatedValue();
    }

    private function normalizeScoreCell(mixed $value): ?float
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }

        return is_numeric($value) ? (float) $value : null;
    }

    private function normalizeRawCellValue(mixed $value): ?string
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }

        return trim((string) $value);
    }
}

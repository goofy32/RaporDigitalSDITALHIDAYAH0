<?php

namespace App\Services;

use App\Models\MataPelajaran;
use App\Models\Siswa;
use App\Models\TahunAjaran;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;

class PengajarScoreExcelPreviewService
{
    private const CONTEXT_MISMATCH_MESSAGE = 'Template Excel tidak sesuai dengan kelas atau mata pelajaran yang sedang dibuka. Silakan download ulang template dari Data Pembelajaran untuk kelas dan mata pelajaran ini.';
    private const STRUCTURE_CHANGED_MESSAGE = 'Format template Excel tidak sesuai atau sudah berubah. Silakan download ulang template terbaru dari Data Pembelajaran, isi nilainya kembali, lalu upload ulang. Jangan mengubah judul kolom, sheet, atau bagian yang dikunci pada template.';
    private const UNREADABLE_TEMPLATE_MESSAGE = 'Template Excel tidak dapat dibaca dengan benar. Pastikan file berasal dari tombol Download Template Nilai pada aplikasi ini dan belum diubah strukturnya.';
    private const MULTI_SHEET_MESSAGE = 'Untuk saat ini, import nilai hanya menerima template satu kelas dan satu mata pelajaran. Silakan gunakan file dari tombol Download Template Nilai, bukan Download Semua Template Siap.';

    public function __construct(
        private readonly PengajarScoreExcelTemplateService $templateService,
        private readonly SpreadsheetImportGuard $spreadsheetGuard
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
        $spreadsheet = $this->spreadsheetGuard->loadUploadedXlsx($file, SpreadsheetImportGuard::PROFILE_SCORE);

        try {
            $scoreColumns = $this->templateService->scoreColumns($mataPelajaran);

            if ($this->isBulkWorkbook($spreadsheet) || $this->hasMultipleScoreSheets($spreadsheet)) {
                return $this->invalidWorkbookPreview($scoreColumns, [self::MULTI_SHEET_MESSAGE]);
            }

            $sheet = $spreadsheet->getSheetByName(PengajarScoreExcelTemplateService::SHEET_NILAI)
                ?? $spreadsheet->getActiveSheet();

            $requiredKeys = collect(PengajarScoreExcelTemplateService::BASE_COLUMNS)
                ->merge($scoreColumns)
                ->pluck('key')
                ->values()
                ->all();
            $columnMap = $this->readColumnMap($sheet);
            $contextErrors = $this->validateWorkbookContext($sheet, $mataPelajaran, $tahunAjaran);

            if (! empty($contextErrors)) {
                return $this->invalidWorkbookPreview($scoreColumns, $contextErrors);
            }

            foreach ($requiredKeys as $requiredKey) {
                if (! array_key_exists($requiredKey, $columnMap)) {
                    $contextErrors[] = self::STRUCTURE_CHANGED_MESSAGE;
                    break;
                }
            }

            if (! empty($contextErrors)) {
                return $this->invalidWorkbookPreview($scoreColumns, $contextErrors);
            }

            $allowedStudents = $siswas->keyBy('id');
            $seenStudentIds = [];
            $previewRows = [];
            $highestRow = $this->spreadsheetGuard->assertDataRowLimit(
                $sheet,
                PengajarScoreExcelTemplateService::DATA_START_ROW,
                SpreadsheetImportGuard::MAX_SCORE_IMPORT_ROWS
            );

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
     * @param array<int, array<string, mixed>> $scoreColumns
     * @param array<int, string> $contextErrors
     * @return array<string, mixed>
     */
    private function invalidWorkbookPreview(array $scoreColumns, array $contextErrors): array
    {
        return [
            'valid' => false,
            'context_errors' => array_values(array_unique($contextErrors)),
            'rows' => [],
            'columns' => $scoreColumns,
            'summary' => [
                'rows' => 0,
                'valid_rows' => 0,
                'invalid_rows' => 0,
            ],
        ];
    }

    private function hasMultipleScoreSheets($spreadsheet): bool
    {
        $scoreSheetCount = 0;

        foreach ($spreadsheet->getWorksheetIterator() as $sheet) {
            if ($sheet->getTitle() === PengajarScoreExcelTemplateService::SHEET_PETUNJUK) {
                continue;
            }

            $scoreSheetCount++;
        }

        return $scoreSheetCount > 1;
    }

    private function isBulkWorkbook($spreadsheet): bool
    {
        $instructionSheet = $spreadsheet->getSheetByName(PengajarScoreExcelTemplateService::SHEET_PETUNJUK);

        if (! $instructionSheet) {
            return false;
        }

        $key = trim((string) $instructionSheet->getCell('A'.PengajarScoreExcelTemplateService::MULTI_METADATA_ROW)->getCalculatedValue());
        $value = trim((string) $instructionSheet->getCell('B'.PengajarScoreExcelTemplateService::MULTI_METADATA_ROW)->getCalculatedValue());

        return $key === 'workbook_type' && $value === PengajarScoreExcelTemplateService::MULTI_WORKBOOK_TYPE;
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
        $expected = [
            'tahun_ajaran_id' => (int) $tahunAjaran->id,
            'semester' => (int) $tahunAjaran->semester,
            'kelas_id' => (int) $mataPelajaran->kelas_id,
            'mata_pelajaran_id' => (int) $mataPelajaran->id,
        ];
        $actualRaw = [
            'tahun_ajaran_id' => $this->cellValue($sheet, 1, 3),
            'semester' => $this->cellValue($sheet, 2, 3),
            'kelas_id' => $this->cellValue($sheet, 3, 3),
            'mata_pelajaran_id' => $this->cellValue($sheet, 4, 3),
        ];

        foreach ($actualRaw as $value) {
            if (! $this->metadataValueIsReadable($value)) {
                return [self::UNREADABLE_TEMPLATE_MESSAGE];
            }
        }

        $actual = array_map(fn ($value) => (int) $value, $actualRaw);

        foreach ($expected as $key => $value) {
            if (($actual[$key] ?? null) !== $value) {
                return $this->contextMismatchMessages($sheet, $mataPelajaran);
            }
        }

        return [];
    }

    private function metadataValueIsReadable(mixed $value): bool
    {
        if ($value === null || trim((string) $value) === '') {
            return false;
        }

        return is_numeric($value);
    }

    /**
     * @return array<int, string>
     */
    private function contextMismatchMessages($sheet, MataPelajaran $mataPelajaran): array
    {
        $messages = [
            self::CONTEXT_MISMATCH_MESSAGE,
            sprintf(
                'Anda sedang membuka Kelas: %s, Mata Pelajaran: %s. Silakan upload template yang sesuai dengan halaman ini.',
                $mataPelajaran->kelas?->label_kelas ?? '-',
                $mataPelajaran->nama_pelajaran
            ),
        ];

        $columnMap = $this->readColumnMap($sheet);
        $templateClass = trim((string) $this->valueForKey($sheet, $columnMap, 'kelas', PengajarScoreExcelTemplateService::DATA_START_ROW));
        $templateSubject = trim((string) $this->valueForKey($sheet, $columnMap, 'mata_pelajaran', PengajarScoreExcelTemplateService::DATA_START_ROW));

        if ($templateClass !== '' && $templateSubject !== ''
            && ($templateClass !== ($mataPelajaran->kelas?->label_kelas ?? '') || $templateSubject !== $mataPelajaran->nama_pelajaran)) {
            $messages[] = sprintf(
                'File yang diupload tampaknya berasal dari Kelas: %s, Mata Pelajaran: %s. Silakan gunakan template yang sesuai.',
                $templateClass,
                $templateSubject
            );
        }

        return $messages;
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
            $errors[] = 'Data siswa pada baris ini tidak terbaca. Jangan mengubah kolom identitas siswa pada template.';
        } elseif (! ctype_digit($rawSiswaId)) {
            $errors[] = 'Data siswa pada baris ini tidak terbaca. Jangan mengubah kolom identitas siswa pada template.';
        } elseif (in_array($siswaId, $seenStudentIds, true)) {
            $errors[] = 'Siswa ini muncul lebih dari satu kali di file Excel.';
        } elseif (! $siswa) {
            $errors[] = 'Siswa tidak ditemukan pada kelas ini.';
        }

        if ($siswa) {
            $this->validateIdentityColumns($sheet, $columnMap, $rowNumber, $siswa, $mataPelajaran, $errors, $warnings);
        }

        $uploadedValues = [];
        $existingValues = [];
        $fieldErrors = [];

        foreach ($scoreColumns as $column) {
            $key = $column['key'];
            $cellPresent = array_key_exists($key, $columnMap);
            $value = $cellPresent ? $this->valueForKey($sheet, $columnMap, $key, $rowNumber) : null;
            $normalizedValue = $this->normalizeScoreCell($value);

            $uploadedValues[] = [
                'key' => $key,
                'label' => $column['label'],
                'value' => $normalizedValue,
                'raw_value' => $this->normalizeRawCellValue($value),
                'cell_present' => $cellPresent,
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

            $label = $this->scoreLabelForMessage((string) $column['label']);

            if (! is_numeric($value)) {
                $message = "{$label} harus berupa angka 0 sampai 100.";
                $errors[] = $message;
                $fieldErrors[$key][] = $message;
                continue;
            }

            $numericValue = (float) $value;
            if ($numericValue < 0 || $numericValue > 100) {
                $message = "{$label} tidak boleh kurang dari 0 atau lebih dari 100.";
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

    private function scoreLabelForMessage(string $label): string
    {
        return str_starts_with($label, 'Nilai ') ? $label : "Nilai {$label}";
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
            $errors[] = 'NIS tidak sesuai dengan data siswa pada template.';
        }

        if ($nisn !== '' && (string) $siswa->nisn !== $nisn) {
            $errors[] = 'NISN tidak sesuai dengan data siswa pada template.';
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

<?php

namespace App\Services;

use App\Models\MataPelajaran;
use App\Models\Siswa;
use App\Models\TahunAjaran;
use DomainException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class PengajarScoreExcelMultiSheetPreviewService
{
    public const WRONG_WORKBOOK_MESSAGE = 'File Excel ini bukan template Upload Semua Nilai dari aplikasi. Silakan gunakan file dari tombol Download Semua Template Siap.';

    private const STRUCTURE_CHANGED_MESSAGE = 'Format template Excel tidak sesuai atau sudah berubah. Silakan download ulang template terbaru dari Download Semua Template Siap, isi nilainya kembali, lalu upload ulang. Jangan mengubah judul kolom, sheet, atau bagian yang dikunci pada template.';
    private const UNREADABLE_TEMPLATE_MESSAGE = 'Template Excel tidak dapat dibaca dengan benar. Pastikan file berasal dari tombol Download Semua Template Siap dan belum diubah strukturnya.';
    private const CONTEXT_MISMATCH_MESSAGE = 'Sheet "%s" tidak sesuai dengan pembelajaran yang tersedia. Silakan download ulang template terbaru.';
    private const INCOMPLETE_WORKBOOK_MESSAGE = 'Template Upload Semua Nilai tidak lengkap. Silakan download ulang template terbaru dari Download Semua Template Siap.';

    public function __construct(
        private readonly PengajarScoreExcelTemplateService $templateService
    ) {
    }

    /**
     * @param Collection<int, array{mataPelajaran: MataPelajaran, siswas: Collection<int, Siswa>, existingScores: array<int, array<string, mixed>>}> $contexts
     * @return array<string, mixed>
     */
    public function preview(UploadedFile $file, Collection $contexts, TahunAjaran $tahunAjaran): array
    {
        $spreadsheet = IOFactory::load($file->getRealPath());

        try {
            $this->assertBulkWorkbook($spreadsheet);

            $contextsBySubject = $contexts->keyBy(fn (array $context) => (int) $context['mataPelajaran']->id);
            $expectedSubjectIds = $contextsBySubject->keys()->map(fn ($id) => (int) $id)->sort()->values()->all();
            $seenSubjectIds = [];
            $globalErrors = [];
            $sheets = [];

            foreach ($spreadsheet->getWorksheetIterator() as $worksheet) {
                if ($worksheet->getTitle() === PengajarScoreExcelTemplateService::SHEET_PETUNJUK) {
                    continue;
                }

                $sheetPreview = $this->previewSheet($worksheet, $contextsBySubject, $tahunAjaran, $seenSubjectIds);

                if ($sheetPreview['mata_pelajaran_id']) {
                    $seenSubjectIds[] = (int) $sheetPreview['mata_pelajaran_id'];
                }

                $sheets[] = $sheetPreview;
            }

            if ($sheets === []) {
                throw new DomainException(self::WRONG_WORKBOOK_MESSAGE);
            }

            $seenAuthorizedSubjectIds = collect($sheets)
                ->pluck('mata_pelajaran_id')
                ->filter()
                ->map(fn ($id) => (int) $id)
                ->intersect($expectedSubjectIds)
                ->sort()
                ->values()
                ->all();

            if ($seenAuthorizedSubjectIds !== $expectedSubjectIds) {
                $globalErrors[] = self::INCOMPLETE_WORKBOOK_MESSAGE;
            }

            $validSheets = collect($sheets)->where('valid', true)->count();
            $errorSheets = count($sheets) - $validSheets;
            $totalStudents = collect($sheets)->sum(fn (array $sheet) => $sheet['summary']['rows']);
            $totalValues = collect($sheets)->sum(fn (array $sheet) => $sheet['summary']['values']);
            $totalErrors = collect($sheets)->sum(fn (array $sheet) => $sheet['summary']['errors']) + count($globalErrors);

            return [
                'valid' => $globalErrors === [] && $errorSheets === 0,
                'global_errors' => $globalErrors,
                'sheets' => $sheets,
                'summary' => [
                    'total_sheets' => count($sheets),
                    'valid_sheets' => $validSheets,
                    'error_sheets' => $errorSheets,
                    'total_students' => $totalStudents,
                    'total_values' => $totalValues,
                    'total_errors' => $totalErrors,
                ],
            ];
        } finally {
            $spreadsheet->disconnectWorksheets();
        }
    }

    private function assertBulkWorkbook(Spreadsheet $spreadsheet): void
    {
        $instructionSheet = $spreadsheet->getSheetByName(PengajarScoreExcelTemplateService::SHEET_PETUNJUK);

        if (! $instructionSheet) {
            throw new DomainException(self::WRONG_WORKBOOK_MESSAGE);
        }

        $key = trim((string) $instructionSheet->getCell('A'.PengajarScoreExcelTemplateService::MULTI_METADATA_ROW)->getCalculatedValue());
        $value = trim((string) $instructionSheet->getCell('B'.PengajarScoreExcelTemplateService::MULTI_METADATA_ROW)->getCalculatedValue());

        if ($key !== 'workbook_type' || $value !== PengajarScoreExcelTemplateService::MULTI_WORKBOOK_TYPE) {
            throw new DomainException(self::WRONG_WORKBOOK_MESSAGE);
        }
    }

    /**
     * @param Collection<int, array{mataPelajaran: MataPelajaran, siswas: Collection<int, Siswa>, existingScores: array<int, array<string, mixed>>}> $contextsBySubject
     * @param array<int, int> $seenSubjectIds
     * @return array<string, mixed>
     */
    private function previewSheet(Worksheet $sheet, Collection $contextsBySubject, TahunAjaran $tahunAjaran, array $seenSubjectIds): array
    {
        $sheetTitle = $sheet->getTitle();
        $metadata = $this->readMetadata($sheet);
        $contextErrors = [];
        $context = null;
        $mataPelajaran = null;
        $scoreColumns = [];
        $rows = [];
        $payload = [];

        if (! $metadata) {
            $contextErrors[] = self::UNREADABLE_TEMPLATE_MESSAGE;
        } else {
            $subjectId = (int) $metadata['mata_pelajaran_id'];

            if (in_array($subjectId, $seenSubjectIds, true)) {
                $contextErrors[] = sprintf('Sheet "%s" muncul lebih dari satu kali. Silakan download ulang template terbaru.', $sheetTitle);
            }

            $context = $contextsBySubject->get($subjectId);

            if (! $context) {
                $contextErrors[] = sprintf(self::CONTEXT_MISMATCH_MESSAGE, $sheetTitle);
            } else {
                $mataPelajaran = $context['mataPelajaran'];

                if (
                    (int) $metadata['tahun_ajaran_id'] !== (int) $tahunAjaran->id
                    || (int) $metadata['semester'] !== (int) $tahunAjaran->semester
                    || (int) $metadata['kelas_id'] !== (int) $mataPelajaran->kelas_id
                ) {
                    $contextErrors[] = sprintf(self::CONTEXT_MISMATCH_MESSAGE, $sheetTitle);
                }
            }
        }

        if ($mataPelajaran) {
            $scoreColumns = $this->templateService->scoreColumns($mataPelajaran);
            $requiredKeys = collect(PengajarScoreExcelTemplateService::BASE_COLUMNS)
                ->merge($scoreColumns)
                ->pluck('key')
                ->values()
                ->all();
            $columnMap = $this->readColumnMap($sheet);

            foreach ($requiredKeys as $requiredKey) {
                if (! array_key_exists($requiredKey, $columnMap)) {
                    $contextErrors[] = self::STRUCTURE_CHANGED_MESSAGE;
                    break;
                }
            }

            if ($contextErrors === []) {
                [$rows, $payload] = $this->previewRows(
                    $sheet,
                    $columnMap,
                    $requiredKeys,
                    $scoreColumns,
                    $mataPelajaran,
                    $context['siswas'],
                    $context['existingScores']
                );
            }
        }

        $rowErrorCount = collect($rows)->sum(fn (array $row) => count($row['errors']));
        $contextErrorCount = count(array_unique($contextErrors));
        $valueCount = collect($rows)->sum(fn (array $row) => collect($row['uploaded_values'])
            ->where('editable', true)
            ->filter(fn (array $value) => $value['raw_value'] !== null)
            ->count());

        return [
            'sheet_name' => $sheetTitle,
            'kelas' => $mataPelajaran?->kelas?->label_kelas ?? $this->templateContextValue($sheet, 'kelas'),
            'mata_pelajaran' => $mataPelajaran?->nama_pelajaran ?? $this->templateContextValue($sheet, 'mata_pelajaran'),
            'tahun_ajaran' => $tahunAjaran->tahun_ajaran,
            'semester' => (int) $tahunAjaran->semester,
            'kelas_id' => $metadata['kelas_id'] ?? null,
            'mata_pelajaran_id' => $metadata['mata_pelajaran_id'] ?? null,
            'context_errors' => array_values(array_unique($contextErrors)),
            'rows' => $rows,
            'columns' => $scoreColumns,
            'scores_payload' => $payload,
            'saved' => false,
            'saved_at' => null,
            'valid' => $contextErrors === [] && $rowErrorCount === 0,
            'summary' => [
                'rows' => count($rows),
                'valid_rows' => collect($rows)->where('valid', true)->count(),
                'invalid_rows' => collect($rows)->where('valid', false)->count(),
                'values' => $valueCount,
                'errors' => $contextErrorCount + $rowErrorCount,
            ],
        ];
    }

    /**
     * @return array<string, int>|null
     */
    private function readMetadata(Worksheet $sheet): ?array
    {
        $raw = [
            'tahun_ajaran_id' => $this->cellValue($sheet, 1, 3),
            'semester' => $this->cellValue($sheet, 2, 3),
            'kelas_id' => $this->cellValue($sheet, 3, 3),
            'mata_pelajaran_id' => $this->cellValue($sheet, 4, 3),
        ];

        foreach ($raw as $value) {
            if ($value === null || trim((string) $value) === '' || ! is_numeric($value)) {
                return null;
            }
        }

        return array_map(fn ($value) => (int) $value, $raw);
    }

    /**
     * @return array<string, int>
     */
    private function readColumnMap(Worksheet $sheet): array
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
     * @param array<string, int> $columnMap
     * @param array<int, string> $requiredKeys
     * @param array<int, array<string, mixed>> $scoreColumns
     * @param Collection<int, Siswa> $siswas
     * @param array<int, array<string, mixed>> $existingScores
     * @return array{0: array<int, array<string, mixed>>, 1: array<int, array<string, mixed>>}
     */
    private function previewRows(
        Worksheet $sheet,
        array $columnMap,
        array $requiredKeys,
        array $scoreColumns,
        MataPelajaran $mataPelajaran,
        Collection $siswas,
        array $existingScores
    ): array {
        $allowedStudents = $siswas->keyBy('id');
        $seenStudentIds = [];
        $rows = [];
        $payload = [];
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

            $rows[] = $row;

            if ($row['siswa_id']) {
                $seenStudentIds[] = (int) $row['siswa_id'];
                $payload[(int) $row['siswa_id']] = $row['scores_payload'];
            }
        }

        return [$rows, $payload];
    }

    /**
     * @param Collection<int, Siswa> $allowedStudents
     * @param array<int, array<string, mixed>> $existingScores
     * @param array<int, int> $seenStudentIds
     * @return array<string, mixed>
     */
    private function previewRow(
        Worksheet $sheet,
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

        if ($rawSiswaId === '' || ! ctype_digit($rawSiswaId)) {
            $errors[] = 'Data siswa pada baris ini tidak terbaca. Jangan mengubah kolom identitas siswa pada template.';
        } elseif (in_array($siswaId, $seenStudentIds, true)) {
            $errors[] = 'Siswa ini muncul lebih dari satu kali di sheet ini.';
        } elseif (! $siswa) {
            $errors[] = 'Siswa tidak ditemukan pada kelas ini.';
        }

        if ($siswa) {
            $this->validateIdentityColumns($sheet, $columnMap, $rowNumber, $siswa, $mataPelajaran, $errors, $warnings);
        }

        $uploadedValues = [];
        $fieldErrors = [];
        $scoresPayload = [
            'tp' => [],
            'lm' => [],
            'nilai_tes' => '',
            'nilai_non_tes' => '',
        ];

        foreach ($scoreColumns as $column) {
            $key = $column['key'];
            $cellPresent = array_key_exists($key, $columnMap);
            $value = $cellPresent ? $this->valueForKey($sheet, $columnMap, $key, $rowNumber) : null;
            $rawValue = $this->normalizeRawCellValue($value);
            $normalizedValue = $this->normalizeScoreCell($value);
            $existingValue = $siswaId ? $this->existingValueForColumn($existingScores[$siswaId] ?? [], $column) : null;

            $uploadedValues[] = [
                'key' => $key,
                'label' => $column['label'],
                'value' => $normalizedValue,
                'raw_value' => $rawValue,
                'existing_value' => $existingValue,
                'cell_present' => $cellPresent,
                'editable' => (bool) ($column['editable'] ?? false),
                'type' => $column['type'] ?? null,
                'lingkup_materi_id' => $column['lingkup_materi_id'] ?? null,
                'tujuan_pembelajaran_id' => $column['tujuan_pembelajaran_id'] ?? null,
            ];

            if (($column['editable'] ?? false) === true) {
                $payloadValue = $cellPresent ? $normalizedValue : ($existingValue ?? '');

                match ($column['type'] ?? null) {
                    'tp' => $scoresPayload['tp'][$column['lingkup_materi_id']][$column['tujuan_pembelajaran_id']] = $payloadValue,
                    'lm' => $scoresPayload['lm'][$column['lingkup_materi_id']] = $payloadValue,
                    'semester' => $scoresPayload[$key] = $payloadValue,
                    default => null,
                };
            }

            if ($rawValue === null) {
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
            'uploaded_values' => $uploadedValues,
            'errors' => $errors,
            'warnings' => $warnings,
            'field_errors' => $fieldErrors,
            'scores_payload' => $scoresPayload,
            'valid' => empty($errors),
            'status' => empty($errors) ? 'Valid' : 'Tidak valid',
        ];
    }

    /**
     * @param array<int, string> $errors
     * @param array<int, string> $warnings
     */
    private function validateIdentityColumns(
        Worksheet $sheet,
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

    private function scoreLabelForMessage(string $label): string
    {
        return str_starts_with($label, 'Nilai ') ? $label : "Nilai {$label}";
    }

    private function existingValueForColumn(array $existingScores, array $column): mixed
    {
        return match ($column['type']) {
            'tp' => $existingScores['tp'][$column['lingkup_materi_id']][$column['tujuan_pembelajaran_id']] ?? null,
            'lm' => $existingScores['lm'][$column['lingkup_materi_id']] ?? null,
            default => $existingScores[$column['key']] ?? null,
        };
    }

    private function templateContextValue(Worksheet $sheet, string $key): string
    {
        $columnMap = $this->readColumnMap($sheet);

        return trim((string) $this->valueForKey(
            $sheet,
            $columnMap,
            $key,
            PengajarScoreExcelTemplateService::DATA_START_ROW
        ));
    }

    private function isBlankRow(Worksheet $sheet, int $rowNumber, array $columnMap, array $keys): bool
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

    private function valueForKey(Worksheet $sheet, array $columnMap, string $key, int $rowNumber): mixed
    {
        if (! isset($columnMap[$key])) {
            return null;
        }

        return $this->cellValue($sheet, $columnMap[$key], $rowNumber);
    }

    private function cellValue(Worksheet $sheet, int $columnIndex, int $rowNumber): mixed
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

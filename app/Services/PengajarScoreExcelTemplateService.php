<?php

namespace App\Services;

use App\Models\MataPelajaran;
use App\Models\Siswa;
use App\Models\TahunAjaran;
use App\Models\Guru;
use Illuminate\Support\Collection;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Protection;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class PengajarScoreExcelTemplateService
{
    public const SHEET_NILAI = 'Nilai';
    public const SHEET_PETUNJUK = 'Petunjuk';
    public const LABEL_ROW = 4;
    public const KEY_ROW = 5;
    public const DATA_START_ROW = 6;
    public const MULTI_METADATA_ROW = 100;
    public const MULTI_WORKBOOK_TYPE = 'pengajar_multi_component_score';

    public const BASE_COLUMNS = [
        ['key' => 'siswa_id', 'label' => 'siswa_id'],
        ['key' => 'nis', 'label' => 'NIS'],
        ['key' => 'nisn', 'label' => 'NISN'],
        ['key' => 'nama_siswa', 'label' => 'Nama Siswa'],
        ['key' => 'kelas', 'label' => 'Kelas'],
        ['key' => 'mata_pelajaran', 'label' => 'Mata Pelajaran'],
    ];

    private const LOCKED_FILL = 'FFF3F4F6';
    private const EDITABLE_FILL = 'FFFFF2CC';
    private const HEADER_FILL = 'FFE2F0D9';

    /**
     * @param Collection<int, Siswa> $siswas
     */
    public function createWorkbook(MataPelajaran $mataPelajaran, Collection $siswas, TahunAjaran $tahunAjaran): Spreadsheet
    {
        $spreadsheet = new Spreadsheet();

        $this->buildScoreSheet($spreadsheet->getActiveSheet(), $mataPelajaran, $siswas, $tahunAjaran);
        $this->buildInstructionSheet($spreadsheet, $mataPelajaran, $tahunAjaran);

        $spreadsheet->setActiveSheetIndex(0);

        return $spreadsheet;
    }

    /**
     * @param Collection<int, array{mataPelajaran: MataPelajaran, siswas: Collection<int, Siswa>}> $contexts
     */
    public function createMultiSheetWorkbook(Collection $contexts, TahunAjaran $tahunAjaran): Spreadsheet
    {
        $spreadsheet = new Spreadsheet();
        $usedTitles = [];

        foreach ($contexts->values() as $index => $context) {
            $sheet = $index === 0
                ? $spreadsheet->getActiveSheet()
                : $spreadsheet->createSheet();
            $title = $this->uniqueMultiSheetTitle($context['mataPelajaran'], $usedTitles);

            $this->buildScoreSheet(
                $sheet,
                $context['mataPelajaran'],
                $context['siswas'],
                $tahunAjaran,
                $title
            );
        }

        $this->buildMultiInstructionSheet($spreadsheet, $contexts, $tahunAjaran);
        $spreadsheet->setActiveSheetIndex(0);

        return $spreadsheet;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function scoreColumns(MataPelajaran $mataPelajaran): array
    {
        $columns = [];

        foreach ($mataPelajaran->lingkupMateris as $lingkupMateri) {
            foreach ($lingkupMateri->tujuanPembelajarans as $tujuanPembelajaran) {
                $columns[] = [
                    'key' => "tp_{$lingkupMateri->id}_{$tujuanPembelajaran->id}",
                    'label' => 'TP '.$tujuanPembelajaran->display_kode_tp,
                    'type' => 'tp',
                    'lingkup_materi_id' => (int) $lingkupMateri->id,
                    'tujuan_pembelajaran_id' => (int) $tujuanPembelajaran->id,
                    'editable' => true,
                ];
            }
        }

        foreach ($mataPelajaran->lingkupMateris as $lingkupMateri) {
            $columns[] = [
                'key' => "lm_{$lingkupMateri->id}",
                'label' => 'LM '.$lingkupMateri->judul_lingkup_materi,
                'type' => 'lm',
                'lingkup_materi_id' => (int) $lingkupMateri->id,
                'editable' => true,
            ];
        }

        return array_merge($columns, [
            ['key' => 'na_tp', 'label' => 'NA Sumatif TP', 'type' => 'aggregate', 'editable' => false],
            ['key' => 'na_lm', 'label' => 'NA Sumatif LM', 'type' => 'aggregate', 'editable' => false],
            ['key' => 'nilai_tes', 'label' => 'Nilai Tes', 'type' => 'semester', 'editable' => true],
            ['key' => 'nilai_non_tes', 'label' => 'Nilai Non-Tes', 'type' => 'semester', 'editable' => true],
            ['key' => 'nilai_akhir_semester', 'label' => 'NA Sumatif Akhir Semester', 'type' => 'aggregate', 'editable' => false],
            ['key' => 'nilai_akhir_rapor', 'label' => 'Nilai Akhir Rapor', 'type' => 'aggregate', 'editable' => false],
        ]);
    }

    public function scoreSheetTitle(MataPelajaran $mataPelajaran): string
    {
        $classLabel = $this->shortClassLabel($mataPelajaran);
        $subjectLabel = $this->normalizeWhitespace($mataPelajaran->nama_pelajaran);
        $withSubject = $this->sanitizeSheetTitleCandidate(trim($classLabel.' - '.$subjectLabel, ' -'));

        if ($withSubject !== '' && mb_strlen($withSubject, 'UTF-8') <= 31) {
            return $this->finalizeSheetTitle($withSubject);
        }

        return $this->finalizeSheetTitle($this->sanitizeSheetTitleCandidate($classLabel));
    }

    public function downloadFilename(MataPelajaran $mataPelajaran): string
    {
        return sprintf(
            'Template_Nilai_%s_%s.xlsx',
            $this->filenameSegment($this->shortClassLabel($mataPelajaran), 'Kelas'),
            $this->filenameSegment($mataPelajaran->nama_pelajaran, 'Mapel')
        );
    }

    public function multiDownloadFilename(TahunAjaran $tahunAjaran, ?Guru $guru = null): string
    {
        $owner = $guru
            ? $this->filenameSegment($guru->nama, 'Pengajar')
            : 'Semua_Pembelajaran';
        $semester = ((int) $tahunAjaran->semester === 2) ? 'Genap' : 'Ganjil';

        return sprintf(
            'Template_Nilai_%s_%s_%s.xlsx',
            $owner,
            $this->filenameSegment($tahunAjaran->tahun_ajaran, 'Tahun_Ajaran'),
            $semester
        );
    }

    private function buildScoreSheet(
        Worksheet $sheet,
        MataPelajaran $mataPelajaran,
        Collection $siswas,
        TahunAjaran $tahunAjaran,
        ?string $sheetTitle = null
    ): void {
        $sheet->setTitle($sheetTitle ?? $this->scoreSheetTitle($mataPelajaran));

        $baseColumns = $this->templateBaseColumns();
        $scoreColumns = $this->scoreColumns($mataPelajaran);
        $allColumns = array_merge($baseColumns, $scoreColumns);
        $lastColumn = $this->columnLetter(count($allColumns));

        $sheet->setCellValue('A1', implode("\n", [
            'Template Nilai '.$this->shortClassLabel($mataPelajaran).' - '.$this->normalizeWhitespace($mataPelajaran->nama_pelajaran),
            'Kelas: '.$this->classContextLabel($mataPelajaran),
            'Mata Pelajaran: '.$this->normalizeWhitespace($mataPelajaran->nama_pelajaran),
            'Tahun Ajaran: '.$tahunAjaran->tahun_ajaran,
            'Semester: '.$tahunAjaran->semester,
            'Isi hanya kolom nilai. Kolom identitas siswa, kelas, dan mata pelajaran tidak perlu diubah.',
        ]));
        $sheet->mergeCells("A1:{$lastColumn}1");
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);
        $sheet->getStyle('A1')->getAlignment()->setWrapText(true);
        $sheet->getRowDimension(1)->setRowHeight(112);

        $sheet->fromArray(['tahun_ajaran_id', 'semester', 'kelas_id', 'mata_pelajaran_id'], null, 'A2');
        $sheet->fromArray([
            $tahunAjaran->id,
            $tahunAjaran->semester,
            $mataPelajaran->kelas_id,
            $mataPelajaran->id,
        ], null, 'A3');

        foreach ($allColumns as $index => $column) {
            $columnLetter = $this->columnLetter($index + 1);
            $sheet->setCellValue("{$columnLetter}".self::LABEL_ROW, $column['label']);
            $sheet->setCellValue("{$columnLetter}".self::KEY_ROW, $column['key']);
        }

        $row = self::DATA_START_ROW;
        foreach ($siswas->sortBy('nama')->values() as $siswa) {
            $kelasLabel = $mataPelajaran->kelas?->label_kelas ?? '';
            $mapelLabel = $mataPelajaran->nama_pelajaran;
            $studentNumber = $row - self::DATA_START_ROW + 1;

            $sheet->setCellValue("A{$row}", $siswa->id);
            $sheet->setCellValue("B{$row}", $studentNumber);
            $this->setStringCell($sheet, "C{$row}", $siswa->nis);
            $this->setStringCell($sheet, "D{$row}", $siswa->nisn);
            $sheet->setCellValue("E{$row}", $siswa->nama);
            $sheet->setCellValue("F{$row}", $kelasLabel);
            $sheet->setCellValue("G{$row}", $mapelLabel);

            $row++;
        }

        $sheet->freezePane('H6');
        $sheet->getStyle("A".self::LABEL_ROW.":{$lastColumn}".self::KEY_ROW)->getFont()->setBold(true);
        $sheet->getStyle("A".self::LABEL_ROW.":{$lastColumn}".self::LABEL_ROW)
            ->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB(self::HEADER_FILL);
        $sheet->getStyle("A".self::KEY_ROW.":{$lastColumn}".self::KEY_ROW)
            ->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB(self::LOCKED_FILL);
        $sheet->getStyle("A:{$lastColumn}")->getAlignment()->setVertical(Alignment::VERTICAL_TOP);

        foreach (range(1, count($allColumns)) as $columnIndex) {
            $columnLetter = $this->columnLetter($columnIndex);
            $sheet->getColumnDimension($columnLetter)->setWidth(match ($columnIndex) {
                1, 6, 7 => 16,
                2 => 8,
                3, 4 => 18,
                5 => 28,
                default => 16,
            });
        }

        $lastDataRow = max(self::DATA_START_ROW, $row - 1);
        $sheet->getStyle("A1:{$lastColumn}{$lastDataRow}")
            ->getProtection()
            ->setLocked(Protection::PROTECTION_PROTECTED);
        $sheet->getStyle("A".self::DATA_START_ROW.":G{$lastDataRow}")
            ->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB(self::LOCKED_FILL);

        foreach ($scoreColumns as $index => $column) {
            $columnLetter = $this->columnLetter(count($baseColumns) + $index + 1);
            $scoreDataRange = "{$columnLetter}".self::DATA_START_ROW.":{$columnLetter}{$lastDataRow}";

            $sheet->getStyle($scoreDataRange)
                ->getAlignment()
                ->setHorizontal(Alignment::HORIZONTAL_CENTER);

            if (($column['editable'] ?? false) === true) {
                $sheet->getStyle($scoreDataRange)
                    ->getProtection()
                    ->setLocked(Protection::PROTECTION_UNPROTECTED);
                $sheet->getStyle($scoreDataRange)
                    ->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB(self::EDITABLE_FILL);

                continue;
            }

            $sheet->getStyle($scoreDataRange)
                ->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB(self::LOCKED_FILL);
        }

        $sheet->getRowDimension(2)->setVisible(false);
        $sheet->getRowDimension(3)->setVisible(false);
        $sheet->getRowDimension(self::KEY_ROW)->setVisible(false);
        foreach (['A', 'F', 'G'] as $hiddenColumn) {
            $sheet->getColumnDimension($hiddenColumn)->setVisible(false);
        }
        $sheet->getProtection()->setSheet(true);
        $sheet->getProtection()->setPassword('nilai');
        $sheet->getProtection()->setSort(true);
        $sheet->getProtection()->setAutoFilter(true);
    }

    private function buildInstructionSheet(Spreadsheet $spreadsheet, MataPelajaran $mataPelajaran, TahunAjaran $tahunAjaran): void
    {
        $sheet = $spreadsheet->createSheet();
        $sheet->setTitle(self::SHEET_PETUNJUK);

        $instructions = [
            ['Template Import Nilai Pengajar'],
            ['Kelas: '.$this->classContextLabel($mataPelajaran)],
            ["Mata pelajaran: {$mataPelajaran->nama_pelajaran}"],
            ["Tahun ajaran: {$tahunAjaran->tahun_ajaran}"],
            ["Semester: {$tahunAjaran->semester}"],
            ['Sheet nilai: '.$this->scoreSheetTitle($mataPelajaran)],
            ['Template ini khusus untuk kelas dan mata pelajaran yang tertera pada sheet nilai utama.'],
            ['Isi hanya kolom nilai. Kolom identitas siswa, kelas, dan mata pelajaran tidak perlu diubah.'],
            ['Jangan mengubah siswa_id. Nama siswa hanya untuk verifikasi manusia.'],
            ['Isi nilai pada kolom TP, LM, Nilai Tes, dan Nilai Non-Tes.'],
            ['Kolom NA dan Nilai Akhir adalah referensi kalkulasi. Perhitungan final tetap dilakukan oleh aplikasi saat fase simpan nanti.'],
            ['Nilai boleh dikosongkan. Jika diisi, nilai harus angka 0 sampai 100.'],
        ];

        $sheet->fromArray($instructions, null, 'A1');
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);
        $sheet->getColumnDimension('A')->setWidth(120);
        $sheet->getStyle('A:A')->getAlignment()->setWrapText(true);
        $sheet->getProtection()->setSheet(true);
    }

    /**
     * @param Collection<int, array{mataPelajaran: MataPelajaran, siswas: Collection<int, Siswa>}> $contexts
     */
    private function buildMultiInstructionSheet(Spreadsheet $spreadsheet, Collection $contexts, TahunAjaran $tahunAjaran): void
    {
        $sheet = $spreadsheet->createSheet();
        $sheet->setTitle(self::SHEET_PETUNJUK);

        $instructions = [
            ['Template Nilai Komponen Pengajar'],
            ["Tahun ajaran: {$tahunAjaran->tahun_ajaran}"],
            ["Semester: {$tahunAjaran->semester}"],
            ['Setiap sheet berisi satu kelas dan mata pelajaran yang sudah lengkap Lingkup Materi dan Tujuan Pembelajarannya.'],
            ['Isi hanya kolom nilai. Kolom identitas siswa, kelas, dan mata pelajaran tidak perlu diubah.'],
            ['Template ini untuk nilai komponen. Perhitungan nilai akhir tetap dilakukan oleh aplikasi saat guru menekan Simpan pada halaman input nilai.'],
            ['Mode nilai akhir manual dari Excel belum diaktifkan pada template ini.'],
            [''],
            ['Daftar sheet:'],
        ];

        foreach ($contexts as $context) {
            $mataPelajaran = $context['mataPelajaran'];
            $instructions[] = [
                $this->multiSheetTitle($mataPelajaran).' - '.$this->classContextLabel($mataPelajaran).' - '.$mataPelajaran->nama_pelajaran,
            ];
        }

        $sheet->fromArray($instructions, null, 'A1');
        $sheet->setCellValue('A'.self::MULTI_METADATA_ROW, 'workbook_type');
        $sheet->setCellValue('B'.self::MULTI_METADATA_ROW, self::MULTI_WORKBOOK_TYPE);
        $sheet->getRowDimension(self::MULTI_METADATA_ROW)->setVisible(false);
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);
        $sheet->getColumnDimension('A')->setWidth(120);
        $sheet->getStyle('A:A')->getAlignment()->setWrapText(true);
        $sheet->getProtection()->setSheet(true);
    }

    /**
     * @return array<int, array<string, string>>
     */
    private function templateBaseColumns(): array
    {
        return [
            ['key' => 'siswa_id', 'label' => 'siswa_id'],
            ['key' => 'no', 'label' => 'No'],
            ['key' => 'nis', 'label' => 'NIS'],
            ['key' => 'nisn', 'label' => 'NISN'],
            ['key' => 'nama_siswa', 'label' => 'Nama Siswa'],
            ['key' => 'kelas', 'label' => 'Kelas'],
            ['key' => 'mata_pelajaran', 'label' => 'Mata Pelajaran'],
        ];
    }

    private function setStringCell($sheet, string $cell, ?string $value): void
    {
        $sheet->setCellValueExplicit($cell, (string) ($value ?? ''), DataType::TYPE_STRING);
    }

    private function shortClassLabel(MataPelajaran $mataPelajaran): string
    {
        $kelas = $mataPelajaran->kelas;

        if (! $kelas) {
            return 'Kelas';
        }

        $nomorKelas = $this->normalizeWhitespace((string) $kelas->nomor_kelas);
        $namaKelas = $this->normalizeWhitespace((string) $kelas->nama_kelas);
        $label = trim($nomorKelas.' '.$namaKelas);

        return $label !== '' ? $label : 'Kelas';
    }

    private function classContextLabel(MataPelajaran $mataPelajaran): string
    {
        $label = $this->shortClassLabel($mataPelajaran);

        if (str_starts_with(mb_strtolower($label, 'UTF-8'), 'kelas ')) {
            return $label;
        }

        return 'Kelas '.$label;
    }

    private function sanitizeSheetTitleCandidate(string $title): string
    {
        $title = preg_replace('/[\\\\\\/\\?\\*\\[\\]\\:]+/u', ' ', $title) ?? '';
        $title = $this->normalizeWhitespace($title);

        return trim($title, " '");
    }

    private function finalizeSheetTitle(string $title): string
    {
        $title = $title !== '' ? $title : self::SHEET_NILAI;

        if (mb_strtolower($title, 'UTF-8') === mb_strtolower(self::SHEET_PETUNJUK, 'UTF-8')) {
            $title = self::SHEET_NILAI.' '.$title;
        }

        if (mb_strlen($title, 'UTF-8') > 31) {
            $title = rtrim(mb_substr($title, 0, 31, 'UTF-8'));
        }

        return $title !== '' ? $title : self::SHEET_NILAI;
    }

    private function multiSheetTitle(MataPelajaran $mataPelajaran): string
    {
        $title = $this->sanitizeSheetTitleCandidate(trim(
            $this->shortClassLabel($mataPelajaran).' - '.$this->normalizeWhitespace($mataPelajaran->nama_pelajaran),
            ' -'
        ));

        return $this->finalizeSheetTitle($title);
    }

    /**
     * @param array<int, string> $usedTitles
     */
    private function uniqueMultiSheetTitle(MataPelajaran $mataPelajaran, array &$usedTitles): string
    {
        $baseTitle = $this->multiSheetTitle($mataPelajaran);
        $candidate = $baseTitle;
        $suffix = 2;

        while (in_array(mb_strtolower($candidate, 'UTF-8'), $usedTitles, true)) {
            $marker = " ({$suffix})";
            $baseLength = 31 - mb_strlen($marker, 'UTF-8');
            $candidate = rtrim(mb_substr($baseTitle, 0, $baseLength, 'UTF-8')).$marker;
            $suffix++;
        }

        $usedTitles[] = mb_strtolower($candidate, 'UTF-8');

        return $candidate;
    }

    private function filenameSegment(?string $value, string $fallback): string
    {
        $segment = preg_replace('/[^\pL\pN]+/u', '_', (string) $value) ?? '';
        $segment = trim($segment, '_');

        if ($segment === '') {
            return $fallback;
        }

        return mb_substr($segment, 0, 80, 'UTF-8');
    }

    private function normalizeWhitespace(?string $value): string
    {
        return trim(preg_replace('/\s+/u', ' ', (string) $value) ?? '');
    }

    private function columnLetter(int $columnIndex): string
    {
        $letter = '';

        while ($columnIndex > 0) {
            $columnIndex--;
            $letter = chr(65 + ($columnIndex % 26)).$letter;
            $columnIndex = intdiv($columnIndex, 26);
        }

        return $letter;
    }
}

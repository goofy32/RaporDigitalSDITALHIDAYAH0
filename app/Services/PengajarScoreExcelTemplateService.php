<?php

namespace App\Services;

use App\Models\MataPelajaran;
use App\Models\Siswa;
use App\Models\TahunAjaran;
use Illuminate\Support\Collection;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;

class PengajarScoreExcelTemplateService
{
    public const SHEET_NILAI = 'Nilai';
    public const SHEET_PETUNJUK = 'Petunjuk';
    public const LABEL_ROW = 4;
    public const KEY_ROW = 5;
    public const DATA_START_ROW = 6;

    public const BASE_COLUMNS = [
        ['key' => 'siswa_id', 'label' => 'siswa_id'],
        ['key' => 'nis', 'label' => 'NIS'],
        ['key' => 'nisn', 'label' => 'NISN'],
        ['key' => 'nama_siswa', 'label' => 'Nama Siswa'],
        ['key' => 'kelas', 'label' => 'Kelas'],
        ['key' => 'mata_pelajaran', 'label' => 'Mata Pelajaran'],
    ];

    /**
     * @param Collection<int, Siswa> $siswas
     */
    public function createWorkbook(MataPelajaran $mataPelajaran, Collection $siswas, TahunAjaran $tahunAjaran): Spreadsheet
    {
        $spreadsheet = new Spreadsheet();

        $this->buildScoreSheet($spreadsheet, $mataPelajaran, $siswas, $tahunAjaran);
        $this->buildInstructionSheet($spreadsheet, $mataPelajaran, $tahunAjaran);

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

    private function buildScoreSheet(
        Spreadsheet $spreadsheet,
        MataPelajaran $mataPelajaran,
        Collection $siswas,
        TahunAjaran $tahunAjaran
    ): void {
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle(self::SHEET_NILAI);

        $baseColumns = self::BASE_COLUMNS;
        $scoreColumns = $this->scoreColumns($mataPelajaran);
        $allColumns = array_merge($baseColumns, $scoreColumns);
        $lastColumn = $this->columnLetter(count($allColumns));

        $sheet->setCellValue('A1', 'Template Import Nilai Pengajar');
        $sheet->mergeCells("A1:{$lastColumn}1");
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);

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

            $sheet->setCellValue("A{$row}", $siswa->id);
            $this->setStringCell($sheet, "B{$row}", $siswa->nis);
            $this->setStringCell($sheet, "C{$row}", $siswa->nisn);
            $sheet->setCellValue("D{$row}", $siswa->nama);
            $sheet->setCellValue("E{$row}", $kelasLabel);
            $sheet->setCellValue("F{$row}", $mapelLabel);

            $row++;
        }

        $sheet->freezePane('G6');
        $sheet->getStyle("A".self::LABEL_ROW.":{$lastColumn}".self::KEY_ROW)->getFont()->setBold(true);
        $sheet->getStyle("A".self::LABEL_ROW.":{$lastColumn}".self::LABEL_ROW)
            ->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFE2F0D9');
        $sheet->getStyle("A".self::KEY_ROW.":{$lastColumn}".self::KEY_ROW)
            ->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFF3F4F6');
        $sheet->getStyle("A:{$lastColumn}")->getAlignment()->setVertical(Alignment::VERTICAL_TOP);

        foreach (range(1, count($allColumns)) as $columnIndex) {
            $columnLetter = $this->columnLetter($columnIndex);
            $sheet->getColumnDimension($columnLetter)->setWidth($columnIndex <= 6 ? 18 : 16);
        }

        foreach ($scoreColumns as $index => $column) {
            if (($column['editable'] ?? false) === true) {
                continue;
            }

            $columnLetter = $this->columnLetter(count($baseColumns) + $index + 1);
            $sheet->getStyle("{$columnLetter}".self::DATA_START_ROW.":{$columnLetter}".max(self::DATA_START_ROW, $row - 1))
                ->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFF9FAFB');
        }
    }

    private function buildInstructionSheet(Spreadsheet $spreadsheet, MataPelajaran $mataPelajaran, TahunAjaran $tahunAjaran): void
    {
        $sheet = $spreadsheet->createSheet();
        $sheet->setTitle(self::SHEET_PETUNJUK);

        $instructions = [
            ['Template Import Nilai Pengajar'],
            ["Tahun ajaran: {$tahunAjaran->tahun_ajaran}, semester {$tahunAjaran->semester}."],
            ['Template ini khusus untuk kelas dan mata pelajaran yang tertera pada sheet Nilai.'],
            ['Jangan mengubah siswa_id. Nama siswa hanya untuk verifikasi manusia.'],
            ['Isi nilai pada kolom TP, LM, Nilai Tes, dan Nilai Non-Tes.'],
            ['Kolom NA dan Nilai Akhir adalah referensi kalkulasi. Perhitungan final tetap dilakukan oleh aplikasi saat fase simpan nanti.'],
            ['Nilai boleh dikosongkan. Jika diisi, nilai harus angka 0 sampai 100.'],
            ["Kelas: ".($mataPelajaran->kelas?->label_kelas ?? '-')],
            ["Mata pelajaran: {$mataPelajaran->nama_pelajaran}"],
        ];

        $sheet->fromArray($instructions, null, 'A1');
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);
        $sheet->getColumnDimension('A')->setWidth(120);
        $sheet->getStyle('A:A')->getAlignment()->setWrapText(true);
    }

    private function setStringCell($sheet, string $cell, ?string $value): void
    {
        $sheet->setCellValueExplicit($cell, (string) ($value ?? ''), DataType::TYPE_STRING);
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

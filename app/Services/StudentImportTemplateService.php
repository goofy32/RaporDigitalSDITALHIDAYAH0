<?php

namespace App\Services;

use App\Models\Kelas;
use App\Models\TahunAjaran;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Cell\DataValidation;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;

class StudentImportTemplateService
{
    public const HEADERS = [
        'nis',
        'nisn',
        'nama',
        'tanggal_lahir',
        'jenis_kelamin',
        'agama',
        'alamat',
        'kelas',
        'nama_ayah',
        'nama_ibu',
        'pekerjaan_ayah',
        'pekerjaan_ibu',
        'alamat_orangtua',
        'photo',
    ];

    public function createWorkbook(TahunAjaran $tahunAjaran): Spreadsheet
    {
        $spreadsheet = new Spreadsheet;
        $classes = $this->activeClasses($tahunAjaran);

        $this->buildTemplateSheet($spreadsheet, $tahunAjaran, $classes);
        $this->buildClassSheet($spreadsheet, $classes);
        $this->buildInstructionSheet($spreadsheet, $tahunAjaran, $classes->isEmpty());

        $spreadsheet->setActiveSheetIndex(0);

        return $spreadsheet;
    }

    private function buildTemplateSheet(Spreadsheet $spreadsheet, TahunAjaran $tahunAjaran, $classes): void
    {
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Template Siswa');
        $sheet->fromArray(self::HEADERS, null, 'A1');

        $firstClass = $classes->first();
        $secondClass = $classes->skip(1)->first();
        $firstClassLabel = $firstClass ? $this->canonicalClassLabel($firstClass) : 'Kelas 1 A';
        $secondClassLabel = $secondClass ? $this->canonicalClassLabel($secondClass) : $firstClassLabel;

        $examples = [
            [
                '2601001',
                '9900000001',
                'Siswa Contoh Satu',
                '2015-01-15',
                'L',
                'Islam',
                'Jalan Contoh 1',
                $firstClassLabel,
                'Ayah Contoh',
                'Ibu Contoh',
                'Wiraswasta',
                'Ibu Rumah Tangga',
                'Jalan Orang Tua 1',
                '',
            ],
            [
                '2601002',
                '9900000002',
                'Siswa Contoh Dua',
                '2015-02-20',
                'P',
                'Islam',
                'Jalan Contoh 2',
                $secondClassLabel,
                'Ayah Contoh',
                'Ibu Contoh',
                'Karyawan',
                'Ibu Rumah Tangga',
                'Jalan Orang Tua 2',
                '',
            ],
        ];

        $sheet->fromArray($examples, null, 'A2');
        $sheet->getStyle('A:B')->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_TEXT);
        foreach ($examples as $index => $example) {
            $row = $index + 2;
            $sheet->setCellValueExplicit("A{$row}", (string) $example[0], DataType::TYPE_STRING);
            $sheet->setCellValueExplicit("B{$row}", (string) $example[1], DataType::TYPE_STRING);
        }
        $sheet->freezePane('A2');
        $sheet->getStyle('A1:N1')->getFont()->setBold(true);
        $sheet->getStyle('A1:N1')->getFill()
            ->setFillType(Fill::FILL_SOLID)
            ->getStartColor()->setARGB('FFE2F0D9');
        $sheet->getStyle('A:N')->getAlignment()->setVertical(Alignment::VERTICAL_TOP);

        foreach (range('A', 'N') as $column) {
            $sheet->getColumnDimension($column)->setWidth($column === 'G' || $column === 'M' ? 28 : 18);
        }

        $this->addGenderValidation($sheet);

        if ($classes->isNotEmpty()) {
            $this->addClassValidation($sheet, $classes->count());
        }

        $sheet->setCellValue('A5', 'Catatan: hapus baris contoh sebelum import data asli.');
        $sheet->mergeCells('A5:N5');
        $sheet->getStyle('A5')->getFont()->setItalic(true);
    }

    private function buildClassSheet(Spreadsheet $spreadsheet, $classes): void
    {
        $sheet = $spreadsheet->createSheet();
        $sheet->setTitle('Daftar Kelas');
        $sheet->fromArray(['kelas', 'nomor_kelas', 'nama_kelas'], null, 'A1');

        $row = 2;
        foreach ($classes as $class) {
            $sheet->fromArray([
                $this->canonicalClassLabel($class),
                $class->nomor_kelas,
                $class->nama_kelas,
            ], null, "A{$row}");
            $row++;
        }

        $sheet->getStyle('A1:C1')->getFont()->setBold(true);
        $sheet->getColumnDimension('A')->setWidth(28);
        $sheet->getColumnDimension('B')->setWidth(14);
        $sheet->getColumnDimension('C')->setWidth(24);
    }

    private function buildInstructionSheet(Spreadsheet $spreadsheet, TahunAjaran $tahunAjaran, bool $hasNoClasses): void
    {
        $sheet = $spreadsheet->createSheet();
        $sheet->setTitle('Petunjuk');

        $instructions = [
            ['Template Import Siswa'],
            ["Tahun ajaran aktif: {$tahunAjaran->tahun_ajaran} semester {$tahunAjaran->semester}."],
            ['Kelas harus sudah dibuat di sistem sebelum import.'],
            ['Import tidak akan membuat kelas otomatis.'],
            ['Kolom kelas harus sama dengan salah satu kelas pada sheet "Daftar Kelas".'],
            ['Format tanggal_lahir: YYYY-MM-DD.'],
            ['jenis_kelamin: L atau P.'],
            ['nis dan nisn wajib berisi maksimal 10 digit angka, disimpan sebagai teks, dan unik baik di file maupun di database.'],
            ['Kolom photo boleh dikosongkan.'],
        ];

        if ($hasNoClasses) {
            $instructions[] = ['PERINGATAN: belum ada kelas pada tahun ajaran aktif. Buat kelas terlebih dahulu sebelum import.'];
        }

        $sheet->fromArray($instructions, null, 'A1');
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);
        $sheet->getColumnDimension('A')->setWidth(110);
        $sheet->getStyle('A:A')->getAlignment()->setWrapText(true);
    }

    private function addGenderValidation($sheet): void
    {
        for ($row = 2; $row <= 1001; $row++) {
            $validation = $sheet->getCell("E{$row}")->getDataValidation();
            $validation->setType(DataValidation::TYPE_LIST);
            $validation->setErrorStyle(DataValidation::STYLE_STOP);
            $validation->setAllowBlank(false);
            $validation->setShowDropDown(true);
            $validation->setFormula1('"L,P"');
        }
    }

    private function addClassValidation($sheet, int $classCount): void
    {
        $lastClassRow = $classCount + 1;

        for ($row = 2; $row <= 1001; $row++) {
            $validation = $sheet->getCell("H{$row}")->getDataValidation();
            $validation->setType(DataValidation::TYPE_LIST);
            $validation->setErrorStyle(DataValidation::STYLE_STOP);
            $validation->setAllowBlank(false);
            $validation->setShowDropDown(true);
            $validation->setFormula1("'Daftar Kelas'!".'$A$2:$A$'.$lastClassRow);
        }
    }

    private function activeClasses(TahunAjaran $tahunAjaran)
    {
        return Kelas::query()
            ->where('tahun_ajaran_id', $tahunAjaran->id)
            ->orderBy('nomor_kelas')
            ->orderBy('nama_kelas')
            ->get();
    }

    private function canonicalClassLabel(Kelas $class): string
    {
        $number = trim((string) $class->nomor_kelas);
        $name = trim(preg_replace('/\s+/u', ' ', (string) $class->nama_kelas) ?? '');
        $label = trim('Kelas '.$number);

        return $name === '' ? $label : $label.' '.$name;
    }
}

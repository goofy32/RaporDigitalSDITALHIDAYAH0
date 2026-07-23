<?php

namespace Tests\Unit;

use App\Services\ReportIdentityLayoutStabilizer;
use PHPUnit\Framework\TestCase;
use ZipArchive;

class ReportIdentityLayoutStabilizerTest extends TestCase
{
    public function test_identity_text_boxes_are_relaxed_without_touching_unrelated_text_boxes(): void
    {
        $stabilizer = new ReportIdentityLayoutStabilizer();

        $xml = <<<'XML'
<w:document xmlns:w="w" xmlns:wps="wps" xmlns:a="a" xmlns:v="v">
    <wps:wsp><wps:spPr><a:xfrm><a:ext cx="686435" cy="270510"/></a:xfrm></wps:spPr><wps:txbx><w:txbxContent><w:tbl><w:tr><w:trPr><w:trHeight w:val="205"/></w:trPr><w:tc><w:p><w:pPr><w:spacing w:line="165" w:lineRule="exact"/></w:pPr><w:r><w:t>Nama</w:t></w:r><w:r><w:t>Siswa</w:t></w:r></w:p></w:tc></w:tr><w:tr><w:trPr><w:trHeight w:val="205"/></w:trPr><w:tc><w:p><w:pPr><w:spacing w:line="165" w:lineRule="exact"/></w:pPr><w:r><w:t>NISN/NIS</w:t></w:r></w:p></w:tc></w:tr></w:tbl></w:txbxContent></wps:txbx><wps:bodyPr><a:noAutofit/></wps:bodyPr></wps:wsp>
    <v:shape style="position:absolute;height:21.3pt;width:54pt"><v:textbox><w:txbxContent><w:p><w:r><w:t>Kelas</w:t></w:r></w:p><w:p><w:r><w:t>Tahun</w:t></w:r><w:r><w:t>Pelajaran</w:t></w:r></w:p></w:txbxContent></v:textbox></v:shape>
    <wps:wsp><wps:spPr><a:xfrm><a:ext cx="100000" cy="120000"/></a:xfrm></wps:spPr><wps:txbx><w:txbxContent><w:p><w:r><w:t>Catatan</w:t></w:r></w:p></w:txbxContent></wps:txbx></wps:wsp>
</w:document>
XML;

        $updated = $stabilizer->stabilizeDocumentXml($xml);

        $this->assertStringContainsString('cy="431800"', $updated);
        $this->assertStringContainsString('height:34pt', $updated);
        $this->assertStringContainsString('w:lineRule="auto"', $updated);
        $this->assertStringContainsString('w:val="260" w:hRule="atLeast"', $updated);
        $this->assertStringContainsString('cy="120000"', $updated);
    }

    public function test_stabilize_updates_document_xml_inside_docx(): void
    {
        if (! class_exists(ZipArchive::class)) {
            $this->markTestSkipped('ZipArchive extension is not available.');
        }

        $path = tempnam(sys_get_temp_dir(), 'identity-layout-') . '.docx';
        $zip = new ZipArchive();
        $zip->open($path, ZipArchive::CREATE);
        $zip->addFromString('word/document.xml', <<<'XML'
<w:document xmlns:w="w" xmlns:wps="wps" xmlns:a="a"><wps:wsp><wps:spPr><a:xfrm><a:ext cx="686435" cy="270510"/></a:xfrm></wps:spPr><wps:txbx><w:txbxContent><w:tbl><w:tr><w:trPr><w:trHeight w:val="205"/></w:trPr><w:tc><w:p><w:pPr><w:spacing w:line="165" w:lineRule="exact"/></w:pPr><w:r><w:t>Nama</w:t></w:r><w:r><w:t>Siswa</w:t></w:r></w:p></w:tc></w:tr><w:tr><w:trPr><w:trHeight w:val="205"/></w:trPr><w:tc><w:p><w:pPr><w:spacing w:line="165" w:lineRule="exact"/></w:pPr><w:r><w:t>NISN/NIS</w:t></w:r></w:p></w:tc></w:tr></w:tbl></w:txbxContent></wps:txbx></wps:wsp></w:document>
XML);
        $zip->close();

        try {
            $this->assertTrue((new ReportIdentityLayoutStabilizer())->stabilize($path));

            $zip->open($path);
            $updatedXml = $zip->getFromName('word/document.xml');
            $zip->close();

            $this->assertIsString($updatedXml);
            $this->assertStringContainsString('cy="431800"', $updatedXml);
            $this->assertStringContainsString('w:lineRule="auto"', $updatedXml);
        } finally {
            @unlink($path);
        }
    }
}

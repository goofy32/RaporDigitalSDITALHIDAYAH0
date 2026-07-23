<?php

namespace Tests\Unit;

use App\Models\TujuanPembelajaran;
use PHPUnit\Framework\TestCase;

class TujuanPembelajaranDisplayLabelTest extends TestCase
{
    public function test_tp_label_formatter_removes_redundant_short_tp_prefix(): void
    {
        $this->assertSame('1.1', TujuanPembelajaran::formatDisplayKodeTp('TP1.1'));
        $this->assertSame('1.1', TujuanPembelajaran::formatDisplayKodeTp('TP 1.1'));
        $this->assertSame('1.1', TujuanPembelajaran::formatDisplayKodeTp('1.1'));
        $this->assertSame('2.3', TujuanPembelajaran::formatDisplayKodeTp('TP2.3'));
    }

    public function test_tp_label_formatter_keeps_descriptive_labels_safe(): void
    {
        $this->assertSame(
            'Memahami bilangan cacah',
            TujuanPembelajaran::formatDisplayKodeTp('Memahami bilangan cacah')
        );

        $this->assertSame(
            'TP memahami bilangan',
            TujuanPembelajaran::formatDisplayKodeTp('TP memahami bilangan')
        );
    }
}

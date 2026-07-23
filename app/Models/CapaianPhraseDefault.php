<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CapaianPhraseDefault extends Model
{
    public const TYPE_TERTINGGI = 'tertinggi';

    public const TYPE_TERENDAH = 'terendah';

    public const MODE_PRESET = 'preset';

    public const MODE_CUSTOM = 'custom';

    protected $fillable = [
        'tahun_ajaran_id',
        'semester',
        'kelas_id',
        'mata_pelajaran_id',
        'type',
        'mode',
        'phrase',
    ];

    protected $casts = [
        'tahun_ajaran_id' => 'integer',
        'semester' => 'integer',
        'kelas_id' => 'integer',
        'mata_pelajaran_id' => 'integer',
    ];

    public static function validTypes(): array
    {
        return [
            self::TYPE_TERTINGGI,
            self::TYPE_TERENDAH,
        ];
    }

    public static function validModes(): array
    {
        return [
            self::MODE_PRESET,
            self::MODE_CUSTOM,
        ];
    }

    public function tahunAjaran()
    {
        return $this->belongsTo(TahunAjaran::class);
    }

    public function kelas()
    {
        return $this->belongsTo(Kelas::class);
    }

    public function mataPelajaran()
    {
        return $this->belongsTo(MataPelajaran::class);
    }
}

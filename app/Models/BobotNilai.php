<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Traits\HasTahunAjaran;

class BobotNilai extends Model
{
    use HasTahunAjaran;

    public const DEFAULT_BOBOT_TP = 1;
    public const DEFAULT_BOBOT_LM = 1;
    public const DEFAULT_BOBOT_AS = 2;

    protected $casts = [
        'bobot_tp' => 'integer',
        'bobot_lm' => 'integer',
        'bobot_as' => 'integer',
    ];
    
    protected $fillable = [
        'tahun_ajaran_id',
        'bobot_tp',
        'bobot_lm',
        'bobot_as'
    ];
    
    public static function getDefault()
    {
        $tahunAjaranId = session('tahun_ajaran_id');
        $default = self::where('tahun_ajaran_id', $tahunAjaranId)
            ->orderBy('id')
            ->first();
        
        if (!$default) {
            $default = self::create([
                'tahun_ajaran_id' => $tahunAjaranId,
                'bobot_tp' => self::DEFAULT_BOBOT_TP,
                'bobot_lm' => self::DEFAULT_BOBOT_LM,
                'bobot_as' => self::DEFAULT_BOBOT_AS
            ]);
        }
        
        return $default;
    }

    public static function resolveForRead(?int $tahunAjaranId = null): self
    {
        $tahunAjaranId ??= session('tahun_ajaran_id');

        return self::where('tahun_ajaran_id', $tahunAjaranId)
            ->orderBy('id')
            ->first()
            ?? new self([
                'tahun_ajaran_id' => $tahunAjaranId,
                'bobot_tp' => self::DEFAULT_BOBOT_TP,
                'bobot_lm' => self::DEFAULT_BOBOT_LM,
                'bobot_as' => self::DEFAULT_BOBOT_AS,
            ]);
    }

    public function getTotal(): int
    {
        return (int) $this->bobot_tp + (int) $this->bobot_lm + (int) $this->bobot_as;
    }

    public function getTpPercentage(): float
    {
        $total = $this->getTotal();

        if ($total === 0) {
            return 0.0;
        }

        return round(((int) $this->bobot_tp / $total) * 100, 1);
    }

    public function getLmPercentage(): float
    {
        $total = $this->getTotal();

        if ($total === 0) {
            return 0.0;
        }

        return round(((int) $this->bobot_lm / $total) * 100, 1);
    }

    public function getAsPercentage(): float
    {
        $total = $this->getTotal();

        if ($total === 0) {
            return 0.0;
        }

        return round(((int) $this->bobot_as / $total) * 100, 1);
    }
}

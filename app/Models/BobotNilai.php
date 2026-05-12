<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Traits\HasTahunAjaran;

class BobotNilai extends Model
{
    use HasTahunAjaran;

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
        $default = self::where('tahun_ajaran_id', $tahunAjaranId)->first();
        
        if (!$default) {
            $default = self::create([
                'tahun_ajaran_id' => $tahunAjaranId,
                'bobot_tp' => 1,
                'bobot_lm' => 1,
                'bobot_as' => 2
            ]);
        }
        
        return $default;
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

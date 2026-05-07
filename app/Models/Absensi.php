<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Traits\HasTahunAjaran;

class Absensi extends Model
{
    use HasTahunAjaran, SoftDeletes;
    
    protected $fillable = [
        'siswa_id',
        'sakit',
        'izin',
        'tanpa_keterangan',
        'semester',
        'tahun_ajaran_id'
    ];
    
    public function siswa()
    {
        return $this->belongsTo(Siswa::class);
    }
    
    public function tahunAjaran()
    {
        return $this->belongsTo(TahunAjaran::class);
    }
    
    public function getTahunAjaranIdAttribute()
    {
        // Access the raw attribute value directly using the attributes array
        // This avoids the infinite recursion problem
        if (isset($this->attributes['tahun_ajaran_id']) && $this->attributes['tahun_ajaran_id']) {
            return $this->attributes['tahun_ajaran_id'];
        }
        
        if ($this->siswa && $this->siswa->kelas) {
            return $this->siswa->kelas->tahun_ajaran_id;
        }
        
        return session('tahun_ajaran_id');
    }

    public function setTahunAjaranIdAttribute($value)
    {
        // Jika nilai yang diberikan kosong, gunakan session
        if (empty($value)) {
            $this->attributes['tahun_ajaran_id'] = session('tahun_ajaran_id');
        } else {
            $this->attributes['tahun_ajaran_id'] = $value;
        }
    }


    /**
     * Mendapatkan semester dari tahun ajaran yang aktif
     * jika tidak ada semester yang diberikan
     */
    public function getSemesterAttribute($value)
    {
        if ($value !== null) {
            return $value;
        }
        
        // Jika tidak ada semester, ambil dari tahun ajaran
        if ($this->tahun_ajaran_id) {
            $tahunAjaran = TahunAjaran::find($this->tahun_ajaran_id);
            if ($tahunAjaran) {
                return $tahunAjaran->semester;
            }
        }
        
        // Jika masih belum ada, gunakan semester default (1)
        return 1;
    }

    public function scopeTahunAjaran($query, $tahunAjaranId)
    {
        return $query->where('tahun_ajaran_id', $tahunAjaranId);
    }
    
    public function scopeAktif($query)
    {
        $tahunAjaranAktif = TahunAjaran::where('is_active', true)->first();
        if ($tahunAjaranAktif) {
            return $query->where('tahun_ajaran_id', $tahunAjaranAktif->id);
        }
        return $query;
    }
}

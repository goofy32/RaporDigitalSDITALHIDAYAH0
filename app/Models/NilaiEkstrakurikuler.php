<?php

namespace App\Models;

use App\Traits\HasTahunAjaran;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class NilaiEkstrakurikuler extends Model
{
    use HasTahunAjaran, SoftDeletes;

    protected $table = 'nilai_ekstrakurikuler';

    protected $fillable = [
        'siswa_id',
        'ekstrakurikuler_id',
        'predikat',
        'deskripsi',
        'tahun_ajaran_id',
        'semester',
    ];

    protected $casts = [
        'semester' => 'integer',
    ];

    // Perbaikan pada accessor (getter)
    public function getTahunAjaranIdAttribute($value)
    {
        // Jika nilai tahun_ajaran_id sudah ada di model, kembalikan nilai tersebut
        if (! empty($value)) {
            return $value;
        }

        // Jika belum ada, coba ambil dari relasi siswa->kelas
        if ($this->siswa && $this->siswa->kelas) {
            return $this->siswa->kelas->tahun_ajaran_id;
        }

        // Jika masih tidak ada, gunakan tahun ajaran dari session
        return session('tahun_ajaran_id');
    }

    public function siswa()
    {
        return $this->belongsTo(Siswa::class);
    }

    public function ekstrakurikuler()
    {
        return $this->belongsTo(Ekstrakurikuler::class);
    }

    public function tahunAjaran()
    {
        return $this->belongsTo(TahunAjaran::class);
    }

    public function scopeTahunAjaran($query, $tahunAjaranId)
    {
        return $query->where('tahun_ajaran_id', $tahunAjaranId);
    }

    public function scopeContext($query, int $tahunAjaranId, int $semester)
    {
        return $query->where('tahun_ajaran_id', $tahunAjaranId)
            ->where('semester', $semester);
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

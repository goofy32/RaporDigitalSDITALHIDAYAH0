<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Traits\HasTahunAjaran;

class ReportGeneration extends Model
{
    use HasTahunAjaran;
    
    // Tambahkan tahun_ajaran_id ke fillable
    protected $fillable = [
        'siswa_id',
        'kelas_id',
        'report_template_id',
        'generated_file',
        'type',
        'tahun_ajaran',
        'semester',
        'tahun_ajaran_id', // Tambahkan ini
        'generated_at',
        'generated_by'
    ];

    protected $casts = [
        'generated_at' => 'datetime'
    ];

    public function siswa()
    {
        return $this->belongsTo(Siswa::class)->withTrashed();
    }

    public function kelas()
    {
        return $this->belongsTo(Kelas::class)->withTrashed();
    }

    public function tahunAjaran()
    {
        return $this->belongsTo(TahunAjaran::class);
    }

    public function template()
    {
        return $this->belongsTo(ReportTemplate::class, 'report_template_id');
    }

    public function generator()
    {
        return $this->belongsTo(Guru::class, 'generated_by')->withTrashed();
    }
}

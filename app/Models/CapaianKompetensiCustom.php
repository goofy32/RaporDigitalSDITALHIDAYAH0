<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Traits\HasTahunAjaran;

class CapaianKompetensiCustom extends Model
{
    use HasTahunAjaran;
    
    protected $table = 'capaian_custom';

    protected $fillable = [
        'siswa_id',
        'mata_pelajaran_id',
        'custom_capaian',
        'custom_capaian_tertinggi',
        'custom_capaian_terendah',
        'tahun_ajaran_id',
        'semester'
    ];

    protected $casts = [
        'semester' => 'integer',
    ];

    public function siswa()
    {
        return $this->belongsTo(Siswa::class);
    }

    public function mataPelajaran()
    {
        return $this->belongsTo(MataPelajaran::class);
    }

    public function tahunAjaran()
    {
        return $this->belongsTo(TahunAjaran::class);
    }

    /**
     * Generate capaian kompetensi final
     * Menggabungkan template otomatis dengan custom text dari wali kelas
     */
    public function generateFinalCapaian()
    {
        // Ambil nilai siswa untuk mata pelajaran ini
        $nilai = $this->siswa->nilais()
            ->where('mata_pelajaran_id', $this->mata_pelajaran_id)
            ->where('tahun_ajaran_id', $this->tahun_ajaran_id)
            ->first();

        if (!$nilai || !$nilai->nilai_akhir_rapor) {
            return $this->custom_capaian ?: 'Nilai belum tersedia.';
        }

        // Cari template berdasarkan mata pelajaran dan nilai
        $template = CapaianKompetensiTemplate::getTemplateByNilai(
            $this->mataPelajaran->nama_pelajaran,
            $nilai->nilai_akhir_rapor,
            $this->tahun_ajaran_id
        );

        if (!$template) {
            // Fallback ke template default jika tidak ada
            return $this->custom_capaian ?: $this->generateDefaultCapaian($nilai->nilai_akhir_rapor);
        }

        $autoText = $template->generateCapaianText($this->siswa->nama);
        
        // Gabungkan dengan custom text jika ada
        if ($this->custom_capaian) {
            return $autoText . ' ' . $this->custom_capaian;
        }

        return $autoText;
    }

    /**
     * Generate default capaian jika tidak ada template
     */
    private function generateDefaultCapaian($nilai)
    {
        $namaSiswa = $this->siswa->nama;
        $namaMapel = $this->mataPelajaran->nama_pelajaran;

        if ($nilai >= 90) {
            return "{$namaSiswa} menunjukkan penguasaan yang sangat baik dalam mata pelajaran {$namaMapel}.";
        } elseif ($nilai >= 80) {
            return "{$namaSiswa} menunjukkan penguasaan yang baik dalam mata pelajaran {$namaMapel}.";
        } elseif ($nilai >= 70) {
            return "{$namaSiswa} menunjukkan penguasaan yang cukup dalam mata pelajaran {$namaMapel}.";
        } else {
            return "{$namaSiswa} perlu meningkatkan penguasaan dalam mata pelajaran {$namaMapel}.";
        }
    }

    /**
     * Scope untuk current context (tahun ajaran dan semester)
     */
    public function scopeCurrentContext($query)
    {
        $tahunAjaranId = session('tahun_ajaran_id');
        $tahunAjaran = TahunAjaran::find($tahunAjaranId);
        $semester = $tahunAjaran ? $tahunAjaran->semester : 1;
        
        return $query->where('tahun_ajaran_id', $tahunAjaranId)
                    ->where('semester', $semester);
    }
}

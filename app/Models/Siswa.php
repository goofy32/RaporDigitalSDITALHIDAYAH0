<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Traits\HasTahunAjaran;
use Illuminate\Database\Eloquent\SoftDeletes;

class Siswa extends Model
{
    use HasFactory, HasTahunAjaran, SoftDeletes;

    protected $table = 'siswas';

    protected $fillable = [
        'nis',
        'nisn',
        'nama',
        'tanggal_lahir',
        'jenis_kelamin',
        'agama',
        'alamat',
        'kelas_id',
        'nama_ayah',
        'nama_ibu',
        'pekerjaan_ayah',
        'pekerjaan_ibu',
        'alamat_orangtua',
        'photo',
        'wali_siswa',
        'pekerjaan_wali',
        'tahun_ajaran_id'
    ];
    public function kelas()
    {
        return $this->belongsTo(Kelas::class)->orderBy('nomor_kelas', 'asc');        
    }

    public function getTahunAjaranIdAttribute()
    {
        return $this->kelas->tahun_ajaran_id ?? session('tahun_ajaran_id');
    }

    public function tahunAjaran()
    {
        if ($this->kelas) {
            return $this->kelas->tahunAjaran();
        }
        
        return $this->belongsTo(TahunAjaran::class, 'tahun_ajaran_id');
    }
    
    public function scopeTahunAjaran($query, $tahunAjaranId)
    {
        return $query->whereHas('kelas', function($q) use ($tahunAjaranId) {
            $q->where('tahun_ajaran_id', $tahunAjaranId);
        });
    }
    
    public function scopeAktif($query)
    {
        $tahunAjaranAktif = TahunAjaran::where('is_active', true)->first();
        if ($tahunAjaranAktif) {
            return $this->scopeTahunAjaran($query, $tahunAjaranAktif->id);
        }
        return $query;
    }

    public function prestasi()
    {
        return $this->hasMany(Prestasi::class);
    }
    public function nilais()
    {
        return $this->hasMany(Nilai::class);
    }

    public function nilaiEkstrakurikuler()
    {
        return $this->hasMany(NilaiEkstrakurikuler::class);
    }

    public function absensi()
    {
        return $this->hasOne(Absensi::class);
    }

    public function getKelasLengkapAttribute()
    {
        return $this->kelas ? $this->kelas->full_kelas : '-';
    }

    /**
     * Diagnosa masalah kelengkapan data untuk rapor
     * 
     * @param string $type Tipe rapor (UTS/UAS)
     * @return array Array berisi status dan detail masalah
     */
    public function diagnoseDataCompleteness($type = 'UTS')
    {
        static $semesterCache = [];

        // Ambil semester dari tahun ajaran aktif
        $tahunAjaranId = session('tahun_ajaran_id');
        if (!array_key_exists($tahunAjaranId, $semesterCache)) {
            $tahunAjaran = $tahunAjaranId ? \App\Models\TahunAjaran::find($tahunAjaranId) : null;
            $semesterCache[$tahunAjaranId] = $tahunAjaran ? $tahunAjaran->semester : 1;
        }

        $semester = $semesterCache[$tahunAjaranId];
        
        \Log::info('diagnoseDataCompleteness untuk', [
            'siswa_id' => $this->id,
            'siswa_nama' => $this->nama,
            'type' => $type, // UTS atau UAS
            'semester' => $semester, // 1 atau 2
            'tahun_ajaran_id' => $tahunAjaranId
        ]);
        
        $result = [
            'nilai_status' => false,
            'nilai_message' => '',
            'absensi_status' => false,
            'absensi_message' => '',
            'complete' => false
        ];
        
        // Cek mata pelajaran untuk semester ini
        $kelas = $this->relationLoaded('kelas') ? $this->kelas : $this->kelas()->first();

        if ($kelas && $kelas->relationLoaded('mataPelajarans')) {
            $mataPelajarans = $kelas->mataPelajarans->filter(function ($mataPelajaran) use ($semester, $tahunAjaranId) {
                return (int) $mataPelajaran->semester === (int) $semester
                    && (!$tahunAjaranId || (int) $mataPelajaran->tahun_ajaran_id === (int) $tahunAjaranId);
            })->values();
        } else {
            $mataPelajarans = $kelas
                ? $kelas->mataPelajarans()
                    ->where('semester', $semester)
                    ->when($tahunAjaranId, function($query) use ($tahunAjaranId) {
                        return $query->where('tahun_ajaran_id', $tahunAjaranId);
                    })
                    ->get()
                : collect();
        }
        
        // Cek nilai di tahun ajaran yang aktif
        // PENTING: Untuk membedakan nilai UTS dan UAS, idealnya ada field tambahan
        // tapi untuk saat ini, kita bisa gunakan semua nilai yang ada di semester yang sama
        if ($this->relationLoaded('nilais')) {
            $nilaiCount = $this->nilais->filter(function ($nilai) use ($semester, $tahunAjaranId) {
                if ($tahunAjaranId && (int) $nilai->tahun_ajaran_id !== (int) $tahunAjaranId) {
                    return false;
                }

                if (is_null($nilai->nilai_akhir_rapor)) {
                    return false;
                }

                $mataPelajaran = $nilai->mataPelajaran;

                return $mataPelajaran && (int) $mataPelajaran->semester === (int) $semester;
            })->count();
        } else {
            $nilaiCount = $this->nilais()
                ->whereHas('mataPelajaran', function($q) use ($semester) {
                    $q->where('semester', $semester);
                })
                ->when($tahunAjaranId, function($query) use ($tahunAjaranId) {
                    return $query->where('tahun_ajaran_id', $tahunAjaranId);
                })
                ->whereNotNull('nilai_akhir_rapor')
                ->count();
        }
        
        if ($nilaiCount > 0) {
            $result['nilai_status'] = true;
            $result['nilai_message'] = "Data nilai lengkap ({$nilaiCount} mata pelajaran)";
        } else {
            // Berbagai kemungkinan masalah nilai
            if ($mataPelajarans->count() == 0) {
                $result['nilai_message'] = "Tidak ada mata pelajaran untuk semester {$semester} pada tahun ajaran ini";
            } else {
                $result['nilai_message'] = "Belum ada nilai yang diinput sama sekali untuk semester {$semester}";
            }
        }
        
        // Cek kehadiran untuk semester ini
        if ($this->relationLoaded('absensi')) {
            $absensi = $this->absensi
                && (int) $this->absensi->semester === (int) $semester
                && (!$tahunAjaranId || (int) $this->absensi->tahun_ajaran_id === (int) $tahunAjaranId)
                ? $this->absensi
                : null;
        } else {
            $absensi = $this->absensi()
                ->where('semester', $semester)
                ->when($tahunAjaranId, function($query) use ($tahunAjaranId) {
                    return $query->where('tahun_ajaran_id', $tahunAjaranId);
                })
                ->first();
        }
        
        if ($absensi) {
            $result['absensi_status'] = true;
            $result['absensi_message'] = "Data absensi lengkap";
        } else {
            $result['absensi_message'] = "Data absensi belum diinput untuk semester {$semester}";
        }
        
        $result['complete'] = $result['nilai_status'] && $result['absensi_status'];
        
        return $result;
    }

    public static function debugWaliKelasRelation($guruId, $kelasId)
    {
        // Helper untuk debugging relasi wali kelas
        $guru = \App\Models\Guru::find($guruId);
        $kelas = \App\Models\Kelas::find($kelasId);
        
        if (!$guru || !$kelas) {
            return [
                'success' => false,
                'message' => 'Guru atau kelas tidak ditemukan'
            ];
        }
        
        // Cek pivot table
        $pivot = \DB::table('guru_kelas')
            ->where('guru_id', $guruId)
            ->where('kelas_id', $kelasId)
            ->first();
        
        return [
            'success' => true,
            'guru' => $guru->toArray(),
            'kelas' => $kelas->toArray(),
            'pivot' => $pivot ? (array)$pivot : null,
            'is_wali_kelas' => $pivot ? ($pivot->is_wali_kelas && $pivot->role === 'wali_kelas') : false
        ];
    }

    // Tambahkan scope untuk filter berdasarkan wali kelas
    public function scopeWaliKelas($query, $guruId)
    {
        return $query->whereHas('kelas', function($q) use ($guruId) {
            $q->where('wali_kelas', $guruId);
        });
    }

    public function catatanSiswa()
    {
        return $this->hasMany(CatatanSiswa::class);
    }

    public function catatanMataPelajaran()
    {
        return $this->hasMany(CatatanMataPelajaran::class);
    }

    public function getCatatanForCurrentSemester($type = 'umum')
    {
        $tahunAjaranId = session('tahun_ajaran_id');
        $selectedSemester = session('selected_semester', 1);
        
        return $this->catatanSiswa()
            ->where('tahun_ajaran_id', $tahunAjaranId)
            ->where('semester', $selectedSemester)
            ->where('type', $type)
            ->first();
    }
    /**
     * Periksa apakah siswa berada di kelas yang diwalikan oleh guru
     * 
     * @param int $guruId
     * @return bool
     */
    public function isInKelasWali($guruId)
    {
        // Log untuk debugging
        \Log::info('Checking isInKelasWali', [
            'siswa_id' => $this->id,
            'kelas_id' => $this->kelas_id,
            'guru_id' => $guruId
        ]);
        
        // Cek jika siswa memiliki kelas
        if (!$this->kelas) {
            \Log::warning('Siswa tidak memiliki kelas', ['siswa_id' => $this->id]);
            return false;
        }
        
        // Cari guru yang bersangkutan
        $guru = \App\Models\Guru::find($guruId);
        if (!$guru) {
            \Log::warning('Guru tidak ditemukan', ['guru_id' => $guruId]);
            return false;
        }
        
        // Gunakan method manual yang baru dibuat
        $kelasWaliIds = \DB::table('guru_kelas')
            ->where('guru_id', $guruId)
            ->where('is_wali_kelas', true)
            ->where('role', 'wali_kelas')
            ->pluck('kelas_id');
        
        // Periksa apakah kelas siswa termasuk dalam kelas-kelas yang diwalikan
        $result = $kelasWaliIds->contains($this->kelas_id);
        
        \Log::info('isInKelasWali result', [
            'siswa_kelas_id' => $this->kelas_id,
            'wali_kelas_ids' => $kelasWaliIds->toArray(),
            'is_match' => $result
        ]);
        
        return $result;
    }

    public function hasCompleteData($type = 'UTS', $tahunAjaranId = null)
    {
        $tahunAjaranId = $tahunAjaranId ?: session('tahun_ajaran_id');
        
        // Get the current semester from the tahun ajaran
        $tahunAjaran = \App\Models\TahunAjaran::find($tahunAjaranId);
        $semester = $tahunAjaran ? $tahunAjaran->semester : ($type === 'UTS' ? 1 : 2);
        
        // Check nilai based on current semester
        $hasNilai = $this->nilais()
            ->whereHas('mataPelajaran', function($q) use ($semester) {
                $q->where('semester', $semester);
            })
            ->when($tahunAjaranId, function($query) use ($tahunAjaranId) {
                return $query->where('tahun_ajaran_id', $tahunAjaranId);
            })
            ->whereNotNull('nilai_akhir_rapor')
            ->exists();
        
        // Check absensi for current semester
        $hasAbsensi = $this->absensi()
            ->where('semester', $semester)
            ->when($tahunAjaranId, function($query) use ($tahunAjaranId) {
                return $query->where('tahun_ajaran_id', $tahunAjaranId);
            })
            ->exists();
        
        return $hasNilai && $hasAbsensi;
    }
}

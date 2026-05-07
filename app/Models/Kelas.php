<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;
use App\Traits\HasTahunAjaran;

class Kelas extends Model
{
    use HasFactory, HasTahunAjaran, SoftDeletes;

    protected $table = 'kelas';

    protected $fillable = [
        'nomor_kelas',
        'nama_kelas',
        'tahun_ajaran_id',  // Tambahkan field ini
    ];

    protected $appends = ['full_kelas'];
    
    protected $casts = [
        'nomor_kelas' => 'integer'
    ];

    protected static function booted()
    {
        static::deleting(function ($kelas) {
            if ($kelas->isForceDeleting()) {
                return;
            }

            $kelas->loadMissing(['siswas', 'mataPelajarans']);

            $siswaIds = $kelas->siswas->pluck('id')->all();
            $mataPelajaranIds = $kelas->mataPelajarans->pluck('id')->all();
            $prestasiIds = Prestasi::where('kelas_id', $kelas->id)->pluck('id')->all();
            $absensiIds = !empty($siswaIds)
                ? Absensi::whereIn('siswa_id', $siswaIds)->pluck('id')->all()
                : [];
            $guruPivots = DB::table('guru_kelas')
                ->where('kelas_id', $kelas->id)
                ->get()
                ->map(fn ($pivot) => (array) $pivot)
                ->all();

            AuditLog::create([
                'user_type' => self::resolveAuditActorType(),
                'user_id' => self::resolveAuditActorId(),
                'action' => 'cascade_delete_snapshot',
                'model_type' => self::class,
                'model_id' => $kelas->id,
                'description' => 'Snapshot sebelum hapus Kelas dan relasinya',
                'old_values' => [
                    'kelas' => $kelas->attributesToArray(),
                    'siswa_count' => $kelas->siswas->count(),
                    'mata_pelajaran_count' => $kelas->mataPelajarans->count(),
                    'siswa_ids' => $siswaIds,
                    'mata_pelajaran_ids' => $mataPelajaranIds,
                    'prestasi_ids' => $prestasiIds,
                    'absensi_ids' => $absensiIds,
                    'guru_pivots' => $guruPivots,
                ],
                'ip_address' => request()->ip(),
                'user_agent' => request()->userAgent(),
            ]);

            if (!empty($siswaIds)) {
                Absensi::whereIn('siswa_id', $siswaIds)
                    ->get()
                    ->each(fn (Absensi $absensi) => $absensi->delete());
            }

            Prestasi::where('kelas_id', $kelas->id)
                ->get()
                ->each(fn (Prestasi $prestasi) => $prestasi->delete());

            $kelas->siswas->each(fn (Siswa $siswa) => $siswa->delete());
            $kelas->mataPelajarans->each(fn (MataPelajaran $mataPelajaran) => $mataPelajaran->delete());

            DB::table('guru_kelas')->where('kelas_id', $kelas->id)->delete();
        });
    }

    // Relasi dengan guru
    public function guru()
    {
        return $this->belongsToMany(Guru::class, 'guru_kelas')
            ->withPivot('is_wali_kelas', 'role')
            ->withTimestamps();
    }
    
    // Relasi dengan tahun ajaran
    public function tahunAjaran()
    {
        return $this->belongsTo(TahunAjaran::class, 'tahun_ajaran_id');
    }
    
    // Wali kelas
    public function waliKelas()
    {
        return $this->belongsToMany(Guru::class, 'guru_kelas')
            ->where('guru_kelas.is_wali_kelas', true)
            ->withPivot('role');
    }

    public function getProgressKelas()
    {
        $total_siswa = $this->siswas()->count();
        $completed_siswa = $this->siswas()
            ->whereHas('nilais', function($q) {
                $q->whereNotNull('nilai_akhir_rapor');
            })->count();
        
        return [
            'total' => $total_siswa,
            'completed' => $completed_siswa,
            'percentage' => $total_siswa > 0 ? ($completed_siswa / $total_siswa) * 100 : 0
        ];
    }

    // Guru pengajar
    public function guruPengajar()
    {
        return $this->belongsToMany(Guru::class, 'guru_kelas')
            ->wherePivot('role', 'pengajar')
            ->withTimestamps();
    }

    public function getWaliKelas()
    {
        // Ambil data wali kelas untuk kelas ini
        return $this->belongsToMany(Guru::class, 'guru_kelas')
            ->where('guru_kelas.is_wali_kelas', true)
            ->where('guru_kelas.role', 'wali_kelas')
            ->first();
    }

    // Get wali kelas name
    public function getWaliKelasNameAttribute()
    {
        $waliKelas = $this->waliKelas()->first();
        return $waliKelas ? $waliKelas->nama : '-';
    }

    // Get full kelas name
    public function getFullKelasAttribute()
    {
        $tahunAjaranText = $this->tahunAjaran ? " ({$this->tahunAjaran->tahun_ajaran})" : "";
        return "Kelas {$this->nomor_kelas} {$this->nama_kelas}{$tahunAjaranText}";
    }
    
    public function siswas()
    {
        return $this->hasMany(Siswa::class);
    }

    public function mataPelajarans()
    {
        return $this->hasMany(MataPelajaran::class, 'kelas_id');
    }

    public function prestasis()
    {
        return $this->hasMany(Prestasi::class, 'kelas_id');
    }

    public function isWaliKelas($guruId)
    {
        return $this->belongsToMany(Guru::class, 'guru_kelas')
            ->where('guru_kelas.is_wali_kelas', true)
            ->where('guru_kelas.role', 'wali_kelas')
            ->where('guru_kelas.guru_id', $guruId)
            ->exists();
    }

    public function getWaliKelasId()
    {
        $waliKelas = $this->getWaliKelas();
        return $waliKelas ? $waliKelas->id : null;
    }
    
    public static function getWaliKelasMap($tahunAjaranId = null)
    {
        $result = [];
        $query = self::query();
        
        if ($tahunAjaranId) {
            $query->where('tahun_ajaran_id', $tahunAjaranId);
        }
        
        $allKelas = $query->get();
        
        foreach ($allKelas as $kelas) {
            $waliKelasId = $kelas->getWaliKelasId();
            if ($waliKelasId) {
                $result[$kelas->id] = $waliKelasId;
            }
        }
        
        return json_encode($result);
    }

    public function toArray()
    {
        $array = parent::toArray();
        $array['mata_pelajarans'] = $this->mataPelajarans;
        return $array;
    }

    public function hasWaliKelas()
    {
        // Periksa apakah kelas sudah memiliki wali kelas
        return $this->belongsToMany(Guru::class, 'guru_kelas')
            ->where('guru_kelas.is_wali_kelas', true)
            ->where('guru_kelas.role', 'wali_kelas')
            ->exists();
    }

    /**
     * Ambil wali kelas dengan format yang mudah digunakan untuk JavaScript
     * 
     * @return array
     */
    public static function getWaliKelasMapping()
    {
        $result = [];
        $allKelas = self::all();
        
        foreach ($allKelas as $kelas) {
            $waliKelasId = $kelas->getWaliKelasId();
            if ($waliKelasId) {
                $result[$kelas->id] = [
                    'id' => $waliKelasId,
                    'kelas' => "Kelas {$kelas->nomor_kelas} {$kelas->nama_kelas}",
                    'nama' => optional($kelas->getWaliKelas())->nama
                ];
            }
        }
        
        return $result;
    }
    
    public function reportTemplates()
    {
        return $this->hasMany(ReportTemplate::class, 'kelas_id');
    }
    
    public function reportTemplateMulti()
    {
        return $this->belongsToMany(ReportTemplate::class, 'report_template_kelas');
    }

    
    /**
     * Scope untuk filter berdasarkan tahun ajaran
     */
    public function scopeTahunAjaran($query, $tahunAjaranId)
    {
        return $query->where('tahun_ajaran_id', $tahunAjaranId);
    }
    
    /**
     * Scope untuk filter berdasarkan tahun ajaran aktif
     */
    public function scopeAktif($query)
    {
        $tahunAjaranAktif = TahunAjaran::where('is_active', true)->first();
        if ($tahunAjaranAktif) {
            return $query->where('tahun_ajaran_id', $tahunAjaranAktif->id);
        }
        return $query;
    }

    protected static function resolveAuditActorType(): ?string
    {
        if (auth()->guard('web')->check()) {
            return User::class;
        }

        if (auth()->guard('guru')->check()) {
            return Guru::class;
        }

        return null;
    }

    protected static function resolveAuditActorId(): ?int
    {
        if (auth()->guard('web')->check()) {
            return auth()->guard('web')->id();
        }

        if (auth()->guard('guru')->check()) {
            return auth()->guard('guru')->id();
        }

        return null;
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Http\Controllers\DashboardController;
use App\Traits\HasTahunAjaran;
use Illuminate\Database\Eloquent\SoftDeletes;

class MataPelajaran extends Model
{
    use HasFactory, HasTahunAjaran, SoftDeletes;

    protected $table = 'mata_pelajarans';

    protected $fillable = [
        'nama_pelajaran',
        'kelas_id',
        'guru_id',
        'semester',
        'is_muatan_lokal',
        'allow_non_wali',
        'tahun_ajaran_id', // Tambahkan field ini
    ];
    
    protected $casts = [
        'is_muatan_lokal' => 'boolean',
        'allow_non_wali' => 'boolean',
        'guru_id' => 'integer'
    ];

    public function kelas()
    {
        return $this->belongsTo(Kelas::class, 'kelas_id');
    }

    public function guru()
    {
        return $this->belongsTo(Guru::class, 'guru_id');
    }
    
    public function tahunAjaran()
    {
        return $this->belongsTo(TahunAjaran::class, 'tahun_ajaran_id');
    }

    public function lingkupMateris()
    {
        return $this->hasMany(LingkupMateri::class, 'mata_pelajaran_id');
    }

    public function nilais()
    {
        return $this->hasMany(Nilai::class, 'mata_pelajaran_id');
    }

    protected static function booted()
    {
        static::deleting(function ($mataPelajaran) {
            if (method_exists($mataPelajaran, 'isForceDeleting') && $mataPelajaran->isForceDeleting()) {
                return;
            }

            $mataPelajaran->loadMissing(['lingkupMateris.tujuanPembelajarans', 'nilais']);

            AuditLog::create([
                'user_type' => static::resolveAuditActorType(),
                'user_id' => static::resolveAuditActorId(),
                'action' => 'cascade_delete_snapshot',
                'model_type' => self::class,
                'model_id' => $mataPelajaran->id,
                'description' => 'Snapshot sebelum hapus MataPelajaran dan relasinya',
                'old_values' => [
                    'mata_pelajaran' => $mataPelajaran->attributesToArray(),
                    'lingkup_materis' => $mataPelajaran->lingkupMateris
                        ->map(fn ($lingkupMateri) => $lingkupMateri->attributesToArray())
                        ->values()
                        ->all(),
                    'tujuan_pembelajarans' => $mataPelajaran->lingkupMateris
                        ->flatMap(fn ($lingkupMateri) => $lingkupMateri->tujuanPembelajarans
                            ->map(fn ($tujuanPembelajaran) => $tujuanPembelajaran->attributesToArray()))
                        ->values()
                        ->all(),
                    'nilai_ids' => $mataPelajaran->nilais
                        ->pluck('id')
                        ->values()
                        ->all(),
                ],
                'new_values' => null,
                'ip_address' => request()?->ip(),
                'user_agent' => request()?->userAgent(),
            ]);

            $mataPelajaran->nilais->each(function (Nilai $nilai) {
                $nilai->delete();
            });

            $mataPelajaran->lingkupMateris->each(function ($lingkupMateri) {
                $lingkupMateri->delete();
            });
        });

        static::deleted(function ($mataPelajaran) {
            DashboardController::clearProgressCacheForKelas(
                $mataPelajaran->kelas_id,
                $mataPelajaran->guru_id
            );
        });

        static::restored(function ($mataPelajaran) {
            DashboardController::clearProgressCacheForKelas(
                $mataPelajaran->kelas_id,
                $mataPelajaran->guru_id
            );
        });
    }
    
    public function catatanMataPelajaran()
    {
        return $this->hasMany(CatatanMataPelajaran::class);
    }

    // Get catatan for specific student and type
    public function getCatatanForSiswa($siswaId, $type = 'umum')
    {
        $tahunAjaranId = session('tahun_ajaran_id');
        $selectedSemester = session('selected_semester', 1);
        
        return $this->catatanMataPelajaran()
            ->where('siswa_id', $siswaId)
            ->where('tahun_ajaran_id', $tahunAjaranId)
            ->where('semester', $selectedSemester)
            ->where('type', $type)
            ->first();
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

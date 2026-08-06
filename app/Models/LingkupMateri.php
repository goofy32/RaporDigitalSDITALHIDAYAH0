<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Services\ScoreAggregateRecalculationService;

class LingkupMateri extends Model
{
    use HasFactory, SoftDeletes;

    /** @var array<int, array{siswa_id:mixed, mata_pelajaran_id:mixed, tahun_ajaran_id:mixed}> */
    private array $scoreAggregateContextsBeforeDelete = [];

    protected $table = 'lingkup_materis';

    protected $fillable = [
        'mata_pelajaran_id',
        'judul_lingkup_materi',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    // Menambahkan eager loading default
    protected $with = ['tujuanPembelajarans'];

    public function getTahunAjaranIdAttribute()
    {
        return $this->mataPelajaran ? $this->mataPelajaran->tahun_ajaran_id : null;
    }
    
    public function mataPelajaran()
    {
        return $this->belongsTo(MataPelajaran::class, 'mata_pelajaran_id');
    }

    public function tujuanPembelajarans()
    {
        return $this->hasMany(TujuanPembelajaran::class, 'lingkup_materi_id');
    }

    public function nilais()
    {
        return $this->hasMany(Nilai::class, 'lingkup_materi_id');
    }

    protected static function booted()
    {
        static::deleting(function ($lingkupMateri) {
            $lingkupMateri->loadMissing(['tujuanPembelajarans', 'nilais']);

            $lingkupMateri->scoreAggregateContextsBeforeDelete = $lingkupMateri->nilais
                ->map(fn (Nilai $nilai) => $nilai->only([
                    'siswa_id',
                    'mata_pelajaran_id',
                    'tahun_ajaran_id',
                ]))
                ->unique(fn (array $context) => implode(':', $context))
                ->values()
                ->all();

            if (method_exists($lingkupMateri, 'isForceDeleting') && $lingkupMateri->isForceDeleting()) {
                return;
            }

            AuditLog::create([
                'user_type' => static::resolveAuditActorType(),
                'user_id' => static::resolveAuditActorId(),
                'action' => 'cascade_delete_snapshot',
                'model_type' => self::class,
                'model_id' => $lingkupMateri->id,
                'description' => 'Snapshot sebelum hapus LingkupMateri dan relasinya',
                'old_values' => [
                    'lingkup_materi' => $lingkupMateri->attributesToArray(),
                    'tujuan_pembelajarans' => $lingkupMateri->tujuanPembelajarans
                        ->map(fn ($tp) => $tp->attributesToArray())
                        ->values()
                        ->all(),
                    'nilai_ids' => $lingkupMateri->nilais
                        ->pluck('id')
                        ->values()
                        ->all(),
                ],
                'new_values' => null,
                'ip_address' => request()?->ip(),
                'user_agent' => request()?->userAgent(),
            ]);

            $ownsDeferredInvalidation = ! app()->bound('score_save.defer_nilai_pdf_cache_invalidation');
            $ownsDeferredRecalculation = ! app()->bound('score_aggregate.defer_recalculation');

            if ($ownsDeferredInvalidation) {
                app()->instance('score_save.defer_nilai_pdf_cache_invalidation', true);
            }
            if ($ownsDeferredRecalculation) {
                app()->instance('score_aggregate.defer_recalculation', true);
            }

            try {
                $lingkupMateri->nilais->each(function (Nilai $nilai) {
                    $nilai->delete();
                });

                $lingkupMateri->tujuanPembelajarans->each(function ($tujuanPembelajaran) {
                    $tujuanPembelajaran->delete();
                });
            } finally {
                if ($ownsDeferredRecalculation) {
                    app()->forgetInstance('score_aggregate.defer_recalculation');
                }
                if ($ownsDeferredInvalidation) {
                    app()->forgetInstance('score_save.defer_nilai_pdf_cache_invalidation');
                }
            }
        });

        static::deleted(function ($lingkupMateri) {
            app(ScoreAggregateRecalculationService::class)
                ->recalculateMany($lingkupMateri->scoreAggregateContextsBeforeDelete);
        });

        static::updated(function ($lingkupMateri) {
            if (! $lingkupMateri->wasChanged('is_active')) {
                return;
            }

            $contexts = $lingkupMateri->nilais()
                ->select(['siswa_id', 'mata_pelajaran_id', 'tahun_ajaran_id'])
                ->distinct()
                ->get()
                ->map(fn (Nilai $nilai) => $nilai->only([
                    'siswa_id',
                    'mata_pelajaran_id',
                    'tahun_ajaran_id',
                ]));

            app(ScoreAggregateRecalculationService::class)->recalculateMany($contexts);
        });
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

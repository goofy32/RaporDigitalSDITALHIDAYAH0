<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class LingkupMateri extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'lingkup_materis';

    protected $fillable = [
        'mata_pelajaran_id',
        'judul_lingkup_materi',
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
            if (method_exists($lingkupMateri, 'isForceDeleting') && $lingkupMateri->isForceDeleting()) {
                return;
            }

            $lingkupMateri->loadMissing(['tujuanPembelajarans', 'nilais']);

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

            $lingkupMateri->nilais->each(function (Nilai $nilai) {
                $nilai->delete();
            });

            $lingkupMateri->tujuanPembelajarans->each(function ($tujuanPembelajaran) {
                $tujuanPembelajaran->delete();
            });
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

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Traits\HasTahunAjaran;

class Ekstrakurikuler extends Model
{
    use HasFactory, HasTahunAjaran, SoftDeletes;

    protected $table = 'ekstrakurikulers';

    protected $fillable = [
        'nama_ekstrakurikuler',
        'pembina',
        'tahun_ajaran_id',
    ];

    protected static function booted()
    {
        static::deleting(function ($ekstrakurikuler) {
            if ($ekstrakurikuler->isForceDeleting()) {
                return;
            }

            $ekstrakurikuler->nilaiEkstrakurikuler()
                ->get()
                ->each(fn (NilaiEkstrakurikuler $nilai) => $nilai->delete());
        });
    }

    public function nilaiEkstrakurikuler()
    {
        return $this->hasMany(NilaiEkstrakurikuler::class, 'ekstrakurikuler_id');
    }
}

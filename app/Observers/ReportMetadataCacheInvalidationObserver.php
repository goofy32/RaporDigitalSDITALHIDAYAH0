<?php

namespace App\Observers;

use App\Models\Guru;
use App\Models\Kelas;
use App\Models\ProfilSekolah;
use App\Models\Siswa;
use App\Services\PdfCacheService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ReportMetadataCacheInvalidationObserver
{
    public function saved(Model $model): void
    {
        if (! $model->wasRecentlyCreated && ! $this->reportFieldsChanged($model)) {
            return;
        }

        $this->invalidate($model);
    }

    public function deleted(Model $model): void
    {
        $this->invalidate($model);
    }

    public function restored(Model $model): void
    {
        $this->invalidate($model);
    }

    private function invalidate(Model $model): void
    {
        if ($model instanceof ProfilSekolah) {
            PdfCacheService::clearAllStudentCaches();

            return;
        }

        if ($model instanceof Siswa) {
            PdfCacheService::clearStudentCache($model);

            return;
        }

        if ($model instanceof Kelas) {
            PdfCacheService::clearClassCaches([$model->getKey()]);

            return;
        }

        if ($model instanceof Guru) {
            if (! Schema::hasTable('guru_kelas')) {
                return;
            }

            $classIds = DB::table('guru_kelas')
                ->where('guru_id', $model->getKey())
                ->where('is_wali_kelas', true)
                ->where('role', 'wali_kelas')
                ->pluck('kelas_id');

            PdfCacheService::clearClassCaches($classIds);
        }
    }

    private function reportFieldsChanged(Model $model): bool
    {
        $fields = match (true) {
            $model instanceof ProfilSekolah => [
                'nama_instansi', 'nama_sekolah', 'npsn', 'alamat', 'kelurahan',
                'kecamatan', 'kabupaten', 'provinsi', 'kode_pos', 'telepon',
                'email_sekolah', 'website', 'kepala_sekolah', 'nip_kepala_sekolah',
                'tempat_terbit', 'tanggal_terbit',
            ],
            $model instanceof Siswa => [
                'nama', 'nis', 'nisn', 'jenis_kelamin', 'tanggal_lahir', 'agama',
                'alamat', 'kelas_id', 'nama_ayah', 'nama_ibu', 'pekerjaan_ayah', 'pekerjaan_ibu',
                'alamat_orangtua', 'wali_siswa', 'pekerjaan_wali', 'photo',
            ],
            $model instanceof Guru => ['nama', 'nuptk'],
            $model instanceof Kelas => ['nama_kelas', 'nomor_kelas'],
            default => [],
        };

        return $fields !== [] && $model->wasChanged($fields);
    }
}

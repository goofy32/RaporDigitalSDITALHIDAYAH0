<?php

namespace App\Observers;

use App\Models\Siswa;
use App\Services\PdfCacheService;

class PdfCacheInvalidationObserver
{
    protected function clearCacheForSiswa($model, string $siswaIdField = 'siswa_id'): void
    {
        $siswaId = $model->{$siswaIdField} ?? null;
        $tahunAjaranId = $model->tahun_ajaran_id ?? null;

        if (!$siswaId) {
            return;
        }

        $siswa = Siswa::find($siswaId);

        if ($siswa) {
            PdfCacheService::clearStudentCache($siswa, $tahunAjaranId);
        }
    }

    public function saved($model): void
    {
        $this->clearCacheForSiswa($model);
    }

    public function deleted($model): void
    {
        $this->clearCacheForSiswa($model);
    }
}

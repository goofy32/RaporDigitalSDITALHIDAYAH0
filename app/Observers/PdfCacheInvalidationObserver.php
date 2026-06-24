<?php

namespace App\Observers;

use App\Models\Absensi;
use App\Models\CapaianKompetensiCustom;
use App\Models\CatatanMataPelajaran;
use App\Models\CatatanSiswa;
use App\Models\Nilai;
use App\Models\NilaiEkstrakurikuler;
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
            PdfCacheService::clearStudentCache($siswa, $tahunAjaranId, true, $this->autoPrepareDelaySeconds($model));
        }
    }

    public function saved($model): void
    {
        if ($model instanceof Nilai && app()->bound('score_save.defer_nilai_pdf_cache_invalidation')) {
            return;
        }

        if (! $this->shouldClearAfterSave($model)) {
            return;
        }

        $this->clearCacheForSiswa($model);
    }

    public function deleted($model): void
    {
        if ($model instanceof Nilai && app()->bound('score_save.defer_nilai_pdf_cache_invalidation')) {
            return;
        }

        $this->clearCacheForSiswa($model);
    }

    private function shouldClearAfterSave($model): bool
    {
        if ((bool) ($model->wasRecentlyCreated ?? false)) {
            return $this->createdModelAffectsReport($model);
        }

        if (! method_exists($model, 'wasChanged')) {
            return true;
        }

        $attributes = $this->reportAffectingAttributes($model);

        if ($attributes === []) {
            return true;
        }

        return $model->wasChanged($attributes);
    }

    private function createdModelAffectsReport($model): bool
    {
        if ($model instanceof Absensi) {
            return (int) $model->sakit > 0
                || (int) $model->izin > 0
                || (int) $model->tanpa_keterangan > 0;
        }

        if ($model instanceof CatatanSiswa || $model instanceof CatatanMataPelajaran) {
            return trim((string) $model->catatan) !== '';
        }

        if ($model instanceof NilaiEkstrakurikuler) {
            return trim((string) $model->predikat) !== ''
                || trim((string) $model->deskripsi) !== '';
        }

        return true;
    }

    private function reportAffectingAttributes($model): array
    {
        return match (true) {
            $model instanceof Nilai => [
                'siswa_id',
                'mata_pelajaran_id',
                'tujuan_pembelajaran_id',
                'lingkup_materi_id',
                'nilai_tp',
                'nilai_lm',
                'nilai_akhir_semester',
                'na_tp',
                'na_lm',
                'tp_number',
                'nilai_tes',
                'nilai_non_tes',
                'nilai_akhir_rapor',
                'is_submitted',
                'tahun_ajaran_id',
            ],
            $model instanceof Absensi => [
                'siswa_id',
                'sakit',
                'izin',
                'tanpa_keterangan',
                'semester',
                'tahun_ajaran_id',
            ],
            $model instanceof CatatanSiswa => [
                'siswa_id',
                'catatan',
                'tahun_ajaran_id',
                'semester',
                'type',
            ],
            $model instanceof CatatanMataPelajaran => [
                'mata_pelajaran_id',
                'siswa_id',
                'tahun_ajaran_id',
                'semester',
                'type',
                'catatan',
            ],
            $model instanceof NilaiEkstrakurikuler => [
                'siswa_id',
                'ekstrakurikuler_id',
                'predikat',
                'deskripsi',
                'tahun_ajaran_id',
                'semester',
            ],
            $model instanceof CapaianKompetensiCustom => [
                'siswa_id',
                'mata_pelajaran_id',
                'custom_capaian',
                'custom_capaian_tertinggi',
                'custom_capaian_terendah',
                'tertinggi_prefix_mode',
                'tertinggi_prefix_text',
                'terendah_prefix_mode',
                'terendah_prefix_text',
                'tahun_ajaran_id',
                'semester',
            ],
            default => [],
        };
    }

    private function autoPrepareDelaySeconds($model): ?int
    {
        if ($model instanceof Nilai) {
            return null;
        }

        return max(0, (int) config('report.pdf_auto_prepare.late_stage_delay_seconds', 10));
    }
}

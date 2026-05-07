<?php

namespace App\Traits;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

trait InvalidatesDashboardCache
{
    protected function invalidateDashboardCaches(?int $guruId = null, ?int $kelasId = null): void
    {
        if ($guruId) {
            Cache::forget("guru_{$guruId}_dashboard_stats");
        }

        if (!$kelasId) {
            return;
        }

        $waliKelasIds = DB::table('guru_kelas')
            ->where('kelas_id', $kelasId)
            ->where('is_wali_kelas', true)
            ->where('role', 'wali_kelas')
            ->pluck('guru_id');

        foreach ($waliKelasIds as $waliKelasId) {
            Cache::forget("wali_kelas_progress_{$waliKelasId}");
        }
    }
}

<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\View;
use App\Models\ProfilSekolah;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\URL;
use App\Services\RaporTemplateProcessor;

// Add these imports for the audit system
use App\Observers\AuditObserver;
use App\Observers\PdfCacheInvalidationObserver;
use App\Models\User;
use App\Models\Guru;
use App\Models\Siswa;
use App\Models\Kelas;
use App\Models\MataPelajaran;
use App\Models\LingkupMateri;
use App\Models\Nilai;
use App\Models\Prestasi;
use App\Models\Absensi;
use App\Models\Ekstrakurikuler;
use App\Models\NilaiEkstrakurikuler;
use App\Models\ReportTemplate;
use App\Models\TujuanPembelajaran;
use App\Models\CatatanSiswa;
use App\Models\CapaianKompetensiCustom;
use App\Services\SiswaKelasSemesterResolver;
use App\Services\ReportPerformanceTracker;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->scoped(SiswaKelasSemesterResolver::class);
        $this->app->scoped(ReportPerformanceTracker::class);

        $this->app->bind(RaporTemplateProcessor::class, function ($app) {
            return new RaporTemplateProcessor();
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot()
    {
        View::composer('*', function ($view) {
            $schoolProfile = Cache::remember(
                'profil_sekolah',
                now()->addHours(24),
                fn () => ProfilSekolah::first()
            );

            $view->with('schoolProfile', $schoolProfile);
            $view->with('profilSekolah', $schoolProfile);
        });

        // Register the audit observers for various models
        ReportPerformanceTracker::registerDatabaseListener();
        $this->registerAuditObservers();
        $this->registerPdfCacheInvalidationObservers();

        if (app()->environment('production') && config('app.force_https', false)) {
            URL::forceScheme('https');
        }
    }

    /**
     * Register the audit observer with various models
     */
    protected function registerAuditObservers(): void
    {
        // Register observer for important models only to avoid excessive logging
        User::observe(AuditObserver::class);
        Guru::observe(AuditObserver::class);
        Siswa::observe(AuditObserver::class);
        Kelas::observe(AuditObserver::class);
        MataPelajaran::observe(AuditObserver::class);
        LingkupMateri::observe(AuditObserver::class);
        TujuanPembelajaran::observe(AuditObserver::class);
        Nilai::observe(AuditObserver::class);
        Prestasi::observe(AuditObserver::class);
        Absensi::observe(AuditObserver::class);
        Ekstrakurikuler::observe(AuditObserver::class);
        NilaiEkstrakurikuler::observe(AuditObserver::class);
        ReportTemplate::observe(AuditObserver::class);
        
        // 
    }

    protected function registerPdfCacheInvalidationObservers(): void
    {
        Nilai::observe(PdfCacheInvalidationObserver::class);
        Absensi::observe(PdfCacheInvalidationObserver::class);
        CatatanSiswa::observe(PdfCacheInvalidationObserver::class);
        NilaiEkstrakurikuler::observe(PdfCacheInvalidationObserver::class);
        CapaianKompetensiCustom::observe(PdfCacheInvalidationObserver::class);
    }
}

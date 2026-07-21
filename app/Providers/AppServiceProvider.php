<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\View;
use App\Models\ProfilSekolah;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use App\Services\RaporTemplateProcessor;

// Add these imports for the audit system
use App\Observers\AuditObserver;
use App\Observers\PdfCacheInvalidationObserver;
use App\Models\User;
use App\Models\Guru;
use App\Models\Siswa;
use App\Models\Kelas;
use App\Models\TahunAjaran;
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
use App\Models\CatatanMataPelajaran;
use App\Models\CapaianKompetensiCustom;
use App\Services\DocumentConversionService;
use App\Services\GuruRoleAvailability;
use App\Services\SiswaKelasSemesterResolver;
use App\Services\ReportPerformanceTracker;
use App\Services\ReportPdfAutoPrepareService;
use App\Services\TahunAjaranContext;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->scoped(SiswaKelasSemesterResolver::class);
        $this->app->scoped(ReportPerformanceTracker::class);
        $this->app->scoped(DocumentConversionService::class);
        $this->app->scoped(ReportPdfAutoPrepareService::class);
        $this->app->scoped(TahunAjaranContext::class);
        $this->app->scoped(GuruRoleAvailability::class);

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

        View::composer(['components.admin.topbar', 'components.guru.role-switcher'], function ($view) {
            $guru = Auth::guard('guru')->user();
            $context = app(TahunAjaranContext::class);
            $tahunAjaran = $context->selected() ?: $context->systemActive();

            if (!$tahunAjaran) {
                $tahunAjaranId = session('tahun_ajaran_id');
                $tahunAjaran = $tahunAjaranId
                    ? TahunAjaran::find($tahunAjaranId)
                    : TahunAjaran::where('is_active', true)->first();
            }

            $availableRoles = $guru
                ? app(GuruRoleAvailability::class)->availableRoles($guru, $tahunAjaran?->id, $tahunAjaran?->semester)
                : [];

            $view->with('currentGuru', $guru);
            $view->with('currentGuruAvailableRoles', $availableRoles);
        });

        View::composer('components.pengajar.sidebar', function ($view) {
            $guru = Auth::guard('guru')->user();
            $tahunAjaranId = app(TahunAjaranContext::class)->selectedId() ?: session('tahun_ajaran_id');
            $lowScoreCount = 0;

            if ($guru) {
                $query = DB::table('nilais')
                    ->join('mata_pelajarans', 'nilais.mata_pelajaran_id', '=', 'mata_pelajarans.id')
                    ->join('kkms', 'mata_pelajarans.id', '=', 'kkms.mata_pelajaran_id')
                    ->where('mata_pelajarans.guru_id', $guru->id)
                    ->whereNull('nilais.deleted_at')
                    ->whereNull('mata_pelajarans.deleted_at')
                    ->whereColumn('nilais.nilai_akhir_rapor', '<', 'kkms.nilai');

                if ($tahunAjaranId) {
                    $query->where(function ($query) use ($tahunAjaranId) {
                        $query->where('nilais.tahun_ajaran_id', $tahunAjaranId)
                            ->where('mata_pelajarans.tahun_ajaran_id', $tahunAjaranId)
                            ->where('kkms.tahun_ajaran_id', $tahunAjaranId);
                    });
                }

                $lowScoreCount = (int) $query->count();
            }

            $view->with('pengajarLowScoreCount', $lowScoreCount);
            $view->with('pengajarHasLowScores', $lowScoreCount > 0);
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
        CatatanMataPelajaran::observe(PdfCacheInvalidationObserver::class);
        NilaiEkstrakurikuler::observe(PdfCacheInvalidationObserver::class);
        CapaianKompetensiCustom::observe(PdfCacheInvalidationObserver::class);
    }
}

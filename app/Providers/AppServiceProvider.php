<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\View;
use App\Models\ProfilSekolah;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
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

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(RaporTemplateProcessor::class, function ($app) {
            return new RaporTemplateProcessor();
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot()
    {
        try {
            // Create directories if they don't exist
            if (!file_exists(public_path('storage'))) {
                app('files')->makeDirectory(public_path('storage'), 0755, true);
            }
            if (!file_exists(storage_path('app/public'))) {
                app('files')->makeDirectory(storage_path('app/public'), 0755, true);
            }
            
            // Hanya jalankan storage:link jika belum ada
            if (!file_exists(public_path('storage'))) {
                Artisan::call('storage:link');
            }
            
            // Buat direktori previews jika belum ada
            if (!Storage::exists('public/previews')) {
                Storage::makeDirectory('public/previews');
            }

            $this->ensureProtectedFileDirectories();
        } catch (\Exception $e) {
            // Log the error but don't crash the application
            Log::warning('Unable to create storage symlink: ' . $e->getMessage());
        }
        
        View::composer('*', function ($view) {
            $schoolProfile = ProfilSekolah::first();
            $view->with('schoolProfile', $schoolProfile);
        });

        // Register the audit observers for various models
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

    protected function ensureProtectedFileDirectories(): void
    {
        $publicDiskDirectories = [
            'templates',
            'generated',
            'pdf_reports',
            'pdf_previews',
            'previews',
        ];

        foreach ($publicDiskDirectories as $directory) {
            $this->protectDirectory(Storage::disk('public')->path($directory));
        }

        $this->protectDirectory(public_path('downloads'));
    }

    protected function protectDirectory(string $directory): void
    {
        if (!file_exists($directory)) {
            app('files')->makeDirectory($directory, 0755, true);
        }

        $htaccessPath = $directory . DIRECTORY_SEPARATOR . '.htaccess';
        if (!file_exists($htaccessPath)) {
            file_put_contents($htaccessPath, <<<HTACCESS
<IfModule mod_authz_core.c>
    Require all denied
</IfModule>
<IfModule !mod_authz_core.c>
    Deny from all
</IfModule>
HTACCESS);
        }

        $webConfigPath = $directory . DIRECTORY_SEPARATOR . 'web.config';
        if (!file_exists($webConfigPath)) {
            file_put_contents($webConfigPath, <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<configuration>
  <system.webServer>
    <authorization>
      <remove users="*" roles="" verbs="" />
      <add accessType="Deny" users="*" />
    </authorization>
  </system.webServer>
</configuration>
XML);
        }
    }
}

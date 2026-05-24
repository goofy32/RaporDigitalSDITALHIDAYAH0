<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;

class SetupStorage extends Command
{
    protected $signature = 'app:setup-storage';

    protected $description = 'Create required storage directories, storage symlink, permissions, and protection files.';

    public function handle(): int
    {
        $this->info('Menyiapkan direktori storage aplikasi...');

        $this->ensureDirectory(storage_path('app/public'), 'storage/app/public');
        $this->ensureStorageLink();

        foreach (['templates', 'generated', 'pdf_reports', 'pdf_previews', 'previews'] as $directory) {
            $this->protectDirectory(
                Storage::disk('public')->path($directory),
                "storage/app/public/{$directory}"
            );
        }

        $this->protectDirectory(public_path('downloads'), 'public/downloads');

        $this->newLine();
        $this->info('Setup storage selesai.');

        return self::SUCCESS;
    }

    private function ensureStorageLink(): void
    {
        $linkPath = public_path('storage');

        if (is_link($linkPath)) {
            $this->line("Symlink sudah ada: {$linkPath}");
            return;
        }

        if (file_exists($linkPath)) {
            $this->warn("Path sudah ada dan bukan symlink, dilewati: {$linkPath}");
            return;
        }

        Artisan::call('storage:link');
        $output = trim(Artisan::output());

        if ($output !== '') {
            $this->line($output);
        }

        $this->info("Symlink storage dibuat: {$linkPath}");
    }

    private function ensureDirectory(string $path, string $label): void
    {
        if (!file_exists($path)) {
            app('files')->makeDirectory($path, 0755, true);
            $this->info("Direktori dibuat: {$label}");
        } else {
            $this->line("Direktori tersedia: {$label}");
        }

        @chmod($path, 0755);
    }

    private function protectDirectory(string $directory, string $label): void
    {
        $this->ensureDirectory($directory, $label);

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
            @chmod($htaccessPath, 0644);
            $this->info("Proteksi .htaccess dibuat: {$label}");
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
            @chmod($webConfigPath, 0644);
            $this->info("Proteksi web.config dibuat: {$label}");
        }
    }
}

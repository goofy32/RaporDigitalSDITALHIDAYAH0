<?php

namespace App\Console\Commands;

use App\Services\BaselinePreparationService;
use Illuminate\Console\Command;
use RuntimeException;
use Throwable;

class PrepareBaseline extends Command
{
    protected $signature = 'initial-data:prepare-baseline
        {mode : minimal or school-structure}
        {--apply : Apply the baseline cleanup. Without this option the command is read-only.}
        {--confirm= : Exact mode-and-database confirmation required by --apply}';

    protected $description = 'Prepare a guarded, deterministic database baseline in an allow-listed rehearsal database.';

    public function handle(BaselinePreparationService $service): int
    {
        $mode = strtolower(trim((string) $this->argument('mode')));

        try {
            $confirmation = $service->confirmationFor($mode);
            $plan = $service->inspect($mode);
        } catch (RuntimeException $exception) {
            $this->error('BLOCKER: '.$exception->getMessage());

            return self::FAILURE;
        } catch (Throwable) {
            $this->error('BLOCKER: preflight baseline tidak dapat diselesaikan dengan aman.');

            return self::FAILURE;
        }

        $this->displayPlan($plan, (bool) $this->option('apply'));

        if (! $this->option('apply')) {
            $this->info('DRY RUN: tidak ada data database atau file yang diubah.');
            $this->line('Apply membutuhkan --apply --confirm="'.$confirmation.'"');

            return self::SUCCESS;
        }

        if (! hash_equals($confirmation, (string) $this->option('confirm'))) {
            $this->error('BLOCKER: konfirmasi tidak valid. Gunakan teks mode dan database yang ditampilkan oleh dry-run.');

            return self::FAILURE;
        }

        try {
            $service->apply($mode, $plan);
        } catch (Throwable) {
            $this->error('Apply gagal. Seluruh perubahan database dalam transaksi telah dibatalkan.');

            return self::FAILURE;
        }

        $this->info('Baseline '.$mode.' selesai dan seluruh postcondition lulus. Filesystem tidak diubah.');

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $plan
     */
    private function displayPlan(array $plan, bool $apply): void
    {
        $this->warn('Mode eksekusi: '.($apply ? 'APPLY' : 'DRY RUN'));
        $this->line('Baseline: '.$plan['mode']);
        $this->line('Configured database: '.$plan['identity']['configured']);
        $this->line('SELECT DATABASE(): '.$plan['identity']['physical']);
        $this->line('Target Tahun Ajaran ID: '.$plan['target_year_id']);
        $this->line('Retained Guru IDs: '.count($plan['retained_guru_ids']));
        $this->line('Retained Kelas IDs: '.count($plan['retained_class_ids']));
        $this->line('Retained guru_kelas rows: '.count($plan['retained_pivot_ids']));
        $this->line('Role wali_kelas: '.$plan['role_counts']['wali_kelas']);
        $this->line('Role pengajar: '.$plan['role_counts']['pengajar']);
        $this->line('Guru dengan role Pengajar yang dipertahankan: '.count($plan['pengajar_guru_ids']));
        $this->line('Known settings: '.implode(', ', $plan['settings']['known_keys']));
        $this->line('active_wali_report_period target: UTS');
        $this->line('Profile logo references: '.$plan['files']['profile_logo']['references']);
        $this->line('Guru photo references: '.$plan['files']['guru_photos']['references']);
        $this->line('Guru signature references: '.$plan['files']['guru_signatures']['references']);
        $this->warn('Filesystem policy: READ ONLY. Tidak ada file yang disalin, diubah, atau dihapus.');

        $rows = [];
        foreach ($plan['counts'] as $table => $count) {
            $rows[] = [
                $table,
                $count,
                $plan['removal_counts'][$table] ?? 0,
                $count - ($plan['removal_counts'][$table] ?? 0),
            ];
        }

        $this->table(['Tabel', 'Saat ini', 'Akan dihapus', 'Akan tersisa'], $rows);
    }
}

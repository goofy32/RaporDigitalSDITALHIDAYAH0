<?php

namespace App\Console\Commands;

use App\Services\DummyCloneDatabaseIdentity;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Throwable;

class CleanupDummyClone extends Command
{
    private const ALLOWED_DATABASE = 'u975086294_rapor_dummy';

    private const PRODUCTION_DATABASE = 'u975086294_rapor_digital';

    private const PRESERVED_TABLES = [
        'migrations',
        'users',
        'profil_sekolah',
        'settings',
        'report_placeholders',
    ];

    /**
     * Child tables appear before their FK parents.
     */
    private const RESET_TABLES = [
        'notification_reads',
        'notification_user_states',
        'report_template_kelas',
        'pembelajaran_siswa',
        'report_generations',
        'nilais',
        'absensis',
        'nilai_ekstrakurikuler',
        'prestasis',
        'catatan_siswa',
        'catatan_mata_pelajaran',
        'capaian_custom',
        'capaian_phrase_defaults',
        'capaian_templates',
        'capaian_range',
        'kkms',
        'bobot_nilais',
        'semester_snapshots',
        'guru_kelas',
        'siswa_kelas_semester',
        'notifications',
        'audit_logs',
        'gemini_chats',
        'tujuan_pembelajarans',
        'lingkup_materis',
        'pembelajarans',
        'mata_pelajarans',
        'ekstrakurikulers',
        'siswas',
        'kelas',
        'gurus',
        'tahun_ajarans',
    ];

    private const VOLATILE_TABLES = [
        'sessions',
        'cache',
        'cache_locks',
        'jobs',
        'failed_jobs',
        'job_batches',
        'password_reset_tokens',
        'guru_password_reset_tokens',
        'batch_downloads',
    ];

    private const MANUAL_REVIEW_TABLES = [
        'report_templates',
        'report_mappings',
        'format_rapor',
    ];

    protected $signature = 'initial-data:cleanup-dummy-clone
        {--apply : Apply the cleanup. Without this option the command is read-only.}
        {--confirm= : Must exactly match the allowed dummy database name when applying}';

    protected $description = 'Preview or clean academic data only from the guarded Hostinger dummy database clone.';

    private DummyCloneDatabaseIdentity $identityInspector;

    public function handle(DummyCloneDatabaseIdentity $identityInspector): int
    {
        $this->identityInspector = $identityInspector;

        try {
            $identity = $this->inspectIdentity();
        } catch (Throwable $exception) {
            $this->error('Identitas database fisik tidak dapat diverifikasi. Cleanup dibatalkan.');

            return self::FAILURE;
        }

        $this->displayIdentity($identity);

        $apply = (bool) $this->option('apply');

        if ($apply) {
            try {
                $this->assertApplyRequest($identity);
            } catch (RuntimeException $exception) {
                $this->error($exception->getMessage());

                return self::FAILURE;
            }
        }

        $missingTables = $this->missingRequiredTables();
        if ($missingTables !== []) {
            $this->error('Cleanup dibatalkan karena tabel wajib tidak ditemukan: '.implode(', ', $missingTables).'.');

            return self::FAILURE;
        }

        $counts = $this->tableCounts();
        $this->displayPlan($counts, $apply);

        if (! $apply) {
            $this->info('DRY RUN: tidak ada data database atau file yang diubah.');
            $this->line(
                'Apply command: php artisan initial-data:cleanup-dummy-clone --apply --confirm="'.self::ALLOWED_DATABASE.'"'
            );

            return self::SUCCESS;
        }

        if ($counts['users'] !== 1) {
            $this->error('Cleanup dibatalkan: tabel users harus berisi tepat satu Admin. Jumlah saat ini: '.$counts['users'].'.');

            return self::FAILURE;
        }

        $preservedCounts = $this->countsFor(self::PRESERVED_TABLES);
        $manualReviewCounts = $this->countsFor(self::MANUAL_REVIEW_TABLES);
        $admin = DB::table('users')->select(['id', 'username', 'email', 'password'])->first();

        if ($admin === null) {
            $this->error('Cleanup dibatalkan: data Admin tidak dapat dibaca.');

            return self::FAILURE;
        }

        try {
            // This is intentionally the last operation before opening the destructive transaction.
            $this->assertAllowedIdentity($this->inspectIdentity());

            DB::transaction(function () use ($admin, $preservedCounts, $manualReviewCounts): void {
                $this->assertAllowedIdentity($this->inspectIdentity());
                $this->deleteAllRows(self::VOLATILE_TABLES);

                $this->assertAllowedIdentity($this->inspectIdentity());
                $this->deleteAllRows(self::RESET_TABLES);

                if (Schema::hasColumn('users', 'remember_token')) {
                    DB::table('users')->update(['remember_token' => null]);
                }

                $this->assertPostconditions($admin, $preservedCounts, $manualReviewCounts);
            }, 1);
        } catch (Throwable $exception) {
            $this->error('Cleanup gagal. Seluruh perubahan database dalam transaksi telah dibatalkan.');

            return self::FAILURE;
        }

        $this->info('Cleanup dummy clone selesai. Data Admin dan tabel konfigurasi yang diwajibkan tetap dipertahankan.');

        return self::SUCCESS;
    }

    /**
     * @return array{configured: string, physical: string, current_user: string}
     */
    private function inspectIdentity(): array
    {
        return $this->identityInspector->inspect();
    }

    /**
     * @param array{configured: string, physical: string, current_user: string} $identity
     */
    private function displayIdentity(array $identity): void
    {
        $this->line('Configured database: '.$identity['configured']);
        $this->line('SELECT DATABASE(): '.$identity['physical']);
        $this->line('CURRENT_USER(): '.$identity['current_user']);
    }

    /**
     * @param array{configured: string, physical: string, current_user: string} $identity
     */
    private function assertApplyRequest(array $identity): void
    {
        if ((string) $this->option('confirm') !== self::ALLOWED_DATABASE) {
            throw new RuntimeException(
                'Konfirmasi tidak valid. --apply wajib disertai --confirm="'.self::ALLOWED_DATABASE.'".'
            );
        }

        $this->assertAllowedIdentity($identity);
    }

    /**
     * @param array{configured: string, physical: string, current_user: string} $identity
     */
    private function assertAllowedIdentity(array $identity): void
    {
        if (
            $identity['physical'] === self::PRODUCTION_DATABASE
            || $identity['configured'] === self::PRODUCTION_DATABASE
        ) {
            throw new RuntimeException('DITOLAK: database production tidak boleh dibersihkan oleh command ini.');
        }

        if (
            $identity['physical'] !== self::ALLOWED_DATABASE
            || $identity['configured'] !== self::ALLOWED_DATABASE
        ) {
            throw new RuntimeException(
                'DITOLAK: configured database dan SELECT DATABASE() harus persis '.self::ALLOWED_DATABASE.'.'
            );
        }
    }

    /**
     * @return array<int, string>
     */
    private function missingRequiredTables(): array
    {
        return collect($this->allRequiredTables())
            ->reject(fn (string $table): bool => Schema::hasTable($table))
            ->values()
            ->all();
    }

    /**
     * @return array<int, string>
     */
    private function allRequiredTables(): array
    {
        return array_values(array_unique([
            ...self::PRESERVED_TABLES,
            ...self::RESET_TABLES,
            ...self::VOLATILE_TABLES,
            ...self::MANUAL_REVIEW_TABLES,
        ]));
    }

    /**
     * @return array<string, int>
     */
    private function tableCounts(): array
    {
        return $this->countsFor($this->allRequiredTables());
    }

    /**
     * @param array<int, string> $tables
     * @return array<string, int>
     */
    private function countsFor(array $tables): array
    {
        $counts = [];

        foreach ($tables as $table) {
            $counts[$table] = DB::table($table)->count();
        }

        return $counts;
    }

    /**
     * @param array<string, int> $counts
     */
    private function displayPlan(array $counts, bool $apply): void
    {
        $this->warn('Mode: '.($apply ? 'APPLY' : 'DRY RUN'));
        $this->warn('Filesystem: TIDAK disentuh. Template, foto, tanda tangan, DOCX, PDF, preview, dan batch ZIP tetap ada.');
        $this->warn(
            'MANUAL REVIEW: report_templates dan report_mappings dipertahankan. FK kelas/tahun yang dihapus dapat menjadi NULL dan perlu ditautkan ulang.'
        );

        $rows = [];

        foreach (self::PRESERVED_TABLES as $table) {
            $rows[] = ['PRESERVE', $table, $counts[$table], 'Tidak dihapus'];
        }

        foreach (self::RESET_TABLES as $table) {
            $rows[] = ['RESET', $table, $counts[$table], 'DELETE seluruh baris saat apply'];
        }

        foreach (self::VOLATILE_TABLES as $table) {
            $rows[] = ['VOLATILE', $table, $counts[$table], 'DELETE seluruh baris saat apply'];
        }

        foreach (self::MANUAL_REVIEW_TABLES as $table) {
            $rows[] = ['MANUAL REVIEW', $table, $counts[$table], 'Tidak dihapus'];
        }

        $this->table(['Kategori', 'Tabel', 'Rows sekarang', 'Tindakan'], $rows);
    }

    /**
     * @param array<int, string> $tables
     */
    private function deleteAllRows(array $tables): void
    {
        foreach ($tables as $table) {
            DB::table($table)->delete();
        }
    }

    /**
     * @param object $admin
     * @param array<string, int> $preservedCounts
     * @param array<string, int> $manualReviewCounts
     */
    private function assertPostconditions(object $admin, array $preservedCounts, array $manualReviewCounts): void
    {
        if (DB::table('users')->count() !== 1) {
            throw new RuntimeException('Postcondition gagal: users tidak berisi tepat satu Admin.');
        }

        $preservedAdmin = DB::table('users')->select(['id', 'username', 'email', 'password'])->first();
        if ($preservedAdmin === null || (array) $preservedAdmin !== (array) $admin) {
            throw new RuntimeException('Postcondition gagal: identitas atau password hash Admin berubah.');
        }

        foreach ([...self::RESET_TABLES, ...self::VOLATILE_TABLES] as $table) {
            if (DB::table($table)->count() !== 0) {
                throw new RuntimeException("Postcondition gagal: {$table} belum kosong.");
            }
        }

        foreach ($preservedCounts as $table => $expectedCount) {
            if (! Schema::hasTable($table) || DB::table($table)->count() !== $expectedCount) {
                throw new RuntimeException("Postcondition gagal: tabel preserved {$table} berubah.");
            }
        }

        foreach ($manualReviewCounts as $table => $expectedCount) {
            if (DB::table($table)->count() !== $expectedCount) {
                throw new RuntimeException("Postcondition gagal: jumlah baris manual-review {$table} berubah.");
            }
        }

        if (Schema::hasColumn('users', 'remember_token') && DB::table('users')->whereNotNull('remember_token')->exists()) {
            throw new RuntimeException('Postcondition gagal: remember_token Admin belum dibersihkan.');
        }
    }
}

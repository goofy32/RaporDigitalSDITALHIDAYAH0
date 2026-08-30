<?php

namespace App\Services;

use Illuminate\Database\Migrations\Migrator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

class BaselineSchemaInspector
{
    public const EXPECTED_TABLES = [
        'absensis',
        'audit_logs',
        'batch_downloads',
        'bobot_nilais',
        'cache',
        'cache_locks',
        'capaian_custom',
        'capaian_phrase_defaults',
        'capaian_range',
        'capaian_templates',
        'catatan_mata_pelajaran',
        'catatan_siswa',
        'ekstrakurikulers',
        'failed_jobs',
        'format_rapor',
        'gemini_chats',
        'guru_kelas',
        'guru_password_reset_tokens',
        'gurus',
        'job_batches',
        'jobs',
        'kelas',
        'kkms',
        'lingkup_materis',
        'mata_pelajarans',
        'migrations',
        'nilai_ekstrakurikuler',
        'nilais',
        'notification_reads',
        'notification_user_states',
        'notifications',
        'password_reset_tokens',
        'pembelajaran_siswa',
        'pembelajarans',
        'prestasis',
        'profil_sekolah',
        'report_generations',
        'report_mappings',
        'report_placeholders',
        'report_template_kelas',
        'report_templates',
        'semester_snapshots',
        'sessions',
        'settings',
        'siswa_kelas_semester',
        'siswas',
        'tahun_ajarans',
        'tujuan_pembelajarans',
        'users',
    ];

    public function __construct(private readonly Migrator $migrator)
    {
    }

    public function assertCurrentSchema(): void
    {
        $actual = collect(Schema::getTableListing())
            ->map(fn (string $table): string => str_contains($table, '.')
                ? substr($table, strrpos($table, '.') + 1)
                : $table)
            ->reject(fn (string $table): bool => $table === 'sqlite_sequence')
            ->sort()
            ->values()
            ->all();
        $expected = collect(self::EXPECTED_TABLES)->sort()->values()->all();

        $missing = array_values(array_diff($expected, $actual));
        $unexpected = array_values(array_diff($actual, $expected));

        if ($missing !== [] || $unexpected !== []) {
            throw new RuntimeException(sprintf(
                'Schema tidak cocok dengan aplikasi saat ini (missing=%d, unexpected=%d).',
                count($missing),
                count($unexpected)
            ));
        }

        $this->assertMigrationSet();

        if (! $this->hasAdminSingletonInvariant()) {
            throw new RuntimeException('Generated column/index singleton Admin tidak ditemukan atau tidak valid.');
        }
    }

    /**
     * @param  iterable<int, \SplFileInfo>|null  $files
     * @return array<int, string>
     */
    public function expectedMigrations(?iterable $files = null): array
    {
        $migrationNames = collect($files ?? File::files(database_path('migrations')))
            ->filter(fn (\SplFileInfo $file): bool => $file->getExtension() === 'php')
            ->map(fn (\SplFileInfo $file): string => $this->migrator->getMigrationName($file->getPathname()));
        $collisions = $migrationNames->duplicatesStrict()->unique()->values();

        if ($collisions->isNotEmpty()) {
            throw new RuntimeException(sprintf(
                'Nama migration source bertabrakan setelah normalisasi Laravel (collisions=%d).',
                $collisions->count()
            ));
        }

        return $migrationNames
            ->sort()
            ->values()
            ->all();
    }

    public function hasAdminSingletonInvariant(): bool
    {
        if (! Schema::hasColumn('users', 'admin_singleton')) {
            return false;
        }

        return match (DB::connection()->getDriverName()) {
            'mysql', 'mariadb' => $this->hasMysqlAdminSingletonInvariant(),
            'sqlite' => $this->hasSqliteAdminSingletonInvariant(),
            default => false,
        };
    }

    private function assertMigrationSet(): void
    {
        $actual = DB::table('migrations')
            ->pluck('migration')
            ->map(fn ($migration): string => (string) $migration)
            ->sort()
            ->values()
            ->all();
        $expected = $this->expectedMigrations();

        if ($actual !== $expected) {
            throw new RuntimeException(sprintf(
                'Migration set tidak lengkap atau tidak cocok (expected=%d, actual=%d).',
                count($expected),
                count($actual)
            ));
        }
    }

    private function hasMysqlAdminSingletonInvariant(): bool
    {
        $column = DB::selectOne(
            <<<'SQL'
                SELECT GENERATION_EXPRESSION AS expression
                FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE()
                  AND TABLE_NAME = 'users'
                  AND COLUMN_NAME = 'admin_singleton'
                SQL
        );
        $expression = preg_replace('/[()\s`]+/', '', (string) ($column->expression ?? ''));

        $indexColumns = DB::select(
            <<<'SQL'
                SELECT COLUMN_NAME AS column_name, NON_UNIQUE AS non_unique
                FROM information_schema.STATISTICS
                WHERE TABLE_SCHEMA = DATABASE()
                  AND TABLE_NAME = 'users'
                  AND INDEX_NAME = 'users_admin_singleton_unique'
                ORDER BY SEQ_IN_INDEX
                SQL
        );

        return $expression === '1'
            && count($indexColumns) === 1
            && (string) ($indexColumns[0]->column_name ?? '') === 'admin_singleton'
            && (int) ($indexColumns[0]->non_unique ?? 1) === 0;
    }

    private function hasSqliteAdminSingletonInvariant(): bool
    {
        $columns = DB::select("PRAGMA table_xinfo('users')");
        $column = collect($columns)->first(
            fn (object $item): bool => (string) ($item->name ?? '') === 'admin_singleton'
        );

        if ($column === null || (int) ($column->hidden ?? 0) === 0) {
            return false;
        }

        $indexes = DB::select("PRAGMA index_list('users')");
        $index = collect($indexes)->first(
            fn (object $item): bool => (string) ($item->name ?? '') === 'users_admin_singleton_unique'
                && (int) ($item->unique ?? 0) === 1
        );

        if ($index === null) {
            return false;
        }

        $indexColumns = DB::select("PRAGMA index_info('users_admin_singleton_unique')");

        return count($indexColumns) === 1
            && (string) ($indexColumns[0]->name ?? '') === 'admin_singleton';
    }
}

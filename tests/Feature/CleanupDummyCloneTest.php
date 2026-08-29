<?php

namespace Tests\Feature;

use App\Services\DummyCloneDatabaseIdentity;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class CleanupDummyCloneTest extends TestCase
{
    private const DUMMY_DATABASE = 'u975086294_rapor_dummy';

    private const PRESERVED_TABLES = [
        'migrations',
        'users',
        'profil_sekolah',
        'settings',
        'report_placeholders',
    ];

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

    /**
     * Tables are created in parent-before-child order for the representative FK graph.
     */
    private const SCHEMA_ORDER = [
        'gurus',
        'tahun_ajarans',
        'kelas',
        'siswas',
        'mata_pelajarans',
        'lingkup_materis',
        'tujuan_pembelajarans',
        'pembelajarans',
        'ekstrakurikulers',
        'notifications',
        'report_templates',
        'report_mappings',
        'format_rapor',
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
        'audit_logs',
        'gemini_chats',
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

    /**
     * @var array<string, array<string, string>>
     */
    private const RELATIONS = [
        'kelas' => ['tahun_ajaran_id' => 'tahun_ajarans'],
        'siswas' => ['kelas_id' => 'kelas', 'tahun_ajaran_id' => 'tahun_ajarans'],
        'mata_pelajarans' => [
            'kelas_id' => 'kelas',
            'guru_id' => 'gurus',
            'tahun_ajaran_id' => 'tahun_ajarans',
        ],
        'lingkup_materis' => ['mata_pelajaran_id' => 'mata_pelajarans'],
        'tujuan_pembelajarans' => ['lingkup_materi_id' => 'lingkup_materis'],
        'pembelajarans' => [
            'kelas_id' => 'kelas',
            'mata_pelajaran_id' => 'mata_pelajarans',
            'guru_id' => 'gurus',
        ],
        'report_templates' => ['kelas_id' => 'kelas', 'tahun_ajaran_id' => 'tahun_ajarans'],
        'report_mappings' => ['report_template_id' => 'report_templates', 'tahun_ajaran_id' => 'tahun_ajarans'],
        'notification_reads' => ['notification_id' => 'notifications', 'guru_id' => 'gurus'],
        'notification_user_states' => ['notification_id' => 'notifications'],
        'report_template_kelas' => ['report_template_id' => 'report_templates', 'kelas_id' => 'kelas'],
        'pembelajaran_siswa' => ['pembelajaran_id' => 'pembelajarans', 'siswa_id' => 'siswas'],
        'report_generations' => [
            'siswa_id' => 'siswas',
            'kelas_id' => 'kelas',
            'report_template_id' => 'report_templates',
            'generated_by' => 'gurus',
            'tahun_ajaran_id' => 'tahun_ajarans',
        ],
        'nilais' => [
            'siswa_id' => 'siswas',
            'mata_pelajaran_id' => 'mata_pelajarans',
            'tujuan_pembelajaran_id' => 'tujuan_pembelajarans',
            'lingkup_materi_id' => 'lingkup_materis',
            'tahun_ajaran_id' => 'tahun_ajarans',
        ],
        'absensis' => ['siswa_id' => 'siswas', 'tahun_ajaran_id' => 'tahun_ajarans'],
        'nilai_ekstrakurikuler' => [
            'siswa_id' => 'siswas',
            'ekstrakurikuler_id' => 'ekstrakurikulers',
            'tahun_ajaran_id' => 'tahun_ajarans',
        ],
        'prestasis' => [
            'siswa_id' => 'siswas',
            'kelas_id' => 'kelas',
            'tahun_ajaran_id' => 'tahun_ajarans',
        ],
        'catatan_siswa' => [
            'siswa_id' => 'siswas',
            'tahun_ajaran_id' => 'tahun_ajarans',
            'created_by' => 'gurus',
        ],
        'catatan_mata_pelajaran' => [
            'mata_pelajaran_id' => 'mata_pelajarans',
            'siswa_id' => 'siswas',
            'tahun_ajaran_id' => 'tahun_ajarans',
            'created_by' => 'gurus',
        ],
        'capaian_custom' => [
            'siswa_id' => 'siswas',
            'mata_pelajaran_id' => 'mata_pelajarans',
            'tahun_ajaran_id' => 'tahun_ajarans',
        ],
        'capaian_templates' => ['tahun_ajaran_id' => 'tahun_ajarans'],
        'capaian_range' => ['tahun_ajaran_id' => 'tahun_ajarans'],
        'kkms' => [
            'mata_pelajaran_id' => 'mata_pelajarans',
            'kelas_id' => 'kelas',
            'tahun_ajaran_id' => 'tahun_ajarans',
        ],
        'bobot_nilais' => ['tahun_ajaran_id' => 'tahun_ajarans'],
        'semester_snapshots' => ['tahun_ajaran_id' => 'tahun_ajarans'],
        'guru_kelas' => ['guru_id' => 'gurus', 'kelas_id' => 'kelas'],
        'siswa_kelas_semester' => [
            'siswa_id' => 'siswas',
            'kelas_id' => 'kelas',
            'tahun_ajaran_id' => 'tahun_ajarans',
        ],
        'gemini_chats' => ['user_id' => 'users'],
        'batch_downloads' => ['guru_id' => 'gurus'],
    ];

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('database.default', 'sqlite');
        config()->set('database.connections.sqlite.database', ':memory:');
        DB::purge('sqlite');
        DB::reconnect('sqlite');
        Schema::enableForeignKeyConstraints();

        $this->createSchema();
        $this->bindIdentity(self::DUMMY_DATABASE, self::DUMMY_DATABASE);
    }

    public function test_default_mode_is_read_only_and_reports_identity_counts_and_filesystem_policy(): void
    {
        $this->seedAllTables();
        $before = $this->allTableCounts();

        $exitCode = Artisan::call('initial-data:cleanup-dummy-clone');
        $output = Artisan::output();

        $this->assertSame(0, $exitCode);
        $this->assertSame($before, $this->allTableCounts());
        $this->assertStringContainsString('Configured database: '.self::DUMMY_DATABASE, $output);
        $this->assertStringContainsString('SELECT DATABASE(): '.self::DUMMY_DATABASE, $output);
        $this->assertStringContainsString('CURRENT_USER(): dummy_user@localhost', $output);
        $this->assertStringContainsString('DRY RUN', $output);
        $this->assertStringContainsString('PRESERVE', $output);
        $this->assertStringContainsString('RESET', $output);
        $this->assertStringContainsString('VOLATILE', $output);
        $this->assertStringContainsString('MANUAL REVIEW', $output);
        $this->assertStringContainsString('Filesystem: TIDAK disentuh', $output);
    }

    public function test_apply_rejects_any_non_dummy_physical_database(): void
    {
        $this->seedAllTables();
        $this->bindIdentity(self::DUMMY_DATABASE, 'another_database');

        $exitCode = $this->applyCommand();

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('DITOLAK', Artisan::output());
        $this->assertSame(1, DB::table('gurus')->count());
    }

    public function test_apply_explicitly_rejects_the_production_database(): void
    {
        $this->seedAllTables();
        $this->bindIdentity(self::DUMMY_DATABASE, 'u975086294_rapor_digital');

        $exitCode = $this->applyCommand();

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('database production', Artisan::output());
        $this->assertSame(1, DB::table('gurus')->count());
    }

    public function test_apply_requires_the_exact_typed_confirmation(): void
    {
        $this->seedAllTables();

        $missingConfirmation = Artisan::call('initial-data:cleanup-dummy-clone', ['--apply' => true]);
        $this->assertSame(1, $missingConfirmation);
        $this->assertSame(1, DB::table('gurus')->count());

        $wrongConfirmation = Artisan::call('initial-data:cleanup-dummy-clone', [
            '--apply' => true,
            '--confirm' => strtoupper(self::DUMMY_DATABASE),
        ]);
        $this->assertSame(1, $wrongConfirmation);
        $this->assertSame(1, DB::table('gurus')->count());
    }

    public function test_apply_requires_exactly_one_admin(): void
    {
        $this->insertAdmin(1);
        $this->insertAdmin(2);

        $this->assertSame(1, $this->applyCommand());
        $this->assertStringContainsString('tepat satu Admin', Artisan::output());
        $this->assertSame(2, DB::table('users')->count());

        DB::table('users')->delete();

        $this->assertSame(1, $this->applyCommand());
        $this->assertStringContainsString('tepat satu Admin', Artisan::output());
        $this->assertSame(0, DB::table('users')->count());
    }

    public function test_apply_preserves_configuration_and_manual_review_rows_and_clears_expected_data(): void
    {
        $this->seedAllTables();
        $sentinel = storage_path('framework/dummy-cleanup-filesystem-sentinel.txt');
        file_put_contents($sentinel, 'must remain');

        try {
            $exitCode = $this->applyCommand();

            $this->assertSame(0, $exitCode);
            $this->assertSame(1, DB::table('users')->count());
            $this->assertDatabaseHas('users', [
                'id' => 1,
                'username' => 'admin_dummy',
                'email' => 'admin@example.test',
                'password' => 'hashed-admin-password',
                'remember_token' => null,
            ]);

            foreach (array_diff(self::PRESERVED_TABLES, ['users']) as $table) {
                $this->assertSame(1, DB::table($table)->count(), "Preserved table changed: {$table}");
            }

            foreach ([...self::RESET_TABLES, ...self::VOLATILE_TABLES] as $table) {
                $this->assertSame(0, DB::table($table)->count(), "Cleanup table not empty: {$table}");
            }

            foreach (self::MANUAL_REVIEW_TABLES as $table) {
                $this->assertSame(1, DB::table($table)->count(), "Manual-review table changed: {$table}");
            }

            $this->assertDatabaseHas('report_templates', [
                'id' => 1,
                'marker' => 'report_templates-row',
                'kelas_id' => null,
                'tahun_ajaran_id' => null,
            ]);
            $this->assertDatabaseHas('report_mappings', [
                'id' => 1,
                'marker' => 'report_mappings-row',
                'report_template_id' => 1,
                'tahun_ajaran_id' => null,
            ]);
            $this->assertFileExists($sentinel);
            $this->assertSame('must remain', file_get_contents($sentinel));
        } finally {
            if (is_file($sentinel)) {
                unlink($sentinel);
            }
        }
    }

    public function test_any_deletion_failure_rolls_back_the_complete_transaction(): void
    {
        $this->seedAllTables();
        $before = $this->allTableCounts();
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER prevent_guru_cleanup
            BEFORE DELETE ON gurus
            BEGIN
                SELECT RAISE(ABORT, 'forced cleanup failure');
            END
        SQL);

        $exitCode = $this->applyCommand();

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('Seluruh perubahan database dalam transaksi telah dibatalkan', Artisan::output());
        $this->assertSame($before, $this->allTableCounts());
        $this->assertSame('admin-remember-token', DB::table('users')->value('remember_token'));
    }

    public function test_missing_required_table_fails_instead_of_being_silently_skipped(): void
    {
        Schema::drop('nilais');

        $exitCode = Artisan::call('initial-data:cleanup-dummy-clone');

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('tabel wajib tidak ditemukan: nilais', Artisan::output());
    }

    private function applyCommand(): int
    {
        return Artisan::call('initial-data:cleanup-dummy-clone', [
            '--apply' => true,
            '--confirm' => self::DUMMY_DATABASE,
        ]);
    }

    private function bindIdentity(string $configured, string $physical): void
    {
        $identity = [
            'configured' => $configured,
            'physical' => $physical,
            'current_user' => 'dummy_user@localhost',
        ];

        $this->app->instance(DummyCloneDatabaseIdentity::class, new class($identity) extends DummyCloneDatabaseIdentity
        {
            /**
             * @param array{configured: string, physical: string, current_user: string} $identity
             */
            public function __construct(private readonly array $identity) {}

            /**
             * @return array{configured: string, physical: string, current_user: string}
             */
            public function inspect(): array
            {
                return $this->identity;
            }
        });
    }

    private function createSchema(): void
    {
        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('username')->unique();
            $table->string('email')->unique();
            $table->string('password');
            $table->rememberToken();
            $table->timestamps();
        });

        foreach (array_diff(self::PRESERVED_TABLES, ['users']) as $table) {
            $this->createTable($table);
        }

        foreach (self::SCHEMA_ORDER as $table) {
            $this->createTable($table);
        }
    }

    private function createTable(string $tableName): void
    {
        Schema::create($tableName, function (Blueprint $table) use ($tableName): void {
            $table->id();
            $table->string('marker')->nullable();

            foreach (self::RELATIONS[$tableName] ?? [] as $column => $parentTable) {
                $foreign = $table->foreignId($column)->nullable();

                if (
                    in_array($tableName, self::MANUAL_REVIEW_TABLES, true)
                    && in_array($column, ['kelas_id', 'tahun_ajaran_id'], true)
                ) {
                    $foreign->constrained($parentTable)->nullOnDelete();
                } else {
                    $foreign->constrained($parentTable)->restrictOnDelete();
                }
            }
        });
    }

    private function seedAllTables(): void
    {
        $this->insertAdmin(1);

        foreach (array_diff(self::PRESERVED_TABLES, ['users']) as $table) {
            DB::table($table)->insert(['id' => 1, 'marker' => "{$table}-row"]);
        }

        foreach (self::SCHEMA_ORDER as $table) {
            $row = ['id' => 1, 'marker' => "{$table}-row"];

            foreach (self::RELATIONS[$table] ?? [] as $column => $parentTable) {
                $row[$column] = 1;
            }

            DB::table($table)->insert($row);
        }
    }

    private function insertAdmin(int $id): void
    {
        DB::table('users')->insert([
            'id' => $id,
            'name' => "Admin {$id}",
            'username' => "admin_dummy_{$id}",
            'email' => "admin{$id}@example.test",
            'password' => 'hashed-admin-password',
            'remember_token' => 'admin-remember-token',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        if ($id === 1) {
            DB::table('users')->where('id', 1)->update([
                'username' => 'admin_dummy',
                'email' => 'admin@example.test',
            ]);
        }
    }

    /**
     * @return array<string, int>
     */
    private function allTableCounts(): array
    {
        $tables = [
            ...self::PRESERVED_TABLES,
            ...self::RESET_TABLES,
            ...self::VOLATILE_TABLES,
            ...self::MANUAL_REVIEW_TABLES,
        ];
        $counts = [];

        foreach ($tables as $table) {
            $counts[$table] = DB::table($table)->count();
        }

        return $counts;
    }
}

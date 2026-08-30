<?php

namespace Tests\Feature;

use App\Models\Guru;
use App\Services\BaselineDatabaseIdentity;
use App\Services\BaselinePreparationService;
use App\Services\BaselineSchemaInspector;
use App\Services\GuruRoleAvailability;
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

class PrepareBaselineTest extends TestCase
{
    private const BASELINE_A_DATABASE = 'u975086294_raporbaselineA';

    private const BASELINE_B_DATABASE = 'u975086294_raporbaselineB';

    private const RELATIONS = [
        'siswas' => ['kelas_id' => 'kelas', 'tahun_ajaran_id' => 'tahun_ajarans'],
        'mata_pelajarans' => ['kelas_id' => 'kelas', 'guru_id' => 'gurus', 'tahun_ajaran_id' => 'tahun_ajarans'],
        'lingkup_materis' => ['mata_pelajaran_id' => 'mata_pelajarans'],
        'tujuan_pembelajarans' => ['lingkup_materi_id' => 'lingkup_materis'],
        'pembelajarans' => ['kelas_id' => 'kelas', 'mata_pelajaran_id' => 'mata_pelajarans', 'guru_id' => 'gurus'],
        'pembelajaran_siswa' => ['pembelajaran_id' => 'pembelajarans', 'siswa_id' => 'siswas'],
        'nilai_ekstrakurikuler' => ['siswa_id' => 'siswas', 'ekstrakurikuler_id' => 'ekstrakurikulers', 'tahun_ajaran_id' => 'tahun_ajarans'],
        'absensis' => ['siswa_id' => 'siswas', 'tahun_ajaran_id' => 'tahun_ajarans'],
        'prestasis' => ['kelas_id' => 'kelas', 'siswa_id' => 'siswas', 'tahun_ajaran_id' => 'tahun_ajarans'],
        'notification_reads' => ['notification_id' => 'notifications', 'guru_id' => 'gurus'],
        'notification_user_states' => ['notification_id' => 'notifications'],
        'report_templates' => ['kelas_id' => 'kelas', 'tahun_ajaran_id' => 'tahun_ajarans'],
        'report_mappings' => ['report_template_id' => 'report_templates', 'tahun_ajaran_id' => 'tahun_ajarans'],
        'report_template_kelas' => ['report_template_id' => 'report_templates', 'kelas_id' => 'kelas'],
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
        'catatan_siswa' => ['siswa_id' => 'siswas', 'tahun_ajaran_id' => 'tahun_ajarans', 'created_by' => 'gurus'],
        'catatan_mata_pelajaran' => [
            'mata_pelajaran_id' => 'mata_pelajarans',
            'siswa_id' => 'siswas',
            'tahun_ajaran_id' => 'tahun_ajarans',
            'created_by' => 'gurus',
        ],
        'capaian_custom' => ['siswa_id' => 'siswas', 'mata_pelajaran_id' => 'mata_pelajarans', 'tahun_ajaran_id' => 'tahun_ajarans'],
        'capaian_templates' => ['tahun_ajaran_id' => 'tahun_ajarans'],
        'capaian_range' => ['tahun_ajaran_id' => 'tahun_ajarans'],
        'kkms' => ['mata_pelajaran_id' => 'mata_pelajarans', 'kelas_id' => 'kelas', 'tahun_ajaran_id' => 'tahun_ajarans'],
        'bobot_nilais' => ['tahun_ajaran_id' => 'tahun_ajarans'],
        'semester_snapshots' => ['tahun_ajaran_id' => 'tahun_ajarans'],
        'siswa_kelas_semester' => ['siswa_id' => 'siswas', 'kelas_id' => 'kelas', 'tahun_ajaran_id' => 'tahun_ajarans'],
        'gemini_chats' => ['user_id' => 'users'],
        'batch_downloads' => ['guru_id' => 'gurus'],
    ];

    private const DATA_ORDER = [
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

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('database.default', 'sqlite');
        config()->set('database.connections.sqlite.database', ':memory:');
        DB::purge('sqlite');
        DB::reconnect('sqlite');
        Schema::enableForeignKeyConstraints();
        Storage::fake('public');
        Storage::fake('local');

        $this->createSchema();
        $this->seedValidCandidate();
        $this->bindIdentity(self::BASELINE_A_DATABASE, self::BASELINE_A_DATABASE);
    }

    public function test_production_database_name_is_always_rejected(): void
    {
        $before = $this->allTableCounts();
        $this->bindIdentity(BaselinePreparationService::PRODUCTION_DATABASE, BaselinePreparationService::PRODUCTION_DATABASE);

        $this->assertSame(1, Artisan::call('initial-data:prepare-baseline', ['mode' => 'minimal']));
        $this->assertStringContainsString('database production', Artisan::output());
        $this->assertSame($before, $this->allTableCounts());
    }

    public function test_configured_database_mismatch_is_rejected(): void
    {
        $before = $this->allTableCounts();
        $this->bindIdentity(self::BASELINE_A_DATABASE, self::BASELINE_B_DATABASE);

        $this->assertSame(1, Artisan::call('initial-data:prepare-baseline', ['mode' => 'minimal']));
        $this->assertStringContainsString('tidak sama dengan SELECT DATABASE()', Artisan::output());
        $this->assertSame($before, $this->allTableCounts());
    }

    public function test_non_allowlisted_database_is_rejected(): void
    {
        $this->bindIdentity('rapor_random_clone', 'rapor_random_clone');

        $this->assertSame(1, Artisan::call('initial-data:prepare-baseline', ['mode' => 'minimal']));
        $this->assertStringContainsString('bukan allow-list', Artisan::output());
    }

    public function test_dry_run_changes_nothing_and_reports_safe_plan(): void
    {
        $beforeState = $this->allTableRows();

        $this->assertSame(0, Artisan::call('initial-data:prepare-baseline', ['mode' => 'minimal']));
        $output = Artisan::output();

        $this->assertSame($beforeState, $this->allTableRows());
        $this->assertStringContainsString('DRY RUN', $output);
        $this->assertStringContainsString('Target Tahun Ajaran ID: 1', $output);
        $this->assertStringContainsString('Profile logo references: 1', $output);
        $this->assertStringNotContainsString('school/logo.png', $output);
        $this->assertStringNotContainsString('admin@example.test', $output);
        $this->assertStringNotContainsString('hashed-admin-password', $output);
    }

    public function test_cleanup_manifest_classifies_every_schema_table_exactly_once(): void
    {
        $classified = [
            ...BaselinePreparationService::CONTROL_TABLES,
            ...BaselinePreparationService::STRUCTURE_TABLES,
            ...BaselinePreparationService::CLEAR_TABLES,
            ...BaselinePreparationService::VOLATILE_TABLES,
        ];

        $this->assertCount(count(array_unique($classified)), $classified);
        sort($classified);
        $expected = BaselineSchemaInspector::EXPECTED_TABLES;
        sort($expected);
        $this->assertSame($expected, $classified);
    }

    public function test_migration_names_use_laravel_semantics_for_normal_and_double_extensions(): void
    {
        $inspector = app(BaselineSchemaInspector::class);

        $this->assertSame(
            ['example_name'],
            $inspector->expectedMigrations([new \SplFileInfo('example_name.php')])
        );
        $this->assertSame(
            ['example_name'],
            $inspector->expectedMigrations([new \SplFileInfo('example_name.php.php')])
        );
    }

    public function test_real_legacy_double_extension_matches_laravel_migration_row(): void
    {
        $canonical = '2025_02_09_142601_create_guru_kelas_table';
        $expected = app(BaselineSchemaInspector::class)->expectedMigrations();

        $this->assertContains($canonical, $expected);
        $this->assertNotContains($canonical.'.php', $expected);
        $this->assertDatabaseHas('migrations', ['migration' => $canonical]);
    }

    public function test_genuinely_missing_migration_still_blocks(): void
    {
        $migration = DB::table('migrations')->orderBy('migration')->value('migration');
        DB::table('migrations')->where('migration', $migration)->delete();

        $this->assertSame(1, Artisan::call('initial-data:prepare-baseline', ['mode' => 'minimal']));
        $this->assertStringContainsString('Migration set tidak lengkap atau tidak cocok', Artisan::output());
    }

    public function test_genuinely_extra_database_migration_still_blocks(): void
    {
        DB::table('migrations')->insert([
            'migration' => '2099_01_01_000000_unexpected_extra_migration',
            'batch' => 999,
        ]);

        $this->assertSame(1, Artisan::call('initial-data:prepare-baseline', ['mode' => 'minimal']));
        $this->assertStringContainsString('Migration set tidak lengkap atau tidak cocok', Artisan::output());
    }

    public function test_same_migration_count_with_different_names_still_blocks(): void
    {
        $migration = DB::table('migrations')->orderBy('migration')->value('migration');
        DB::table('migrations')->where('migration', $migration)->update([
            'migration' => '2099_01_01_000000_replaced_migration_name',
        ]);

        $this->assertSame(1, Artisan::call('initial-data:prepare-baseline', ['mode' => 'minimal']));
        $this->assertStringContainsString('Migration set tidak lengkap atau tidak cocok', Artisan::output());
        $this->assertSame(count(File::files(database_path('migrations'))), DB::table('migrations')->count());
    }

    public function test_canonical_migration_name_collision_fails_closed(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Nama migration source bertabrakan');

        app(BaselineSchemaInspector::class)->expectedMigrations([
            new \SplFileInfo('duplicate_name.php'),
            new \SplFileInfo('duplicate_name.php.php'),
        ]);
    }

    public function test_unexpected_schema_table_blocks_before_mutation(): void
    {
        Schema::create('future_unclassified_table', function (Blueprint $table): void {
            $table->id();
        });
        $before = $this->allTableRows();

        $this->assertSame(1, Artisan::call('initial-data:prepare-baseline', ['mode' => 'minimal']));
        $this->assertStringContainsString('Schema tidak cocok', Artisan::output());
        $this->assertSame($before, $this->allTableRows());
    }

    public function test_apply_requires_exact_mode_and_database_confirmation(): void
    {
        $before = $this->allTableCounts();

        $this->assertSame(1, Artisan::call('initial-data:prepare-baseline', [
            'mode' => 'minimal',
            '--apply' => true,
        ]));
        $this->assertSame(1, Artisan::call('initial-data:prepare-baseline', [
            'mode' => 'minimal',
            '--apply' => true,
            '--confirm' => 'PREPARE BASELINE minimal ON '.self::BASELINE_B_DATABASE,
        ]));
        $this->assertSame($before, $this->allTableCounts());
    }

    public function test_minimal_mode_creates_exact_baseline_a(): void
    {
        $this->assertSame(0, $this->apply('minimal'));

        $this->assertCoreBaseline(0, 0, 0, 0);
        $this->assertSame(0, DB::table('mata_pelajarans')->count());
        $this->assertSame(1, DB::table('tahun_ajarans')->count());
        $this->assertDatabaseHas('tahun_ajarans', [
            'tahun_ajaran' => '2026/2027',
            'semester' => 1,
            'is_active' => 1,
            'deleted_at' => null,
        ]);
    }

    public function test_school_structure_mode_creates_exact_baseline_b(): void
    {
        $subjectIds = DB::table('mata_pelajarans')->orderBy('id')->pluck('id')->all();
        $pivotIds = DB::table('guru_kelas')
            ->whereIn('kelas_id', range(1, 12))
            ->orderBy('id')
            ->pluck('id')
            ->all();
        $this->bindIdentity(self::BASELINE_B_DATABASE, self::BASELINE_B_DATABASE);

        $this->assertSame(0, $this->apply('school-structure'));

        $this->assertCoreBaseline(18, 12, 18, 1);
        $this->assertSame($subjectIds, DB::table('mata_pelajarans')->orderBy('id')->pluck('id')->all());
        $this->assertSame($pivotIds, DB::table('guru_kelas')->orderBy('id')->pluck('id')->all());
        $this->assertSame(12, DB::table('guru_kelas')->where('role', 'wali_kelas')->count());
        $this->assertSame(6, DB::table('guru_kelas')->where('role', 'pengajar')->count());
        $this->assertSame(0, DB::table('kelas')->where('tahun_ajaran_id', '!=', 1)->count());
        foreach (['lingkup_materis', 'tujuan_pembelajarans', 'siswas', 'siswa_kelas_semester', 'nilais', 'absensis'] as $table) {
            $this->assertSame(0, DB::table($table)->count(), "{$table} should be empty");
        }
    }

    public function test_school_structure_dry_run_preserves_subject_plan_without_writes(): void
    {
        $before = $this->allTableRows();
        $this->bindIdentity(self::BASELINE_B_DATABASE, self::BASELINE_B_DATABASE);

        $this->assertSame(0, Artisan::call('initial-data:prepare-baseline', ['mode' => 'school-structure']));

        $this->assertSame($before, $this->allTableRows());
        $this->assertStringContainsString('Retained Mata Pelajaran IDs: 1', Artisan::output());
    }

    public function test_school_structure_retains_the_exact_dynamic_valid_subject_set(): void
    {
        $this->insertSubject(2, 14, 2);
        $expectedIds = DB::table('mata_pelajarans')->orderBy('id')->pluck('id')->all();
        $this->bindIdentity(self::BASELINE_B_DATABASE, self::BASELINE_B_DATABASE);

        $this->assertSame(0, $this->apply('school-structure'));

        $this->assertSame([1, 2], $expectedIds);
        $this->assertSame($expectedIds, DB::table('mata_pelajarans')->orderBy('id')->pluck('id')->all());
        $this->assertSame(0, DB::table('lingkup_materis')->count());
        $this->assertSame(0, DB::table('tujuan_pembelajarans')->count());
    }

    public function test_admin_identity_credentials_and_hash_are_preserved_while_transient_state_is_cleared(): void
    {
        $before = (array) DB::table('users')->first();

        $this->assertSame(0, $this->apply('minimal'));

        $after = (array) DB::table('users')->first();
        foreach (['id', 'name', 'username', 'email', 'email_verified_at', 'password'] as $field) {
            $this->assertSame($before[$field], $after[$field]);
        }
        foreach (['remember_token', 'pending_email', 'pending_email_token_hash', 'pending_email_expires_at'] as $field) {
            $this->assertNull($after[$field]);
        }
    }

    public function test_guru_hashes_status_and_file_references_are_preserved_in_baseline_b(): void
    {
        $before = DB::table('gurus')->orderBy('id')->get()->keyBy('id');
        $this->bindIdentity(self::BASELINE_B_DATABASE, self::BASELINE_B_DATABASE);

        $this->assertSame(0, $this->apply('school-structure'));

        $after = DB::table('gurus')->orderBy('id')->get()->keyBy('id');
        foreach ($before as $id => $guru) {
            $this->assertSame($guru->password, $after[$id]->password);
            $this->assertSame($guru->email_verified_at, $after[$id]->email_verified_at);
            $this->assertSame($guru->must_change_password, $after[$id]->must_change_password);
            $this->assertSame($guru->photo, $after[$id]->photo);
            $this->assertSame($guru->signature_path, $after[$id]->signature_path);
            $this->assertNull($after[$id]->password_plain);
        }
    }

    public function test_report_placeholders_are_preserved_unchanged(): void
    {
        $before = DB::table('report_placeholders')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();

        $this->assertSame(0, $this->apply('minimal'));

        $after = DB::table('report_placeholders')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
        $this->assertSame($before, $after);
    }

    public function test_all_report_template_and_history_tables_are_removed(): void
    {
        $this->assertSame(0, $this->apply('minimal'));

        foreach (['report_templates', 'report_mappings', 'report_template_kelas', 'format_rapor', 'report_generations', 'batch_downloads'] as $table) {
            $this->assertSame(0, DB::table($table)->count(), "{$table} should be empty");
        }
    }

    public function test_active_wali_report_period_is_explicitly_uts_and_known_setting_is_preserved(): void
    {
        $this->assertSame(0, $this->apply('minimal'));

        $this->assertSame('UTS', DB::table('settings')->where('key', 'active_wali_report_period')->value('value'));
        $this->assertSame('1', DB::table('settings')->where('key', 'kkm_notification_complete_scores_only')->value('value'));
        $this->assertSame(2, DB::table('settings')->count());
    }

    public function test_profile_counts_are_normalized_without_clearing_identity_or_logo(): void
    {
        $before = DB::table('profil_sekolah')->first();

        $this->assertSame(0, $this->apply('minimal'));

        $after = DB::table('profil_sekolah')->first();
        $this->assertSame($before->nama_sekolah, $after->nama_sekolah);
        $this->assertSame($before->logo, $after->logo);
        $this->assertSame('2026/2027', $after->tahun_pelajaran);
        $this->assertSame(1, (int) $after->semester);
        $this->assertSame(0, (int) $after->jumlah_siswa);
        $this->assertSame(0, (int) $after->guru_kelas);
        $this->assertSame(0, (int) $after->kelas);
    }

    public function test_soft_deleted_business_rows_are_physically_removed(): void
    {
        $this->assertNotNull(DB::table('siswas')->value('deleted_at'));

        $this->assertSame(0, $this->apply('minimal'));

        $this->assertSame(0, DB::table('siswas')->count());
        $this->assertSame(0, DB::table('gurus')->count());
        $this->assertSame(0, DB::table('kelas')->count());
        $this->assertSame(0, DB::table('tahun_ajarans')->where('id', '!=', 1)->count());
    }

    public function test_all_volatile_tables_are_emptied(): void
    {
        $this->assertSame(0, $this->apply('minimal'));

        foreach (BaselinePreparationService::VOLATILE_TABLES as $table) {
            $this->assertSame(0, DB::table($table)->count(), "{$table} should be empty");
        }
    }

    public function test_invalid_school_structure_counts_abort_before_mutation(): void
    {
        DB::table('gurus')->insert([
            'id' => 19,
            'nuptk' => '0000000000000019',
            'nama' => 'Guru Tambahan',
            'username' => 'guru_tambahan',
            'email' => null,
            'email_verified_at' => null,
            'password' => 'hashed-extra-password',
            'password_plain' => null,
            'must_change_password' => true,
            'photo' => null,
            'signature_path' => null,
            'deleted_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $before = $this->allTableCounts();
        $this->bindIdentity(self::BASELINE_B_DATABASE, self::BASELINE_B_DATABASE);

        $this->assertSame(1, $this->apply('school-structure'));
        $this->assertStringContainsString('tepat 18 Guru', Artisan::output());
        $this->assertSame($before, $this->allTableCounts());
    }

    public function test_missing_wali_assignment_aborts_before_mutation(): void
    {
        DB::table('guru_kelas')->where('kelas_id', 12)->where('role', 'wali_kelas')->delete();
        $before = $this->allTableCounts();
        $this->bindIdentity(self::BASELINE_B_DATABASE, self::BASELINE_B_DATABASE);

        $this->assertSame(1, $this->apply('school-structure'));
        $this->assertStringContainsString('tepat satu Wali Kelas', Artisan::output());
        $this->assertSame($before, $this->allTableCounts());
    }

    public function test_invalid_target_class_count_aborts_before_mutation(): void
    {
        DB::table('kelas')->insert([
            'id' => 14,
            'nomor_kelas' => 7,
            'nama_kelas' => 'A',
            'tahun_ajaran_id' => 1,
            'deleted_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $before = $this->allTableRows();
        $this->bindIdentity(self::BASELINE_B_DATABASE, self::BASELINE_B_DATABASE);

        $this->assertSame(1, $this->apply('school-structure'));
        $this->assertStringContainsString('tepat 12 Kelas', Artisan::output());
        $this->assertSame($before, $this->allTableRows());
    }

    public function test_wali_with_subject_only_pengajar_assignment_retains_both_roles_without_new_pivot(): void
    {
        DB::table('mata_pelajarans')->where('id', 1)->update(['guru_id' => 1, 'kelas_id' => 1]);
        $beforePivotIds = DB::table('guru_kelas')
            ->whereIn('kelas_id', range(1, 12))
            ->orderBy('id')
            ->pluck('id')
            ->all();
        $beforeRoles = $this->availableRoles(1);
        $this->bindIdentity(self::BASELINE_B_DATABASE, self::BASELINE_B_DATABASE);

        $this->assertSame(['pengajar', 'wali_kelas'], $beforeRoles);
        $this->assertSame(0, $this->apply('school-structure'));
        $this->assertSame($beforeRoles, $this->availableRoles(1));
        $this->assertSame($beforePivotIds, DB::table('guru_kelas')->orderBy('id')->pluck('id')->all());
        $this->assertSame(0, DB::table('guru_kelas')->where('guru_id', 1)->where('role', 'pengajar')->count());
    }

    public function test_non_wali_subject_only_pengajar_retains_role_without_synthetic_pivot(): void
    {
        DB::table('guru_kelas')->where('guru_id', 13)->where('role', 'pengajar')->delete();
        $beforePivotIds = DB::table('guru_kelas')
            ->whereIn('kelas_id', range(1, 12))
            ->orderBy('id')
            ->pluck('id')
            ->all();
        $beforeRoles = $this->availableRoles(13);
        $this->bindIdentity(self::BASELINE_B_DATABASE, self::BASELINE_B_DATABASE);

        $this->assertSame(['pengajar'], $beforeRoles);
        $this->assertSame(0, $this->apply('school-structure'));
        $this->assertSame($beforeRoles, $this->availableRoles(13));
        $this->assertSame($beforePivotIds, DB::table('guru_kelas')->orderBy('id')->pluck('id')->all());
        $this->assertSame(0, DB::table('guru_kelas')->where('guru_id', 13)->count());
    }

    public function test_existing_pengajar_pivot_retains_role_without_subject(): void
    {
        DB::table('mata_pelajarans')->where('id', 1)->update(['guru_id' => 1, 'kelas_id' => 1]);
        $beforeRoles = $this->availableRoles(13);
        $this->bindIdentity(self::BASELINE_B_DATABASE, self::BASELINE_B_DATABASE);

        $this->assertSame(['pengajar'], $beforeRoles);
        $this->assertSame(0, $this->apply('school-structure'));
        $this->assertSame($beforeRoles, $this->availableRoles(13));
        $this->assertSame(1, DB::table('mata_pelajarans')->count());
    }

    public function test_soft_deleted_subject_aborts_before_mutation(): void
    {
        DB::table('mata_pelajarans')->where('id', 1)->update(['deleted_at' => now()]);

        $this->assertSchoolStructureApplyBlocked('Mata Pelajaran soft-deleted');
    }

    public function test_subject_outside_target_year_aborts_before_mutation(): void
    {
        DB::table('mata_pelajarans')->where('id', 1)->update(['tahun_ajaran_id' => 2]);

        $this->assertSchoolStructureApplyBlocked('di luar target Tahun Ajaran');
    }

    public function test_subject_outside_target_semester_aborts_before_mutation(): void
    {
        DB::table('mata_pelajarans')->where('id', 1)->update(['semester' => 2]);

        $this->assertSchoolStructureApplyBlocked('di luar target semester');
    }

    public function test_subject_with_non_retained_guru_aborts_before_mutation(): void
    {
        DB::table('mata_pelajarans')->where('id', 1)->update(['guru_id' => null]);

        $this->assertSchoolStructureApplyBlocked('parent Guru/Kelas valid');
    }

    public function test_subject_with_non_retained_class_aborts_before_mutation(): void
    {
        DB::table('mata_pelajarans')->where('id', 1)->update(['kelas_id' => 13]);

        $this->assertSchoolStructureApplyBlocked('Kelas di luar struktur');
    }

    public function test_subject_set_drift_between_plan_and_apply_is_rejected(): void
    {
        $this->bindIdentity(self::BASELINE_B_DATABASE, self::BASELINE_B_DATABASE);
        $service = app(BaselinePreparationService::class);
        $plan = $service->inspect('school-structure');
        $this->insertSubject(2, 14, 2);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Data berubah setelah dry-run/preflight');

        $service->apply('school-structure', $plan);
    }

    public function test_unknown_settings_block_apply_without_deleting_the_unknown_row(): void
    {
        DB::table('settings')->insert([
            'key' => 'future_unknown_setting',
            'value' => 'keep-for-review',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $before = $this->allTableCounts();

        $this->assertSame(1, $this->apply('minimal'));
        $this->assertStringContainsString('setting tidak dikenal', Artisan::output());
        $this->assertSame($before, $this->allTableCounts());
        $this->assertDatabaseHas('settings', ['key' => 'future_unknown_setting', 'value' => 'keep-for-review']);
    }

    public function test_postcondition_failure_rolls_back_every_database_change(): void
    {
        DB::table('settings')->insert([
            'key' => 'active_wali_report_period',
            'value' => 'UAS',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $before = $this->allTableCounts();
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER corrupt_baseline_period
            AFTER UPDATE OF value ON settings
            WHEN NEW.key = 'active_wali_report_period'
            BEGIN
                UPDATE settings SET value = 'BROKEN' WHERE key = 'active_wali_report_period';
            END
        SQL);

        $this->assertSame(1, $this->apply('minimal'));
        $this->assertStringContainsString('Seluruh perubahan database dalam transaksi telah dibatalkan', Artisan::output());
        $this->assertSame($before, $this->allTableCounts());
        $this->assertSame('UAS', DB::table('settings')->where('key', 'active_wali_report_period')->value('value'));
        $this->assertSame(18, DB::table('gurus')->count());
    }

    public function test_cleanup_uses_query_builder_and_does_not_invoke_model_delete_observers(): void
    {
        $deletingEvents = 0;
        $model = new class extends Model
        {
            protected $table = 'gurus';

            public $timestamps = false;
        };
        $model::deleting(function () use (&$deletingEvents): void {
            $deletingEvents++;
        });

        $this->assertSame(0, $this->apply('minimal'));
        $this->assertSame(0, $deletingEvents);
    }

    public function test_filesystem_is_not_modified_by_apply(): void
    {
        Storage::disk('public')->put('sentinel.txt', 'public sentinel');
        Storage::disk('local')->put('private/sentinel.txt', 'private sentinel');

        $this->assertSame(0, $this->apply('minimal'));

        Storage::disk('public')->assertExists('school/logo.png');
        Storage::disk('public')->assertExists('teachers/guru-1.jpg');
        Storage::disk('local')->assertExists('private/guru-signatures/guru-1.png');
        $this->assertSame('public sentinel', Storage::disk('public')->get('sentinel.txt'));
        $this->assertSame('private sentinel', Storage::disk('local')->get('private/sentinel.txt'));
    }

    public function test_required_file_references_are_reported_by_count_without_private_values(): void
    {
        $this->bindIdentity(self::BASELINE_B_DATABASE, self::BASELINE_B_DATABASE);

        $this->assertSame(0, Artisan::call('initial-data:prepare-baseline', ['mode' => 'school-structure']));
        $output = Artisan::output();

        $this->assertStringContainsString('Profile logo references: 1', $output);
        $this->assertStringContainsString('Guru photo references: 1', $output);
        $this->assertStringContainsString('Guru signature references: 1', $output);
        $this->assertStringNotContainsString('teachers/guru-1.jpg', $output);
        $this->assertStringNotContainsString('private/guru-signatures/guru-1.png', $output);
        $this->assertStringNotContainsString('guru1@example.test', $output);
    }

    public function test_missing_required_file_reference_blocks_without_normalizing_database_value(): void
    {
        Storage::disk('public')->delete('school/logo.png');
        $beforeLogo = DB::table('profil_sekolah')->value('logo');

        $this->assertSame(1, $this->apply('minimal'));
        $this->assertStringContainsString('Referensi file wajib tidak lengkap', Artisan::output());
        $this->assertSame($beforeLogo, DB::table('profil_sekolah')->value('logo'));
    }

    public function test_missing_retained_guru_file_blocks_school_structure_mode(): void
    {
        Storage::disk('local')->delete('private/guru-signatures/guru-1.png');
        $before = $this->allTableCounts();
        $this->bindIdentity(self::BASELINE_B_DATABASE, self::BASELINE_B_DATABASE);

        $this->assertSame(1, $this->apply('school-structure'));
        $this->assertStringContainsString('tanda_tangan=1', Artisan::output());
        $this->assertSame($before, $this->allTableCounts());
        $this->assertSame('private/guru-signatures/guru-1.png', DB::table('gurus')->where('id', 1)->value('signature_path'));
    }

    private function createSchema(): void
    {
        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('username')->unique();
            $table->string('email')->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('pending_email')->nullable();
            $table->char('pending_email_token_hash', 64)->nullable();
            $table->timestamp('pending_email_expires_at')->nullable();
            $table->string('password');
            $table->rememberToken();
            $table->tinyInteger('admin_singleton')->virtualAs('1');
            $table->unique('admin_singleton', 'users_admin_singleton_unique');
            $table->timestamps();
        });

        Schema::create('migrations', function (Blueprint $table): void {
            $table->id();
            $table->string('migration')->unique();
            $table->integer('batch');
        });

        Schema::create('profil_sekolah', function (Blueprint $table): void {
            $table->id();
            $table->string('logo')->nullable();
            $table->string('nama_sekolah');
            $table->string('tahun_pelajaran')->nullable();
            $table->integer('semester')->nullable();
            $table->integer('guru_kelas')->default(0);
            $table->integer('kelas')->default(0);
            $table->integer('jumlah_siswa')->default(0);
            $table->timestamps();
        });

        Schema::create('settings', function (Blueprint $table): void {
            $table->id();
            $table->string('key')->unique();
            $table->text('value')->nullable();
            $table->timestamps();
        });

        Schema::create('report_placeholders', function (Blueprint $table): void {
            $table->id();
            $table->string('placeholder_key');
            $table->string('description');
            $table->string('category');
            $table->string('sample_value')->nullable();
            $table->boolean('is_required')->default(false);
            $table->timestamps();
        });

        Schema::create('tahun_ajarans', function (Blueprint $table): void {
            $table->id();
            $table->string('tahun_ajaran');
            $table->integer('semester');
            $table->boolean('is_active');
            $table->date('tanggal_mulai')->nullable();
            $table->date('tanggal_selesai')->nullable();
            $table->softDeletes();
            $table->timestamps();
        });

        Schema::create('gurus', function (Blueprint $table): void {
            $table->id();
            $table->string('nuptk')->nullable();
            $table->string('nama');
            $table->string('username')->unique();
            $table->string('email')->nullable()->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');
            $table->string('password_plain')->nullable();
            $table->boolean('must_change_password')->default(false);
            $table->string('photo')->nullable();
            $table->string('signature_path')->nullable();
            $table->softDeletes();
            $table->timestamps();
        });

        Schema::create('kelas', function (Blueprint $table): void {
            $table->id();
            $table->integer('nomor_kelas');
            $table->string('nama_kelas');
            $table->foreignId('tahun_ajaran_id')->nullable()->constrained('tahun_ajarans')->restrictOnDelete();
            $table->softDeletes();
            $table->timestamps();
        });

        Schema::create('guru_kelas', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('guru_id')->constrained('gurus')->restrictOnDelete();
            $table->foreignId('kelas_id')->constrained('kelas')->restrictOnDelete();
            $table->boolean('is_wali_kelas');
            $table->string('role');
            $table->timestamps();
            $table->unique(['guru_id', 'kelas_id', 'role']);
        });

        foreach (self::DATA_ORDER as $table) {
            $this->createGenericTable($table);
        }
    }

    private function createGenericTable(string $tableName): void
    {
        Schema::create($tableName, function (Blueprint $table) use ($tableName): void {
            $table->id();
            $table->string('marker')->nullable();

            foreach (self::RELATIONS[$tableName] ?? [] as $column => $parentTable) {
                $table->foreignId($column)->nullable()->constrained($parentTable)->restrictOnDelete();
            }

            if ($tableName === 'siswas') {
                $table->softDeletes();
            }
            if ($tableName === 'mata_pelajarans') {
                $table->unsignedTinyInteger('semester')->nullable();
                $table->softDeletes();
            }
        });
    }

    private function seedValidCandidate(): void
    {
        $migrationRows = collect(app(Migrator::class)->getMigrationFiles(database_path('migrations')))
            ->keys()
            ->sort()
            ->values()
            ->map(fn (string $migration, int $index): array => [
                'migration' => $migration,
                'batch' => $index + 1,
            ])
            ->all();
        DB::table('migrations')->insert($migrationRows);

        DB::table('users')->insert([
            'id' => 1,
            'name' => 'Admin Baseline',
            'username' => 'admin_baseline',
            'email' => 'admin@example.test',
            'email_verified_at' => '2026-08-01 00:00:00',
            'pending_email' => 'pending@example.test',
            'pending_email_token_hash' => str_repeat('a', 64),
            'pending_email_expires_at' => '2026-09-01 00:00:00',
            'password' => 'hashed-admin-password',
            'remember_token' => 'admin-remember-token',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('profil_sekolah')->insert([
            'id' => 1,
            'logo' => 'school/logo.png',
            'nama_sekolah' => 'SDIT Al-Hidayah',
            'tahun_pelajaran' => '2025/2026',
            'semester' => 2,
            'guru_kelas' => 99,
            'kelas' => 99,
            'jumlah_siswa' => 999,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        Storage::disk('public')->put('school/logo.png', 'logo');

        DB::table('settings')->insert([
            'key' => 'kkm_notification_complete_scores_only',
            'value' => '1',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('report_placeholders')->insert([
            'id' => 1,
            'placeholder_key' => 'nama_siswa',
            'description' => 'Nama siswa',
            'category' => 'siswa',
            'sample_value' => 'Contoh',
            'is_required' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('tahun_ajarans')->insert([
            [
                'id' => 1,
                'tahun_ajaran' => '2026/2027',
                'semester' => 1,
                'is_active' => true,
                'tanggal_mulai' => '2026-07-01',
                'tanggal_selesai' => '2026-12-31',
                'deleted_at' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'id' => 2,
                'tahun_ajaran' => '2025/2026',
                'semester' => 2,
                'is_active' => false,
                'tanggal_mulai' => '2026-01-01',
                'tanggal_selesai' => '2026-06-30',
                'deleted_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        for ($id = 1; $id <= 18; $id++) {
            DB::table('gurus')->insert([
                'id' => $id,
                'nuptk' => str_pad((string) $id, 16, '0', STR_PAD_LEFT),
                'nama' => "Guru {$id}",
                'username' => "guru_{$id}",
                'email' => "guru{$id}@example.test",
                'email_verified_at' => $id % 2 === 0 ? '2026-08-01 00:00:00' : null,
                'password' => "guru-hash-{$id}",
                'password_plain' => $id === 1 ? 'legacy-must-clear' : null,
                'must_change_password' => $id % 3 === 0,
                'photo' => $id === 1 ? 'teachers/guru-1.jpg' : null,
                'signature_path' => $id === 1 ? 'private/guru-signatures/guru-1.png' : null,
                'deleted_at' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
        Storage::disk('public')->put('teachers/guru-1.jpg', 'photo');
        Storage::disk('local')->put('private/guru-signatures/guru-1.png', 'signature');

        for ($id = 1; $id <= 12; $id++) {
            DB::table('kelas')->insert([
                'id' => $id,
                'nomor_kelas' => (int) ceil($id / 2),
                'nama_kelas' => $id % 2 === 0 ? 'B' : 'A',
                'tahun_ajaran_id' => 1,
                'deleted_at' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            DB::table('guru_kelas')->insert([
                'id' => $id,
                'guru_id' => $id,
                'kelas_id' => $id,
                'is_wali_kelas' => true,
                'role' => 'wali_kelas',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        DB::table('kelas')->insert([
            'id' => 13,
            'nomor_kelas' => 6,
            'nama_kelas' => 'Arsip',
            'tahun_ajaran_id' => 2,
            'deleted_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        for ($guruId = 13; $guruId <= 18; $guruId++) {
            DB::table('guru_kelas')->insert([
                'id' => $guruId,
                'guru_id' => $guruId,
                'kelas_id' => $guruId - 12,
                'is_wali_kelas' => false,
                'role' => 'pengajar',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
        DB::table('guru_kelas')->insert([
            'id' => 19,
            'guru_id' => 1,
            'kelas_id' => 13,
            'is_wali_kelas' => false,
            'role' => 'pengajar',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        foreach (self::DATA_ORDER as $table) {
            $row = ['id' => 1, 'marker' => "{$table}-row"];
            foreach (self::RELATIONS[$table] ?? [] as $column => $parent) {
                $row[$column] = 1;
            }
            if ($table === 'siswas') {
                $row['deleted_at'] = now();
            }
            if ($table === 'mata_pelajarans') {
                $row['guru_id'] = 13;
                $row['semester'] = 1;
                $row['deleted_at'] = null;
            }
            DB::table($table)->insert($row);
        }
    }

    private function apply(string $mode): int
    {
        $database = $mode === 'minimal' ? self::BASELINE_A_DATABASE : self::BASELINE_B_DATABASE;

        return Artisan::call('initial-data:prepare-baseline', [
            'mode' => $mode,
            '--apply' => true,
            '--confirm' => "PREPARE BASELINE {$mode} ON {$database}",
        ]);
    }

    private function bindIdentity(string $configured, string $physical): void
    {
        $this->app->instance(BaselineDatabaseIdentity::class, new class($configured, $physical) extends BaselineDatabaseIdentity
        {
            public function __construct(
                private readonly string $configured,
                private readonly string $physical
            ) {
            }

            public function inspect(): array
            {
                return [
                    'configured' => $this->configured,
                    'physical' => $this->physical,
                ];
            }
        });
    }

    private function assertCoreBaseline(int $gurus, int $classes, int $pivots, int $subjects): void
    {
        $this->assertSame(1, DB::table('users')->count());
        $this->assertSame(1, DB::table('profil_sekolah')->count());
        $this->assertSame(1, DB::table('tahun_ajarans')->count());
        $this->assertSame($gurus, DB::table('gurus')->count());
        $this->assertSame($classes, DB::table('kelas')->count());
        $this->assertSame($pivots, DB::table('guru_kelas')->count());
        $this->assertSame($subjects, DB::table('mata_pelajarans')->count());

        foreach ([...BaselinePreparationService::CLEAR_TABLES, ...BaselinePreparationService::VOLATILE_TABLES] as $table) {
            $this->assertSame(0, DB::table($table)->count(), "{$table} should be empty");
        }

        $this->assertSame(1, DB::table('report_placeholders')->count());
        $this->assertSame(count(File::files(database_path('migrations'))), DB::table('migrations')->count());
        $this->assertTrue(app(BaselineSchemaInspector::class)->hasAdminSingletonInvariant());
    }

    private function assertSchoolStructureApplyBlocked(string $message): void
    {
        $before = $this->allTableRows();
        $this->bindIdentity(self::BASELINE_B_DATABASE, self::BASELINE_B_DATABASE);

        $this->assertSame(1, $this->apply('school-structure'));
        $this->assertStringContainsString($message, Artisan::output());
        $this->assertSame($before, $this->allTableRows());
    }

    /**
     * @return array<int, string>
     */
    private function availableRoles(int $guruId): array
    {
        $guru = Guru::query()->findOrFail($guruId);

        return (new GuruRoleAvailability)->availableRoles($guru, 1, 1);
    }

    private function insertSubject(int $id, int $guruId, int $classId): void
    {
        DB::table('mata_pelajarans')->insert([
            'id' => $id,
            'marker' => "mata-pelajaran-{$id}",
            'kelas_id' => $classId,
            'guru_id' => $guruId,
            'tahun_ajaran_id' => 1,
            'semester' => 1,
            'deleted_at' => null,
        ]);
    }

    /**
     * @return array<string, int>
     */
    private function allTableCounts(): array
    {
        $counts = [];
        foreach (BaselineSchemaInspector::EXPECTED_TABLES as $table) {
            $counts[$table] = DB::table($table)->count();
        }

        return $counts;
    }

    /**
     * @return array<string, array<int, array<string, mixed>>>
     */
    private function allTableRows(): array
    {
        $state = [];

        foreach (BaselineSchemaInspector::EXPECTED_TABLES as $table) {
            $state[$table] = DB::table($table)
                ->get()
                ->map(fn (object $row): array => collect((array) $row)->sortKeys()->all())
                ->sortBy(fn (array $row): string => json_encode($row, JSON_THROW_ON_ERROR))
                ->values()
                ->all();
        }

        return $state;
    }
}

<?php

namespace Tests\Feature;

use App\Jobs\AutoPreparePdfReportJob;
use App\Models\Guru;
use App\Models\Nilai;
use App\Models\ProfilSekolah;
use App\Models\Siswa;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class TestingToolsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
        ]);

        DB::purge('sqlite');
        DB::reconnect('sqlite');
        Cache::flush();

        $this->createSchema();
    }

    public function test_route_is_hidden_when_staging_testing_flag_is_disabled(): void
    {
        $this->basicSetup();
        config(['staging_test_tools.enabled' => false]);

        $this->actingAs(User::factory()->create())
            ->get(route('admin.testing.multi-user.index'))
            ->assertNotFound();
    }

    public function test_route_is_accessible_to_admin_when_enabled(): void
    {
        $this->basicSetup();
        config(['staging_test_tools.enabled' => true]);

        $this->actingAs(User::factory()->create())
            ->get(route('admin.testing.multi-user.index'))
            ->assertOk()
            ->assertSee('Simulasi Multi-User Guru')
            ->assertSee('Gunakan hanya di staging. Jangan jalankan saat guru sedang testing nyata.');
    }

    public function test_guest_and_teacher_cannot_access_the_admin_testing_tool(): void
    {
        $this->basicSetup();
        config(['staging_test_tools.enabled' => true]);

        $this->get(route('admin.testing.multi-user.index'))
            ->assertRedirect(route('login'));

        $guru = Guru::find($this->createGuru());

        $this->actingAs($guru, 'guru')
            ->get(route('admin.testing.multi-user.index'))
            ->assertRedirect(route('login'));
    }

    public function test_pdf_simulation_validates_the_max_request_count(): void
    {
        $context = $this->dummyContext();
        config(['staging_test_tools.enabled' => true]);

        $this->actingAs(User::factory()->create())
            ->postJson(route('admin.testing.multi-user.pdf'), [
                'action' => 'preview',
                'report_type' => 'UTS',
                'tahun_ajaran_id' => $context['tahun_ajaran_id'],
                'kelas_id' => $context['kelas_id'],
                'student_id' => $context['siswa_id'],
                'request_count' => 21,
                'request_index' => 1,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('request_count');
    }

    public function test_score_simulation_requires_explicit_confirmation(): void
    {
        $context = $this->dummyContext();
        config(['staging_test_tools.enabled' => true]);

        $this->actingAs(User::factory()->create())
            ->postJson(route('admin.testing.multi-user.score'), [
                'tahun_ajaran_id' => $context['tahun_ajaran_id'],
                'kelas_id' => $context['kelas_id'],
                'mata_pelajaran_id' => $context['mata_pelajaran_id'],
                'student_id' => $context['siswa_id'],
                'request_count' => 1,
                'request_index' => 1,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('confirmation');
    }

    public function test_score_simulation_refuses_non_dummy_data(): void
    {
        $context = $this->realLookingContext();
        config(['staging_test_tools.enabled' => true]);

        $this->actingAs(User::factory()->create())
            ->postJson(route('admin.testing.multi-user.score'), [
                'confirmation' => config('staging_test_tools.score_confirmation'),
                'tahun_ajaran_id' => $context['tahun_ajaran_id'],
                'kelas_id' => $context['kelas_id'],
                'mata_pelajaran_id' => $context['mata_pelajaran_id'],
                'student_id' => $context['siswa_id'],
                'request_count' => 1,
                'request_index' => 1,
            ])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Simulasi hanya boleh memakai kelas dan siswa dummy/test/simulasi.');
    }

    public function test_repeated_dummy_score_simulation_updates_without_duplicate_rows(): void
    {
        $context = $this->dummyContext();
        config(['staging_test_tools.enabled' => true]);

        $admin = User::factory()->create();
        $payload = [
            'confirmation' => config('staging_test_tools.score_confirmation'),
            'tahun_ajaran_id' => $context['tahun_ajaran_id'],
            'kelas_id' => $context['kelas_id'],
            'mata_pelajaran_id' => $context['mata_pelajaran_id'],
            'student_id' => $context['siswa_id'],
            'request_count' => 2,
        ];

        $this->actingAs($admin)
            ->postJson(route('admin.testing.multi-user.score'), $payload + ['request_index' => 1])
            ->assertOk()
            ->assertJsonPath('status', 'saved');

        $this->actingAs($admin)
            ->postJson(route('admin.testing.multi-user.score'), $payload + ['request_index' => 2])
            ->assertOk()
            ->assertJsonPath('status', 'saved');

        $this->assertSame(1, Nilai::query()
            ->where('siswa_id', $context['siswa_id'])
            ->where('mata_pelajaran_id', $context['mata_pelajaran_id'])
            ->where('tahun_ajaran_id', $context['tahun_ajaran_id'])
            ->whereNull('lingkup_materi_id')
            ->whereNull('tujuan_pembelajaran_id')
            ->count());
    }

    public function test_testing_tool_is_not_exposed_when_production_default_is_disabled(): void
    {
        $this->basicSetup();
        config([
            'app.env' => 'production',
            'staging_test_tools.enabled' => false,
        ]);

        $this->actingAs(User::factory()->create())
            ->get(route('admin.testing.multi-user.index'))
            ->assertNotFound();
    }

    public function test_simulation_data_command_is_blocked_when_not_allowed(): void
    {
        config([
            'app.env' => 'production',
            'staging_test_tools.enabled' => false,
        ]);

        $this->artisan('staging:create-simulation-data')
            ->expectsOutput('Command ini hanya boleh dijalankan di local, testing, staging, atau saat STAGING_TEST_TOOLS_ENABLED=true.')
            ->assertFailed();
    }

    public function test_simulation_data_command_creates_complete_dummy_context(): void
    {
        $tahunAjaranId = $this->basicSetup();
        config([
            'app.env' => 'staging',
            'staging_test_tools.enabled' => true,
        ]);

        $this->artisan('staging:create-simulation-data')
            ->assertSuccessful();

        $kelas = DB::table('kelas')
            ->where('nama_kelas', 'Kelas Simulasi Load Test')
            ->where('tahun_ajaran_id', $tahunAjaranId)
            ->first();

        $this->assertNotNull($kelas);

        $guru = DB::table('gurus')->where('username', 'dummy_simulasi_load')->first();
        $this->assertNotNull($guru);
        $this->assertTrue(Hash::check('Simulasi123!', $guru->password));

        $subject = DB::table('mata_pelajarans')
            ->where('nama_pelajaran', 'Mapel Dummy Simulasi Load Test')
            ->where('kelas_id', $kelas->id)
            ->where('guru_id', $guru->id)
            ->where('tahun_ajaran_id', $tahunAjaranId)
            ->where('semester', 1)
            ->first();

        $this->assertNotNull($subject);

        $studentIds = DB::table('siswas')
            ->where('nama', 'like', 'Siswa Dummy Simulasi Load Test%')
            ->pluck('id');

        $this->assertCount(20, $studentIds);
        $this->assertSame(20, DB::table('siswa_kelas_semester')
            ->where('kelas_id', $kelas->id)
            ->where('tahun_ajaran_id', $tahunAjaranId)
            ->where('semester', 1)
            ->whereIn('siswa_id', $studentIds)
            ->count());

        $lmIds = DB::table('lingkup_materis')
            ->where('mata_pelajaran_id', $subject->id)
            ->pluck('id');

        $this->assertCount(2, $lmIds);
        $this->assertSame(6, DB::table('tujuan_pembelajarans')
            ->whereIn('lingkup_materi_id', $lmIds)
            ->count());

        $this->assertDatabaseHas('kkms', [
            'mata_pelajaran_id' => $subject->id,
            'kelas_id' => $kelas->id,
            'tahun_ajaran_id' => $tahunAjaranId,
            'nilai' => 70,
        ]);

        $this->assertDatabaseHas('bobot_nilais', [
            'tahun_ajaran_id' => $tahunAjaranId,
            'bobot_tp' => 1,
            'bobot_lm' => 1,
            'bobot_as' => 2,
        ]);

        $this->assertDatabaseHas('guru_kelas', [
            'guru_id' => $guru->id,
            'kelas_id' => $kelas->id,
            'role' => 'pengajar',
            'is_wali_kelas' => false,
        ]);

        $this->assertDatabaseHas('guru_kelas', [
            'guru_id' => $guru->id,
            'kelas_id' => $kelas->id,
            'role' => 'wali_kelas',
            'is_wali_kelas' => true,
        ]);
    }

    public function test_simulation_data_command_is_idempotent_and_does_not_touch_real_data(): void
    {
        $tahunAjaranId = $this->basicSetup();
        $realKelasId = $this->createClass($tahunAjaranId, 'A');
        $realGuruId = $this->createGuru($realKelasId, [
            'nama' => 'Guru Real',
            'username' => 'guru_real',
            'email' => 'guru.real@example.test',
            'nuptk' => 'REALNUPTK001',
        ]);
        $realSiswaId = Siswa::query()->insertGetId([
            'nis' => 'REAL-001',
            'nisn' => 'REALN-001',
            'nama' => 'Siswa Real',
            'tanggal_lahir' => '2015-01-01',
            'jenis_kelamin' => 'Laki-laki',
            'agama' => 'Islam',
            'alamat' => 'Alamat Real',
            'kelas_id' => $realKelasId,
            'nama_ayah' => 'Ayah Real',
            'nama_ibu' => 'Ibu Real',
            'pekerjaan_ayah' => 'Wiraswasta',
            'pekerjaan_ibu' => 'Ibu Rumah Tangga',
            'alamat_orangtua' => 'Alamat Orang Tua Real',
            'tahun_ajaran_id' => $tahunAjaranId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        config([
            'app.env' => 'staging',
            'staging_test_tools.enabled' => true,
        ]);

        $this->artisan('staging:create-simulation-data')->assertSuccessful();
        $this->artisan('staging:create-simulation-data')->assertSuccessful();

        $this->assertSame(1, DB::table('kelas')
            ->where('nama_kelas', 'Kelas Simulasi Load Test')
            ->where('tahun_ajaran_id', $tahunAjaranId)
            ->count());
        $this->assertSame(1, DB::table('gurus')->where('username', 'dummy_simulasi_load')->count());
        $this->assertSame(1, DB::table('mata_pelajarans')->where('nama_pelajaran', 'Mapel Dummy Simulasi Load Test')->count());
        $this->assertSame(20, DB::table('siswas')->where('nama', 'like', 'Siswa Dummy Simulasi Load Test%')->count());

        $dummyKelas = DB::table('kelas')->where('nama_kelas', 'Kelas Simulasi Load Test')->first();
        $dummySubject = DB::table('mata_pelajarans')->where('nama_pelajaran', 'Mapel Dummy Simulasi Load Test')->first();
        $dummyLmIds = DB::table('lingkup_materis')->where('mata_pelajaran_id', $dummySubject->id)->pluck('id');

        $this->assertSame(2, $dummyLmIds->count());
        $this->assertSame(6, DB::table('tujuan_pembelajarans')->whereIn('lingkup_materi_id', $dummyLmIds)->count());
        $this->assertSame(20, DB::table('siswa_kelas_semester')->where('kelas_id', $dummyKelas->id)->count());

        $this->assertDatabaseHas('kelas', [
            'id' => $realKelasId,
            'nama_kelas' => 'A',
        ]);
        $this->assertDatabaseHas('gurus', [
            'id' => $realGuruId,
            'nama' => 'Guru Real',
            'username' => 'guru_real',
        ]);
        $this->assertDatabaseHas('siswas', [
            'id' => $realSiswaId,
            'nama' => 'Siswa Real',
            'kelas_id' => $realKelasId,
        ]);
    }

    public function test_simulation_page_lists_command_dummy_class_subject_and_students(): void
    {
        $this->basicSetup();
        config([
            'app.env' => 'staging',
            'staging_test_tools.enabled' => true,
        ]);

        $this->artisan('staging:create-simulation-data')
            ->assertSuccessful();

        $this->actingAs(User::factory()->create())
            ->get(route('admin.testing.multi-user.index'))
            ->assertOk()
            ->assertSee('Kelas Simulasi Load Test')
            ->assertSee('Mapel Dummy Simulasi Load Test')
            ->assertSee('Siswa Dummy Simulasi Load Test 01');
    }

    public function test_multi_wali_load_commands_are_blocked_when_not_allowed(): void
    {
        config([
            'app.env' => 'production',
            'staging_test_tools.enabled' => false,
        ]);

        $this->artisan('staging:create-multi-wali-load-data')
            ->expectsOutput('Command ini hanya boleh dijalankan di local, testing, staging, atau saat STAGING_TEST_TOOLS_ENABLED=true.')
            ->assertFailed();

        $this->artisan('staging:simulate-multi-wali-dashboard-warmup')
            ->expectsOutput('Command ini hanya boleh dijalankan di local, testing, staging, atau saat STAGING_TEST_TOOLS_ENABLED=true.')
            ->assertFailed();

        $this->artisan('staging:simulate-concurrent-score-saves')
            ->expectsOutput('Command ini hanya boleh dijalankan di local, testing, staging, atau saat STAGING_TEST_TOOLS_ENABLED=true.')
            ->assertFailed();
    }

    public function test_staging_commands_are_allowed_by_config_in_production_like_environment(): void
    {
        $this->basicSetup();
        config([
            'app.env' => 'production',
            'staging_test_tools.enabled' => true,
        ]);

        $this->artisan('staging:create-simulation-data', [
            '--dry-run' => true,
        ])->assertSuccessful();

        $this->artisan('staging:create-multi-wali-load-data', [
            '--wali' => 1,
            '--students' => 1,
            '--dry-run' => true,
        ])->assertSuccessful();

        $this->artisan('staging:simulate-multi-wali-dashboard-warmup', [
            '--wali' => 1,
            '--dry-run' => true,
        ])->assertSuccessful();

        $this->artisan('staging:simulate-concurrent-score-saves', [
            '--teachers' => 1,
            '--students' => 1,
            '--subject-limit' => 1,
            '--changed-values' => 1,
            '--dry-run' => true,
        ])->assertSuccessful();
    }

    public function test_multi_wali_load_data_dry_run_does_not_write_data(): void
    {
        $this->basicSetup();
        config([
            'app.env' => 'staging',
            'staging_test_tools.enabled' => true,
        ]);

        $this->artisan('staging:create-multi-wali-load-data', [
            '--wali' => 2,
            '--students' => 3,
            '--dry-run' => true,
        ])->assertSuccessful();

        $this->assertSame(0, DB::table('kelas')->where('nama_kelas', 'like', 'Kelas Load Test%')->count());
        $this->assertSame(0, DB::table('gurus')->where('nama', 'like', 'Wali Load Test%')->count());
        $this->assertSame(0, DB::table('siswas')->where('nama', 'like', 'Siswa Load Test%')->count());
    }

    public function test_multi_wali_load_data_is_idempotent_and_creates_realistic_report_rows(): void
    {
        $tahunAjaranId = $this->basicSetup();
        $this->createActiveReportTemplate($tahunAjaranId);
        config([
            'app.env' => 'staging',
            'staging_test_tools.enabled' => true,
        ]);

        $this->artisan('staging:create-multi-wali-load-data', [
            '--wali' => 2,
            '--students' => 3,
        ])->assertSuccessful();

        $this->artisan('staging:create-multi-wali-load-data', [
            '--wali' => 2,
            '--students' => 3,
        ])->assertSuccessful();

        $classIds = DB::table('kelas')
            ->where('nama_kelas', 'like', 'Kelas Load Test%')
            ->pluck('id');
        $waliIds = DB::table('gurus')
            ->where('nama', 'like', 'Wali Load Test%')
            ->pluck('id');
        $studentIds = DB::table('siswas')
            ->where('nama', 'like', 'Siswa Load Test%')
            ->pluck('id');
        $subjectIds = DB::table('mata_pelajarans')
            ->whereIn('kelas_id', $classIds)
            ->pluck('id');

        $this->assertCount(2, $classIds);
        $this->assertCount(2, $waliIds);
        $this->assertCount(6, $studentIds);
        $this->assertCount(18, $subjectIds);

        foreach ($classIds as $classId) {
            $this->assertSame(3, DB::table('siswa_kelas_semester')
                ->where('kelas_id', $classId)
                ->where('tahun_ajaran_id', $tahunAjaranId)
                ->where('semester', 1)
                ->count());
        }

        foreach ($waliIds as $waliId) {
            $this->assertSame(1, DB::table('guru_kelas')
                ->join('kelas', 'guru_kelas.kelas_id', '=', 'kelas.id')
                ->where('guru_kelas.guru_id', $waliId)
                ->where('guru_kelas.is_wali_kelas', true)
                ->where('guru_kelas.role', 'wali_kelas')
                ->where('kelas.nama_kelas', 'like', 'Kelas Load Test%')
                ->count());
        }

        $this->assertSame(36, DB::table('lingkup_materis')->whereIn('mata_pelajaran_id', $subjectIds)->count());
        $this->assertSame(108, DB::table('tujuan_pembelajarans')
            ->join('lingkup_materis', 'tujuan_pembelajarans.lingkup_materi_id', '=', 'lingkup_materis.id')
            ->whereIn('lingkup_materis.mata_pelajaran_id', $subjectIds)
            ->count());

        $this->assertSame(486, DB::table('nilais')
            ->whereIn('siswa_id', $studentIds)
            ->whereIn('mata_pelajaran_id', $subjectIds)
            ->count());
        $this->assertSame(54, DB::table('nilais')
            ->whereIn('siswa_id', $studentIds)
            ->whereIn('mata_pelajaran_id', $subjectIds)
            ->whereNull('tujuan_pembelajaran_id')
            ->whereNull('lingkup_materi_id')
            ->whereNotNull('nilai_akhir_rapor')
            ->where('is_submitted', true)
            ->count());
        $this->assertGreaterThanOrEqual(78, (float) DB::table('nilais')->whereNotNull('nilai_tp')->min('nilai_tp'));
        $this->assertLessThanOrEqual(95, (float) DB::table('nilais')->whereNotNull('nilai_tp')->max('nilai_tp'));
        $this->assertGreaterThanOrEqual(80, (float) DB::table('nilais')->whereNotNull('nilai_tes')->min('nilai_tes'));
        $this->assertLessThanOrEqual(92, (float) DB::table('nilais')->whereNotNull('nilai_tes')->max('nilai_tes'));

        $this->assertSame(6, DB::table('absensis')->whereIn('siswa_id', $studentIds)->count());
        $this->assertSame(6, DB::table('catatan_siswa')->whereIn('siswa_id', $studentIds)->count());
        $this->assertSame(54, DB::table('catatan_mata_pelajaran')->whereIn('siswa_id', $studentIds)->count());
        $this->assertSame(54, DB::table('capaian_custom')->whereIn('siswa_id', $studentIds)->count());
    }

    public function test_multi_wali_dashboard_warmup_schedules_only_dummy_owned_classes(): void
    {
        $tahunAjaranId = $this->basicSetup();
        $this->createActiveReportTemplate($tahunAjaranId);
        config([
            'app.env' => 'staging',
            'staging_test_tools.enabled' => true,
            'report.pdf_auto_prepare.enabled' => true,
            'report.pdf_dashboard_warmup.enabled' => true,
            'report.pdf_auto_prepare.queue' => 'pdf-warm',
        ]);

        $this->artisan('staging:create-multi-wali-load-data', [
            '--wali' => 1,
            '--students' => 2,
        ])->assertSuccessful();

        $dummyWaliId = DB::table('gurus')->where('nama', 'Wali Load Test 01')->value('id');
        $realClassId = $this->createClass($tahunAjaranId, 'A');
        $realStudentId = Siswa::query()->insertGetId([
            'nis' => 'REAL-WARM-001',
            'nisn' => 'REAL-WARMN-001',
            'nama' => 'Siswa Real Warmup',
            'tanggal_lahir' => '2015-01-01',
            'jenis_kelamin' => 'Laki-laki',
            'agama' => 'Islam',
            'alamat' => 'Alamat Real',
            'kelas_id' => $realClassId,
            'nama_ayah' => 'Ayah Real',
            'nama_ibu' => 'Ibu Real',
            'pekerjaan_ayah' => 'Wiraswasta',
            'pekerjaan_ibu' => 'Ibu Rumah Tangga',
            'alamat_orangtua' => 'Alamat Orang Tua Real',
            'tahun_ajaran_id' => $tahunAjaranId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('siswa_kelas_semester')->insert([
            'siswa_id' => $realStudentId,
            'kelas_id' => $realClassId,
            'tahun_ajaran_id' => $tahunAjaranId,
            'semester' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('guru_kelas')->insert([
            'guru_id' => $dummyWaliId,
            'kelas_id' => $realClassId,
            'is_wali_kelas' => true,
            'role' => 'wali_kelas',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Queue::fake();

        $this->artisan('staging:simulate-multi-wali-dashboard-warmup', [
            '--wali' => 1,
        ])->assertSuccessful();

        Queue::assertPushed(AutoPreparePdfReportJob::class, 2);
        Queue::assertNotPushed(
            AutoPreparePdfReportJob::class,
            fn (AutoPreparePdfReportJob $job) => $job->siswaId === $realStudentId
        );
    }

    public function test_multi_wali_dashboard_warmup_cooldown_blocks_duplicates_unless_ignored(): void
    {
        $tahunAjaranId = $this->basicSetup();
        $this->createActiveReportTemplate($tahunAjaranId);
        config([
            'app.env' => 'staging',
            'staging_test_tools.enabled' => true,
            'report.pdf_auto_prepare.enabled' => true,
            'report.pdf_dashboard_warmup.enabled' => true,
            'report.pdf_dashboard_warmup.cooldown_seconds' => 900,
            'report.pdf_auto_prepare.queue' => 'pdf-warm',
        ]);

        $this->artisan('staging:create-multi-wali-load-data', [
            '--wali' => 1,
            '--students' => 2,
        ])->assertSuccessful();

        Queue::fake();

        $this->artisan('staging:simulate-multi-wali-dashboard-warmup', [
            '--wali' => 1,
        ])->assertSuccessful();
        $this->app->forgetScopedInstances();

        $this->artisan('staging:simulate-multi-wali-dashboard-warmup', [
            '--wali' => 1,
        ])->assertSuccessful();
        Queue::assertPushed(AutoPreparePdfReportJob::class, 2);
        $this->app->forgetScopedInstances();

        $this->artisan('staging:simulate-multi-wali-dashboard-warmup', [
            '--wali' => 1,
            '--ignore-cooldown' => true,
        ])->assertSuccessful();

        Queue::assertPushed(AutoPreparePdfReportJob::class, 4);
    }

    public function test_concurrent_score_save_dry_run_does_not_write_data(): void
    {
        $this->basicSetup();
        config([
            'app.env' => 'staging',
            'staging_test_tools.enabled' => true,
        ]);

        $this->artisan('staging:create-multi-wali-load-data', [
            '--wali' => 1,
            '--students' => 2,
        ])->assertSuccessful();

        $nilaiCountBefore = DB::table('nilais')->count();
        $nilaiSumBefore = (float) DB::table('nilais')->sum('nilai_tp');

        $this->artisan('staging:simulate-concurrent-score-saves', [
            '--teachers' => 1,
            '--students' => 2,
            '--subject-limit' => 1,
            '--changed-values' => 1,
            '--dry-run' => true,
        ])->assertSuccessful();

        $this->assertSame($nilaiCountBefore, DB::table('nilais')->count());
        $this->assertSame($nilaiSumBefore, (float) DB::table('nilais')->sum('nilai_tp'));
    }

    public function test_concurrent_score_save_single_teacher_updates_only_dummy_scores(): void
    {
        $tahunAjaranId = $this->basicSetup();
        config([
            'app.env' => 'staging',
            'staging_test_tools.enabled' => true,
            'report.pdf_auto_prepare.enabled' => true,
        ]);

        $this->artisan('staging:create-multi-wali-load-data', [
            '--wali' => 1,
            '--students' => 2,
        ])->assertSuccessful();

        $dummyTeacherId = (int) DB::table('gurus')->where('nama', 'Wali Load Test 01')->value('id');
        $dummySubject = DB::table('mata_pelajarans')
            ->join('kelas', 'mata_pelajarans.kelas_id', '=', 'kelas.id')
            ->where('mata_pelajarans.guru_id', $dummyTeacherId)
            ->where('mata_pelajarans.nama_pelajaran', 'like', 'Load Test%')
            ->where('kelas.nama_kelas', 'like', 'Kelas Load Test%')
            ->orderBy('mata_pelajarans.nama_pelajaran')
            ->select('mata_pelajarans.id')
            ->first();
        $dummyStudentId = (int) DB::table('siswas')
            ->where('nama', 'like', 'Siswa Load Test 01-%')
            ->orderBy('nama')
            ->value('id');
        $firstTpRow = DB::table('nilais')
            ->where('siswa_id', $dummyStudentId)
            ->where('mata_pelajaran_id', $dummySubject->id)
            ->whereNotNull('tujuan_pembelajaran_id')
            ->orderBy('tujuan_pembelajaran_id')
            ->first(['id', 'nilai_tp']);

        $realClassId = $this->createClass($tahunAjaranId, 'A');
        DB::table('guru_kelas')->insert([
            'guru_id' => $dummyTeacherId,
            'kelas_id' => $realClassId,
            'is_wali_kelas' => false,
            'role' => 'pengajar',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $realSubjectId = DB::table('mata_pelajarans')->insertGetId([
            'nama_pelajaran' => 'Matematika Real',
            'kelas_id' => $realClassId,
            'semester' => 1,
            'guru_id' => $dummyTeacherId,
            'tahun_ajaran_id' => $tahunAjaranId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $realStudentId = Siswa::query()->insertGetId([
            'nis' => 'REAL-SCORE-001',
            'nisn' => 'REAL-SCOREN-001',
            'nama' => 'Siswa Real Score',
            'tanggal_lahir' => '2015-01-01',
            'jenis_kelamin' => 'Laki-laki',
            'agama' => 'Islam',
            'alamat' => 'Alamat Real',
            'kelas_id' => $realClassId,
            'nama_ayah' => 'Ayah Real',
            'nama_ibu' => 'Ibu Real',
            'pekerjaan_ayah' => 'Wiraswasta',
            'pekerjaan_ibu' => 'Ibu Rumah Tangga',
            'alamat_orangtua' => 'Alamat Orang Tua Real',
            'tahun_ajaran_id' => $tahunAjaranId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('siswa_kelas_semester')->insert([
            'siswa_id' => $realStudentId,
            'kelas_id' => $realClassId,
            'tahun_ajaran_id' => $tahunAjaranId,
            'semester' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('nilais')->insert([
            'siswa_id' => $realStudentId,
            'mata_pelajaran_id' => $realSubjectId,
            'tahun_ajaran_id' => $tahunAjaranId,
            'nilai_akhir_rapor' => 77,
            'is_submitted' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->artisan('staging:simulate-concurrent-score-saves', [
            '--run-teacher' => true,
            '--teacher-id' => $dummyTeacherId,
            '--students' => 2,
            '--subject-limit' => 1,
            '--changed-values' => 1,
            '--ignore-pdf-warmup' => true,
        ])->assertSuccessful();

        $updatedTp = (float) DB::table('nilais')->where('id', $firstTpRow->id)->value('nilai_tp');

        $this->assertNotSame((float) $firstTpRow->nilai_tp, $updatedTp);
        $this->assertGreaterThanOrEqual(70, $updatedTp);
        $this->assertLessThanOrEqual(95, $updatedTp);
        $this->assertSame(77.0, (float) DB::table('nilais')
            ->where('siswa_id', $realStudentId)
            ->where('mata_pelajaran_id', $realSubjectId)
            ->value('nilai_akhir_rapor'));
    }

    private function createActiveReportTemplate(int $tahunAjaranId): int
    {
        return DB::table('report_templates')->insertGetId([
            'filename' => 'load-test-template.docx',
            'path' => 'templates/load-test-template.docx',
            'type' => 'UTS',
            'is_active' => true,
            'kelas_id' => null,
            'tahun_ajaran_id' => $tahunAjaranId,
            'semester' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function basicSetup(): int
    {
        ProfilSekolah::create([
            'nama_instansi' => 'Yayasan Test',
            'nama_sekolah' => 'SD Test',
            'tahun_pelajaran' => '2025/2026',
            'semester' => 1,
            'npsn' => '12345678',
            'kepala_sekolah' => 'Kepala Test',
            'alamat' => 'Jl. Test',
            'guru_kelas' => 1,
            'kode_pos' => '12345',
            'kelas' => 1,
            'telepon' => '021',
            'jumlah_siswa' => 1,
        ]);

        return DB::table('tahun_ajarans')->insertGetId([
            'tahun_ajaran' => '2025/2026',
            'is_active' => true,
            'tanggal_mulai' => '2025-07-01',
            'tanggal_selesai' => '2026-06-30',
            'semester' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function dummyContext(): array
    {
        return $this->createContext(
            className: 'Simulasi Test',
            subjectName: 'Mapel Simulasi Test',
            studentName: 'Siswa Dummy Test',
            nis: 'TEST-001',
            nisn: 'TESTN-001'
        );
    }

    private function realLookingContext(): array
    {
        return $this->createContext(
            className: 'A',
            subjectName: 'Matematika',
            studentName: 'Ahmad Fulan',
            nis: '1001',
            nisn: '2001'
        );
    }

    private function createContext(string $className, string $subjectName, string $studentName, string $nis, string $nisn): array
    {
        $tahunAjaranId = $this->basicSetup();
        $kelasId = $this->createClass($tahunAjaranId, $className);
        $guruId = $this->createGuru($kelasId);

        DB::table('guru_kelas')->insert([
            'guru_id' => $guruId,
            'kelas_id' => $kelasId,
            'is_wali_kelas' => true,
            'role' => 'wali_kelas',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('guru_kelas')->insert([
            'guru_id' => $guruId,
            'kelas_id' => $kelasId,
            'is_wali_kelas' => false,
            'role' => 'pengajar',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $mataPelajaranId = DB::table('mata_pelajarans')->insertGetId([
            'nama_pelajaran' => $subjectName,
            'kelas_id' => $kelasId,
            'semester' => 1,
            'guru_id' => $guruId,
            'tahun_ajaran_id' => $tahunAjaranId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $siswaId = Siswa::query()->insertGetId([
            'nis' => $nis,
            'nisn' => $nisn,
            'nama' => $studentName,
            'tanggal_lahir' => '2015-01-01',
            'jenis_kelamin' => 'Laki-laki',
            'agama' => 'Islam',
            'alamat' => 'Alamat Test',
            'kelas_id' => $kelasId,
            'nama_ayah' => 'Ayah Test',
            'nama_ibu' => 'Ibu Test',
            'pekerjaan_ayah' => 'Wiraswasta',
            'pekerjaan_ibu' => 'Ibu Rumah Tangga',
            'alamat_orangtua' => 'Alamat Orang Tua',
            'tahun_ajaran_id' => $tahunAjaranId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('siswa_kelas_semester')->insert([
            'siswa_id' => $siswaId,
            'kelas_id' => $kelasId,
            'tahun_ajaran_id' => $tahunAjaranId,
            'semester' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [
            'tahun_ajaran_id' => $tahunAjaranId,
            'kelas_id' => $kelasId,
            'guru_id' => $guruId,
            'mata_pelajaran_id' => $mataPelajaranId,
            'siswa_id' => $siswaId,
        ];
    }

    private function createClass(int $tahunAjaranId, string $namaKelas): int
    {
        return DB::table('kelas')->insertGetId([
            'nomor_kelas' => 5,
            'nama_kelas' => $namaKelas,
            'wali_kelas' => 'Wali Test',
            'tahun_ajaran_id' => $tahunAjaranId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function createGuru(?int $kelasId = null, array $overrides = []): int
    {
        $kelasId ??= $this->createClass($this->basicSetup(), 'Simulasi Test');

        return DB::table('gurus')->insertGetId(array_merge([
            'nuptk' => 'NUPTK'.fake()->unique()->numerify('######'),
            'nama' => 'Guru Simulasi Test',
            'jenis_kelamin' => 'Laki-laki',
            'tanggal_lahir' => '1990-01-01',
            'no_handphone' => '081234567890',
            'email' => fake()->unique()->safeEmail(),
            'alamat' => 'Alamat Guru',
            'jabatan' => 'Guru',
            'kelas_pengajar_id' => $kelasId,
            'username' => fake()->unique()->userName(),
            'password' => Hash::make('password'),
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));
    }

    private function createSchema(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');
            $table->rememberToken();
            $table->timestamps();
        });

        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->string('user_type')->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('action');
            $table->string('model_type')->nullable();
            $table->unsignedBigInteger('model_id')->nullable();
            $table->text('description')->nullable();
            $table->json('old_values')->nullable();
            $table->json('new_values')->nullable();
            $table->string('ip_address')->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamps();
        });

        Schema::create('profil_sekolah', function (Blueprint $table) {
            $table->id();
            $table->string('logo')->nullable();
            $table->string('nama_instansi');
            $table->string('nama_sekolah');
            $table->string('tahun_pelajaran');
            $table->integer('semester');
            $table->string('npsn');
            $table->string('kepala_sekolah');
            $table->text('alamat');
            $table->integer('guru_kelas');
            $table->string('kode_pos');
            $table->integer('kelas');
            $table->string('telepon');
            $table->integer('jumlah_siswa');
            $table->timestamps();
        });

        Schema::create('tahun_ajarans', function (Blueprint $table) {
            $table->id();
            $table->string('tahun_ajaran');
            $table->boolean('is_active')->default(false);
            $table->date('tanggal_mulai');
            $table->date('tanggal_selesai');
            $table->integer('semester')->default(1);
            $table->string('deskripsi')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('kelas', function (Blueprint $table) {
            $table->id();
            $table->integer('nomor_kelas')->nullable();
            $table->string('nama_kelas');
            $table->string('wali_kelas')->nullable();
            $table->foreignId('tahun_ajaran_id')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('gurus', function (Blueprint $table) {
            $table->id();
            $table->string('nuptk')->unique();
            $table->string('nama');
            $table->string('jenis_kelamin');
            $table->date('tanggal_lahir');
            $table->string('no_handphone');
            $table->string('email')->unique();
            $table->text('alamat');
            $table->string('jabatan');
            $table->unsignedBigInteger('kelas_pengajar_id')->nullable();
            $table->string('username')->unique();
            $table->string('password');
            $table->boolean('must_change_password')->default(false);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('guru_kelas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('guru_id');
            $table->foreignId('kelas_id');
            $table->boolean('is_wali_kelas')->default(false);
            $table->string('role')->default('pengajar');
            $table->timestamps();
        });

        Schema::create('mata_pelajarans', function (Blueprint $table) {
            $table->id();
            $table->string('nama_pelajaran');
            $table->foreignId('kelas_id');
            $table->foreignId('tahun_ajaran_id')->nullable();
            $table->integer('semester')->default(1);
            $table->foreignId('guru_id')->nullable();
            $table->json('lingkup_materi')->nullable();
            $table->boolean('is_muatan_lokal')->default(false);
            $table->boolean('allow_non_wali')->default(false);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('lingkup_materis', function (Blueprint $table) {
            $table->id();
            $table->foreignId('mata_pelajaran_id');
            $table->string('judul_lingkup_materi');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('tujuan_pembelajarans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lingkup_materi_id');
            $table->string('kode_tp');
            $table->text('deskripsi_tp');
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('siswas', function (Blueprint $table) {
            $table->id();
            $table->string('nis')->unique();
            $table->string('nisn')->unique();
            $table->string('nama');
            $table->date('tanggal_lahir');
            $table->string('jenis_kelamin');
            $table->string('agama');
            $table->text('alamat');
            $table->foreignId('kelas_id');
            $table->string('nama_ayah');
            $table->string('nama_ibu');
            $table->string('pekerjaan_ayah');
            $table->string('pekerjaan_ibu');
            $table->text('alamat_orangtua');
            $table->string('photo')->nullable();
            $table->foreignId('tahun_ajaran_id')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('siswa_kelas_semester', function (Blueprint $table) {
            $table->id();
            $table->foreignId('siswa_id');
            $table->foreignId('kelas_id');
            $table->foreignId('tahun_ajaran_id');
            $table->integer('semester');
            $table->timestamps();
            $table->unique(['siswa_id', 'tahun_ajaran_id', 'semester']);
        });

        Schema::create('nilais', function (Blueprint $table) {
            $table->id();
            $table->foreignId('siswa_id');
            $table->foreignId('mata_pelajaran_id');
            $table->foreignId('tujuan_pembelajaran_id')->nullable();
            $table->foreignId('lingkup_materi_id')->nullable();
            $table->foreignId('tahun_ajaran_id')->nullable();
            $table->decimal('nilai_tp', 5, 2)->nullable();
            $table->decimal('nilai_lm', 5, 2)->nullable();
            $table->decimal('na_tp', 5, 2)->nullable();
            $table->decimal('na_lm', 5, 2)->nullable();
            $table->decimal('nilai_tes', 5, 2)->nullable();
            $table->decimal('nilai_non_tes', 5, 2)->nullable();
            $table->decimal('nilai_akhir_semester', 5, 2)->nullable();
            $table->decimal('nilai_akhir_rapor', 5, 2)->nullable();
            $table->boolean('is_submitted')->default(false);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('bobot_nilais', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tahun_ajaran_id')->nullable();
            $table->integer('bobot_tp')->default(1);
            $table->integer('bobot_lm')->default(1);
            $table->integer('bobot_as')->default(2);
            $table->timestamps();
        });

        Schema::create('kkms', function (Blueprint $table) {
            $table->id();
            $table->foreignId('mata_pelajaran_id')->nullable();
            $table->foreignId('kelas_id')->nullable();
            $table->foreignId('tahun_ajaran_id')->nullable();
            $table->integer('nilai')->default(70);
            $table->timestamps();
        });

        Schema::create('absensis', function (Blueprint $table) {
            $table->id();
            $table->foreignId('siswa_id');
            $table->integer('sakit')->default(0);
            $table->integer('izin')->default(0);
            $table->integer('tanpa_keterangan')->default(0);
            $table->integer('semester')->default(1);
            $table->foreignId('tahun_ajaran_id')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('catatan_siswa', function (Blueprint $table) {
            $table->id();
            $table->foreignId('siswa_id');
            $table->text('catatan');
            $table->foreignId('tahun_ajaran_id');
            $table->integer('semester');
            $table->string('type')->default('umum');
            $table->foreignId('created_by');
            $table->timestamps();
        });

        Schema::create('catatan_mata_pelajaran', function (Blueprint $table) {
            $table->id();
            $table->foreignId('mata_pelajaran_id');
            $table->foreignId('siswa_id');
            $table->text('catatan');
            $table->foreignId('tahun_ajaran_id');
            $table->integer('semester');
            $table->string('type')->default('umum');
            $table->foreignId('created_by');
            $table->timestamps();
        });

        Schema::create('capaian_custom', function (Blueprint $table) {
            $table->id();
            $table->foreignId('siswa_id');
            $table->foreignId('mata_pelajaran_id');
            $table->text('custom_capaian')->nullable();
            $table->text('custom_capaian_tertinggi')->nullable();
            $table->text('custom_capaian_terendah')->nullable();
            $table->string('tertinggi_prefix_mode')->nullable();
            $table->text('tertinggi_prefix_text')->nullable();
            $table->string('terendah_prefix_mode')->nullable();
            $table->text('terendah_prefix_text')->nullable();
            $table->foreignId('tahun_ajaran_id');
            $table->tinyInteger('semester');
            $table->timestamps();
        });

        Schema::create('report_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('kelas_id')->nullable();
            $table->string('filename')->nullable();
            $table->string('path')->nullable();
            $table->string('type');
            $table->boolean('is_active')->default(false);
            $table->string('tahun_ajaran')->nullable();
            $table->string('tahun_ajaran_text')->nullable();
            $table->integer('semester')->nullable();
            $table->foreignId('tahun_ajaran_id')->nullable();
            $table->timestamps();
        });

        Schema::create('report_template_kelas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('report_template_id');
            $table->foreignId('kelas_id');
            $table->timestamps();
        });

        Schema::create('jobs', function (Blueprint $table) {
            $table->id();
            $table->string('queue')->index();
            $table->longText('payload');
            $table->unsignedTinyInteger('attempts');
            $table->unsignedInteger('reserved_at')->nullable();
            $table->unsignedInteger('available_at');
            $table->unsignedInteger('created_at');
        });

        Schema::create('failed_jobs', function (Blueprint $table) {
            $table->id();
            $table->string('uuid')->unique();
            $table->text('connection');
            $table->text('queue');
            $table->longText('payload');
            $table->longText('exception');
            $table->timestamp('failed_at')->useCurrent();
        });
    }
}

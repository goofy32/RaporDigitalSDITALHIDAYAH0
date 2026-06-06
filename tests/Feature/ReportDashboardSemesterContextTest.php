<?php

namespace Tests\Feature;

use App\Models\Guru;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ReportDashboardSemesterContextTest extends TestCase
{
    private Guru $wali;

    private Guru $pengajar;

    private User $admin;

    private int $ganjilYearId;

    private int $genapYearId;

    private int $ganjilClassId;

    private int $genapClassId;

    private int $otherClassId;

    private int $ganjilSubjectId;

    private int $genapSubjectId;

    private int $studentId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->withoutMiddleware(ValidateCsrfToken::class);

        config()->set('database.default', 'sqlite');
        config()->set('database.connections.sqlite.database', ':memory:');
        config()->set('cache.default', 'array');
        config()->set('session.driver', 'array');
        DB::purge('sqlite');
        DB::reconnect('sqlite');
        Cache::flush();

        $this->createSchema();
        $this->seedFixture();
    }

    public function test_wali_preview_uses_requested_semester_data_and_enrollment_class(): void
    {
        $response = $this->actingAsWali($this->ganjilYearId, 1)
            ->get(route('wali_kelas.rapor.preview', [
                'siswa' => $this->studentId,
                'tahun_ajaran_id' => $this->ganjilYearId,
            ]));

        $response->assertOk()
            ->assertJsonPath('success', true);

        $html = $response->json('html');

        $this->assertStringContainsString('Ganjil Pramuka', $html);
        $this->assertStringNotContainsString('Genap Pramuka', $html);
        $this->assertStringContainsString('<p class="font-medium">A</p>', $html);
        $this->assertStringNotContainsString('<p class="font-medium">B</p>', $html);
    }

    public function test_genap_preview_does_not_include_ganjil_supporting_data(): void
    {
        $response = $this->actingAsWali($this->genapYearId, 2)
            ->get(route('wali_kelas.rapor.preview', [
                'siswa' => $this->studentId,
                'tahun_ajaran_id' => $this->genapYearId,
            ]));

        $response->assertOk()
            ->assertJsonPath('success', true);

        $html = $response->json('html');

        $this->assertStringContainsString('Genap Pramuka', $html);
        $this->assertStringNotContainsString('Ganjil Pramuka', $html);
        $this->assertStringContainsString('<p class="text-2xl font-bold">3</p>', $html);
        $this->assertStringNotContainsString('<p class="text-2xl font-bold">7</p>', $html);
    }

    public function test_history_preview_uses_stored_report_class_and_semester_context(): void
    {
        $reportId = DB::table('report_generations')->insertGetId([
            'siswa_id' => $this->studentId,
            'kelas_id' => $this->ganjilClassId,
            'report_template_id' => null,
            'generated_file' => null,
            'type' => 'UTS',
            'tahun_ajaran' => '2026/2027',
            'semester' => 1,
            'tahun_ajaran_id' => $this->ganjilYearId,
            'generated_by' => $this->wali->id,
            'generated_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->actingAs($this->admin)
            ->get(route('admin.report.history.preview', $reportId));

        $response->assertOk()
            ->assertJsonPath('success', true);

        $html = $response->json('html');

        $this->assertStringContainsString('<p class="font-medium">A</p>', $html);
        $this->assertStringNotContainsString('<p class="font-medium">B</p>', $html);
        $this->assertStringContainsString('Ganjil Pramuka', $html);
        $this->assertStringNotContainsString('Genap Pramuka', $html);
    }

    public function test_pengajar_genap_dashboard_progress_starts_zero_when_only_ganjil_grades_exist(): void
    {
        $this->actingAsPengajar($this->genapYearId, 2)
            ->get(route('pengajar.dashboard'))
            ->assertOk()
            ->assertViewHas('overallProgress', fn ($progress) => (float) $progress === 0.0)
            ->assertViewHas('siswaCount', 1)
            ->assertViewHas('mapelCount', 1);
    }

    public function test_pengajar_ganjil_overall_progress_is_non_zero_and_complete_for_completed_subject(): void
    {
        $this->actingAsPengajar($this->ganjilYearId, 1)
            ->get(route('pengajar.dashboard'))
            ->assertOk()
            ->assertViewHas('overallProgress', function ($progress) {
                return (float) $progress > 0.0 && (float) $progress === 100.0;
            })
            ->assertViewHas('siswaCount', 1)
            ->assertViewHas('mapelCount', 1);
    }

    public function test_another_teachers_subject_does_not_affect_pengajar_overall_progress(): void
    {
        $otherGuruId = DB::table('gurus')->insertGetId([
            'nama' => 'Guru Lain',
            'username' => 'guru-lain',
            'password' => Hash::make('secret'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $otherSubjectId = $this->insertSubject('IPA Guru Lain', $otherGuruId, $this->ganjilClassId, $this->ganjilYearId, 1);
        $this->insertLearningData($otherSubjectId);

        $this->actingAsPengajar($this->ganjilYearId, 1)
            ->get(route('pengajar.dashboard'))
            ->assertOk()
            ->assertViewHas('overallProgress', fn ($progress) => (float) $progress === 100.0)
            ->assertViewHas('mapelCount', 1);
    }

    public function test_pengajar_per_subject_progress_matches_completed_subject(): void
    {
        $this->actingAsPengajar($this->ganjilYearId, 1)
            ->get(route('pengajar.mata_pelajaran.progress', $this->ganjilSubjectId))
            ->assertOk()
            ->assertJsonPath('progress', 100)
            ->assertJsonPath('completed', 1)
            ->assertJsonPath('total', 1);
    }

    public function test_wali_genap_dashboard_supporting_progress_starts_zero_when_only_ganjil_data_exists(): void
    {
        DB::table('absensis')->where('tahun_ajaran_id', $this->genapYearId)->delete();
        DB::table('nilai_ekstrakurikuler')->where('tahun_ajaran_id', $this->genapYearId)->delete();
        DB::table('nilais')->where('tahun_ajaran_id', $this->genapYearId)->delete();

        $this->actingAsWali($this->genapYearId, 2)
            ->get(route('wali_kelas.dashboard'))
            ->assertOk()
            ->assertViewHas('totalSiswa', 1)
            ->assertViewHas('totalAbsensi', 0)
            ->assertViewHas('totalEkskul', 0)
            ->assertViewHas('overallProgress', fn ($progress) => (float) $progress === 0.0);
    }

    public function test_admin_genap_dashboard_progress_does_not_count_ganjil_grades(): void
    {
        $this->actingAs($this->admin)
            ->withSession($this->sessionFor($this->genapYearId, 2, 'admin'))
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->assertViewHas('totalStudents', 1)
            ->assertViewHas('totalSubjects', 1)
            ->assertViewHas('overallProgress', fn ($progress) => (float) $progress === 0.0);
    }

    private function actingAsWali(int $tahunAjaranId, int $semester): self
    {
        return $this->actingAs($this->wali, 'guru')
            ->withSession($this->sessionFor($tahunAjaranId, $semester, 'wali_kelas'));
    }

    private function actingAsPengajar(int $tahunAjaranId, int $semester): self
    {
        return $this->actingAs($this->pengajar, 'guru')
            ->withSession($this->sessionFor($tahunAjaranId, $semester, 'pengajar'));
    }

    private function sessionFor(int $tahunAjaranId, int $semester, string $role): array
    {
        return [
            'selected_role' => $role,
            'tahun_ajaran_id' => $tahunAjaranId,
            'selected_semester' => $semester,
            'no_tahun_ajaran' => false,
        ];
    }

    private function createSchema(): void
    {
        foreach ([
            'notification_reads',
            'notifications',
            'report_generations',
            'report_template_kelas',
            'report_templates',
            'nilai_ekstrakurikuler',
            'ekstrakurikulers',
            'capaian_custom',
            'catatan_siswa',
            'absensis',
            'nilais',
            'kkms',
            'tujuan_pembelajarans',
            'lingkup_materis',
            'mata_pelajarans',
            'siswa_kelas_semester',
            'siswas',
            'guru_kelas',
            'kelas',
            'profil_sekolah',
            'tahun_ajarans',
            'gurus',
            'users',
        ] as $table) {
            Schema::dropIfExists($table);
        }

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->timestamps();
        });

        Schema::create('gurus', function (Blueprint $table) {
            $table->id();
            $table->string('nuptk')->nullable();
            $table->string('nama');
            $table->string('email')->nullable();
            $table->string('username')->nullable();
            $table->string('password');
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('tahun_ajarans', function (Blueprint $table) {
            $table->id();
            $table->string('tahun_ajaran');
            $table->boolean('is_active')->default(false);
            $table->integer('semester')->default(1);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('profil_sekolah', function (Blueprint $table) {
            $table->id();
            $table->string('nama_sekolah')->nullable();
            $table->string('tahun_pelajaran')->nullable();
            $table->integer('semester')->nullable();
            $table->string('kepala_sekolah')->nullable();
            $table->timestamps();
        });

        Schema::create('kelas', function (Blueprint $table) {
            $table->id();
            $table->integer('nomor_kelas');
            $table->string('nama_kelas');
            $table->string('tahun_ajaran')->nullable();
            $table->foreignId('tahun_ajaran_id')->nullable();
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

        Schema::create('siswas', function (Blueprint $table) {
            $table->id();
            $table->string('nis')->nullable();
            $table->string('nisn')->nullable();
            $table->string('nama');
            $table->foreignId('kelas_id')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('siswa_kelas_semester', function (Blueprint $table) {
            $table->id();
            $table->foreignId('siswa_id');
            $table->foreignId('kelas_id');
            $table->foreignId('tahun_ajaran_id');
            $table->tinyInteger('semester');
            $table->timestamps();
            $table->unique(['siswa_id', 'tahun_ajaran_id', 'semester']);
        });

        Schema::create('mata_pelajarans', function (Blueprint $table) {
            $table->id();
            $table->string('nama_pelajaran');
            $table->foreignId('kelas_id')->nullable();
            $table->foreignId('guru_id')->nullable();
            $table->integer('semester')->default(1);
            $table->foreignId('tahun_ajaran_id')->nullable();
            $table->boolean('is_muatan_lokal')->default(false);
            $table->boolean('allow_non_wali')->default(false);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('lingkup_materis', function (Blueprint $table) {
            $table->id();
            $table->foreignId('mata_pelajaran_id');
            $table->string('judul_lingkup_materi');
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('tujuan_pembelajarans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lingkup_materi_id');
            $table->string('kode_tp');
            $table->text('deskripsi_tp')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('kkms', function (Blueprint $table) {
            $table->id();
            $table->foreignId('mata_pelajaran_id');
            $table->foreignId('kelas_id')->nullable();
            $table->integer('nilai')->default(70);
            $table->foreignId('tahun_ajaran_id')->nullable();
            $table->timestamps();
        });

        Schema::create('nilais', function (Blueprint $table) {
            $table->id();
            $table->foreignId('siswa_id');
            $table->foreignId('mata_pelajaran_id')->nullable();
            $table->foreignId('tujuan_pembelajaran_id')->nullable();
            $table->foreignId('lingkup_materi_id')->nullable();
            $table->decimal('nilai_tp', 5, 2)->nullable();
            $table->decimal('nilai_lm', 5, 2)->nullable();
            $table->decimal('nilai_akhir_rapor', 5, 2)->nullable();
            $table->text('deskripsi')->nullable();
            $table->boolean('is_submitted')->default(false);
            $table->foreignId('tahun_ajaran_id')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('absensis', function (Blueprint $table) {
            $table->id();
            $table->foreignId('siswa_id');
            $table->integer('semester')->default(1);
            $table->foreignId('tahun_ajaran_id')->nullable();
            $table->integer('sakit')->default(0);
            $table->integer('izin')->default(0);
            $table->integer('tanpa_keterangan')->default(0);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('catatan_siswa', function (Blueprint $table) {
            $table->id();
            $table->foreignId('siswa_id');
            $table->foreignId('tahun_ajaran_id')->nullable();
            $table->integer('semester')->default(1);
            $table->string('type')->default('umum');
            $table->text('catatan')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('capaian_custom', function (Blueprint $table) {
            $table->id();
            $table->foreignId('siswa_id');
            $table->foreignId('mata_pelajaran_id');
            $table->text('custom_capaian_tertinggi')->nullable();
            $table->text('custom_capaian_terendah')->nullable();
            $table->foreignId('tahun_ajaran_id');
            $table->tinyInteger('semester');
            $table->timestamps();
        });

        Schema::create('ekstrakurikulers', function (Blueprint $table) {
            $table->id();
            $table->string('nama_ekstrakurikuler');
            $table->foreignId('tahun_ajaran_id')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('nilai_ekstrakurikuler', function (Blueprint $table) {
            $table->id();
            $table->foreignId('siswa_id');
            $table->foreignId('ekstrakurikuler_id')->nullable();
            $table->string('predikat')->nullable();
            $table->text('deskripsi')->nullable();
            $table->foreignId('tahun_ajaran_id')->nullable();
            $table->tinyInteger('semester')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('report_templates', function (Blueprint $table) {
            $table->id();
            $table->string('filename')->nullable();
            $table->string('path')->nullable();
            $table->string('type');
            $table->boolean('is_active')->default(false);
            $table->foreignId('kelas_id')->nullable();
            $table->foreignId('tahun_ajaran_id')->nullable();
            $table->integer('semester')->nullable();
            $table->timestamps();
        });

        Schema::create('report_template_kelas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('report_template_id');
            $table->foreignId('kelas_id');
            $table->timestamps();
        });

        Schema::create('report_generations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('siswa_id')->nullable();
            $table->foreignId('kelas_id')->nullable();
            $table->foreignId('report_template_id')->nullable();
            $table->string('generated_file')->nullable();
            $table->string('type')->nullable();
            $table->string('tahun_ajaran')->nullable();
            $table->integer('semester')->nullable();
            $table->foreignId('tahun_ajaran_id')->nullable();
            $table->foreignId('generated_by')->nullable();
            $table->timestamp('generated_at')->nullable();
            $table->timestamps();
        });

        Schema::create('notifications', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->text('content');
            $table->string('target');
            $table->json('specific_users')->nullable();
            $table->boolean('is_read')->default(false);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('notification_reads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('notification_id');
            $table->foreignId('guru_id');
            $table->timestamps();
        });
    }

    private function seedFixture(): void
    {
        $adminId = DB::table('users')->insertGetId([
            'name' => 'Admin Demo',
            'email' => 'admin@example.test',
            'password' => Hash::make('secret'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->admin = User::findOrFail($adminId);

        $waliId = DB::table('gurus')->insertGetId([
            'nama' => 'Budi Santoso',
            'username' => 'budi',
            'password' => Hash::make('secret'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->wali = Guru::findOrFail($waliId);

        $pengajarId = DB::table('gurus')->insertGetId([
            'nama' => 'Yusuf Hidayat',
            'username' => 'yusuf',
            'password' => Hash::make('secret'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->pengajar = Guru::findOrFail($pengajarId);

        $this->ganjilYearId = DB::table('tahun_ajarans')->insertGetId([
            'tahun_ajaran' => '2026/2027',
            'is_active' => true,
            'semester' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->genapYearId = DB::table('tahun_ajarans')->insertGetId([
            'tahun_ajaran' => '2026/2027',
            'is_active' => false,
            'semester' => 2,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('profil_sekolah')->insert([
            'nama_sekolah' => 'SDIT Al Hidayah',
            'tahun_pelajaran' => '2026/2027',
            'semester' => 1,
            'kepala_sekolah' => 'Kepala Demo',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->ganjilClassId = $this->insertClass(5, 'A', $this->ganjilYearId);
        $this->genapClassId = $this->insertClass(5, 'B', $this->genapYearId);
        $this->otherClassId = $this->insertClass(5, 'C', $this->ganjilYearId);

        $this->attachWali($this->wali->id, $this->ganjilClassId);
        $this->attachWali($this->wali->id, $this->genapClassId);
        $this->attachPengajar($this->pengajar->id, $this->ganjilClassId);
        $this->attachPengajar($this->pengajar->id, $this->genapClassId);

        $this->studentId = DB::table('siswas')->insertGetId([
            'nis' => '1001',
            'nisn' => '9001',
            'nama' => 'Ahmad Fauzan',
            'kelas_id' => $this->otherClassId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->insertEnrollment($this->studentId, $this->ganjilClassId, $this->ganjilYearId, 1);
        $this->insertEnrollment($this->studentId, $this->genapClassId, $this->genapYearId, 2);

        $this->ganjilSubjectId = $this->insertSubject('Matematika Ganjil', $this->pengajar->id, $this->ganjilClassId, $this->ganjilYearId, 1);
        $this->genapSubjectId = $this->insertSubject('Matematika Genap', $this->pengajar->id, $this->genapClassId, $this->genapYearId, 2);

        $ganjilLmId = $this->insertLearningData($this->ganjilSubjectId);
        $this->insertLearningData($this->genapSubjectId);

        DB::table('nilais')->insert([
            'siswa_id' => $this->studentId,
            'mata_pelajaran_id' => $this->ganjilSubjectId,
            'lingkup_materi_id' => $ganjilLmId,
            'nilai_lm' => 88,
            'nilai_akhir_rapor' => 88,
            'is_submitted' => true,
            'tahun_ajaran_id' => $this->ganjilYearId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('absensis')->insert([
            [
                'siswa_id' => $this->studentId,
                'semester' => 1,
                'tahun_ajaran_id' => $this->ganjilYearId,
                'sakit' => 7,
                'izin' => 0,
                'tanpa_keterangan' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'siswa_id' => $this->studentId,
                'semester' => 2,
                'tahun_ajaran_id' => $this->genapYearId,
                'sakit' => 3,
                'izin' => 0,
                'tanpa_keterangan' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        DB::table('catatan_siswa')->insert([
            'siswa_id' => $this->studentId,
            'tahun_ajaran_id' => $this->ganjilYearId,
            'semester' => 1,
            'type' => 'umum',
            'catatan' => 'Catatan ganjil',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $ganjilEkskulId = DB::table('ekstrakurikulers')->insertGetId([
            'nama_ekstrakurikuler' => 'Pramuka',
            'tahun_ajaran_id' => $this->ganjilYearId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $genapEkskulId = DB::table('ekstrakurikulers')->insertGetId([
            'nama_ekstrakurikuler' => 'Pramuka Genap',
            'tahun_ajaran_id' => $this->genapYearId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('nilai_ekstrakurikuler')->insert([
            [
                'siswa_id' => $this->studentId,
                'ekstrakurikuler_id' => $ganjilEkskulId,
                'predikat' => 'A',
                'deskripsi' => 'Ganjil Pramuka',
                'tahun_ajaran_id' => $this->ganjilYearId,
                'semester' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'siswa_id' => $this->studentId,
                'ekstrakurikuler_id' => $genapEkskulId,
                'predikat' => 'B',
                'deskripsi' => 'Genap Pramuka',
                'tahun_ajaran_id' => $this->genapYearId,
                'semester' => 2,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
    }

    private function insertClass(int $nomor, string $nama, int $tahunAjaranId): int
    {
        return DB::table('kelas')->insertGetId([
            'nomor_kelas' => $nomor,
            'nama_kelas' => $nama,
            'tahun_ajaran' => '2026/2027',
            'tahun_ajaran_id' => $tahunAjaranId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function insertSubject(string $name, int $guruId, int $kelasId, int $tahunAjaranId, int $semester): int
    {
        return DB::table('mata_pelajarans')->insertGetId([
            'nama_pelajaran' => $name,
            'guru_id' => $guruId,
            'kelas_id' => $kelasId,
            'tahun_ajaran_id' => $tahunAjaranId,
            'semester' => $semester,
            'is_muatan_lokal' => false,
            'allow_non_wali' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function insertLearningData(int $subjectId): int
    {
        $lmId = DB::table('lingkup_materis')->insertGetId([
            'mata_pelajaran_id' => $subjectId,
            'judul_lingkup_materi' => 'Bilangan',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('tujuan_pembelajarans')->insert([
            'lingkup_materi_id' => $lmId,
            'kode_tp' => 'TP1',
            'deskripsi_tp' => 'Memahami bilangan',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $lmId;
    }

    private function attachWali(int $guruId, int $kelasId): void
    {
        DB::table('guru_kelas')->insert([
            'guru_id' => $guruId,
            'kelas_id' => $kelasId,
            'is_wali_kelas' => true,
            'role' => 'wali_kelas',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function attachPengajar(int $guruId, int $kelasId): void
    {
        DB::table('guru_kelas')->insert([
            'guru_id' => $guruId,
            'kelas_id' => $kelasId,
            'is_wali_kelas' => false,
            'role' => 'pengajar',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function insertEnrollment(int $studentId, int $kelasId, int $tahunAjaranId, int $semester): void
    {
        DB::table('siswa_kelas_semester')->insert([
            'siswa_id' => $studentId,
            'kelas_id' => $kelasId,
            'tahun_ajaran_id' => $tahunAjaranId,
            'semester' => $semester,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}

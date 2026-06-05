<?php

namespace Tests\Feature;

use App\Models\Guru;
use App\Models\Kelas;
use App\Models\MataPelajaran;
use App\Models\Siswa;
use App\Models\SiswaKelasSemester;
use App\Models\TahunAjaran;
use Database\Seeders\DemoSemesterGanjilSeeder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

class DemoSemesterGanjilSeederTest extends TestCase
{
    private const DEMO_NIS = ['2605001', '2605002', '2605003', '2605101'];

    private const DEMO_USERNAMES = ['demo_admin_sdit', 'demo_budi', 'demo_ani', 'demo_yusuf'];

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('database.default', 'sqlite');
        config()->set('database.connections.sqlite.database', ':memory:');
        config()->set('cache.default', 'array');
        config()->set('session.driver', 'array');
        DB::purge('sqlite');
        DB::reconnect('sqlite');
        Cache::flush();

        $this->createSchema();
        $this->setDemoPasswords();
    }

    protected function tearDown(): void
    {
        foreach (['DEMO_ADMIN_PASSWORD', 'DEMO_BUDI_PASSWORD', 'DEMO_ANI_PASSWORD', 'DEMO_YUSUF_PASSWORD'] as $key) {
            putenv($key);
            unset($_ENV[$key], $_SERVER[$key]);
        }

        parent::tearDown();
    }

    public function test_seeder_refuses_unsafe_environments(): void
    {
        $this->app->detectEnvironment(fn () => 'production');

        try {
            $this->seedDemo();
            $this->fail('The demo seeder should refuse production-like environments.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('may only run', $exception->getMessage());
        }

        $this->assertSame(0, DB::table('tahun_ajarans')->count());
        $this->assertSame(0, DB::table('users')->count());
    }

    public function test_required_ganjil_demo_records_are_created(): void
    {
        $this->seedDemo();

        $year = $this->activeYear();

        $this->assertSame('2026/2027', $year->tahun_ajaran);
        $this->assertSame(1, (int) $year->semester);
        $this->assertTrue((bool) $year->is_active);

        $this->assertDatabaseHas('profil_sekolah', [
            'nama_sekolah' => 'SDIT Al Hidayah',
            'tahun_pelajaran' => '2026/2027',
            'semester' => 1,
        ]);
        $this->assertDatabaseHas('users', ['username' => 'demo_admin_sdit']);
        $this->assertDatabaseHas('gurus', ['username' => 'demo_budi', 'nama' => 'Budi Santoso']);
        $this->assertDatabaseHas('gurus', ['username' => 'demo_ani', 'nama' => 'Ani Rahmawati']);
        $this->assertDatabaseHas('gurus', ['username' => 'demo_yusuf', 'nama' => 'Yusuf Hidayat']);
        $this->assertSame(2, Kelas::where('tahun_ajaran_id', $year->id)->count());
        $this->assertSame(4, Siswa::whereIn('nis', self::DEMO_NIS)->count());
        $this->assertSame(4, SiswaKelasSemester::where('tahun_ajaran_id', $year->id)->where('semester', 1)->count());
    }

    public function test_budi_has_valid_pengajar_and_wali_kelas_roles(): void
    {
        $this->seedDemo();

        $year = $this->activeYear();
        $budi = Guru::where('username', 'demo_budi')->firstOrFail();

        $this->assertTrue($budi->hasPengajarAssignment($year->id, 1));
        $this->assertTrue($budi->hasWaliKelasAssignment($year->id));
        $this->assertSame(['pengajar', 'wali_kelas'], $budi->availableRoles($year->id, 1));
        $this->assertDatabaseHas('mata_pelajarans', [
            'nama_pelajaran' => 'Matematika',
            'guru_id' => $budi->id,
            'semester' => 1,
            'tahun_ajaran_id' => $year->id,
        ]);
        $this->assertSame(
            [$this->classByName(5, 'A')->id],
            MataPelajaran::where('guru_id', $budi->id)->pluck('kelas_id')->unique()->values()->all()
        );
        $this->assertSame(0, MataPelajaran::where('guru_id', $budi->id)
            ->where(function ($query) {
                $query->where('is_muatan_lokal', true)
                    ->orWhere('allow_non_wali', true);
            })
            ->count());
    }

    public function test_ani_has_only_intended_assignments(): void
    {
        $this->seedDemo();

        $year = $this->activeYear();
        $ani = Guru::where('username', 'demo_ani')->firstOrFail();
        $kelas5A = $this->classByName(5, 'A');
        $kelas5B = $this->classByName(5, 'B');

        $this->assertTrue($ani->hasPengajarAssignment($year->id, 1));
        $this->assertTrue($ani->hasWaliKelasAssignment($year->id));
        $this->assertDatabaseHas('guru_kelas', [
            'guru_id' => $ani->id,
            'kelas_id' => $kelas5B->id,
            'role' => 'wali_kelas',
            'is_wali_kelas' => true,
        ]);
        $this->assertDatabaseMissing('guru_kelas', [
            'guru_id' => $ani->id,
            'kelas_id' => $kelas5A->id,
            'role' => 'pengajar',
            'is_wali_kelas' => false,
        ]);

        $aniSubjects = MataPelajaran::where('guru_id', $ani->id)
            ->orderBy('kelas_id')
            ->orderBy('nama_pelajaran')
            ->get()
            ->map(fn (MataPelajaran $subject) => [
                'nama' => $subject->nama_pelajaran,
                'kelas' => $subject->kelas_id,
            ])
            ->values()
            ->all();

        $this->assertSame([
            ['nama' => 'Bahasa Indonesia', 'kelas' => $kelas5B->id],
            ['nama' => 'Matematika', 'kelas' => $kelas5B->id],
        ], $aniSubjects);
        $this->assertSame(0, MataPelajaran::where('guru_id', $ani->id)
            ->where(function ($query) {
                $query->where('is_muatan_lokal', true)
                    ->orWhere('allow_non_wali', true);
            })
            ->count());
    }

    public function test_non_wali_demo_teacher_has_intended_cross_class_specialist_and_local_assignments(): void
    {
        $this->seedDemo();

        $yusuf = Guru::where('username', 'demo_yusuf')->firstOrFail();
        $kelas5A = $this->classByName(5, 'A');
        $kelas5B = $this->classByName(5, 'B');

        $this->assertFalse($yusuf->hasWaliKelasAssignment($this->activeYear()->id));
        $this->assertTrue($yusuf->hasPengajarAssignment($this->activeYear()->id, 1));
        $this->assertDatabaseHas('guru_kelas', [
            'guru_id' => $yusuf->id,
            'kelas_id' => $kelas5A->id,
            'role' => 'pengajar',
            'is_wali_kelas' => false,
        ]);
        $this->assertDatabaseHas('guru_kelas', [
            'guru_id' => $yusuf->id,
            'kelas_id' => $kelas5B->id,
            'role' => 'pengajar',
            'is_wali_kelas' => false,
        ]);

        $this->assertDatabaseHas('mata_pelajarans', [
            'nama_pelajaran' => 'PAI',
            'kelas_id' => $kelas5A->id,
            'guru_id' => $yusuf->id,
            'is_muatan_lokal' => false,
            'allow_non_wali' => true,
        ]);
        $this->assertDatabaseHas('mata_pelajarans', [
            'nama_pelajaran' => 'Bahasa Sunda',
            'kelas_id' => $kelas5B->id,
            'guru_id' => $yusuf->id,
            'is_muatan_lokal' => true,
            'allow_non_wali' => false,
        ]);
    }

    public function test_classes_have_intended_wali_assignments(): void
    {
        $this->seedDemo();

        $budi = Guru::where('username', 'demo_budi')->firstOrFail();
        $ani = Guru::where('username', 'demo_ani')->firstOrFail();
        $kelas5A = $this->classByName(5, 'A');
        $kelas5B = $this->classByName(5, 'B');

        $this->assertSame($budi->id, $this->waliGuruId($kelas5A));
        $this->assertSame($ani->id, $this->waliGuruId($kelas5B));
        $this->assertSame(1, $this->waliCount($kelas5A));
        $this->assertSame(1, $this->waliCount($kelas5B));
    }

    public function test_running_the_seeder_twice_does_not_duplicate_core_records(): void
    {
        $this->seedDemo();
        $firstCounts = $this->coreCounts();

        $this->seedDemo();

        $this->assertSame($firstCounts, $this->coreCounts());
    }

    public function test_existing_unrelated_data_is_not_deleted(): void
    {
        $oldYearId = DB::table('tahun_ajarans')->insertGetId([
            'tahun_ajaran' => '2025/2026',
            'semester' => 1,
            'is_active' => false,
            'tanggal_mulai' => '2025-07-14',
            'tanggal_selesai' => '2026-06-20',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $oldClassId = DB::table('kelas')->insertGetId([
            'nomor_kelas' => 4,
            'nama_kelas' => 'Z',
            'tahun_ajaran_id' => $oldYearId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('siswas')->insert([
            'nis' => '2504001',
            'nisn' => '8000000001',
            'nama' => 'Existing Student',
            'tanggal_lahir' => '2014-01-01',
            'jenis_kelamin' => 'Laki-laki',
            'agama' => 'Islam',
            'alamat' => 'Existing Address',
            'kelas_id' => $oldClassId,
            'nama_ayah' => 'Existing Father',
            'nama_ibu' => 'Existing Mother',
            'pekerjaan_ayah' => 'Existing Job',
            'pekerjaan_ibu' => 'Existing Job',
            'alamat_orangtua' => 'Existing Address',
            'tahun_ajaran_id' => $oldYearId,
            'status' => 'aktif',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->seedDemo();

        $this->assertDatabaseHas('tahun_ajarans', ['id' => $oldYearId, 'tahun_ajaran' => '2025/2026']);
        $this->assertDatabaseHas('kelas', ['id' => $oldClassId, 'nomor_kelas' => 4, 'nama_kelas' => 'Z']);
        $this->assertDatabaseHas('siswas', ['nis' => '2504001', 'nama' => 'Existing Student']);
    }

    public function test_existing_non_demo_admin_is_not_overwritten(): void
    {
        $passwordHash = Hash::make('keep-this-password');

        DB::table('users')->insert([
            'name' => 'Real Admin',
            'username' => 'admin_real',
            'email' => 'admin.real@example.test',
            'password' => $passwordHash,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->seedDemo();

        $admin = DB::table('users')->where('username', 'admin_real')->first();
        $this->assertSame('Real Admin', $admin->name);
        $this->assertSame('admin.real@example.test', $admin->email);
        $this->assertSame($passwordHash, $admin->password);
    }

    public function test_subject_and_learning_data_support_grade_input(): void
    {
        $this->seedDemo();

        $year = $this->activeYear();
        $math5A = $this->subject('Matematika', 5, 'A');
        $bahasa5A = $this->subject('Bahasa Indonesia', 5, 'A');
        $ahmad = Siswa::where('nis', '2605001')->firstOrFail();
        $siti = Siswa::where('nis', '2605002')->firstOrFail();
        $rina = Siswa::where('nis', '2605003')->firstOrFail();

        foreach ([
            $math5A,
            $bahasa5A,
            $this->subject('Matematika', 5, 'B'),
            $this->subject('Bahasa Indonesia', 5, 'B'),
            $this->subject('PAI', 5, 'A'),
            $this->subject('PAI', 5, 'B'),
            $this->subject('Bahasa Sunda', 5, 'A'),
            $this->subject('Bahasa Sunda', 5, 'B'),
        ] as $subject) {
            $this->assertDatabaseHas('lingkup_materis', ['mata_pelajaran_id' => $subject->id]);
            $lmId = DB::table('lingkup_materis')->where('mata_pelajaran_id', $subject->id)->value('id');
            $this->assertDatabaseHas('tujuan_pembelajarans', ['lingkup_materi_id' => $lmId]);
            $this->assertDatabaseHas('kkms', [
                'mata_pelajaran_id' => $subject->id,
                'kelas_id' => $subject->kelas_id,
                'tahun_ajaran_id' => $year->id,
                'nilai' => 75,
            ]);
        }

        $this->assertDatabaseHas('nilais', [
            'siswa_id' => $ahmad->id,
            'mata_pelajaran_id' => $math5A->id,
            'nilai_akhir_rapor' => 87,
            'is_submitted' => true,
            'tahun_ajaran_id' => $year->id,
        ]);
        $this->assertDatabaseHas('nilais', [
            'siswa_id' => $siti->id,
            'mata_pelajaran_id' => $math5A->id,
            'is_submitted' => false,
            'tahun_ajaran_id' => $year->id,
        ]);
        $this->assertSame(0, DB::table('nilais')->where('siswa_id', $rina->id)->count());
        $this->assertSame(0, DB::table('nilais')->where('mata_pelajaran_id', $bahasa5A->id)->count());
        $this->assertDatabaseHas('bobot_nilais', [
            'tahun_ajaran_id' => $year->id,
            'bobot_tp' => 1,
            'bobot_lm' => 1,
            'bobot_as' => 2,
        ]);
    }

    public function test_no_fake_report_template_is_created(): void
    {
        $this->seedDemo();

        $this->assertSame(0, DB::table('report_templates')->count());
    }

    public function test_no_semester_genap_academic_year_record_is_created(): void
    {
        $this->seedDemo();

        $this->assertDatabaseMissing('tahun_ajarans', [
            'tahun_ajaran' => '2026/2027',
            'semester' => 2,
        ]);
    }

    public function test_no_2027_2028_academic_year_record_is_created(): void
    {
        $this->seedDemo();

        $this->assertDatabaseMissing('tahun_ajarans', ['tahun_ajaran' => '2027/2028']);
    }

    public function test_no_s2_student_records_are_created(): void
    {
        $this->seedDemo();

        $this->assertSame(0, DB::table('siswas')->where('nis', 'like', 'S2-%')->count());
        $this->assertSame(0, DB::table('siswas')->where('nisn', 'like', 'S2-%')->count());
    }

    public function test_no_promotion_or_class_mutation_occurs(): void
    {
        $this->seedDemo();

        $kelas5A = $this->classByName(5, 'A');
        $kelas5B = $this->classByName(5, 'B');

        $this->assertSame(
            [$kelas5A->id, $kelas5A->id, $kelas5A->id, $kelas5B->id],
            Siswa::whereIn('nis', self::DEMO_NIS)->orderBy('nis')->pluck('kelas_id')->all()
        );
        $this->assertSame(0, Siswa::whereIn('nis', self::DEMO_NIS)->whereNotNull('is_naik_kelas')->count());
        $this->assertSame(0, Siswa::whereIn('nis', self::DEMO_NIS)->whereNotNull('kelas_tujuan_id')->count());
        $this->assertSame(0, Kelas::where('nomor_kelas', 6)->count());
    }

    public function test_demo_students_receive_only_semester_one_enrollment(): void
    {
        $this->seedDemo();

        $year = $this->activeYear();
        $kelas5A = $this->classByName(5, 'A');
        $kelas5B = $this->classByName(5, 'B');

        $expectedClassesByNis = [
            '2605001' => $kelas5A->id,
            '2605002' => $kelas5A->id,
            '2605003' => $kelas5A->id,
            '2605101' => $kelas5B->id,
        ];

        foreach ($expectedClassesByNis as $nis => $kelasId) {
            $student = Siswa::where('nis', $nis)->firstOrFail();

            $this->assertDatabaseHas('siswa_kelas_semester', [
                'siswa_id' => $student->id,
                'kelas_id' => $kelasId,
                'tahun_ajaran_id' => $year->id,
                'semester' => 1,
            ]);
        }

        $this->assertSame(0, SiswaKelasSemester::where('semester', 2)->count());
    }

    public function test_rerunning_the_seeder_does_not_duplicate_enrollment_rows(): void
    {
        $this->seedDemo();
        $firstCount = SiswaKelasSemester::count();

        $this->seedDemo();

        $this->assertSame($firstCount, SiswaKelasSemester::count());
        $this->assertSame(4, $firstCount);
    }

    public function test_seeded_ganjil_data_satisfies_transition_prerequisites(): void
    {
        $this->seedDemo();

        $year = $this->activeYear();

        $this->assertSame(1, (int) $year->semester);
        $this->assertTrue((bool) $year->is_active);
        $this->assertSame(2, Kelas::where('tahun_ajaran_id', $year->id)->count());
        $this->assertSame(6, DB::table('guru_kelas')->count());
        $this->assertSame(8, MataPelajaran::where('tahun_ajaran_id', $year->id)->where('semester', 1)->count());
        $this->assertSame(8, DB::table('lingkup_materis')->count());
        $this->assertSame(8, DB::table('tujuan_pembelajarans')->count());
        $this->assertSame(8, DB::table('kkms')->where('tahun_ajaran_id', $year->id)->count());
        $this->assertSame(1, DB::table('bobot_nilais')->where('tahun_ajaran_id', $year->id)->count());
        $this->assertSame(1, DB::table('ekstrakurikulers')->where('tahun_ajaran_id', $year->id)->count());
        $this->assertSame(2, DB::table('absensis')->where('semester', 1)->where('tahun_ajaran_id', $year->id)->count());
        $this->assertSame(0, DB::table('report_templates')->count(), 'DOCX templates must be uploaded manually.');
    }

    private function seedDemo(): void
    {
        (new DemoSemesterGanjilSeeder)->run();
    }

    private function activeYear(): TahunAjaran
    {
        return TahunAjaran::where('tahun_ajaran', '2026/2027')
            ->where('semester', 1)
            ->firstOrFail();
    }

    private function classByName(int $number, string $name): Kelas
    {
        return Kelas::where('nomor_kelas', $number)
            ->where('nama_kelas', $name)
            ->where('tahun_ajaran_id', $this->activeYear()->id)
            ->firstOrFail();
    }

    private function subject(string $name, int $classNumber, string $className): MataPelajaran
    {
        $kelas = $this->classByName($classNumber, $className);

        return MataPelajaran::where('nama_pelajaran', $name)
            ->where('kelas_id', $kelas->id)
            ->where('tahun_ajaran_id', $this->activeYear()->id)
            ->where('semester', 1)
            ->firstOrFail();
    }

    private function waliGuruId(Kelas $kelas): int
    {
        return (int) DB::table('guru_kelas')
            ->where('kelas_id', $kelas->id)
            ->where('role', 'wali_kelas')
            ->where('is_wali_kelas', true)
            ->value('guru_id');
    }

    private function waliCount(Kelas $kelas): int
    {
        return DB::table('guru_kelas')
            ->where('kelas_id', $kelas->id)
            ->where('role', 'wali_kelas')
            ->where('is_wali_kelas', true)
            ->count();
    }

    /**
     * @return array<string, int>
     */
    private function coreCounts(): array
    {
        $year = $this->activeYear();

        return [
            'users' => DB::table('users')->whereIn('username', ['demo_admin_sdit'])->count(),
            'gurus' => DB::table('gurus')->whereIn('username', ['demo_budi', 'demo_ani', 'demo_yusuf'])->count(),
            'kelas' => DB::table('kelas')->where('tahun_ajaran_id', $year->id)->count(),
            'students' => DB::table('siswas')->whereIn('nis', self::DEMO_NIS)->count(),
            'subjects' => DB::table('mata_pelajarans')->where('tahun_ajaran_id', $year->id)->count(),
            'guru_kelas' => DB::table('guru_kelas')->count(),
            'lingkup_materis' => DB::table('lingkup_materis')->count(),
            'tujuan_pembelajarans' => DB::table('tujuan_pembelajarans')->count(),
            'nilais' => DB::table('nilais')->count(),
            'absensis' => DB::table('absensis')->count(),
            'catatan_siswa' => DB::table('catatan_siswa')->count(),
            'capaian_custom' => DB::table('capaian_custom')->count(),
            'report_templates' => DB::table('report_templates')->count(),
            'siswa_kelas_semester' => DB::table('siswa_kelas_semester')->count(),
        ];
    }

    private function setDemoPasswords(): void
    {
        foreach ([
            'DEMO_ADMIN_PASSWORD' => 'secret-admin',
            'DEMO_BUDI_PASSWORD' => 'secret-budi',
            'DEMO_ANI_PASSWORD' => 'secret-ani',
            'DEMO_YUSUF_PASSWORD' => 'secret-yusuf',
        ] as $key => $value) {
            putenv("{$key}={$value}");
            $_ENV[$key] = $value;
            $_SERVER[$key] = $value;
        }
    }

    private function createSchema(): void
    {
        foreach ([
            'audit_logs',
            'report_templates',
            'catatan_mata_pelajaran',
            'catatan_siswa',
            'capaian_custom',
            'nilai_ekstrakurikuler',
            'ekstrakurikulers',
            'absensis',
            'nilais',
            'tujuan_pembelajarans',
            'lingkup_materis',
            'bobot_nilais',
            'kkms',
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
            $table->string('name')->nullable();
            $table->string('username')->nullable()->unique();
            $table->string('email')->nullable()->unique();
            $table->string('password');
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
            $table->string('user_agent')->nullable();
            $table->timestamps();
        });

        Schema::create('gurus', function (Blueprint $table) {
            $table->id();
            $table->string('nuptk')->nullable()->unique();
            $table->string('nama');
            $table->string('jenis_kelamin')->nullable();
            $table->date('tanggal_lahir')->nullable();
            $table->string('no_handphone')->nullable();
            $table->string('email')->nullable()->unique();
            $table->text('alamat')->nullable();
            $table->string('jabatan')->nullable();
            $table->string('username')->nullable()->unique();
            $table->string('password');
            $table->string('photo')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('tahun_ajarans', function (Blueprint $table) {
            $table->id();
            $table->string('tahun_ajaran');
            $table->boolean('is_active')->default(false);
            $table->date('tanggal_mulai')->nullable();
            $table->date('tanggal_selesai')->nullable();
            $table->integer('semester')->default(1);
            $table->text('deskripsi')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('profil_sekolah', function (Blueprint $table) {
            $table->id();
            $table->string('logo')->nullable();
            $table->string('nama_instansi')->nullable();
            $table->string('nama_sekolah')->nullable();
            $table->string('tahun_pelajaran')->nullable();
            $table->integer('semester')->nullable();
            $table->string('npsn')->nullable();
            $table->string('kepala_sekolah')->nullable();
            $table->string('nip_kepala_sekolah')->nullable();
            $table->text('alamat')->nullable();
            $table->integer('guru_kelas')->nullable();
            $table->string('kode_pos')->nullable();
            $table->integer('kelas')->nullable();
            $table->string('telepon')->nullable();
            $table->integer('jumlah_siswa')->nullable();
            $table->string('email_sekolah')->nullable();
            $table->string('tempat_terbit')->nullable();
            $table->date('tanggal_terbit')->nullable();
            $table->string('website')->nullable();
            $table->string('kelurahan')->nullable();
            $table->string('kecamatan')->nullable();
            $table->string('kabupaten')->nullable();
            $table->string('provinsi')->nullable();
            $table->timestamps();
        });

        Schema::create('kelas', function (Blueprint $table) {
            $table->id();
            $table->integer('nomor_kelas');
            $table->string('nama_kelas');
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
            $table->unique(['guru_id', 'kelas_id', 'role']);
        });

        Schema::create('siswas', function (Blueprint $table) {
            $table->id();
            $table->string('nis')->nullable()->unique();
            $table->string('nisn')->nullable()->unique();
            $table->string('nama');
            $table->date('tanggal_lahir')->nullable();
            $table->string('jenis_kelamin')->nullable();
            $table->string('agama')->nullable();
            $table->text('alamat')->nullable();
            $table->foreignId('kelas_id')->nullable();
            $table->string('nama_ayah')->nullable();
            $table->string('nama_ibu')->nullable();
            $table->string('pekerjaan_ayah')->nullable();
            $table->string('pekerjaan_ibu')->nullable();
            $table->string('wali_siswa')->nullable();
            $table->string('pekerjaan_wali')->nullable();
            $table->text('alamat_orangtua')->nullable();
            $table->string('photo')->nullable();
            $table->foreignId('tahun_ajaran_id')->nullable();
            $table->string('status')->default('aktif');
            $table->boolean('is_naik_kelas')->nullable();
            $table->foreignId('kelas_tujuan_id')->nullable();
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
            $table->index(['kelas_id', 'tahun_ajaran_id', 'semester']);
        });

        Schema::create('mata_pelajarans', function (Blueprint $table) {
            $table->id();
            $table->string('nama_pelajaran');
            $table->foreignId('kelas_id')->nullable();
            $table->foreignId('tahun_ajaran_id')->nullable();
            $table->integer('semester')->default(1);
            $table->boolean('is_muatan_lokal')->default(false);
            $table->boolean('allow_non_wali')->default(false);
            $table->foreignId('guru_id')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('kkms', function (Blueprint $table) {
            $table->id();
            $table->foreignId('mata_pelajaran_id')->nullable();
            $table->foreignId('kelas_id')->nullable();
            $table->foreignId('tahun_ajaran_id')->nullable();
            $table->integer('nilai')->default(70);
            $table->timestamps();
        });

        Schema::create('bobot_nilais', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tahun_ajaran_id')->nullable();
            $table->integer('bobot_tp')->default(1);
            $table->integer('bobot_lm')->default(1);
            $table->integer('bobot_as')->default(2);
            $table->timestamps();
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

        Schema::create('nilais', function (Blueprint $table) {
            $table->id();
            $table->foreignId('siswa_id');
            $table->foreignId('mata_pelajaran_id')->nullable();
            $table->foreignId('tujuan_pembelajaran_id')->nullable();
            $table->foreignId('lingkup_materi_id')->nullable();
            $table->decimal('nilai_tp', 5, 2)->nullable();
            $table->decimal('nilai_lm', 5, 2)->nullable();
            $table->decimal('nilai_akhir_semester', 5, 2)->nullable();
            $table->decimal('na_tp', 5, 2)->nullable();
            $table->decimal('na_lm', 5, 2)->nullable();
            $table->integer('tp_number')->nullable();
            $table->decimal('nilai_tes', 5, 2)->nullable();
            $table->decimal('nilai_non_tes', 5, 2)->nullable();
            $table->decimal('nilai_akhir_rapor', 5, 2)->nullable();
            $table->boolean('is_submitted')->default(false);
            $table->foreignId('tahun_ajaran_id')->nullable();
            $table->timestamps();
            $table->softDeletes();
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

        Schema::create('ekstrakurikulers', function (Blueprint $table) {
            $table->id();
            $table->string('nama_ekstrakurikuler');
            $table->string('pembina')->nullable();
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
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('capaian_custom', function (Blueprint $table) {
            $table->id();
            $table->foreignId('siswa_id');
            $table->foreignId('mata_pelajaran_id');
            $table->text('custom_capaian')->nullable();
            $table->text('custom_capaian_tertinggi')->nullable();
            $table->text('custom_capaian_terendah')->nullable();
            $table->foreignId('tahun_ajaran_id');
            $table->tinyInteger('semester');
            $table->timestamps();
            $table->unique(['siswa_id', 'mata_pelajaran_id', 'tahun_ajaran_id', 'semester']);
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

        Schema::create('report_templates', function (Blueprint $table) {
            $table->id();
            $table->string('filename')->nullable();
            $table->string('path')->nullable();
            $table->string('type')->nullable();
            $table->boolean('is_active')->default(false);
            $table->string('tahun_ajaran')->nullable();
            $table->string('tahun_ajaran_text')->nullable();
            $table->integer('semester')->nullable();
            $table->foreignId('kelas_id')->nullable();
            $table->foreignId('tahun_ajaran_id')->nullable();
            $table->timestamps();
        });
    }
}

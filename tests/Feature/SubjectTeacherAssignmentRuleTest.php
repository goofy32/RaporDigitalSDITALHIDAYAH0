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

class SubjectTeacherAssignmentRuleTest extends TestCase
{
    private User $admin;

    private Guru $budi;

    private Guru $ani;

    private Guru $yusuf;

    private int $yearId;

    private int $oldYearId;

    private int $kelas5AId;

    private int $kelas5BId;

    private int $oldKelas5AId;

    protected function setUp(): void
    {
        parent::setUp();

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

    public function test_wali_5a_may_teach_regular_mandatory_subject_in_5a(): void
    {
        $this->postSubject('Matematika Demo', $this->kelas5AId, $this->budi->id, 'regular')
            ->assertRedirect(route('subject.index'));

        $this->assertDatabaseHas('mata_pelajarans', [
            'nama_pelajaran' => 'Matematika Demo',
            'kelas_id' => $this->kelas5AId,
            'guru_id' => $this->budi->id,
            'is_muatan_lokal' => false,
            'allow_non_wali' => false,
        ]);
    }

    public function test_wali_5a_cannot_teach_regular_mandatory_subject_in_5b(): void
    {
        $this->postSubject('Matematika Lintas Kelas', $this->kelas5BId, $this->budi->id, 'regular')
            ->assertRedirect()
            ->assertSessionHasErrors('subjects.0.guru_pengampu');

        $this->assertDatabaseMissing('mata_pelajarans', ['nama_pelajaran' => 'Matematika Lintas Kelas']);
    }

    public function test_wali_cannot_teach_muatan_lokal(): void
    {
        $this->postSubject('Bahasa Sunda Wali', $this->kelas5AId, $this->budi->id, 'muatan_lokal')
            ->assertRedirect()
            ->assertSessionHasErrors('subjects.0.guru_pengampu');

        $this->assertDatabaseMissing('mata_pelajarans', ['nama_pelajaran' => 'Bahasa Sunda Wali']);
    }

    public function test_wali_cannot_teach_specialist_mandatory_subject(): void
    {
        $this->postSubject('PAI Wali', $this->kelas5AId, $this->budi->id, 'specialist')
            ->assertRedirect()
            ->assertSessionHasErrors('subjects.0.guru_pengampu');

        $this->assertDatabaseMissing('mata_pelajarans', ['nama_pelajaran' => 'PAI Wali']);
    }

    public function test_non_wali_teacher_can_teach_muatan_lokal(): void
    {
        $this->postSubject('Bahasa Sunda Demo', $this->kelas5AId, $this->yusuf->id, 'muatan_lokal')
            ->assertRedirect(route('subject.index'));

        $this->assertDatabaseHas('mata_pelajarans', [
            'nama_pelajaran' => 'Bahasa Sunda Demo',
            'kelas_id' => $this->kelas5AId,
            'guru_id' => $this->yusuf->id,
            'is_muatan_lokal' => true,
            'allow_non_wali' => false,
        ]);
    }

    public function test_non_wali_teacher_can_teach_specialist_mandatory_subject(): void
    {
        $this->postSubject('PAI Demo', $this->kelas5BId, $this->yusuf->id, 'specialist')
            ->assertRedirect(route('subject.index'));

        $this->assertDatabaseHas('mata_pelajarans', [
            'nama_pelajaran' => 'PAI Demo',
            'kelas_id' => $this->kelas5BId,
            'guru_id' => $this->yusuf->id,
            'is_muatan_lokal' => false,
            'allow_non_wali' => true,
        ]);
    }

    public function test_both_subject_type_flags_are_rejected_on_direct_post(): void
    {
        $this->actingAs($this->admin, 'web')
            ->withSession($this->adminSession())
            ->from(route('subject.create'))
            ->post(route('subject.store'), [
                'subjects' => [[
                    'mata_pelajaran' => 'Konfigurasi Ganda',
                    'kelas' => $this->kelas5AId,
                    'guru_pengampu' => $this->yusuf->id,
                    'semester' => 1,
                    'is_muatan_lokal' => '1',
                    'allow_non_wali' => '1',
                    'lingkup_materi' => ['Materi demo'],
                ]],
            ])
            ->assertRedirect(route('subject.create'))
            ->assertSessionHasErrors('subjects.0.teaching_type');

        $this->assertDatabaseMissing('mata_pelajarans', ['nama_pelajaran' => 'Konfigurasi Ganda']);
    }

    public function test_academic_year_scope_is_respected_for_wali_lookup(): void
    {
        $this->postSubject('Matematika Tahun Lama', $this->oldKelas5AId, $this->budi->id, 'regular')
            ->assertRedirect()
            ->assertSessionHasErrors('subjects.0.guru_pengampu');

        $this->assertDatabaseMissing('mata_pelajarans', ['nama_pelajaran' => 'Matematika Tahun Lama']);
    }

    public function test_create_page_teacher_options_expose_active_year_wali_eligibility(): void
    {
        $historicalWali = Guru::findOrFail($this->insertGuru('Wali Tahun Lama', 'wali_lama', 'guru_wali'));
        DB::table('guru_kelas')->insert($this->pivot($historicalWali->id, $this->oldKelas5AId, true, 'wali_kelas'));

        $activeClassId = $this->insertClass(5, 'C', $this->yearId);
        $activeWaliWithGuruLabel = Guru::findOrFail($this->insertGuru('Wali Aktif Label Guru', 'wali_label_guru', 'guru'));
        DB::table('guru_kelas')->insert($this->pivot($activeWaliWithGuruLabel->id, $activeClassId, true, 'wali_kelas'));

        $response = $this->actingAs($this->admin, 'web')
            ->withSession($this->adminSession())
            ->get(route('subject.create'));

        $response->assertOk();
        $this->assertTeacherOptionState($response, $this->budi, true, [$this->kelas5AId]);
        $this->assertTeacherOptionState($response, $this->ani, true, [$this->kelas5BId]);
        $this->assertTeacherOptionState($response, $this->yusuf, false, []);
        $this->assertTeacherOptionState($response, $historicalWali, false, []);
        $this->assertTeacherOptionState($response, $activeWaliWithGuruLabel, true, [$activeClassId]);
    }

    public function test_edit_page_teacher_options_use_the_same_active_year_eligibility_metadata(): void
    {
        $subjectId = DB::table('mata_pelajarans')->insertGetId([
            'nama_pelajaran' => 'PAI',
            'kelas_id' => $this->kelas5AId,
            'guru_id' => $this->yusuf->id,
            'semester' => 1,
            'is_muatan_lokal' => false,
            'allow_non_wali' => true,
            'tahun_ajaran_id' => $this->yearId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('lingkup_materis')->insert([
            'mata_pelajaran_id' => $subjectId,
            'judul_lingkup_materi' => 'Materi demo',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->actingAs($this->admin, 'web')
            ->withSession($this->adminSession())
            ->get(route('subject.edit', $subjectId));

        $response->assertOk();
        $this->assertTeacherOptionState($response, $this->budi, true, [$this->kelas5AId]);
        $this->assertTeacherOptionState($response, $this->ani, true, [$this->kelas5BId]);
        $this->assertTeacherOptionState($response, $this->yusuf, false, []);
    }

    private function postSubject(string $name, int $kelasId, int $guruId, string $teachingType)
    {
        return $this->actingAs($this->admin, 'web')
            ->withSession($this->adminSession())
            ->from(route('subject.create'))
            ->post(route('subject.store'), [
                'subjects' => [[
                    'mata_pelajaran' => $name,
                    'kelas' => $kelasId,
                    'guru_pengampu' => $guruId,
                    'semester' => 1,
                    'teaching_type' => $teachingType,
                    'lingkup_materi' => ['Materi demo'],
                ]],
            ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function adminSession(): array
    {
        return [
            'tahun_ajaran_id' => $this->yearId,
            'selected_semester' => 1,
            'no_tahun_ajaran' => false,
        ];
    }

    /**
     * @param  array<int, int>  $waliClassIds
     */
    private function assertTeacherOptionState($response, Guru $teacher, bool $isActiveWali, array $waliClassIds): void
    {
        $html = $response->getContent();
        $expectedWaliState = $isActiveWali ? 'true' : 'false';
        $expectedClassIds = implode(',', $waliClassIds);

        $this->assertMatchesRegularExpression(
            '/<option[^>]*value="'.preg_quote((string) $teacher->id, '/').'"[^>]*data-is-active-wali="'.$expectedWaliState.'"[^>]*data-wali-kelas-ids="'.preg_quote($expectedClassIds, '/').'"[^>]*>/',
            $html,
            "Teacher option metadata was not correct for {$teacher->nama}."
        );
    }

    private function createSchema(): void
    {
        foreach ([
            'audit_logs',
            'tujuan_pembelajarans',
            'lingkup_materis',
            'mata_pelajarans',
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
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('tahun_ajarans', function (Blueprint $table) {
            $table->id();
            $table->string('tahun_ajaran');
            $table->boolean('is_active')->default(false);
            $table->integer('semester')->default(1);
            $table->date('tanggal_mulai')->nullable();
            $table->date('tanggal_selesai')->nullable();
            $table->text('deskripsi')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('profil_sekolah', function (Blueprint $table) {
            $table->id();
            $table->string('nama_sekolah')->nullable();
            $table->string('tahun_pelajaran')->nullable();
            $table->integer('semester')->nullable();
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
        });

        Schema::create('mata_pelajarans', function (Blueprint $table) {
            $table->id();
            $table->string('nama_pelajaran');
            $table->foreignId('kelas_id')->nullable();
            $table->foreignId('guru_id')->nullable();
            $table->integer('semester')->default(1);
            $table->boolean('is_muatan_lokal')->default(false);
            $table->boolean('allow_non_wali')->default(false);
            $table->foreignId('tahun_ajaran_id')->nullable();
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
            $table->string('kode_tp')->nullable();
            $table->text('deskripsi_tp')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    private function seedFixture(): void
    {
        $this->admin = User::create([
            'name' => 'Demo Admin',
            'username' => 'admin',
            'email' => 'admin@example.test',
            'password' => Hash::make('password'),
        ]);

        $this->yearId = $this->insertYear('2026/2027', true);
        $this->oldYearId = $this->insertYear('2025/2026', false);
        $this->kelas5AId = $this->insertClass(5, 'A', $this->yearId);
        $this->kelas5BId = $this->insertClass(5, 'B', $this->yearId);
        $this->oldKelas5AId = $this->insertClass(5, 'A', $this->oldYearId);

        $this->budi = Guru::findOrFail($this->insertGuru('Budi Santoso', 'budi', 'guru_wali'));
        $this->ani = Guru::findOrFail($this->insertGuru('Ani Rahmawati', 'ani', 'guru_wali'));
        $this->yusuf = Guru::findOrFail($this->insertGuru('Yusuf Hidayat', 'yusuf', 'guru'));

        DB::table('guru_kelas')->insert([
            $this->pivot($this->budi->id, $this->kelas5AId, true, 'wali_kelas'),
            $this->pivot($this->ani->id, $this->kelas5BId, true, 'wali_kelas'),
            $this->pivot($this->yusuf->id, $this->kelas5AId, false, 'pengajar'),
            $this->pivot($this->yusuf->id, $this->kelas5BId, false, 'pengajar'),
        ]);

        DB::table('profil_sekolah')->insert([
            'nama_sekolah' => 'SDIT Al Hidayah',
            'tahun_pelajaran' => '2026/2027',
            'semester' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function insertYear(string $year, bool $active): int
    {
        return DB::table('tahun_ajarans')->insertGetId([
            'tahun_ajaran' => $year,
            'semester' => 1,
            'is_active' => $active,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function insertClass(int $number, string $name, int $yearId): int
    {
        return DB::table('kelas')->insertGetId([
            'nomor_kelas' => $number,
            'nama_kelas' => $name,
            'tahun_ajaran_id' => $yearId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function insertGuru(string $name, string $username, string $jabatan): int
    {
        return DB::table('gurus')->insertGetId([
            'nama' => $name,
            'email' => "{$username}@example.test",
            'username' => $username,
            'jabatan' => $jabatan,
            'password' => Hash::make('password'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function pivot(int $guruId, int $kelasId, bool $isWali, string $role): array
    {
        return [
            'guru_id' => $guruId,
            'kelas_id' => $kelasId,
            'is_wali_kelas' => $isWali,
            'role' => $role,
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }
}

<?php

namespace Tests\Feature;

use App\Models\Guru;
use App\Models\MataPelajaran;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class LearningStructureCopyPembelajaranTest extends TestCase
{
    private User $admin;

    private Guru $budi;

    private Guru $ani;

    private int $yearId;

    private int $oldYearId;

    private int $kelas1UbayId;

    private int $kelas1ZaidId;

    private int $kelas2UbayId;

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

    public function test_admin_create_subject_shows_inline_copy_checkbox_when_safe_parallel_source_exists(): void
    {
        $sourceSubjectId = $this->insertSubject('Matematika', $this->budi->id, $this->kelas1UbayId);
        $this->insertLmWithTp($sourceSubjectId, 'Bilangan Cacah', [
            ['TP 1', 'Mengenal bilangan sampai 20'],
        ]);

        $this->actingAs($this->admin, 'web')
            ->withSession($this->adminSession())
            ->get(route('subject.create'))
            ->assertOk()
            ->assertSeeText('Salin LM dan TP dari mata pelajaran yang sama di kelas paralel')
            ->assertSeeText('Yang disalin hanya Lingkup Materi dan Tujuan Pembelajaran.')
            ->assertSee('Kelas 1 Ubay - Matematika', false);
    }

    public function test_admin_create_subject_hides_inline_copy_checkbox_when_no_source_exists(): void
    {
        $this->actingAs($this->admin, 'web')
            ->withSession($this->adminSession())
            ->get(route('subject.create'))
            ->assertOk()
            ->assertDontSeeText('Salin LM dan TP dari mata pelajaran yang sama di kelas paralel');
    }

    public function test_admin_create_subject_with_inline_checkbox_copies_lm_tp_after_save_without_manual_lm(): void
    {
        $sourceSubjectId = $this->insertSubject('Matematika', $this->budi->id, $this->kelas1UbayId);
        $this->insertLmWithTp($sourceSubjectId, 'Bilangan Cacah', [
            ['TP 1', 'Mengenal bilangan sampai 20'],
            ['TP 2', 'Membandingkan bilangan sampai 20'],
        ]);
        $this->insertLmWithTp($sourceSubjectId, 'Pengukuran', [
            ['TP 1', 'Mengenal panjang benda'],
        ]);
        $this->insertNilaiForSubject($sourceSubjectId);

        $this->actingAs($this->admin, 'web')
            ->withSession($this->adminSession())
            ->post(route('subject.store'), [
                'subjects' => [
                    [
                        'mata_pelajaran' => 'Matematika',
                        'kelas' => $this->kelas1ZaidId,
                        'guru_pengampu' => $this->budi->id,
                        'semester' => 1,
                        'teaching_type' => 'specialist',
                        'lingkup_materi' => [''],
                        'copy_lm_tp' => '1',
                        'copy_lm_tp_source_id' => $sourceSubjectId,
                    ],
                ],
            ])
            ->assertRedirect(route('subject.index'))
            ->assertSessionHas('success');

        $targetSubjectId = $this->subjectId('Matematika', $this->kelas1ZaidId);

        $this->assertSame(2, $this->lmCountForSubject($targetSubjectId));
        $this->assertSame(3, $this->tpCountForSubject($targetSubjectId));
        $this->assertDatabaseHas('lingkup_materis', [
            'mata_pelajaran_id' => $targetSubjectId,
            'judul_lingkup_materi' => 'Pengukuran',
        ]);
        $this->assertDatabaseHas('tujuan_pembelajarans', [
            'deskripsi_tp' => 'Membandingkan bilangan sampai 20',
        ]);
        $this->assertSame(1, DB::table('nilais')->count(), 'Copy must not duplicate or mutate Nilai rows.');
        $this->assertFalse(DB::table('nilais')->where('mata_pelajaran_id', $targetSubjectId)->exists());
    }

    public function test_admin_cannot_create_subject_with_empty_manual_lm_when_copy_is_not_checked(): void
    {
        $this->actingAs($this->admin, 'web')
            ->withSession($this->adminSession())
            ->from(route('subject.create'))
            ->post(route('subject.store'), [
                'subjects' => [
                    [
                        'mata_pelajaran' => 'Matematika',
                        'kelas' => $this->kelas1ZaidId,
                        'guru_pengampu' => $this->budi->id,
                        'semester' => 1,
                        'teaching_type' => 'specialist',
                        'lingkup_materi' => [''],
                    ],
                ],
            ])
            ->assertRedirect(route('subject.create'))
            ->assertSessionHasErrors(['subjects.0.lingkup_materi']);

        $this->assertFalse(DB::table('mata_pelajarans')
            ->where('nama_pelajaran', 'Matematika')
            ->where('kelas_id', $this->kelas1ZaidId)
            ->exists());
    }

    public function test_admin_edit_subject_with_inline_checkbox_copies_only_missing_lm_tp(): void
    {
        $sourceSubjectId = $this->insertSubject('Matematika', $this->budi->id, $this->kelas1UbayId);
        $targetSubjectId = $this->insertSubject('Matematika', $this->budi->id, $this->kelas1ZaidId);
        $this->insertLmWithTp($sourceSubjectId, 'Bilangan', [
            ['TP 1', 'Mengenal bilangan sumber'],
            ['TP 2', 'Membandingkan bilangan'],
        ]);
        $this->insertLmWithTp($sourceSubjectId, 'Pengukuran', [
            ['TP 1', 'Mengenal panjang'],
        ]);
        $this->insertLmWithTp($targetSubjectId, 'Bilangan', [
            ['TP 1', 'Deskripsi target tetap dipertahankan'],
        ]);

        $this->actingAs($this->admin, 'web')
            ->withSession($this->adminSession())
            ->get(route('subject.edit', $targetSubjectId))
            ->assertOk()
            ->assertSeeText('Salin LM dan TP dari mata pelajaran yang sama di kelas paralel')
            ->assertSee('Kelas 1 Ubay - Matematika', false);

        $this->actingAs($this->admin, 'web')
            ->withSession($this->adminSession())
            ->put(route('subject.update', $targetSubjectId), [
                'mata_pelajaran' => 'Matematika',
                'kelas' => $this->kelas1ZaidId,
                'guru_pengampu' => $this->budi->id,
                'semester' => 1,
                'teaching_type' => 'specialist',
                'lingkup_materi' => ['Bilangan'],
                'copy_lm_tp' => '1',
                'copy_lm_tp_source_id' => $sourceSubjectId,
            ])
            ->assertRedirect(route('subject.index'))
            ->assertSessionHas('success');

        $this->assertSame(2, $this->lmCountForSubject($targetSubjectId));
        $this->assertSame(3, $this->tpCountForSubject($targetSubjectId));
        $this->assertDatabaseHas('tujuan_pembelajarans', [
            'kode_tp' => 'TP 1',
            'deskripsi_tp' => 'Deskripsi target tetap dipertahankan',
        ]);
        $this->assertFalse(
            $this->targetSubjectHasTpDescription($targetSubjectId, 'Mengenal bilangan sumber'),
            'Duplicate TP from source should not overwrite or duplicate existing target TP.'
        );
        $this->assertTrue($this->targetSubjectHasTpDescription($targetSubjectId, 'Membandingkan bilangan'));
    }

    public function test_pengajar_edit_subject_shows_and_applies_inline_copy_only_for_authorized_parallel_source(): void
    {
        $sourceSubjectId = $this->insertSubject('Matematika', $this->budi->id, $this->kelas1UbayId);
        $targetSubjectId = $this->insertSubject('Matematika', $this->budi->id, $this->kelas1ZaidId);
        $this->insertLmWithTp($sourceSubjectId, 'Bilangan', [
            ['TP 1', 'Menjelaskan bilangan'],
        ]);

        $this->actingAs($this->budi, 'guru')
            ->withSession($this->pengajarSession())
            ->get(route('pengajar.subject.edit', $targetSubjectId))
            ->assertOk()
            ->assertSeeText('Salin LM dan TP dari mata pelajaran yang sama di kelas paralel')
            ->assertSee('Kelas 1 Ubay - Matematika', false);

        $this->actingAs($this->budi, 'guru')
            ->withSession($this->pengajarSession())
            ->put(route('pengajar.subject.update', $targetSubjectId), [
                'mata_pelajaran' => 'Matematika',
                'kelas' => $this->kelas1ZaidId,
                'semester' => 1,
                'teaching_type' => 'specialist',
                'lingkup_materi' => [''],
                'copy_lm_tp' => '1',
                'copy_lm_tp_source_id' => $sourceSubjectId,
            ])
            ->assertRedirect(route('pengajar.subject.index'))
            ->assertSessionHas('success');

        $this->assertSame(1, $this->lmCountForSubject($targetSubjectId));
        $this->assertSame(1, $this->tpCountForSubject($targetSubjectId));
    }

    public function test_pengajar_cannot_copy_from_another_teacher_subject(): void
    {
        $targetSubjectId = $this->insertSubject('Matematika', $this->budi->id, $this->kelas1ZaidId);
        $otherTeacherSourceId = $this->insertSubject('Matematika', $this->ani->id, $this->kelas1UbayId);
        $this->insertLmWithTp($targetSubjectId, 'Target', []);
        $this->insertLmWithTp($otherTeacherSourceId, 'Geometri', [
            ['TP 1', 'Mengenal bangun datar'],
        ]);

        $this->actingAs($this->budi, 'guru')
            ->withSession($this->pengajarSession())
            ->from(route('pengajar.subject.edit', $targetSubjectId))
            ->put(route('pengajar.subject.update', $targetSubjectId), [
                'mata_pelajaran' => 'Matematika',
                'kelas' => $this->kelas1ZaidId,
                'semester' => 1,
                'teaching_type' => 'specialist',
                'lingkup_materi' => [''],
                'copy_lm_tp' => '1',
                'copy_lm_tp_source_id' => $otherTeacherSourceId,
            ])
            ->assertRedirect(route('pengajar.subject.edit', $targetSubjectId))
            ->assertSessionHasErrors(['copy_lm_tp_source_id']);

        $this->assertSame(1, $this->lmCountForSubject($targetSubjectId));
        $this->assertSame(0, $this->tpCountForSubject($targetSubjectId));
    }

    public function test_copy_is_blocked_for_different_subject_year_semester_or_grade_contexts(): void
    {
        $targetSubjectId = $this->insertSubject('Matematika', $this->budi->id, $this->kelas1ZaidId);
        $this->insertLmWithTp($targetSubjectId, 'Target', []);

        $differentYearClassId = $this->insertClass(1, 'Ubay', $this->oldYearId);
        $blockedSourceIds = [
            $this->insertSubjectWithStructure('Bahasa Indonesia', $this->kelas1UbayId, 1, $this->yearId),
            $this->insertSubjectWithStructure('Matematika', $this->kelas1UbayId, 2, $this->yearId),
            $this->insertSubjectWithStructure('Matematika', $this->kelas2UbayId, 1, $this->yearId),
            $this->insertSubjectWithStructure('Matematika', $differentYearClassId, 1, $this->oldYearId),
        ];

        foreach ($blockedSourceIds as $sourceSubjectId) {
            $this->actingAs($this->admin, 'web')
                ->withSession($this->adminSession())
                ->from(route('subject.edit', $targetSubjectId))
                ->put(route('subject.update', $targetSubjectId), [
                    'mata_pelajaran' => 'Matematika',
                    'kelas' => $this->kelas1ZaidId,
                    'guru_pengampu' => $this->budi->id,
                    'semester' => 1,
                    'teaching_type' => 'specialist',
                    'lingkup_materi' => ['Target'],
                    'copy_lm_tp' => '1',
                    'copy_lm_tp_source_id' => $sourceSubjectId,
                ])
                ->assertRedirect(route('subject.edit', $targetSubjectId))
                ->assertSessionHasErrors(['copy_lm_tp_source_id']);

            $this->assertSame(1, $this->lmCountForSubject($targetSubjectId));
            $this->assertSame(0, $this->tpCountForSubject($targetSubjectId));
        }
    }

    public function test_old_separate_copy_buttons_and_routes_are_not_exposed(): void
    {
        $subjectId = $this->insertSubject('Matematika', $this->budi->id, $this->kelas1UbayId);
        $this->insertLmWithTp($subjectId, 'Bilangan', [
            ['TP 1', 'Mengenal bilangan'],
        ]);

        $this->assertFalse(Route::has('subject.copy_lm_tp'));
        $this->assertFalse(Route::has('subject.copy_lm_tp.apply'));
        $this->assertFalse(Route::has('pengajar.subject.copy_lm_tp'));
        $this->assertFalse(Route::has('pengajar.subject.copy_lm_tp.apply'));

        $this->actingAs($this->admin, 'web')
            ->withSession($this->adminSession())
            ->get(route('subject.index'))
            ->assertOk()
            ->assertDontSee('copy-lm-tp', false)
            ->assertDontSeeText('Salin LM/TP');

        $this->actingAs($this->budi, 'guru')
            ->withSession($this->pengajarSession())
            ->get(route('pengajar.subject.index'))
            ->assertOk()
            ->assertDontSee('copy-lm-tp', false)
            ->assertDontSeeText('Salin LM/TP');
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
     * @return array<string, mixed>
     */
    private function pengajarSession(): array
    {
        return [
            'tahun_ajaran_id' => $this->yearId,
            'selected_semester' => 1,
            'selected_role' => 'pengajar',
            'no_tahun_ajaran' => false,
        ];
    }

    private function createSchema(): void
    {
        foreach ([
            'audit_logs',
            'notifications',
            'nilais',
            'tujuan_pembelajarans',
            'lingkup_materis',
            'kkms',
            'bobot_nilais',
            'mata_pelajarans',
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
            $table->string('email')->nullable()->unique();
            $table->string('username')->nullable()->unique();
            $table->string('password');
            $table->string('jabatan')->nullable();
            $table->string('status')->nullable();
            $table->boolean('must_change_password')->default(false);
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

        Schema::create('siswas', function (Blueprint $table) {
            $table->id();
            $table->string('nis')->nullable();
            $table->string('nisn')->nullable();
            $table->string('nama');
            $table->foreignId('kelas_id')->nullable();
            $table->timestamps();
            $table->softDeletes();
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
            $table->foreignId('tahun_ajaran_id')->nullable();
            $table->integer('nilai')->default(70);
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
            $table->string('kode_tp')->nullable();
            $table->text('deskripsi_tp')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('nilais', function (Blueprint $table) {
            $table->id();
            $table->foreignId('siswa_id')->nullable();
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
    }

    private function seedFixture(): void
    {
        $this->admin = User::create([
            'name' => 'Admin Sekolah',
            'username' => 'admin',
            'email' => 'admin@example.test',
            'password' => Hash::make('password'),
        ]);

        $this->yearId = $this->insertYear('2026/2027', true);
        $this->oldYearId = $this->insertYear('2025/2026', false);
        $this->kelas1UbayId = $this->insertClass(1, 'Ubay', $this->yearId);
        $this->kelas1ZaidId = $this->insertClass(1, 'Zaid', $this->yearId);
        $this->kelas2UbayId = $this->insertClass(2, 'Ubay', $this->yearId);

        $this->budi = Guru::create([
            'nama' => 'Budi Pengajar',
            'email' => 'budi@example.test',
            'username' => 'budi',
            'password' => Hash::make('password'),
            'jabatan' => 'guru',
            'status' => 'aktif',
        ]);
        $this->ani = Guru::create([
            'nama' => 'Ani Pengajar',
            'email' => 'ani@example.test',
            'username' => 'ani',
            'password' => Hash::make('password'),
            'jabatan' => 'guru',
            'status' => 'aktif',
        ]);

        DB::table('guru_kelas')->insert([
            $this->pivot($this->budi->id, $this->kelas1UbayId),
            $this->pivot($this->budi->id, $this->kelas1ZaidId),
            $this->pivot($this->budi->id, $this->kelas2UbayId),
            $this->pivot($this->ani->id, $this->kelas1ZaidId),
            $this->pivot($this->ani->id, $this->kelas1UbayId),
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

    private function insertSubject(string $name, int $guruId, int $classId, int $semester = 1, ?int $yearId = null): int
    {
        return DB::table('mata_pelajarans')->insertGetId([
            'nama_pelajaran' => $name,
            'kelas_id' => $classId,
            'guru_id' => $guruId,
            'semester' => $semester,
            'is_muatan_lokal' => false,
            'allow_non_wali' => true,
            'tahun_ajaran_id' => $yearId ?? $this->yearId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function insertSubjectWithStructure(string $name, int $classId, int $semester, int $yearId): int
    {
        $subjectId = $this->insertSubject($name, $this->budi->id, $classId, $semester, $yearId);
        $this->insertLmWithTp($subjectId, 'Materi Sumber', [
            ['TP 1', 'Tidak boleh tersalin'],
        ]);

        return $subjectId;
    }

    /**
     * @param  array<int, array{0: string, 1: string}>  $tujuanPembelajarans
     */
    private function insertLmWithTp(int $subjectId, string $title, array $tujuanPembelajarans): int
    {
        $lingkupMateriId = DB::table('lingkup_materis')->insertGetId([
            'mata_pelajaran_id' => $subjectId,
            'judul_lingkup_materi' => $title,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        foreach ($tujuanPembelajarans as [$kodeTp, $deskripsiTp]) {
            DB::table('tujuan_pembelajarans')->insert([
                'lingkup_materi_id' => $lingkupMateriId,
                'kode_tp' => $kodeTp,
                'deskripsi_tp' => $deskripsiTp,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return $lingkupMateriId;
    }

    private function insertNilaiForSubject(int $subjectId): void
    {
        $studentId = DB::table('siswas')->insertGetId([
            'nis' => '1001',
            'nisn' => '1001001',
            'nama' => 'Ahmad Fajar',
            'kelas_id' => $this->kelas1UbayId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('nilais')->insert([
            'siswa_id' => $studentId,
            'mata_pelajaran_id' => $subjectId,
            'nilai_akhir_rapor' => 88,
            'is_submitted' => true,
            'tahun_ajaran_id' => $this->yearId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function subjectId(string $subjectName, int $classId): int
    {
        return (int) DB::table('mata_pelajarans')
            ->where('nama_pelajaran', $subjectName)
            ->where('kelas_id', $classId)
            ->whereNull('deleted_at')
            ->value('id');
    }

    private function lmCountForSubject(int $subjectId): int
    {
        return DB::table('lingkup_materis')
            ->where('mata_pelajaran_id', $subjectId)
            ->whereNull('deleted_at')
            ->count();
    }

    private function tpCountForSubject(int $subjectId): int
    {
        return DB::table('tujuan_pembelajarans')
            ->join('lingkup_materis', 'tujuan_pembelajarans.lingkup_materi_id', '=', 'lingkup_materis.id')
            ->where('lingkup_materis.mata_pelajaran_id', $subjectId)
            ->whereNull('lingkup_materis.deleted_at')
            ->whereNull('tujuan_pembelajarans.deleted_at')
            ->count();
    }

    private function targetSubjectHasTpDescription(int $subjectId, string $description): bool
    {
        return DB::table('tujuan_pembelajarans')
            ->join('lingkup_materis', 'tujuan_pembelajarans.lingkup_materi_id', '=', 'lingkup_materis.id')
            ->where('lingkup_materis.mata_pelajaran_id', $subjectId)
            ->where('tujuan_pembelajarans.deskripsi_tp', $description)
            ->whereNull('lingkup_materis.deleted_at')
            ->whereNull('tujuan_pembelajarans.deleted_at')
            ->exists();
    }

    /**
     * @return array<string, mixed>
     */
    private function pivot(int $guruId, int $kelasId): array
    {
        return [
            'guru_id' => $guruId,
            'kelas_id' => $kelasId,
            'is_wali_kelas' => false,
            'role' => 'pengajar',
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }
}

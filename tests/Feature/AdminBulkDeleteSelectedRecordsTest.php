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

class AdminBulkDeleteSelectedRecordsTest extends TestCase
{
    private User $admin;

    private Guru $wali;

    private int $yearId;

    private int $classAId;

    private int $classBId;

    private int $studentAId;

    private int $studentBId;

    private int $subjectAId;

    private int $subjectBId;

    private int $ekskulAId;

    private int $ekskulBId;

    private int $prestasiAId;

    private int $prestasiBId;

    private int $teacherBId;

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

    public function test_admin_can_bulk_delete_selected_siswa_with_soft_delete(): void
    {
        $this->actingAsAdmin()
            ->delete(route('admin.bulk-delete', 'students'), [
                'ids' => [$this->studentAId, $this->studentBId],
            ])
            ->assertRedirect(route('student'))
            ->assertSessionHas('success');

        $this->assertSoftDeletedRow('siswas', $this->studentAId);
        $this->assertSoftDeletedRow('siswas', $this->studentBId);
    }

    public function test_admin_can_bulk_delete_selected_prestasi_with_soft_delete(): void
    {
        $this->actingAsAdmin()
            ->delete(route('admin.bulk-delete', 'achievements'), [
                'ids' => [$this->prestasiAId, $this->prestasiBId],
            ])
            ->assertRedirect(route('achievement.index'))
            ->assertSessionHas('success');

        $this->assertSoftDeletedRow('prestasis', $this->prestasiAId);
        $this->assertSoftDeletedRow('prestasis', $this->prestasiBId);
    }

    public function test_admin_can_bulk_delete_selected_ekstrakurikuler_with_soft_delete(): void
    {
        $this->actingAsAdmin()
            ->delete(route('admin.bulk-delete', 'ekstrakurikulers'), [
                'ids' => [$this->ekskulAId, $this->ekskulBId],
            ])
            ->assertRedirect(route('ekstra.index'))
            ->assertSessionHas('success');

        $this->assertSoftDeletedRow('ekstrakurikulers', $this->ekskulAId);
        $this->assertSoftDeletedRow('ekstrakurikulers', $this->ekskulBId);
    }

    public function test_admin_can_bulk_delete_selected_mata_pelajaran_when_single_delete_allows_it(): void
    {
        $this->actingAsAdmin()
            ->delete(route('admin.bulk-delete', 'subjects'), [
                'ids' => [$this->subjectAId, $this->subjectBId],
            ])
            ->assertRedirect(route('subject.index'))
            ->assertSessionHas('success');

        $this->assertSoftDeletedRow('mata_pelajarans', $this->subjectAId);
        $this->assertSoftDeletedRow('mata_pelajarans', $this->subjectBId);
    }

    public function test_admin_can_bulk_delete_selected_kelas_when_single_delete_allows_it(): void
    {
        $emptyClassA = $this->insertClass(3, 'A');
        $emptyClassB = $this->insertClass(3, 'B');

        $this->actingAsAdmin()
            ->delete(route('admin.bulk-delete', 'kelas'), [
                'ids' => [$emptyClassA, $emptyClassB],
            ])
            ->assertRedirect(route('kelas.index'))
            ->assertSessionHas('success');

        $this->assertSoftDeletedRow('kelas', $emptyClassA);
        $this->assertSoftDeletedRow('kelas', $emptyClassB);
    }

    public function test_admin_can_bulk_delete_selected_pengajar_with_soft_delete(): void
    {
        $teacherCId = $this->insertTeacher('Cici Pengajar', 'cici');

        $this->actingAsAdmin()
            ->delete(route('admin.bulk-delete', 'teachers'), [
                'ids' => [$this->teacherBId, $teacherCId],
            ])
            ->assertRedirect(route('teacher'))
            ->assertSessionHas('success');

        $this->assertSoftDeletedRow('gurus', $this->teacherBId);
        $this->assertSoftDeletedRow('gurus', $teacherCId);
    }

    public function test_bulk_delete_does_not_permanently_delete_records(): void
    {
        $this->actingAsAdmin()
            ->delete(route('admin.bulk-delete', 'students'), [
                'ids' => [$this->studentAId],
            ])
            ->assertRedirect(route('student'));

        $this->assertDatabaseHas('siswas', ['id' => $this->studentAId]);
        $this->assertSoftDeletedRow('siswas', $this->studentAId);
    }

    public function test_bulk_delete_invalid_type_is_not_available(): void
    {
        $this->actingAsAdmin()
            ->delete('/admin/bulk-delete/users', [
                'ids' => [$this->studentAId],
            ])
            ->assertStatus(405);

        $this->assertNull(DB::table('siswas')->where('id', $this->studentAId)->value('deleted_at'));
    }

    public function test_non_admin_cannot_access_bulk_delete_endpoint(): void
    {
        $this->actingAs($this->wali, 'guru')
            ->withSession($this->waliSession())
            ->deleteJson(route('admin.bulk-delete', 'students'), [
                'ids' => [$this->studentAId],
            ])
            ->assertUnauthorized();

        $this->assertNull(DB::table('siswas')->where('id', $this->studentAId)->value('deleted_at'));
    }

    public function test_wali_kelas_student_page_does_not_render_bulk_delete_controls(): void
    {
        $this->actingAs($this->wali, 'guru')
            ->withSession($this->waliSession())
            ->get(route('wali_kelas.student.index'))
            ->assertOk()
            ->assertDontSee('data-bulk-delete', false)
            ->assertDontSee('data-bulk-delete-checkbox', false)
            ->assertDontSee('Hapus Terpilih');
    }

    public function test_admin_live_list_pages_render_bulk_delete_controls_and_action_bypass_remains(): void
    {
        foreach ([
            route('kelas.index'),
            route('teacher'),
            route('student'),
            route('subject.index'),
            route('ekstra.index'),
            route('achievement.index'),
        ] as $url) {
            $response = $this->actingAsAdmin()
                ->get($url)
                ->assertOk()
                ->assertSee('Hapus Terpilih')
                ->assertSee('data-bulk-delete', false)
                ->assertSee('data-bulk-delete-checkbox', false)
                ->assertSee('data-bulk-delete-select-all', false)
                ->assertSee('data-live-list-ignore', false);
        }
    }

    public function test_partial_failure_returns_friendly_summary(): void
    {
        $this->actingAsAdmin()
            ->delete(route('admin.bulk-delete', 'students'), [
                'ids' => [$this->studentAId, 999999],
            ])
            ->assertRedirect(route('student'))
            ->assertSessionHas('warning', function (string $message): bool {
                return str_contains($message, '1 data Siswa berhasil dihapus.')
                    && str_contains($message, '1 data tidak dapat dihapus');
            });

        $this->assertSoftDeletedRow('siswas', $this->studentAId);
        $this->assertNull(DB::table('siswas')->where('id', $this->studentBId)->value('deleted_at'));
    }

    private function actingAsAdmin()
    {
        return $this->actingAs($this->admin, 'web')->withSession($this->adminSession());
    }

    private function adminSession(): array
    {
        return [
            'tahun_ajaran_id' => $this->yearId,
            'selected_semester' => 1,
        ];
    }

    private function waliSession(): array
    {
        return [
            'tahun_ajaran_id' => $this->yearId,
            'selected_semester' => 1,
            'selected_role' => 'wali_kelas',
        ];
    }

    private function assertSoftDeletedRow(string $table, int $id): void
    {
        $this->assertDatabaseHas($table, ['id' => $id]);
        $this->assertNotNull(DB::table($table)->where('id', $id)->value('deleted_at'));
    }

    private function createSchema(): void
    {
        foreach ([
            'audit_logs',
            'nilai_ekstrakurikuler',
            'nilais',
            'absensis',
            'prestasis',
            'ekstrakurikulers',
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
            $table->string('name')->nullable();
            $table->string('username')->nullable()->unique();
            $table->string('email')->nullable()->unique();
            $table->string('password');
            $table->timestamps();
        });

        Schema::create('gurus', function (Blueprint $table) {
            $table->id();
            $table->string('nuptk')->nullable();
            $table->string('nama');
            $table->string('jenis_kelamin')->nullable();
            $table->date('tanggal_lahir')->nullable();
            $table->string('no_handphone')->nullable();
            $table->string('email')->nullable();
            $table->text('alamat')->nullable();
            $table->string('jabatan')->nullable();
            $table->string('username')->nullable();
            $table->string('password');
            $table->string('password_plain')->nullable();
            $table->boolean('must_change_password')->default(false);
            $table->string('photo')->nullable();
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
            $table->string('nis')->unique();
            $table->string('nisn')->unique();
            $table->string('nama');
            $table->string('jenis_kelamin')->nullable();
            $table->foreignId('kelas_id')->nullable();
            $table->foreignId('tahun_ajaran_id')->nullable();
            $table->string('photo')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('siswa_kelas_semester', function (Blueprint $table) {
            $table->id();
            $table->foreignId('siswa_id');
            $table->foreignId('kelas_id');
            $table->foreignId('tahun_ajaran_id');
            $table->unsignedTinyInteger('semester');
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

        Schema::create('nilais', function (Blueprint $table) {
            $table->id();
            $table->foreignId('siswa_id')->nullable();
            $table->foreignId('mata_pelajaran_id')->nullable();
            $table->foreignId('tujuan_pembelajaran_id')->nullable();
            $table->foreignId('lingkup_materi_id')->nullable();
            $table->decimal('nilai_tp', 5, 2)->nullable();
            $table->foreignId('tahun_ajaran_id')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('ekstrakurikulers', function (Blueprint $table) {
            $table->id();
            $table->string('nama_ekstrakurikuler');
            $table->string('pembina');
            $table->foreignId('tahun_ajaran_id')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('nilai_ekstrakurikuler', function (Blueprint $table) {
            $table->id();
            $table->foreignId('siswa_id')->nullable();
            $table->foreignId('ekstrakurikuler_id')->nullable();
            $table->text('nilai')->nullable();
            $table->foreignId('tahun_ajaran_id')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('prestasis', function (Blueprint $table) {
            $table->id();
            $table->foreignId('kelas_id');
            $table->foreignId('siswa_id');
            $table->string('jenis_prestasi');
            $table->text('keterangan')->nullable();
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
            $table->foreignId('tahun_ajaran_id')->nullable();
            $table->unsignedTinyInteger('semester')->default(1);
            $table->timestamps();
            $table->softDeletes();
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
    }

    private function seedFixture(): void
    {
        $this->admin = User::create([
            'name' => 'Admin',
            'username' => 'admin',
            'email' => 'admin@example.test',
            'password' => Hash::make('password'),
        ]);

        DB::table('profil_sekolah')->insert([
            'nama_sekolah' => 'SDIT Al Hidayah',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->yearId = DB::table('tahun_ajarans')->insertGetId([
            'tahun_ajaran' => '2026/2027',
            'is_active' => true,
            'semester' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->classAId = $this->insertClass(1, 'Ubay');
        $this->classBId = $this->insertClass(1, 'Zaid');

        $this->wali = Guru::create([
            'nama' => 'Budi Wali',
            'jenis_kelamin' => 'Laki-laki',
            'jabatan' => 'guru_wali',
            'username' => 'budi',
            'password' => Hash::make('password'),
        ]);

        $this->teacherBId = $this->insertTeacher('Sari Pengajar', 'sari');

        DB::table('guru_kelas')->insert([
            [
                'guru_id' => $this->wali->id,
                'kelas_id' => $this->classAId,
                'is_wali_kelas' => true,
                'role' => 'wali_kelas',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'guru_id' => $this->teacherBId,
                'kelas_id' => $this->classBId,
                'is_wali_kelas' => false,
                'role' => 'pengajar',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $this->studentAId = $this->insertStudent('2701001', '9100000001', 'Ahmad Fauzan', 'Laki-laki', $this->classAId);
        $this->studentBId = $this->insertStudent('2701002', '9100000002', 'Siti Aisyah', 'Perempuan', $this->classAId);

        $this->subjectAId = $this->insertSubject('Matematika', $this->wali->id, $this->classAId);
        $this->subjectBId = $this->insertSubject('Bahasa Indonesia', $this->teacherBId, $this->classBId);
        $this->insertLmTp($this->subjectAId);

        $this->ekskulAId = $this->insertEkstrakurikuler('Pramuka', 'Budi Wali');
        $this->ekskulBId = $this->insertEkstrakurikuler('Memanah', 'Sari Pengajar');

        $this->prestasiAId = $this->insertPrestasi($this->studentAId, 'Tahfidz');
        $this->prestasiBId = $this->insertPrestasi($this->studentBId, 'Sains');
    }

    private function insertClass(int $number, string $name): int
    {
        return DB::table('kelas')->insertGetId([
            'nomor_kelas' => $number,
            'nama_kelas' => $name,
            'tahun_ajaran_id' => $this->yearId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function insertTeacher(string $name, string $username): int
    {
        return DB::table('gurus')->insertGetId([
            'nama' => $name,
            'jenis_kelamin' => 'Perempuan',
            'jabatan' => 'guru',
            'username' => $username,
            'password' => Hash::make('password'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function insertStudent(string $nis, string $nisn, string $name, string $gender, int $classId): int
    {
        $studentId = DB::table('siswas')->insertGetId([
            'nis' => $nis,
            'nisn' => $nisn,
            'nama' => $name,
            'jenis_kelamin' => $gender,
            'kelas_id' => $classId,
            'tahun_ajaran_id' => $this->yearId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('siswa_kelas_semester')->insert([
            'siswa_id' => $studentId,
            'kelas_id' => $classId,
            'tahun_ajaran_id' => $this->yearId,
            'semester' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $studentId;
    }

    private function insertSubject(string $name, int $guruId, int $classId): int
    {
        return DB::table('mata_pelajarans')->insertGetId([
            'nama_pelajaran' => $name,
            'kelas_id' => $classId,
            'guru_id' => $guruId,
            'semester' => 1,
            'is_muatan_lokal' => false,
            'allow_non_wali' => false,
            'tahun_ajaran_id' => $this->yearId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function insertLmTp(int $subjectId): void
    {
        $lmId = DB::table('lingkup_materis')->insertGetId([
            'mata_pelajaran_id' => $subjectId,
            'judul_lingkup_materi' => 'Bilangan',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('tujuan_pembelajarans')->insert([
            'lingkup_materi_id' => $lmId,
            'kode_tp' => 'TP 1',
            'deskripsi_tp' => 'Mengenal bilangan',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function insertEkstrakurikuler(string $name, string $pembina): int
    {
        return DB::table('ekstrakurikulers')->insertGetId([
            'nama_ekstrakurikuler' => $name,
            'pembina' => $pembina,
            'tahun_ajaran_id' => $this->yearId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function insertPrestasi(int $studentId, string $type): int
    {
        return DB::table('prestasis')->insertGetId([
            'kelas_id' => $this->classAId,
            'siswa_id' => $studentId,
            'jenis_prestasi' => $type,
            'keterangan' => 'Juara '.$type,
            'tahun_ajaran_id' => $this->yearId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}

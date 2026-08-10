<?php

namespace Tests\Feature;

use App\Models\Kelas;
use App\Models\MataPelajaran;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ClassSubjectDeletionSafetyTest extends TestCase
{
    private User $admin;

    private int $yearId;

    private int $guruId;

    private int $classAId;

    private int $classBId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->withoutMiddleware(PreventRequestForgery::class);

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

    public function test_soft_deleting_class_keeps_subject_row_visible_for_admin(): void
    {
        $subjectId = $this->insertSubject('Matematika Kelas Terhapus', $this->classAId);
        $this->insertLingkupMateri($subjectId, 'Bilangan');

        $this->actingAs($this->admin, 'web')
            ->withSession($this->adminSession())
            ->delete(route('kelas.destroy', $this->classAId))
            ->assertRedirect(route('kelas.index'));

        $subject = MataPelajaran::withTrashed()->findOrFail($subjectId);

        $this->assertTrue(Kelas::onlyTrashed()->whereKey($this->classAId)->exists());
        $this->assertFalse($subject->trashed());
        $this->assertTrue(MataPelajaran::whereKey($subjectId)->exists());

        $this->actingAs($this->admin, 'web')
            ->withSession($this->adminSession())
            ->get(route('subject.index'))
            ->assertOk()
            ->assertSee('Matematika Kelas Terhapus')
            ->assertSee('Kelas tidak tersedia')
            ->assertSee('Bilangan');
    }

    public function test_deleting_one_class_does_not_remove_other_class_subject_or_assignment(): void
    {
        $deletedClassSubjectId = $this->insertSubject('IPA Bersama', $this->classAId);
        $survivingSubjectId = $this->insertSubject('IPA Bersama', $this->classBId);

        $this->actingAs($this->admin, 'web')
            ->withSession($this->adminSession())
            ->delete(route('kelas.destroy', $this->classAId))
            ->assertRedirect(route('kelas.index'));

        $this->assertFalse(MataPelajaran::withTrashed()->findOrFail($deletedClassSubjectId)->trashed());
        $this->assertFalse(MataPelajaran::withTrashed()->findOrFail($survivingSubjectId)->trashed());
        $this->assertTrue(DB::table('guru_kelas')->where('kelas_id', $this->classBId)->exists());
        $this->assertFalse(DB::table('guru_kelas')->where('kelas_id', $this->classAId)->exists());
    }

    public function test_restoring_class_makes_existing_subject_class_visible_again(): void
    {
        $subjectId = $this->insertSubject('Bahasa Indonesia Pulih', $this->classAId);

        Kelas::findOrFail($this->classAId)->delete();

        $subject = MataPelajaran::findOrFail($subjectId);
        $this->assertNull($subject->kelas);

        Kelas::onlyTrashed()->findOrFail($this->classAId)->restore();

        $restoredSubject = MataPelajaran::findOrFail($subjectId);
        $this->assertSame($this->classAId, (int) $restoredSubject->kelas?->id);

        $this->actingAs($this->admin, 'web')
            ->withSession($this->adminSession())
            ->get(route('subject.index'))
            ->assertOk()
            ->assertSee('Bahasa Indonesia Pulih')
            ->assertSee('1-A');
    }

    public function test_permanently_deleting_class_removes_class_owned_subject_rows_without_orphans(): void
    {
        $subjectId = $this->insertSubject('Mapel Kelas Permanen', $this->classAId);
        $lingkupMateriId = $this->insertLingkupMateri($subjectId, 'Materi ikut terhapus');

        Kelas::findOrFail($this->classAId)->delete();
        Kelas::onlyTrashed()->findOrFail($this->classAId)->forceDelete();

        $this->assertFalse(Kelas::withTrashed()->whereKey($this->classAId)->exists());
        $this->assertFalse(DB::table('mata_pelajarans')->where('id', $subjectId)->exists());
        $this->assertFalse(DB::table('lingkup_materis')->where('id', $lingkupMateriId)->exists());
        $this->assertFalse(DB::table('lingkup_materis')->where('mata_pelajaran_id', $subjectId)->exists());
    }

    private function createSchema(): void
    {
        Schema::disableForeignKeyConstraints();

        foreach ([
            'audit_logs',
            'nilais',
            'tujuan_pembelajarans',
            'lingkup_materis',
            'mata_pelajarans',
            'absensis',
            'prestasis',
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

        Schema::enableForeignKeyConstraints();

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
            $table->text('user_agent')->nullable();
            $table->timestamps();
        });

        Schema::create('gurus', function (Blueprint $table) {
            $table->id();
            $table->string('nama');
            $table->string('username')->nullable()->unique();
            $table->string('password');
            $table->string('jabatan')->nullable();
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
            $table->foreignId('tahun_ajaran_id')->nullable()->constrained('tahun_ajarans')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('guru_kelas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('guru_id')->constrained('gurus')->cascadeOnDelete();
            $table->foreignId('kelas_id')->constrained('kelas')->cascadeOnDelete();
            $table->boolean('is_wali_kelas')->default(false);
            $table->string('role')->default('pengajar');
            $table->timestamps();
        });

        Schema::create('siswas', function (Blueprint $table) {
            $table->id();
            $table->string('nis')->nullable();
            $table->string('nisn')->nullable();
            $table->string('nama')->nullable();
            $table->foreignId('kelas_id')->nullable()->constrained('kelas')->cascadeOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('prestasis', function (Blueprint $table) {
            $table->id();
            $table->foreignId('kelas_id')->nullable()->constrained('kelas')->cascadeOnDelete();
            $table->foreignId('siswa_id')->nullable()->constrained('siswas')->cascadeOnDelete();
            $table->string('jenis_prestasi')->nullable();
            $table->text('keterangan')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('absensis', function (Blueprint $table) {
            $table->id();
            $table->foreignId('siswa_id')->nullable()->constrained('siswas')->cascadeOnDelete();
            $table->integer('semester')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('mata_pelajarans', function (Blueprint $table) {
            $table->id();
            $table->string('nama_pelajaran');
            $table->foreignId('kelas_id')->constrained('kelas')->cascadeOnDelete();
            $table->foreignId('guru_id')->nullable()->constrained('gurus')->cascadeOnDelete();
            $table->integer('semester')->default(1);
            $table->boolean('is_muatan_lokal')->default(false);
            $table->boolean('allow_non_wali')->default(false);
            $table->foreignId('tahun_ajaran_id')->nullable()->constrained('tahun_ajarans')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('lingkup_materis', function (Blueprint $table) {
            $table->id();
            $table->foreignId('mata_pelajaran_id')->constrained('mata_pelajarans')->cascadeOnDelete();
            $table->string('judul_lingkup_materi');
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('tujuan_pembelajarans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lingkup_materi_id')->constrained('lingkup_materis')->cascadeOnDelete();
            $table->string('kode_tp')->nullable();
            $table->text('deskripsi_tp')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('nilais', function (Blueprint $table) {
            $table->id();
            $table->foreignId('siswa_id')->nullable()->constrained('siswas')->cascadeOnDelete();
            $table->foreignId('mata_pelajaran_id')->nullable()->constrained('mata_pelajarans')->cascadeOnDelete();
            $table->foreignId('lingkup_materi_id')->nullable()->constrained('lingkup_materis')->cascadeOnDelete();
            $table->foreignId('tujuan_pembelajaran_id')->nullable()->constrained('tujuan_pembelajarans')->cascadeOnDelete();
            $table->timestamps();
            $table->softDeletes();
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

        $this->guruId = DB::table('gurus')->insertGetId([
            'nama' => 'Guru Demo',
            'username' => 'guru-demo',
            'password' => Hash::make('password'),
            'jabatan' => 'guru',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->classAId = $this->insertClass(1, 'A');
        $this->classBId = $this->insertClass(1, 'B');

        DB::table('guru_kelas')->insert([
            $this->assignment($this->classAId),
            $this->assignment($this->classBId),
        ]);
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

    private function insertSubject(string $name, int $classId): int
    {
        return DB::table('mata_pelajarans')->insertGetId([
            'nama_pelajaran' => $name,
            'kelas_id' => $classId,
            'guru_id' => $this->guruId,
            'semester' => 1,
            'is_muatan_lokal' => false,
            'allow_non_wali' => false,
            'tahun_ajaran_id' => $this->yearId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function insertLingkupMateri(int $subjectId, string $title): int
    {
        return DB::table('lingkup_materis')->insertGetId([
            'mata_pelajaran_id' => $subjectId,
            'judul_lingkup_materi' => $title,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function assignment(int $classId): array
    {
        return [
            'guru_id' => $this->guruId,
            'kelas_id' => $classId,
            'is_wali_kelas' => false,
            'role' => 'pengajar',
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function adminSession(): array
    {
        return [
            'tahun_ajaran_id' => $this->yearId,
            'selected_semester' => 1,
        ];
    }
}

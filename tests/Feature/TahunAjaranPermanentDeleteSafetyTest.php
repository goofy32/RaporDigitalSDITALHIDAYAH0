<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class TahunAjaranPermanentDeleteSafetyTest extends TestCase
{
    private User $admin;

    private int $activeYearId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->withoutMiddleware(ValidateCsrfToken::class);
        Event::fake();

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

    public function test_permanent_delete_is_blocked_when_year_has_enrollment_rows(): void
    {
        $archivedYearId = $this->insertYear('2025/2026', 2, false, true);
        $classId = $this->insertClass($archivedYearId);
        $studentId = $this->insertStudent($classId, $archivedYearId);

        DB::table('siswa_kelas_semester')->insert([
            'siswa_id' => $studentId,
            'kelas_id' => $classId,
            'tahun_ajaran_id' => $archivedYearId,
            'semester' => 2,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->actingAs($this->admin, 'web')
            ->delete(route('tahun.ajaran.force-delete', $archivedYearId));

        $response->assertRedirect();
        $response->assertSessionHas('error');

        $message = session('error');
        $this->assertStringContainsString('tidak dapat dihapus permanen', $message);
        $this->assertStringContainsString('enrollment siswa (1)', $message);
        $this->assertStringNotContainsString('SQLSTATE', $message);
        $this->assertStringNotContainsString('foreign key constraint', strtolower($message));
        $this->assertNotNull(DB::table('tahun_ajarans')->where('id', $archivedYearId)->value('deleted_at'));
        $this->assertSame(1, DB::table('siswa_kelas_semester')->where('tahun_ajaran_id', $archivedYearId)->count());
    }

    public function test_permanent_delete_is_blocked_when_year_has_structural_dependencies(): void
    {
        $archivedYearId = $this->insertYear('2024/2025', 1, false, true);
        $classId = $this->insertClass($archivedYearId);

        DB::table('mata_pelajarans')->insert([
            'nama_pelajaran' => 'Matematika',
            'kelas_id' => $classId,
            'tahun_ajaran_id' => $archivedYearId,
            'semester' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->actingAs($this->admin, 'web')
            ->deleteJson(route('tahun.ajaran.force-delete', $archivedYearId));

        $response->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonFragment([
                'message' => 'Tahun ajaran ini tidak dapat dihapus permanen karena masih terhubung dengan alur akademik, siswa, nilai, atau rapor. Gunakan arsip sebagai penyimpanan aman, atau pulihkan jika masih diperlukan. Data terkait: kelas (1), mata pelajaran (1).',
            ]);

        $this->assertNotNull(DB::table('tahun_ajarans')->where('id', $archivedYearId)->value('deleted_at'));
        $this->assertSame(1, DB::table('kelas')->where('tahun_ajaran_id', $archivedYearId)->count());
        $this->assertSame(1, DB::table('mata_pelajarans')->where('tahun_ajaran_id', $archivedYearId)->count());
    }

    public function test_protected_archived_year_renders_protected_notice_without_force_delete_action(): void
    {
        $archivedYearId = $this->insertYear('2023/2024', 1, false, true);
        $this->insertClass($archivedYearId);

        $this->actingAs($this->admin, 'web')
            ->get(route('tahun.ajaran.index', ['showArchived' => 'true']))
            ->assertOk()
            ->assertSee('title="Pulihkan"', false)
            ->assertSeeText('Dilindungi')
            ->assertSeeText('Data tahun ajaran ini tidak dapat dihapus permanen karena masih terhubung dengan alur akademik.')
            ->assertDontSee('title="Hapus Permanen"', false);

        $this->actingAs($this->admin, 'web')
            ->get(route('tahun.ajaran.show', $archivedYearId))
            ->assertOk()
            ->assertSeeText('Pulihkan Tahun Ajaran')
            ->assertSeeText('Dilindungi')
            ->assertSeeText('Data tahun ajaran ini tidak dapat dihapus permanen karena masih terhubung dengan alur akademik.')
            ->assertDontSeeText('Hapus Permanen');
    }

    public function test_unprotected_archived_year_still_renders_force_delete_action(): void
    {
        $archivedYearId = $this->insertYear('2029/2030', 1, false, true);

        $this->actingAs($this->admin, 'web')
            ->get(route('tahun.ajaran.show', $archivedYearId))
            ->assertOk()
            ->assertSeeText('Pulihkan Tahun Ajaran')
            ->assertSeeText('Hapus Permanen')
            ->assertDontSeeText('Dilindungi');
    }

    public function test_archive_behavior_still_soft_deletes_inactive_year(): void
    {
        $inactiveYearId = $this->insertYear('2028/2029', 1, false, false);

        $this->actingAs($this->admin, 'web')
            ->delete(route('tahun.ajaran.destroy', $inactiveYearId))
            ->assertRedirect(route('tahun.ajaran.index'))
            ->assertSessionHas('success');

        $this->assertNotNull(DB::table('tahun_ajarans')->where('id', $inactiveYearId)->value('deleted_at'));
    }

    public function test_archived_year_without_dependencies_can_still_be_permanently_deleted(): void
    {
        $archivedYearId = $this->insertYear('2029/2030', 1, false, true);

        $this->actingAs($this->admin, 'web')
            ->delete(route('tahun.ajaran.force-delete', $archivedYearId))
            ->assertRedirect(route('tahun.ajaran.index', ['showArchived' => 'true']))
            ->assertSessionHas('success');

        $this->assertSame(0, DB::table('tahun_ajarans')->where('id', $archivedYearId)->count());
    }

    public function test_permanent_delete_requires_archived_year(): void
    {
        $inactiveYearId = $this->insertYear('2030/2031', 1, false, false);

        $this->actingAs($this->admin, 'web')
            ->delete(route('tahun.ajaran.force-delete', $inactiveYearId))
            ->assertRedirect()
            ->assertSessionHas('error', 'Hanya tahun ajaran yang sudah diarsipkan yang dapat dihapus permanen.');

        $this->assertSame(1, DB::table('tahun_ajarans')->where('id', $inactiveYearId)->count());
    }

    private function createSchema(): void
    {
        foreach ([
            'report_templates',
            'report_generations',
            'nilai_ekstrakurikuler',
            'capaian_custom',
            'catatan_mata_pelajaran',
            'catatan_siswa',
            'absensis',
            'nilais',
            'mata_pelajarans',
            'siswa_kelas_semester',
            'siswas',
            'kelas',
            'profil_sekolah',
            'tahun_ajarans',
            'users',
        ] as $table) {
            Schema::dropIfExists($table);
        }

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('username')->nullable();
            $table->string('email')->unique();
            $table->string('password');
            $table->timestamps();
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
            $table->string('nama_sekolah')->nullable();
            $table->string('tahun_pelajaran')->nullable();
            $table->integer('semester')->nullable();
            $table->timestamps();
        });

        Schema::create('kelas', function (Blueprint $table) {
            $table->id();
            $table->string('nomor_kelas');
            $table->string('nama_kelas');
            $table->foreignId('tahun_ajaran_id')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('siswas', function (Blueprint $table) {
            $table->id();
            $table->string('nis')->unique();
            $table->string('nisn')->unique();
            $table->string('nama');
            $table->foreignId('kelas_id')->nullable();
            $table->foreignId('tahun_ajaran_id')->nullable();
            $table->string('status')->default('aktif');
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
            $table->unique(['siswa_id', 'tahun_ajaran_id', 'semester'], 'siswa_kelas_semester_unique_context');
        });

        Schema::create('mata_pelajarans', function (Blueprint $table) {
            $table->id();
            $table->string('nama_pelajaran');
            $table->foreignId('kelas_id')->nullable();
            $table->foreignId('tahun_ajaran_id')->nullable();
            $table->integer('semester')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        foreach (['nilais', 'absensis', 'catatan_siswa', 'catatan_mata_pelajaran', 'capaian_custom', 'nilai_ekstrakurikuler', 'report_generations', 'report_templates'] as $tableName) {
            Schema::create($tableName, function (Blueprint $table) {
                $table->id();
                $table->foreignId('tahun_ajaran_id')->nullable();
                $table->timestamps();
                $table->softDeletes();
            });
        }
    }

    private function seedFixture(): void
    {
        $this->admin = User::create([
            'name' => 'Demo Admin',
            'username' => 'demo_admin',
            'email' => 'admin@example.test',
            'password' => Hash::make('password'),
        ]);

        $this->activeYearId = $this->insertYear('2026/2027', 2, true, false);

        DB::table('profil_sekolah')->insert([
            'nama_sekolah' => 'SDIT Al Hidayah',
            'tahun_pelajaran' => '2026/2027',
            'semester' => 2,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function insertYear(string $label, int $semester, bool $active, bool $trashed): int
    {
        return DB::table('tahun_ajarans')->insertGetId([
            'tahun_ajaran' => $label,
            'is_active' => $active,
            'tanggal_mulai' => $semester === 1 ? substr($label, 0, 4).'-07-01' : substr($label, 5, 4).'-01-01',
            'tanggal_selesai' => substr($label, 5, 4).'-06-30',
            'semester' => $semester,
            'deskripsi' => 'Fixture',
            'created_at' => now(),
            'updated_at' => now(),
            'deleted_at' => $trashed ? now() : null,
        ]);
    }

    private function insertClass(int $tahunAjaranId): int
    {
        return DB::table('kelas')->insertGetId([
            'nomor_kelas' => '5',
            'nama_kelas' => 'A',
            'tahun_ajaran_id' => $tahunAjaranId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function insertStudent(int $kelasId, int $tahunAjaranId): int
    {
        return DB::table('siswas')->insertGetId([
            'nis' => '2605001',
            'nisn' => '9000000001',
            'nama' => 'Ahmad Fauzan',
            'kelas_id' => $kelasId,
            'tahun_ajaran_id' => $tahunAjaranId,
            'status' => 'aktif',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}

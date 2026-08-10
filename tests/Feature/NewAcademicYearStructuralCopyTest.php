<?php

namespace Tests\Feature;

use App\Models\Guru;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class NewAcademicYearStructuralCopyTest extends TestCase
{
    private User $admin;

    private Guru $budi;

    private int $sourceYearId;

    private int $class5AId;

    private int $subjectId;

    private int $studentId;

    private int $templateId;

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
        Storage::fake('local');
        Event::fake();

        $this->createSchema();
        $this->seedFixture();
    }

    public function test_new_year_copy_copies_only_structural_data_and_remaps_subject_context(): void
    {
        $response = $this->postCopy(['is_active' => true]);

        $response->assertOk()
            ->assertJsonPath('success', true);

        $targetYear = $this->targetYear();
        $targetClass = $this->targetClass();
        $targetSubject = DB::table('mata_pelajarans')
            ->where('tahun_ajaran_id', $targetYear->id)
            ->where('kelas_id', $targetClass->id)
            ->where('nama_pelajaran', 'Matematika')
            ->first();

        $this->assertNotNull($targetYear);
        $this->assertTrue((bool) $targetYear->is_active);
        $this->assertFalse((bool) DB::table('tahun_ajarans')->where('id', $this->sourceYearId)->value('is_active'));
        $this->assertSame($targetYear->id, session('tahun_ajaran_id'));
        $this->assertSame(1, session('selected_semester'));

        $this->assertNotNull($targetClass);
        $this->assertDatabaseHas('guru_kelas', [
            'guru_id' => $this->budi->id,
            'kelas_id' => $targetClass->id,
            'is_wali_kelas' => true,
            'role' => 'wali_kelas',
        ]);

        $this->assertNotNull($targetSubject);
        $this->assertSame(1, (int) $targetSubject->semester);
        $this->assertDatabaseHas('lingkup_materis', [
            'mata_pelajaran_id' => $targetSubject->id,
            'judul_lingkup_materi' => 'Bilangan',
        ]);

        $targetLingkupMateriId = DB::table('lingkup_materis')
            ->where('mata_pelajaran_id', $targetSubject->id)
            ->value('id');

        $this->assertDatabaseHas('tujuan_pembelajarans', [
            'lingkup_materi_id' => $targetLingkupMateriId,
            'kode_tp' => 'TP1',
        ]);

        $this->assertDatabaseHas('kkms', [
            'tahun_ajaran_id' => $targetYear->id,
            'kelas_id' => $targetClass->id,
            'mata_pelajaran_id' => $targetSubject->id,
            'nilai' => 75,
        ]);
        $this->assertDatabaseMissing('kkms', [
            'tahun_ajaran_id' => $targetYear->id,
            'mata_pelajaran_id' => $this->subjectId,
        ]);

        $this->assertDatabaseHas('bobot_nilais', [
            'tahun_ajaran_id' => $targetYear->id,
            'bobot_tp' => 0.25,
            'bobot_lm' => 0.25,
            'bobot_as' => 0.5,
        ]);

        $targetTemplate = DB::table('report_templates')
            ->where('tahun_ajaran_id', $targetYear->id)
            ->where('filename', 'demo.docx')
            ->first();

        $this->assertNotNull($targetTemplate);
        $this->assertSame($targetClass->id, (int) $targetTemplate->kelas_id);
        $this->assertSame('templates/copy_2027-2028_demo.docx', $targetTemplate->path);
        $this->assertFalse((bool) $targetTemplate->is_active);
        Storage::assertExists('public/templates/copy_2027-2028_demo.docx');

        $this->assertDatabaseHas('report_template_kelas', [
            'report_template_id' => $targetTemplate->id,
            'kelas_id' => $targetClass->id,
        ]);
        $this->assertDatabaseHas('report_mappings', [
            'report_template_id' => $targetTemplate->id,
            'placeholder_key' => 'nama_siswa',
            'data_source' => 'siswa.nama',
        ]);

        $this->assertDatabaseHas('ekstrakurikulers', [
            'tahun_ajaran_id' => $targetYear->id,
            'nama_ekstrakurikuler' => 'Pramuka',
        ]);

        $this->assertSame(1, DB::table('siswas')->count());
        $this->assertSame(0, DB::table('siswas')->where('nis', 'like', 'S2-%')->orWhere('nisn', 'like', 'S2-%')->count());
        $this->assertSame($this->class5AId, (int) DB::table('siswas')->where('id', $this->studentId)->value('kelas_id'));
        $this->assertSame(1, DB::table('siswa_kelas_semester')->count());

        foreach (['nilais', 'absensis', 'catatan_siswa', 'catatan_mata_pelajaran', 'capaian_custom', 'nilai_ekstrakurikuler', 'report_generations'] as $table) {
            $this->assertSame(0, DB::table($table)->where('tahun_ajaran_id', $targetYear->id)->count(), "{$table} should not be copied.");
        }
    }

    public function test_new_year_copy_requires_semester_two_source_and_semester_one_target(): void
    {
        DB::table('tahun_ajarans')->where('id', $this->sourceYearId)->update(['semester' => 1]);

        $this->postCopy()->assertStatus(422);

        DB::table('tahun_ajarans')->where('id', $this->sourceYearId)->update(['semester' => 2]);

        $this->postCopy(['semester' => 2])
            ->assertStatus(422)
            ->assertJsonPath('success', false);

        $this->postCopy(['tahun_ajaran' => '2028/2029'])
            ->assertStatus(422)
            ->assertJsonPath('success', false);

        $this->assertDatabaseMissing('tahun_ajarans', [
            'tahun_ajaran' => '2027/2028',
        ]);
    }

    public function test_new_year_copy_page_renders_readiness_and_does_not_persist_default_bobot(): void
    {
        DB::table('bobot_nilais')->where('tahun_ajaran_id', $this->sourceYearId)->delete();
        DB::table('tahun_ajarans')->insert([
            'tahun_ajaran' => '2025/2026',
            'is_active' => false,
            'tanggal_mulai' => '2025-07-01',
            'tanggal_selesai' => '2026-06-30',
            'semester' => 2,
            'deskripsi' => 'Other source',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $counts = [
            'tahun_ajarans' => DB::table('tahun_ajarans')->count(),
            'kelas' => DB::table('kelas')->count(),
            'mata_pelajarans' => DB::table('mata_pelajarans')->count(),
            'bobot_nilais' => DB::table('bobot_nilais')->count(),
            'report_templates' => DB::table('report_templates')->count(),
        ];

        $this->actingAs($this->admin, 'web')
            ->get(route('tahun.ajaran.copy', $this->sourceYearId))
            ->assertOk()
            ->assertSeeText('Buat Tahun Ajaran Berikutnya?')
            ->assertSeeText('Target akan dibuat dalam keadaan belum aktif.')
            ->assertSeeText('Penempatan siswa untuk tahun ajaran berikutnya ditangani melalui proses Kenaikan Kelas.')
            ->assertSeeText('1 kelas tersedia')
            ->assertSeeText('Semua kelas memiliki wali kelas')
            ->assertSeeText('1 mata pelajaran tersedia')
            ->assertSeeText('Struktur LM/TP sudah tersedia')
            ->assertSeeText('Menggunakan default sementara (1:1:2), belum tersimpan');

        $this->assertSame(0, DB::table('bobot_nilais')->where('tahun_ajaran_id', $this->sourceYearId)->count());
        $this->assertSame($counts, [
            'tahun_ajarans' => DB::table('tahun_ajarans')->count(),
            'kelas' => DB::table('kelas')->count(),
            'mata_pelajarans' => DB::table('mata_pelajarans')->count(),
            'bobot_nilais' => DB::table('bobot_nilais')->count(),
            'report_templates' => DB::table('report_templates')->count(),
        ]);
        $this->assertDatabaseMissing('tahun_ajarans', [
            'tahun_ajaran' => '2027/2028',
        ]);
    }

    public function test_new_year_copy_without_typed_confirmation_is_rejected_without_partial_copy(): void
    {
        $this->postCopy(['transition_confirmation' => null])
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Konfirmasi tidak sesuai. Ketik kalimat yang diminta untuk melanjutkan.');

        $this->assertDatabaseMissing('tahun_ajarans', [
            'tahun_ajaran' => '2027/2028',
        ]);
        $this->assertSame(1, DB::table('kelas')->count());
        $this->assertSame(1, DB::table('mata_pelajarans')->count());
    }

    public function test_new_year_copy_with_incorrect_confirmation_is_rejected_without_partial_copy(): void
    {
        $this->postCopy(['transition_confirmation' => 'BUAT'])
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Konfirmasi tidak sesuai. Ketik kalimat yang diminta untuk melanjutkan.');

        $this->assertDatabaseMissing('tahun_ajarans', [
            'tahun_ajaran' => '2027/2028',
        ]);
        $this->assertSame(1, DB::table('report_templates')->count());
    }

    public function test_new_year_copy_normal_request_creates_inactive_target(): void
    {
        $this->postCopy()
            ->assertOk()
            ->assertJsonPath('success', true);

        $targetYear = $this->targetYear();

        $this->assertNotNull($targetYear);
        $this->assertFalse((bool) $targetYear->is_active);
        $this->assertTrue((bool) DB::table('tahun_ajarans')->where('id', $this->sourceYearId)->value('is_active'));
    }

    public function test_new_year_copy_rejects_archived_source_direct_request(): void
    {
        DB::table('tahun_ajarans')->where('id', $this->sourceYearId)->update([
            'is_active' => false,
            'deleted_at' => now(),
        ]);
        $activeFallbackId = DB::table('tahun_ajarans')->insertGetId([
            'tahun_ajaran' => '2028/2029',
            'is_active' => true,
            'tanggal_mulai' => '2028-07-01',
            'tanggal_selesai' => '2029-06-30',
            'semester' => 1,
            'deskripsi' => 'Active fallback',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($this->admin, 'web')
            ->withSession([
                'tahun_ajaran_id' => $activeFallbackId,
                'selected_semester' => 1,
                'no_tahun_ajaran' => false,
            ])
            ->postJson(route('tahun.ajaran.process-copy', $this->sourceYearId), [
                'tahun_ajaran' => '2027/2028',
                'tanggal_mulai' => '2027-07-01',
                'tanggal_selesai' => '2028-06-30',
                'semester' => 1,
                'copy_kelas' => true,
                'copy_mata_pelajaran' => true,
                'copy_templates' => true,
                'copy_ekstrakurikuler' => true,
                'copy_kkm' => true,
                'copy_bobot_nilai' => true,
                'transition_confirmation' => 'BUAT TAHUN AJARAN BERIKUTNYA',
                'is_active' => false,
            ])
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Tahun ajaran yang berada di arsip harus dipulihkan terlebih dahulu sebelum dapat digunakan untuk membuat tahun ajaran berikutnya.');

        $this->assertDatabaseMissing('tahun_ajarans', [
            'tahun_ajaran' => '2027/2028',
        ]);
        Storage::assertMissing('public/templates/copy_2027-2028_demo.docx');
    }

    public function test_new_year_copy_page_renders_phase_one_safeguards(): void
    {
        $this->actingAs($this->admin, 'web')
            ->get(route('tahun.ajaran.copy', $this->sourceYearId))
            ->assertOk()
            ->assertSeeText('Buat Tahun Ajaran Berikutnya?')
            ->assertSeeText('Perubahan pada tahun ajaran sumber setelah proses ini tidak akan otomatis disalin ke target.')
            ->assertSeeText('Target akan dibuat dalam keadaan belum aktif.')
            ->assertSeeText('Penempatan siswa untuk tahun ajaran berikutnya ditangani melalui proses Kenaikan Kelas.')
            ->assertSeeText('BUAT TAHUN AJARAN BERIKUTNYA')
            ->assertSee('disabled', false);
    }

    public function test_duplicate_target_year_is_rejected_without_partial_copy(): void
    {
        DB::table('tahun_ajarans')->insert([
            'tahun_ajaran' => '2027/2028',
            'is_active' => false,
            'tanggal_mulai' => '2027-07-01',
            'tanggal_selesai' => '2028-06-30',
            'semester' => 1,
            'deskripsi' => 'Existing target',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->postCopy()->assertStatus(409);

        $this->assertSame(2, DB::table('tahun_ajarans')->count());
        $this->assertSame(1, DB::table('kelas')->count());
        $this->assertSame(1, DB::table('mata_pelajarans')->count());
        $this->assertSame(1, DB::table('report_templates')->count());
        Storage::assertMissing('public/templates/copy_2027-2028_demo.docx');
    }

    public function test_bobot_only_archived_target_can_be_removed_then_copy_retried_successfully(): void
    {
        $archivedTargetId = DB::table('tahun_ajarans')->insertGetId([
            'tahun_ajaran' => '2027/2028',
            'is_active' => false,
            'tanggal_mulai' => '2027-07-01',
            'tanggal_selesai' => '2028-06-30',
            'semester' => 1,
            'deskripsi' => 'Archived draft target',
            'created_at' => now(),
            'updated_at' => now(),
            'deleted_at' => now(),
        ]);

        DB::table('bobot_nilais')->insert([
            'tahun_ajaran_id' => $archivedTargetId,
            'bobot_tp' => 1,
            'bobot_lm' => 1,
            'bobot_as' => 2,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->postCopy()
            ->assertStatus(409)
            ->assertJsonPath('success', false)
            ->assertJsonPath('conflict', 'archived')
            ->assertJsonPath('archived_id', $archivedTargetId);

        $this->actingAs($this->admin, 'web')
            ->deleteJson(route('tahun.ajaran.force-delete', $archivedTargetId), [
                'purge_confirmation' => 'HAPUS PERMANEN 2027/2028 SEMESTER GANJIL',
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertSame(0, DB::table('tahun_ajarans')->where('id', $archivedTargetId)->count());
        $this->assertSame(0, DB::table('bobot_nilais')->where('tahun_ajaran_id', $archivedTargetId)->count());

        $this->postCopy()
            ->assertOk()
            ->assertJsonPath('success', true);

        $targetYear = $this->targetYear();
        $this->assertNotNull($targetYear);
        $this->assertNotSame($archivedTargetId, (int) $targetYear->id);
        $this->assertDatabaseHas('bobot_nilais', [
            'tahun_ajaran_id' => $targetYear->id,
            'bobot_tp' => 0.25,
            'bobot_lm' => 0.25,
            'bobot_as' => 0.5,
        ]);
    }

    public function test_new_year_copy_skips_bobot_when_source_has_no_persisted_bobot(): void
    {
        DB::table('bobot_nilais')->where('tahun_ajaran_id', $this->sourceYearId)->delete();

        $this->postCopy()
            ->assertOk()
            ->assertJsonPath('success', true);

        $targetYear = $this->targetYear();
        $this->assertNotNull($targetYear);
        $this->assertSame(0, DB::table('bobot_nilais')->where('tahun_ajaran_id', $targetYear->id)->count());
    }

    public function test_failed_copy_rolls_back_structural_data_keeps_active_year_and_cleans_new_template_file(): void
    {
        Schema::dropIfExists('bobot_nilais');

        $this->postCopy(['is_active' => true])->assertStatus(500);

        $this->assertDatabaseMissing('tahun_ajarans', [
            'tahun_ajaran' => '2027/2028',
        ]);
        $this->assertSame(1, DB::table('tahun_ajarans')->where('is_active', true)->count());
        $this->assertTrue((bool) DB::table('tahun_ajarans')->where('id', $this->sourceYearId)->value('is_active'));
        $this->assertSame(1, DB::table('kelas')->count());
        $this->assertSame(1, DB::table('report_templates')->count());
        Storage::assertExists('public/templates/demo.docx');
        Storage::assertMissing('public/templates/copy_2027-2028_demo.docx');
    }

    public function test_direct_copy_request_rejects_structural_dependencies_without_partial_copy(): void
    {
        $this->postCopy([
            'copy_kelas' => false,
            'copy_mata_pelajaran' => true,
            'copy_templates' => true,
            'copy_kkm' => true,
        ])->assertStatus(422);

        $this->assertDatabaseMissing('tahun_ajarans', [
            'tahun_ajaran' => '2027/2028',
        ]);
        $this->assertSame(1, DB::table('kelas')->count());
        $this->assertSame(1, DB::table('kkms')->count());
    }

    private function postCopy(array $overrides = [])
    {
        return $this->actingAs($this->admin, 'web')
            ->postJson(route('tahun.ajaran.process-copy', $this->sourceYearId), array_merge([
                'tahun_ajaran' => '2027/2028',
                'tanggal_mulai' => '2027-07-01',
                'tanggal_selesai' => '2028-06-30',
                'semester' => 1,
                'copy_kelas' => true,
                'copy_mata_pelajaran' => true,
                'copy_templates' => true,
                'copy_ekstrakurikuler' => true,
                'copy_kkm' => true,
                'copy_bobot_nilai' => true,
                'transition_confirmation' => 'BUAT TAHUN AJARAN BERIKUTNYA',
                'is_active' => false,
            ], $overrides));
    }

    private function targetYear()
    {
        return DB::table('tahun_ajarans')
            ->where('tahun_ajaran', '2027/2028')
            ->where('semester', 1)
            ->first();
    }

    private function targetClass()
    {
        return DB::table('kelas')
            ->where('tahun_ajaran_id', $this->targetYear()->id)
            ->where('nomor_kelas', '5')
            ->where('nama_kelas', 'A')
            ->first();
    }

    private function createSchema(): void
    {
        foreach ([
            'report_generations',
            'report_template_kelas',
            'report_mappings',
            'report_templates',
            'nilai_ekstrakurikuler',
            'ekstrakurikulers',
            'capaian_custom',
            'catatan_mata_pelajaran',
            'catatan_siswa',
            'absensis',
            'nilais',
            'bobot_nilais',
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
            $table->string('username')->nullable();
            $table->string('email')->unique();
            $table->string('password');
            $table->timestamps();
        });

        Schema::create('gurus', function (Blueprint $table) {
            $table->id();
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
            $table->foreignId('kelas_id')->nullable();
            $table->foreignId('tahun_ajaran_id')->nullable();
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
            $table->foreignId('kelas_id');
            $table->integer('semester');
            $table->foreignId('guru_id');
            $table->json('lingkup_materi')->nullable();
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
            $table->foreignId('tahun_ajaran_id')
                ->nullable()
                ->constrained('tahun_ajarans')
                ->cascadeOnDelete();
            $table->float('bobot_tp', 5, 2)->default(0.25);
            $table->float('bobot_lm', 5, 2)->default(0.25);
            $table->float('bobot_as', 5, 2)->default(0.50);
            $table->timestamps();
        });

        Schema::create('nilais', function (Blueprint $table) {
            $table->id();
            $table->foreignId('siswa_id');
            $table->foreignId('mata_pelajaran_id');
            $table->foreignId('tujuan_pembelajaran_id')->nullable();
            $table->foreignId('lingkup_materi_id')->nullable();
            $table->decimal('nilai_tp', 5, 2)->nullable();
            $table->decimal('nilai_lm', 5, 2)->nullable();
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
            $table->integer('semester')->nullable();
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
            $table->foreignId('tahun_ajaran_id');
            $table->tinyInteger('semester');
            $table->timestamps();
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
            $table->unsignedTinyInteger('semester')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('report_templates', function (Blueprint $table) {
            $table->id();
            $table->string('filename');
            $table->string('path')->nullable();
            $table->string('type');
            $table->boolean('is_active')->default(false);
            $table->string('tahun_ajaran')->nullable();
            $table->string('tahun_ajaran_text')->nullable();
            $table->integer('semester')->nullable();
            $table->foreignId('kelas_id')->nullable();
            $table->foreignId('tahun_ajaran_id')->nullable();
            $table->timestamps();
        });

        Schema::create('report_mappings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('report_template_id');
            $table->string('placeholder_key');
            $table->string('data_source');
            $table->text('description')->nullable();
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
            $table->foreignId('siswa_id');
            $table->foreignId('kelas_id')->nullable();
            $table->foreignId('report_template_id')->nullable();
            $table->string('generated_file')->nullable();
            $table->string('type');
            $table->string('tahun_ajaran');
            $table->integer('semester');
            $table->timestamp('generated_at')->nullable();
            $table->foreignId('generated_by')->nullable();
            $table->foreignId('tahun_ajaran_id')->nullable();
            $table->timestamps();
        });
    }

    private function seedFixture(): void
    {
        $this->admin = User::create([
            'name' => 'Demo Admin',
            'username' => 'demo_admin',
            'email' => 'admin@example.test',
            'password' => Hash::make('password'),
        ]);

        $this->budi = Guru::create([
            'nama' => 'Budi Santoso',
            'email' => 'budi@example.test',
            'username' => 'budi',
            'password' => Hash::make('password'),
        ]);

        $this->sourceYearId = DB::table('tahun_ajarans')->insertGetId([
            'tahun_ajaran' => '2026/2027',
            'is_active' => true,
            'tanggal_mulai' => '2026-07-01',
            'tanggal_selesai' => '2027-06-30',
            'semester' => 2,
            'deskripsi' => 'Demo genap',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        session([
            'tahun_ajaran_id' => $this->sourceYearId,
            'selected_semester' => 2,
        ]);

        DB::table('profil_sekolah')->insert([
            'nama_sekolah' => 'SDIT Al Hidayah',
            'tahun_pelajaran' => '2026/2027',
            'semester' => 2,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->class5AId = DB::table('kelas')->insertGetId([
            'nomor_kelas' => '5',
            'nama_kelas' => 'A',
            'tahun_ajaran_id' => $this->sourceYearId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('guru_kelas')->insert([
            'guru_id' => $this->budi->id,
            'kelas_id' => $this->class5AId,
            'is_wali_kelas' => true,
            'role' => 'wali_kelas',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->studentId = DB::table('siswas')->insertGetId([
            'nis' => '2605001',
            'nisn' => '9000000001',
            'nama' => 'Ahmad Fauzan',
            'kelas_id' => $this->class5AId,
            'tahun_ajaran_id' => $this->sourceYearId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('siswa_kelas_semester')->insert([
            'siswa_id' => $this->studentId,
            'kelas_id' => $this->class5AId,
            'tahun_ajaran_id' => $this->sourceYearId,
            'semester' => 2,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->subjectId = DB::table('mata_pelajarans')->insertGetId([
            'nama_pelajaran' => 'Matematika',
            'kelas_id' => $this->class5AId,
            'semester' => 2,
            'guru_id' => $this->budi->id,
            'tahun_ajaran_id' => $this->sourceYearId,
            'is_muatan_lokal' => false,
            'allow_non_wali' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $lmId = DB::table('lingkup_materis')->insertGetId([
            'mata_pelajaran_id' => $this->subjectId,
            'judul_lingkup_materi' => 'Bilangan',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $tpId = DB::table('tujuan_pembelajarans')->insertGetId([
            'lingkup_materi_id' => $lmId,
            'kode_tp' => 'TP1',
            'deskripsi_tp' => 'Memahami bilangan',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('kkms')->insert([
            'mata_pelajaran_id' => $this->subjectId,
            'kelas_id' => $this->class5AId,
            'tahun_ajaran_id' => $this->sourceYearId,
            'nilai' => 75,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('bobot_nilais')->insert([
            'tahun_ajaran_id' => $this->sourceYearId,
            'bobot_tp' => 0.25,
            'bobot_lm' => 0.25,
            'bobot_as' => 0.50,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Storage::put('public/templates/demo.docx', 'demo-template');

        $this->templateId = DB::table('report_templates')->insertGetId([
            'filename' => 'demo.docx',
            'path' => 'templates/demo.docx',
            'type' => 'UTS',
            'is_active' => true,
            'tahun_ajaran' => '2026/2027',
            'tahun_ajaran_text' => '2026/2027',
            'semester' => 2,
            'kelas_id' => $this->class5AId,
            'tahun_ajaran_id' => $this->sourceYearId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('report_template_kelas')->insert([
            'report_template_id' => $this->templateId,
            'kelas_id' => $this->class5AId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('report_mappings')->insert([
            'report_template_id' => $this->templateId,
            'placeholder_key' => 'nama_siswa',
            'data_source' => 'siswa.nama',
            'description' => 'Nama siswa',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $ekskulId = DB::table('ekstrakurikulers')->insertGetId([
            'nama_ekstrakurikuler' => 'Pramuka',
            'pembina' => 'Yusuf',
            'tahun_ajaran_id' => $this->sourceYearId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('nilais')->insert([
            'siswa_id' => $this->studentId,
            'mata_pelajaran_id' => $this->subjectId,
            'tujuan_pembelajaran_id' => $tpId,
            'lingkup_materi_id' => $lmId,
            'nilai_tp' => 90,
            'nilai_lm' => 88,
            'nilai_tes' => 86,
            'nilai_non_tes' => 84,
            'nilai_akhir_rapor' => 87,
            'is_submitted' => true,
            'tahun_ajaran_id' => $this->sourceYearId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('absensis')->insert([
            'siswa_id' => $this->studentId,
            'sakit' => 2,
            'izin' => 1,
            'tanpa_keterangan' => 0,
            'semester' => 2,
            'tahun_ajaran_id' => $this->sourceYearId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('catatan_siswa')->insert([
            'siswa_id' => $this->studentId,
            'catatan' => 'Genap note',
            'tahun_ajaran_id' => $this->sourceYearId,
            'semester' => 2,
            'type' => 'umum',
            'created_by' => $this->budi->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('catatan_mata_pelajaran')->insert([
            'mata_pelajaran_id' => $this->subjectId,
            'siswa_id' => $this->studentId,
            'catatan' => 'Subject note',
            'tahun_ajaran_id' => $this->sourceYearId,
            'semester' => 2,
            'type' => 'umum',
            'created_by' => $this->budi->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('capaian_custom')->insert([
            'siswa_id' => $this->studentId,
            'mata_pelajaran_id' => $this->subjectId,
            'custom_capaian' => 'Capaian genap',
            'tahun_ajaran_id' => $this->sourceYearId,
            'semester' => 2,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('nilai_ekstrakurikuler')->insert([
            'siswa_id' => $this->studentId,
            'ekstrakurikuler_id' => $ekskulId,
            'predikat' => 'A',
            'deskripsi' => 'Ekskul genap',
            'tahun_ajaran_id' => $this->sourceYearId,
            'semester' => 2,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('report_generations')->insert([
            'siswa_id' => $this->studentId,
            'kelas_id' => $this->class5AId,
            'report_template_id' => $this->templateId,
            'generated_file' => 'reports/ahmad.pdf',
            'type' => 'UAS',
            'tahun_ajaran' => '2026/2027',
            'semester' => 2,
            'generated_at' => now(),
            'generated_by' => $this->budi->id,
            'tahun_ajaran_id' => $this->sourceYearId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}

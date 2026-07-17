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
use Illuminate\Support\Facades\Storage;
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
        Storage::fake('public');

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
            ->assertSeeText('Tidak dapat dihapus permanen karena terhubung alur akademik.')
            ->assertDontSee('title="Hapus Permanen"', false);

        $this->actingAs($this->admin, 'web')
            ->get(route('tahun.ajaran.show', $archivedYearId))
            ->assertOk()
            ->assertSeeText('Tindakan Arsip')
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

    public function test_manual_create_tahun_ajaran_is_fresh_without_copying_basic_structure(): void
    {
        $guruId = DB::table('gurus')->insertGetId([
            'nama' => 'Guru Lama',
            'username' => 'guru_lama',
            'email' => 'guru-lama@example.test',
            'password' => Hash::make('password'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $sourceClassId = $this->insertClass($this->activeYearId);

        DB::table('guru_kelas')->insert([
            'guru_id' => $guruId,
            'kelas_id' => $sourceClassId,
            'is_wali_kelas' => true,
            'role' => 'wali_kelas',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('bobot_nilais')->insert([
            'tahun_ajaran_id' => $this->activeYearId,
            'bobot_tp' => 1,
            'bobot_lm' => 1,
            'bobot_as' => 2,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('mata_pelajarans')->insert([
            'nama_pelajaran' => 'Matematika Lama',
            'kelas_id' => $sourceClassId,
            'tahun_ajaran_id' => $this->activeYearId,
            'semester' => 2,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($this->admin, 'web')
            ->post(route('tahun.ajaran.store'), [
                'tahun_ajaran' => '2031/2032',
                'tanggal_mulai' => '2031-07-01',
                'tanggal_selesai' => '2032-06-30',
                'semester' => 1,
                'deskripsi' => 'Tahun ajaran fresh',
            ])
            ->assertRedirect(route('tahun.ajaran.index'))
            ->assertSessionHas('success', 'Tahun ajaran berhasil dibuat.');

        $newYearId = DB::table('tahun_ajarans')
            ->where('tahun_ajaran', '2031/2032')
            ->where('semester', 1)
            ->value('id');

        $this->assertNotNull($newYearId);
        $this->assertSame(0, DB::table('kelas')->where('tahun_ajaran_id', $newYearId)->count());
        $this->assertSame(0, DB::table('bobot_nilais')->where('tahun_ajaran_id', $newYearId)->count());
        $this->assertSame(0, DB::table('mata_pelajarans')->where('tahun_ajaran_id', $newYearId)->count());
        $this->assertSame(1, DB::table('gurus')->count());
        $this->assertSame(1, DB::table('guru_kelas')->count());
    }

    public function test_archived_year_with_only_bobot_can_be_permanently_deleted_and_cascades_bobot(): void
    {
        $archivedYearId = $this->insertYear('2032/2033', 1, false, true);
        $templatePath = 'templates/bobot-plus-template.docx';
        $templateId = $this->insertReportTemplate($archivedYearId, false, $templatePath);
        Storage::disk('public')->put($templatePath, 'template-docx');

        DB::table('bobot_nilais')->insert([
            'tahun_ajaran_id' => $archivedYearId,
            'bobot_tp' => 1,
            'bobot_lm' => 1,
            'bobot_as' => 2,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($this->admin, 'web')
            ->delete(route('tahun.ajaran.force-delete', $archivedYearId))
            ->assertRedirect(route('tahun.ajaran.index', ['showArchived' => 'true']))
            ->assertSessionHas('success');

        $this->assertSame(0, DB::table('tahun_ajarans')->where('id', $archivedYearId)->count());
        $this->assertSame(0, DB::table('bobot_nilais')->where('tahun_ajaran_id', $archivedYearId)->count());
        $this->assertSame(0, DB::table('report_templates')->where('id', $templateId)->count());
        Storage::disk('public')->assertMissing($templatePath);
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

    public function test_archived_year_with_only_inactive_report_template_is_permanently_deleted_and_cleans_file(): void
    {
        $archivedYearId = $this->insertYear('2033/2034', 1, false, true);
        $path = 'templates/inactive-owned-template.docx';
        $templateId = $this->insertReportTemplate($archivedYearId, false, $path);
        Storage::disk('public')->put($path, 'template-docx');

        $this->actingAs($this->admin, 'web')
            ->delete(route('tahun.ajaran.force-delete', $archivedYearId))
            ->assertRedirect(route('tahun.ajaran.index', ['showArchived' => 'true']))
            ->assertSessionHas('success', 'Tahun ajaran berhasil dihapus permanen.');

        $this->assertSame(0, DB::table('tahun_ajarans')->where('id', $archivedYearId)->count());
        $this->assertSame(0, DB::table('report_templates')->where('id', $templateId)->count());
        Storage::disk('public')->assertMissing($path);
    }

    public function test_archived_year_with_active_report_template_is_permanently_deleted_and_cleans_file(): void
    {
        $archivedYearId = $this->insertYear('2034/2035', 1, false, true);
        $path = 'templates/active-owned-template.docx';
        $templateId = $this->insertReportTemplate($archivedYearId, true, $path);
        Storage::disk('public')->put($path, 'template-docx');

        $this->actingAs($this->admin, 'web')
            ->deleteJson(route('tahun.ajaran.force-delete', $archivedYearId))
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Tahun ajaran berhasil dihapus permanen.');

        $this->assertSame(0, DB::table('tahun_ajarans')->where('id', $archivedYearId)->count());
        $this->assertSame(0, DB::table('report_templates')->where('id', $templateId)->count());
        Storage::disk('public')->assertMissing($path);
    }

    public function test_shared_report_template_path_is_not_deleted_when_another_template_still_uses_it(): void
    {
        $archivedYearId = $this->insertYear('2035/2036', 1, false, true);
        $survivingYearId = $this->insertYear('2036/2037', 1, false, false);
        $sharedPath = 'templates/shared-template.docx';
        $targetTemplateId = $this->insertReportTemplate($archivedYearId, false, $sharedPath);
        $survivingTemplateId = $this->insertReportTemplate($survivingYearId, false, $sharedPath);
        Storage::disk('public')->put($sharedPath, 'shared-template-docx');

        $this->actingAs($this->admin, 'web')
            ->delete(route('tahun.ajaran.force-delete', $archivedYearId))
            ->assertRedirect(route('tahun.ajaran.index', ['showArchived' => 'true']))
            ->assertSessionHas('success', 'Tahun ajaran berhasil dihapus permanen.');

        $this->assertSame(0, DB::table('tahun_ajarans')->where('id', $archivedYearId)->count());
        $this->assertSame(0, DB::table('report_templates')->where('id', $targetTemplateId)->count());
        $this->assertSame(1, DB::table('report_templates')->where('id', $survivingTemplateId)->count());
        Storage::disk('public')->assertExists($sharedPath);
    }

    public function test_missing_report_template_file_does_not_prevent_permanent_delete(): void
    {
        $archivedYearId = $this->insertYear('2037/2038', 1, false, true);
        $path = 'templates/missing-owned-template.docx';
        $templateId = $this->insertReportTemplate($archivedYearId, false, $path);

        Storage::disk('public')->assertMissing($path);

        $this->actingAs($this->admin, 'web')
            ->delete(route('tahun.ajaran.force-delete', $archivedYearId))
            ->assertRedirect(route('tahun.ajaran.index', ['showArchived' => 'true']))
            ->assertSessionHas('success', 'Tahun ajaran berhasil dihapus permanen.');

        $this->assertSame(0, DB::table('tahun_ajarans')->where('id', $archivedYearId)->count());
        $this->assertSame(0, DB::table('report_templates')->where('id', $templateId)->count());
    }

    public function test_report_generation_with_target_tahun_ajaran_id_still_blocks_permanent_delete(): void
    {
        $archivedYearId = $this->insertYear('2038/2039', 1, false, true);
        $this->insertReportGeneration($archivedYearId);

        $response = $this->actingAs($this->admin, 'web')
            ->deleteJson(route('tahun.ajaran.force-delete', $archivedYearId));

        $response->assertStatus(422)
            ->assertJsonPath('success', false);

        $message = $response->json('message');
        $this->assertStringContainsString('riwayat rapor (1)', $message);
        $this->assertStringNotContainsString('SQLSTATE', $message);
        $this->assertStringNotContainsString('report_generations', $message);
        $this->assertStringNotContainsString('constraint', strtolower($message));
        $this->assertSame(1, DB::table('tahun_ajarans')->where('id', $archivedYearId)->count());
    }

    public function test_legacy_report_generation_referencing_target_template_blocks_even_with_null_or_wrong_tahun_ajaran_id(): void
    {
        $archivedYearId = $this->insertYear('2039/2040', 1, false, true);
        $wrongYearId = $this->insertYear('2040/2041', 1, false, false);
        $templateId = $this->insertReportTemplate($archivedYearId, false, 'templates/history-owned-template.docx');
        $this->insertReportGeneration(null, $templateId);
        $this->insertReportGeneration($wrongYearId, $templateId);

        $response = $this->actingAs($this->admin, 'web')
            ->deleteJson(route('tahun.ajaran.force-delete', $archivedYearId));

        $response->assertStatus(422)
            ->assertJsonPath('success', false);

        $message = $response->json('message');
        $this->assertStringContainsString('riwayat rapor (2)', $message);
        $this->assertStringNotContainsString('SQLSTATE', $message);
        $this->assertStringNotContainsString('report_template_id', $message);
        $this->assertStringNotContainsString('constraint', strtolower($message));
        $this->assertSame(1, DB::table('tahun_ajarans')->where('id', $archivedYearId)->count());
        $this->assertSame(1, DB::table('report_templates')->where('id', $templateId)->count());
    }

    public function test_report_template_row_and_file_are_not_removed_when_protected_dependency_blocks_delete(): void
    {
        $archivedYearId = $this->insertYear('2041/2042', 1, false, true);
        $this->insertClass($archivedYearId);
        $path = 'templates/protected-owned-template.docx';
        $templateId = $this->insertReportTemplate($archivedYearId, false, $path);
        Storage::disk('public')->put($path, 'template-docx');

        $this->actingAs($this->admin, 'web')
            ->delete(route('tahun.ajaran.force-delete', $archivedYearId))
            ->assertRedirect()
            ->assertSessionHas('error');

        $message = session('error');
        $this->assertStringContainsString('kelas (1)', $message);
        $this->assertStringNotContainsString('template rapor', $message);
        $this->assertSame(1, DB::table('tahun_ajarans')->where('id', $archivedYearId)->count());
        $this->assertSame(1, DB::table('report_templates')->where('id', $templateId)->count());
        Storage::disk('public')->assertExists($path);
    }

    public function test_templates_belonging_to_another_tahun_ajaran_are_never_deleted(): void
    {
        $archivedYearId = $this->insertYear('2042/2043', 1, false, true);
        $otherYearId = $this->insertYear('2043/2044', 1, false, false);
        $targetPath = 'templates/target-owned-template.docx';
        $otherPath = 'templates/other-owned-template.docx';
        $targetTemplateId = $this->insertReportTemplate($archivedYearId, false, $targetPath);
        $otherTemplateId = $this->insertReportTemplate($otherYearId, false, $otherPath);
        Storage::disk('public')->put($targetPath, 'target-template-docx');
        Storage::disk('public')->put($otherPath, 'other-template-docx');

        $this->actingAs($this->admin, 'web')
            ->delete(route('tahun.ajaran.force-delete', $archivedYearId))
            ->assertRedirect(route('tahun.ajaran.index', ['showArchived' => 'true']))
            ->assertSessionHas('success');

        $this->assertSame(0, DB::table('report_templates')->where('id', $targetTemplateId)->count());
        $this->assertSame(1, DB::table('report_templates')->where('id', $otherTemplateId)->count());
        Storage::disk('public')->assertMissing($targetPath);
        Storage::disk('public')->assertExists($otherPath);
    }

    public function test_template_only_archived_year_is_not_rendered_as_protected(): void
    {
        $archivedYearId = $this->insertYear('2044/2045', 1, false, true);
        $this->insertReportTemplate($archivedYearId, false, 'templates/template-only-ui.docx');

        $this->actingAs($this->admin, 'web')
            ->get(route('tahun.ajaran.show', $archivedYearId))
            ->assertOk()
            ->assertSeeText('Pulihkan Tahun Ajaran')
            ->assertSeeText('Hapus Permanen')
            ->assertDontSeeText('Dilindungi');

        $this->actingAs($this->admin, 'web')
            ->get(route('tahun.ajaran.index', ['showArchived' => 'true']))
            ->assertOk()
            ->assertSee('title="Hapus Permanen"', false)
            ->assertDontSeeText('Tidak dapat dihapus permanen karena terhubung alur akademik.');
    }

    private function createSchema(): void
    {
        foreach ([
            'bobot_nilais',
            'report_templates',
            'report_template_kelas',
            'report_mappings',
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
            'guru_kelas',
            'kelas',
            'gurus',
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

        Schema::create('gurus', function (Blueprint $table) {
            $table->id();
            $table->string('nama');
            $table->string('username')->nullable()->unique();
            $table->string('email')->nullable()->unique();
            $table->string('password');
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

        foreach (['nilais', 'absensis', 'catatan_siswa', 'catatan_mata_pelajaran', 'capaian_custom', 'nilai_ekstrakurikuler'] as $tableName) {
            Schema::create($tableName, function (Blueprint $table) {
                $table->id();
                $table->foreignId('tahun_ajaran_id')->nullable();
                $table->timestamps();
                $table->softDeletes();
            });
        }

        Schema::create('report_templates', function (Blueprint $table) {
            $table->id();
            $table->string('filename')->nullable();
            $table->string('path')->nullable();
            $table->string('type')->nullable();
            $table->boolean('is_active')->default(false);
            $table->string('tahun_ajaran')->nullable();
            $table->string('tahun_ajaran_text')->nullable();
            $table->unsignedTinyInteger('semester')->nullable();
            $table->foreignId('kelas_id')->nullable();
            $table->foreignId('tahun_ajaran_id')->nullable();
            $table->timestamps();
        });

        Schema::create('report_mappings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('report_template_id');
            $table->string('placeholder_key')->nullable();
            $table->string('data_source')->nullable();
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
            $table->foreignId('tahun_ajaran_id')->nullable();
            $table->foreignId('report_template_id')->nullable();
            $table->timestamps();
        });

        Schema::create('bobot_nilais', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tahun_ajaran_id')
                ->nullable()
                ->constrained('tahun_ajarans')
                ->cascadeOnDelete();
            $table->unsignedTinyInteger('bobot_tp')->default(1);
            $table->unsignedTinyInteger('bobot_lm')->default(1);
            $table->unsignedTinyInteger('bobot_as')->default(2);
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

    private function insertReportTemplate(int $tahunAjaranId, bool $active, string $path): int
    {
        return DB::table('report_templates')->insertGetId([
            'filename' => basename($path),
            'path' => $path,
            'type' => 'UTS',
            'is_active' => $active,
            'tahun_ajaran' => 'Fixture',
            'tahun_ajaran_text' => 'Fixture',
            'semester' => 1,
            'kelas_id' => null,
            'tahun_ajaran_id' => $tahunAjaranId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function insertReportGeneration(?int $tahunAjaranId, ?int $templateId = null): int
    {
        return DB::table('report_generations')->insertGetId([
            'tahun_ajaran_id' => $tahunAjaranId,
            'report_template_id' => $templateId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}

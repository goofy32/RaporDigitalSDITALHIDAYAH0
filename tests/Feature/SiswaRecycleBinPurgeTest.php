<?php

namespace Tests\Feature;

use App\Http\Controllers\RecycleBinController;
use App\Models\Siswa;
use App\Models\User;
use App\Services\PdfCacheService;
use App\Services\SiswaPurgeService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Tests\TestCase;

class SiswaRecycleBinPurgeTest extends TestCase
{
    private User $admin;

    private int $activeYearId;

    private int $oldYearId;

    private int $activeClassId;

    private int $oldClassId;

    private int $guruId;

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
        DB::statement('PRAGMA foreign_keys = ON');

        Cache::flush();
        Event::fake();
        Storage::fake('public');

        $this->createSchema();
        $this->seedFixture();
    }

    public function test_trashed_siswa_with_enrollment_and_academic_data_is_permanently_purged_safely(): void
    {
        $targetId = $this->insertStudent('90001', 'Siswa Purge', $this->activeClassId, true, 'photos/siswa-purge.jpg');
        $otherId = $this->insertStudent('90002', 'Siswa Lain', $this->activeClassId);
        $this->insertFullStudentData($targetId);
        $this->insertEnrollment($otherId, $this->activeClassId, $this->activeYearId, 1);
        DB::table('nilais')->insert([
            'siswa_id' => $otherId,
            'mata_pelajaran_id' => $this->insertSubject($this->activeClassId, $this->activeYearId, 'Bahasa Indonesia'),
            'tahun_ajaran_id' => $this->activeYearId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Storage::disk('public')->put('photos/siswa-purge.jpg', 'photo');

        $this->actingAsAdmin()
            ->delete(route('admin.recycle-bin.force-delete', ['type' => 'siswa', 'id' => $targetId]), [
                'purge_confirmation' => $this->confirmationPhrase($targetId),
            ])
            ->assertRedirect()
            ->assertSessionHas('success', 'Siswa Siswa Purge berhasil dihapus permanen.');

        $this->assertDatabaseMissing('siswas', ['id' => $targetId]);
        $this->assertNoSiswaReferencesRemain($targetId);
        Storage::disk('public')->assertMissing('photos/siswa-purge.jpg');
        Storage::disk('public')->assertMissing('pdf_reports/siswa-purge-report.pdf');
        Storage::disk('public')->assertMissing('pdf_cache/siswa-purge-cache.pdf');
        Storage::disk('public')->assertMissing('docx_reports/siswa-purge-cache.docx');

        $this->assertDatabaseHas('siswas', ['id' => $otherId, 'nama' => 'Siswa Lain']);
        $this->assertSame(1, DB::table('siswa_kelas_semester')->where('siswa_id', $otherId)->count());
        $this->assertSame(1, DB::table('nilais')->where('siswa_id', $otherId)->count());
        $this->assertSame(2, DB::table('tahun_ajarans')->count());
        $this->assertSame(2, DB::table('kelas')->count());
        $this->assertSame(1, DB::table('gurus')->count());
    }

    public function test_active_siswa_cannot_be_permanently_purged_from_recycle_bin(): void
    {
        $studentId = $this->insertStudent('90003', 'Siswa Aktif', $this->activeClassId, false);

        $this->actingAsAdmin()
            ->deleteJson(route('admin.recycle-bin.force-delete', ['type' => 'siswa', 'id' => $studentId]), [
                'purge_confirmation' => 'HAPUS PERMANEN SISWA 90003',
            ])
            ->assertUnprocessable()
            ->assertJsonPath('message', SiswaPurgeService::NOT_TRASHED_MESSAGE);

        $this->assertDatabaseHas('siswas', [
            'id' => $studentId,
            'deleted_at' => null,
        ]);
    }

    public function test_restore_siswa_remains_soft_delete_flow_and_preserves_enrollment_history(): void
    {
        $studentId = $this->insertStudent('90004', 'Siswa Restore', $this->activeClassId, true);
        $enrollmentId = $this->insertEnrollment($studentId, $this->activeClassId, $this->activeYearId, 1);

        $this->actingAsAdmin()
            ->post(route('admin.recycle-bin.restore', ['type' => 'siswa', 'id' => $studentId]))
            ->assertRedirect()
            ->assertSessionHas('success', 'Data siswa Siswa Restore berhasil dipulihkan.');

        $this->assertNull(DB::table('siswas')->where('id', $studentId)->value('deleted_at'));
        $this->assertDatabaseHas('siswa_kelas_semester', [
            'id' => $enrollmentId,
            'siswa_id' => $studentId,
        ]);
    }

    public function test_unknown_siswa_dependency_blocks_and_rolls_back_purge(): void
    {
        $studentId = $this->insertStudent('90005', 'Siswa Ambigu', $this->activeClassId, true, 'photos/siswa-ambigu.jpg');
        $this->insertFullStudentData($studentId);
        Storage::disk('public')->put('photos/siswa-ambigu.jpg', 'photo');

        Schema::create('unresolved_student_refs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('siswa_id');
            $table->timestamps();
        });

        DB::table('unresolved_student_refs')->insert([
            'siswa_id' => $studentId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAsAdmin()
            ->delete(route('admin.recycle-bin.force-delete', ['type' => 'siswa', 'id' => $studentId]), [
                'purge_confirmation' => $this->confirmationPhrase($studentId),
            ])
            ->assertRedirect()
            ->assertSessionHas('error', SiswaPurgeService::UNRESOLVED_DEPENDENCY_MESSAGE);

        $this->assertNotNull(DB::table('siswas')->where('id', $studentId)->value('deleted_at'));
        foreach ($this->studentReferenceTables() as $table) {
            $this->assertGreaterThan(0, DB::table($table)->where('siswa_id', $studentId)->count(), "Expected {$table} to be preserved.");
        }
        $this->assertSame(1, DB::table('unresolved_student_refs')->where('siswa_id', $studentId)->count());
        Storage::disk('public')->assertExists('photos/siswa-ambigu.jpg');
        Storage::disk('public')->assertExists('pdf_reports/siswa-purge-report.pdf');
        $this->assertSame(0, DB::table('audit_logs')->where('action', 'permanent_purge')->count());
    }

    public function test_forced_transaction_failure_preserves_siswa_and_all_dependencies(): void
    {
        $studentId = $this->insertStudent('90006', 'Siswa Rollback', $this->activeClassId, true);
        $this->insertFullStudentData($studentId);
        Storage::disk('public')->put('photos/siswa-rollback.jpg', 'photo');

        $service = new class extends SiswaPurgeService {
            protected function afterDependenciesDeleted(array $plan): void
            {
                throw new \RuntimeException('simulated database failure');
            }
        };

        app()->instance(SiswaPurgeService::class, $service);

        $this->actingAsAdmin()
            ->delete(route('admin.recycle-bin.force-delete', ['type' => 'siswa', 'id' => $studentId]), [
                'purge_confirmation' => $this->confirmationPhrase($studentId),
            ])
            ->assertRedirect()
            ->assertSessionHas('error', 'Terjadi kesalahan saat menghapus permanen data. Silakan coba lagi.');

        $this->assertNotNull(DB::table('siswas')->where('id', $studentId)->value('deleted_at'));
        foreach ($this->studentReferenceTables() as $table) {
            $this->assertGreaterThan(0, DB::table($table)->where('siswa_id', $studentId)->count(), "Expected {$table} to be rolled back.");
        }
        Storage::disk('public')->assertExists('pdf_reports/siswa-purge-report.pdf');
    }

    public function test_missing_or_wrong_confirmation_is_rejected_without_deleting_data(): void
    {
        $studentId = $this->insertStudent('90007', 'Siswa Konfirmasi', $this->activeClassId, true);
        $this->insertEnrollment($studentId, $this->activeClassId, $this->activeYearId, 1);

        $this->actingAsAdmin()
            ->deleteJson(route('admin.recycle-bin.force-delete', ['type' => 'siswa', 'id' => $studentId]))
            ->assertUnprocessable()
            ->assertJsonPath('message', SiswaPurgeService::CONFIRMATION_MISMATCH_MESSAGE);

        $this->actingAsAdmin()
            ->deleteJson(route('admin.recycle-bin.force-delete', ['type' => 'siswa', 'id' => $studentId]), [
                'purge_confirmation' => 'hapus',
            ])
            ->assertUnprocessable()
            ->assertJsonPath('message', SiswaPurgeService::CONFIRMATION_MISMATCH_MESSAGE);

        $this->assertNotNull(DB::table('siswas')->where('id', $studentId)->value('deleted_at'));
        $this->assertSame(1, DB::table('siswa_kelas_semester')->where('siswa_id', $studentId)->count());
    }

    public function test_json_response_remains_successful_with_warning_when_post_commit_cleanup_fails(): void
    {
        $studentId = $this->insertStudent('90008', 'Siswa Cleanup', $this->activeClassId, true);
        $this->insertEnrollment($studentId, $this->activeClassId, $this->activeYearId, 1);
        $phrase = $this->confirmationPhrase($studentId);

        $service = Mockery::mock(SiswaPurgeService::class)->makePartial();
        $service->shouldReceive('runPostCommitCleanupSafely')->once()->andReturn(false);
        app()->instance(SiswaPurgeService::class, $service);

        $this->actingAsAdmin()
            ->deleteJson(route('admin.recycle-bin.force-delete', ['type' => 'siswa', 'id' => $studentId]), [
                'purge_confirmation' => $phrase,
            ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', SiswaPurgeService::FILE_CLEANUP_WARNING);

        $this->assertDatabaseMissing('siswas', ['id' => $studentId]);
        $this->assertSame(0, DB::table('siswa_kelas_semester')->where('siswa_id', $studentId)->count());
    }

    public function test_recycle_bin_page_renders_siswa_confirmation_phrase_and_note(): void
    {
        $studentId = $this->insertStudent('90009', 'Siswa View', $this->activeClassId, true);

        $this->actingAsAdmin()
            ->get(route('admin.recycle-bin.index', ['type' => 'siswa']))
            ->assertOk()
            ->assertSee('data-force-delete-form', false)
            ->assertSee($this->confirmationPhrase($studentId))
            ->assertSeeText('Hapus permanen siswa akan membersihkan enrollment dan riwayat akademik milik siswa ini.');
    }

    public function test_selected_siswa_force_delete_is_rejected_so_identity_confirmation_cannot_be_bypassed(): void
    {
        $studentId = $this->insertStudent('90010', 'Siswa Terpilih', $this->activeClassId, true);
        $this->insertEnrollment($studentId, $this->activeClassId, $this->activeYearId, 1);

        $this->actingAsAdmin()
            ->deleteJson(route('admin.recycle-bin.force-delete-all'), [
                'items' => ["siswa:{$studentId}"],
            ])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Hapus permanen siswa harus dilakukan satu per satu agar konfirmasi identitas siswa dapat diverifikasi.');

        $this->assertNotNull(DB::table('siswas')->where('id', $studentId)->value('deleted_at'));
        $this->assertSame(1, DB::table('siswa_kelas_semester')->where('siswa_id', $studentId)->count());
    }

    public function test_delete_all_is_rejected_when_trashed_siswa_requires_identity_confirmation(): void
    {
        $studentId = $this->insertStudent('90011', 'Siswa Semua', $this->activeClassId, true);
        $this->insertEnrollment($studentId, $this->activeClassId, $this->activeYearId, 1);

        $this->actingAsAdmin()
            ->deleteJson(route('admin.recycle-bin.force-delete-all'), [
                'confirmation' => 'HAPUS PERMANEN',
            ])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Hapus permanen siswa harus dilakukan satu per satu agar konfirmasi identitas siswa dapat diverifikasi.');

        $this->assertNotNull(DB::table('siswas')->where('id', $studentId)->value('deleted_at'));
        $this->assertSame(1, DB::table('siswa_kelas_semester')->where('siswa_id', $studentId)->count());
    }

    public function test_generic_force_delete_model_cannot_bypass_siswa_confirmation(): void
    {
        $studentId = $this->insertStudent('90012', 'Siswa Internal', $this->activeClassId, true);
        $this->insertEnrollment($studentId, $this->activeClassId, $this->activeYearId, 1);
        $siswa = Siswa::withTrashed()->findOrFail($studentId);

        $controller = new class extends RecycleBinController {
            public function callForceDeleteModel(Siswa $siswa): void
            {
                $this->forceDeleteModel('siswa', $siswa);
            }
        };

        try {
            $controller->callForceDeleteModel($siswa);
            $this->fail('Generic forceDeleteModel path should not purge Siswa.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Hapus permanen siswa harus menggunakan alur konfirmasi satu data dari recycle bin.', $exception->getMessage());
        }

        $this->assertNotNull(DB::table('siswas')->where('id', $studentId)->value('deleted_at'));
        $this->assertSame(1, DB::table('siswa_kelas_semester')->where('siswa_id', $studentId)->count());
    }

    public function test_unknown_siswa_id_table_without_target_rows_does_not_block_purge(): void
    {
        $studentId = $this->insertStudent('90013', 'Siswa Unknown Kosong', $this->activeClassId, true);
        $this->insertEnrollment($studentId, $this->activeClassId, $this->activeYearId, 1);

        Schema::create('unresolved_student_refs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('siswa_id');
            $table->timestamps();
        });

        $this->actingAsAdmin()
            ->delete(route('admin.recycle-bin.force-delete', ['type' => 'siswa', 'id' => $studentId]), [
                'purge_confirmation' => $this->confirmationPhrase($studentId),
            ])
            ->assertRedirect()
            ->assertSessionHas('success', 'Siswa Siswa Unknown Kosong berhasil dihapus permanen.');

        $this->assertDatabaseMissing('siswas', ['id' => $studentId]);
        $this->assertSame(0, DB::table('unresolved_student_refs')->count());
    }

    public function test_unknown_siswa_id_table_with_only_other_student_rows_does_not_block_purge(): void
    {
        $studentId = $this->insertStudent('90014', 'Siswa Unknown Lain', $this->activeClassId, true);
        $otherId = $this->insertStudent('90015', 'Siswa Pemilik Unknown', $this->activeClassId, false);
        $this->insertEnrollment($studentId, $this->activeClassId, $this->activeYearId, 1);

        Schema::create('unresolved_student_refs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('siswa_id');
            $table->timestamps();
        });

        DB::table('unresolved_student_refs')->insert([
            'siswa_id' => $otherId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAsAdmin()
            ->delete(route('admin.recycle-bin.force-delete', ['type' => 'siswa', 'id' => $studentId]), [
                'purge_confirmation' => $this->confirmationPhrase($studentId),
            ])
            ->assertRedirect()
            ->assertSessionHas('success', 'Siswa Siswa Unknown Lain berhasil dihapus permanen.');

        $this->assertDatabaseMissing('siswas', ['id' => $studentId]);
        $this->assertDatabaseHas('siswas', ['id' => $otherId, 'nama' => 'Siswa Pemilik Unknown']);
        $this->assertSame(1, DB::table('unresolved_student_refs')->where('siswa_id', $otherId)->count());
    }

    public function test_successful_siswa_purge_creates_single_permanent_purge_audit_entry(): void
    {
        DB::table('audit_logs')->delete();
        $studentId = $this->insertStudent('90016', 'Siswa Audit', $this->activeClassId, true);
        $this->insertEnrollment($studentId, $this->activeClassId, $this->activeYearId, 1);

        $this->actingAsAdmin()
            ->delete(route('admin.recycle-bin.force-delete', ['type' => 'siswa', 'id' => $studentId]), [
                'purge_confirmation' => $this->confirmationPhrase($studentId),
            ])
            ->assertRedirect()
            ->assertSessionHas('success', 'Siswa Siswa Audit berhasil dihapus permanen.');

        $this->assertSame(1, DB::table('audit_logs')->count());
        $audit = DB::table('audit_logs')->where('action', 'permanent_purge')->first();
        $this->assertNotNull($audit);
        $this->assertSame(Siswa::class, $audit->model_type);
        $this->assertSame($studentId, (int) $audit->model_id);
        $this->assertStringContainsString('purge aman recycle bin', $audit->description);

        $oldValues = json_decode((string) $audit->old_values, true);
        $newValues = json_decode((string) $audit->new_values, true);
        $this->assertSame('90016', $oldValues['nis']);
        $this->assertSame('Siswa Audit', $oldValues['nama']);
        $this->assertArrayNotHasKey('alamat', $oldValues);
        $this->assertArrayNotHasKey('photo', $oldValues);
        $this->assertSame('recycle_bin_siswa_purge', $newValues['safe_flow']);
        $this->assertSame(1, $newValues['counts']['siswa_kelas_semester']);
    }

    public function test_audit_failure_rolls_back_siswa_purge_and_dependencies(): void
    {
        $studentId = $this->insertStudent('90017', 'Siswa Audit Rollback', $this->activeClassId, true);
        $this->insertFullStudentData($studentId);

        $service = new class extends SiswaPurgeService {
            protected function writePurgeAudit(int $siswaId, array $siswaSnapshot, array $counts): void
            {
                throw new \RuntimeException('simulated audit failure');
            }
        };

        app()->instance(SiswaPurgeService::class, $service);

        $this->actingAsAdmin()
            ->delete(route('admin.recycle-bin.force-delete', ['type' => 'siswa', 'id' => $studentId]), [
                'purge_confirmation' => $this->confirmationPhrase($studentId),
            ])
            ->assertRedirect()
            ->assertSessionHas('error', 'Terjadi kesalahan saat menghapus permanen data. Silakan coba lagi.');

        $this->assertNotNull(DB::table('siswas')->where('id', $studentId)->value('deleted_at'));
        foreach ($this->studentReferenceTables() as $table) {
            $this->assertGreaterThan(0, DB::table($table)->where('siswa_id', $studentId)->count(), "Expected {$table} to be rolled back.");
        }
        $this->assertSame(0, DB::table('audit_logs')->where('action', 'permanent_purge')->count());
    }

    public function test_shared_student_photo_is_preserved_for_surviving_student(): void
    {
        $studentId = $this->insertStudent('90018', 'Siswa Foto Target', $this->activeClassId, true, 'public/photos/shared-student.jpg');
        $otherId = $this->insertStudent('90019', 'Siswa Foto Bertahan', $this->activeClassId, false, 'photos/shared-student.jpg');
        $this->insertEnrollment($studentId, $this->activeClassId, $this->activeYearId, 1);
        $this->insertEnrollment($otherId, $this->activeClassId, $this->activeYearId, 1);
        Storage::disk('public')->put('photos/shared-student.jpg', 'shared photo');

        $this->actingAsAdmin()
            ->delete(route('admin.recycle-bin.force-delete', ['type' => 'siswa', 'id' => $studentId]), [
                'purge_confirmation' => $this->confirmationPhrase($studentId),
            ])
            ->assertRedirect()
            ->assertSessionHas('success', 'Siswa Siswa Foto Target berhasil dihapus permanen.');

        $this->assertDatabaseMissing('siswas', ['id' => $studentId]);
        $this->assertDatabaseHas('siswas', [
            'id' => $otherId,
            'photo' => 'photos/shared-student.jpg',
        ]);
        Storage::disk('public')->assertExists('photos/shared-student.jpg');
    }

    public function test_shared_generated_report_file_is_preserved_for_surviving_report_history(): void
    {
        $studentId = $this->insertStudent('90020', 'Siswa Rapor Target', $this->activeClassId, true);
        $otherId = $this->insertStudent('90021', 'Siswa Rapor Bertahan', $this->activeClassId, false);
        $this->insertFullStudentData($studentId);
        $templateId = (int) DB::table('report_templates')->value('id');

        DB::table('report_generations')->insert([
            'siswa_id' => $otherId,
            'kelas_id' => $this->activeClassId,
            'tahun_ajaran_id' => $this->activeYearId,
            'report_template_id' => $templateId,
            'generated_file' => 'public/pdf_reports/siswa-purge-report.pdf',
            'type' => 'UTS',
            'generated_by' => $this->guruId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAsAdmin()
            ->delete(route('admin.recycle-bin.force-delete', ['type' => 'siswa', 'id' => $studentId]), [
                'purge_confirmation' => $this->confirmationPhrase($studentId),
            ])
            ->assertRedirect()
            ->assertSessionHas('success', 'Siswa Siswa Rapor Target berhasil dihapus permanen.');

        $this->assertDatabaseMissing('siswas', ['id' => $studentId]);
        $this->assertSame(1, DB::table('report_generations')->where('siswa_id', $otherId)->count());
        Storage::disk('public')->assertExists('pdf_reports/siswa-purge-report.pdf');
    }

    public function test_web_response_redirects_with_warning_when_post_commit_cleanup_fails(): void
    {
        $studentId = $this->insertStudent('90022', 'Siswa Cleanup Web', $this->activeClassId, true);
        $this->insertEnrollment($studentId, $this->activeClassId, $this->activeYearId, 1);
        $phrase = $this->confirmationPhrase($studentId);

        $service = Mockery::mock(SiswaPurgeService::class)->makePartial();
        $service->shouldReceive('runPostCommitCleanupSafely')->once()->andReturn(false);
        app()->instance(SiswaPurgeService::class, $service);

        $this->actingAsAdmin()
            ->delete(route('admin.recycle-bin.force-delete', ['type' => 'siswa', 'id' => $studentId]), [
                'purge_confirmation' => $phrase,
            ])
            ->assertRedirect()
            ->assertSessionHas('success', SiswaPurgeService::FILE_CLEANUP_WARNING)
            ->assertSessionMissing('error');

        $this->assertDatabaseMissing('siswas', ['id' => $studentId]);
        $this->assertSame(0, DB::table('siswa_kelas_semester')->where('siswa_id', $studentId)->count());
    }

    public function test_cleanup_failure_in_one_category_does_not_stop_later_cleanup_categories(): void
    {
        Log::spy();

        $siswa = new Siswa();
        $siswa->setAttribute($siswa->getKeyName(), 999);

        Cache::put(PdfCacheService::getCacheKey($siswa, 'UTS', $this->activeYearId), [
            'path' => 'pdf_cache/cleanup-continuation.pdf',
            'generated_at' => now(),
            'filename' => 'cleanup-continuation.pdf',
            'file_size' => 10,
        ], now()->addHour());
        Cache::put(PdfCacheService::getDocxCacheKey($siswa, 'UTS', $this->activeYearId), [
            'path' => 'docx_reports/cleanup-continuation.docx',
            'generated_at' => now(),
            'filename' => 'cleanup-continuation.docx',
        ], now()->addHour());

        config()->set('filesystems.disks.public.driver', 'unsupported-test-driver');
        app('filesystem')->forgetDisk('public');

        $cleanupComplete = app(SiswaPurgeService::class)->runPostCommitCleanupSafely([
            'siswa_id' => 999,
            'photo_path' => 'photos/cleanup-continuation.jpg',
            'generated_report_file_paths' => ['pdf_reports/cleanup-continuation.pdf'],
            'report_cache_entries' => [
                [
                    'type' => 'UTS',
                    'tahun_ajaran_id' => $this->activeYearId,
                ],
            ],
        ]);

        $this->assertFalse($cleanupComplete);
        Log::shouldHaveReceived('warning')->withArgs(fn ($message, $context = []) => str_contains($message, 'Student photo storage disk unavailable')
            && ($context['cleanup_category'] ?? null) === 'student_photo');
        Log::shouldHaveReceived('warning')->withArgs(fn ($message, $context = []) => str_contains($message, 'Generated report storage disk unavailable')
            && ($context['cleanup_category'] ?? null) === 'generated_report_files');
        Log::shouldHaveReceived('warning')->withArgs(fn ($message, $context = []) => str_contains($message, 'Report cache cleanup failed')
            && ($context['cleanup_category'] ?? null) === 'cached_pdf_file');
    }

    private function actingAsAdmin(): self
    {
        return $this->actingAs($this->admin, 'web')->withSession([
            'tahun_ajaran_id' => $this->activeYearId,
            'selected_semester' => 1,
            'no_tahun_ajaran' => false,
        ]);
    }

    private function confirmationPhrase(int $studentId): string
    {
        return app(SiswaPurgeService::class)->confirmationPhrase(Siswa::withTrashed()->findOrFail($studentId));
    }

    private function insertFullStudentData(int $studentId): void
    {
        $subjectId = $this->insertSubject($this->activeClassId, $this->activeYearId, 'Matematika');
        $lmId = DB::table('lingkup_materis')->insertGetId([
            'mata_pelajaran_id' => $subjectId,
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
        $ekskulId = DB::table('ekstrakurikulers')->insertGetId([
            'nama_ekstrakurikuler' => 'Pramuka',
            'tahun_ajaran_id' => $this->activeYearId,
            'semester' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $pembelajaranId = DB::table('pembelajarans')->insertGetId([
            'kelas_id' => $this->activeClassId,
            'mata_pelajaran_id' => $subjectId,
            'guru_id' => $this->guruId,
            'tahun_ajaran' => '2025/2026',
            'semester' => 'ganjil',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $templateId = DB::table('report_templates')->insertGetId([
            'filename' => 'template.docx',
            'path' => 'templates/template.docx',
            'type' => 'UTS',
            'is_active' => true,
            'tahun_ajaran_id' => $this->activeYearId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->insertEnrollment($studentId, $this->activeClassId, $this->activeYearId, 1);
        $this->insertEnrollment($studentId, $this->oldClassId, $this->oldYearId, 2);

        DB::table('pembelajaran_siswa')->insert([
            'pembelajaran_id' => $pembelajaranId,
            'siswa_id' => $studentId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('nilais')->insert([
            'siswa_id' => $studentId,
            'mata_pelajaran_id' => $subjectId,
            'lingkup_materi_id' => $lmId,
            'tujuan_pembelajaran_id' => $tpId,
            'tahun_ajaran_id' => $this->activeYearId,
            'semester' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('absensis')->insert([
            'siswa_id' => $studentId,
            'tahun_ajaran_id' => $this->activeYearId,
            'semester' => 1,
            'sakit' => 1,
            'izin' => 0,
            'tanpa_keterangan' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('catatan_siswa')->insert([
            'siswa_id' => $studentId,
            'tahun_ajaran_id' => $this->activeYearId,
            'semester' => 1,
            'type' => 'UTS',
            'catatan' => 'Catatan siswa',
            'created_by' => $this->guruId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('catatan_mata_pelajaran')->insert([
            'mata_pelajaran_id' => $subjectId,
            'siswa_id' => $studentId,
            'tahun_ajaran_id' => $this->activeYearId,
            'semester' => 1,
            'type' => 'UTS',
            'catatan' => 'Catatan mapel',
            'created_by' => $this->guruId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('capaian_custom')->insert([
            'siswa_id' => $studentId,
            'mata_pelajaran_id' => $subjectId,
            'tahun_ajaran_id' => $this->activeYearId,
            'semester' => 1,
            'tipe' => 'tertinggi',
            'deskripsi' => 'Capaian custom',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('nilai_ekstrakurikuler')->insert([
            'siswa_id' => $studentId,
            'ekstrakurikuler_id' => $ekskulId,
            'tahun_ajaran_id' => $this->activeYearId,
            'semester' => 1,
            'nilai' => 'A',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('prestasis')->insert([
            'siswa_id' => $studentId,
            'kelas_id' => $this->activeClassId,
            'tahun_ajaran_id' => $this->activeYearId,
            'semester' => 1,
            'jenis_prestasi' => 'Akademik',
            'keterangan' => 'Juara',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('report_generations')->insert([
            'siswa_id' => $studentId,
            'kelas_id' => $this->activeClassId,
            'tahun_ajaran_id' => $this->activeYearId,
            'report_template_id' => $templateId,
            'generated_file' => 'pdf_reports/siswa-purge-report.pdf',
            'type' => 'UTS',
            'generated_by' => $this->guruId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Storage::disk('public')->put('pdf_reports/siswa-purge-report.pdf', 'report');
        Storage::disk('public')->put('pdf_cache/siswa-purge-cache.pdf', 'cached pdf');
        Storage::disk('public')->put('docx_reports/siswa-purge-cache.docx', 'cached docx');

        $siswa = Siswa::withTrashed()->findOrFail($studentId);
        $requestId = 'request-siswa-purge';
        Cache::put(PdfCacheService::getCacheKey($siswa, 'UTS', $this->activeYearId), [
            'path' => 'pdf_cache/siswa-purge-cache.pdf',
            'generated_at' => now(),
            'filename' => 'siswa-purge-cache.pdf',
            'file_size' => 10,
        ], now()->addHour());
        Cache::put(PdfCacheService::getDocxCacheKey($siswa, 'UTS', $this->activeYearId), [
            'path' => 'docx_reports/siswa-purge-cache.docx',
            'generated_at' => now(),
            'filename' => 'siswa-purge-cache.docx',
        ], now()->addHour());
        Cache::put(PdfCacheService::getGenerationRequestKey($siswa, 'UTS', $this->activeYearId), $requestId, now()->addHour());
        Cache::put(PdfCacheService::getProgressKey($requestId), ['status' => 'processing'], now()->addHour());
        Cache::put(PdfCacheService::getAutoPrepareTokenKey($siswa, 'UTS', $this->activeYearId), 'scheduled', now()->addHour());
        Cache::put(PdfCacheService::getGenerationLockKey($siswa, 'UTS', $this->activeYearId), true, now()->addHour());
        Cache::put("pdf_cache_index_{$studentId}", [
            [
                'type' => 'UTS',
                'tahun_ajaran_id' => $this->activeYearId,
            ],
        ], now()->addHour());
    }

    private function insertStudent(string $nis, string $name, int $classId, bool $trashed = false, ?string $photo = null): int
    {
        return DB::table('siswas')->insertGetId([
            'nis' => $nis,
            'nisn' => $nis.'99',
            'nama' => $name,
            'tanggal_lahir' => '2015-01-01',
            'jenis_kelamin' => 'Laki-laki',
            'agama' => 'Islam',
            'alamat' => 'Jl. Siswa',
            'kelas_id' => $classId,
            'nama_ayah' => 'Ayah',
            'nama_ibu' => 'Ibu',
            'pekerjaan_ayah' => 'Wiraswasta',
            'pekerjaan_ibu' => 'Wiraswasta',
            'alamat_orangtua' => 'Jl. Orang Tua',
            'photo' => $photo,
            'wali_siswa' => null,
            'pekerjaan_wali' => null,
            'tahun_ajaran_id' => $this->activeYearId,
            'status' => 'aktif',
            'deleted_at' => $trashed ? now() : null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function insertEnrollment(int $studentId, int $classId, int $yearId, int $semester): int
    {
        return DB::table('siswa_kelas_semester')->insertGetId([
            'siswa_id' => $studentId,
            'kelas_id' => $classId,
            'tahun_ajaran_id' => $yearId,
            'semester' => $semester,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function insertSubject(int $classId, int $yearId, string $name): int
    {
        return DB::table('mata_pelajarans')->insertGetId([
            'nama_pelajaran' => $name,
            'kelas_id' => $classId,
            'guru_id' => $this->guruId,
            'semester' => 1,
            'tahun_ajaran_id' => $yearId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function assertNoSiswaReferencesRemain(int $studentId): void
    {
        foreach ($this->studentReferenceTables() as $table) {
            $this->assertSame(0, DB::table($table)->where('siswa_id', $studentId)->count(), "Expected {$table} to have no target siswa_id references.");
        }
    }

    /**
     * @return array<int, string>
     */
    private function studentReferenceTables(): array
    {
        return [
            'pembelajaran_siswa',
            'report_generations',
            'nilais',
            'catatan_mata_pelajaran',
            'catatan_siswa',
            'capaian_custom',
            'nilai_ekstrakurikuler',
            'prestasis',
            'absensis',
            'siswa_kelas_semester',
        ];
    }

    private function seedFixture(): void
    {
        $this->admin = User::create([
            'name' => 'Admin',
            'username' => 'admin',
            'email' => 'admin@example.test',
            'password' => Hash::make('password'),
        ]);

        $this->activeYearId = DB::table('tahun_ajarans')->insertGetId([
            'tahun_ajaran' => '2025/2026',
            'semester' => 1,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->oldYearId = DB::table('tahun_ajarans')->insertGetId([
            'tahun_ajaran' => '2024/2025',
            'semester' => 2,
            'is_active' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('profil_sekolah')->insert([
            'nama_sekolah' => 'SDIT Al-Hidayah',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->guruId = DB::table('gurus')->insertGetId([
            'nama' => 'Guru Test',
            'username' => 'guru-test',
            'password' => Hash::make('password'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->activeClassId = DB::table('kelas')->insertGetId([
            'nomor_kelas' => 5,
            'nama_kelas' => 'A',
            'tahun_ajaran_id' => $this->activeYearId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->oldClassId = DB::table('kelas')->insertGetId([
            'nomor_kelas' => 4,
            'nama_kelas' => 'A',
            'tahun_ajaran_id' => $this->oldYearId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function createSchema(): void
    {
        foreach ([
            'audit_logs',
            'report_generations',
            'report_templates',
            'pembelajaran_siswa',
            'pembelajarans',
            'prestasis',
            'nilai_ekstrakurikuler',
            'capaian_custom',
            'catatan_mata_pelajaran',
            'catatan_siswa',
            'absensis',
            'nilais',
            'siswa_kelas_semester',
            'siswas',
            'ekstrakurikulers',
            'tujuan_pembelajarans',
            'lingkup_materis',
            'mata_pelajarans',
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
            $table->rememberToken();
            $table->timestamps();
        });

        Schema::create('tahun_ajarans', function (Blueprint $table) {
            $table->id();
            $table->string('tahun_ajaran');
            $table->unsignedTinyInteger('semester')->default(1);
            $table->boolean('is_active')->default(false);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('profil_sekolah', function (Blueprint $table) {
            $table->id();
            $table->string('nama_sekolah')->nullable();
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
            $table->string('photo')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('kelas', function (Blueprint $table) {
            $table->id();
            $table->unsignedTinyInteger('nomor_kelas');
            $table->string('nama_kelas');
            $table->foreignId('tahun_ajaran_id')->nullable()->constrained('tahun_ajarans')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('mata_pelajarans', function (Blueprint $table) {
            $table->id();
            $table->string('nama_pelajaran');
            $table->foreignId('kelas_id')->nullable()->constrained('kelas')->nullOnDelete();
            $table->foreignId('guru_id')->nullable()->constrained('gurus')->nullOnDelete();
            $table->unsignedTinyInteger('semester')->default(1);
            $table->foreignId('tahun_ajaran_id')->nullable()->constrained('tahun_ajarans')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('lingkup_materis', function (Blueprint $table) {
            $table->id();
            $table->foreignId('mata_pelajaran_id')->nullable()->constrained('mata_pelajarans')->nullOnDelete();
            $table->string('judul_lingkup_materi');
            $table->timestamps();
        });

        Schema::create('tujuan_pembelajarans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lingkup_materi_id')->nullable()->constrained('lingkup_materis')->nullOnDelete();
            $table->string('kode_tp')->nullable();
            $table->text('deskripsi_tp')->nullable();
            $table->timestamps();
        });

        Schema::create('ekstrakurikulers', function (Blueprint $table) {
            $table->id();
            $table->string('nama_ekstrakurikuler');
            $table->foreignId('tahun_ajaran_id')->nullable()->constrained('tahun_ajarans')->nullOnDelete();
            $table->unsignedTinyInteger('semester')->nullable();
            $table->timestamps();
        });

        Schema::create('siswas', function (Blueprint $table) {
            $table->id();
            $table->string('nis')->unique();
            $table->string('nisn')->unique();
            $table->string('nama');
            $table->date('tanggal_lahir')->nullable();
            $table->string('jenis_kelamin')->nullable();
            $table->string('agama')->nullable();
            $table->text('alamat')->nullable();
            $table->foreignId('kelas_id')->nullable()->constrained('kelas')->nullOnDelete();
            $table->string('nama_ayah')->nullable();
            $table->string('nama_ibu')->nullable();
            $table->string('pekerjaan_ayah')->nullable();
            $table->string('pekerjaan_ibu')->nullable();
            $table->text('alamat_orangtua')->nullable();
            $table->string('photo')->nullable();
            $table->string('wali_siswa')->nullable();
            $table->string('pekerjaan_wali')->nullable();
            $table->foreignId('tahun_ajaran_id')->nullable()->constrained('tahun_ajarans')->nullOnDelete();
            $table->string('status')->default('aktif');
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('siswa_kelas_semester', function (Blueprint $table) {
            $table->id();
            $table->foreignId('siswa_id')->constrained('siswas')->restrictOnDelete();
            $table->foreignId('kelas_id')->constrained('kelas')->restrictOnDelete();
            $table->foreignId('tahun_ajaran_id')->constrained('tahun_ajarans')->restrictOnDelete();
            $table->unsignedTinyInteger('semester');
            $table->timestamps();
            $table->unique(['siswa_id', 'tahun_ajaran_id', 'semester']);
        });

        Schema::create('nilais', function (Blueprint $table) {
            $table->id();
            $table->foreignId('siswa_id')->constrained('siswas')->cascadeOnDelete();
            $table->foreignId('mata_pelajaran_id')->nullable()->constrained('mata_pelajarans')->nullOnDelete();
            $table->foreignId('lingkup_materi_id')->nullable()->constrained('lingkup_materis')->nullOnDelete();
            $table->foreignId('tujuan_pembelajaran_id')->nullable()->constrained('tujuan_pembelajarans')->nullOnDelete();
            $table->foreignId('tahun_ajaran_id')->nullable()->constrained('tahun_ajarans')->nullOnDelete();
            $table->unsignedTinyInteger('semester')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('absensis', function (Blueprint $table) {
            $table->id();
            $table->foreignId('siswa_id')->constrained('siswas')->cascadeOnDelete();
            $table->foreignId('tahun_ajaran_id')->nullable()->constrained('tahun_ajarans')->nullOnDelete();
            $table->unsignedTinyInteger('semester')->default(1);
            $table->unsignedTinyInteger('sakit')->default(0);
            $table->unsignedTinyInteger('izin')->default(0);
            $table->unsignedTinyInteger('tanpa_keterangan')->default(0);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('catatan_siswa', function (Blueprint $table) {
            $table->id();
            $table->foreignId('siswa_id')->constrained('siswas')->cascadeOnDelete();
            $table->foreignId('tahun_ajaran_id')->nullable()->constrained('tahun_ajarans')->nullOnDelete();
            $table->unsignedTinyInteger('semester')->default(1);
            $table->string('type')->default('umum');
            $table->text('catatan')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('gurus')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('catatan_mata_pelajaran', function (Blueprint $table) {
            $table->id();
            $table->foreignId('mata_pelajaran_id')->nullable()->constrained('mata_pelajarans')->nullOnDelete();
            $table->foreignId('siswa_id')->constrained('siswas')->cascadeOnDelete();
            $table->foreignId('tahun_ajaran_id')->nullable()->constrained('tahun_ajarans')->nullOnDelete();
            $table->unsignedTinyInteger('semester')->default(1);
            $table->string('type')->default('umum');
            $table->text('catatan')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('gurus')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('capaian_custom', function (Blueprint $table) {
            $table->id();
            $table->foreignId('siswa_id')->constrained('siswas')->cascadeOnDelete();
            $table->foreignId('mata_pelajaran_id')->nullable()->constrained('mata_pelajarans')->nullOnDelete();
            $table->foreignId('tahun_ajaran_id')->nullable()->constrained('tahun_ajarans')->nullOnDelete();
            $table->unsignedTinyInteger('semester')->default(1);
            $table->string('tipe')->nullable();
            $table->text('deskripsi')->nullable();
            $table->timestamps();
        });

        Schema::create('nilai_ekstrakurikuler', function (Blueprint $table) {
            $table->id();
            $table->foreignId('siswa_id')->constrained('siswas')->cascadeOnDelete();
            $table->foreignId('ekstrakurikuler_id')->nullable()->constrained('ekstrakurikulers')->nullOnDelete();
            $table->foreignId('tahun_ajaran_id')->nullable()->constrained('tahun_ajarans')->nullOnDelete();
            $table->unsignedTinyInteger('semester')->nullable();
            $table->string('nilai')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('prestasis', function (Blueprint $table) {
            $table->id();
            $table->foreignId('siswa_id')->constrained('siswas')->cascadeOnDelete();
            $table->foreignId('kelas_id')->nullable()->constrained('kelas')->nullOnDelete();
            $table->foreignId('tahun_ajaran_id')->nullable()->constrained('tahun_ajarans')->nullOnDelete();
            $table->unsignedTinyInteger('semester')->nullable();
            $table->string('jenis_prestasi');
            $table->text('keterangan')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('pembelajarans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('kelas_id')->constrained('kelas')->cascadeOnDelete();
            $table->foreignId('mata_pelajaran_id')->constrained('mata_pelajarans')->cascadeOnDelete();
            $table->foreignId('guru_id')->constrained('gurus')->cascadeOnDelete();
            $table->string('tahun_ajaran');
            $table->string('semester');
            $table->timestamps();
        });

        Schema::create('pembelajaran_siswa', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pembelajaran_id')->constrained('pembelajarans')->cascadeOnDelete();
            $table->foreignId('siswa_id')->constrained('siswas')->cascadeOnDelete();
            $table->timestamps();
        });

        Schema::create('report_templates', function (Blueprint $table) {
            $table->id();
            $table->string('filename');
            $table->string('path')->nullable();
            $table->string('type');
            $table->boolean('is_active')->default(false);
            $table->foreignId('tahun_ajaran_id')->nullable()->constrained('tahun_ajarans')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('report_generations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('siswa_id')->constrained('siswas')->cascadeOnDelete();
            $table->foreignId('kelas_id')->nullable()->constrained('kelas')->nullOnDelete();
            $table->foreignId('tahun_ajaran_id')->nullable()->constrained('tahun_ajarans')->nullOnDelete();
            $table->foreignId('report_template_id')->nullable()->constrained('report_templates')->nullOnDelete();
            $table->string('generated_file')->nullable();
            $table->string('type');
            $table->foreignId('generated_by')->nullable()->constrained('gurus')->nullOnDelete();
            $table->timestamps();
        });
    }
}

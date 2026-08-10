<?php

namespace Tests\Feature;

use App\Models\Siswa;
use App\Models\TahunAjaran;
use App\Models\User;
use App\Services\PdfCacheService;
use App\Services\TahunAjaranPurgeException;
use App\Services\TahunAjaranPurgeService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Tests\TestCase;

class TahunAjaranPermanentDeleteSafetyTest extends TestCase
{
    private User $admin;

    private int $activeYearId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->withoutMiddleware(PreventRequestForgery::class);
        Event::fake();

        config()->set('database.default', 'sqlite');
        config()->set('database.connections.sqlite.database', ':memory:');
        config()->set('cache.default', 'array');
        config()->set('session.driver', 'array');
        config()->set('filesystems.default', 'local');
        DB::purge('sqlite');
        DB::reconnect('sqlite');
        DB::statement('PRAGMA foreign_keys = ON');
        Cache::flush();
        Storage::fake('public');

        $this->createSchema();
        $this->seedFixture();
    }

    public function test_purge_requires_archived_inactive_period_active_replacement_and_exact_confirmation(): void
    {
        $activeArchivedId = $this->insertYear('2030/2031', 1, true, true);
        $inactiveOpenId = $this->insertYear('2031/2032', 1, false, false);
        $archivedId = $this->insertYear('2032/2033', 2, false, true);

        $this->deleteJsonWithConfirmation($activeArchivedId)
            ->assertStatus(422)
            ->assertJsonPath('message', 'Tahun ajaran aktif tidak dapat dihapus permanen. Nonaktifkan atau pilih tahun ajaran lain terlebih dahulu.');

        $this->deleteJsonWithConfirmation($inactiveOpenId)
            ->assertStatus(422)
            ->assertJsonPath('message', 'Hanya tahun ajaran yang sudah diarsipkan yang dapat dihapus permanen.');

        $this->actingAs($this->admin, 'web')
            ->deleteJson(route('tahun.ajaran.force-delete', $archivedId))
            ->assertStatus(422)
            ->assertJsonPath('message', 'Konfirmasi tidak sesuai. Ketik kalimat yang diminta untuk menghapus permanen.');

        $this->actingAs($this->admin, 'web')
            ->deleteJson(route('tahun.ajaran.force-delete', $archivedId), [
                'purge_confirmation' => 'hapus permanen',
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Konfirmasi tidak sesuai. Ketik kalimat yang diminta untuk menghapus permanen.');

        DB::table('tahun_ajarans')->where('id', $this->activeYearId)->update(['is_active' => false]);

        $this->deleteJsonWithConfirmation($archivedId)
            ->assertStatus(422)
            ->assertJsonPath('message', 'Tidak ada tahun ajaran aktif pengganti. Aktifkan periode yang akan dipakai sebelum menghapus permanen.');

        $this->assertSame(1, DB::table('tahun_ajarans')->where('id', $archivedId)->count());
    }

    public function test_archived_period_with_owned_academic_data_is_purged_and_shared_identity_is_preserved(): void
    {
        $targetYearId = $this->insertYear('2025/2026', 2, false, true);
        $targetClassId = $this->insertClass($targetYearId, '5', 'B');
        $activeClassId = $this->insertClass($this->activeYearId, '6', 'B');
        $otherYearId = $this->insertYear('2028/2029', 1, false, false);
        $otherClassId = $this->insertClass($otherYearId, '5', 'B');
        $guruId = $this->insertGuru('Guru Shared');
        $siswaId = $this->insertStudent($targetClassId, $targetYearId, '2605001', '9000000001', 'Ahmad Fauzan');
        $targetSubjectId = $this->insertSubject($targetYearId, $targetClassId, 'Matematika');
        $targetLmId = $this->insertLingkupMateri($targetSubjectId);
        $targetTpId = $this->insertTujuanPembelajaran($targetLmId);
        $targetEkskulId = $this->insertEkskul($targetYearId);
        $templatePath = 'templates/semester-copies/'.$targetYearId.'/owned-template.docx';
        $targetTemplateId = $this->insertReportTemplate($targetYearId, $templatePath, $targetClassId, true);
        $otherTemplatePath = 'templates/other-period-template.docx';
        $otherTemplateId = $this->insertReportTemplate($otherYearId, $otherTemplatePath, $otherClassId, true);
        $otherEkskulId = $this->insertEkskul($otherYearId);

        Storage::disk('public')->put($templatePath, 'target-template');
        Storage::disk('public')->put($otherTemplatePath, 'other-template');
        Storage::disk('public')->put('pdf_reports/generated.pdf', 'target-report');
        Storage::disk('public')->put('pdf_reports/cached-target.pdf', 'cached-pdf');
        Storage::disk('public')->put('docx_reports/cached-target.docx', 'cached-docx');

        $this->insertEnrollment($siswaId, $targetClassId, $targetYearId, 2);
        $this->insertEnrollment($siswaId, $activeClassId, $this->activeYearId, 2);
        $this->insertOwnedRows($targetYearId, $targetClassId, $targetSubjectId, $targetLmId, $targetTpId, $targetEkskulId, $targetTemplateId, $guruId, $siswaId);
        DB::table('nilai_ekstrakurikuler')->insert([
            'siswa_id' => $siswaId,
            'ekstrakurikuler_id' => $otherEkskulId,
            'tahun_ajaran_id' => $otherYearId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $otherSubjectId = $this->insertSubject($otherYearId, $otherClassId, 'Matematika');
        $siswa = Siswa::findOrFail($siswaId);
        Cache::put(PdfCacheService::getCacheKey($siswa, 'UTS', $targetYearId, 2), [
            'path' => 'pdf_reports/cached-target.pdf',
            'generated_at' => now()->toISOString(),
        ], now()->addHour());
        Cache::put(PdfCacheService::getDocxCacheKey($siswa, 'UTS', $targetYearId, 2), [
            'path' => 'docx_reports/cached-target.docx',
            'generated_at' => now()->toISOString(),
        ], now()->addHour());
        Cache::put(PdfCacheService::getGenerationRequestKey($siswa, 'UTS', $targetYearId, 2), 'request-target', now()->addHour());
        Cache::put(PdfCacheService::getProgressKey('request-target'), ['completed' => false, 'updated_at' => now()->timestamp], now()->addHour());
        Cache::put(PdfCacheService::getAutoPrepareTokenKey($siswa, 'UTS', $targetYearId, 2), 'token-target', now()->addHour());

        $studentIdsBefore = DB::table('siswas')->pluck('id')->all();
        $guruIdsBefore = DB::table('gurus')->pluck('id')->all();
        $userIdsBefore = DB::table('users')->pluck('id')->all();
        $profileIdsBefore = DB::table('profil_sekolah')->pluck('id')->all();

        $this->deleteWithConfirmation($targetYearId)
            ->assertRedirect(route('tahun.ajaran.index', ['showArchived' => 'true']))
            ->assertSessionHas('success', 'Tahun ajaran berhasil dihapus permanen.');

        $this->assertSame(0, DB::table('tahun_ajarans')->where('id', $targetYearId)->count());
        $this->assertSame(0, DB::table('kelas')->where('id', $targetClassId)->count());
        $this->assertSame(0, DB::table('siswa_kelas_semester')->where('tahun_ajaran_id', $targetYearId)->count());
        $this->assertSame(0, DB::table('mata_pelajarans')->where('id', $targetSubjectId)->count());
        $this->assertSame(0, DB::table('lingkup_materis')->where('id', $targetLmId)->count());
        $this->assertSame(0, DB::table('tujuan_pembelajarans')->where('id', $targetTpId)->count());
        $this->assertSame(0, DB::table('nilais')->where('tahun_ajaran_id', $targetYearId)->count());
        $this->assertSame(0, DB::table('absensis')->where('tahun_ajaran_id', $targetYearId)->count());
        $this->assertSame(0, DB::table('catatan_siswa')->where('tahun_ajaran_id', $targetYearId)->count());
        $this->assertSame(0, DB::table('catatan_mata_pelajaran')->where('tahun_ajaran_id', $targetYearId)->count());
        $this->assertSame(0, DB::table('capaian_custom')->where('tahun_ajaran_id', $targetYearId)->count());
        $this->assertSame(0, DB::table('nilai_ekstrakurikuler')->where('tahun_ajaran_id', $targetYearId)->count());
        $this->assertSame(0, DB::table('ekstrakurikulers')->where('id', $targetEkskulId)->count());
        $this->assertSame(0, DB::table('kkms')->where('tahun_ajaran_id', $targetYearId)->count());
        $this->assertSame(0, DB::table('bobot_nilais')->where('tahun_ajaran_id', $targetYearId)->count());
        $this->assertSame(0, DB::table('report_generations')->where('tahun_ajaran_id', $targetYearId)->count());
        $this->assertSame(0, DB::table('report_templates')->where('id', $targetTemplateId)->count());
        $this->assertSame(0, DB::table('semester_snapshots')->where('tahun_ajaran_id', $targetYearId)->count());

        $this->assertEqualsCanonicalizing($studentIdsBefore, DB::table('siswas')->pluck('id')->all());
        $this->assertEqualsCanonicalizing($guruIdsBefore, DB::table('gurus')->pluck('id')->all());
        $this->assertEqualsCanonicalizing($userIdsBefore, DB::table('users')->pluck('id')->all());
        $this->assertEqualsCanonicalizing($profileIdsBefore, DB::table('profil_sekolah')->pluck('id')->all());
        $this->assertSame($activeClassId, (int) DB::table('siswas')->where('id', $siswaId)->value('kelas_id'));
        $this->assertSame($this->activeYearId, (int) DB::table('siswas')->where('id', $siswaId)->value('tahun_ajaran_id'));

        $this->assertSame(1, DB::table('tahun_ajarans')->where('id', $otherYearId)->count());
        $this->assertSame(1, DB::table('kelas')->where('id', $otherClassId)->count());
        $this->assertSame(1, DB::table('mata_pelajarans')->where('id', $otherSubjectId)->count());
        $this->assertSame(1, DB::table('ekstrakurikulers')->where('id', $otherEkskulId)->count());
        $this->assertSame(1, DB::table('nilai_ekstrakurikuler')->where('ekstrakurikuler_id', $otherEkskulId)->count());
        $this->assertSame(1, DB::table('report_templates')->where('id', $otherTemplateId)->count());
        Storage::disk('public')->assertMissing($templatePath);
        Storage::disk('public')->assertMissing('pdf_reports/generated.pdf');
        Storage::disk('public')->assertMissing('pdf_reports/cached-target.pdf');
        Storage::disk('public')->assertMissing('docx_reports/cached-target.docx');
        Storage::disk('public')->assertExists($otherTemplatePath);
        $this->assertFalse(Cache::has(PdfCacheService::getCacheKey($siswa, 'UTS', $targetYearId, 2)));
        $this->assertFalse(Cache::has(PdfCacheService::getDocxCacheKey($siswa, 'UTS', $targetYearId, 2)));
        $this->assertFalse(Cache::has(PdfCacheService::getGenerationRequestKey($siswa, 'UTS', $targetYearId, 2)));
        $this->assertFalse(Cache::has(PdfCacheService::getProgressKey('request-target')));
        $this->assertFalse(Cache::has(PdfCacheService::getAutoPrepareTokenKey($siswa, 'UTS', $targetYearId, 2)));

        $this->assertSame(1, DB::table('audit_logs')->where('action', 'permanent_purge')->where('model_id', $targetYearId)->count());
    }

    public function test_student_without_active_period_enrollment_blocks_purge_and_rolls_back_everything(): void
    {
        $targetYearId = $this->insertYear('2033/2034', 2, false, true);
        $targetClassId = $this->insertClass($targetYearId);
        $siswaId = $this->insertStudent($targetClassId, $targetYearId, '2605002', '9000000002', 'Siswa Tanpa Kelas Aktif');
        $templatePath = 'templates/blocked-owned-template.docx';
        $templateId = $this->insertReportTemplate($targetYearId, $templatePath);
        Storage::disk('public')->put($templatePath, 'template');
        $this->insertEnrollment($siswaId, $targetClassId, $targetYearId, 2);

        $this->deleteWithConfirmation($targetYearId)
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertStringContainsString('siswa yang belum memiliki kelas pengganti', session('error'));
        $this->assertSame($targetClassId, (int) DB::table('siswas')->where('id', $siswaId)->value('kelas_id'));
        $this->assertSame(1, DB::table('tahun_ajarans')->where('id', $targetYearId)->count());
        $this->assertSame(1, DB::table('kelas')->where('id', $targetClassId)->count());
        $this->assertSame(1, DB::table('report_templates')->where('id', $templateId)->count());
        Storage::disk('public')->assertExists($templatePath);
    }

    public function test_database_failure_rolls_back_student_remap_dependency_deletion_and_file_cleanup(): void
    {
        $targetYearId = $this->insertYear('2034/2035', 2, false, true);
        $targetClassId = $this->insertClass($targetYearId);
        $activeClassId = $this->insertClass($this->activeYearId, '6', 'C');
        $siswaId = $this->insertStudent($targetClassId, $targetYearId, '2605003', '9000000003', 'Rollback Siswa');
        $subjectId = $this->insertSubject($targetYearId, $targetClassId);
        $templatePath = 'templates/rollback-owned-template.docx';
        $templateId = $this->insertReportTemplate($targetYearId, $templatePath);
        Storage::disk('public')->put($templatePath, 'template');
        Storage::disk('public')->put('pdf_reports/generated.pdf', 'report');
        $this->insertEnrollment($siswaId, $targetClassId, $targetYearId, 2);
        $this->insertEnrollment($siswaId, $activeClassId, $this->activeYearId, 2);
        $this->insertReportGeneration($targetYearId, $templateId, $targetClassId);
        DB::table('nilais')->insert([
            'tahun_ajaran_id' => $targetYearId,
            'mata_pelajaran_id' => $subjectId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::statement("
            CREATE TRIGGER fail_target_score_delete
            BEFORE DELETE ON nilais
            BEGIN
                SELECT RAISE(ABORT, 'forced purge failure');
            END
        ");

        $this->deleteWithConfirmation($targetYearId)
            ->assertRedirect()
            ->assertSessionHas('error', 'Terjadi kesalahan saat menghapus permanen tahun ajaran. Silakan coba lagi.');

        $this->assertSame($targetClassId, (int) DB::table('siswas')->where('id', $siswaId)->value('kelas_id'));
        $this->assertSame($targetYearId, (int) DB::table('siswas')->where('id', $siswaId)->value('tahun_ajaran_id'));
        $this->assertSame(1, DB::table('tahun_ajarans')->where('id', $targetYearId)->count());
        $this->assertSame(1, DB::table('kelas')->where('id', $targetClassId)->count());
        $this->assertSame(1, DB::table('nilais')->where('tahun_ajaran_id', $targetYearId)->count());
        $this->assertSame(1, DB::table('report_templates')->where('tahun_ajaran_id', $targetYearId)->count());
        $this->assertSame(1, DB::table('report_generations')->where('tahun_ajaran_id', $targetYearId)->count());
        Storage::disk('public')->assertExists($templatePath);
        Storage::disk('public')->assertExists('pdf_reports/generated.pdf');
    }

    public function test_shared_template_path_and_legacy_path_forms_are_preserved(): void
    {
        $this->assertSame('local', config('filesystems.default'));

        $targetYearId = $this->insertYear('2035/2036', 1, false, true);
        $survivingYearId = $this->insertYear('2036/2037', 1, false, false);
        $sharedPath = 'templates/shared-template.docx';
        $sharedReportPath = 'pdf_reports/shared-report.pdf';
        $targetTemplateId = $this->insertReportTemplate($targetYearId, 'public/'.$sharedPath);
        $survivingTemplateId = $this->insertReportTemplate($survivingYearId, '\\'.$sharedPath);
        $targetReportId = $this->insertReportGeneration($targetYearId, $targetTemplateId, null, 'storage/'.$sharedReportPath);
        $survivingReportId = $this->insertReportGeneration($survivingYearId, $survivingTemplateId, null, '\\'.$sharedReportPath);
        Storage::disk('public')->put($sharedPath, 'shared-template');
        Storage::disk('public')->put($sharedReportPath, 'shared-report');

        $this->deleteWithConfirmation($targetYearId)
            ->assertRedirect(route('tahun.ajaran.index', ['showArchived' => 'true']))
            ->assertSessionHas('success', 'Tahun ajaran berhasil dihapus permanen.');

        $this->assertSame(0, DB::table('report_templates')->where('id', $targetTemplateId)->count());
        $this->assertSame(1, DB::table('report_templates')->where('id', $survivingTemplateId)->count());
        $this->assertSame(0, DB::table('report_generations')->where('id', $targetReportId)->count());
        $this->assertSame(1, DB::table('report_generations')->where('id', $survivingReportId)->count());
        Storage::disk('public')->assertExists($sharedPath);
        Storage::disk('public')->assertExists($sharedReportPath);
    }

    public function test_post_commit_file_cleanup_failure_returns_warning_and_logs_without_rolling_back_database(): void
    {
        Log::spy();

        $targetYearId = $this->insertYear('2037/2038', 1, false, true);
        $path = 'templates/failure-owned-template.docx';
        $this->insertReportTemplate($targetYearId, $path);

        $disk = Mockery::mock();
        $disk->shouldReceive('exists')->with($path)->andReturn(true);
        $disk->shouldReceive('delete')->with($path)->andReturn(false);
        Storage::shouldReceive('disk')->with('public')->andReturn($disk);

        $this->deleteWithConfirmation($targetYearId)
            ->assertRedirect(route('tahun.ajaran.index', ['showArchived' => 'true']))
            ->assertSessionHas('success', 'Tahun ajaran berhasil dihapus permanen, tetapi ada file periode yang perlu dibersihkan oleh administrator sistem.');

        $this->assertSame(0, DB::table('tahun_ajarans')->where('id', $targetYearId)->count());
        $this->assertSame(0, DB::table('report_templates')->where('tahun_ajaran_id', $targetYearId)->count());
        Log::shouldHaveReceived('warning')->withArgs(fn ($message, $context) => str_contains($message, 'Failed to delete report template file')
            && ($context['tahun_ajaran_id'] ?? null) === $targetYearId
            && ($context['path'] ?? null) === $path);
    }

    public function test_archived_show_renders_purge_modal_summary_and_reopens_after_validation_error(): void
    {
        $targetYearId = $this->insertYear('2038/2039', 2, false, true);
        $classId = $this->insertClass($targetYearId);
        $activeClassId = $this->insertClass($this->activeYearId, '6', 'D');
        $siswaId = $this->insertStudent($classId, $targetYearId, '2605004', '9000000004', 'Modal Siswa');
        $templateId = $this->insertReportTemplate($targetYearId, 'templates/modal-template.docx');
        $this->insertEnrollment($siswaId, $classId, $targetYearId, 2);
        $this->insertEnrollment($siswaId, $activeClassId, $this->activeYearId, 2);
        $this->insertReportGeneration($targetYearId, $templateId, $classId);

        $phrase = $this->confirmationPhrase($targetYearId);

        $response = $this->actingAs($this->admin, 'web')
            ->get(route('tahun.ajaran.show', $targetYearId))
            ->assertOk()
            ->assertSeeText('Hapus Permanen')
            ->assertSee('data-permanent-purge-trigger', false)
            ->assertSee('data-permanent-purge-modal', false)
            ->assertSee('purgeModalOpen: false', false)
            ->assertSeeText('Ringkasan data yang akan dipurge')
            ->assertSeeText('Periode aktif pengganti')
            ->assertSeeText($phrase)
            ->assertSee('name="purge_confirmation"', false)
            ->assertSee('x-bind:readonly="purgeSubmitting"', false);

        $content = $response->getContent();
        $this->assertMatchesRegularExpression(
            '/<input\b(?=[^>]*id="purge_confirmation")(?=[^>]*name="purge_confirmation")(?=[^>]*x-bind:readonly="purgeSubmitting")[^>]*>/',
            $content
        );
        $this->assertDoesNotMatchRegularExpression(
            '/<input\b(?=[^>]*id="purge_confirmation")(?=[^>]*x-bind:disabled="purgeSubmitting")[^>]*>/',
            $content
        );

        $this->actingAs($this->admin, 'web')
            ->from(route('tahun.ajaran.show', $targetYearId))
            ->delete(route('tahun.ajaran.force-delete', $targetYearId), [
                'purge_confirmation' => 'SALAH',
            ])
            ->assertRedirect(route('tahun.ajaran.show', $targetYearId))
            ->assertSessionHasErrors('purge_confirmation');

        $this->actingAs($this->admin, 'web')
            ->get(route('tahun.ajaran.show', $targetYearId))
            ->assertOk()
            ->assertSee('purgeModalOpen: true', false)
            ->assertSeeText('Konfirmasi tidak sesuai. Ketik kalimat yang diminta untuk menghapus permanen.');

        $this->assertStringNotContainsString('SQLSTATE', $response->getContent());
    }

    public function test_active_or_non_archived_views_do_not_expose_permanent_purge_action(): void
    {
        $inactiveOpenId = $this->insertYear('2039/2040', 1, false, false);
        $archivedActiveId = $this->insertYear('2040/2041', 1, true, true);

        $this->actingAs($this->admin, 'web')
            ->get(route('tahun.ajaran.show', $this->activeYearId))
            ->assertOk()
            ->assertDontSee('data-permanent-purge-trigger', false);

        $this->actingAs($this->admin, 'web')
            ->get(route('tahun.ajaran.show', $inactiveOpenId))
            ->assertOk()
            ->assertDontSee('data-permanent-purge-trigger', false);

        $this->actingAs($this->admin, 'web')
            ->get(route('tahun.ajaran.show', $archivedActiveId))
            ->assertOk()
            ->assertSeeText('Dilindungi')
            ->assertDontSee('data-permanent-purge-trigger', false);
    }

    public function test_archived_middle_period_can_be_purged_without_mutating_earlier_or_later_periods(): void
    {
        $periodA = $this->insertYear('2024/2025', 1, false, false);
        $periodB = $this->insertYear('2024/2025', 2, false, true);
        $periodC = $this->activeYearId;

        $classA = $this->insertClass($periodA, '5', 'A');
        $classB = $this->insertClass($periodB, '5', 'A');
        $classC = $this->insertClass($periodC, '6', 'A');
        $studentId = $this->insertStudent($classB, $periodB, '2605005', '9000000005', 'Middle Siswa');
        $subjectA = $this->insertSubject($periodA, $classA, 'IPA');
        $subjectB = $this->insertSubject($periodB, $classB, 'IPA');
        $subjectC = $this->insertSubject($periodC, $classC, 'IPA');
        $templateA = $this->insertReportTemplate($periodA, 'templates/period-a.docx', $classA);
        $templateB = $this->insertReportTemplate($periodB, 'templates/period-b.docx', $classB);
        $templateC = $this->insertReportTemplate($periodC, 'templates/period-c.docx', $classC);
        Storage::disk('public')->put('templates/period-a.docx', 'a');
        Storage::disk('public')->put('templates/period-b.docx', 'b');
        Storage::disk('public')->put('templates/period-c.docx', 'c');

        $this->insertEnrollment($studentId, $classB, $periodB, 2);
        $this->insertEnrollment($studentId, $classC, $periodC, 2);

        $this->deleteWithConfirmation($periodB)
            ->assertRedirect(route('tahun.ajaran.index', ['showArchived' => 'true']))
            ->assertSessionHas('success', 'Tahun ajaran berhasil dihapus permanen.');

        $this->assertSame(1, DB::table('tahun_ajarans')->where('id', $periodA)->count());
        $this->assertSame(0, DB::table('tahun_ajarans')->where('id', $periodB)->count());
        $this->assertSame(1, DB::table('tahun_ajarans')->where('id', $periodC)->where('is_active', true)->count());
        $this->assertSame(1, DB::table('kelas')->where('id', $classA)->count());
        $this->assertSame(0, DB::table('kelas')->where('id', $classB)->count());
        $this->assertSame(1, DB::table('kelas')->where('id', $classC)->count());
        $this->assertSame(1, DB::table('mata_pelajarans')->where('id', $subjectA)->count());
        $this->assertSame(0, DB::table('mata_pelajarans')->where('id', $subjectB)->count());
        $this->assertSame(1, DB::table('mata_pelajarans')->where('id', $subjectC)->count());
        $this->assertSame(1, DB::table('report_templates')->where('id', $templateA)->count());
        $this->assertSame(0, DB::table('report_templates')->where('id', $templateB)->count());
        $this->assertSame(1, DB::table('report_templates')->where('id', $templateC)->count());
        $this->assertSame($classC, (int) DB::table('siswas')->where('id', $studentId)->value('kelas_id'));
        Storage::disk('public')->assertExists('templates/period-a.docx');
        Storage::disk('public')->assertMissing('templates/period-b.docx');
        Storage::disk('public')->assertExists('templates/period-c.docx');
        $this->assertSame(0, DB::table('siswa_kelas_semester')->where('kelas_id', $classB)->count());
        $this->assertSame(0, DB::table('report_generations')->where('report_template_id', $templateB)->count());
    }

    public function test_purge_rejects_middle_period_when_later_period_has_unresolved_reference_to_target_owned_subject(): void
    {
        $periodB = $this->insertYear('2025/2026', 2, false, true);
        $periodC = $this->activeYearId;
        $classB = $this->insertClass($periodB, '5', 'B');
        $classC = $this->insertClass($periodC, '6', 'B');
        $studentId = $this->insertStudent($classB, $periodB, '2605006', '9000000006', 'Unresolved Siswa');
        $subjectB = $this->insertSubject($periodB, $classB, 'Bahasa Indonesia');
        $templatePath = 'templates/unresolved-target.docx';
        $templateId = $this->insertReportTemplate($periodB, $templatePath, $classB);
        Storage::disk('public')->put($templatePath, 'template');
        $this->insertEnrollment($studentId, $classB, $periodB, 2);
        $this->insertEnrollment($studentId, $classC, $periodC, 2);
        DB::table('nilais')->insert([
            'tahun_ajaran_id' => $periodC,
            'mata_pelajaran_id' => $subjectB,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->deleteWithConfirmation($periodB)
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertStringContainsString('data periode lain yang masih mengarah', session('error'));
        $this->assertSame(1, DB::table('tahun_ajarans')->where('id', $periodB)->count());
        $this->assertSame(1, DB::table('kelas')->where('id', $classB)->count());
        $this->assertSame(1, DB::table('mata_pelajarans')->where('id', $subjectB)->count());
        $this->assertSame(1, DB::table('report_templates')->where('id', $templateId)->count());
        $this->assertSame($classB, (int) DB::table('siswas')->where('id', $studentId)->value('kelas_id'));
        Storage::disk('public')->assertExists($templatePath);
    }

    public function test_archive_and_restore_remain_soft_delete_flows(): void
    {
        $inactiveYearId = $this->insertYear('2042/2043', 1, false, false);

        $this->actingAs($this->admin, 'web')
            ->delete(route('tahun.ajaran.destroy', $inactiveYearId))
            ->assertRedirect(route('tahun.ajaran.index'))
            ->assertSessionHas('success');

        $this->assertNotNull(DB::table('tahun_ajarans')->where('id', $inactiveYearId)->value('deleted_at'));

        $this->actingAs($this->admin, 'web')
            ->post(route('tahun.ajaran.restore', $inactiveYearId))
            ->assertRedirect(route('tahun.ajaran.index', ['showArchived' => 1]))
            ->assertSessionHas('success');

        $this->assertNull(DB::table('tahun_ajarans')->where('id', $inactiveYearId)->value('deleted_at'));
        $this->assertFalse((bool) DB::table('tahun_ajarans')->where('id', $inactiveYearId)->value('is_active'));
    }

    public function test_manual_create_tahun_ajaran_remains_fresh_without_copying_existing_structure(): void
    {
        $guruId = $this->insertGuru('Guru Lama');
        $sourceClassId = $this->insertClass($this->activeYearId);
        $sourceSubjectId = $this->insertSubject($this->activeYearId, $sourceClassId, 'Matematika Lama');
        $sourceLmId = $this->insertLingkupMateri($sourceSubjectId);
        $this->insertTujuanPembelajaran($sourceLmId);
        $this->insertEkskul($this->activeYearId);
        $this->insertReportTemplate($this->activeYearId, 'templates/source-template.docx', $sourceClassId, true);

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
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($this->admin, 'web')
            ->post(route('tahun.ajaran.store'), [
                'tahun_ajaran' => '2043/2044',
                'tanggal_mulai' => '2043-07-01',
                'tanggal_selesai' => '2044-06-30',
                'semester' => 1,
                'deskripsi' => 'Tahun ajaran fresh',
            ])
            ->assertRedirect(route('tahun.ajaran.index'))
            ->assertSessionHas('success', 'Tahun ajaran berhasil dibuat.');

        $newYearId = DB::table('tahun_ajarans')
            ->where('tahun_ajaran', '2043/2044')
            ->where('semester', 1)
            ->value('id');

        $this->assertNotNull($newYearId);
        $this->assertSame(0, DB::table('kelas')->where('tahun_ajaran_id', $newYearId)->count());
        $this->assertSame(0, DB::table('guru_kelas')->join('kelas', 'guru_kelas.kelas_id', '=', 'kelas.id')->where('kelas.tahun_ajaran_id', $newYearId)->count());
        $this->assertSame(0, DB::table('mata_pelajarans')->where('tahun_ajaran_id', $newYearId)->count());
        $this->assertSame(0, DB::table('bobot_nilais')->where('tahun_ajaran_id', $newYearId)->count());
        $this->assertSame(0, DB::table('report_templates')->where('tahun_ajaran_id', $newYearId)->count());
    }

    public function test_empty_and_configuration_only_archived_periods_purge_successfully(): void
    {
        $emptyYearId = $this->insertYear('2044/2045', 1, false, true);
        $configOnlyYearId = $this->insertYear('2026/2027', 1, false, true);
        $templatePath = 'templates/config-only.docx';
        $templateId = $this->insertReportTemplate($configOnlyYearId, $templatePath, null, false);
        Storage::disk('public')->put($templatePath, 'config-template');
        DB::table('bobot_nilais')->insert([
            'tahun_ajaran_id' => $configOnlyYearId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->deleteWithConfirmation($emptyYearId)
            ->assertRedirect(route('tahun.ajaran.index', ['showArchived' => 'true']))
            ->assertSessionHas('success', 'Tahun ajaran berhasil dihapus permanen.');

        $this->deleteWithConfirmation($configOnlyYearId)
            ->assertRedirect(route('tahun.ajaran.index', ['showArchived' => 'true']))
            ->assertSessionHas('success', 'Tahun ajaran berhasil dihapus permanen.');

        $this->assertSame(0, DB::table('tahun_ajarans')->whereIn('id', [$emptyYearId, $configOnlyYearId])->count());
        $this->assertSame(0, DB::table('bobot_nilais')->where('tahun_ajaran_id', $configOnlyYearId)->count());
        $this->assertSame(0, DB::table('report_templates')->where('id', $templateId)->count());
        Storage::disk('public')->assertMissing($templatePath);
        $this->assertSame(1, DB::table('tahun_ajarans')->where('id', $this->activeYearId)->where('is_active', true)->count());
    }

    public function test_missing_report_template_file_does_not_block_database_purge(): void
    {
        $targetYearId = $this->insertYear('2045/2046', 1, false, true);
        $missingPath = 'templates/missing-owned-template.docx';
        $templateId = $this->insertReportTemplate($targetYearId, $missingPath, null, true);

        Storage::disk('public')->assertMissing($missingPath);

        $this->deleteWithConfirmation($targetYearId)
            ->assertRedirect(route('tahun.ajaran.index', ['showArchived' => 'true']))
            ->assertSessionHas('success', 'Tahun ajaran berhasil dihapus permanen.');

        $this->assertSame(0, DB::table('tahun_ajarans')->where('id', $targetYearId)->count());
        $this->assertSame(0, DB::table('report_templates')->where('id', $templateId)->count());
    }

    public function test_other_period_templates_and_files_are_preserved(): void
    {
        $targetYearId = $this->insertYear('2046/2047', 1, false, true);
        $otherYearId = $this->insertYear('2047/2048', 1, false, false);
        $targetPath = 'templates/target-only-template.docx';
        $otherPath = 'templates/other-only-template.docx';
        $targetTemplateId = $this->insertReportTemplate($targetYearId, $targetPath, null, true);
        $otherTemplateId = $this->insertReportTemplate($otherYearId, $otherPath, null, true);
        Storage::disk('public')->put($targetPath, 'target');
        Storage::disk('public')->put($otherPath, 'other');

        $this->deleteWithConfirmation($targetYearId)
            ->assertRedirect(route('tahun.ajaran.index', ['showArchived' => 'true']))
            ->assertSessionHas('success', 'Tahun ajaran berhasil dihapus permanen.');

        $this->assertSame(0, DB::table('report_templates')->where('id', $targetTemplateId)->count());
        $this->assertSame(1, DB::table('report_templates')->where('id', $otherTemplateId)->count());
        Storage::disk('public')->assertMissing($targetPath);
        Storage::disk('public')->assertExists($otherPath);
    }

    public function test_missing_target_returns_friendly_web_and_json_responses(): void
    {
        $missingId = 987654;

        $this->actingAs($this->admin, 'web')
            ->delete(route('tahun.ajaran.force-delete', $missingId), [
                'purge_confirmation' => 'HAPUS PERMANEN 2099/2100 SEMESTER GANJIL',
            ])
            ->assertRedirect(route('tahun.ajaran.index', ['showArchived' => 'true']))
            ->assertSessionHas('error', 'Tahun ajaran tidak ditemukan.');

        $this->actingAs($this->admin, 'web')
            ->deleteJson(route('tahun.ajaran.force-delete', $missingId), [
                'purge_confirmation' => 'HAPUS PERMANEN 2099/2100 SEMESTER GANJIL',
            ])
            ->assertStatus(404)
            ->assertJson([
                'success' => false,
                'message' => 'Tahun ajaran tidak ditemukan.',
            ]);
    }

    public function test_confirmation_is_case_sensitive_but_accepts_surrounding_whitespace(): void
    {
        $targetYearId = $this->insertYear('2048/2049', 2, false, true);
        $phrase = $this->confirmationPhrase($targetYearId);

        $this->actingAs($this->admin, 'web')
            ->deleteJson(route('tahun.ajaran.force-delete', $targetYearId), [
                'purge_confirmation' => strtolower($phrase),
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', TahunAjaranPurgeService::CONFIRMATION_MISMATCH_MESSAGE);

        $this->actingAs($this->admin, 'web')
            ->deleteJson(route('tahun.ajaran.force-delete', $targetYearId), [
                'purge_confirmation' => "  {$phrase}  ",
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertSame(0, DB::table('tahun_ajarans')->where('id', $targetYearId)->count());
    }

    public function test_service_revalidates_confirmation_from_locked_target_state(): void
    {
        $targetYearId = $this->insertYear('2049/2050', 2, false, true);

        try {
            app(TahunAjaranPurgeService::class)->purge($targetYearId, 'HAPUS PERMANEN 2049/2050 SEMESTER GANJIL');
            $this->fail('Purge service should reject a phrase that does not match the locked target semester.');
        } catch (TahunAjaranPurgeException $exception) {
            $this->assertSame(TahunAjaranPurgeService::CONFIRMATION_MISMATCH_MESSAGE, $exception->getMessage());
        }

        $this->assertSame(1, DB::table('tahun_ajarans')->where('id', $targetYearId)->count());

        app(TahunAjaranPurgeService::class)->purge($targetYearId, $this->confirmationPhrase($targetYearId));

        $this->assertSame(0, DB::table('tahun_ajarans')->where('id', $targetYearId)->count());
    }

    public function test_preview_counts_unique_rows_once_when_multiple_ownership_paths_match(): void
    {
        $targetYearId = $this->insertYear('2050/2051', 2, false, true);
        $classId = $this->insertClass($targetYearId);
        $subjectId = $this->insertSubject($targetYearId, $classId, 'IPA');
        $templateId = $this->insertReportTemplate($targetYearId, 'templates/count-template.docx', $classId);

        DB::table('kkms')->insert([
            'tahun_ajaran_id' => $targetYearId,
            'kelas_id' => $classId,
            'mata_pelajaran_id' => $subjectId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('report_mappings')->insert([
            'tahun_ajaran_id' => $targetYearId,
            'report_template_id' => $templateId,
            'placeholder_key' => 'nama_siswa',
            'data_source' => 'siswa.nama',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $preview = app(TahunAjaranPurgeService::class)->preview(
            TahunAjaran::withTrashed()->findOrFail($targetYearId)
        );

        $this->assertTrue($preview['can_purge']);
        $this->assertSame(1, $preview['counts']['kkm']);
        $this->assertSame(1, $preview['counts']['report_mappings']);
        $this->assertSame(1, $preview['counts']['report_templates']);
    }

    public function test_surviving_report_mapping_to_target_template_blocks_without_deleting_rows_or_files(): void
    {
        $targetYearId = $this->insertYear('2051/2052', 1, false, true);
        $templatePath = 'templates/mapping-block-template.docx';
        $targetTemplateId = $this->insertReportTemplate($targetYearId, $templatePath, null, true);
        Storage::disk('public')->put($templatePath, 'template');

        DB::table('report_mappings')->insert([
            'tahun_ajaran_id' => $this->activeYearId,
            'report_template_id' => $targetTemplateId,
            'placeholder_key' => 'nama_siswa',
            'data_source' => 'siswa.nama',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->deleteWithConfirmation($targetYearId)
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertStringContainsString('data periode lain yang masih mengarah', session('error'));
        $this->assertSame(1, DB::table('tahun_ajarans')->where('id', $targetYearId)->count());
        $this->assertSame(1, DB::table('report_templates')->where('id', $targetTemplateId)->count());
        $this->assertSame(1, DB::table('report_mappings')->where('report_template_id', $targetTemplateId)->count());
        Storage::disk('public')->assertExists($templatePath);
    }

    public function test_surviving_capaian_rows_referencing_target_structure_block_without_deleting_rows_or_files(): void
    {
        $targetYearId = $this->insertYear('2052/2053', 2, false, true);
        $targetClassId = $this->insertClass($targetYearId, '5', 'C');
        $targetSubjectId = $this->insertSubject($targetYearId, $targetClassId, 'Bahasa Arab');
        $templatePath = 'templates/capaian-block-template.docx';
        $templateId = $this->insertReportTemplate($targetYearId, $templatePath, $targetClassId);
        Storage::disk('public')->put($templatePath, 'template');

        foreach (['capaian_templates', 'capaian_range'] as $table) {
            DB::table($table)->insert([
                'tahun_ajaran_id' => $this->activeYearId,
                'kelas_id' => $targetClassId,
                'mata_pelajaran_id' => $targetSubjectId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
        DB::table('capaian_phrase_defaults')->insert([
            'tahun_ajaran_id' => $this->activeYearId,
            'kelas_id' => $targetClassId,
            'mata_pelajaran_id' => $targetSubjectId,
            'semester' => 2,
            'type' => 'tertinggi',
            'mode' => 'preset',
            'phrase' => 'Baik',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->deleteWithConfirmation($targetYearId)
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertSame(1, DB::table('tahun_ajarans')->where('id', $targetYearId)->count());
        $this->assertSame(1, DB::table('mata_pelajarans')->where('id', $targetSubjectId)->count());
        $this->assertSame(1, DB::table('report_templates')->where('id', $templateId)->count());
        Storage::disk('public')->assertExists($templatePath);
    }

    public function test_surviving_template_class_pivot_to_target_class_blocks_without_deleting_rows_or_files(): void
    {
        $targetYearId = $this->insertYear('2053/2054', 2, false, true);
        $targetClassId = $this->insertClass($targetYearId, '5', 'D');
        $targetTemplatePath = 'templates/pivot-target-template.docx';
        $targetTemplateId = $this->insertReportTemplate($targetYearId, $targetTemplatePath, $targetClassId);
        $survivingTemplateId = $this->insertReportTemplate($this->activeYearId, 'templates/surviving-template.docx');
        Storage::disk('public')->put($targetTemplatePath, 'target-template');
        Storage::disk('public')->put('templates/surviving-template.docx', 'surviving-template');

        DB::table('report_template_kelas')->insert([
            'report_template_id' => $survivingTemplateId,
            'kelas_id' => $targetClassId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->deleteWithConfirmation($targetYearId)
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertSame(1, DB::table('tahun_ajarans')->where('id', $targetYearId)->count());
        $this->assertSame(1, DB::table('kelas')->where('id', $targetClassId)->count());
        $this->assertSame(1, DB::table('report_templates')->where('id', $targetTemplateId)->count());
        $this->assertSame(1, DB::table('report_templates')->where('id', $survivingTemplateId)->count());
        $this->assertSame(1, DB::table('report_template_kelas')->where('report_template_id', $survivingTemplateId)->where('kelas_id', $targetClassId)->count());
        Storage::disk('public')->assertExists($targetTemplatePath);
        Storage::disk('public')->assertExists('templates/surviving-template.docx');
    }

    public function test_surviving_template_class_pivot_to_target_class_blocks_even_without_target_template(): void
    {
        $targetYearId = $this->insertYear('2055/2056', 2, false, true);
        $targetClassId = $this->insertClass($targetYearId, '5', 'E');
        $survivingTemplateId = $this->insertReportTemplate($this->activeYearId, 'templates/surviving-only-template.docx');
        Storage::disk('public')->put('templates/surviving-only-template.docx', 'surviving-template');

        DB::table('report_template_kelas')->insert([
            'report_template_id' => $survivingTemplateId,
            'kelas_id' => $targetClassId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->deleteWithConfirmation($targetYearId)
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertStringContainsString('data periode lain yang masih mengarah', session('error'));
        $this->assertSame(1, DB::table('tahun_ajarans')->where('id', $targetYearId)->count());
        $this->assertSame(1, DB::table('kelas')->where('id', $targetClassId)->count());
        $this->assertSame(1, DB::table('report_templates')->where('id', $survivingTemplateId)->count());
        $this->assertSame(1, DB::table('report_template_kelas')->where('report_template_id', $survivingTemplateId)->where('kelas_id', $targetClassId)->count());
        Storage::disk('public')->assertExists('templates/surviving-only-template.docx');
    }

    public function test_surviving_pembelajaran_to_target_class_blocks_without_deleting_rows(): void
    {
        $targetYearId = $this->insertYear('2056/2057', 2, false, true);
        $targetClassId = $this->insertClass($targetYearId, '5', 'F');
        $activeClassId = $this->insertClass($this->activeYearId, '6', 'F');
        $activeSubjectId = $this->insertSubject($this->activeYearId, $activeClassId, 'Fiqih');
        $guruId = $this->insertGuru('Guru Pembelajaran Surviving');
        $pembelajaranId = DB::table('pembelajarans')->insertGetId([
            'kelas_id' => $targetClassId,
            'mata_pelajaran_id' => $activeSubjectId,
            'guru_id' => $guruId,
            'tahun_ajaran' => 'Surviving',
            'semester' => 'genap',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->deleteWithConfirmation($targetYearId)
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertStringContainsString('data periode lain yang masih mengarah', session('error'));
        $this->assertSame(1, DB::table('tahun_ajarans')->where('id', $targetYearId)->count());
        $this->assertSame(1, DB::table('kelas')->where('id', $targetClassId)->count());
        $this->assertSame(1, DB::table('pembelajarans')->where('id', $pembelajaranId)->count());
        $this->assertSame(1, DB::table('mata_pelajarans')->where('id', $activeSubjectId)->count());
    }

    public function test_surviving_report_generation_to_target_template_blocks_without_deleting_file(): void
    {
        $targetYearId = $this->insertYear('2054/2055', 1, false, true);
        $targetTemplateId = $this->insertReportTemplate($targetYearId, 'templates/report-generation-block-template.docx');
        $reportPath = 'pdf_reports/surviving-report-generation.pdf';
        $reportId = $this->insertReportGeneration($this->activeYearId, $targetTemplateId, null, $reportPath);
        Storage::disk('public')->put('templates/report-generation-block-template.docx', 'template');
        Storage::disk('public')->put($reportPath, 'report');

        $this->deleteWithConfirmation($targetYearId)
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertSame(1, DB::table('tahun_ajarans')->where('id', $targetYearId)->count());
        $this->assertSame(1, DB::table('report_generations')->where('id', $reportId)->count());
        Storage::disk('public')->assertExists($reportPath);
        Storage::disk('public')->assertExists('templates/report-generation-block-template.docx');
    }

    public function test_stale_student_year_id_is_repaired_for_active_and_null_current_class(): void
    {
        $targetYearId = $this->insertYear('2057/2058', 2, false, true);
        $activeClassA = $this->insertClass($this->activeYearId, '6', 'G');
        $activeClassB = $this->insertClass($this->activeYearId, '6', 'H');

        $activeClassStudent = $this->insertStudent($activeClassA, $targetYearId, '2605007', '9000000007', 'Stale Active Class');
        $nullClassStudent = DB::table('siswas')->insertGetId([
            'nis' => '2605008',
            'nisn' => '9000000008',
            'nama' => 'Stale Null Class',
            'kelas_id' => null,
            'tahun_ajaran_id' => $targetYearId,
            'status' => 'aktif',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->insertEnrollment($activeClassStudent, $activeClassA, $this->activeYearId, 2);
        $this->insertEnrollment($nullClassStudent, $activeClassB, $this->activeYearId, 2);

        $this->deleteWithConfirmation($targetYearId)
            ->assertRedirect(route('tahun.ajaran.index', ['showArchived' => 'true']))
            ->assertSessionHas('success', 'Tahun ajaran berhasil dihapus permanen.');

        $this->assertSame($activeClassA, (int) DB::table('siswas')->where('id', $activeClassStudent)->value('kelas_id'));
        $this->assertSame($activeClassB, (int) DB::table('siswas')->where('id', $nullClassStudent)->value('kelas_id'));
        $this->assertSame($this->activeYearId, (int) DB::table('siswas')->where('id', $activeClassStudent)->value('tahun_ajaran_id'));
        $this->assertSame($this->activeYearId, (int) DB::table('siswas')->where('id', $nullClassStudent)->value('tahun_ajaran_id'));
        $this->assertSame(0, DB::table('siswas')->where('tahun_ajaran_id', $targetYearId)->count());
    }

    public function test_student_consistency_blocks_missing_duplicate_invalid_and_inconsistent_active_enrollments(): void
    {
        DB::statement('DROP INDEX siswa_kelas_semester_unique_context');

        $missingYear = $this->insertYear('2058/2059', 2, false, true);
        $activeClassA = $this->insertClass($this->activeYearId, '6', 'I');
        $missingStudent = $this->insertStudent($activeClassA, $missingYear, '2605009', '9000000009', 'Missing Enrollment');

        $this->deleteWithConfirmation($missingYear)
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertSame($missingYear, (int) DB::table('siswas')->where('id', $missingStudent)->value('tahun_ajaran_id'));

        $duplicateYear = $this->insertYear('2059/2060', 2, false, true);
        $duplicateTargetClass = $this->insertClass($duplicateYear, '5', 'I');
        $activeClassB = $this->insertClass($this->activeYearId, '6', 'J');
        $activeClassC = $this->insertClass($this->activeYearId, '6', 'K');
        $duplicateStudent = $this->insertStudent($duplicateTargetClass, $duplicateYear, '2605010', '9000000010', 'Duplicate Enrollment');
        $this->insertEnrollment($duplicateStudent, $activeClassB, $this->activeYearId, 2);
        $this->insertEnrollment($duplicateStudent, $activeClassC, $this->activeYearId, 2);

        $this->deleteWithConfirmation($duplicateYear)
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertSame($duplicateTargetClass, (int) DB::table('siswas')->where('id', $duplicateStudent)->value('kelas_id'));
        $this->assertSame($duplicateYear, (int) DB::table('siswas')->where('id', $duplicateStudent)->value('tahun_ajaran_id'));

        $invalidYear = $this->insertYear('2060/2061', 2, false, true);
        $invalidTargetClass = $this->insertClass($invalidYear, '5', 'J');
        $otherYear = $this->insertYear('2060/2061', 1, false, false);
        $otherClass = $this->insertClass($otherYear, '6', 'L');
        $invalidStudent = $this->insertStudent($invalidTargetClass, $invalidYear, '2605011', '9000000011', 'Invalid Replacement');
        $this->insertEnrollment($invalidStudent, $otherClass, $this->activeYearId, 2);

        $this->deleteWithConfirmation($invalidYear)
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertSame($invalidTargetClass, (int) DB::table('siswas')->where('id', $invalidStudent)->value('kelas_id'));

        $inconsistentYear = $this->insertYear('2061/2062', 2, false, true);
        $activeClassD = $this->insertClass($this->activeYearId, '6', 'M');
        $activeClassE = $this->insertClass($this->activeYearId, '6', 'N');
        $inconsistentStudent = $this->insertStudent($activeClassD, $inconsistentYear, '2605012', '9000000012', 'Inconsistent Current Class');
        $this->insertEnrollment($inconsistentStudent, $activeClassE, $this->activeYearId, 2);

        $this->deleteWithConfirmation($inconsistentYear)
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertSame($activeClassD, (int) DB::table('siswas')->where('id', $inconsistentStudent)->value('kelas_id'));
        $this->assertSame($inconsistentYear, (int) DB::table('siswas')->where('id', $inconsistentStudent)->value('tahun_ajaran_id'));
    }

    public function test_null_year_enrollment_for_target_class_is_explicitly_deleted(): void
    {
        $targetYearId = $this->insertYear('2062/2063', 2, false, true);
        $targetClassId = $this->insertClass($targetYearId, '5', 'O');
        $activeClassId = $this->insertClass($this->activeYearId, '6', 'O');
        $studentId = $this->insertStudent($targetClassId, $targetYearId, '2605013', '9000000013', 'Legacy Null Enrollment');
        $legacyEnrollmentId = DB::table('siswa_kelas_semester')->insertGetId([
            'siswa_id' => $studentId,
            'kelas_id' => $targetClassId,
            'tahun_ajaran_id' => null,
            'semester' => 2,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->insertEnrollment($studentId, $activeClassId, $this->activeYearId, 2);

        $this->deleteWithConfirmation($targetYearId)
            ->assertRedirect(route('tahun.ajaran.index', ['showArchived' => 'true']))
            ->assertSessionHas('success', 'Tahun ajaran berhasil dihapus permanen.');

        $this->assertSame(0, DB::table('siswa_kelas_semester')->where('id', $legacyEnrollmentId)->count());
        $this->assertSame(0, DB::table('siswas')->where('kelas_id', $targetClassId)->orWhere('tahun_ajaran_id', $targetYearId)->count());
    }

    public function test_null_year_rows_are_purged_only_when_all_populated_period_references_are_target_owned(): void
    {
        $targetYearId = $this->insertYear('2063/2064', 2, false, true);
        $targetClassId = $this->insertClass($targetYearId, '5', 'P');
        $subjectId = $this->insertSubject($targetYearId, $targetClassId, 'Target Owned');
        $lmId = $this->insertLingkupMateri($subjectId);
        $tpId = $this->insertTujuanPembelajaran($lmId);

        $scoreId = DB::table('nilais')->insertGetId([
            'tahun_ajaran_id' => null,
            'mata_pelajaran_id' => $subjectId,
            'lingkup_materi_id' => $lmId,
            'tujuan_pembelajaran_id' => $tpId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $kkmId = DB::table('kkms')->insertGetId([
            'tahun_ajaran_id' => null,
            'kelas_id' => $targetClassId,
            'mata_pelajaran_id' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->deleteWithConfirmation($targetYearId)
            ->assertRedirect(route('tahun.ajaran.index', ['showArchived' => 'true']))
            ->assertSessionHas('success', 'Tahun ajaran berhasil dihapus permanen.');

        $this->assertSame(0, DB::table('nilais')->where('id', $scoreId)->count());
        $this->assertSame(0, DB::table('kkms')->where('id', $kkmId)->count());
    }

    public function test_null_year_mixed_learning_references_block_without_database_file_or_session_changes(): void
    {
        $targetYearId = $this->insertYear('2064/2065', 2, false, true);
        $targetClassId = $this->insertClass($targetYearId, '5', 'Q');
        $targetSubjectId = $this->insertSubject($targetYearId, $targetClassId, 'Target Mixed');
        $survivingYearId = $this->insertYear('2065/2066', 1, false, false);
        $survivingClassId = $this->insertClass($survivingYearId, '6', 'Q');
        $survivingSubjectId = $this->insertSubject($survivingYearId, $survivingClassId, 'Surviving Mixed');
        $survivingLmId = $this->insertLingkupMateri($survivingSubjectId);
        $survivingTpId = $this->insertTujuanPembelajaran($survivingLmId);
        $templatePath = 'templates/null-year-mixed-template.docx';
        $templateId = $this->insertReportTemplate($targetYearId, $templatePath, $targetClassId);
        Storage::disk('public')->put($templatePath, 'template');
        session(['tahun_ajaran_id' => $targetYearId]);

        $scoreWithSurvivingLm = DB::table('nilais')->insertGetId([
            'tahun_ajaran_id' => null,
            'mata_pelajaran_id' => $targetSubjectId,
            'lingkup_materi_id' => $survivingLmId,
            'tujuan_pembelajaran_id' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $scoreWithSurvivingTp = DB::table('nilais')->insertGetId([
            'tahun_ajaran_id' => null,
            'mata_pelajaran_id' => $targetSubjectId,
            'lingkup_materi_id' => null,
            'tujuan_pembelajaran_id' => $survivingTpId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->deleteWithConfirmation($targetYearId)
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertStringContainsString('data periode lain yang masih mengarah', session('error'));
        $this->assertSame($targetYearId, session('tahun_ajaran_id'));
        $this->assertSame(1, DB::table('tahun_ajarans')->where('id', $targetYearId)->count());
        $this->assertSame(1, DB::table('nilais')->where('id', $scoreWithSurvivingLm)->count());
        $this->assertSame(1, DB::table('nilais')->where('id', $scoreWithSurvivingTp)->count());
        $this->assertSame(1, DB::table('report_templates')->where('id', $templateId)->count());
        Storage::disk('public')->assertExists($templatePath);
    }

    public function test_null_year_mixed_class_template_and_extracurricular_references_block_without_cleanup(): void
    {
        $targetYearId = $this->insertYear('2065/2066', 2, false, true);
        $targetClassId = $this->insertClass($targetYearId, '5', 'R');
        $targetTemplatePath = 'templates/null-year-pivot-target.docx';
        $targetTemplateId = $this->insertReportTemplate($targetYearId, $targetTemplatePath, $targetClassId);
        $targetEkskulId = $this->insertEkskul($targetYearId);
        $activeClassId = $this->insertClass($this->activeYearId, '6', 'R');
        $activeSubjectId = $this->insertSubject($this->activeYearId, $activeClassId, 'Active Subject');
        Storage::disk('public')->put($targetTemplatePath, 'target');
        session(['tahun_ajaran_id' => $targetYearId]);

        $pembelajaranId = DB::table('pembelajarans')->insertGetId([
            'kelas_id' => $targetClassId,
            'mata_pelajaran_id' => $activeSubjectId,
            'guru_id' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $pivotId = DB::table('report_template_kelas')->insertGetId([
            'report_template_id' => $targetTemplateId,
            'kelas_id' => $activeClassId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $ekskulScoreId = DB::table('nilai_ekstrakurikuler')->insertGetId([
            'tahun_ajaran_id' => $this->activeYearId,
            'ekstrakurikuler_id' => $targetEkskulId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->deleteWithConfirmation($targetYearId)
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertSame($targetYearId, session('tahun_ajaran_id'));
        $this->assertSame(1, DB::table('pembelajarans')->where('id', $pembelajaranId)->count());
        $this->assertSame(1, DB::table('report_template_kelas')->where('id', $pivotId)->count());
        $this->assertSame(1, DB::table('nilai_ekstrakurikuler')->where('id', $ekskulScoreId)->count());
        $this->assertSame(1, DB::table('tahun_ajarans')->where('id', $targetYearId)->count());
        Storage::disk('public')->assertExists($targetTemplatePath);
    }

    public function test_post_commit_storage_disk_failure_returns_json_success_warning_and_keeps_committed_state(): void
    {
        $targetYearId = $this->insertYear('2066/2067', 2, false, true);
        $targetClassId = $this->insertClass($targetYearId, '5', 'S');
        $activeClassId = $this->insertClass($this->activeYearId, '6', 'S');
        $studentId = $this->insertStudent($targetClassId, $targetYearId, '2605014', '9000000014', 'Cleanup Disk Failure');
        $this->insertEnrollment($studentId, $targetClassId, $targetYearId, 2);
        $this->insertEnrollment($studentId, $activeClassId, $this->activeYearId, 2);
        $this->insertReportTemplate($targetYearId, 'templates/disk-failure-template.docx', $targetClassId);
        session(['tahun_ajaran_id' => $targetYearId]);

        Storage::shouldReceive('disk')->with('public')->andThrow(new \RuntimeException('disk unavailable'));

        $this->actingAs($this->admin, 'web')
            ->deleteJson(route('tahun.ajaran.force-delete', $targetYearId), [
                'purge_confirmation' => $this->confirmationPhrase($targetYearId),
            ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', TahunAjaranPurgeService::FILE_CLEANUP_WARNING);

        $this->assertSame(0, DB::table('tahun_ajarans')->where('id', $targetYearId)->count());
        $this->assertSame($activeClassId, (int) DB::table('siswas')->where('id', $studentId)->value('kelas_id'));
        $this->assertSame($this->activeYearId, (int) DB::table('siswas')->where('id', $studentId)->value('tahun_ajaran_id'));
        $this->assertSame($this->activeYearId, session('tahun_ajaran_id'));
    }

    public function test_later_post_commit_cleanup_runs_after_ambiguous_template_path_warning(): void
    {
        $targetYearId = $this->insertYear('2067/2068', 1, false, true);
        $ambiguousPath = 'templates/defaults/custom-owned-template.docx';
        $reportPath = 'pdf_reports/later-cleanup-still-runs.pdf';
        $templateId = $this->insertReportTemplate($targetYearId, $ambiguousPath);
        $this->insertReportGeneration($targetYearId, $templateId, null, $reportPath);
        Storage::disk('public')->put($ambiguousPath, 'ambiguous');
        Storage::disk('public')->put($reportPath, 'report');

        $this->deleteWithConfirmation($targetYearId)
            ->assertRedirect(route('tahun.ajaran.index', ['showArchived' => 'true']))
            ->assertSessionHas('success', TahunAjaranPurgeService::FILE_CLEANUP_WARNING);

        $this->assertSame(0, DB::table('tahun_ajarans')->where('id', $targetYearId)->count());
        Storage::disk('public')->assertExists($ambiguousPath);
        Storage::disk('public')->assertMissing($reportPath);
    }

    public function test_default_template_cleanup_classifies_true_default_legacy_runtime_ambiguous_shared_and_normalized_paths(): void
    {
        $targetYearId = $this->insertYear('2068/2069', 2, false, true);
        $survivingYearId = $this->insertYear('2069/2070', 1, false, false);
        $trueDefault = 'templates/defaults/template-default-uts-2068-2069-s2.docx';
        $sharedDefault = 'templates/defaults/template-default-uas-shared-s2.docx';
        $legacyRuntime = 'templates/defaults/semester2_template-default-uts-2068-2069-s2.docx';
        $windowsLegacy = 'templates/defaults/semester2_template-default-uas-2068-2069-s2.docx';
        $ambiguous = 'templates/defaults/custom-target-copy.docx';
        $semesterCopy = "templates/semester-copies/{$targetYearId}/runtime-copy.docx";
        $otherSemesterCopy = 'templates/semester-copies/other-period/runtime-copy.docx';

        foreach ([$trueDefault, $sharedDefault, $legacyRuntime, $windowsLegacy, $ambiguous, $semesterCopy, $otherSemesterCopy] as $path) {
            Storage::disk('public')->put($path, $path);
        }

        $this->insertReportTemplate($targetYearId, $trueDefault);
        $this->insertReportTemplate($targetYearId, $sharedDefault);
        $this->insertReportTemplate($survivingYearId, 'public/'.$sharedDefault);
        $this->insertReportTemplate($targetYearId, $legacyRuntime);
        $this->insertReportTemplate($targetYearId, 'public\\'.$windowsLegacy);
        $this->insertReportTemplate($targetYearId, $ambiguous);
        $this->insertReportTemplate($targetYearId, $semesterCopy);
        $this->insertReportTemplate($survivingYearId, $otherSemesterCopy);

        $this->deleteWithConfirmation($targetYearId)
            ->assertRedirect(route('tahun.ajaran.index', ['showArchived' => 'true']))
            ->assertSessionHas('success', TahunAjaranPurgeService::FILE_CLEANUP_WARNING);

        Storage::disk('public')->assertExists($trueDefault);
        Storage::disk('public')->assertExists($sharedDefault);
        Storage::disk('public')->assertMissing($legacyRuntime);
        Storage::disk('public')->assertMissing($windowsLegacy);
        Storage::disk('public')->assertExists($ambiguous);
        Storage::disk('public')->assertMissing($semesterCopy);
        Storage::disk('public')->assertExists($otherSemesterCopy);
    }

    public function test_multiple_active_replacements_block_preview_and_execution_without_changing_data_files_or_session(): void
    {
        $targetYearId = $this->insertYear('2070/2071', 2, false, true);
        $targetClassId = $this->insertClass($targetYearId, '5', 'T');
        $activeClassId = $this->insertClass($this->activeYearId, '6', 'T');
        $studentId = $this->insertStudent($targetClassId, $targetYearId, '2605015', '9000000015', 'Multiple Active');
        $this->insertEnrollment($studentId, $activeClassId, $this->activeYearId, 2);
        $path = 'templates/multiple-active-template.docx';
        $templateId = $this->insertReportTemplate($targetYearId, $path, $targetClassId);
        Storage::disk('public')->put($path, 'template');
        $secondActiveId = $this->insertYear('2071/2072', 1, true, false);
        session(['tahun_ajaran_id' => $targetYearId]);

        $preview = app(TahunAjaranPurgeService::class)->preview(TahunAjaran::withTrashed()->findOrFail($targetYearId));
        $this->assertFalse($preview['can_purge']);
        $this->assertSame(TahunAjaranPurgeService::MULTIPLE_ACTIVE_REPLACEMENTS_MESSAGE, $preview['blocked_message']);

        $this->actingAs($this->admin, 'web')
            ->get(route('tahun.ajaran.show', $targetYearId))
            ->assertOk()
            ->assertSeeText('Dilindungi')
            ->assertSeeText(TahunAjaranPurgeService::MULTIPLE_ACTIVE_REPLACEMENTS_MESSAGE)
            ->assertDontSee('data-permanent-purge-trigger', false);

        $this->deleteWithConfirmation($targetYearId)
            ->assertRedirect()
            ->assertSessionHas('error', TahunAjaranPurgeService::MULTIPLE_ACTIVE_REPLACEMENTS_MESSAGE);

        $this->assertSame($targetYearId, session('tahun_ajaran_id'));
        $this->assertSame(1, DB::table('tahun_ajarans')->where('id', $targetYearId)->count());
        $this->assertSame(1, DB::table('tahun_ajarans')->where('id', $secondActiveId)->where('is_active', true)->count());
        $this->assertSame($targetClassId, (int) DB::table('siswas')->where('id', $studentId)->value('kelas_id'));
        $this->assertSame($targetYearId, (int) DB::table('siswas')->where('id', $studentId)->value('tahun_ajaran_id'));
        $this->assertSame(1, DB::table('report_templates')->where('id', $templateId)->count());
        Storage::disk('public')->assertExists($path);
    }

    public function test_locked_purge_plan_revalidates_students_added_to_affected_set_after_class_locks(): void
    {
        $targetYearId = $this->insertYear('2072/2073', 2, false, true);
        $targetClassId = $this->insertClass($targetYearId, '5', 'U');
        $activeClassId = $this->insertClass($this->activeYearId, '6', 'U');
        $studentId = $this->insertStudent($activeClassId, $this->activeYearId, '2605016', '9000000016', 'Revalidated Siswa');
        session(['tahun_ajaran_id' => $targetYearId]);

        $service = new class($studentId, $targetClassId, $targetYearId, $activeClassId, $this->activeYearId) extends TahunAjaranPurgeService {
            public function __construct(
                private readonly int $studentId,
                private readonly int $targetClassId,
                private readonly int $targetYearId,
                private readonly int $activeClassId,
                private readonly int $activeYearId
            ) {
            }

            protected function afterClassLocksAcquired(TahunAjaran $target, TahunAjaran $activeReplacement, array $targetClassIds, array $activeClassIds): void
            {
                DB::table('siswas')->where('id', $this->studentId)->update([
                    'kelas_id' => $this->targetClassId,
                    'tahun_ajaran_id' => $this->targetYearId,
                    'updated_at' => now(),
                ]);

                DB::table('siswa_kelas_semester')->insert([
                    'siswa_id' => $this->studentId,
                    'kelas_id' => $this->activeClassId,
                    'tahun_ajaran_id' => $this->activeYearId,
                    'semester' => 2,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        };
        app()->instance(TahunAjaranPurgeService::class, $service);

        $this->deleteWithConfirmation($targetYearId)
            ->assertRedirect(route('tahun.ajaran.index', ['showArchived' => 'true']))
            ->assertSessionHas('success', 'Tahun ajaran berhasil dihapus permanen.');

        $this->assertSame(0, DB::table('tahun_ajarans')->where('id', $targetYearId)->count());
        $this->assertSame(0, DB::table('kelas')->where('id', $targetClassId)->count());
        $this->assertSame($activeClassId, (int) DB::table('siswas')->where('id', $studentId)->value('kelas_id'));
        $this->assertSame($this->activeYearId, (int) DB::table('siswas')->where('id', $studentId)->value('tahun_ajaran_id'));
        $this->assertSame($this->activeYearId, session('tahun_ajaran_id'));
    }

    public function test_session_finalization_catches_storage_failure_and_reports_incomplete(): void
    {
        Log::spy();

        $result = app(TahunAjaranPurgeService::class)->finalizeSessionAfterCommitSafely(
            10,
            20,
            (int) $this->admin->id,
            fn () => 10,
            fn () => throw new \RuntimeException('session store unavailable')
        );

        $this->assertFalse($result);
        Log::shouldHaveReceived('warning')->withArgs(fn ($message, $context) => str_contains($message, 'Session finalization failed')
            && ($context['tahun_ajaran_id'] ?? null) === 10
            && ($context['active_replacement_id'] ?? null) === 20
            && ($context['admin_id'] ?? null) === (int) $this->admin->id
            && ($context['exception_class'] ?? null) === \RuntimeException::class);
    }

    public function test_json_purge_returns_success_warning_and_runs_cleanup_when_session_finalization_fails(): void
    {
        $targetYearId = $this->insertYear('2073/2074', 2, false, true);
        $targetClassId = $this->insertClass($targetYearId, '5', 'V');
        $activeClassId = $this->insertClass($this->activeYearId, '6', 'V');
        $studentId = $this->insertStudent($targetClassId, $targetYearId, '2605017', '9000000017', 'Session Json');
        $templatePath = 'templates/session-json-template.docx';
        $reportPath = 'pdf_reports/session-json-report.pdf';
        $templateId = $this->insertReportTemplate($targetYearId, $templatePath, $targetClassId);
        $this->insertReportGeneration($targetYearId, $templateId, $targetClassId, $reportPath, $studentId);
        $this->insertEnrollment($studentId, $activeClassId, $this->activeYearId, 2);
        Storage::disk('public')->put($templatePath, 'template');
        Storage::disk('public')->put($reportPath, 'report');
        $student = Siswa::findOrFail($studentId);
        $cachedPdfPath = 'pdf_cache/session-json-cache.pdf';
        $cachedDocxPath = 'docx_reports/session-json-cache.docx';
        $requestId = 'request-session-json';
        $pdfCacheKey = PdfCacheService::getCacheKey($student, 'UTS', $targetYearId);
        $docxCacheKey = PdfCacheService::getDocxCacheKey($student, 'UTS', $targetYearId);
        $requestKey = PdfCacheService::getGenerationRequestKey($student, 'UTS', $targetYearId);
        $progressKey = PdfCacheService::getProgressKey($requestId);
        $autoPrepareKey = PdfCacheService::getAutoPrepareTokenKey($student, 'UTS', $targetYearId);
        Storage::disk('public')->put($cachedPdfPath, 'cached pdf');
        Storage::disk('public')->put($cachedDocxPath, 'cached docx');
        Cache::put($pdfCacheKey, [
            'path' => $cachedPdfPath,
            'generated_at' => now(),
            'filename' => 'session-json-cache.pdf',
            'file_size' => 10,
        ], now()->addHour());
        Cache::put($docxCacheKey, [
            'path' => $cachedDocxPath,
            'generated_at' => now(),
            'filename' => 'session-json-cache.docx',
        ], now()->addHour());
        Cache::put($requestKey, $requestId, now()->addHour());
        Cache::put($progressKey, ['status' => 'processing'], now()->addHour());
        Cache::put($autoPrepareKey, 'scheduled', now()->addHour());
        session(['tahun_ajaran_id' => $targetYearId]);

        $service = Mockery::mock(TahunAjaranPurgeService::class)->makePartial();
        $service->shouldReceive('finalizeSessionAfterCommitSafely')->once()->andReturn(false);
        app()->instance(TahunAjaranPurgeService::class, $service);

        $this->actingAs($this->admin, 'web')
            ->deleteJson(route('tahun.ajaran.force-delete', $targetYearId), [
                'purge_confirmation' => $this->confirmationPhrase($targetYearId),
            ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', TahunAjaranPurgeService::FILE_CLEANUP_WARNING);

        $this->assertSame(0, DB::table('tahun_ajarans')->where('id', $targetYearId)->count());
        $this->assertSame($activeClassId, (int) DB::table('siswas')->where('id', $studentId)->value('kelas_id'));
        $this->assertSame($this->activeYearId, (int) DB::table('siswas')->where('id', $studentId)->value('tahun_ajaran_id'));
        Storage::disk('public')->assertMissing($templatePath);
        Storage::disk('public')->assertMissing($reportPath);
        Storage::disk('public')->assertMissing($cachedPdfPath);
        Storage::disk('public')->assertMissing($cachedDocxPath);
        $this->assertNull(Cache::get($pdfCacheKey));
        $this->assertNull(Cache::get($docxCacheKey));
        $this->assertNull(Cache::get($requestKey));
        $this->assertNull(Cache::get($progressKey));
        $this->assertNull(Cache::get($autoPrepareKey));
    }

    public function test_web_purge_redirects_with_success_warning_when_session_finalization_fails(): void
    {
        $targetYearId = $this->insertYear('2074/2075', 1, false, true);
        $templatePath = 'templates/session-web-template.docx';
        $this->insertReportTemplate($targetYearId, $templatePath);
        Storage::disk('public')->put($templatePath, 'template');
        session(['tahun_ajaran_id' => $targetYearId]);

        $service = Mockery::mock(TahunAjaranPurgeService::class)->makePartial();
        $service->shouldReceive('finalizeSessionAfterCommitSafely')->once()->andReturn(false);
        app()->instance(TahunAjaranPurgeService::class, $service);

        $this->deleteWithConfirmation($targetYearId)
            ->assertRedirect(route('tahun.ajaran.index', ['showArchived' => 'true']))
            ->assertSessionHas('success', TahunAjaranPurgeService::FILE_CLEANUP_WARNING)
            ->assertSessionMissing('error');

        $this->assertSame(0, DB::table('tahun_ajarans')->where('id', $targetYearId)->count());
        Storage::disk('public')->assertMissing($templatePath);
    }

    public function test_non_admin_access_to_permanent_purge_remains_denied(): void
    {
        $archivedId = $this->insertYear('2041/2042', 1, false, true);

        $this->delete(route('tahun.ajaran.force-delete', $archivedId), [
            'purge_confirmation' => $this->confirmationPhrase($archivedId),
        ])->assertRedirect(route('login'));

        $this->assertSame(1, DB::table('tahun_ajarans')->where('id', $archivedId)->count());
    }

    private function createSchema(): void
    {
        foreach ([
            'audit_logs',
            'capaian_phrase_defaults',
            'bobot_nilais',
            'kkms',
            'semester_snapshots',
            'capaian_range',
            'capaian_templates',
            'report_template_kelas',
            'report_mappings',
            'report_generations',
            'report_templates',
            'pembelajaran_siswa',
            'pembelajarans',
            'nilai_ekstrakurikuler',
            'ekstrakurikulers',
            'prestasis',
            'capaian_custom',
            'catatan_mata_pelajaran',
            'catatan_siswa',
            'absensis',
            'nilais',
            'tujuan_pembelajarans',
            'lingkup_materis',
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
            $table->softDeletes();
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
            $table->foreignId('tahun_ajaran_id')->nullable();
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

        Schema::create('lingkup_materis', function (Blueprint $table) {
            $table->id();
            $table->foreignId('mata_pelajaran_id');
            $table->string('judul_lingkup_materi')->nullable();
            $table->timestamps();
        });

        Schema::create('tujuan_pembelajarans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lingkup_materi_id');
            $table->string('kode_tp')->nullable();
            $table->text('deskripsi_tp')->nullable();
            $table->timestamps();
        });

        Schema::create('nilais', function (Blueprint $table) {
            $table->id();
            $table->foreignId('siswa_id')->nullable();
            $table->foreignId('mata_pelajaran_id')->nullable();
            $table->foreignId('lingkup_materi_id')->nullable();
            $table->foreignId('tujuan_pembelajaran_id')->nullable();
            $table->foreignId('tahun_ajaran_id')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        foreach (['absensis', 'catatan_siswa'] as $tableName) {
            Schema::create($tableName, function (Blueprint $table) {
                $table->id();
                $table->foreignId('siswa_id')->nullable();
                $table->foreignId('tahun_ajaran_id')->nullable();
                $table->timestamps();
                $table->softDeletes();
            });
        }

        Schema::create('catatan_mata_pelajaran', function (Blueprint $table) {
            $table->id();
            $table->foreignId('mata_pelajaran_id')->nullable();
            $table->foreignId('siswa_id')->nullable();
            $table->foreignId('tahun_ajaran_id')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('capaian_custom', function (Blueprint $table) {
            $table->id();
            $table->foreignId('siswa_id')->nullable();
            $table->foreignId('mata_pelajaran_id')->nullable();
            $table->foreignId('tahun_ajaran_id')->nullable();
            $table->timestamps();
        });

        Schema::create('ekstrakurikulers', function (Blueprint $table) {
            $table->id();
            $table->string('nama_ekstrakurikuler')->nullable();
            $table->foreignId('tahun_ajaran_id')->nullable();
            $table->timestamps();
        });

        Schema::create('nilai_ekstrakurikuler', function (Blueprint $table) {
            $table->id();
            $table->foreignId('siswa_id')->nullable();
            $table->foreignId('ekstrakurikuler_id')->nullable();
            $table->foreignId('tahun_ajaran_id')->nullable();
            $table->timestamps();
        });

        Schema::create('prestasis', function (Blueprint $table) {
            $table->id();
            $table->foreignId('kelas_id')->nullable();
            $table->foreignId('siswa_id')->nullable();
            $table->foreignId('tahun_ajaran_id')->nullable();
            $table->timestamps();
        });

        Schema::create('pembelajarans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('kelas_id')->nullable();
            $table->foreignId('mata_pelajaran_id')->nullable();
            $table->foreignId('guru_id')->nullable();
            $table->string('tahun_ajaran')->nullable();
            $table->string('semester')->nullable();
            $table->timestamps();
        });

        Schema::create('pembelajaran_siswa', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pembelajaran_id')->nullable();
            $table->foreignId('siswa_id')->nullable();
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
            $table->unsignedTinyInteger('semester')->nullable();
            $table->foreignId('kelas_id')->nullable();
            $table->foreignId('tahun_ajaran_id')->nullable();
            $table->timestamps();
        });

        Schema::create('report_mappings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('report_template_id')->nullable();
            $table->foreignId('tahun_ajaran_id')->nullable();
            $table->string('placeholder_key')->nullable();
            $table->string('data_source')->nullable();
            $table->timestamps();
        });

        Schema::create('report_template_kelas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('report_template_id')->nullable();
            $table->foreignId('kelas_id')->nullable();
            $table->timestamps();
        });

        Schema::create('report_generations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tahun_ajaran_id')->nullable();
            $table->foreignId('siswa_id')->nullable();
            $table->foreignId('report_template_id')->nullable();
            $table->foreignId('kelas_id')->nullable();
            $table->string('generated_file')->nullable();
            $table->string('type')->nullable();
            $table->timestamps();
        });

        foreach (['bobot_nilais', 'kkms', 'semester_snapshots', 'capaian_templates', 'capaian_range'] as $tableName) {
            Schema::create($tableName, function (Blueprint $table) {
                $table->id();
                $table->foreignId('tahun_ajaran_id')->nullable();
                $table->foreignId('kelas_id')->nullable();
                $table->foreignId('mata_pelajaran_id')->nullable();
                $table->timestamps();
            });
        }

        Schema::create('capaian_phrase_defaults', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tahun_ajaran_id');
            $table->foreignId('kelas_id');
            $table->foreignId('mata_pelajaran_id');
            $table->unsignedTinyInteger('semester')->default(1);
            $table->string('type')->default('tertinggi');
            $table->string('mode')->default('preset');
            $table->text('phrase')->nullable();
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

    private function deleteWithConfirmation(int $tahunAjaranId)
    {
        return $this->actingAs($this->admin, 'web')
            ->delete(route('tahun.ajaran.force-delete', $tahunAjaranId), [
                'purge_confirmation' => $this->confirmationPhrase($tahunAjaranId),
            ]);
    }

    private function deleteJsonWithConfirmation(int $tahunAjaranId)
    {
        return $this->actingAs($this->admin, 'web')
            ->deleteJson(route('tahun.ajaran.force-delete', $tahunAjaranId), [
                'purge_confirmation' => $this->confirmationPhrase($tahunAjaranId),
            ]);
    }

    private function confirmationPhrase(int $tahunAjaranId): string
    {
        $year = DB::table('tahun_ajarans')->where('id', $tahunAjaranId)->first();

        return 'HAPUS PERMANEN '.$year->tahun_ajaran.' SEMESTER '.(((int) $year->semester) === 1 ? 'GANJIL' : 'GENAP');
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

    private function insertClass(int $tahunAjaranId, string $nomor = '5', string $nama = 'A'): int
    {
        return DB::table('kelas')->insertGetId([
            'nomor_kelas' => $nomor,
            'nama_kelas' => $nama,
            'tahun_ajaran_id' => $tahunAjaranId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function insertGuru(string $name): int
    {
        return DB::table('gurus')->insertGetId([
            'nama' => $name,
            'username' => strtolower(str_replace(' ', '_', $name)).random_int(1000, 9999),
            'email' => strtolower(str_replace(' ', '.', $name)).random_int(1000, 9999).'@example.test',
            'password' => Hash::make('password'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function insertStudent(int $kelasId, int $tahunAjaranId, string $nis, string $nisn, string $name): int
    {
        return DB::table('siswas')->insertGetId([
            'nis' => $nis,
            'nisn' => $nisn,
            'nama' => $name,
            'kelas_id' => $kelasId,
            'tahun_ajaran_id' => $tahunAjaranId,
            'status' => 'aktif',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function insertEnrollment(int $siswaId, int $kelasId, int $tahunAjaranId, int $semester): int
    {
        return DB::table('siswa_kelas_semester')->insertGetId([
            'siswa_id' => $siswaId,
            'kelas_id' => $kelasId,
            'tahun_ajaran_id' => $tahunAjaranId,
            'semester' => $semester,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function insertSubject(int $tahunAjaranId, int $kelasId, string $name = 'Matematika'): int
    {
        return DB::table('mata_pelajarans')->insertGetId([
            'nama_pelajaran' => $name,
            'kelas_id' => $kelasId,
            'tahun_ajaran_id' => $tahunAjaranId,
            'semester' => 2,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function insertLingkupMateri(int $subjectId): int
    {
        return DB::table('lingkup_materis')->insertGetId([
            'mata_pelajaran_id' => $subjectId,
            'judul_lingkup_materi' => 'Bilangan',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function insertTujuanPembelajaran(int $lmId): int
    {
        return DB::table('tujuan_pembelajarans')->insertGetId([
            'lingkup_materi_id' => $lmId,
            'kode_tp' => 'TP1',
            'deskripsi_tp' => 'Memahami materi',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function insertEkskul(int $tahunAjaranId): int
    {
        return DB::table('ekstrakurikulers')->insertGetId([
            'nama_ekstrakurikuler' => 'Pramuka',
            'tahun_ajaran_id' => $tahunAjaranId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function insertReportTemplate(int $tahunAjaranId, string $path, ?int $kelasId = null, bool $active = false): int
    {
        return DB::table('report_templates')->insertGetId([
            'filename' => basename(str_replace('\\', '/', $path)),
            'path' => $path,
            'type' => 'UTS',
            'is_active' => $active,
            'tahun_ajaran' => 'Fixture',
            'tahun_ajaran_text' => 'Fixture',
            'semester' => 1,
            'kelas_id' => $kelasId,
            'tahun_ajaran_id' => $tahunAjaranId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function insertReportGeneration(?int $tahunAjaranId, ?int $templateId = null, ?int $kelasId = null, string $generatedFile = 'pdf_reports/generated.pdf', ?int $siswaId = null): int
    {
        return DB::table('report_generations')->insertGetId([
            'tahun_ajaran_id' => $tahunAjaranId,
            'siswa_id' => $siswaId,
            'report_template_id' => $templateId,
            'kelas_id' => $kelasId,
            'generated_file' => $generatedFile,
            'type' => 'UTS',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function insertOwnedRows(
        int $yearId,
        int $classId,
        int $subjectId,
        int $lmId,
        int $tpId,
        int $ekskulId,
        int $templateId,
        int $guruId,
        int $siswaId
    ): void {
        DB::table('guru_kelas')->insert([
            'guru_id' => $guruId,
            'kelas_id' => $classId,
            'is_wali_kelas' => true,
            'role' => 'wali_kelas',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('nilais')->insert([
            'siswa_id' => $siswaId,
            'mata_pelajaran_id' => $subjectId,
            'lingkup_materi_id' => $lmId,
            'tujuan_pembelajaran_id' => $tpId,
            'tahun_ajaran_id' => $yearId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        foreach (['absensis', 'catatan_siswa'] as $table) {
            DB::table($table)->insert([
                'siswa_id' => $siswaId,
                'tahun_ajaran_id' => $yearId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        DB::table('catatan_mata_pelajaran')->insert([
            'mata_pelajaran_id' => $subjectId,
            'siswa_id' => $siswaId,
            'tahun_ajaran_id' => $yearId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('capaian_custom')->insert([
            'siswa_id' => $siswaId,
            'mata_pelajaran_id' => $subjectId,
            'tahun_ajaran_id' => $yearId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('nilai_ekstrakurikuler')->insert([
            'siswa_id' => $siswaId,
            'ekstrakurikuler_id' => $ekskulId,
            'tahun_ajaran_id' => $yearId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('prestasis')->insert([
            'kelas_id' => $classId,
            'siswa_id' => $siswaId,
            'tahun_ajaran_id' => $yearId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('kkms')->insert([
            'tahun_ajaran_id' => $yearId,
            'kelas_id' => $classId,
            'mata_pelajaran_id' => $subjectId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        foreach (['bobot_nilais', 'semester_snapshots', 'capaian_templates', 'capaian_range'] as $table) {
            DB::table($table)->insert([
                'tahun_ajaran_id' => $yearId,
                'kelas_id' => $classId,
                'mata_pelajaran_id' => $subjectId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        DB::table('capaian_phrase_defaults')->insert([
            'tahun_ajaran_id' => $yearId,
            'kelas_id' => $classId,
            'mata_pelajaran_id' => $subjectId,
            'semester' => 2,
            'type' => 'tertinggi',
            'mode' => 'preset',
            'phrase' => 'Baik',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('report_mappings')->insert([
            'report_template_id' => $templateId,
            'tahun_ajaran_id' => $yearId,
            'placeholder_key' => 'nama_siswa',
            'data_source' => 'siswa.nama',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('report_template_kelas')->insert([
            'report_template_id' => $templateId,
            'kelas_id' => $classId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $reportGenerationId = $this->insertReportGeneration($yearId, $templateId, $classId, 'pdf_reports/generated.pdf', $siswaId);

        $pembelajaranId = DB::table('pembelajarans')->insertGetId([
            'kelas_id' => $classId,
            'mata_pelajaran_id' => $subjectId,
            'guru_id' => $guruId,
            'tahun_ajaran' => 'Fixture',
            'semester' => 'genap',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('pembelajaran_siswa')->insert([
            'pembelajaran_id' => $pembelajaranId,
            'siswa_id' => $siswaId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertIsInt($reportGenerationId);
    }
}

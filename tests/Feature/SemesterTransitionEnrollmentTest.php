<?php

namespace Tests\Feature;

use App\Models\Guru;
use App\Models\User;
use App\Services\SiswaKelasSemesterResolver;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class SemesterTransitionEnrollmentTest extends TestCase
{
    private User $admin;

    private Guru $budi;

    private int $sourceYearId;

    private int $class5AId;

    private int $class5BId;

    private int $mathSubjectId;

    private int $ahmadId;

    private int $sitiId;

    private int $rinaId;

    private int $dimasId;

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
        config()->set('filesystems.default', 'local');
        DB::purge('sqlite');
        DB::reconnect('sqlite');
        Cache::flush();
        Storage::fake('public');

        $this->createSchema();
        $this->seedFixture();
    }

    public function test_transition_creates_genap_year_and_continues_same_students_through_enrollment(): void
    {
        $response = $this->runTransition();

        $response->assertRedirect(route('tahun.ajaran.index'))
            ->assertSessionHas('success');

        $targetYear = $this->targetYear();
        $targetClass5A = $this->targetClass('5', 'A');
        $targetClass5B = $this->targetClass('5', 'B');

        $this->assertNotNull($targetYear);
        $this->assertTrue((bool) $targetYear->is_active);
        $this->assertFalse((bool) DB::table('tahun_ajarans')->where('id', $this->sourceYearId)->value('is_active'));
        $this->assertSame($targetYear->id, session('tahun_ajaran_id'));
        $this->assertSame(2, session('selected_semester'));

        $this->assertSame(4, DB::table('siswas')->count());
        $this->assertSame(0, DB::table('siswas')->where('nis', 'like', 'S2-%')->orWhere('nisn', 'like', 'S2-%')->count());

        foreach ([$this->ahmadId, $this->sitiId, $this->rinaId] as $studentId) {
            $this->assertDatabaseHas('siswa_kelas_semester', [
                'siswa_id' => $studentId,
                'kelas_id' => $targetClass5A->id,
                'tahun_ajaran_id' => $targetYear->id,
                'semester' => 2,
            ]);
        }

        $this->assertDatabaseHas('siswa_kelas_semester', [
            'siswa_id' => $this->dimasId,
            'kelas_id' => $targetClass5B->id,
            'tahun_ajaran_id' => $targetYear->id,
            'semester' => 2,
        ]);

        $resolver = app(SiswaKelasSemesterResolver::class);
        $students = $resolver->studentsForClass($targetClass5A->id, $targetYear->id, 2, true);
        $this->assertEqualsCanonicalizing(
            [$this->ahmadId, $this->sitiId, $this->rinaId],
            $students->pluck('id')->all()
        );
    }

    public function test_manually_created_student_receives_ganjil_enrollment_and_continues_to_genap(): void
    {
        $this->actingAs($this->admin)
            ->withSession([
                'tahun_ajaran_id' => $this->sourceYearId,
                'selected_semester' => 1,
                'no_tahun_ajaran' => false,
            ])
            ->post(route('student.store'), $this->manualStudentPayload())
            ->assertRedirect(route('student'));

        $manualStudentId = (int) DB::table('siswas')->where('nis', '2605990')->value('id');
        $this->assertGreaterThan(0, $manualStudentId);
        $this->assertDatabaseHas('siswa_kelas_semester', [
            'siswa_id' => $manualStudentId,
            'kelas_id' => $this->class5AId,
            'tahun_ajaran_id' => $this->sourceYearId,
            'semester' => 1,
        ]);

        $this->runTransition()
            ->assertRedirect(route('tahun.ajaran.index'))
            ->assertSessionHas('success');

        $targetYear = $this->targetYear();
        $targetClass5A = $this->targetClass('5', 'A');

        $this->assertDatabaseHas('siswas', [
            'id' => $manualStudentId,
            'nis' => '2605990',
            'kelas_id' => $this->class5AId,
        ]);
        $this->assertDatabaseHas('siswa_kelas_semester', [
            'siswa_id' => $manualStudentId,
            'kelas_id' => $this->class5AId,
            'tahun_ajaran_id' => $this->sourceYearId,
            'semester' => 1,
        ]);
        $this->assertDatabaseHas('siswa_kelas_semester', [
            'siswa_id' => $manualStudentId,
            'kelas_id' => $targetClass5A->id,
            'tahun_ajaran_id' => $targetYear->id,
            'semester' => 2,
        ]);

        $this->actingAs($this->admin)
            ->withSession([
                'tahun_ajaran_id' => $targetYear->id,
                'selected_semester' => 2,
                'no_tahun_ajaran' => false,
            ])
            ->get(route('student', ['search' => 'Manual Transisi']))
            ->assertOk()
            ->assertSee('Siswa Manual Transisi')
            ->assertSee('Kelas 5 A');
    }

    public function test_transition_copies_structural_data_without_copying_student_work_data(): void
    {
        $this->runTransition();

        $targetYear = $this->targetYear();
        $targetClass5A = $this->targetClass('5', 'A');
        $targetSubject = DB::table('mata_pelajarans')
            ->where('tahun_ajaran_id', $targetYear->id)
            ->where('kelas_id', $targetClass5A->id)
            ->where('nama_pelajaran', 'Matematika')
            ->first();

        $this->assertNotNull($targetSubject);
        $this->assertSame(2, (int) $targetSubject->semester);
        $this->assertDatabaseHas('guru_kelas', [
            'guru_id' => $this->budi->id,
            'kelas_id' => $targetClass5A->id,
            'is_wali_kelas' => true,
            'role' => 'wali_kelas',
        ]);

        $targetLmId = DB::table('lingkup_materis')
            ->where('mata_pelajaran_id', $targetSubject->id)
            ->value('id');

        $this->assertNotNull($targetLmId);
        $this->assertDatabaseHas('tujuan_pembelajarans', [
            'lingkup_materi_id' => $targetLmId,
            'kode_tp' => 'TP1',
        ]);
        $this->assertDatabaseHas('kkms', [
            'tahun_ajaran_id' => $targetYear->id,
            'kelas_id' => $targetClass5A->id,
            'mata_pelajaran_id' => $targetSubject->id,
            'nilai' => 75,
        ]);
        $this->assertDatabaseHas('bobot_nilais', [
            'tahun_ajaran_id' => $targetYear->id,
        ]);
        $this->assertDatabaseHas('ekstrakurikulers', [
            'tahun_ajaran_id' => $targetYear->id,
            'nama_ekstrakurikuler' => 'Pramuka',
        ]);
        $this->assertDatabaseHas('report_templates', [
            'tahun_ajaran_id' => $targetYear->id,
            'semester' => 2,
            'kelas_id' => $targetClass5A->id,
            'is_active' => false,
        ]);

        $targetTemplateId = DB::table('report_templates')
            ->where('tahun_ajaran_id', $targetYear->id)
            ->value('id');

        $this->assertDatabaseHas('report_template_kelas', [
            'report_template_id' => $targetTemplateId,
            'kelas_id' => $targetClass5A->id,
        ]);
        $this->assertDatabaseHas('report_mappings', [
            'report_template_id' => $targetTemplateId,
            'placeholder_key' => 'nama_siswa',
        ]);

        $this->assertSame(1, DB::table('nilais')->where('tahun_ajaran_id', $this->sourceYearId)->count());
        $this->assertSame(0, DB::table('nilais')->where('tahun_ajaran_id', $targetYear->id)->count());
        $this->assertSame(0, DB::table('catatan_siswa')->where('tahun_ajaran_id', $targetYear->id)->count());
        $this->assertSame(0, DB::table('catatan_mata_pelajaran')->where('tahun_ajaran_id', $targetYear->id)->count());
        $this->assertSame(0, DB::table('capaian_custom')->where('tahun_ajaran_id', $targetYear->id)->count());
        $this->assertSame(0, DB::table('nilai_ekstrakurikuler')->where('tahun_ajaran_id', $targetYear->id)->count());
        $this->assertSame(0, DB::table('report_generations')->where('tahun_ajaran_id', $targetYear->id)->count());
    }

    public function test_semester_transition_copies_report_templates_to_public_semester_copy_directory(): void
    {
        $this->assertSame('local', config('filesystems.default'));

        $utsTemplateId = (int) DB::table('report_templates')
            ->where('tahun_ajaran_id', $this->sourceYearId)
            ->where('type', 'UTS')
            ->value('id');
        $utsSourcePath = 'templates/defaults/source.docx';
        $uasStoredPath = '/public/templates/defaults/other/source.docx';
        $uasSourcePath = 'templates/defaults/other/source.docx';

        DB::table('report_templates')->where('id', $utsTemplateId)->update([
            'filename' => 'source.docx',
            'path' => $utsSourcePath,
        ]);

        $uasTemplateId = DB::table('report_templates')->insertGetId([
            'filename' => 'source.docx',
            'path' => $uasStoredPath,
            'type' => 'UAS',
            'is_active' => true,
            'tahun_ajaran' => '2026/2027',
            'tahun_ajaran_text' => '2026/2027',
            'semester' => 1,
            'kelas_id' => null,
            'tahun_ajaran_id' => $this->sourceYearId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Storage::disk('public')->put($utsSourcePath, 'uts-docx');
        Storage::disk('public')->put($uasSourcePath, 'uas-docx');

        $this->runTransition()
            ->assertRedirect(route('tahun.ajaran.index'))
            ->assertSessionHas('success');

        $targetYear = $this->targetYear();
        $this->assertNotNull($targetYear);

        $copiedTemplates = DB::table('report_templates')
            ->where('tahun_ajaran_id', $targetYear->id)
            ->orderBy('type')
            ->get(['type', 'path', 'is_active']);

        $this->assertCount(2, $copiedTemplates);
        $this->assertCount(2, $copiedTemplates->pluck('path')->unique());

        foreach ($copiedTemplates as $template) {
            $this->assertStringStartsWith("templates/semester-copies/{$targetYear->id}/", $template->path);
            $this->assertFalse(str_starts_with($template->path, 'templates/defaults/'));
            $this->assertStringNotContainsString('public/', $template->path);
            $this->assertStringNotContainsString('\\', $template->path);
            $this->assertSame(0, (int) $template->is_active);
            Storage::disk('public')->assertExists($template->path);
        }

        $utsCopiedPath = $copiedTemplates->firstWhere('type', 'UTS')->path;
        $uasCopiedPath = $copiedTemplates->firstWhere('type', 'UAS')->path;

        $this->assertStringContainsString("template-{$utsTemplateId}-uts-source.docx", $utsCopiedPath);
        $this->assertStringContainsString("template-{$uasTemplateId}-uas-source.docx", $uasCopiedPath);
    }

    public function test_semester_transition_rollback_deletes_copied_report_template_files(): void
    {
        $templateId = (int) DB::table('report_templates')
            ->where('tahun_ajaran_id', $this->sourceYearId)
            ->where('type', 'UTS')
            ->value('id');
        $sourcePath = 'templates/defaults/rollback-source.docx';

        DB::table('report_templates')->where('id', $templateId)->update([
            'filename' => 'rollback-source.docx',
            'path' => $sourcePath,
        ]);
        Storage::disk('public')->put($sourcePath, 'template-docx');

        DB::statement("
            CREATE TRIGGER fail_semester_two_attendance_copy
            BEFORE INSERT ON absensis
            WHEN NEW.semester = 2
            BEGIN
                SELECT RAISE(ABORT, 'forced attendance copy failure');
            END
        ");

        $this->runTransition()
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertSame(1, DB::table('tahun_ajarans')->count());
        $this->assertSame(1, DB::table('report_templates')->where('tahun_ajaran_id', $this->sourceYearId)->count());
        Storage::disk('public')->assertExists($sourcePath);
        $this->assertSame([], Storage::disk('public')->allFiles('templates/semester-copies'));
    }

    public function test_missing_source_report_template_file_reuses_existing_path_and_logs_warning(): void
    {
        Log::spy();

        $templateId = (int) DB::table('report_templates')
            ->where('tahun_ajaran_id', $this->sourceYearId)
            ->where('type', 'UTS')
            ->value('id');
        $missingPath = 'templates/defaults/missing-source.docx';

        DB::table('report_templates')->where('id', $templateId)->update([
            'filename' => 'missing-source.docx',
            'path' => $missingPath,
        ]);
        Storage::disk('public')->assertMissing($missingPath);

        $this->runTransition()
            ->assertRedirect(route('tahun.ajaran.index'))
            ->assertSessionHas('success');

        $targetYear = $this->targetYear();
        $this->assertNotNull($targetYear);

        $copiedPath = DB::table('report_templates')
            ->where('tahun_ajaran_id', $targetYear->id)
            ->value('path');

        $this->assertSame($missingPath, $copiedPath);
        $this->assertSame([], Storage::disk('public')->allFiles('templates/semester-copies'));

        Log::shouldHaveReceived('warning')->withArgs(function ($message, $context) use ($templateId, $missingPath) {
            return $message === 'Report template file missing during semester transition; copied template metadata will reuse existing path.'
                && ($context['report_template_id'] ?? null) === $templateId
                && ($context['path'] ?? null) === $missingPath;
        });
    }

    public function test_transition_skips_bobot_when_source_has_no_persisted_bobot(): void
    {
        DB::table('bobot_nilais')->where('tahun_ajaran_id', $this->sourceYearId)->delete();

        $this->runTransition()
            ->assertRedirect(route('tahun.ajaran.index'))
            ->assertSessionHas('success');

        $targetYear = $this->targetYear();
        $this->assertNotNull($targetYear);
        $this->assertSame(0, DB::table('bobot_nilais')->where('tahun_ajaran_id', $targetYear->id)->count());
    }

    public function test_transition_initializes_blank_genap_attendance_with_same_student_ids(): void
    {
        $this->runTransition();

        $targetYear = $this->targetYear();
        $targetAbsensi = DB::table('absensis')
            ->where('tahun_ajaran_id', $targetYear->id)
            ->where('semester', 2)
            ->get();

        $this->assertCount(4, $targetAbsensi);
        $this->assertEqualsCanonicalizing(
            [$this->ahmadId, $this->sitiId, $this->rinaId, $this->dimasId],
            $targetAbsensi->pluck('siswa_id')->all()
        );
        $this->assertTrue($targetAbsensi->every(fn ($row) => $row->sakit == 0 && $row->izin == 0 && $row->tanpa_keterangan == 0));

        $this->assertDatabaseHas('absensis', [
            'siswa_id' => $this->ahmadId,
            'tahun_ajaran_id' => $this->sourceYearId,
            'semester' => 1,
            'sakit' => 2,
        ]);
    }

    public function test_transition_does_not_mutate_student_identity_or_legacy_kelas_id(): void
    {
        $before = DB::table('siswas')->orderBy('id')->get(['id', 'nis', 'nisn', 'kelas_id']);

        $this->runTransition();

        $after = DB::table('siswas')->orderBy('id')->get(['id', 'nis', 'nisn', 'kelas_id']);

        $this->assertEquals($before, $after);
    }

    public function test_repeated_transition_is_rejected_without_duplicate_rows(): void
    {
        $this->runTransition();
        $targetYearId = $this->targetYear()->id;
        $counts = $this->coreCounts();

        $this->runTransition()
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertSame($counts, $this->coreCounts());
        $this->assertSame(1, DB::table('tahun_ajarans')->where('tahun_ajaran', '2026/2027')->where('semester', 2)->count());
        $this->assertSame($targetYearId, DB::table('tahun_ajarans')->where('is_active', true)->value('id'));
    }

    public function test_transition_with_archived_genap_returns_clear_restore_message(): void
    {
        $archivedGenapId = $this->insertArchivedSameAcademicYear(2);
        $counts = $this->coreCounts();

        $this->runTransition()
            ->assertRedirect()
            ->assertSessionHas('error', 'Semester Genap untuk tahun ajaran ini sudah ada di arsip. Pulihkan Semester Genap dari arsip terlebih dahulu, lalu aktifkan semester tersebut jika ingin melanjutkan.');

        $this->assertSame($counts, $this->coreCounts());
        $this->assertNotNull(DB::table('tahun_ajarans')->where('id', $archivedGenapId)->value('deleted_at'));
        $this->assertSame($this->sourceYearId, DB::table('tahun_ajarans')->where('is_active', true)->value('id'));
    }

    public function test_semester_genap_page_renders_readiness_and_does_not_persist_default_bobot(): void
    {
        DB::table('bobot_nilais')->where('tahun_ajaran_id', $this->sourceYearId)->delete();
        DB::table('tahun_ajarans')->insert([
            'tahun_ajaran' => '2025/2026',
            'is_active' => false,
            'tanggal_mulai' => '2025-07-01',
            'tanggal_selesai' => '2026-06-30',
            'semester' => 1,
            'deskripsi' => 'Other year',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $counts = $this->coreCounts();

        $this->actingAs($this->admin)
            ->get(route('tahun.ajaran.show', $this->sourceYearId))
            ->assertOk()
            ->assertSeeText('Edit Tahun Ajaran')
            ->assertSeeText('Lanjutkan ke Semester Genap')
            ->assertSee('data-semester-transition-trigger', false)
            ->assertSee('data-semester-transition-modal', false)
            ->assertSee('transitionModalOpen: false', false)
            ->assertSee('x-show="transitionModalOpen"', false)
            ->assertSee('x-cloak', false)
            ->assertDontSee('w-full rounded-lg border border-amber-200 bg-amber-50 p-4', false)
            ->assertSeeText('Ringkasan di bawah dihitung saat halaman ini dimuat. Jika data sumber berubah, muat ulang halaman sebelum melanjutkan.')
            ->assertDontSeeText('Ringkasan di bawah adalah snapshot saat tombol ini dibuka.')
            ->assertSeeText('Kelas')
            ->assertSeeText('2 kelas tersedia')
            ->assertSeeText('Roster siswa sumber')
            ->assertSeeText('4 siswa terdeteksi dari kelas sumber')
            ->assertSeeText('1 kelas belum memiliki wali kelas')
            ->assertSeeText('2 mata pelajaran tersedia')
            ->assertSeeText('1 mata pelajaran belum memiliki LM/TP lengkap')
            ->assertSeeText('Menggunakan default sementara (1:1:2), belum tersimpan');

        $this->assertSame(0, DB::table('bobot_nilais')->where('tahun_ajaran_id', $this->sourceYearId)->count());
        $this->assertSame($counts, $this->coreCounts());
        $this->assertDatabaseMissing('tahun_ajarans', [
            'tahun_ajaran' => '2026/2027',
            'semester' => 2,
        ]);
    }

    public function test_semester_transition_without_typed_confirmation_is_rejected_without_creating_target(): void
    {
        $counts = $this->coreCounts();

        $this->actingAs($this->admin)
            ->withSession([
                'tahun_ajaran_id' => $this->sourceYearId,
                'selected_semester' => 1,
                'no_tahun_ajaran' => false,
            ])
            ->post(route('tahun.ajaran.advance-semester', $this->sourceYearId))
            ->assertRedirect()
            ->assertSessionHas('error', 'Konfirmasi tidak sesuai. Ketik kalimat yang diminta untuk melanjutkan.')
            ->assertSessionHasErrors('transition_confirmation');

        $this->assertSame($counts, $this->coreCounts());
        $this->assertDatabaseMissing('tahun_ajarans', [
            'tahun_ajaran' => '2026/2027',
            'semester' => 2,
        ]);
        $this->assertSame($this->sourceYearId, DB::table('tahun_ajarans')->where('is_active', true)->value('id'));
    }

    public function test_semester_transition_with_incorrect_confirmation_is_rejected_without_changing_source(): void
    {
        $counts = $this->coreCounts();

        $this->actingAs($this->admin)
            ->withSession([
                'tahun_ajaran_id' => $this->sourceYearId,
                'selected_semester' => 1,
                'no_tahun_ajaran' => false,
            ])
            ->post(route('tahun.ajaran.advance-semester', $this->sourceYearId), [
                'transition_confirmation' => 'lanjutkan',
            ])
            ->assertRedirect()
            ->assertSessionHas('error', 'Konfirmasi tidak sesuai. Ketik kalimat yang diminta untuk melanjutkan.')
            ->assertSessionHasErrors('transition_confirmation');

        $this->assertSame($counts, $this->coreCounts());
        $this->assertTrue((bool) DB::table('tahun_ajarans')->where('id', $this->sourceYearId)->value('is_active'));
    }

    public function test_semester_transition_modal_reopens_after_backend_confirmation_validation_error(): void
    {
        $this->actingAs($this->admin)
            ->from(route('tahun.ajaran.show', $this->sourceYearId))
            ->withSession([
                'tahun_ajaran_id' => $this->sourceYearId,
                'selected_semester' => 1,
                'no_tahun_ajaran' => false,
            ])
            ->post(route('tahun.ajaran.advance-semester', $this->sourceYearId), [
                'transition_confirmation' => 'lanjutkan',
            ])
            ->assertRedirect(route('tahun.ajaran.show', $this->sourceYearId))
            ->assertSessionHasErrors('transition_confirmation');

        $this->actingAs($this->admin)
            ->get(route('tahun.ajaran.show', $this->sourceYearId))
            ->assertOk()
            ->assertSee('transitionModalOpen: true', false)
            ->assertSee('x-init="if (transitionModalOpen)', false)
            ->assertSeeText('Konfirmasi tidak sesuai. Ketik kalimat yang diminta untuk melanjutkan.')
            ->assertSeeText('Ringkasan di bawah dihitung saat halaman ini dimuat. Jika data sumber berubah, muat ulang halaman sebelum melanjutkan.')
            ->assertDontSeeText('Ringkasan di bawah adalah snapshot saat tombol ini dibuka.');
    }

    public function test_semester_transition_rejects_active_source_that_is_not_semester_ganjil(): void
    {
        DB::table('tahun_ajarans')->where('id', $this->sourceYearId)->update(['semester' => 0]);
        $counts = $this->coreCounts();

        $this->runTransition()
            ->assertRedirect()
            ->assertSessionHas('error', 'Pembuatan Semester Genap hanya dapat dilakukan dari Semester Ganjil.');

        $this->assertSame($counts, $this->coreCounts());
        $this->assertDatabaseMissing('tahun_ajarans', [
            'tahun_ajaran' => '2026/2027',
            'semester' => 2,
        ]);
        $this->assertSame($this->sourceYearId, DB::table('tahun_ajarans')->where('is_active', true)->value('id'));
    }

    public function test_semester_genap_ui_renders_phase_one_safeguards(): void
    {
        $response = $this->actingAs($this->admin)
            ->get(route('tahun.ajaran.show', $this->sourceYearId))
            ->assertOk();

        $response
            ->assertSeeText('Edit Tahun Ajaran')
            ->assertSeeText('Lanjutkan ke Semester Genap')
            ->assertSeeText('Konfirmasi Lanjut ke Semester Genap')
            ->assertSeeText('Perubahan yang Anda lakukan pada Semester Ganjil setelah proses ini tidak akan otomatis disalin ke Semester Genap.')
            ->assertSeeText('Semester Genap akan menjadi periode aktif untuk Admin, Pengajar, dan Wali Kelas.')
            ->assertSeeText('Ringkasan di bawah dihitung saat halaman ini dimuat. Jika data sumber berubah, muat ulang halaman sebelum melanjutkan.')
            ->assertDontSeeText('Ringkasan di bawah adalah snapshot saat tombol ini dibuka.')
            ->assertSeeText('Batal')
            ->assertSeeText('Memproses...')
            ->assertSee('transitionModalOpen: false', false)
            ->assertSee('x-init="if (transitionModalOpen)', false)
            ->assertSee('x-on:keydown.escape.window="closeTransitionModal(); closePurgeModal()"', false)
            ->assertSee('x-on:submit="if (!canSubmit || submitting)', false)
            ->assertSeeText('LANJUTKAN KE SEMESTER GENAP')
            ->assertSee('disabled', false);

        $content = $response->getContent();

        $this->assertMatchesRegularExpression(
            '/<input\b(?=[^>]*id="transition_confirmation_semester")(?=[^>]*name="transition_confirmation")(?=[^>]*x-bind:readonly="submitting")(?=[^>]*read-only:cursor-wait)(?=[^>]*read-only:bg-gray-100)[^>]*>/',
            $content
        );
        $this->assertDoesNotMatchRegularExpression(
            '/<input\b(?=[^>]*id="transition_confirmation_semester")(?=[^>]*x-bind:disabled="submitting")[^>]*>/',
            $content
        );
    }

    public function test_archive_ui_does_not_render_active_semester_transition_actions(): void
    {
        $archivedActiveGanjilId = $this->insertArchivedSameAcademicYear(1, true);
        $archivedGenapId = $this->insertArchivedSameAcademicYear(2);

        $this->actingAs($this->admin)
            ->get(route('tahun.ajaran.index', ['showArchived' => 'true']))
            ->assertOk()
            ->assertDontSeeText('Lanjutkan ke Semester Selanjutnya')
            ->assertDontSeeText('Lanjutkan ke Semester Genap');

        $this->actingAs($this->admin)
            ->get(route('tahun.ajaran.show', $archivedActiveGanjilId))
            ->assertOk()
            ->assertSeeText('Diarsipkan')
            ->assertSeeText('Pulihkan Tahun Ajaran')
            ->assertDontSeeText('Lanjutkan ke Semester Genap');

        $this->actingAs($this->admin)
            ->get(route('tahun.ajaran.show', $archivedGenapId))
            ->assertOk()
            ->assertSeeText('Diarsipkan')
            ->assertSeeText('Pulihkan Tahun Ajaran')
            ->assertDontSeeText('Buat Tahun Ajaran Berikutnya');
    }

    public function test_transition_action_rejects_archived_tahun_ajaran_with_friendly_message(): void
    {
        $archivedActiveGanjilId = $this->insertArchivedSameAcademicYear(1, true);
        $counts = $this->coreCounts();

        $this->actingAs($this->admin)
            ->post(route('tahun.ajaran.advance-semester', $archivedActiveGanjilId))
            ->assertRedirect()
            ->assertSessionHas('error', 'Tahun ajaran yang berada di arsip harus dipulihkan terlebih dahulu sebelum dapat dilanjutkan ke semester berikutnya.');

        $this->assertSame($counts, $this->coreCounts());
        $this->assertNotNull(DB::table('tahun_ajarans')->where('id', $archivedActiveGanjilId)->value('deleted_at'));
        $this->assertSame($this->sourceYearId, DB::table('tahun_ajarans')->where('is_active', true)->whereNull('deleted_at')->value('id'));
    }

    public function test_archived_genap_can_be_restored_while_ganjil_is_active(): void
    {
        $archivedGenapId = $this->insertArchivedSameAcademicYear(2);

        $this->actingAs($this->admin)
            ->post(route('tahun.ajaran.restore', $archivedGenapId))
            ->assertRedirect(route('tahun.ajaran.index', ['showArchived' => true]))
            ->assertSessionHas('success');

        $restored = DB::table('tahun_ajarans')->where('id', $archivedGenapId)->first();

        $this->assertNull($restored->deleted_at);
        $this->assertFalse((bool) $restored->is_active);
        $this->assertSame($this->sourceYearId, DB::table('tahun_ajarans')->where('is_active', true)->value('id'));
    }

    public function test_restore_is_blocked_when_same_semester_record_exists(): void
    {
        $archivedDuplicateGanjilId = $this->insertArchivedSameAcademicYear(1);

        $this->actingAs($this->admin)
            ->post(route('tahun.ajaran.restore', $archivedDuplicateGanjilId))
            ->assertRedirect()
            ->assertSessionHas('error', 'Tidak dapat memulihkan tahun ajaran ini karena semester yang sama sudah ada. Periksa daftar tahun ajaran sebelum memulihkan.');

        $this->assertNotNull(DB::table('tahun_ajarans')->where('id', $archivedDuplicateGanjilId)->value('deleted_at'));
        $this->assertSame($this->sourceYearId, DB::table('tahun_ajarans')->where('is_active', true)->value('id'));
    }

    public function test_empty_archived_genap_in_active_year_flow_can_be_permanently_deleted(): void
    {
        $archivedGenapId = $this->insertArchivedSameAcademicYear(2);

        $this->actingAs($this->admin)
            ->delete(route('tahun.ajaran.force-delete', $archivedGenapId), [
                'purge_confirmation' => 'HAPUS PERMANEN 2026/2027 SEMESTER GENAP',
            ])
            ->assertRedirect(route('tahun.ajaran.index', ['showArchived' => 'true']))
            ->assertSessionHas('success', 'Tahun ajaran berhasil dihapus permanen.');

        $this->assertSame(0, DB::table('tahun_ajarans')->where('id', $archivedGenapId)->count());
        $this->assertSame($this->sourceYearId, DB::table('tahun_ajarans')->where('is_active', true)->value('id'));
        $this->assertNull(DB::table('tahun_ajarans')->where('id', $this->sourceYearId)->value('deleted_at'));
    }

    public function test_mid_transition_failure_rolls_back_database_changes(): void
    {
        DB::statement("
            CREATE TRIGGER fail_semester_two_subject_copy
            BEFORE INSERT ON mata_pelajarans
            WHEN NEW.semester = 2
            BEGIN
                SELECT RAISE(ABORT, 'forced semester transition failure');
            END
        ");

        $this->runTransition()
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertSame(1, DB::table('tahun_ajarans')->count());
        $this->assertSame(2, DB::table('kelas')->count());
        $this->assertSame(4, DB::table('siswa_kelas_semester')->count());
        $this->assertSame($this->sourceYearId, DB::table('tahun_ajarans')->where('is_active', true)->value('id'));
        $this->assertSame(0, DB::table('siswas')->where('nis', 'like', 'S2-%')->orWhere('nisn', 'like', 'S2-%')->count());
    }

    public function test_source_legacy_kelas_fallback_creates_target_enrollment_when_no_enrollment_exists(): void
    {
        DB::table('siswa_kelas_semester')->where('siswa_id', $this->dimasId)->delete();

        $this->runTransition();

        $targetYear = $this->targetYear();
        $targetClass5B = $this->targetClass('5', 'B');

        $this->assertDatabaseHas('siswa_kelas_semester', [
            'siswa_id' => $this->dimasId,
            'kelas_id' => $targetClass5B->id,
            'tahun_ajaran_id' => $targetYear->id,
            'semester' => 2,
        ]);
    }

    public function test_source_s2_student_rows_abort_transition_without_modifying_existing_data(): void
    {
        $s2Id = DB::table('siswas')->insertGetId([
            'nis' => 'S2-2605999',
            'nisn' => 'S2-9000009999',
            'nama' => 'Legacy S2 Student',
            'tanggal_lahir' => '2015-01-01',
            'jenis_kelamin' => 'L',
            'agama' => 'Islam',
            'alamat' => 'Demo',
            'kelas_id' => $this->class5AId,
            'tahun_ajaran_id' => $this->sourceYearId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('siswa_kelas_semester')->insert([
            'siswa_id' => $s2Id,
            'kelas_id' => $this->class5AId,
            'tahun_ajaran_id' => $this->sourceYearId,
            'semester' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->runTransition()
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertSame(1, DB::table('tahun_ajarans')->count());
        $this->assertSame(1, DB::table('siswas')->where('id', $s2Id)->count());
        $this->assertSame($this->sourceYearId, DB::table('tahun_ajarans')->where('is_active', true)->value('id'));
    }

    private function runTransition()
    {
        return $this->actingAs($this->admin)
            ->withSession([
                'tahun_ajaran_id' => $this->sourceYearId,
                'selected_semester' => 1,
                'no_tahun_ajaran' => false,
            ])
            ->post(route('tahun.ajaran.advance-semester', $this->sourceYearId), [
                'transition_confirmation' => 'LANJUTKAN KE SEMESTER GENAP',
            ]);
    }

    private function manualStudentPayload(array $overrides = []): array
    {
        return array_merge([
            'nis' => '2605990',
            'nisn' => '9000000990',
            'nama' => 'Siswa Manual Transisi',
            'tanggal_lahir' => '2015-01-01',
            'jenis_kelamin' => 'Laki-laki',
            'agama' => 'Islam',
            'alamat' => 'Jl. Manual',
            'kelas_id' => $this->class5AId,
            'nama_ayah' => 'Ayah Manual',
            'nama_ibu' => 'Ibu Manual',
            'pekerjaan_ayah' => 'Guru',
            'pekerjaan_ibu' => 'Guru',
            'alamat_orangtua' => 'Jl. Orang Tua',
            'wali_siswa' => '',
            'pekerjaan_wali' => '',
        ], $overrides);
    }

    private function targetYear(): ?object
    {
        return DB::table('tahun_ajarans')
            ->where('tahun_ajaran', '2026/2027')
            ->where('semester', 2)
            ->first();
    }

    private function targetClass(string $nomorKelas, string $namaKelas): object
    {
        return DB::table('kelas')
            ->where('tahun_ajaran_id', $this->targetYear()->id)
            ->where('nomor_kelas', $nomorKelas)
            ->where('nama_kelas', $namaKelas)
            ->first();
    }

    private function coreCounts(): array
    {
        return [
            'tahun_ajarans' => DB::table('tahun_ajarans')->count(),
            'kelas' => DB::table('kelas')->count(),
            'siswas' => DB::table('siswas')->count(),
            'enrollments' => DB::table('siswa_kelas_semester')->count(),
            'subjects' => DB::table('mata_pelajarans')->count(),
            'absensi' => DB::table('absensis')->count(),
        ];
    }

    private function insertArchivedSameAcademicYear(int $semester, bool $isActive = false): int
    {
        return DB::table('tahun_ajarans')->insertGetId([
            'tahun_ajaran' => '2026/2027',
            'is_active' => $isActive,
            'tanggal_mulai' => $semester === 1 ? '2026-07-01' : '2027-01-01',
            'tanggal_selesai' => '2027-06-30',
            'semester' => $semester,
            'deskripsi' => 'Archived fixture',
            'created_at' => now(),
            'updated_at' => now(),
            'deleted_at' => now(),
        ]);
    }

    private function createSchema(): void
    {
        foreach ([
            'notification_reads',
            'notifications',
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
            $table->date('tanggal_lahir')->nullable();
            $table->string('jenis_kelamin')->nullable();
            $table->string('agama')->nullable();
            $table->text('alamat')->nullable();
            $table->foreignId('kelas_id')->nullable();
            $table->string('nama_ayah')->nullable();
            $table->string('nama_ibu')->nullable();
            $table->string('pekerjaan_ayah')->nullable();
            $table->string('pekerjaan_ibu')->nullable();
            $table->text('alamat_orangtua')->nullable();
            $table->string('photo')->nullable();
            $table->string('wali_siswa')->nullable();
            $table->string('pekerjaan_wali')->nullable();
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
            $table->index(['kelas_id', 'tahun_ajaran_id', 'semester'], 'siswa_kelas_semester_class_context_index');
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
            $table->foreignId('tahun_ajaran_id')->nullable();
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
            $table->decimal('nilai_akhir_semester', 5, 2)->nullable();
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
            'semester' => 1,
            'deskripsi' => 'Demo ganjil',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('profil_sekolah')->insert([
            'nama_sekolah' => 'SDIT Al Hidayah',
            'tahun_pelajaran' => '2026/2027',
            'semester' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->class5AId = $this->insertClass('5', 'A');
        $this->class5BId = $this->insertClass('5', 'B');

        foreach ([$this->class5AId, $this->class5BId] as $classId) {
            DB::table('guru_kelas')->insert([
                [
                    'guru_id' => $this->budi->id,
                    'kelas_id' => $classId,
                    'is_wali_kelas' => $classId === $this->class5AId,
                    'role' => $classId === $this->class5AId ? 'wali_kelas' : 'pengajar',
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
            ]);
        }

        $this->ahmadId = $this->insertStudent('2605001', '9000000001', 'Ahmad Fauzan', $this->class5AId);
        $this->sitiId = $this->insertStudent('2605002', '9000000002', 'Siti Aisyah', $this->class5AId);
        $this->rinaId = $this->insertStudent('2605003', '9000000003', 'Rina Putri', $this->class5AId);
        $this->dimasId = $this->insertStudent('2605004', '9000000004', 'Dimas Pratama', $this->class5BId);

        foreach ([
            [$this->ahmadId, $this->class5AId],
            [$this->sitiId, $this->class5AId],
            [$this->rinaId, $this->class5AId],
            [$this->dimasId, $this->class5BId],
        ] as [$studentId, $classId]) {
            DB::table('siswa_kelas_semester')->insert([
                'siswa_id' => $studentId,
                'kelas_id' => $classId,
                'tahun_ajaran_id' => $this->sourceYearId,
                'semester' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $this->mathSubjectId = DB::table('mata_pelajarans')->insertGetId([
            'nama_pelajaran' => 'Matematika',
            'kelas_id' => $this->class5AId,
            'semester' => 1,
            'guru_id' => $this->budi->id,
            'tahun_ajaran_id' => $this->sourceYearId,
            'is_muatan_lokal' => false,
            'allow_non_wali' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('mata_pelajarans')->insert([
            'nama_pelajaran' => 'Matematika',
            'kelas_id' => $this->class5BId,
            'semester' => 1,
            'guru_id' => $this->budi->id,
            'tahun_ajaran_id' => $this->sourceYearId,
            'is_muatan_lokal' => false,
            'allow_non_wali' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $lmId = DB::table('lingkup_materis')->insertGetId([
            'mata_pelajaran_id' => $this->mathSubjectId,
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
            'mata_pelajaran_id' => $this->mathSubjectId,
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

        $templateId = DB::table('report_templates')->insertGetId([
            'filename' => 'demo.docx',
            'path' => null,
            'type' => 'UTS',
            'is_active' => true,
            'tahun_ajaran' => '2026/2027',
            'tahun_ajaran_text' => '2026/2027',
            'semester' => 1,
            'kelas_id' => $this->class5AId,
            'tahun_ajaran_id' => $this->sourceYearId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('report_template_kelas')->insert([
            'report_template_id' => $templateId,
            'kelas_id' => $this->class5AId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('report_mappings')->insert([
            'report_template_id' => $templateId,
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
            'siswa_id' => $this->ahmadId,
            'mata_pelajaran_id' => $this->mathSubjectId,
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
            'siswa_id' => $this->ahmadId,
            'sakit' => 2,
            'izin' => 1,
            'tanpa_keterangan' => 0,
            'semester' => 1,
            'tahun_ajaran_id' => $this->sourceYearId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('catatan_siswa')->insert([
            'siswa_id' => $this->ahmadId,
            'catatan' => 'Ganjil note',
            'tahun_ajaran_id' => $this->sourceYearId,
            'semester' => 1,
            'type' => 'umum',
            'created_by' => $this->budi->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('catatan_mata_pelajaran')->insert([
            'mata_pelajaran_id' => $this->mathSubjectId,
            'siswa_id' => $this->ahmadId,
            'catatan' => 'Subject note',
            'tahun_ajaran_id' => $this->sourceYearId,
            'semester' => 1,
            'type' => 'umum',
            'created_by' => $this->budi->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('capaian_custom')->insert([
            'siswa_id' => $this->ahmadId,
            'mata_pelajaran_id' => $this->mathSubjectId,
            'custom_capaian' => 'Capaian ganjil',
            'tahun_ajaran_id' => $this->sourceYearId,
            'semester' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('nilai_ekstrakurikuler')->insert([
            'siswa_id' => $this->ahmadId,
            'ekstrakurikuler_id' => $ekskulId,
            'predikat' => 'A',
            'deskripsi' => 'Ekskul ganjil',
            'tahun_ajaran_id' => $this->sourceYearId,
            'semester' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('report_generations')->insert([
            'siswa_id' => $this->ahmadId,
            'kelas_id' => $this->class5AId,
            'report_template_id' => $templateId,
            'generated_file' => 'reports/ahmad.pdf',
            'type' => 'UTS',
            'tahun_ajaran' => '2026/2027',
            'semester' => 1,
            'generated_at' => now(),
            'generated_by' => $this->budi->id,
            'tahun_ajaran_id' => $this->sourceYearId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function insertClass(string $nomorKelas, string $namaKelas): int
    {
        return DB::table('kelas')->insertGetId([
            'nomor_kelas' => $nomorKelas,
            'nama_kelas' => $namaKelas,
            'tahun_ajaran_id' => $this->sourceYearId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function insertStudent(string $nis, string $nisn, string $nama, int $kelasId): int
    {
        return DB::table('siswas')->insertGetId([
            'nis' => $nis,
            'nisn' => $nisn,
            'nama' => $nama,
            'tanggal_lahir' => '2015-01-01',
            'jenis_kelamin' => 'L',
            'agama' => 'Islam',
            'alamat' => 'Demo',
            'kelas_id' => $kelasId,
            'tahun_ajaran_id' => $this->sourceYearId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}

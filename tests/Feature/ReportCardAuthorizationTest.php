<?php

namespace Tests\Feature;

use App\Jobs\AutoPreparePdfReportJob;
use App\Jobs\GeneratePdfReportJob;
use App\Models\Guru;
use App\Models\Siswa;
use App\Models\User;
use App\Services\DocumentConversionService;
use App\Services\PdfCacheService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Js;
use Tests\TestCase;

class ReportCardAuthorizationTest extends TestCase
{
    private Guru $wali;

    private User $admin;

    private int $activeYearId;

    private int $oldYearId;

    private int $currentClassId;

    private int $otherClassId;

    private int $oldClassId;

    private int $authorizedStudentId;

    private int $otherClassStudentId;

    private int $oldYearStudentId;

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
        $this->seedAuthorizationFixture();
    }

    public function test_wali_can_preview_report_for_student_in_owned_class_and_active_year(): void
    {
        $response = $this->actingAsWali()
            ->get(route('wali_kelas.rapor.preview', $this->authorizedStudentId));

        $response->assertOk()
            ->assertJsonPath('success', true);
    }

    public function test_wali_cannot_preview_report_for_student_from_another_class(): void
    {
        $this->actingAsWali()
            ->get(route('wali_kelas.rapor.preview', $this->otherClassStudentId))
            ->assertForbidden();
    }

    public function test_wali_cannot_preview_report_for_student_from_class_owned_only_in_another_year(): void
    {
        $this->actingAsWali()
            ->get(route('wali_kelas.rapor.preview', $this->oldYearStudentId))
            ->assertForbidden();
    }

    public function test_wali_cannot_preview_report_when_requested_year_does_not_match_student_class_year(): void
    {
        $this->actingAsWali()
            ->get(route('wali_kelas.rapor.preview', [
                'siswa' => $this->oldYearStudentId,
                'tahun_ajaran_id' => $this->activeYearId,
            ]))
            ->assertForbidden();
    }

    public function test_enrollment_grants_wali_access_even_when_legacy_student_class_differs(): void
    {
        $studentId = $this->insertStudent('1004', 'Enrollment Authorized Student', $this->otherClassId);
        $this->insertEnrollment($studentId, $this->currentClassId, $this->activeYearId, 1);
        $this->insertReportData($studentId, $this->currentClassId, $this->activeYearId, $this->wali->id);

        $this->actingAsWali()
            ->get(route('wali_kelas.rapor.preview', $studentId))
            ->assertOk()
            ->assertJsonPath('success', true);
    }

    public function test_matching_legacy_student_class_still_grants_wali_access_without_enrollment(): void
    {
        $studentId = $this->insertStudent('1008', 'Legacy Authorized Student', $this->currentClassId);
        $this->insertReportData($studentId, $this->currentClassId, $this->activeYearId, $this->wali->id);

        $this->actingAsWali()
            ->get(route('wali_kelas.rapor.preview', $studentId))
            ->assertOk()
            ->assertJsonPath('success', true);
    }

    public function test_unrelated_legacy_student_class_does_not_grant_wali_access_when_enrollment_differs(): void
    {
        $studentId = $this->insertStudent('1005', 'Enrollment Denied Student', $this->currentClassId);
        $this->insertEnrollment($studentId, $this->otherClassId, $this->activeYearId, 1);

        $this->actingAsWali()
            ->get(route('wali_kelas.rapor.preview', $studentId))
            ->assertForbidden();
    }

    public function test_other_semester_enrollment_does_not_fall_back_to_legacy_student_class(): void
    {
        $studentId = $this->insertStudent('1006', 'Genap Only Student', $this->currentClassId);
        $this->insertEnrollment($studentId, $this->currentClassId, $this->activeYearId, 2);

        $this->actingAsWali()
            ->get(route('wali_kelas.rapor.preview', $studentId))
            ->assertForbidden();
    }

    public function test_other_year_enrollment_does_not_fall_back_to_legacy_student_class(): void
    {
        $studentId = $this->insertStudent('1007', 'Old Year Only Student', $this->currentClassId);
        $this->insertEnrollment($studentId, $this->oldClassId, $this->oldYearId, 1);

        $this->actingAsWali()
            ->get(route('wali_kelas.rapor.preview', $studentId))
            ->assertForbidden();
    }

    public function test_wali_cannot_preview_report_without_usable_academic_year(): void
    {
        Cache::flush();
        DB::table('tahun_ajarans')->delete();

        $this->actingAsWaliWithSession([
            'selected_role' => 'wali_kelas',
            'selected_semester' => 1,
            'no_tahun_ajaran' => true,
        ])
            ->get(route('wali_kelas.rapor.preview', $this->authorizedStudentId))
            ->assertForbidden();
    }

    public function test_guru_with_non_wali_selected_role_cannot_preview_report(): void
    {
        $this->actingAsWaliWithSession([
            'selected_role' => 'guru_mapel',
            'tahun_ajaran_id' => $this->activeYearId,
            'selected_semester' => 1,
            'no_tahun_ajaran' => false,
        ])
            ->get(route('wali_kelas.rapor.preview', $this->authorizedStudentId))
            ->assertForbidden();
    }

    public function test_wali_can_print_html_report_for_authorized_student(): void
    {
        $this->mock(\Illuminate\Contracts\View\Factory::class, function ($mock) {
            $mock->shouldReceive('share')->andReturnNull();
            $mock->shouldReceive('make')
                ->with('wali_kelas.rapor.print_html', \Mockery::type('array'), [])
                ->andReturn(response('print ok'));
        });

        $this->actingAsWali()
            ->get(route('wali_kelas.rapor_html.print', $this->authorizedStudentId))
            ->assertOk()
            ->assertSee('print ok');
    }

    public function test_wali_cannot_print_html_report_for_student_from_another_class(): void
    {
        $this->actingAsWali()
            ->get(route('wali_kelas.rapor_html.print', $this->otherClassStudentId))
            ->assertForbidden();
    }

    public function test_wali_cannot_preview_pdf_for_student_from_another_class(): void
    {
        $this->fakeLibreOfficeAvailability();

        $this->actingAsWali()
            ->get(route('wali_kelas.rapor.preview-pdf', [
                'siswa' => $this->otherClassStudentId,
                'tahun_ajaran_id' => $this->activeYearId,
            ]))
            ->assertForbidden();
    }

    public function test_pdf_preview_missing_template_returns_safe_response(): void
    {
        $this->fakeLibreOfficeAvailability();

        $this->actingAsWali()
            ->getJson(route('wali_kelas.rapor.preview-pdf', [
                'siswa' => $this->authorizedStudentId,
                'type' => 'UTS',
                'tahun_ajaran_id' => $this->activeYearId,
            ]))
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('error_type', 'template_missing');
    }

    public function test_pdf_download_missing_template_returns_safe_response(): void
    {
        $this->fakeLibreOfficeAvailability();

        $this->actingAsWali()
            ->getJson(route('wali_kelas.rapor.download-pdf', [
                'siswa' => $this->authorizedStudentId,
                'type' => 'UTS',
                'tahun_ajaran_id' => $this->activeYearId,
            ]))
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('error_type', 'template_missing');
    }

    public function test_pdf_preview_and_legacy_download_reuse_same_cached_file(): void
    {
        $this->insertReportTemplate($this->currentClassId);
        Storage::fake('public');
        Storage::disk('public')->put('pdf_reports/cached-report.pdf', 'PDF');

        Cache::put(PdfCacheService::getCacheKey(Siswa::find($this->authorizedStudentId), 'UTS', $this->activeYearId), [
            'path' => 'pdf_reports/cached-report.pdf',
            'filename' => 'cached-report.pdf',
            'file_size' => 3,
            'generated_at' => now()->toISOString(),
        ], now()->addHour());

        $this->mock(DocumentConversionService::class, function ($mock) {
            $mock->shouldNotReceive('isLibreOfficeAvailable');
            $mock->shouldNotReceive('convertStorageDocxToPdf');
        });

        $previewLocation = $this->actingAsWali()
            ->get(route('wali_kelas.rapor.preview-pdf', [
                'siswa' => $this->authorizedStudentId,
                'type' => 'UTS',
                'tahun_ajaran_id' => $this->activeYearId,
            ]))
            ->assertRedirect()
            ->headers->get('Location');

        $downloadLocation = $this->actingAsWali()
            ->get(route('wali_kelas.rapor.download-pdf', [
                'siswa' => $this->authorizedStudentId,
                'type' => 'UTS',
                'tahun_ajaran_id' => $this->activeYearId,
            ]))
            ->assertRedirect()
            ->headers->get('Location');

        $this->assertStringContainsString('/wali-kelas/rapor/secure-file', $previewLocation);
        $this->assertStringContainsString('/wali-kelas/rapor/secure-file', $downloadLocation);
        $this->assertStringContainsString('path=pdf_reports%2Fcached-report.pdf', $previewLocation);
        $this->assertStringContainsString('path=pdf_reports%2Fcached-report.pdf', $downloadLocation);
        $this->assertStringContainsString('disposition=inline', $previewLocation);
        $this->assertStringContainsString('disposition=attachment', $downloadLocation);
    }

    public function test_pdf_preview_cache_hit_returns_ready_json_without_dispatching_job(): void
    {
        $this->insertReportTemplate($this->currentClassId);
        Bus::fake([GeneratePdfReportJob::class]);
        Storage::fake('public');
        Storage::disk('public')->put('pdf_reports/cached-preview.pdf', 'PDF');

        Cache::put(PdfCacheService::getCacheKey(Siswa::find($this->authorizedStudentId), 'UTS', $this->activeYearId), [
            'path' => 'pdf_reports/cached-preview.pdf',
            'filename' => 'cached-preview.pdf',
            'file_size' => 3,
            'generated_at' => now()->toISOString(),
        ], now()->addHour());

        $response = $this->actingAsWali()
            ->getJson(route('wali_kelas.rapor.preview-pdf', [
                'siswa' => $this->authorizedStudentId,
                'type' => 'UTS',
                'tahun_ajaran_id' => $this->activeYearId,
            ]))
            ->assertOk()
            ->assertJsonPath('status', 'ready')
            ->assertJsonPath('cache_hit', true)
            ->assertJsonPath('filename', 'cached-preview.pdf');

        $this->assertStringContainsString('disposition=inline', $response->json('url'));

        Bus::assertNotDispatched(GeneratePdfReportJob::class);
    }

    public function test_pdf_download_cache_hit_returns_ready_json_without_dispatching_job(): void
    {
        $this->insertReportTemplate($this->currentClassId);
        Bus::fake([GeneratePdfReportJob::class]);
        Storage::fake('public');
        Storage::disk('public')->put('pdf_reports/cached-download.pdf', 'PDF');

        Cache::put(PdfCacheService::getCacheKey(Siswa::find($this->authorizedStudentId), 'UTS', $this->activeYearId), [
            'path' => 'pdf_reports/cached-download.pdf',
            'filename' => 'cached-download.pdf',
            'file_size' => 3,
            'generated_at' => now()->toISOString(),
        ], now()->addHour());

        $response = $this->actingAsWali()
            ->getJson(route('wali_kelas.rapor.download-pdf', [
                'siswa' => $this->authorizedStudentId,
                'type' => 'UTS',
                'tahun_ajaran_id' => $this->activeYearId,
            ]))
            ->assertOk()
            ->assertJsonPath('status', 'ready')
            ->assertJsonPath('cache_hit', true)
            ->assertJsonPath('filename', 'cached-download.pdf');

        $this->assertStringContainsString('disposition=attachment', $response->json('url'));

        Bus::assertNotDispatched(GeneratePdfReportJob::class);
    }

    public function test_pdf_preview_cache_miss_queues_job_without_running_conversion_in_request(): void
    {
        $this->insertReportTemplate($this->currentClassId);
        Bus::fake([GeneratePdfReportJob::class]);

        $response = $this->actingAsWali()
            ->getJson(route('wali_kelas.rapor.preview-pdf', [
                'siswa' => $this->authorizedStudentId,
                'type' => 'UTS',
                'tahun_ajaran_id' => $this->activeYearId,
            ]))
            ->assertAccepted()
            ->assertJsonPath('status', 'processing')
            ->assertJsonPath('cache_hit', false)
            ->assertJsonStructure(['request_id', 'poll_url']);

        $requestId = $response->json('request_id');
        $this->assertNotEmpty($requestId);
        $this->assertSame($requestId, Cache::get(PdfCacheService::getGenerationRequestKey(Siswa::find($this->authorizedStudentId), 'UTS', $this->activeYearId)));
        $this->assertSame($this->wali->id, Cache::get(PdfCacheService::getProgressKey($requestId))['user_id']);

        Bus::assertDispatchedTimes(GeneratePdfReportJob::class, 1);
    }

    public function test_pdf_download_cache_miss_queues_job_with_attachment_poll_url(): void
    {
        $this->insertReportTemplate($this->currentClassId);
        Bus::fake([GeneratePdfReportJob::class]);

        $response = $this->actingAsWali()
            ->getJson(route('wali_kelas.rapor.download-pdf', [
                'siswa' => $this->authorizedStudentId,
                'type' => 'UTS',
                'tahun_ajaran_id' => $this->activeYearId,
            ]))
            ->assertAccepted()
            ->assertJsonPath('status', 'processing');

        $this->assertStringContainsString('disposition=attachment', $response->json('poll_url'));

        Bus::assertDispatchedTimes(GeneratePdfReportJob::class, 1);
    }

    public function test_repeated_pdf_requests_reuse_existing_generation_request(): void
    {
        $this->insertReportTemplate($this->currentClassId);
        Bus::fake([GeneratePdfReportJob::class]);

        $first = $this->actingAsWali()
            ->getJson(route('wali_kelas.rapor.preview-pdf', [
                'siswa' => $this->authorizedStudentId,
                'type' => 'UTS',
                'tahun_ajaran_id' => $this->activeYearId,
            ]))
            ->assertAccepted();

        $second = $this->actingAsWali()
            ->getJson(route('wali_kelas.rapor.preview-pdf', [
                'siswa' => $this->authorizedStudentId,
                'type' => 'UTS',
                'tahun_ajaran_id' => $this->activeYearId,
            ]))
            ->assertAccepted()
            ->assertJsonPath('reused', true);

        $this->assertSame($first->json('request_id'), $second->json('request_id'));
        Bus::assertDispatchedTimes(GeneratePdfReportJob::class, 1);
    }

    public function test_preview_and_download_share_same_generation_request_on_cache_miss(): void
    {
        $this->insertReportTemplate($this->currentClassId);
        Bus::fake([GeneratePdfReportJob::class]);

        $preview = $this->actingAsWali()
            ->getJson(route('wali_kelas.rapor.preview-pdf', [
                'siswa' => $this->authorizedStudentId,
                'type' => 'UTS',
                'tahun_ajaran_id' => $this->activeYearId,
            ]))
            ->assertAccepted();

        $download = $this->actingAsWali()
            ->getJson(route('wali_kelas.rapor.download-pdf', [
                'siswa' => $this->authorizedStudentId,
                'type' => 'UTS',
                'tahun_ajaran_id' => $this->activeYearId,
            ]))
            ->assertAccepted()
            ->assertJsonPath('reused', true);

        $this->assertSame($preview->json('request_id'), $download->json('request_id'));
        $this->assertStringContainsString('disposition=attachment', $download->json('poll_url'));
        Bus::assertDispatchedTimes(GeneratePdfReportJob::class, 1);
    }

    public function test_pdf_progress_polling_requires_request_owner(): void
    {
        $requestId = 'pdf_foreign_request';
        Cache::put(PdfCacheService::getProgressKey($requestId), [
            'status' => 'processing',
            'message' => 'Menunggu antrean',
            'completed' => false,
            'error' => false,
            'request_id' => $requestId,
            'siswa_id' => $this->authorizedStudentId,
            'type' => 'UTS',
            'tahun_ajaran_id' => $this->activeYearId,
            'user_id' => $this->wali->id + 999,
            'updated_at' => time(),
        ], now()->addMinutes(30));

        $this->actingAsWali()
            ->getJson(route('wali_kelas.rapor.pdf-progress', $requestId))
            ->assertForbidden();
    }

    public function test_ready_pdf_progress_returns_fresh_secure_url_with_requested_disposition(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('pdf_reports/ready.pdf', 'PDF');
        $siswa = Siswa::find($this->authorizedStudentId);
        Cache::put(PdfCacheService::getCacheKey($siswa, 'UTS', $this->activeYearId), [
            'path' => 'pdf_reports/ready.pdf',
            'filename' => 'ready.pdf',
            'file_size' => 3,
            'generated_at' => now()->toISOString(),
        ], now()->addHour());

        $requestId = 'pdf_ready_request';
        Cache::put(PdfCacheService::getProgressKey($requestId), [
            'status' => 'ready',
            'message' => 'PDF siap dibuka',
            'completed' => true,
            'error' => false,
            'request_id' => $requestId,
            'siswa_id' => $this->authorizedStudentId,
            'type' => 'UTS',
            'tahun_ajaran_id' => $this->activeYearId,
            'user_id' => $this->wali->id,
            'cached' => false,
            'updated_at' => time(),
        ], now()->addMinutes(30));

        $inline = $this->actingAsWali()
            ->getJson(route('wali_kelas.rapor.pdf-progress', [
                'requestId' => $requestId,
                'disposition' => 'inline',
            ]))
            ->assertOk()
            ->assertJsonPath('status', 'ready');

        $this->assertStringContainsString('disposition=inline', $inline->json('url'));

        $attachment = $this->actingAsWali()
            ->getJson(route('wali_kelas.rapor.pdf-progress', [
                'requestId' => $requestId,
                'disposition' => 'attachment',
            ]))
            ->assertOk()
            ->assertJsonPath('status', 'ready');

        $this->assertStringContainsString('disposition=attachment', $attachment->json('url'));
    }

    public function test_pdf_template_lookup_uses_enrollment_context_not_unrelated_legacy_class(): void
    {
        $this->fakeLibreOfficeAvailability();

        $studentId = $this->insertStudent('1009', 'Enrollment Template Student', $this->otherClassId);
        $this->insertEnrollment($studentId, $this->currentClassId, $this->activeYearId, 1);
        $this->insertReportData($studentId, $this->currentClassId, $this->activeYearId, $this->wali->id);
        $this->insertReportTemplate($this->otherClassId);

        $this->actingAsWali()
            ->getJson(route('wali_kelas.rapor.preview-pdf', [
                'siswa' => $studentId,
                'type' => 'UTS',
                'tahun_ajaran_id' => $this->activeYearId,
            ]))
            ->assertStatus(422)
            ->assertJsonPath('error_type', 'template_missing');
    }

    public function test_report_index_marks_pdf_unavailable_when_template_is_missing(): void
    {
        $this->fakeLibreOfficeAvailability();

        $this->actingAsWali()
            ->get(route('wali_kelas.rapor.index', [
                'type' => 'UTS',
                'tahun_ajaran_id' => $this->activeYearId,
            ]))
            ->assertOk()
            ->assertSee('Template PDF belum tersedia untuk UTS', false)
            ->assertSee('"UTS":false', false);
    }

    public function test_report_index_keeps_pdf_actions_available_with_valid_template(): void
    {
        $this->fakeLibreOfficeAvailability();
        $this->insertReportTemplate($this->currentClassId);

        $this->actingAsWali()
            ->get(route('wali_kelas.rapor.index', [
                'type' => 'UTS',
                'tahun_ajaran_id' => $this->activeYearId,
            ]))
            ->assertOk()
            ->assertDontSee('Template PDF belum tersedia untuk UTS', false)
            ->assertSee('"UTS":true', false);
    }

    public function test_report_index_js_encodes_student_names_in_report_actions(): void
    {
        $this->fakeLibreOfficeAvailability();
        $this->insertReportTemplate($this->currentClassId);

        $studentNamesById = [];
        foreach ([
            ['1010', "Siswa 2 Sa'ad 01"],
            ['1011', 'Siswa "Test" 01'],
            ['1012', "Siswa \\ Backslash\nUnicode \u{96EA} <script> & 01"],
        ] as [$nis, $name]) {
            $studentId = $this->insertStudent($nis, $name, $this->currentClassId);
            $this->insertEnrollment($studentId, $this->currentClassId, $this->activeYearId, 1);
            $this->insertReportData($studentId, $this->currentClassId, $this->activeYearId, $this->wali->id);
            $studentNamesById[$studentId] = $name;
        }

        $html = $this->actingAsWali()
            ->get(route('wali_kelas.rapor.index', [
                'type' => 'UTS',
                'tahun_ajaran_id' => $this->activeYearId,
            ]))
            ->assertOk()
            ->getContent();

        foreach ($studentNamesById as $studentId => $name) {
            $encodedName = Js::from($name)->toHtml();

            $this->assertStringContainsString(
                "handleGenerate({$studentId}, 1, true, {$encodedName})",
                $html
            );
            $this->assertStringContainsString(
                "handleDownloadPdf({$studentId}, 1, true, {$encodedName})",
                $html
            );
        }

        $this->assertStringNotContainsString("'Siswa 2 Sa&#039;ad 01'", $html);
        $this->assertStringNotContainsString("'Siswa &quot;Test&quot; 01'", $html);
    }

    public function test_wali_dashboard_schedules_pdf_warmup_for_owned_class_and_current_semester_only(): void
    {
        config()->set('report.pdf_auto_prepare.enabled', true);
        config()->set('report.pdf_auto_prepare.delay_seconds', 60);
        config()->set('report.pdf_dashboard_warmup.enabled', true);
        config()->set('report.pdf_auto_prepare.queue', 'pdf-warm');
        Queue::fake();

        $this->insertReportTemplate($this->currentClassId, 'UTS', $this->activeYearId, 1);
        $this->insertReportTemplate($this->currentClassId, 'UAS', $this->activeYearId, 2);

        $this->actingAsWali()
            ->get(route('wali_kelas.dashboard'))
            ->assertOk();

        Queue::assertPushedOn('pdf-warm', AutoPreparePdfReportJob::class);
        Queue::assertPushed(AutoPreparePdfReportJob::class, 1);
        Queue::assertPushed(AutoPreparePdfReportJob::class, function (AutoPreparePdfReportJob $job) {
            return $job->siswaId === $this->authorizedStudentId
                && $job->type === 'UTS'
                && $job->tahunAjaranId === $this->activeYearId
                && $job->reason === 'dashboard_warmup'
                && $job->delay
                && abs($job->delay->getTimestamp() - now()->addSeconds(60)->getTimestamp()) <= 2;
        });
        Queue::assertNotPushed(
            AutoPreparePdfReportJob::class,
            fn (AutoPreparePdfReportJob $job) => $job->siswaId === $this->otherClassStudentId || $job->type === 'UAS'
        );
    }

    public function test_wali_dashboard_does_not_schedule_warmup_for_cached_pdf(): void
    {
        config()->set('report.pdf_auto_prepare.enabled', true);
        config()->set('report.pdf_dashboard_warmup.enabled', true);
        Queue::fake();

        $this->insertReportTemplate($this->currentClassId);
        $this->cachePdfForAuthorizedStudent('cached-dashboard-warmup.pdf');

        $this->actingAsWali()
            ->get(route('wali_kelas.dashboard'))
            ->assertOk();

        Queue::assertNotPushed(AutoPreparePdfReportJob::class);
    }

    public function test_wali_dashboard_warmup_cooldown_prevents_duplicate_scheduling(): void
    {
        config()->set('report.pdf_auto_prepare.enabled', true);
        config()->set('report.pdf_dashboard_warmup.enabled', true);
        config()->set('report.pdf_dashboard_warmup.cooldown_seconds', 900);
        Queue::fake();

        $this->insertReportTemplate($this->currentClassId);

        $this->actingAsWali()
            ->get(route('wali_kelas.dashboard'))
            ->assertOk();

        $this->actingAsWali()
            ->get(route('wali_kelas.dashboard'))
            ->assertOk();

        Queue::assertPushed(AutoPreparePdfReportJob::class, 1);
    }

    public function test_wali_dashboard_warmup_skips_inactive_academic_year(): void
    {
        config()->set('report.pdf_auto_prepare.enabled', true);
        config()->set('report.pdf_dashboard_warmup.enabled', true);
        Queue::fake();

        $this->insertReportTemplate($this->oldClassId, 'UTS', $this->oldYearId, 1);

        $this->actingAsWaliWithSession([
            'selected_role' => 'wali_kelas',
            'tahun_ajaran_id' => $this->oldYearId,
            'selected_semester' => 1,
            'no_tahun_ajaran' => false,
        ])
            ->get(route('wali_kelas.dashboard'))
            ->assertOk();

        Queue::assertNotPushed(AutoPreparePdfReportJob::class);
    }

    public function test_non_wali_cannot_trigger_dashboard_pdf_warmup(): void
    {
        config()->set('report.pdf_auto_prepare.enabled', true);
        config()->set('report.pdf_dashboard_warmup.enabled', true);
        Queue::fake();

        $guruId = DB::table('gurus')->insertGetId([
            'nuptk' => 'pengajar-only',
            'nama' => 'Pengajar Only',
            'email' => 'pengajar-only@example.test',
            'username' => 'pengajar-only',
            'password' => Hash::make('password'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $guru = Guru::query()->findOrFail($guruId);

        $this->actingAs($guru, 'guru')
            ->withSession([
                'selected_role' => 'pengajar',
                'tahun_ajaran_id' => $this->activeYearId,
                'selected_semester' => 1,
                'no_tahun_ajaran' => false,
            ])
            ->get(route('wali_kelas.dashboard'))
            ->assertStatus(403);

        Queue::assertNotPushed(AutoPreparePdfReportJob::class);
    }

    public function test_report_index_shows_pdf_ready_status_for_cached_pdf(): void
    {
        $this->fakeLibreOfficeAvailability();
        $this->insertReportTemplate($this->currentClassId);
        $this->cachePdfForAuthorizedStudent('cached-status-ready.pdf');

        $this->actingAsWali()
            ->get(route('wali_kelas.rapor.index', [
                'type' => 'UTS',
                'tahun_ajaran_id' => $this->activeYearId,
            ]))
            ->assertOk()
            ->assertSee('PDF siap');
    }

    public function test_report_index_shows_pdf_preparing_status_for_active_warmup(): void
    {
        $this->fakeLibreOfficeAvailability();
        $this->insertReportTemplate($this->currentClassId);

        $siswa = Siswa::findOrFail($this->authorizedStudentId);
        Cache::put(
            PdfCacheService::getAutoPrepareTokenKey($siswa, 'UTS', $this->activeYearId),
            'active-warmup-token',
            now()->addHour()
        );

        $this->actingAsWali()
            ->get(route('wali_kelas.rapor.index', [
                'type' => 'UTS',
                'tahun_ajaran_id' => $this->activeYearId,
            ]))
            ->assertOk()
            ->assertSee('Sedang disiapkan');
    }

    public function test_report_index_shows_pdf_missing_status_without_cache_or_warmup(): void
    {
        $this->fakeLibreOfficeAvailability();
        $this->insertReportTemplate($this->currentClassId);

        $this->actingAsWali()
            ->get(route('wali_kelas.rapor.index', [
                'type' => 'UTS',
                'tahun_ajaran_id' => $this->activeYearId,
            ]))
            ->assertOk()
            ->assertSee('Belum siap');
    }

    public function test_pdf_status_endpoint_returns_ready_for_cached_pdf(): void
    {
        $this->cachePdfForAuthorizedStudent('cached-status-endpoint.pdf');

        $this->actingAsWali()
            ->getJson(route('wali_kelas.rapor.pdf-statuses', [
                'type' => 'UTS',
                'tahun_ajaran_id' => $this->activeYearId,
                'student_ids' => [$this->authorizedStudentId],
            ]))
            ->assertOk()
            ->assertJsonPath("statuses.{$this->authorizedStudentId}", 'ready');
    }

    public function test_pdf_status_endpoint_returns_preparing_for_active_warmup_token(): void
    {
        $siswa = Siswa::findOrFail($this->authorizedStudentId);
        Cache::put(
            PdfCacheService::getAutoPrepareTokenKey($siswa, 'UTS', $this->activeYearId),
            'active-warmup-token',
            now()->addHour()
        );

        $this->actingAsWali()
            ->getJson(route('wali_kelas.rapor.pdf-statuses', [
                'type' => 'UTS',
                'tahun_ajaran_id' => $this->activeYearId,
                'student_ids' => [$this->authorizedStudentId],
            ]))
            ->assertOk()
            ->assertJsonPath("statuses.{$this->authorizedStudentId}", 'preparing');
    }

    public function test_pdf_status_endpoint_returns_missing_without_cache_or_token(): void
    {
        $this->actingAsWali()
            ->getJson(route('wali_kelas.rapor.pdf-statuses', [
                'type' => 'UTS',
                'tahun_ajaran_id' => $this->activeYearId,
                'student_ids' => [$this->authorizedStudentId],
            ]))
            ->assertOk()
            ->assertJsonPath("statuses.{$this->authorizedStudentId}", 'missing');
    }

    public function test_pdf_status_endpoint_rejects_students_outside_wali_class(): void
    {
        $this->actingAsWali()
            ->getJson(route('wali_kelas.rapor.pdf-statuses', [
                'type' => 'UTS',
                'tahun_ajaran_id' => $this->activeYearId,
                'student_ids' => [$this->otherClassStudentId],
            ]))
            ->assertForbidden();
    }

    public function test_pdf_status_endpoint_keeps_unchanged_cached_student_ready_after_targeted_clear(): void
    {
        $secondStudentId = $this->insertStudent('1010', 'Second Authorized Student', $this->currentClassId);
        $this->insertEnrollment($secondStudentId, $this->currentClassId, $this->activeYearId, 1);
        $this->insertReportData($secondStudentId, $this->currentClassId, $this->activeYearId, $this->wali->id);

        Storage::fake('public');
        $this->putPdfCache($this->authorizedStudentId, 'changed-student.pdf');
        $this->putPdfCache($secondStudentId, 'unchanged-student.pdf');

        PdfCacheService::clearStudentCache(
            Siswa::findOrFail($this->authorizedStudentId),
            $this->activeYearId,
            false
        );

        $this->actingAsWali()
            ->getJson(route('wali_kelas.rapor.pdf-statuses', [
                'type' => 'UTS',
                'tahun_ajaran_id' => $this->activeYearId,
                'student_ids' => [$this->authorizedStudentId, $secondStudentId],
            ]))
            ->assertOk()
            ->assertJsonPath("statuses.{$this->authorizedStudentId}", 'missing')
            ->assertJsonPath("statuses.{$secondStudentId}", 'ready');
    }

    public function test_report_index_exposes_pdf_status_polling_endpoint(): void
    {
        $this->fakeLibreOfficeAvailability();
        $this->insertReportTemplate($this->currentClassId);

        $this->actingAsWali()
            ->get(route('wali_kelas.rapor.index', [
                'type' => 'UTS',
                'tahun_ajaran_id' => $this->activeYearId,
            ]))
            ->assertOk()
            ->assertSee('/wali-kelas/rapor/pdf-statuses', false)
            ->assertSee('data-dashboard-warmup-enabled', false);
    }

    public function test_wali_cannot_clear_cache_for_student_from_another_class(): void
    {
        $this->actingAsWali()
            ->deleteJson(route('wali_kelas.rapor.clear-cache', [
                'siswa' => $this->otherClassStudentId,
                'tahun_ajaran_id' => $this->activeYearId,
            ]))
            ->assertForbidden();
    }

    public function test_batch_report_generation_rejects_injected_student_ids_outside_authorized_class(): void
    {
        $this->actingAsWali()
            ->postJson(route('wali_kelas.rapor.batch.generate'), [
                'siswa_ids' => [
                    $this->authorizedStudentId,
                    $this->otherClassStudentId,
                ],
                'type' => 'UTS',
                'tahun_ajaran_id' => $this->activeYearId,
            ])
            ->assertServerError()
            ->assertJsonPath('success', false);
    }

    public function test_wali_can_request_pdf_for_authorized_student(): void
    {
        $this->insertReportTemplate($this->currentClassId);
        Bus::fake([GeneratePdfReportJob::class]);

        $this->actingAsWali()
            ->postJson(route('wali_kelas.rapor.request-pdf', $this->authorizedStudentId), [
                'type' => 'UTS',
                'tahun_ajaran_id' => $this->activeYearId,
            ])
            ->assertAccepted()
            ->assertJsonPath('success', true)
            ->assertJsonPath('status', 'processing');

        Bus::assertDispatched(GeneratePdfReportJob::class);
    }

    public function test_wali_cannot_request_pdf_for_student_from_another_class(): void
    {
        Bus::fake([GeneratePdfReportJob::class]);

        $this->actingAsWali()
            ->postJson(route('wali_kelas.rapor.request-pdf', $this->otherClassStudentId), [
                'type' => 'UTS',
                'tahun_ajaran_id' => $this->activeYearId,
            ])
            ->assertForbidden();

        Bus::assertNotDispatched(GeneratePdfReportJob::class);
    }

    public function test_wali_cannot_request_pdf_for_student_outside_active_academic_year(): void
    {
        Bus::fake([GeneratePdfReportJob::class]);

        $this->actingAsWali()
            ->postJson(route('wali_kelas.rapor.request-pdf', $this->oldYearStudentId), [
                'type' => 'UTS',
                'tahun_ajaran_id' => $this->activeYearId,
            ])
            ->assertForbidden();

        Bus::assertNotDispatched(GeneratePdfReportJob::class);
    }

    public function test_authorized_report_generation_route_reaches_controller_after_authorization(): void
    {
        $this->actingAsWali()
            ->postJson(route('wali_kelas.rapor.generate', $this->authorizedStudentId), [
                'type' => 'UTS',
                'tahun_ajaran_id' => $this->activeYearId,
            ])
            ->assertNotFound()
            ->assertJsonPath('error_type', 'template_missing');
    }

    public function test_docx_cache_hit_returns_secure_url_without_pdf_generation(): void
    {
        $this->insertReportTemplate($this->currentClassId);
        Bus::fake([GeneratePdfReportJob::class]);

        $siswa = Siswa::findOrFail($this->authorizedStudentId);
        Storage::disk('public')->put('docx_reports/cached-report.docx', 'PK cached docx');
        Cache::put(PdfCacheService::getDocxCacheKey($siswa, 'UTS', $this->activeYearId), [
            'path' => 'docx_reports/cached-report.docx',
            'filename' => 'cached-report.docx',
            'file_size' => 14,
            'generated_at' => now()->toISOString(),
        ], now()->addHour());

        $response = $this->actingAsWali()
            ->postJson(route('wali_kelas.rapor.generate', $this->authorizedStudentId), [
                'type' => 'UTS',
                'tahun_ajaran_id' => $this->activeYearId,
                'action' => 'preview',
            ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('filename', 'cached-report.docx')
            ->assertJsonPath('cache_hit', true);
        $this->assertStringContainsString('/wali-kelas/rapor/secure-file', (string) $response->json('file_url'));
        $this->assertStringContainsString('docx_reports%2Fcached-report.docx', (string) $response->json('file_url'));
        Bus::assertNotDispatched(GeneratePdfReportJob::class);
    }

    public function test_admin_report_history_access_is_not_restricted_by_wali_authorization(): void
    {
        $this->actingAs($this->admin, 'web')
            ->withSession(['tahun_ajaran_id' => $this->activeYearId])
            ->get(route('admin.report.history'))
            ->assertOk();
    }

    public function test_admin_report_history_renders_when_related_student_teacher_or_class_is_deleted(): void
    {
        $templateId = $this->insertReportTemplate($this->currentClassId);

        DB::table('report_generations')->insert([
            'siswa_id' => $this->authorizedStudentId,
            'kelas_id' => $this->currentClassId,
            'report_template_id' => $templateId,
            'generated_file' => 'generated/missing-history.docx',
            'type' => 'UTS',
            'tahun_ajaran_id' => $this->activeYearId,
            'generated_by' => $this->wali->id,
            'generated_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('siswas')
            ->where('id', $this->authorizedStudentId)
            ->update(['deleted_at' => now()]);
        DB::table('gurus')
            ->where('id', $this->wali->id)
            ->update(['deleted_at' => now()]);
        DB::table('kelas')
            ->where('id', $this->currentClassId)
            ->update(['deleted_at' => now()]);

        $this->actingAs($this->admin, 'web')
            ->withSession(['tahun_ajaran_id' => $this->activeYearId])
            ->get(route('admin.report.history'))
            ->assertOk()
            ->assertSee('Authorized Student')
            ->assertSee('Wali Kelas');
    }

    private function actingAsWali(): self
    {
        return $this->actingAsWaliWithSession([
            'selected_role' => 'wali_kelas',
            'tahun_ajaran_id' => $this->activeYearId,
            'selected_semester' => 1,
            'no_tahun_ajaran' => false,
        ]);
    }

    private function actingAsWaliWithSession(array $session): self
    {
        return $this->actingAs($this->wali, 'guru')
            ->withSession($session);
    }

    private function createSchema(): void
    {
        foreach ([
            'report_generations',
            'report_template_kelas',
            'report_templates',
            'notifications',
            'capaian_custom',
            'nilai_ekstrakurikuler',
            'ekstrakurikulers',
            'absensis',
            'nilais',
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
            $table->string('email')->unique();
            $table->string('password');
            $table->timestamps();
        });

        Schema::create('gurus', function (Blueprint $table) {
            $table->id();
            $table->string('nuptk')->nullable();
            $table->string('nama');
            $table->string('jenis_kelamin')->nullable();
            $table->date('tanggal_lahir')->nullable();
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
            $table->integer('semester')->default(1);
            $table->date('tanggal_mulai')->nullable();
            $table->date('tanggal_selesai')->nullable();
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
            $table->string('tahun_ajaran')->nullable();
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

        Schema::create('siswa_kelas_semester', function (Blueprint $table) {
            $table->id();
            $table->foreignId('siswa_id');
            $table->foreignId('kelas_id');
            $table->foreignId('tahun_ajaran_id');
            $table->tinyInteger('semester');
            $table->timestamps();
            $table->unique(['siswa_id', 'tahun_ajaran_id', 'semester']);
        });

        Schema::create('mata_pelajarans', function (Blueprint $table) {
            $table->id();
            $table->string('nama_pelajaran');
            $table->foreignId('kelas_id')->nullable();
            $table->foreignId('guru_id')->nullable();
            $table->integer('semester')->default(1);
            $table->foreignId('tahun_ajaran_id')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('nilais', function (Blueprint $table) {
            $table->id();
            $table->foreignId('siswa_id');
            $table->foreignId('mata_pelajaran_id')->nullable();
            $table->json('nilai_tp')->nullable();
            $table->decimal('nilai_akhir_rapor', 5, 2)->nullable();
            $table->boolean('is_submitted')->default(false);
            $table->foreignId('tahun_ajaran_id')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('absensis', function (Blueprint $table) {
            $table->id();
            $table->foreignId('siswa_id');
            $table->integer('semester')->default(1);
            $table->foreignId('tahun_ajaran_id')->nullable();
            $table->integer('sakit')->default(0);
            $table->integer('izin')->default(0);
            $table->integer('tanpa_keterangan')->default(0);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('ekstrakurikulers', function (Blueprint $table) {
            $table->id();
            $table->string('nama_ekstrakurikuler');
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
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('capaian_custom', function (Blueprint $table) {
            $table->id();
            $table->foreignId('siswa_id');
            $table->foreignId('mata_pelajaran_id');
            $table->text('custom_capaian')->nullable();
            $table->text('custom_capaian_tertinggi')->nullable();
            $table->text('custom_capaian_terendah')->nullable();
            $table->foreignId('tahun_ajaran_id');
            $table->tinyInteger('semester');
            $table->timestamps();
        });

        Schema::create('report_templates', function (Blueprint $table) {
            $table->id();
            $table->string('filename')->nullable();
            $table->string('path')->nullable();
            $table->string('type');
            $table->boolean('is_active')->default(false);
            $table->foreignId('kelas_id')->nullable();
            $table->foreignId('tahun_ajaran_id')->nullable();
            $table->integer('semester')->nullable();
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
            $table->foreignId('siswa_id')->nullable();
            $table->foreignId('kelas_id')->nullable();
            $table->foreignId('report_template_id')->nullable();
            $table->string('generated_file')->nullable();
            $table->string('type')->nullable();
            $table->integer('semester')->nullable();
            $table->foreignId('tahun_ajaran_id')->nullable();
            $table->foreignId('generated_by')->nullable();
            $table->timestamp('generated_at')->nullable();
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

    private function seedAuthorizationFixture(): void
    {
        $this->activeYearId = DB::table('tahun_ajarans')->insertGetId([
            'tahun_ajaran' => '2025/2026',
            'is_active' => true,
            'semester' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->oldYearId = DB::table('tahun_ajarans')->insertGetId([
            'tahun_ajaran' => '2024/2025',
            'is_active' => false,
            'semester' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('profil_sekolah')->insert([
            'nama_sekolah' => 'SDIT Al Hidayah',
            'tahun_pelajaran' => '2025/2026',
            'semester' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $waliId = DB::table('gurus')->insertGetId([
            'nuptk' => 'wali-1',
            'nama' => 'Wali Kelas',
            'email' => 'wali@example.test',
            'username' => 'wali',
            'password' => Hash::make('password'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $adminId = DB::table('users')->insertGetId([
            'name' => 'Admin',
            'email' => 'admin@example.test',
            'password' => Hash::make('password'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->admin = User::query()->findOrFail($adminId);

        $this->currentClassId = $this->insertClass(1, 'A', $this->activeYearId, '2025/2026');
        $this->otherClassId = $this->insertClass(1, 'B', $this->activeYearId, '2025/2026');
        $this->oldClassId = $this->insertClass(1, 'A', $this->oldYearId, '2024/2025');

        $this->attachWali($waliId, $this->currentClassId);
        $this->attachWali($waliId, $this->oldClassId);

        $this->authorizedStudentId = $this->insertStudent('1001', 'Authorized Student', $this->currentClassId);
        $this->otherClassStudentId = $this->insertStudent('1002', 'Other Class Student', $this->otherClassId);
        $this->oldYearStudentId = $this->insertStudent('1003', 'Old Year Student', $this->oldClassId);

        $this->insertEnrollment($this->authorizedStudentId, $this->currentClassId, $this->activeYearId, 1);
        $this->insertEnrollment($this->otherClassStudentId, $this->otherClassId, $this->activeYearId, 1);
        $this->insertEnrollment($this->oldYearStudentId, $this->oldClassId, $this->oldYearId, 1);

        $this->insertReportData($this->authorizedStudentId, $this->currentClassId, $this->activeYearId, $waliId);

        $this->wali = Guru::query()->findOrFail($waliId);
    }

    private function insertClass(int $number, string $name, int $yearId, string $yearText): int
    {
        return DB::table('kelas')->insertGetId([
            'nomor_kelas' => $number,
            'nama_kelas' => $name,
            'tahun_ajaran' => $yearText,
            'tahun_ajaran_id' => $yearId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function attachWali(int $guruId, int $kelasId): void
    {
        DB::table('guru_kelas')->insert([
            'guru_id' => $guruId,
            'kelas_id' => $kelasId,
            'is_wali_kelas' => true,
            'role' => 'wali_kelas',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function insertStudent(string $nis, string $name, int $kelasId): int
    {
        return DB::table('siswas')->insertGetId([
            'nis' => $nis,
            'nisn' => $nis.'000',
            'nama' => $name,
            'kelas_id' => $kelasId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function insertEnrollment(int $studentId, int $kelasId, int $yearId, int $semester): void
    {
        DB::table('siswa_kelas_semester')->insert([
            'siswa_id' => $studentId,
            'kelas_id' => $kelasId,
            'tahun_ajaran_id' => $yearId,
            'semester' => $semester,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function insertReportData(int $studentId, int $classId, int $yearId, int $guruId): void
    {
        $subjectId = DB::table('mata_pelajarans')->insertGetId([
            'nama_pelajaran' => 'Matematika',
            'kelas_id' => $classId,
            'guru_id' => $guruId,
            'semester' => 1,
            'tahun_ajaran_id' => $yearId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('nilais')->insert([
            'siswa_id' => $studentId,
            'mata_pelajaran_id' => $subjectId,
            'nilai_akhir_rapor' => 88,
            'is_submitted' => true,
            'tahun_ajaran_id' => $yearId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('absensis')->insert([
            'siswa_id' => $studentId,
            'semester' => 1,
            'tahun_ajaran_id' => $yearId,
            'sakit' => 0,
            'izin' => 0,
            'tanpa_keterangan' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function insertReportTemplate(int $classId, string $type = 'UTS', ?int $yearId = null, ?int $semester = 1): int
    {
        return DB::table('report_templates')->insertGetId([
            'filename' => 'demo-template.docx',
            'path' => 'templates/demo-template.docx',
            'type' => $type,
            'is_active' => true,
            'kelas_id' => $classId,
            'tahun_ajaran_id' => $yearId ?: $this->activeYearId,
            'semester' => $semester,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function cachePdfForAuthorizedStudent(string $filename): void
    {
        Storage::fake('public');
        $this->putPdfCache($this->authorizedStudentId, $filename);
    }

    private function putPdfCache(int $studentId, string $filename): void
    {
        Storage::disk('public')->put("pdf_reports/{$filename}", 'PDF');

        Cache::put(PdfCacheService::getCacheKey(Siswa::findOrFail($studentId), 'UTS', $this->activeYearId), [
            'path' => "pdf_reports/{$filename}",
            'filename' => $filename,
            'file_size' => 3,
            'generated_at' => now()->toISOString(),
        ], now()->addHour());
    }

    private function fakeLibreOfficeAvailability(bool $available = true): void
    {
        $this->mock(DocumentConversionService::class, function ($mock) use ($available) {
            $mock->shouldReceive('isLibreOfficeAvailable')->andReturn($available);
        });
    }
}

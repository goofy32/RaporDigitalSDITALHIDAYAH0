<?php

namespace Tests\Feature;

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
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
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
            ->assertOk()
            ->assertJsonPath('success', true);

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

    public function test_admin_report_history_access_is_not_restricted_by_wali_authorization(): void
    {
        $this->actingAs($this->admin, 'web')
            ->withSession(['tahun_ajaran_id' => $this->activeYearId])
            ->get(route('admin.report.history'))
            ->assertOk();
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

    private function fakeLibreOfficeAvailability(bool $available = true): void
    {
        $this->mock(DocumentConversionService::class, function ($mock) use ($available) {
            $mock->shouldReceive('isLibreOfficeAvailable')->andReturn($available);
        });
    }
}

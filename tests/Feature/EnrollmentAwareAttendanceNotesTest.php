<?php

namespace Tests\Feature;

use App\Jobs\AutoPreparePdfReportJob;
use App\Models\Guru;
use App\Models\Siswa;
use App\Services\PdfCacheService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class EnrollmentAwareAttendanceNotesTest extends TestCase
{
    private Guru $wali;

    private int $ganjilYearId;

    private int $genapYearId;

    private int $oldYearId;

    private int $waliGanjilClassId;

    private int $waliGenapClassId;

    private int $otherClassId;

    private int $oldClassId;

    private int $ganjilSubjectId;

    private int $genapSubjectId;

    private int $ahmadId;

    private int $sitiId;

    private int $legacyId;

    private int $genapOnlyId;

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
        $this->seedFixture();
    }

    public function test_wali_sees_attendance_roster_for_enrolled_ganjil_students(): void
    {
        $response = $this->actingAsWali($this->ganjilYearId, 1)
            ->get(route('wali_kelas.absence.index'));

        $response->assertOk()
            ->assertViewHas('absensiData', function ($rows) {
                $names = collect($rows)->pluck('nama');

                return $names->contains('Ahmad Fauzan')
                    && $names->contains('Siti Aisyah')
                    && $names->contains('Legacy Matching Student')
                    && ! $names->contains('Genap Only Student')
                    && ! $names->contains('Other Class Student');
            });
    }

    public function test_ganjil_only_student_is_excluded_from_genap_attendance(): void
    {
        $response = $this->actingAsWali($this->genapYearId, 2)
            ->get(route('wali_kelas.absence.index'));

        $response->assertOk()
            ->assertViewHas('absensiData', function ($rows) {
                $names = collect($rows)->pluck('nama');

                return $names->contains('Genap Only Student')
                    && ! $names->contains('Ahmad Fauzan')
                    && ! $names->contains('Siti Aisyah')
                    && ! $names->contains('Legacy Matching Student');
            });
    }

    public function test_genap_only_student_is_excluded_from_ganjil_attendance(): void
    {
        $response = $this->actingAsWali($this->ganjilYearId, 1)
            ->get(route('wali_kelas.absence.index'));

        $response->assertOk()
            ->assertViewHas('absensiData', function ($rows) {
                return ! collect($rows)->pluck('nama')->contains('Genap Only Student');
            });
    }

    public function test_wali_can_save_attendance_for_enrolled_student(): void
    {
        $this->actingAsWali($this->ganjilYearId, 1)
            ->postJson(route('wali_kelas.absence.bulk-save'), [
                'rows' => [$this->attendanceRow($this->ahmadId, 1, 0, 0)],
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertDatabaseHas('absensis', [
            'siswa_id' => $this->ahmadId,
            'tahun_ajaran_id' => $this->ganjilYearId,
            'semester' => 1,
            'sakit' => 1,
        ]);
    }

    public function test_attendance_update_invalidates_only_changed_student_pdf_cache(): void
    {
        $ahmadCacheKey = $this->putPdfCache($this->ahmadId, 'ahmad-attendance.pdf');
        $sitiCacheKey = $this->putPdfCache($this->sitiId, 'siti-attendance.pdf');
        $ahmadDocxCacheKey = $this->putDocxCache($this->ahmadId, 'ahmad-attendance.docx');
        $sitiDocxCacheKey = $this->putDocxCache($this->sitiId, 'siti-attendance.docx');

        $this->actingAsWali($this->ganjilYearId, 1)
            ->postJson(route('wali_kelas.absence.bulk-save'), [
                'rows' => [
                    $this->attendanceRow($this->ahmadId, 1, 0, 0),
                    $this->attendanceRow($this->sitiId, 0, 0, 0),
                ],
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertFalse(Cache::has($ahmadCacheKey));
        $this->assertTrue(Cache::has($sitiCacheKey));
        $this->assertFalse(Cache::has($ahmadDocxCacheKey));
        $this->assertTrue(Cache::has($sitiDocxCacheKey));
    }

    public function test_unchanged_attendance_batch_does_not_enqueue_pdf_warmup(): void
    {
        config()->set('report.pdf_auto_prepare.enabled', true);
        config()->set('report.pdf_auto_prepare.queue', 'pdf-warm');
        Queue::fake();

        $cacheKey = $this->putPdfCache($this->ahmadId, 'ahmad-unchanged-attendance.pdf');

        $this->actingAsWali($this->ganjilYearId, 1)
            ->postJson(route('wali_kelas.absence.bulk-save'), [
                'rows' => [
                    $this->attendanceRow($this->ahmadId, 0, 0, 0),
                ],
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertTrue(Cache::has($cacheKey));
        Queue::assertNotPushed(AutoPreparePdfReportJob::class);
    }

    public function test_attendance_change_uses_late_stage_pdf_warmup_delay(): void
    {
        config()->set('report.pdf_auto_prepare.enabled', true);
        config()->set('report.pdf_auto_prepare.delay_seconds', 60);
        config()->set('report.pdf_auto_prepare.late_stage_delay_seconds', 10);
        config()->set('report.pdf_auto_prepare.queue', 'pdf-warm');
        Queue::fake();

        $this->actingAsWali($this->ganjilYearId, 1)
            ->postJson(route('wali_kelas.absence.bulk-save'), [
                'rows' => [
                    $this->attendanceRow($this->ahmadId, 2, 0, 0),
                ],
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        Queue::assertPushedOn('pdf-warm', AutoPreparePdfReportJob::class);
        Queue::assertPushed(AutoPreparePdfReportJob::class, function (AutoPreparePdfReportJob $job) {
            return $job->siswaId === $this->ahmadId
                && $job->type === 'UTS'
                && $job->delay
                && abs($job->delay->getTimestamp() - now()->addSeconds(10)->getTimestamp()) <= 2;
        });
        Queue::assertNotPushed(AutoPreparePdfReportJob::class, fn (AutoPreparePdfReportJob $job) => $job->siswaId === $this->sitiId);
        Queue::assertNotPushed(AutoPreparePdfReportJob::class, fn (AutoPreparePdfReportJob $job) => $job->type === 'UAS');
    }

    public function test_attendance_student_from_another_class_is_rejected(): void
    {
        $this->assertUnauthorizedAttendanceDoesNotCreateRows($this->otherClassStudentId);
    }

    public function test_attendance_student_from_another_semester_is_rejected(): void
    {
        $this->assertUnauthorizedAttendanceDoesNotCreateRows($this->genapOnlyId);
    }

    public function test_attendance_student_from_another_academic_year_is_rejected(): void
    {
        $this->assertUnauthorizedAttendanceDoesNotCreateRows($this->oldYearStudentId);
    }

    public function test_unrelated_legacy_class_does_not_grant_attendance_access_when_enrollment_differs(): void
    {
        $studentId = $this->insertStudent('2001', 'Legacy Attendance Mismatch Student', $this->waliGanjilClassId);
        $this->insertEnrollment($studentId, $this->otherClassId, $this->ganjilYearId, 1);

        $this->assertUnauthorizedAttendanceDoesNotCreateRows($studentId);
    }

    public function test_matching_legacy_fallback_allows_attendance_when_student_has_no_enrollment(): void
    {
        $this->actingAsWali($this->ganjilYearId, 1)
            ->postJson(route('wali_kelas.absence.bulk-save'), [
                'rows' => [$this->attendanceRow($this->legacyId, 0, 1, 0)],
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertDatabaseHas('absensis', [
            'siswa_id' => $this->legacyId,
            'tahun_ajaran_id' => $this->ganjilYearId,
            'semester' => 1,
            'izin' => 1,
        ]);
    }

    public function test_unauthorized_attendance_request_changes_no_rows(): void
    {
        $this->actingAsWali($this->ganjilYearId, 1)
            ->postJson(route('wali_kelas.absence.bulk-save'), [
                'rows' => [
                    $this->attendanceRow($this->ahmadId, 1, 0, 0),
                    $this->attendanceRow($this->otherClassStudentId, 1, 0, 0),
                ],
            ])
            ->assertForbidden();

        $this->assertSame(0, DB::table('absensis')->count());
    }

    public function test_ganjil_and_genap_attendance_remain_separate(): void
    {
        $studentId = $this->insertStudent('2002', 'Dual Attendance Student', $this->waliGanjilClassId);
        $this->insertEnrollment($studentId, $this->waliGanjilClassId, $this->ganjilYearId, 1);
        $this->insertEnrollment($studentId, $this->waliGenapClassId, $this->genapYearId, 2);

        $this->actingAsWali($this->ganjilYearId, 1)
            ->postJson(route('wali_kelas.absence.bulk-save'), [
                'rows' => [$this->attendanceRow($studentId, 2, 0, 0)],
            ])
            ->assertOk();

        $this->actingAsWali($this->genapYearId, 2)
            ->postJson(route('wali_kelas.absence.bulk-save'), [
                'rows' => [$this->attendanceRow($studentId, 0, 0, 3)],
            ])
            ->assertOk();

        $this->assertDatabaseHas('absensis', [
            'siswa_id' => $studentId,
            'tahun_ajaran_id' => $this->ganjilYearId,
            'semester' => 1,
            'sakit' => 2,
            'tanpa_keterangan' => 0,
        ]);
        $this->assertDatabaseHas('absensis', [
            'siswa_id' => $studentId,
            'tahun_ajaran_id' => $this->genapYearId,
            'semester' => 2,
            'sakit' => 0,
            'tanpa_keterangan' => 3,
        ]);
    }

    public function test_wali_sees_note_roster_for_enrolled_students_only(): void
    {
        $response = $this->actingAsWali($this->ganjilYearId, 1)
            ->get(route('wali_kelas.catatan.mata_pelajaran.show', $this->ganjilSubjectId));

        $response->assertOk()
            ->assertViewHas('siswaList', function ($students) {
                $names = $students->pluck('nama');

                return $names->contains('Ahmad Fauzan')
                    && $names->contains('Siti Aisyah')
                    && $names->contains('Legacy Matching Student')
                    && ! $names->contains('Genap Only Student')
                    && ! $names->contains('Other Class Student');
            });
    }

    public function test_wali_student_note_page_renders_header_save_action(): void
    {
        $response = $this->actingAsWali($this->ganjilYearId, 1)
            ->get(route('wali_kelas.catatan.siswa.show', $this->ahmadId));

        $response->assertOk()
            ->assertSeeText('Catatan Siswa')
            ->assertSeeText('Simpan Catatan')
            ->assertSeeText('Maksimal 1000 karakter');

        $content = $response->getContent();

        $this->assertStringContainsString('id="catatanSiswaForm"', $content);
        $this->assertStringContainsString('form="catatanSiswaForm"', $content);
        $this->assertSame(1, substr_count($content, 'Simpan Catatan'));
    }

    public function test_wali_can_create_and_update_student_notes_for_enrolled_student(): void
    {
        $this->actingAsWali($this->ganjilYearId, 1)
            ->post(route('wali_kelas.catatan.siswa.store', $this->ahmadId), [
                'catatan_umum' => 'Catatan awal',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('catatan_siswa', [
            'siswa_id' => $this->ahmadId,
            'tahun_ajaran_id' => $this->ganjilYearId,
            'semester' => 1,
            'type' => 'umum',
            'catatan' => 'Catatan awal',
        ]);

        $this->actingAsWali($this->ganjilYearId, 1)
            ->post(route('wali_kelas.catatan.siswa.store', $this->ahmadId), [
                'catatan_umum' => 'Catatan diperbarui',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('catatan_siswa', [
            'siswa_id' => $this->ahmadId,
            'tahun_ajaran_id' => $this->ganjilYearId,
            'semester' => 1,
            'type' => 'umum',
            'catatan' => 'Catatan diperbarui',
        ]);
    }

    public function test_student_note_update_invalidates_only_changed_student_pdf_cache(): void
    {
        $ahmadCacheKey = $this->putPdfCache($this->ahmadId, 'ahmad-student-note.pdf');
        $sitiCacheKey = $this->putPdfCache($this->sitiId, 'siti-student-note.pdf');

        $this->actingAsWali($this->ganjilYearId, 1)
            ->post(route('wali_kelas.catatan.siswa.store', $this->ahmadId), [
                'catatan_umum' => 'Catatan wali berubah.',
            ])
            ->assertRedirect();

        $this->assertFalse(Cache::has($ahmadCacheKey));
        $this->assertTrue(Cache::has($sitiCacheKey));
    }

    public function test_student_note_change_warms_opened_report_period_only(): void
    {
        config()->set('report.pdf_auto_prepare.enabled', true);
        config()->set('report.pdf_auto_prepare.late_stage_delay_seconds', 10);
        config()->set('report.pdf_auto_prepare.queue', 'pdf-warm');
        Queue::fake();

        $this->actingAsWali($this->ganjilYearId, 1)
            ->post(route('wali_kelas.catatan.siswa.store', $this->ahmadId), [
                'catatan_umum' => 'Catatan untuk periode dibuka.',
            ])
            ->assertRedirect();

        Queue::assertPushedOn('pdf-warm', AutoPreparePdfReportJob::class);
        Queue::assertPushed(AutoPreparePdfReportJob::class, function (AutoPreparePdfReportJob $job) {
            return $job->siswaId === $this->ahmadId
                && $job->type === 'UTS'
                && $job->reason === 'pdf_cache_invalidated';
        });
        Queue::assertNotPushed(AutoPreparePdfReportJob::class, fn (AutoPreparePdfReportJob $job) => $job->type === 'UAS');
    }

    public function test_note_student_from_another_class_is_rejected(): void
    {
        $this->assertUnauthorizedStudentNoteDoesNotCreateRows($this->otherClassStudentId);
    }

    public function test_note_student_from_another_semester_is_rejected(): void
    {
        $this->assertUnauthorizedStudentNoteDoesNotCreateRows($this->genapOnlyId);
    }

    public function test_note_student_from_another_academic_year_is_rejected(): void
    {
        $this->assertUnauthorizedStudentNoteDoesNotCreateRows($this->oldYearStudentId);
    }

    public function test_unauthorized_subject_note_request_changes_no_rows(): void
    {
        $this->actingAsWali($this->ganjilYearId, 1)
            ->postJson(route('wali_kelas.catatan.mata_pelajaran.store', $this->ganjilSubjectId), [
                'catatan' => [
                    $this->ahmadId => ['umum' => 'Boleh disimpan'],
                    $this->otherClassStudentId => ['umum' => 'Tidak boleh'],
                ],
            ])
            ->assertForbidden();

        $this->assertSame(0, DB::table('catatan_mata_pelajaran')->count());
    }

    public function test_ganjil_and_genap_student_notes_remain_separate(): void
    {
        $studentId = $this->insertStudent('2003', 'Dual Notes Student', $this->waliGanjilClassId);
        $this->insertEnrollment($studentId, $this->waliGanjilClassId, $this->ganjilYearId, 1);
        $this->insertEnrollment($studentId, $this->waliGenapClassId, $this->genapYearId, 2);

        $this->actingAsWali($this->ganjilYearId, 1)
            ->post(route('wali_kelas.catatan.siswa.store', $studentId), [
                'catatan_umum' => 'Catatan ganjil',
            ])
            ->assertRedirect();

        $this->actingAsWali($this->genapYearId, 2)
            ->post(route('wali_kelas.catatan.siswa.store', $studentId), [
                'catatan_umum' => 'Catatan genap',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('catatan_siswa', [
            'siswa_id' => $studentId,
            'tahun_ajaran_id' => $this->ganjilYearId,
            'semester' => 1,
            'catatan' => 'Catatan ganjil',
        ]);
        $this->assertDatabaseHas('catatan_siswa', [
            'siswa_id' => $studentId,
            'tahun_ajaran_id' => $this->genapYearId,
            'semester' => 2,
            'catatan' => 'Catatan genap',
        ]);
    }

    public function test_existing_subject_note_create_update_and_empty_delete_behavior_remains(): void
    {
        $this->actingAsWali($this->ganjilYearId, 1)
            ->post(route('wali_kelas.catatan.mata_pelajaran.store', $this->ganjilSubjectId), [
                'catatan' => [
                    $this->ahmadId => ['umum' => 'Perlu latihan rutin'],
                ],
            ])
            ->assertRedirect(route('wali_kelas.catatan.mata_pelajaran.index'));

        $this->assertDatabaseHas('catatan_mata_pelajaran', [
            'siswa_id' => $this->ahmadId,
            'mata_pelajaran_id' => $this->ganjilSubjectId,
            'tahun_ajaran_id' => $this->ganjilYearId,
            'semester' => 1,
            'type' => 'umum',
            'catatan' => 'Perlu latihan rutin',
        ]);

        $this->actingAsWali($this->ganjilYearId, 1)
            ->post(route('wali_kelas.catatan.mata_pelajaran.store', $this->ganjilSubjectId), [
                'catatan' => [
                    $this->ahmadId => ['umum' => ''],
                ],
            ])
            ->assertRedirect(route('wali_kelas.catatan.mata_pelajaran.index'));

        $this->assertDatabaseMissing('catatan_mata_pelajaran', [
            'siswa_id' => $this->ahmadId,
            'mata_pelajaran_id' => $this->ganjilSubjectId,
            'tahun_ajaran_id' => $this->ganjilYearId,
            'semester' => 1,
            'type' => 'umum',
        ]);
    }

    public function test_subject_note_update_invalidates_only_changed_student_pdf_cache(): void
    {
        $ahmadCacheKey = $this->putPdfCache($this->ahmadId, 'ahmad-subject-note.pdf');
        $sitiCacheKey = $this->putPdfCache($this->sitiId, 'siti-subject-note.pdf');

        $this->actingAsWali($this->ganjilYearId, 1)
            ->post(route('wali_kelas.catatan.mata_pelajaran.store', $this->ganjilSubjectId), [
                'catatan' => [
                    $this->ahmadId => ['umum' => 'Catatan mapel berubah.'],
                    $this->sitiId => ['umum' => ''],
                ],
            ])
            ->assertRedirect(route('wali_kelas.catatan.mata_pelajaran.index'));

        $this->assertFalse(Cache::has($ahmadCacheKey));
        $this->assertTrue(Cache::has($sitiCacheKey));
    }

    private function actingAsWali(int $tahunAjaranId, int $semester): self
    {
        return $this->actingAs($this->wali, 'guru')
            ->withSession([
                'selected_role' => 'wali_kelas',
                'tahun_ajaran_id' => $tahunAjaranId,
                'selected_semester' => $semester,
                'no_tahun_ajaran' => false,
            ]);
    }

    private function assertUnauthorizedAttendanceDoesNotCreateRows(int $studentId): void
    {
        $this->actingAsWali($this->ganjilYearId, 1)
            ->postJson(route('wali_kelas.absence.bulk-save'), [
                'rows' => [$this->attendanceRow($studentId, 1, 1, 1)],
            ])
            ->assertForbidden();

        $this->assertDatabaseMissing('absensis', [
            'siswa_id' => $studentId,
            'tahun_ajaran_id' => $this->ganjilYearId,
            'semester' => 1,
        ]);
    }

    private function assertUnauthorizedStudentNoteDoesNotCreateRows(int $studentId): void
    {
        $this->actingAsWali($this->ganjilYearId, 1)
            ->postJson(route('wali_kelas.catatan.siswa.store', $studentId), [
                'catatan_umum' => 'Tidak boleh tersimpan',
            ])
            ->assertForbidden();

        $this->assertDatabaseMissing('catatan_siswa', [
            'siswa_id' => $studentId,
            'tahun_ajaran_id' => $this->ganjilYearId,
            'semester' => 1,
        ]);
    }

    private function attendanceRow(int $studentId, int $sakit, int $izin, int $tanpaKeterangan): array
    {
        return [
            'siswa_id' => $studentId,
            'sakit' => $sakit,
            'izin' => $izin,
            'tanpa_keterangan' => $tanpaKeterangan,
        ];
    }

    private function putPdfCache(int $studentId, string $filename): string
    {
        $siswa = Siswa::findOrFail($studentId);
        $cacheKey = PdfCacheService::getCacheKey($siswa, 'UTS', $this->ganjilYearId);

        Cache::put($cacheKey, [
            'path' => "pdf_reports/{$filename}",
            'filename' => $filename,
            'file_size' => 3,
            'generated_at' => now()->toISOString(),
        ], now()->addHour());

        return $cacheKey;
    }

    private function putDocxCache(int $studentId, string $filename): string
    {
        $siswa = Siswa::findOrFail($studentId);
        $cacheKey = PdfCacheService::getDocxCacheKey($siswa, 'UTS', $this->ganjilYearId);

        Cache::put($cacheKey, [
            'path' => "docx_reports/{$filename}",
            'filename' => $filename,
            'file_size' => 3,
            'generated_at' => now()->toISOString(),
        ], now()->addHour());

        return $cacheKey;
    }

    private function createSchema(): void
    {
        foreach ([
            'audit_logs',
            'catatan_mata_pelajaran',
            'catatan_siswa',
            'absensis',
            'mata_pelajarans',
            'siswa_kelas_semester',
            'siswas',
            'guru_kelas',
            'kelas',
            'profil_sekolah',
            'tahun_ajarans',
            'gurus',
        ] as $table) {
            Schema::dropIfExists($table);
        }

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
            $table->foreignId('tahun_ajaran_id')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('guru_kelas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('guru_id');
            $table->foreignId('kelas_id');
            $table->boolean('is_wali_kelas')->default(false);
            $table->string('role')->default('wali_kelas');
            $table->timestamps();
        });

        Schema::create('siswas', function (Blueprint $table) {
            $table->id();
            $table->string('nis')->nullable();
            $table->string('nisn')->nullable();
            $table->string('nama');
            $table->string('jenis_kelamin')->nullable();
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
            $table->boolean('is_muatan_lokal')->default(false);
            $table->boolean('allow_non_wali')->default(false);
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

        Schema::create('catatan_siswa', function (Blueprint $table) {
            $table->id();
            $table->foreignId('siswa_id');
            $table->text('catatan');
            $table->foreignId('tahun_ajaran_id')->nullable();
            $table->integer('semester')->default(1);
            $table->string('type')->default('umum');
            $table->foreignId('created_by')->nullable();
            $table->timestamps();
        });

        Schema::create('catatan_mata_pelajaran', function (Blueprint $table) {
            $table->id();
            $table->foreignId('mata_pelajaran_id');
            $table->foreignId('siswa_id');
            $table->foreignId('tahun_ajaran_id')->nullable();
            $table->integer('semester')->default(1);
            $table->string('type')->default('umum');
            $table->text('catatan');
            $table->foreignId('created_by')->nullable();
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
        $this->ganjilYearId = $this->insertYear('2026/2027', 1, true);
        $this->genapYearId = $this->insertYear('2026/2027', 2, false);
        $this->oldYearId = $this->insertYear('2025/2026', 1, false);

        DB::table('profil_sekolah')->insert([
            'nama_sekolah' => 'SDIT Al Hidayah',
            'tahun_pelajaran' => '2026/2027',
            'semester' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->waliGanjilClassId = $this->insertClass(5, 'A', $this->ganjilYearId);
        $this->waliGenapClassId = $this->insertClass(5, 'A', $this->genapYearId);
        $this->otherClassId = $this->insertClass(5, 'B', $this->ganjilYearId);
        $this->oldClassId = $this->insertClass(5, 'A', $this->oldYearId);

        $waliId = $this->insertGuru('Guru Wali', 'wali');
        $this->attachWali($waliId, $this->waliGanjilClassId);
        $this->attachWali($waliId, $this->waliGenapClassId);
        $this->attachWali($waliId, $this->oldClassId);

        $this->ahmadId = $this->insertStudent('1001', 'Ahmad Fauzan', $this->waliGanjilClassId);
        $this->sitiId = $this->insertStudent('1002', 'Siti Aisyah', $this->waliGanjilClassId);
        $this->legacyId = $this->insertStudent('1003', 'Legacy Matching Student', $this->waliGanjilClassId);
        $this->genapOnlyId = $this->insertStudent('1004', 'Genap Only Student', $this->waliGanjilClassId);
        $this->otherClassStudentId = $this->insertStudent('1005', 'Other Class Student', $this->otherClassId);
        $this->oldYearStudentId = $this->insertStudent('1006', 'Old Year Student', $this->waliGanjilClassId);

        $this->insertEnrollment($this->ahmadId, $this->waliGanjilClassId, $this->ganjilYearId, 1);
        $this->insertEnrollment($this->sitiId, $this->waliGanjilClassId, $this->ganjilYearId, 1);
        $this->insertEnrollment($this->genapOnlyId, $this->waliGenapClassId, $this->genapYearId, 2);
        $this->insertEnrollment($this->otherClassStudentId, $this->otherClassId, $this->ganjilYearId, 1);
        $this->insertEnrollment($this->oldYearStudentId, $this->oldClassId, $this->oldYearId, 1);

        $this->ganjilSubjectId = $this->insertSubject('Matematika Ganjil', $waliId, $this->waliGanjilClassId, $this->ganjilYearId, 1);
        $this->genapSubjectId = $this->insertSubject('Matematika Genap', $waliId, $this->waliGenapClassId, $this->genapYearId, 2);

        $this->wali = Guru::findOrFail($waliId);
    }

    private function insertYear(string $year, int $semester, bool $active): int
    {
        return DB::table('tahun_ajarans')->insertGetId([
            'tahun_ajaran' => $year,
            'is_active' => $active,
            'semester' => $semester,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function insertClass(int $number, string $name, int $yearId): int
    {
        return DB::table('kelas')->insertGetId([
            'nomor_kelas' => $number,
            'nama_kelas' => $name,
            'tahun_ajaran_id' => $yearId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function insertGuru(string $name, string $username): int
    {
        return DB::table('gurus')->insertGetId([
            'nama' => $name,
            'email' => "{$username}@example.test",
            'username' => $username,
            'password' => Hash::make('password'),
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
            'nisn' => "{$nis}000",
            'nama' => $name,
            'jenis_kelamin' => 'L',
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

    private function insertSubject(string $name, int $guruId, int $kelasId, int $yearId, int $semester): int
    {
        return DB::table('mata_pelajarans')->insertGetId([
            'nama_pelajaran' => $name,
            'kelas_id' => $kelasId,
            'guru_id' => $guruId,
            'semester' => $semester,
            'tahun_ajaran_id' => $yearId,
            'is_muatan_lokal' => false,
            'allow_non_wali' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}

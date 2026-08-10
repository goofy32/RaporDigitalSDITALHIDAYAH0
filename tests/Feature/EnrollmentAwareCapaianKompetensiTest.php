<?php

namespace Tests\Feature;

use App\Http\Controllers\CapaianKompetensiController;
use App\Models\CapaianKompetensiCustom;
use App\Models\Guru;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class EnrollmentAwareCapaianKompetensiTest extends TestCase
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

    private int $dualStudentId;

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

    public function test_wali_capaian_roster_lists_students_enrolled_in_requested_ganjil_context(): void
    {
        $response = $this->actingAsWali($this->ganjilYearId, 1)
            ->get(route('wali_kelas.capaian_kompetensi.edit', $this->ganjilSubjectId));

        $response->assertOk()
            ->assertViewHas('siswaList', function ($students) {
                $names = $students->pluck('nama');

                return $names->contains('Ahmad Fauzan')
                    && $names->contains('Siti Aisyah')
                    && $names->contains('Legacy Matching Student')
                    && $names->contains('Dual Semester Student')
                    && ! $names->contains('Genap Only Student')
                    && ! $names->contains('Other Class Student')
                    && $names->filter(fn ($name) => $name === 'Ahmad Fauzan')->count() === 1;
            });
    }

    public function test_ganjil_only_student_is_excluded_from_genap_capaian(): void
    {
        $response = $this->actingAsWali($this->genapYearId, 2)
            ->get(route('wali_kelas.capaian_kompetensi.edit', $this->genapSubjectId));

        $response->assertOk()
            ->assertViewHas('siswaList', function ($students) {
                $names = $students->pluck('nama');

                return $names->contains('Genap Only Student')
                    && $names->contains('Dual Semester Student')
                    && ! $names->contains('Ahmad Fauzan')
                    && ! $names->contains('Siti Aisyah')
                    && ! $names->contains('Legacy Matching Student');
            });
    }

    public function test_genap_only_student_is_excluded_from_ganjil_capaian(): void
    {
        $response = $this->actingAsWali($this->ganjilYearId, 1)
            ->get(route('wali_kelas.capaian_kompetensi.edit', $this->ganjilSubjectId));

        $response->assertOk()
            ->assertViewHas('siswaList', function ($students) {
                return ! $students->pluck('nama')->contains('Genap Only Student');
            });
    }

    public function test_another_class_student_is_excluded_from_capaian_roster(): void
    {
        $response = $this->actingAsWali($this->ganjilYearId, 1)
            ->get(route('wali_kelas.capaian_kompetensi.edit', $this->ganjilSubjectId));

        $response->assertOk()
            ->assertViewHas('siswaList', function ($students) {
                return ! $students->pluck('nama')->contains('Other Class Student');
            });
    }

    public function test_existing_capaian_loads_only_from_requested_semester(): void
    {
        $this->insertCapaian($this->dualStudentId, $this->ganjilSubjectId, $this->ganjilYearId, 1, 'Ganjil tinggi', 'Ganjil rendah');
        $this->insertCapaian($this->dualStudentId, $this->genapSubjectId, $this->genapYearId, 2, 'Genap tinggi', 'Genap rendah');

        $response = $this->actingAsWali($this->ganjilYearId, 1)
            ->get(route('wali_kelas.capaian_kompetensi.edit', $this->ganjilSubjectId));

        $response->assertOk()
            ->assertViewHas('existingCapaian', function ($existingCapaian) {
                return $existingCapaian->get($this->dualStudentId)?->custom_capaian_tertinggi === 'Ganjil tinggi'
                    && $existingCapaian->doesntContain('custom_capaian_tertinggi', 'Genap tinggi');
            });
    }

    public function test_wali_can_save_capaian_for_enrolled_student(): void
    {
        $this->actingAsWali($this->ganjilYearId, 1)
            ->put(route('wali_kelas.capaian_kompetensi.update', $this->ganjilSubjectId), [
                'capaian_tertinggi' => [$this->ahmadId => 'Ahmad unggul di operasi hitung.'],
                'capaian_terendah' => [$this->ahmadId => 'Ahmad perlu latihan pecahan.'],
            ])
            ->assertRedirect(route('wali_kelas.capaian_kompetensi.index'));

        $this->assertDatabaseHas('capaian_custom', [
            'siswa_id' => $this->ahmadId,
            'mata_pelajaran_id' => $this->ganjilSubjectId,
            'tahun_ajaran_id' => $this->ganjilYearId,
            'semester' => 1,
            'custom_capaian_tertinggi' => 'Ahmad unggul di operasi hitung.',
        ]);
    }

    public function test_another_class_student_is_rejected_on_capaian_update(): void
    {
        $this->assertUnauthorizedCapaianDoesNotCreateRows($this->otherClassStudentId);
    }

    public function test_another_semester_student_is_rejected_on_capaian_update(): void
    {
        $this->assertUnauthorizedCapaianDoesNotCreateRows($this->genapOnlyId);
    }

    public function test_another_year_student_is_rejected_on_capaian_update(): void
    {
        $this->assertUnauthorizedCapaianDoesNotCreateRows($this->oldYearStudentId);
    }

    public function test_unrelated_legacy_class_does_not_grant_capaian_access_when_enrollment_differs(): void
    {
        $studentId = $this->insertStudent('3001', 'Legacy Capaian Mismatch Student', $this->waliGanjilClassId);
        $this->insertEnrollment($studentId, $this->otherClassId, $this->ganjilYearId, 1);

        $this->assertUnauthorizedCapaianDoesNotCreateRows($studentId);
    }

    public function test_matching_legacy_fallback_allows_capaian_when_student_has_no_enrollment(): void
    {
        $this->actingAsWali($this->ganjilYearId, 1)
            ->put(route('wali_kelas.capaian_kompetensi.update', $this->ganjilSubjectId), [
                'capaian_tertinggi' => [$this->legacyId => 'Legacy tinggi.'],
                'capaian_terendah' => [$this->legacyId => 'Legacy rendah.'],
            ])
            ->assertRedirect(route('wali_kelas.capaian_kompetensi.index'));

        $this->assertDatabaseHas('capaian_custom', [
            'siswa_id' => $this->legacyId,
            'mata_pelajaran_id' => $this->ganjilSubjectId,
            'tahun_ajaran_id' => $this->ganjilYearId,
            'semester' => 1,
            'custom_capaian_tertinggi' => 'Legacy tinggi.',
        ]);
    }

    public function test_unauthorized_capaian_request_changes_no_rows(): void
    {
        $this->insertCapaian($this->ahmadId, $this->ganjilSubjectId, $this->ganjilYearId, 1, 'Existing tinggi', 'Existing rendah');

        $this->actingAsWali($this->ganjilYearId, 1)
            ->put(route('wali_kelas.capaian_kompetensi.update', $this->ganjilSubjectId), [
                'capaian_tertinggi' => [$this->otherClassStudentId => 'Tidak boleh.'],
                'capaian_terendah' => [$this->otherClassStudentId => 'Tidak boleh.'],
            ])
            ->assertForbidden();

        $this->assertDatabaseHas('capaian_custom', [
            'siswa_id' => $this->ahmadId,
            'custom_capaian_tertinggi' => 'Existing tinggi',
        ]);
        $this->assertDatabaseMissing('capaian_custom', [
            'siswa_id' => $this->otherClassStudentId,
            'custom_capaian_tertinggi' => 'Tidak boleh.',
        ]);
    }

    public function test_mixed_valid_and_unauthorized_payload_is_rejected_atomically(): void
    {
        $this->actingAsWali($this->ganjilYearId, 1)
            ->put(route('wali_kelas.capaian_kompetensi.update', $this->ganjilSubjectId), [
                'capaian_tertinggi' => [
                    $this->ahmadId => 'Valid should not save.',
                    $this->otherClassStudentId => 'Invalid should reject.',
                ],
                'capaian_terendah' => [
                    $this->ahmadId => 'Valid should not save.',
                    $this->otherClassStudentId => 'Invalid should reject.',
                ],
            ])
            ->assertForbidden();

        $this->assertSame(0, DB::table('capaian_custom')->count());
    }

    public function test_existing_capaian_from_another_semester_remains_unchanged(): void
    {
        $this->insertCapaian($this->dualStudentId, $this->genapSubjectId, $this->genapYearId, 2, 'Genap tetap', 'Genap tetap rendah');

        $this->actingAsWali($this->ganjilYearId, 1)
            ->put(route('wali_kelas.capaian_kompetensi.update', $this->ganjilSubjectId), [
                'capaian_tertinggi' => [$this->dualStudentId => 'Ganjil baru'],
                'capaian_terendah' => [$this->dualStudentId => 'Ganjil baru rendah'],
            ])
            ->assertRedirect(route('wali_kelas.capaian_kompetensi.index'));

        $this->assertDatabaseHas('capaian_custom', [
            'siswa_id' => $this->dualStudentId,
            'mata_pelajaran_id' => $this->genapSubjectId,
            'tahun_ajaran_id' => $this->genapYearId,
            'semester' => 2,
            'custom_capaian_tertinggi' => 'Genap tetap',
        ]);
    }

    public function test_null_nilai_akhir_rapor_is_excluded_where_intended(): void
    {
        $this->insertNilai($this->ahmadId, $this->ganjilSubjectId, $this->ganjilYearId, null, 85);

        $result = CapaianKompetensiController::generateCapaianForRapor(
            $this->ahmadId,
            $this->ganjilSubjectId,
            $this->ganjilYearId
        );

        $this->assertSame('Nilai belum tersedia.', $result);
    }

    public function test_zero_nilai_akhir_rapor_is_not_treated_as_null(): void
    {
        $this->insertNilai($this->ahmadId, $this->ganjilSubjectId, $this->ganjilYearId, 0, 85);

        $result = CapaianKompetensiController::generateCapaianForRapor(
            $this->ahmadId,
            $this->ganjilSubjectId,
            $this->ganjilYearId
        );

        $this->assertNotSame('Nilai belum tersedia.', $result);
        $this->assertStringContainsString('perlu meningkatkan', $result);
    }

    public function test_completion_related_dirty_changes_remain_effective_for_zero_in_edit_view(): void
    {
        $this->insertNilai($this->ahmadId, $this->ganjilSubjectId, $this->ganjilYearId, 0, 85);

        $response = $this->actingAsWali($this->ganjilYearId, 1)
            ->get(route('wali_kelas.capaian_kompetensi.edit', $this->ganjilSubjectId));

        $response->assertOk()
            ->assertSee('Nilai akhir rapor');
    }

    public function test_custom_capaian_final_generation_allows_zero_final_grade(): void
    {
        $this->insertNilai($this->ahmadId, $this->ganjilSubjectId, $this->ganjilYearId, 0, 85);
        $customId = $this->insertCapaian($this->ahmadId, $this->ganjilSubjectId, $this->ganjilYearId, 1, null, null);

        $result = CapaianKompetensiCustom::findOrFail($customId)->generateFinalCapaian();

        $this->assertNotSame('Nilai belum tersedia.', $result);
        $this->assertStringContainsString('perlu meningkatkan', $result);
    }

    public function test_empty_capaian_payload_deletes_existing_authorized_custom_row(): void
    {
        $this->insertCapaian($this->ahmadId, $this->ganjilSubjectId, $this->ganjilYearId, 1, 'Akan dihapus', 'Akan dihapus');

        $this->actingAsWali($this->ganjilYearId, 1)
            ->put(route('wali_kelas.capaian_kompetensi.update', $this->ganjilSubjectId), [
                'capaian_tertinggi' => [$this->ahmadId => ''],
                'capaian_terendah' => [$this->ahmadId => ''],
            ])
            ->assertRedirect(route('wali_kelas.capaian_kompetensi.index'));

        $this->assertDatabaseMissing('capaian_custom', [
            'siswa_id' => $this->ahmadId,
            'mata_pelajaran_id' => $this->ganjilSubjectId,
            'tahun_ajaran_id' => $this->ganjilYearId,
            'semester' => 1,
        ]);
    }

    private function actingAsWali(int $tahunAjaranId, int $semester): self
    {
        return $this->actingAs($this->wali, 'guru')
            ->withSession([
                'tahun_ajaran_id' => $tahunAjaranId,
                'selected_semester' => $semester,
                'selected_role' => 'wali_kelas',
            ]);
    }

    private function assertUnauthorizedCapaianDoesNotCreateRows(int $studentId): void
    {
        $this->actingAsWali($this->ganjilYearId, 1)
            ->put(route('wali_kelas.capaian_kompetensi.update', $this->ganjilSubjectId), [
                'capaian_tertinggi' => [$studentId => 'Tidak boleh.'],
                'capaian_terendah' => [$studentId => 'Tidak boleh.'],
            ])
            ->assertForbidden();

        $this->assertDatabaseMissing('capaian_custom', [
            'siswa_id' => $studentId,
            'custom_capaian_tertinggi' => 'Tidak boleh.',
        ]);
    }

    private function createSchema(): void
    {
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
            $table->string('user_agent')->nullable();
            $table->timestamps();
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
            $table->string('nuptk')->nullable();
            $table->string('nama');
            $table->string('jenis_kelamin')->nullable();
            $table->date('tanggal_lahir')->nullable();
            $table->string('no_handphone')->nullable();
            $table->string('email')->nullable();
            $table->text('alamat')->nullable();
            $table->string('jabatan')->nullable();
            $table->string('username')->unique();
            $table->string('password');
            $table->string('remember_token')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('tahun_ajarans', function (Blueprint $table) {
            $table->id();
            $table->string('tahun_ajaran');
            $table->integer('semester')->default(1);
            $table->boolean('is_active')->default(false);
            $table->date('tanggal_mulai')->nullable();
            $table->date('tanggal_selesai')->nullable();
            $table->text('deskripsi')->nullable();
            $table->timestamps();
            $table->softDeletes();
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
            $table->string('nis')->unique();
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
            $table->unsignedTinyInteger('semester');
            $table->timestamps();
            $table->unique(['siswa_id', 'tahun_ajaran_id', 'semester'], 'siswa_kelas_semester_unique_context');
            $table->index(['kelas_id', 'tahun_ajaran_id', 'semester'], 'siswa_kelas_semester_class_context_index');
        });

        Schema::create('mata_pelajarans', function (Blueprint $table) {
            $table->id();
            $table->string('nama_pelajaran');
            $table->foreignId('kelas_id');
            $table->foreignId('guru_id')->nullable();
            $table->integer('semester')->default(1);
            $table->boolean('is_muatan_lokal')->default(false);
            $table->boolean('allow_non_wali')->default(false);
            $table->foreignId('tahun_ajaran_id')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('lingkup_materis', function (Blueprint $table) {
            $table->id();
            $table->foreignId('mata_pelajaran_id');
            $table->string('judul_lingkup_materi');
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('tujuan_pembelajarans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lingkup_materi_id');
            $table->string('tujuan_pembelajaran')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('nilais', function (Blueprint $table) {
            $table->id();
            $table->foreignId('siswa_id');
            $table->foreignId('mata_pelajaran_id');
            $table->foreignId('tujuan_pembelajaran_id')->nullable();
            $table->foreignId('lingkup_materi_id')->nullable();
            $table->decimal('nilai_lm', 5, 2)->nullable();
            $table->decimal('nilai_akhir_rapor', 5, 2)->nullable();
            $table->boolean('is_submitted')->default(false);
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
            $table->unique(['siswa_id', 'mata_pelajaran_id', 'tahun_ajaran_id', 'semester'], 'unique_capaian_custom');
        });

        Schema::create('capaian_templates', function (Blueprint $table) {
            $table->id();
            $table->string('mata_pelajaran');
            $table->decimal('nilai_min', 5, 2);
            $table->decimal('nilai_max', 5, 2);
            $table->text('template_text');
            $table->foreignId('tahun_ajaran_id');
            $table->timestamps();
        });
    }

    private function seedFixture(): void
    {
        $this->ganjilYearId = $this->insertYear('2026/2027', 1, true);
        $this->genapYearId = $this->insertYear('2026/2027', 2, false);
        $this->oldYearId = $this->insertYear('2025/2026', 1, false);

        $this->wali = Guru::create([
            'nama' => 'Budi Santoso',
            'username' => 'wali-budi',
            'email' => 'wali-budi@example.test',
            'password' => Hash::make('secret'),
        ]);

        $this->waliGanjilClassId = $this->insertClass(5, 'A', $this->ganjilYearId);
        $this->waliGenapClassId = $this->insertClass(5, 'A', $this->genapYearId);
        $this->otherClassId = $this->insertClass(5, 'B', $this->ganjilYearId);
        $this->oldClassId = $this->insertClass(5, 'A', $this->oldYearId);

        $this->insertWaliAssignment($this->wali->id, $this->waliGanjilClassId);
        $this->insertWaliAssignment($this->wali->id, $this->waliGenapClassId);

        $this->ahmadId = $this->insertStudent('1001', 'Ahmad Fauzan', $this->waliGanjilClassId);
        $this->sitiId = $this->insertStudent('1002', 'Siti Aisyah', $this->waliGanjilClassId);
        $this->legacyId = $this->insertStudent('1003', 'Legacy Matching Student', $this->waliGanjilClassId);
        $this->genapOnlyId = $this->insertStudent('1004', 'Genap Only Student', $this->waliGanjilClassId);
        $this->otherClassStudentId = $this->insertStudent('1005', 'Other Class Student', $this->otherClassId);
        $this->oldYearStudentId = $this->insertStudent('1006', 'Old Year Student', $this->waliGanjilClassId);
        $this->dualStudentId = $this->insertStudent('1007', 'Dual Semester Student', $this->waliGanjilClassId);

        $this->insertEnrollment($this->ahmadId, $this->waliGanjilClassId, $this->ganjilYearId, 1);
        $this->insertEnrollment($this->sitiId, $this->waliGanjilClassId, $this->ganjilYearId, 1);
        $this->insertEnrollment($this->genapOnlyId, $this->waliGenapClassId, $this->genapYearId, 2);
        $this->insertEnrollment($this->otherClassStudentId, $this->otherClassId, $this->ganjilYearId, 1);
        $this->insertEnrollment($this->oldYearStudentId, $this->oldClassId, $this->oldYearId, 1);
        $this->insertEnrollment($this->dualStudentId, $this->waliGanjilClassId, $this->ganjilYearId, 1);
        $this->insertEnrollment($this->dualStudentId, $this->waliGenapClassId, $this->genapYearId, 2);

        $this->ganjilSubjectId = $this->insertSubject('Matematika', $this->wali->id, $this->waliGanjilClassId, $this->ganjilYearId, 1);
        $this->genapSubjectId = $this->insertSubject('Matematika', $this->wali->id, $this->waliGenapClassId, $this->genapYearId, 2);

        $this->insertTemplate('Matematika', $this->ganjilYearId, 0, 69, '{nama_siswa} perlu meningkatkan penguasaan dalam mata pelajaran Matematika.');
    }

    private function insertYear(string $year, int $semester, bool $active): int
    {
        return DB::table('tahun_ajarans')->insertGetId([
            'tahun_ajaran' => $year,
            'semester' => $semester,
            'is_active' => $active,
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

    private function insertWaliAssignment(int $guruId, int $kelasId): void
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

    private function insertStudent(string $nis, string $name, int $classId): int
    {
        return DB::table('siswas')->insertGetId([
            'nis' => $nis,
            'nisn' => 'NISN-'.$nis,
            'nama' => $name,
            'kelas_id' => $classId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function insertEnrollment(int $studentId, int $classId, int $yearId, int $semester): void
    {
        DB::table('siswa_kelas_semester')->insert([
            'siswa_id' => $studentId,
            'kelas_id' => $classId,
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
            'is_muatan_lokal' => false,
            'allow_non_wali' => false,
            'tahun_ajaran_id' => $yearId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function insertNilai(int $studentId, int $subjectId, int $yearId, ?float $finalGrade, float $lm): void
    {
        $lmId = DB::table('lingkup_materis')->insertGetId([
            'mata_pelajaran_id' => $subjectId,
            'judul_lingkup_materi' => 'Bilangan cacah',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('nilais')->insert([
            'siswa_id' => $studentId,
            'mata_pelajaran_id' => $subjectId,
            'lingkup_materi_id' => $lmId,
            'nilai_lm' => $lm,
            'nilai_akhir_rapor' => $finalGrade,
            'is_submitted' => ! is_null($finalGrade),
            'tahun_ajaran_id' => $yearId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function insertCapaian(int $studentId, int $subjectId, int $yearId, int $semester, ?string $highest, ?string $lowest): int
    {
        return DB::table('capaian_custom')->insertGetId([
            'siswa_id' => $studentId,
            'mata_pelajaran_id' => $subjectId,
            'custom_capaian_tertinggi' => $highest,
            'custom_capaian_terendah' => $lowest,
            'tahun_ajaran_id' => $yearId,
            'semester' => $semester,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function insertTemplate(string $subject, int $yearId, float $min, float $max, string $text): void
    {
        DB::table('capaian_templates')->insert([
            'mata_pelajaran' => $subject,
            'nilai_min' => $min,
            'nilai_max' => $max,
            'template_text' => $text,
            'tahun_ajaran_id' => $yearId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}

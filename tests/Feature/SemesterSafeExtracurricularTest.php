<?php

namespace Tests\Feature;

use App\Models\Guru;
use App\Models\NilaiEkstrakurikuler;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class SemesterSafeExtracurricularTest extends TestCase
{
    private Guru $wali;

    private int $ganjilYearId;

    private int $genapYearId;

    private int $oldYearId;

    private int $waliGanjilClassId;

    private int $waliGenapClassId;

    private int $otherClassId;

    private int $oldClassId;

    private int $pramukaId;

    private int $pramukaGenapId;

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

    public function test_migration_adds_nullable_semester_and_preserves_existing_results(): void
    {
        Schema::dropIfExists('nilai_ekstrakurikuler');

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

        DB::table('nilai_ekstrakurikuler')->insert([
            'id' => 99,
            'siswa_id' => $this->ahmadId,
            'ekstrakurikuler_id' => $this->pramukaId,
            'predikat' => 'A',
            'deskripsi' => 'Existing ambiguous result',
            'tahun_ajaran_id' => $this->ganjilYearId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $migration = require database_path('migrations/2026_06_06_000100_add_semester_to_nilai_ekstrakurikuler_table.php');
        $migration->up();

        $this->assertTrue(Schema::hasColumn('nilai_ekstrakurikuler', 'semester'));
        $indexes = collect(Schema::getIndexes('nilai_ekstrakurikuler'));
        $this->assertTrue($indexes->contains(fn ($index) => ($index['columns'] ?? []) === [
            'siswa_id',
            'ekstrakurikuler_id',
            'tahun_ajaran_id',
            'semester',
        ]));
        $this->assertTrue($indexes->contains(fn ($index) => ($index['columns'] ?? []) === [
            'tahun_ajaran_id',
            'semester',
        ]));
        $this->assertDatabaseHas('nilai_ekstrakurikuler', [
            'id' => 99,
            'deskripsi' => 'Existing ambiguous result',
            'semester' => null,
        ]);
    }

    public function test_same_student_and_activity_can_have_separate_ganjil_and_genap_results(): void
    {
        $this->insertResult($this->dualStudentId, $this->pramukaId, $this->ganjilYearId, 1, 'Ganjil result');
        $this->insertResult($this->dualStudentId, $this->pramukaId, $this->genapYearId, 2, 'Genap result');

        $this->assertDatabaseHas('nilai_ekstrakurikuler', [
            'siswa_id' => $this->dualStudentId,
            'ekstrakurikuler_id' => $this->pramukaId,
            'tahun_ajaran_id' => $this->ganjilYearId,
            'semester' => 1,
            'deskripsi' => 'Ganjil result',
        ]);
        $this->assertDatabaseHas('nilai_ekstrakurikuler', [
            'siswa_id' => $this->dualStudentId,
            'ekstrakurikuler_id' => $this->pramukaId,
            'tahun_ajaran_id' => $this->genapYearId,
            'semester' => 2,
            'deskripsi' => 'Genap result',
        ]);
    }

    public function test_wali_roster_lists_students_enrolled_in_requested_ganjil_context(): void
    {
        $response = $this->actingAsWali($this->ganjilYearId, 1)
            ->get(route('wali_kelas.ekstrakurikuler.index'));

        $response->assertOk()
            ->assertViewHas('siswas', function ($students) {
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

    public function test_ganjil_and_genap_rosters_do_not_mix_students(): void
    {
        $this->actingAsWali($this->genapYearId, 2)
            ->get(route('wali_kelas.ekstrakurikuler.index'))
            ->assertOk()
            ->assertViewHas('siswas', function ($students) {
                $names = $students->pluck('nama');

                return $names->contains('Genap Only Student')
                    && $names->contains('Dual Semester Student')
                    && ! $names->contains('Ahmad Fauzan')
                    && ! $names->contains('Siti Aisyah')
                    && ! $names->contains('Legacy Matching Student');
            });
    }

    public function test_legacy_fallback_is_used_only_when_student_has_no_enrollment(): void
    {
        $mismatchId = $this->insertStudent('4001', 'Legacy Mismatch Student', $this->waliGanjilClassId);
        $this->insertEnrollment($mismatchId, $this->otherClassId, $this->ganjilYearId, 1);

        $this->actingAsWali($this->ganjilYearId, 1)
            ->get(route('wali_kelas.ekstrakurikuler.index'))
            ->assertOk()
            ->assertViewHas('siswas', function ($students) {
                $names = $students->pluck('nama');

                return $names->contains('Legacy Matching Student')
                    && ! $names->contains('Legacy Mismatch Student');
            });
    }

    public function test_wali_can_save_ganjil_and_genap_results_without_overwriting(): void
    {
        $this->actingAsWali($this->ganjilYearId, 1)
            ->postJson(route('wali_kelas.ekstrakurikuler.bulk-save'), [
                'rows' => [
                    $this->bulkRow($this->dualStudentId, 'Ganjil Pramuka'),
                ],
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->actingAsWali($this->genapYearId, 2)
            ->postJson(route('wali_kelas.ekstrakurikuler.bulk-save'), [
                'rows' => [
                    $this->bulkRow($this->dualStudentId, 'Genap Pramuka', $this->pramukaGenapId),
                ],
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertDatabaseHas('nilai_ekstrakurikuler', [
            'siswa_id' => $this->dualStudentId,
            'ekstrakurikuler_id' => $this->pramukaId,
            'tahun_ajaran_id' => $this->ganjilYearId,
            'semester' => 1,
            'deskripsi' => 'Ganjil Pramuka',
        ]);
        $this->assertDatabaseHas('nilai_ekstrakurikuler', [
            'siswa_id' => $this->dualStudentId,
            'ekstrakurikuler_id' => $this->pramukaGenapId,
            'tahun_ajaran_id' => $this->genapYearId,
            'semester' => 2,
            'deskripsi' => 'Genap Pramuka',
        ]);
    }

    public function test_reads_return_only_requested_semester_results(): void
    {
        $this->insertResult($this->dualStudentId, $this->pramukaId, $this->ganjilYearId, 1, 'Ganjil only');
        $this->insertResult($this->dualStudentId, $this->pramukaId, $this->genapYearId, 2, 'Genap only');

        $this->actingAsWali($this->ganjilYearId, 1)
            ->get(route('wali_kelas.ekstrakurikuler.index'))
            ->assertOk()
            ->assertViewHas('ekskulData', function (array $data) {
                $rows = collect($data[(string) $this->dualStudentId] ?? []);

                return $rows->contains('deskripsi', 'Ganjil only')
                    && ! $rows->contains('deskripsi', 'Genap only');
            });
    }

    public function test_another_class_student_bulk_save_is_forbidden_and_changes_no_rows(): void
    {
        $this->assertUnauthorizedBulkSaveDoesNotCreateRows($this->otherClassStudentId);
    }

    public function test_another_semester_student_bulk_save_is_forbidden_and_changes_no_rows(): void
    {
        $this->assertUnauthorizedBulkSaveDoesNotCreateRows($this->genapOnlyId);
    }

    public function test_another_year_student_bulk_save_is_forbidden_and_changes_no_rows(): void
    {
        $this->assertUnauthorizedBulkSaveDoesNotCreateRows($this->oldYearStudentId);
    }

    public function test_unrelated_legacy_class_does_not_grant_bulk_save_access(): void
    {
        $studentId = $this->insertStudent('4002', 'Legacy Bulk Mismatch Student', $this->waliGanjilClassId);
        $this->insertEnrollment($studentId, $this->otherClassId, $this->ganjilYearId, 1);

        $this->assertUnauthorizedBulkSaveDoesNotCreateRows($studentId);
    }

    public function test_matching_legacy_fallback_allows_save_when_student_has_no_enrollment(): void
    {
        $this->actingAsWali($this->ganjilYearId, 1)
            ->postJson(route('wali_kelas.ekstrakurikuler.bulk-save'), [
                'rows' => [
                    $this->bulkRow($this->legacyId, 'Legacy fallback result'),
                ],
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertDatabaseHas('nilai_ekstrakurikuler', [
            'siswa_id' => $this->legacyId,
            'tahun_ajaran_id' => $this->ganjilYearId,
            'semester' => 1,
            'deskripsi' => 'Legacy fallback result',
        ]);
    }

    public function test_mixed_payload_is_rejected_atomically(): void
    {
        $this->actingAsWali($this->ganjilYearId, 1)
            ->postJson(route('wali_kelas.ekstrakurikuler.bulk-save'), [
                'rows' => [
                    $this->bulkRow($this->ahmadId, 'Allowed row'),
                    $this->bulkRow($this->otherClassStudentId, 'Forbidden row'),
                ],
            ])
            ->assertForbidden();

        $this->assertSame(0, DB::table('nilai_ekstrakurikuler')->count());
    }

    public function test_delete_removes_only_requested_semester_result(): void
    {
        $ganjilId = $this->insertResult($this->dualStudentId, $this->pramukaId, $this->ganjilYearId, 1, 'Delete ganjil');
        $genapId = $this->insertResult($this->dualStudentId, $this->pramukaId, $this->genapYearId, 2, 'Keep genap');

        $this->actingAsWali($this->ganjilYearId, 1)
            ->postJson(route('wali_kelas.ekstrakurikuler.bulk-save'), [
                'rows' => [],
                'deleted_ids' => [$ganjilId],
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertSoftDeleted('nilai_ekstrakurikuler', ['id' => $ganjilId]);
        $this->assertDatabaseHas('nilai_ekstrakurikuler', [
            'id' => $genapId,
            'deleted_at' => null,
            'deskripsi' => 'Keep genap',
        ]);
    }

    public function test_unauthorized_delete_changes_no_rows(): void
    {
        $otherResultId = $this->insertResult($this->otherClassStudentId, $this->pramukaId, $this->ganjilYearId, 1, 'Other class result');
        $allowedResultId = $this->insertResult($this->ahmadId, $this->pramukaId, $this->ganjilYearId, 1, 'Allowed result');

        $this->actingAsWali($this->ganjilYearId, 1)
            ->postJson(route('wali_kelas.ekstrakurikuler.bulk-save'), [
                'rows' => [],
                'deleted_ids' => [$allowedResultId, $otherResultId],
            ])
            ->assertForbidden();

        $this->assertDatabaseHas('nilai_ekstrakurikuler', [
            'id' => $allowedResultId,
            'deleted_at' => null,
        ]);
        $this->assertDatabaseHas('nilai_ekstrakurikuler', [
            'id' => $otherResultId,
            'deleted_at' => null,
        ]);
    }

    public function test_legacy_create_update_and_destroy_paths_are_semester_scoped(): void
    {
        $this->actingAsWali($this->ganjilYearId, 1)
            ->post(route('wali_kelas.ekstrakurikuler.store'), [
                'siswa_id' => $this->ahmadId,
                'ekstrakurikuler_id' => $this->pramukaId,
                'predikat' => 'A',
                'deskripsi' => 'Created through legacy form',
            ])
            ->assertRedirect(route('wali_kelas.ekstrakurikuler.index'));

        $resultId = (int) DB::table('nilai_ekstrakurikuler')->where([
            'siswa_id' => $this->ahmadId,
            'tahun_ajaran_id' => $this->ganjilYearId,
            'semester' => 1,
        ])->value('id');

        $this->actingAsWali($this->ganjilYearId, 1)
            ->put(route('wali_kelas.ekstrakurikuler.update', $resultId), [
                'predikat' => 'B',
                'deskripsi' => 'Updated through legacy form',
            ])
            ->assertRedirect(route('wali_kelas.ekstrakurikuler.index'));

        $this->assertDatabaseHas('nilai_ekstrakurikuler', [
            'id' => $resultId,
            'tahun_ajaran_id' => $this->ganjilYearId,
            'semester' => 1,
            'predikat' => 'B',
            'deskripsi' => 'Updated through legacy form',
        ]);

        $this->actingAsWali($this->genapYearId, 2)
            ->delete(route('wali_kelas.ekstrakurikuler.destroy', $resultId))
            ->assertForbidden();

        $this->actingAsWali($this->ganjilYearId, 1)
            ->delete(route('wali_kelas.ekstrakurikuler.destroy', $resultId))
            ->assertRedirect(route('wali_kelas.ekstrakurikuler.index'));

        $this->assertSoftDeleted('nilai_ekstrakurikuler', ['id' => $resultId]);
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

    private function assertUnauthorizedBulkSaveDoesNotCreateRows(int $studentId): void
    {
        $this->actingAsWali($this->ganjilYearId, 1)
            ->postJson(route('wali_kelas.ekstrakurikuler.bulk-save'), [
                'rows' => [
                    $this->bulkRow($studentId, 'Tidak boleh tersimpan'),
                ],
            ])
            ->assertForbidden();

        $this->assertDatabaseMissing('nilai_ekstrakurikuler', [
            'siswa_id' => $studentId,
            'tahun_ajaran_id' => $this->ganjilYearId,
            'semester' => 1,
            'deskripsi' => 'Tidak boleh tersimpan',
        ]);
    }

    private function bulkRow(int $studentId, string $description, ?int $ekstrakurikulerId = null): array
    {
        return [
            'siswa_id' => $studentId,
            'ekstrakurikuler_id' => $ekstrakurikulerId ?? $this->pramukaId,
            'deskripsi' => $description,
        ];
    }

    private function createSchema(): void
    {
        foreach ([
            'audit_logs',
            'nilai_ekstrakurikuler',
            'ekstrakurikulers',
            'siswa_kelas_semester',
            'siswas',
            'mata_pelajarans',
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

        Schema::create('siswa_kelas_semester', function (Blueprint $table) {
            $table->id();
            $table->foreignId('siswa_id');
            $table->foreignId('kelas_id');
            $table->foreignId('tahun_ajaran_id');
            $table->tinyInteger('semester');
            $table->timestamps();
            $table->unique(['siswa_id', 'tahun_ajaran_id', 'semester']);
            $table->index(['kelas_id', 'tahun_ajaran_id', 'semester']);
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
            $table->tinyInteger('semester')->nullable();
            $table->timestamps();
            $table->softDeletes();
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
        $this->dualStudentId = $this->insertStudent('1007', 'Dual Semester Student', $this->waliGanjilClassId);

        $this->insertEnrollment($this->ahmadId, $this->waliGanjilClassId, $this->ganjilYearId, 1);
        $this->insertEnrollment($this->sitiId, $this->waliGanjilClassId, $this->ganjilYearId, 1);
        $this->insertEnrollment($this->genapOnlyId, $this->waliGenapClassId, $this->genapYearId, 2);
        $this->insertEnrollment($this->otherClassStudentId, $this->otherClassId, $this->ganjilYearId, 1);
        $this->insertEnrollment($this->oldYearStudentId, $this->oldClassId, $this->oldYearId, 1);
        $this->insertEnrollment($this->dualStudentId, $this->waliGanjilClassId, $this->ganjilYearId, 1);
        $this->insertEnrollment($this->dualStudentId, $this->waliGenapClassId, $this->genapYearId, 2);

        $this->pramukaId = DB::table('ekstrakurikulers')->insertGetId([
            'nama_ekstrakurikuler' => 'Pramuka',
            'pembina' => 'Pembina Demo',
            'tahun_ajaran_id' => $this->ganjilYearId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->pramukaGenapId = DB::table('ekstrakurikulers')->insertGetId([
            'nama_ekstrakurikuler' => 'Pramuka Genap',
            'pembina' => 'Pembina Demo',
            'tahun_ajaran_id' => $this->genapYearId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

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

    private function insertResult(
        int $studentId,
        int $ekstrakurikulerId,
        int $yearId,
        int $semester,
        string $description
    ): int {
        return NilaiEkstrakurikuler::create([
            'siswa_id' => $studentId,
            'ekstrakurikuler_id' => $ekstrakurikulerId,
            'predikat' => 'A',
            'deskripsi' => $description,
            'tahun_ajaran_id' => $yearId,
            'semester' => $semester,
        ])->id;
    }
}

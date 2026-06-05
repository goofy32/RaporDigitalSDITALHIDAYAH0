<?php

namespace Tests\Feature;

use App\Models\Kelas;
use App\Models\Siswa;
use App\Models\SiswaKelasSemester;
use App\Models\TahunAjaran;
use App\Services\SiswaKelasSemesterResolver;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

class SemesterEnrollmentFoundationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('database.default', 'sqlite');
        config()->set('database.connections.sqlite.database', ':memory:');
        config()->set('cache.default', 'array');
        config()->set('session.driver', 'array');
        DB::purge('sqlite');
        DB::reconnect('sqlite');

        $this->app->detectEnvironment(fn () => 'testing');
        $this->createBaseSchema();
    }

    public function test_fresh_database_creates_siswa_kelas_semester_table(): void
    {
        $this->runEnrollmentMigration();

        $this->assertTrue(Schema::hasTable('siswa_kelas_semester'));
        foreach (['id', 'siswa_id', 'kelas_id', 'tahun_ajaran_id', 'semester', 'created_at', 'updated_at'] as $column) {
            $this->assertTrue(Schema::hasColumn('siswa_kelas_semester', $column));
        }
    }

    public function test_required_indexes_and_unique_constraint_exist(): void
    {
        $this->runEnrollmentMigration();

        $indexes = $this->sqliteIndexes('siswa_kelas_semester');

        $this->assertTrue($this->hasIndex($indexes, ['siswa_id', 'tahun_ajaran_id', 'semester'], true));
        $this->assertTrue($this->hasIndex($indexes, ['kelas_id', 'tahun_ajaran_id', 'semester'], false));
    }

    public function test_existing_compatible_table_is_preserved_by_migration(): void
    {
        [$year, $kelas, $student] = $this->seedBaseStudentContext();
        $this->createCompatibleEnrollmentTable();

        DB::table('siswa_kelas_semester')->insert([
            'siswa_id' => $student->id,
            'kelas_id' => $kelas->id,
            'tahun_ajaran_id' => $year->id,
            'semester' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->runEnrollmentMigration();

        $this->assertSame(1, DB::table('siswa_kelas_semester')->count());
        $this->assertDatabaseHas('siswa_kelas_semester', [
            'siswa_id' => $student->id,
            'kelas_id' => $kelas->id,
            'tahun_ajaran_id' => $year->id,
            'semester' => 1,
        ]);
    }

    public function test_one_student_can_have_ganjil_and_genap_enrollment_rows(): void
    {
        $this->runEnrollmentMigration();
        [$ganjil, $kelasGanjil, $student] = $this->seedBaseStudentContext();
        $genap = $this->createAcademicYear('2026/2027', 2);
        $kelasGenap = $this->createClass($genap, 5, 'A');

        SiswaKelasSemester::create([
            'siswa_id' => $student->id,
            'kelas_id' => $kelasGanjil->id,
            'tahun_ajaran_id' => $ganjil->id,
            'semester' => 1,
        ]);
        SiswaKelasSemester::create([
            'siswa_id' => $student->id,
            'kelas_id' => $kelasGenap->id,
            'tahun_ajaran_id' => $genap->id,
            'semester' => 2,
        ]);

        $this->assertSame(2, $student->semesterEnrollments()->count());
    }

    public function test_resolver_returns_correct_class_for_requested_year_and_semester(): void
    {
        $this->runEnrollmentMigration();
        [$ganjil, $kelasGanjil, $student] = $this->seedBaseStudentContext();
        $genap = $this->createAcademicYear('2026/2027', 2);
        $kelasGenap = $this->createClass($genap, 5, 'A');

        SiswaKelasSemester::create([
            'siswa_id' => $student->id,
            'kelas_id' => $kelasGanjil->id,
            'tahun_ajaran_id' => $ganjil->id,
            'semester' => 1,
        ]);
        SiswaKelasSemester::create([
            'siswa_id' => $student->id,
            'kelas_id' => $kelasGenap->id,
            'tahun_ajaran_id' => $genap->id,
            'semester' => 2,
        ]);

        $resolver = new SiswaKelasSemesterResolver;

        $this->assertSame($kelasGanjil->id, $resolver->resolveClass($student, $ganjil->id, 1)->id);
        $this->assertSame($kelasGenap->id, $resolver->resolveClass($student, $genap->id, 2)->id);
    }

    public function test_resolver_does_not_return_unrelated_current_class(): void
    {
        $this->runEnrollmentMigration();
        [, , $student] = $this->seedBaseStudentContext();
        $genap = $this->createAcademicYear('2026/2027', 2);

        $resolver = new SiswaKelasSemesterResolver;

        $this->assertNull($resolver->resolveClass($student, $genap->id, 2));
    }

    public function test_duplicate_enrollment_for_same_student_year_semester_is_rejected(): void
    {
        $this->runEnrollmentMigration();
        [$year, $kelas, $student] = $this->seedBaseStudentContext();

        SiswaKelasSemester::create([
            'siswa_id' => $student->id,
            'kelas_id' => $kelas->id,
            'tahun_ajaran_id' => $year->id,
            'semester' => 1,
        ]);

        $this->expectException(QueryException::class);

        SiswaKelasSemester::create([
            'siswa_id' => $student->id,
            'kelas_id' => $kelas->id,
            'tahun_ajaran_id' => $year->id,
            'semester' => 1,
        ]);
    }

    public function test_class_student_lookup_returns_enrolled_students(): void
    {
        $this->runEnrollmentMigration();
        [$year, $kelas, $student] = $this->seedBaseStudentContext();
        $otherClass = $this->createClass($year, 5, 'B');
        $otherStudent = $this->createStudent($otherClass, '2605002', '9000000002');

        SiswaKelasSemester::create([
            'siswa_id' => $student->id,
            'kelas_id' => $kelas->id,
            'tahun_ajaran_id' => $year->id,
            'semester' => 1,
        ]);
        SiswaKelasSemester::create([
            'siswa_id' => $otherStudent->id,
            'kelas_id' => $otherClass->id,
            'tahun_ajaran_id' => $year->id,
            'semester' => 1,
        ]);

        $students = (new SiswaKelasSemesterResolver)->studentsEnrolledInClass($kelas->id, $year->id, 1);

        $this->assertSame([$student->id], $students->pluck('id')->all());
    }

    public function test_missing_enrollment_behavior_is_explicit(): void
    {
        $this->runEnrollmentMigration();
        [$year, , $student] = $this->seedBaseStudentContext();

        $resolver = new SiswaKelasSemesterResolver;

        $this->assertSame('missing', $resolver->resolveClassContext($student, $year->id, 1, false)['source']);

        $this->expectException(RuntimeException::class);
        $resolver->resolveClassOrFail($student, $year->id, 1, false);
    }

    public function test_controlled_legacy_fallback_only_works_for_matching_context(): void
    {
        $this->runEnrollmentMigration();
        [$ganjil, $kelas, $student] = $this->seedBaseStudentContext();
        $genap = $this->createAcademicYear('2026/2027', 2);

        $resolver = new SiswaKelasSemesterResolver;

        $this->assertSame($kelas->id, $resolver->resolveClass($student, $ganjil->id, 1)->id);
        $this->assertNull($resolver->resolveClass($student, $genap->id, 2));
    }

    public function test_backfill_dry_run_creates_no_rows(): void
    {
        $this->runEnrollmentMigration();
        $this->seedBaseStudentContext();

        $exitCode = Artisan::call('enrollment:backfill');

        $this->assertSame(0, $exitCode);
        $this->assertSame(0, SiswaKelasSemester::count());
    }

    public function test_backfill_creates_valid_missing_enrollment_records(): void
    {
        $this->runEnrollmentMigration();
        $this->seedBaseStudentContext();

        $this->artisan('enrollment:backfill', ['--apply' => true])
            ->expectsConfirmation('Create missing semester enrollment records now?', 'yes')
            ->assertExitCode(0);

        $this->assertSame(1, SiswaKelasSemester::count());
    }

    public function test_backfill_skips_invalid_ambiguous_and_s2_records(): void
    {
        $this->runEnrollmentMigration();
        [$year, $kelas, $student] = $this->seedBaseStudentContext();
        $otherClass = $this->createClass($year, 5, 'B');

        SiswaKelasSemester::create([
            'siswa_id' => $student->id,
            'kelas_id' => $otherClass->id,
            'tahun_ajaran_id' => $year->id,
            'semester' => 1,
        ]);

        $this->createStudent($kelas, 'S2-2605002', 'S2-9000000002');
        Siswa::withoutEvents(fn () => Siswa::create([
            'nis' => '2605003',
            'nisn' => '9000000003',
            'nama' => 'Invalid Class Student',
            'tanggal_lahir' => '2015-01-01',
            'jenis_kelamin' => 'Laki-laki',
            'agama' => 'Islam',
            'alamat' => 'Demo',
            'kelas_id' => null,
            'nama_ayah' => 'Ayah',
            'nama_ibu' => 'Ibu',
            'pekerjaan_ayah' => 'Kerja',
            'pekerjaan_ibu' => 'Kerja',
            'alamat_orangtua' => 'Demo',
        ]));

        $this->artisan('enrollment:backfill', ['--apply' => true])
            ->expectsConfirmation('Create missing semester enrollment records now?', 'yes')
            ->assertExitCode(0);

        $this->assertSame(1, SiswaKelasSemester::count());
        $this->assertDatabaseMissing('siswa_kelas_semester', [
            'siswa_id' => Siswa::where('nis', 'S2-2605002')->value('id'),
        ]);
    }

    public function test_backfill_is_idempotent_and_does_not_change_student_identity_or_class_pointer(): void
    {
        $this->runEnrollmentMigration();
        [, , $student] = $this->seedBaseStudentContext();
        $original = $student->only(['nis', 'nisn', 'kelas_id']);

        $this->artisan('enrollment:backfill', ['--apply' => true])
            ->expectsConfirmation('Create missing semester enrollment records now?', 'yes')
            ->assertExitCode(0);
        $this->artisan('enrollment:backfill', ['--apply' => true])
            ->expectsConfirmation('Create missing semester enrollment records now?', 'yes')
            ->assertExitCode(0);

        $student->refresh();
        $this->assertSame(1, SiswaKelasSemester::count());
        $this->assertSame($original, $student->only(['nis', 'nisn', 'kelas_id']));
    }

    private function createBaseSchema(): void
    {
        foreach (['siswa_kelas_semester', 'siswas', 'kelas', 'profil_sekolah', 'tahun_ajarans'] as $table) {
            Schema::dropIfExists($table);
        }

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
            $table->string('tahun_pelajaran')->nullable();
            $table->integer('semester')->nullable();
            $table->timestamps();
        });

        Schema::create('kelas', function (Blueprint $table) {
            $table->id();
            $table->integer('nomor_kelas');
            $table->string('nama_kelas');
            $table->foreignId('tahun_ajaran_id')->nullable()->constrained('tahun_ajarans')->restrictOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('siswas', function (Blueprint $table) {
            $table->id();
            $table->string('nis')->nullable()->unique();
            $table->string('nisn')->nullable()->unique();
            $table->string('nama');
            $table->date('tanggal_lahir')->nullable();
            $table->string('jenis_kelamin')->nullable();
            $table->string('agama')->nullable();
            $table->text('alamat')->nullable();
            $table->foreignId('kelas_id')->nullable()->constrained('kelas')->restrictOnDelete();
            $table->string('nama_ayah')->nullable();
            $table->string('nama_ibu')->nullable();
            $table->string('pekerjaan_ayah')->nullable();
            $table->string('pekerjaan_ibu')->nullable();
            $table->text('alamat_orangtua')->nullable();
            $table->foreignId('tahun_ajaran_id')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    private function createCompatibleEnrollmentTable(): void
    {
        Schema::create('siswa_kelas_semester', function (Blueprint $table) {
            $table->id();
            $table->foreignId('siswa_id')->constrained('siswas')->restrictOnDelete();
            $table->foreignId('kelas_id')->constrained('kelas')->restrictOnDelete();
            $table->foreignId('tahun_ajaran_id')->constrained('tahun_ajarans')->restrictOnDelete();
            $table->tinyInteger('semester');
            $table->timestamps();
            $table->unique(['siswa_id', 'tahun_ajaran_id', 'semester']);
            $table->index(['kelas_id', 'tahun_ajaran_id', 'semester']);
        });
    }

    private function runEnrollmentMigration(): void
    {
        $migration = require database_path('migrations/2026_06_05_010000_create_siswa_kelas_semester_table.php');
        $migration->up();
    }

    /**
     * @return array{TahunAjaran, Kelas, Siswa}
     */
    private function seedBaseStudentContext(): array
    {
        $year = $this->createAcademicYear('2026/2027', 1);
        $kelas = $this->createClass($year, 5, 'A');
        $student = $this->createStudent($kelas, '2605001', '9000000001');

        return [$year, $kelas, $student];
    }

    private function createAcademicYear(string $year, int $semester): TahunAjaran
    {
        return TahunAjaran::withoutEvents(fn () => TahunAjaran::create([
            'tahun_ajaran' => $year,
            'semester' => $semester,
            'is_active' => $semester === 1,
            'tanggal_mulai' => '2026-07-13',
            'tanggal_selesai' => '2027-06-19',
        ]));
    }

    private function createClass(TahunAjaran $tahunAjaran, int $number, string $name): Kelas
    {
        return Kelas::withoutEvents(fn () => Kelas::create([
            'nomor_kelas' => $number,
            'nama_kelas' => $name,
            'tahun_ajaran_id' => $tahunAjaran->id,
        ]));
    }

    private function createStudent(Kelas $kelas, string $nis, string $nisn): Siswa
    {
        return Siswa::withoutEvents(fn () => Siswa::create([
            'nis' => $nis,
            'nisn' => $nisn,
            'nama' => 'Demo Student '.$nis,
            'tanggal_lahir' => '2015-01-01',
            'jenis_kelamin' => 'Laki-laki',
            'agama' => 'Islam',
            'alamat' => 'Demo',
            'kelas_id' => $kelas->id,
            'nama_ayah' => 'Ayah',
            'nama_ibu' => 'Ibu',
            'pekerjaan_ayah' => 'Kerja',
            'pekerjaan_ibu' => 'Kerja',
            'alamat_orangtua' => 'Demo',
            'tahun_ajaran_id' => $kelas->tahun_ajaran_id,
        ]));
    }

    /**
     * @return array<int, array{columns: array<int, string>, unique: bool}>
     */
    private function sqliteIndexes(string $table): array
    {
        return collect(DB::select("PRAGMA index_list('{$table}')"))
            ->map(function (object $index) {
                return [
                    'columns' => collect(DB::select("PRAGMA index_info('{$index->name}')"))
                        ->sortBy('seqno')
                        ->pluck('name')
                        ->values()
                        ->all(),
                    'unique' => (bool) $index->unique,
                ];
            })
            ->all();
    }

    /**
     * @param  array<int, array{columns: array<int, string>, unique: bool}>  $indexes
     * @param  array<int, string>  $columns
     */
    private function hasIndex(array $indexes, array $columns, bool $unique): bool
    {
        return collect($indexes)->contains(function (array $index) use ($columns, $unique) {
            return $index['columns'] === $columns
                && (! $unique || $index['unique']);
        });
    }
}

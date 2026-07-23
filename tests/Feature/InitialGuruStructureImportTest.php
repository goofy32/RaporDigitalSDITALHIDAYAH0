<?php

namespace Tests\Feature;

use App\Models\Guru;
use App\Models\Kelas;
use App\Services\SubjectTeacherAssignmentValidator;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class InitialGuruStructureImportTest extends TestCase
{
    private int $yearId;

    /**
     * @var array<int, string>
     */
    private array $workbooks = [];

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('database.default', 'sqlite');
        config()->set('database.connections.sqlite.database', ':memory:');
        config()->set('cache.default', 'array');
        config()->set('session.driver', 'array');
        DB::purge('sqlite');
        DB::reconnect('sqlite');
        Cache::flush();

        putenv('INITIAL_GURU_PASSWORD=secret-password');
        $_ENV['INITIAL_GURU_PASSWORD'] = 'secret-password';
        $_SERVER['INITIAL_GURU_PASSWORD'] = 'secret-password';

        $this->createSchema();
        $this->seedActiveYear();
    }

    protected function tearDown(): void
    {
        foreach ($this->workbooks as $workbook) {
            if (is_file($workbook)) {
                @unlink($workbook);
            }
        }

        putenv('INITIAL_GURU_PASSWORD');
        unset($_ENV['INITIAL_GURU_PASSWORD'], $_SERVER['INITIAL_GURU_PASSWORD']);

        parent::tearDown();
    }

    public function test_import_creates_guru_classes_subjects_and_assignments_from_specific_and_range_rows(): void
    {
        $workbook = $this->createWorkbook([
            ['', 'Wali Ubay', 'Laki-laki', 'Wali Kelas + Guru', 'Pendidikan Pancasila, Mtk', 'Kelas 1- Ubay'],
            ['9000000000001', 'Wali Zaid', 'Perempuan', 'Wali Kelas + Guru', 'Pendidikan Pancasila', 'Kelas 2-Zaid'],
            ['', 'Wali Said', 'Laki-laki', 'Wali Kelas + Guru', 'Pendidikan Pancasila', "Kelas 3-Sa'id"],
            ['', 'Wali Ali', 'Laki-laki', 'Wali Kelas + Guru', 'Pendidikan Pancasila', 'Kelas 4-Ali'],
            ['', 'Guru PAI', 'Laki-laki', 'Guru Saja', 'PAI', 'Kelas 1-4'],
            ['', 'Guru PJOK', 'Laki-laki', 'Guru Saja', 'PJOK', 'Kelas 1-2'],
            ['', 'Guru Inggris', 'Perempuan', 'Guru Saja', 'B. Inggris', 'Kelas 1-2'],
            ['', 'Guru Penjas', 'Laki-laki', 'Guru Saja', 'Penjas', 'Kelas 1A, 2A, 2B'],
        ]);

        $this->artisan('initial-data:import-guru-structure', ['--file' => $workbook])
            ->assertExitCode(0);

        $kelasUbay = Kelas::where('nomor_kelas', 1)->where('nama_kelas', 'Ubay')->firstOrFail();
        $kelasZaid = Kelas::where('nomor_kelas', 2)->where('nama_kelas', 'Zaid')->firstOrFail();
        $kelasSaid = Kelas::where('nomor_kelas', 3)->where('nama_kelas', "Sa'id")->firstOrFail();
        $kelasAli = Kelas::where('nomor_kelas', 4)->where('nama_kelas', 'Ali')->firstOrFail();

        $this->assertDatabaseHas('kelas', [
            'nomor_kelas' => 1,
            'nama_kelas' => 'A',
            'tahun_ajaran_id' => $this->yearId,
        ]);
        $this->assertDatabaseHas('kelas', [
            'nomor_kelas' => 2,
            'nama_kelas' => 'B',
            'tahun_ajaran_id' => $this->yearId,
        ]);

        $waliUbay = Guru::where('nama', 'Wali Ubay')->firstOrFail();
        $this->assertNull($waliUbay->nuptk);
        $this->assertDatabaseHas('guru_kelas', [
            'guru_id' => $waliUbay->id,
            'kelas_id' => $kelasUbay->id,
            'is_wali_kelas' => true,
            'role' => 'wali_kelas',
        ]);
        $this->assertDatabaseHas('mata_pelajarans', [
            'nama_pelajaran' => 'Mtk',
            'kelas_id' => $kelasUbay->id,
            'guru_id' => $waliUbay->id,
            'is_muatan_lokal' => false,
            'allow_non_wali' => false,
            'tahun_ajaran_id' => $this->yearId,
            'semester' => 1,
        ]);

        $pai = Guru::where('nama', 'Guru PAI')->firstOrFail();
        foreach ([$kelasUbay, $kelasZaid, $kelasSaid, $kelasAli] as $class) {
            $this->assertDatabaseHas('guru_kelas', [
                'guru_id' => $pai->id,
                'kelas_id' => $class->id,
                'is_wali_kelas' => false,
                'role' => 'pengajar',
            ]);
            $this->assertDatabaseHas('mata_pelajarans', [
                'nama_pelajaran' => 'PAI',
                'kelas_id' => $class->id,
                'guru_id' => $pai->id,
                'is_muatan_lokal' => false,
                'allow_non_wali' => true,
            ]);
        }

        $inggris = Guru::where('nama', 'Guru Inggris')->firstOrFail();
        $this->assertDatabaseHas('mata_pelajarans', [
            'nama_pelajaran' => 'B. Inggris',
            'kelas_id' => $kelasUbay->id,
            'guru_id' => $inggris->id,
            'is_muatan_lokal' => true,
            'allow_non_wali' => false,
        ]);

        $validator = app(SubjectTeacherAssignmentValidator::class);
        $this->assertSame([], $validator->validate($inggris, $kelasUbay, true, false));
        $this->assertNotEmpty($validator->validate($inggris, $kelasAli, true, false));

        $this->assertSame(0, DB::table('siswas')->count());
        $this->assertSame(0, DB::table('siswa_kelas_semester')->count());
    }

    public function test_import_is_idempotent_and_empty_nuptk_does_not_duplicate_guru(): void
    {
        $workbook = $this->createWorkbook([
            ['', 'Wali Ubay', 'Laki-laki', 'Wali Kelas + Guru', 'Pendidikan Pancasila', 'Kelas 1- Ubay'],
        ]);

        $this->artisan('initial-data:import-guru-structure', ['--file' => $workbook])
            ->assertExitCode(0);
        $this->artisan('initial-data:import-guru-structure', ['--file' => $workbook])
            ->assertExitCode(0);

        $this->assertSame(1, Guru::where('nama', 'Wali Ubay')->whereNull('nuptk')->count());
        $this->assertSame(1, Kelas::where('nomor_kelas', 1)->where('nama_kelas', 'Ubay')->count());
        $this->assertSame(1, DB::table('guru_kelas')->count());
        $this->assertSame(1, DB::table('mata_pelajarans')->count());
    }

    public function test_import_skips_example_rows_marked_contoh(): void
    {
        $workbook = $this->createWorkbook([
            ['Contoh', '00001', 'Lestari', 'Perempuan', 'Guru Saja', 'Penjas', 'Kelas 1A, 2A, 2B'],
            [1, '', 'Wali Ubay', 'Laki-laki', 'Wali Kelas + Guru', 'Pendidikan Pancasila', 'Kelas 1- Ubay'],
        ]);

        $this->artisan('initial-data:import-guru-structure', ['--file' => $workbook])
            ->expectsOutput('Rows skipped: 1')
            ->assertExitCode(0);

        $this->assertDatabaseMissing('gurus', ['nama' => 'Lestari']);
        $this->assertDatabaseMissing('kelas', ['nomor_kelas' => 1, 'nama_kelas' => 'A']);
        $this->assertDatabaseMissing('kelas', ['nomor_kelas' => 2, 'nama_kelas' => 'A']);
        $this->assertDatabaseMissing('kelas', ['nomor_kelas' => 2, 'nama_kelas' => 'B']);

        $this->assertDatabaseHas('gurus', ['nama' => 'Wali Ubay']);
        $this->assertDatabaseHas('kelas', [
            'nomor_kelas' => 1,
            'nama_kelas' => 'Ubay',
            'tahun_ajaran_id' => $this->yearId,
        ]);
        $this->assertSame(1, DB::table('mata_pelajarans')->count());
    }

    public function test_import_stops_when_no_active_academic_year_exists(): void
    {
        DB::table('tahun_ajarans')->update(['is_active' => false]);

        $workbook = $this->createWorkbook([
            ['', 'Wali Ubay', 'Laki-laki', 'Wali Kelas + Guru', 'Pendidikan Pancasila', 'Kelas 1- Ubay'],
        ]);

        $this->artisan('initial-data:import-guru-structure', ['--file' => $workbook])
            ->expectsOutput('Tidak ada tahun ajaran aktif. Buat tahun ajaran aktif terlebih dahulu.')
            ->assertExitCode(1);

        $this->assertSame(0, Guru::count());
    }

    /**
     * @param  array<int, array<int, mixed>>  $rows
     */
    private function createWorkbook(array $rows): string
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->fromArray(['No', 'NUPTK', 'Nama', 'Jenis Kelamin', 'Jabatan', 'Pelajaran', 'Kelas Mengajar']);

        foreach ($rows as $index => $row) {
            $row = count($row) === 6 ? array_merge([$index + 1], $row) : $row;
            $sheet->fromArray($row, null, 'A'.($index + 2));
        }

        $directory = storage_path('framework/testing');
        File::ensureDirectoryExists($directory);
        $path = $directory.'/guru-import-'.uniqid('', true).'.xlsx';
        (new Xlsx($spreadsheet))->save($path);
        $this->workbooks[] = $path;

        return $path;
    }

    private function createSchema(): void
    {
        foreach ([
            'siswa_kelas_semester',
            'siswas',
            'mata_pelajarans',
            'guru_kelas',
            'kelas',
            'tahun_ajarans',
            'audit_logs',
            'gurus',
        ] as $table) {
            Schema::dropIfExists($table);
        }

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
            $table->string('nuptk')->nullable()->unique();
            $table->string('nama');
            $table->string('jenis_kelamin')->nullable();
            $table->date('tanggal_lahir')->nullable();
            $table->string('no_handphone')->nullable();
            $table->string('email')->nullable()->unique();
            $table->text('alamat')->nullable();
            $table->string('jabatan')->nullable();
            $table->string('username')->nullable()->unique();
            $table->string('password');
            $table->string('photo')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('tahun_ajarans', function (Blueprint $table) {
            $table->id();
            $table->string('tahun_ajaran');
            $table->boolean('is_active')->default(false);
            $table->integer('semester')->default(1);
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
            $table->string('role')->default('pengajar');
            $table->timestamps();
            $table->unique(['guru_id', 'kelas_id', 'role']);
        });

        Schema::create('mata_pelajarans', function (Blueprint $table) {
            $table->id();
            $table->string('nama_pelajaran');
            $table->foreignId('kelas_id')->nullable();
            $table->foreignId('guru_id')->nullable();
            $table->integer('semester')->default(1);
            $table->boolean('is_muatan_lokal')->default(false);
            $table->boolean('allow_non_wali')->default(false);
            $table->foreignId('tahun_ajaran_id')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('siswas', function (Blueprint $table) {
            $table->id();
            $table->string('nis')->unique();
            $table->string('nisn')->unique();
            $table->string('nama');
            $table->timestamps();
        });

        Schema::create('siswa_kelas_semester', function (Blueprint $table) {
            $table->id();
            $table->foreignId('siswa_id');
            $table->foreignId('kelas_id');
            $table->foreignId('tahun_ajaran_id');
            $table->unsignedTinyInteger('semester');
            $table->timestamps();
        });
    }

    private function seedActiveYear(): void
    {
        $this->yearId = DB::table('tahun_ajarans')->insertGetId([
            'tahun_ajaran' => '2026/2027',
            'semester' => 1,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}

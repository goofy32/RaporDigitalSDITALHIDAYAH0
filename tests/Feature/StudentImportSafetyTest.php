<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Psr\Log\LoggerInterface;
use Tests\TestCase;

class StudentImportSafetyTest extends TestCase
{
    private User $admin;

    private int $activeYearId;

    private int $otherYearId;

    private int $activeClassId;

    private int $otherYearClassId;

    /**
     * @var array<int, string>
     */
    private array $workbooks = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();

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

    protected function tearDown(): void
    {
        foreach ($this->workbooks as $workbook) {
            if (is_file($workbook)) {
                @unlink($workbook);
            }
        }

        parent::tearDown();
    }

    public function test_import_requires_active_academic_year_context(): void
    {
        DB::table('tahun_ajarans')->update(['is_active' => false]);

        $response = $this->postImport([$this->validRow()]);

        $response->assertRedirect();
        $response->assertSessionHas('error');
        $this->assertSame(0, DB::table('siswas')->count());
        $this->assertSame(0, DB::table('siswa_kelas_semester')->count());
    }

    public function test_import_matches_kelas_only_in_active_academic_year(): void
    {
        DB::table('kelas')->where('id', $this->activeClassId)->delete();

        $response = $this->postImport([$this->validRow()]);

        $response->assertRedirect();
        $response->assertSessionHas('import_errors');
        $this->assertSame(0, DB::table('siswas')->count());
        $this->assertSame(0, DB::table('siswa_kelas_semester')->count());
        $this->assertSame($this->otherYearClassId, (int) DB::table('kelas')->where('nomor_kelas', 1)->where('nama_kelas', 'Ubay')->value('id'));
    }

    public function test_import_must_not_auto_create_kelas(): void
    {
        $response = $this->postImport([$this->validRow(['kelas' => '9 - Typo'])]);

        $response->assertRedirect();
        $response->assertSessionHas('import_errors');
        $this->assertDatabaseMissing('kelas', [
            'nomor_kelas' => '9',
            'nama_kelas' => 'Typo',
        ]);
        $this->assertSame(0, DB::table('siswas')->count());
        $this->assertSame(0, DB::table('siswa_kelas_semester')->count());
    }

    public function test_import_creates_student_and_active_semester_enrollment(): void
    {
        $response = $this->postImport([$this->validRow()]);

        $response->assertRedirect(route('student'));
        $siswa = DB::table('siswas')->where('nis', '2601001')->first();

        $this->assertNotNull($siswa);
        $this->assertSame($this->activeClassId, (int) $siswa->kelas_id, 'Compatibility siswas.kelas_id should point to the imported active class.');
        $this->assertDatabaseHas('siswa_kelas_semester', [
            'siswa_id' => $siswa->id,
            'kelas_id' => $this->activeClassId,
            'tahun_ajaran_id' => $this->activeYearId,
            'semester' => 1,
        ]);
    }

    public function test_existing_database_nis_is_rejected_atomically(): void
    {
        $this->insertExistingStudent('2601001', '9900000009', 'Existing NIS');

        $response = $this->postImport([$this->validRow(['nisn' => '9900000010'])]);

        $response->assertRedirect();
        $response->assertSessionHas('import_errors');
        $this->assertSame(1, DB::table('siswas')->count());
        $this->assertSame(0, DB::table('siswa_kelas_semester')->count());
    }

    public function test_existing_database_nisn_is_rejected_atomically(): void
    {
        $this->insertExistingStudent('2601999', '9900000001', 'Existing NISN');

        $response = $this->postImport([$this->validRow(['nis' => '2601010'])]);

        $response->assertRedirect();
        $response->assertSessionHas('import_errors');
        $this->assertSame(1, DB::table('siswas')->count());
        $this->assertSame(0, DB::table('siswa_kelas_semester')->count());
    }

    public function test_duplicate_nis_or_nisn_inside_uploaded_file_is_rejected(): void
    {
        $response = $this->postImport([
            $this->validRow(['nama' => 'Siswa Satu']),
            $this->validRow(['nama' => 'Siswa Dua', 'nisn' => '9900000002']),
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('import_errors');
        $this->assertSame(0, DB::table('siswas')->count());
        $this->assertSame(0, DB::table('siswa_kelas_semester')->count());
    }

    public function test_invalid_or_missing_kelas_reports_row_error_and_imports_nothing(): void
    {
        $response = $this->postImport([
            $this->validRow(['nama' => 'Valid But Must Roll Back', 'nis' => '2601002', 'nisn' => '9900000002']),
            $this->validRow(['nama' => 'Invalid Class', 'nis' => '2601003', 'nisn' => '9900000003', 'kelas' => 'Format Salah']),
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('import_errors');
        $this->assertSame(0, DB::table('siswas')->count());
        $this->assertSame(0, DB::table('siswa_kelas_semester')->count());
    }

    public function test_missing_required_columns_are_reported_clearly(): void
    {
        $response = $this->postImportWithHeaders(
            ['nis', 'nisn', 'nama', 'tanggal_lahir', 'jenis_kelamin', 'agama', 'alamat'],
            [[
                '2601001',
                '9900000001',
                'Siswa Aman',
                '2015-01-15',
                'Laki-laki',
                'Islam',
                'Jalan Aman',
            ]]
        );

        $response->assertRedirect();
        $response->assertSessionHas('import_errors');
        $this->assertSame(0, DB::table('siswas')->count());
    }

    public function test_invalid_birth_date_is_reported_clearly(): void
    {
        $response = $this->postImport([
            $this->validRow(['tanggal_lahir' => 'bukan tanggal']),
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('import_errors');
        $this->assertSame(0, DB::table('siswas')->count());
        $this->assertSame(0, DB::table('siswa_kelas_semester')->count());
    }

    public function test_blank_rows_are_skipped_without_creating_extra_students(): void
    {
        $response = $this->postImport([
            $this->validRow(),
            array_fill_keys($this->headers(), null),
        ]);

        $response->assertRedirect(route('student'));
        $this->assertSame(1, DB::table('siswas')->count());
        $this->assertSame(1, DB::table('siswa_kelas_semester')->count());
    }

    public function test_import_with_one_invalid_row_creates_no_students_or_enrollments(): void
    {
        $response = $this->postImport([
            $this->validRow(['nama' => 'Valid But Atomic', 'nis' => '2601004', 'nisn' => '9900000004']),
            $this->validRow(['nama' => 'Missing Class', 'nis' => '2601005', 'nisn' => '9900000005', 'kelas' => null]),
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('import_errors');
        $this->assertSame(0, DB::table('siswas')->count());
        $this->assertSame(0, DB::table('siswa_kelas_semester')->count());
    }

    public function test_import_logs_no_raw_student_row_or_sensitive_family_data(): void
    {
        $logger = new class implements LoggerInterface
        {
            /**
             * @var array<int, array{level: mixed, message: mixed, context: array<string, mixed>}>
             */
            public array $entries = [];

            public function emergency(\Stringable|string $message, array $context = []): void
            {
                $this->log('emergency', $message, $context);
            }

            public function alert(\Stringable|string $message, array $context = []): void
            {
                $this->log('alert', $message, $context);
            }

            public function critical(\Stringable|string $message, array $context = []): void
            {
                $this->log('critical', $message, $context);
            }

            public function error(\Stringable|string $message, array $context = []): void
            {
                $this->log('error', $message, $context);
            }

            public function warning(\Stringable|string $message, array $context = []): void
            {
                $this->log('warning', $message, $context);
            }

            public function notice(\Stringable|string $message, array $context = []): void
            {
                $this->log('notice', $message, $context);
            }

            public function info(\Stringable|string $message, array $context = []): void
            {
                $this->log('info', $message, $context);
            }

            public function debug(\Stringable|string $message, array $context = []): void
            {
                $this->log('debug', $message, $context);
            }

            public function log($level, \Stringable|string $message, array $context = []): void
            {
                $this->entries[] = compact('level', 'message', 'context');
            }
        };

        Log::swap($logger);

        $this->postImport([
            $this->validRow([
                'nis' => '2601777',
                'nisn' => '9900000777',
                'alamat' => 'Jalan Rahasia Anak',
                'nama_ayah' => 'Ayah Rahasia',
                'nama_ibu' => 'Ibu Rahasia',
            ]),
        ]);

        $payload = json_encode($logger->entries, JSON_UNESCAPED_UNICODE);

        foreach (['2601777', '9900000777', 'Jalan Rahasia Anak', 'Ayah Rahasia', 'Ibu Rahasia'] as $sensitiveValue) {
            $this->assertStringNotContainsString($sensitiveValue, $payload);
        }
    }

    public function test_imported_students_are_visible_to_enrollment_aware_admin_roster(): void
    {
        $this->postImport([$this->validRow(['nama' => 'Siswa Roster'])]);

        $siswa = DB::table('siswas')->where('nama', 'Siswa Roster')->first();
        $this->assertNotNull($siswa);
        $this->assertDatabaseHas('siswa_kelas_semester', [
            'siswa_id' => $siswa->id,
            'kelas_id' => $this->activeClassId,
            'tahun_ajaran_id' => $this->activeYearId,
            'semester' => 1,
        ]);

        $response = $this->actingAs($this->admin, 'web')
            ->withSession(['tahun_ajaran_id' => $this->activeYearId, 'selected_semester' => 1])
            ->get(route('student', ['search' => 'Roster']));

        $response->assertOk();
        $response->assertSee('Siswa Roster');
    }

    public function test_direct_student_upload_route_does_not_point_to_missing_view(): void
    {
        $response = $this->actingAs($this->admin, 'web')
            ->withSession(['tahun_ajaran_id' => $this->activeYearId, 'selected_semester' => 1])
            ->get(route('student.upload'));

        $response->assertOk();
        $response->assertSee('Upload');
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function postImport(array $rows)
    {
        return $this->postImportWithHeaders($this->headers(), array_map(function (array $row) {
            return array_map(fn ($header) => $row[$header] ?? null, $this->headers());
        }, $rows));
    }

    /**
     * @param  array<int, string>  $headers
     * @param  array<int, array<int, mixed>>  $rows
     */
    private function postImportWithHeaders(array $headers, array $rows)
    {
        $path = $this->createWorkbook($headers, $rows);

        return $this->actingAs($this->admin, 'web')
            ->withSession(['tahun_ajaran_id' => $this->activeYearId, 'selected_semester' => 1])
            ->post(route('student.import'), [
                'file' => new UploadedFile(
                    $path,
                    'student-import.xlsx',
                    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                    null,
                    true
                ),
            ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function validRow(array $overrides = []): array
    {
        return array_merge([
            'nis' => '2601001',
            'nisn' => '9900000001',
            'nama' => 'Siswa Aman',
            'tanggal_lahir' => '2015-01-15',
            'jenis_kelamin' => 'Laki-laki',
            'agama' => 'Islam',
            'alamat' => 'Jalan Aman',
            'kelas' => '1 - Ubay',
            'nama_ayah' => 'Ayah Aman',
            'nama_ibu' => 'Ibu Aman',
            'pekerjaan_ayah' => 'Wiraswasta',
            'pekerjaan_ibu' => 'Ibu Rumah Tangga',
            'alamat_orangtua' => 'Jalan Orang Tua',
            'photo' => null,
        ], $overrides);
    }

    /**
     * @return array<int, string>
     */
    private function headers(): array
    {
        return [
            'nis',
            'nisn',
            'nama',
            'tanggal_lahir',
            'jenis_kelamin',
            'agama',
            'alamat',
            'kelas',
            'nama_ayah',
            'nama_ibu',
            'pekerjaan_ayah',
            'pekerjaan_ibu',
            'alamat_orangtua',
            'photo',
        ];
    }

    /**
     * @param  array<int, string>  $headers
     * @param  array<int, array<int, mixed>>  $rows
     */
    private function createWorkbook(array $headers, array $rows): string
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->fromArray($headers);

        foreach ($rows as $index => $row) {
            $sheet->fromArray($row, null, 'A'.($index + 2));
        }

        $directory = storage_path('framework/testing');
        File::ensureDirectoryExists($directory);
        $path = $directory.'/student-import-'.uniqid('', true).'.xlsx';
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
            'gurus',
            'audit_logs',
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
            $table->string('user_agent')->nullable();
            $table->timestamps();
        });

        Schema::create('gurus', function (Blueprint $table) {
            $table->id();
            $table->string('nama');
            $table->string('username')->nullable();
            $table->string('email')->nullable();
            $table->string('password')->nullable();
            $table->string('jabatan')->nullable();
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

        Schema::create('mata_pelajarans', function (Blueprint $table) {
            $table->id();
            $table->string('nama_pelajaran');
            $table->foreignId('kelas_id')->nullable();
            $table->foreignId('guru_id')->nullable();
            $table->foreignId('tahun_ajaran_id')->nullable();
            $table->unsignedTinyInteger('semester')->nullable();
            $table->boolean('is_muatan_lokal')->default(false);
            $table->boolean('allow_non_wali')->default(false);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('siswas', function (Blueprint $table) {
            $table->id();
            $table->string('nis');
            $table->string('nisn');
            $table->string('nama');
            $table->date('tanggal_lahir')->nullable();
            $table->string('jenis_kelamin')->nullable();
            $table->string('agama')->nullable();
            $table->text('alamat')->nullable();
            $table->foreignId('kelas_id')->nullable();
            $table->foreignId('tahun_ajaran_id')->nullable();
            $table->string('nama_ayah')->nullable();
            $table->string('nama_ibu')->nullable();
            $table->string('pekerjaan_ayah')->nullable();
            $table->string('pekerjaan_ibu')->nullable();
            $table->text('alamat_orangtua')->nullable();
            $table->string('photo')->nullable();
            $table->string('wali_siswa')->nullable();
            $table->string('pekerjaan_wali')->nullable();
            $table->string('status')->default('aktif');
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
            $table->unique(['siswa_id', 'tahun_ajaran_id', 'semester']);
            $table->index(['kelas_id', 'tahun_ajaran_id', 'semester']);
        });
    }

    private function seedFixture(): void
    {
        $this->admin = User::create([
            'name' => 'Admin',
            'username' => 'admin',
            'email' => 'admin@example.test',
            'password' => Hash::make('password'),
        ]);

        DB::table('profil_sekolah')->insert([
            'nama_sekolah' => 'SDIT Al Hidayah',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->activeYearId = DB::table('tahun_ajarans')->insertGetId([
            'tahun_ajaran' => '2026/2027',
            'is_active' => true,
            'tanggal_mulai' => '2026-07-01',
            'tanggal_selesai' => '2026-12-31',
            'semester' => 1,
            'deskripsi' => 'Active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->otherYearId = DB::table('tahun_ajarans')->insertGetId([
            'tahun_ajaran' => '2025/2026',
            'is_active' => false,
            'tanggal_mulai' => '2025-07-01',
            'tanggal_selesai' => '2025-12-31',
            'semester' => 1,
            'deskripsi' => 'Other',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->activeClassId = DB::table('kelas')->insertGetId([
            'nomor_kelas' => '1',
            'nama_kelas' => 'Ubay',
            'tahun_ajaran_id' => $this->activeYearId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->otherYearClassId = DB::table('kelas')->insertGetId([
            'nomor_kelas' => '1',
            'nama_kelas' => 'Ubay',
            'tahun_ajaran_id' => $this->otherYearId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function insertExistingStudent(string $nis, string $nisn, string $name): int
    {
        return DB::table('siswas')->insertGetId([
            'nis' => $nis,
            'nisn' => $nisn,
            'nama' => $name,
            'tanggal_lahir' => '2015-01-01',
            'jenis_kelamin' => 'Laki-laki',
            'agama' => 'Islam',
            'alamat' => 'Existing Address',
            'kelas_id' => $this->activeClassId,
            'tahun_ajaran_id' => $this->activeYearId,
            'nama_ayah' => 'Existing Father',
            'nama_ibu' => 'Existing Mother',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}

<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\StudentImportTemplateService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use PhpOffice\PhpSpreadsheet\Cell\DataValidation;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

class StudentTemplateDownloadTest extends TestCase
{
    private User $admin;

    private int $activeYearId;

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

    public function test_template_downloads_successfully_with_active_year_and_expected_sheets(): void
    {
        $response = $this->actingAs($this->admin, 'web')->get(route('student.template'));

        $response->assertOk();
        $response->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');

        $workbook = $this->workbookFromResponse($response);

        $this->assertSame(['Template Siswa', 'Daftar Kelas', 'Petunjuk'], $workbook->getSheetNames());
    }

    public function test_template_contains_expected_headers(): void
    {
        $workbook = $this->workbookFromResponse(
            $this->actingAs($this->admin, 'web')->get(route('student.template'))
        );

        $headers = $workbook->getSheetByName('Template Siswa')->rangeToArray('A1:N1')[0];

        $this->assertSame(StudentImportTemplateService::HEADERS, $headers);
    }

    public function test_template_includes_only_active_year_classes_and_dropdown_validation(): void
    {
        $workbook = $this->workbookFromResponse(
            $this->actingAs($this->admin, 'web')->get(route('student.template'))
        );

        $classSheet = $workbook->getSheetByName('Daftar Kelas');
        $classLabels = collect($classSheet->rangeToArray('A2:A10'))
            ->flatten()
            ->filter()
            ->values()
            ->all();

        $this->assertContains('Kelas 1A', $classLabels);
        $this->assertContains('Kelas 2 Abu Ubaidah', $classLabels);
        $this->assertNotContains('Kelas 6 Alumni', $classLabels);

        $validation = $workbook->getSheetByName('Template Siswa')->getCell('H2')->getDataValidation();

        $this->assertSame(DataValidation::TYPE_LIST, $validation->getType());
        $this->assertStringContainsString('Daftar Kelas', $validation->getFormula1());
    }

    public function test_template_download_handles_active_year_with_no_classes(): void
    {
        DB::table('kelas')->where('tahun_ajaran_id', $this->activeYearId)->delete();

        $workbook = $this->workbookFromResponse(
            $this->actingAs($this->admin, 'web')->get(route('student.template'))
        );

        $this->assertSame('kelas', $workbook->getSheetByName('Daftar Kelas')->getCell('A1')->getValue());

        $instructionText = collect($workbook->getSheetByName('Petunjuk')->rangeToArray('A1:A20'))
            ->flatten()
            ->filter()
            ->implode("\n");

        $this->assertStringContainsString('belum ada kelas pada tahun ajaran aktif', $instructionText);
        $this->assertStringContainsString('Buat kelas terlebih dahulu sebelum import', $instructionText);
    }

    public function test_template_download_returns_clear_error_without_active_year(): void
    {
        DB::table('tahun_ajarans')->update(['is_active' => false]);

        $response = $this->actingAs($this->admin, 'web')->get(route('student.template'));

        $response->assertRedirect();
        $response->assertSessionHas('error', 'Tidak ada tahun ajaran aktif. Buat tahun ajaran aktif terlebih dahulu.');
    }

    private function workbookFromResponse($response)
    {
        $directory = storage_path('framework/testing');
        File::ensureDirectoryExists($directory);
        $path = $directory.'/student-template-'.uniqid('', true).'.xlsx';

        file_put_contents($path, $response->streamedContent());
        $this->workbooks[] = $path;

        return IOFactory::load($path);
    }

    private function createSchema(): void
    {
        foreach (['kelas', 'profil_sekolah', 'tahun_ajarans', 'users'] as $table) {
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

        Schema::create('kelas', function (Blueprint $table) {
            $table->id();
            $table->string('nomor_kelas');
            $table->string('nama_kelas');
            $table->foreignId('tahun_ajaran_id')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    private function seedFixture(): void
    {
        $adminId = DB::table('users')->insertGetId([
            'name' => 'Admin',
            'username' => 'admin',
            'email' => 'admin@example.test',
            'password' => Hash::make('password'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->admin = User::findOrFail($adminId);

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

        $inactiveYearId = DB::table('tahun_ajarans')->insertGetId([
            'tahun_ajaran' => '2025/2026',
            'is_active' => false,
            'tanggal_mulai' => '2025-07-01',
            'tanggal_selesai' => '2025-12-31',
            'semester' => 1,
            'deskripsi' => 'Inactive',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('kelas')->insert([
            [
                'nomor_kelas' => '1',
                'nama_kelas' => 'A',
                'tahun_ajaran_id' => $this->activeYearId,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'nomor_kelas' => '2',
                'nama_kelas' => 'abu ubaidah',
                'tahun_ajaran_id' => $this->activeYearId,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'nomor_kelas' => '6',
                'nama_kelas' => 'Alumni',
                'tahun_ajaran_id' => $inactiveYearId,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
    }
}

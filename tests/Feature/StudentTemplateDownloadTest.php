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
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Cell\DataValidation;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
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

    public function test_template_includes_only_active_year_classes_with_canonical_labels_and_dropdown_validation(): void
    {
        $workbook = $this->workbookFromResponse(
            $this->actingAs($this->admin, 'web')->get(route('student.template'))
        );

        $classSheet = $workbook->getSheetByName('Daftar Kelas');
        $classRows = collect($classSheet->rangeToArray('A2:C10'))
            ->filter(fn (array $row) => filled($row[0] ?? null))
            ->map(fn (array $row) => array_map(fn ($value) => (string) $value, $row))
            ->values()
            ->all();
        $classLabels = collect($classRows)->pluck(0)->all();

        $this->assertContains(['Kelas 1 A', '1', 'A'], $classRows);
        $this->assertContains(['Kelas 2 a', '2', 'a'], $classRows);
        $this->assertContains(['Kelas 1 AA', '1', 'AA'], $classRows);
        $this->assertNotContains('Kelas 1A', $classLabels);
        $this->assertNotContains('Kelas 1 Aa', $classLabels);
        $this->assertNotContains('Kelas 6 Alumni', $classLabels);

        $templateSheet = $workbook->getSheetByName('Template Siswa');
        $validation = $templateSheet->getCell('H2')->getDataValidation();

        $this->assertSame(DataValidation::TYPE_LIST, $validation->getType());
        $this->assertSame("'Daftar Kelas'!".'$A$2:$A$4', $validation->getFormula1());
        $this->assertSame($classSheet->getCell('A2')->getValue(), $templateSheet->getCell('H2')->getValue());
        $this->assertSame($classSheet->getCell('A3')->getValue(), $templateSheet->getCell('H3')->getValue());
    }

    public function test_template_formats_nis_and_nisn_as_text_and_documents_ten_digit_limit(): void
    {
        $workbook = $this->workbookFromResponse(
            $this->actingAs($this->admin, 'web')->get(route('student.template'))
        );

        $templateSheet = $workbook->getSheetByName('Template Siswa');
        $instructionText = collect($workbook->getSheetByName('Petunjuk')->rangeToArray('A1:A20'))
            ->flatten()
            ->filter()
            ->implode("\n");

        $this->assertSame(NumberFormat::FORMAT_TEXT, $templateSheet->getStyle('A2')->getNumberFormat()->getFormatCode());
        $this->assertSame(NumberFormat::FORMAT_TEXT, $templateSheet->getStyle('B2')->getNumberFormat()->getFormatCode());
        $this->assertSame(DataType::TYPE_STRING, $templateSheet->getCell('A2')->getDataType());
        $this->assertSame(DataType::TYPE_STRING, $templateSheet->getCell('B2')->getDataType());
        $this->assertStringContainsString('maksimal 10 digit angka', $instructionText);
        $this->assertStringContainsString('disimpan sebagai teks', $instructionText);
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
                'nama_kelas' => 'a',
                'tahun_ajaran_id' => $this->activeYearId,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'nomor_kelas' => '1',
                'nama_kelas' => 'AA',
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

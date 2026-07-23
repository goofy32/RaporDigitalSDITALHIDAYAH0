<?php

namespace Tests\Feature;

use App\Models\Kelas;
use App\Services\SpreadsheetImportGuard;
use DomainException;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
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

    public function test_import_accepts_one_to_ten_digit_identifiers_and_preserves_text_leading_zero(): void
    {
        $rows = [
            $this->validRow([
                'nis' => '1',
                'nisn' => '0012345678',
                'nama' => 'Siswa Nol Depan',
            ]),
            $this->validRow([
                'nis' => '1234567890',
                'nisn' => '0000000001',
                'nama' => 'Siswa Sepuluh Digit',
            ]),
        ];

        $response = $this->postImport($rows);

        $response->assertRedirect(route('student'));
        $this->assertDatabaseHas('siswas', [
            'nis' => '1',
            'nisn' => '0012345678',
            'nama' => 'Siswa Nol Depan',
        ]);
        $this->assertDatabaseHas('siswas', [
            'nis' => '1234567890',
            'nisn' => '0000000001',
            'nama' => 'Siswa Sepuluh Digit',
        ]);
    }

    public function test_import_accepts_numeric_integer_identifier_cells_as_digit_strings(): void
    {
        $response = $this->postImport([
            $this->validRow([
                'nis' => 1234567890,
                'nisn' => 123456789,
                'nama' => 'Siswa Numeric Cell',
            ]),
        ]);

        $response->assertRedirect(route('student'));
        $this->assertDatabaseHas('siswas', [
            'nis' => '1234567890',
            'nisn' => '123456789',
            'nama' => 'Siswa Numeric Cell',
        ]);
    }

    public function test_import_rejects_identifier_values_longer_than_ten_digits_or_non_digits(): void
    {
        $response = $this->postImport([
            $this->validRow(['nis' => '12345678901', 'nisn' => '9000000001', 'nama' => 'NIS Terlalu Panjang']),
            $this->validRow(['nis' => 'ABC123', 'nisn' => '9000000002', 'nama' => 'NIS Huruf']),
            $this->validRow(['nis' => '12 345', 'nisn' => '9000000003', 'nama' => 'NIS Spasi']),
            $this->validRow(['nis' => '2601010', 'nisn' => '12345678901', 'nama' => 'NISN Terlalu Panjang']),
            $this->validRow(['nis' => '2601011', 'nisn' => '123-456', 'nama' => 'NISN Simbol']),
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('import_errors');

        $errors = $this->importErrorText();
        $this->assertStringContainsString('Baris 2, NIS Terlalu Panjang: NIS maksimal 10 digit.', $errors);
        $this->assertStringContainsString('Baris 3, NIS Huruf: NIS hanya boleh berisi angka.', $errors);
        $this->assertStringContainsString('Baris 4, NIS Spasi: NIS hanya boleh berisi angka.', $errors);
        $this->assertStringContainsString('Baris 5, NISN Terlalu Panjang: NISN maksimal 10 digit.', $errors);
        $this->assertStringContainsString('Baris 6, NISN Simbol: NISN hanya boleh berisi angka.', $errors);
        $this->assertSame(0, DB::table('siswas')->count());
        $this->assertSame(0, DB::table('siswa_kelas_semester')->count());
    }

    public function test_import_rejects_decimal_scientific_and_formula_identifier_cells_as_ambiguous(): void
    {
        $path = $this->createWorkbookWithCellCallbacks(function ($sheet): void {
            $this->writeImportRow($sheet, 2, $this->validRow([
                'nis' => null,
                'nisn' => '9000000101',
                'nama' => 'NIS Decimal',
            ]));
            $sheet->setCellValue('A2', 1.5);

            $this->writeImportRow($sheet, 3, $this->validRow([
                'nis' => '2601012',
                'nisn' => null,
                'nama' => 'NISN Formula',
            ]));
            $sheet->setCellValue('B3', '=CONCAT("12","34")');

            $this->writeImportRow($sheet, 4, $this->validRow([
                'nis' => '1E5',
                'nisn' => '9000000103',
                'nama' => 'NIS Scientific Text',
            ]));
        });

        $response = $this->postWorkbook($path);

        $response->assertRedirect();
        $response->assertSessionHas('import_errors');

        $errors = $this->importErrorText();
        $this->assertStringContainsString('Baris 2, NIS Decimal: NIS harus berupa teks atau angka bulat maksimal 10 digit.', $errors);
        $this->assertStringContainsString('Baris 3, NISN Formula: NISN tidak boleh berupa tanggal, formula, boolean, atau error cell.', $errors);
        $this->assertStringContainsString('Baris 4, NIS Scientific Text: NIS hanya boleh berisi angka.', $errors);
        $this->assertSame(0, DB::table('siswas')->count());
        $this->assertSame(0, DB::table('siswa_kelas_semester')->count());
    }

    public function test_import_rejects_date_boolean_and_error_identifier_cells(): void
    {
        $path = $this->createWorkbookWithCellCallbacks(function ($sheet): void {
            $this->writeImportRow($sheet, 2, $this->validRow([
                'nis' => null,
                'nisn' => '9000000201',
                'nama' => 'NIS Date',
            ]));
            $sheet->setCellValue('A2', ExcelDate::PHPToExcel(Carbon::parse('2026-07-22')));
            $sheet->getStyle('A2')->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_DATE_YYYYMMDD);
            $this->assertTrue(ExcelDate::isDateTime($sheet->getCell('A2')));

            $this->writeImportRow($sheet, 3, $this->validRow([
                'nis' => '2601020',
                'nisn' => null,
                'nama' => 'NISN Date',
            ]));
            $sheet->setCellValue('B3', ExcelDate::PHPToExcel(Carbon::parse('2026-07-23')));
            $sheet->getStyle('B3')->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_DATE_YYYYMMDD);
            $this->assertTrue(ExcelDate::isDateTime($sheet->getCell('B3')));

            $this->writeImportRow($sheet, 4, $this->validRow([
                'nis' => null,
                'nisn' => '9000000204',
                'nama' => 'NIS Boolean',
            ]));
            $sheet->setCellValue('A4', true);

            $this->writeImportRow($sheet, 5, $this->validRow([
                'nis' => '2601021',
                'nisn' => null,
                'nama' => 'NISN Boolean',
            ]));
            $sheet->setCellValue('B5', false);

            $this->writeImportRow($sheet, 6, $this->validRow([
                'nis' => null,
                'nisn' => '9000000206',
                'nama' => 'NIS Error',
            ]));
            $sheet->setCellValueExplicit('A6', '#DIV/0!', DataType::TYPE_ERROR);
        });

        $response = $this->postWorkbook($path);

        $response->assertRedirect();
        $response->assertSessionHas('import_errors');

        $errors = $this->importErrorText();
        $this->assertStringContainsString('Baris 2, NIS Date: NIS tidak boleh berupa tanggal, formula, boolean, atau error cell.', $errors);
        $this->assertStringContainsString('Baris 3, NISN Date: NISN tidak boleh berupa tanggal, formula, boolean, atau error cell.', $errors);
        $this->assertStringContainsString('Baris 4, NIS Boolean: NIS tidak boleh berupa tanggal, formula, boolean, atau error cell.', $errors);
        $this->assertStringContainsString('Baris 5, NISN Boolean: NISN tidak boleh berupa tanggal, formula, boolean, atau error cell.', $errors);
        $this->assertStringContainsString('Baris 6, NIS Error: NIS tidak boleh berupa tanggal, formula, boolean, atau error cell.', $errors);
        $this->assertSame(0, DB::table('siswas')->count());
        $this->assertSame(0, DB::table('siswa_kelas_semester')->count());
    }

    public function test_import_rejects_sparse_workbook_with_row_index_above_limit_before_processing(): void
    {
        $path = $this->createWorkbookWithCellCallbacks(function ($sheet): void {
            $this->writeImportRow(
                $sheet,
                SpreadsheetImportGuard::MAX_STUDENT_IMPORT_ROWS + 2,
                $this->validRow(['nama' => 'Sparse Attack Row'])
            );
        });

        $response = $this->postWorkbook($path);

        $response->assertRedirect();
        $response->assertSessionHas('import_errors');
        $this->assertStringContainsString('File memiliki terlalu banyak baris untuk diproses.', $this->importErrorText());
        $this->assertStringNotContainsString((string) (SpreadsheetImportGuard::MAX_STUDENT_IMPORT_ROWS + 2), $this->importErrorText());
        $this->assertSame(0, DB::table('siswas')->count());
        $this->assertSame(0, DB::table('siswa_kelas_semester')->count());
    }

    public function test_import_rejects_workbook_with_too_many_worksheets(): void
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->fromArray($this->headers());
        $this->writeImportRow($sheet, 2, $this->validRow());

        for ($index = 0; $index < 5; $index++) {
            $spreadsheet->createSheet()->setTitle("Extra {$index}");
        }

        $response = $this->postWorkbook($this->saveWorkbook($spreadsheet));

        $response->assertRedirect();
        $response->assertSessionHas('import_errors');
        $this->assertStringContainsString('Workbook memiliki terlalu banyak worksheet untuk diproses.', $this->importErrorText());
        $this->assertSame(0, DB::table('siswas')->count());
    }

    public function test_import_rejects_oversized_upload_before_parsing(): void
    {
        $response = $this->actingAs($this->admin, 'web')
            ->withSession(['tahun_ajaran_id' => $this->activeYearId, 'selected_semester' => 1])
            ->post(route('student.import'), [
                'file' => UploadedFile::fake()->create(
                    'student-import.xlsx',
                    2049,
                    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'
                ),
            ]);

        $response->assertRedirect();
        $response->assertSessionHasErrors('file');
        $this->assertSame(0, DB::table('siswas')->count());
        $this->assertSame(0, DB::table('siswa_kelas_semester')->count());
    }

    public function test_import_rejects_malformed_xlsx_without_server_error(): void
    {
        $directory = storage_path('framework/testing');
        File::ensureDirectoryExists($directory);
        $path = $directory.'/malformed-student-import-'.uniqid('', true).'.xlsx';
        File::put($path, 'not an xlsx workbook');
        $this->workbooks[] = $path;

        $response = $this->postWorkbook($path);

        $response->assertRedirect();
        $this->assertTrue(
            session()->has('errors') || session()->has('import_errors') || session()->has('error'),
            'Malformed XLSX should be rejected through validation or safe import error.'
        );
        $this->assertSame(0, DB::table('siswas')->count());
        $this->assertSame(0, DB::table('siswa_kelas_semester')->count());
    }

    public function test_spreadsheet_guard_rejects_stream_wrapper_paths_before_reader_load(): void
    {
        $guard = app(SpreadsheetImportGuard::class);

        foreach ([
            'phar://evil.xlsx',
            'php://filter/resource=evil.xlsx',
            'http://example.test/evil.xlsx',
            'https://example.test/evil.xlsx',
            'ftp://example.test/evil.xlsx',
            'data://text/plain,abc',
            'zip://evil.xlsx#xl/workbook.xml',
            'file:///tmp/evil.xlsx',
            'PHAR://evil.xlsx',
            'Php://filter/resource=evil.xlsx',
            'HtTp://example.test/evil.xlsx',
            '  phar://evil.xlsx  ',
        ] as $path) {
            try {
                $guard->loadXlsxFromPath($path, SpreadsheetImportGuard::PROFILE_STUDENT);
                $this->fail("Path {$path} should have been rejected.");
            } catch (DomainException $exception) {
                $this->assertSame('Format file tidak didukung. Gunakan file Excel XLSX dari template aplikasi.', $exception->getMessage());
            }
        }
    }

    public function test_spreadsheet_guard_scheme_detection_does_not_block_windows_local_paths(): void
    {
        $guard = app(SpreadsheetImportGuard::class);
        $method = new \ReflectionMethod(SpreadsheetImportGuard::class, 'hasDisallowedStreamScheme');
        $method->setAccessible(true);

        $this->assertFalse($method->invoke($guard, 'C:\Users\Tahrir\import.xlsx'));
        $this->assertFalse($method->invoke($guard, 'C:/Users/Tahrir/import.xlsx'));
        $this->assertFalse($method->invoke($guard, '\\\\server\\share\\import.xlsx'));
        $this->assertTrue($method->invoke($guard, 'file:///C:/Users/Tahrir/import.xlsx'));
        $this->assertTrue($method->invoke($guard, 'PHAR://evil.xlsx'));
    }

    public function test_spreadsheet_guard_rejects_invalid_zip_metadata_without_divide_by_zero(): void
    {
        $guard = app(SpreadsheetImportGuard::class);
        $sizeMethod = new \ReflectionMethod(SpreadsheetImportGuard::class, 'zipStatSize');
        $sizeMethod->setAccessible(true);
        $ratioMethod = new \ReflectionMethod(SpreadsheetImportGuard::class, 'assertZipCompressionRatio');
        $ratioMethod->setAccessible(true);

        $this->assertSame(12, $sizeMethod->invoke($guard, ['size' => '12'], 'size'));
        $this->assertSame(0, $sizeMethod->invoke($guard, ['comp_size' => 0], 'comp_size'));
        $ratioMethod->invoke($guard, 0, 0, 100);
        $ratioMethod->invoke($guard, 2 * 1048576, 2 * 1048576, 100);

        foreach ([
            [['size' => -1], 'size'],
            [['size' => '-1'], 'size'],
            [['size' => 1.5], 'size'],
            [['size' => (string) PHP_INT_MAX.'0'], 'size'],
            [[], 'size'],
        ] as [$stat, $key]) {
            try {
                $sizeMethod->invoke($guard, $stat, $key);
                $this->fail('Invalid ZIP metadata should have been rejected.');
            } catch (\ReflectionException $exception) {
                throw $exception;
            } catch (\Throwable $exception) {
                $this->assertInstanceOf(DomainException::class, $exception->getPrevious() ?? $exception);
            }
        }

        foreach ([[1, 0], [1, -1], [2 * 1048576, 1]] as [$size, $compressedSize]) {
            try {
                $ratioMethod->invoke($guard, $size, $compressedSize, 100);
                $this->fail('Suspicious compression metadata should have been rejected.');
            } catch (\ReflectionException $exception) {
                throw $exception;
            } catch (\Throwable $exception) {
                $this->assertInstanceOf(DomainException::class, $exception->getPrevious() ?? $exception);
            }
        }
    }

    public function test_import_accepts_canonical_and_legacy_class_aliases(): void
    {
        $classId = $this->insertActiveClass('1', 'A');
        $aliases = [
            'Kelas 1 A',
            'Kelas 1A',
            'kelas 1 a',
            '1 A',
            '1A',
            '1 - A',
        ];

        $rows = collect($aliases)
            ->map(fn (string $alias, int $index) => $this->validRow([
                'nis' => '26011'.str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT),
                'nisn' => '99000001'.str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT),
                'nama' => 'Siswa Alias '.($index + 1),
                'kelas' => $alias,
            ]))
            ->all();

        $response = $this->postImport($rows);

        $response->assertRedirect(route('student'));
        $this->assertSame(count($aliases), DB::table('siswas')->count());
        $this->assertSame(count($aliases), DB::table('siswa_kelas_semester')->count());

        foreach ($rows as $row) {
            $siswa = DB::table('siswas')->where('nis', $row['nis'])->first();

            $this->assertNotNull($siswa);
            $this->assertSame($classId, (int) $siswa->kelas_id);
            $this->assertDatabaseHas('siswa_kelas_semester', [
                'siswa_id' => $siswa->id,
                'kelas_id' => $classId,
                'tahun_ajaran_id' => $this->activeYearId,
                'semester' => 1,
            ]);
        }
    }

    public function test_import_preserves_lowercase_class_name_matching_while_accepting_legacy_uppercase_alias(): void
    {
        $classId = $this->insertActiveClass('2', 'a');
        $rows = [
            $this->validRow([
                'nis' => '2601201',
                'nisn' => '9900000201',
                'nama' => 'Siswa Canonical Lowercase',
                'kelas' => 'Kelas 2 a',
            ]),
            $this->validRow([
                'nis' => '2601202',
                'nisn' => '9900000202',
                'nama' => 'Siswa Legacy Uppercase',
                'kelas' => 'Kelas 2A',
            ]),
        ];

        $response = $this->postImport($rows);

        $response->assertRedirect(route('student'));

        foreach ($rows as $row) {
            $siswa = DB::table('siswas')->where('nis', $row['nis'])->first();

            $this->assertNotNull($siswa);
            $this->assertSame($classId, (int) $siswa->kelas_id);
            $this->assertDatabaseHas('siswa_kelas_semester', [
                'siswa_id' => $siswa->id,
                'kelas_id' => $classId,
                'tahun_ajaran_id' => $this->activeYearId,
                'semester' => 1,
            ]);
        }
    }

    public function test_import_rejects_ambiguous_class_aliases_without_writing_students_or_enrollments(): void
    {
        $this->insertActiveClass('1', 'A');
        $this->insertActiveClass('1', ' a ');
        $classCount = DB::table('kelas')->count();

        $response = $this->postImport([
            $this->validRow(['kelas' => 'Kelas 1A']),
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('import_errors');
        $this->assertStringContainsString(
            'Baris 2, Siswa Aman: kelas "Kelas 1A" ambigu karena cocok dengan lebih dari satu data kelas. Periksa data kelas pada tahun ajaran aktif.',
            $this->importErrorText()
        );
        $this->assertSame(0, DB::table('siswas')->count());
        $this->assertSame(0, DB::table('siswa_kelas_semester')->count());
        $this->assertSame($classCount, DB::table('kelas')->count());
    }

    public function test_import_ignores_matching_soft_deleted_class_as_not_found(): void
    {
        $classId = $this->insertActiveClass('3', 'Zaid');
        $this->softDeleteClass($classId);

        $response = $this->postImport([
            $this->validRow([
                'kelas' => 'Kelas 3 Zaid',
                'nis' => '2601301',
                'nisn' => '9900000301',
                'nama' => 'Siswa Kelas Terhapus',
            ]),
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('import_errors');
        $this->assertStringContainsString(
            'Baris 2, Siswa Kelas Terhapus: kelas "Kelas 3 Zaid" tidak ditemukan. Gunakan nama kelas sesuai template.',
            $this->importErrorText()
        );
        $this->assertStringNotContainsString('ambigu', $this->importErrorText());
        $this->assertSame(0, DB::table('siswas')->count());
        $this->assertSame(0, DB::table('siswa_kelas_semester')->count());
        $this->assertNotNull(Kelas::withTrashed()->findOrFail($classId)->deleted_at);
    }

    public function test_import_uses_active_class_when_matching_soft_deleted_class_would_otherwise_be_ambiguous(): void
    {
        $activeClassId = $this->insertActiveClass('4', 'B');
        $deletedClassId = $this->insertActiveClass('4', ' b ');
        $this->softDeleteClass($deletedClassId);

        $response = $this->postImport([
            $this->validRow([
                'kelas' => 'Kelas 4B',
                'nis' => '2601401',
                'nisn' => '9900000401',
                'nama' => 'Siswa Kelas Aktif',
            ]),
        ]);

        $response->assertRedirect(route('student'));
        $siswa = DB::table('siswas')->where('nis', '2601401')->first();

        $this->assertNotNull($siswa);
        $this->assertSame($activeClassId, (int) $siswa->kelas_id);
        $this->assertDatabaseHas('siswa_kelas_semester', [
            'siswa_id' => $siswa->id,
            'kelas_id' => $activeClassId,
            'tahun_ajaran_id' => $this->activeYearId,
            'semester' => 1,
        ]);
        $this->assertNotNull(Kelas::withTrashed()->findOrFail($deletedClassId)->deleted_at);
    }

    public function test_import_supports_compact_multi_character_class_aliases_without_splitting_two_digit_numbers(): void
    {
        $cases = [
            ['alias' => '1AA', 'number' => '1', 'name' => 'AA', 'nis' => '2601501', 'nisn' => '9900000501'],
            ['alias' => '10A', 'number' => '10', 'name' => 'A', 'nis' => '2601502', 'nisn' => '9900000502'],
            ['alias' => '10AA', 'number' => '10', 'name' => 'AA', 'nis' => '2601503', 'nisn' => '9900000503'],
        ];

        $classIdsByNis = [];
        foreach ($cases as $case) {
            $classIdsByNis[$case['nis']] = $this->insertActiveClass($case['number'], $case['name']);
        }

        $rows = collect($cases)
            ->map(fn (array $case) => $this->validRow([
                'nis' => $case['nis'],
                'nisn' => $case['nisn'],
                'nama' => 'Siswa Compact '.$case['alias'],
                'kelas' => $case['alias'],
            ]))
            ->all();

        $response = $this->postImport($rows);

        $response->assertRedirect(route('student'));

        foreach ($rows as $row) {
            $siswa = DB::table('siswas')->where('nis', $row['nis'])->first();
            $expectedClassId = $classIdsByNis[$row['nis']];

            $this->assertNotNull($siswa);
            $this->assertSame($expectedClassId, (int) $siswa->kelas_id);
            $this->assertDatabaseHas('siswa_kelas_semester', [
                'siswa_id' => $siswa->id,
                'kelas_id' => $expectedClassId,
                'tahun_ajaran_id' => $this->activeYearId,
                'semester' => 1,
            ]);
        }
    }

    public function test_existing_database_nis_is_rejected_atomically(): void
    {
        $this->insertExistingStudent('2601001', '9900000009', 'Existing NIS');

        $response = $this->postImport([$this->validRow(['nisn' => '9900000010'])]);

        $response->assertRedirect();
        $response->assertSessionHas('import_errors');
        $this->assertStringContainsString('Baris 2, Siswa Aman: NIS 2601001 sudah digunakan siswa lain.', $this->importErrorText());
        $this->assertSame(1, DB::table('siswas')->count());
        $this->assertSame(0, DB::table('siswa_kelas_semester')->count());
    }

    public function test_existing_database_nisn_is_rejected_atomically(): void
    {
        $this->insertExistingStudent('2601999', '9900000001', 'Existing NISN');

        $response = $this->postImport([$this->validRow(['nis' => '2601010'])]);

        $response->assertRedirect();
        $response->assertSessionHas('import_errors');
        $this->assertStringContainsString('Baris 2, Siswa Aman: NISN 9900000001 sudah digunakan siswa lain.', $this->importErrorText());
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
        $this->assertStringContainsString('Baris 3, Siswa Dua: NIS 2601001 muncul lebih dari satu kali dalam file.', $this->importErrorText());
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
        $this->assertStringContainsString('Baris 3, Invalid Class: kelas "Format Salah" tidak ditemukan. Gunakan nama kelas sesuai template.', $this->importErrorText());
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
        $this->assertSame('Format template tidak sesuai atau sudah berubah. Silakan download ulang template siswa terbaru.', $this->importErrorText());
        $this->assertStringNotContainsString('Kolom wajib tidak ditemukan', $this->importErrorText());
        $this->assertStringNotContainsString('kelas_id', $this->importErrorText());
        $this->assertSame(0, DB::table('siswas')->count());
    }

    public function test_invalid_birth_date_is_reported_clearly(): void
    {
        $response = $this->postImport([
            $this->validRow(['tanggal_lahir' => 'bukan tanggal']),
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('import_errors');
        $this->assertStringContainsString('Baris 2, Siswa Aman: tanggal lahir harus menggunakan format YYYY-MM-DD, contoh 2017-05-21.', $this->importErrorText());
        $this->assertStringNotContainsString('tanggal_lahir', $this->importErrorText());
        $this->assertSame(0, DB::table('siswas')->count());
        $this->assertSame(0, DB::table('siswa_kelas_semester')->count());
    }

    public function test_import_errors_use_friendly_row_field_messages_without_raw_columns(): void
    {
        $response = $this->postImport([
            $this->validRow([
                'nis' => null,
                'nama' => 'Ahmad Fajar',
                'tanggal_lahir' => 'bukan tanggal',
                'kelas' => '1 Ubayy',
            ]),
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('import_errors');

        $errors = $this->importErrorText();
        $this->assertStringContainsString('Baris 2, Ahmad Fajar: NIS belum diisi.', $errors);
        $this->assertStringContainsString('Baris 2, Ahmad Fajar: kelas "1 Ubayy" tidak ditemukan. Gunakan nama kelas sesuai template.', $errors);
        $this->assertStringContainsString('Baris 2, Ahmad Fajar: tanggal lahir harus menggunakan format YYYY-MM-DD, contoh 2017-05-21.', $errors);
        $this->assertStringNotContainsString('kolom nis', strtolower($errors));
        $this->assertStringNotContainsString('tanggal_lahir', $errors);
        $this->assertStringNotContainsString('kelas_id', $errors);
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

    public function test_template_gender_codes_are_imported_as_existing_gender_values(): void
    {
        $response = $this->postImport([
            $this->validRow(['jenis_kelamin' => 'L']),
            $this->validRow([
                'nis' => '2601002',
                'nisn' => '9900000002',
                'nama' => 'Siswa Perempuan',
                'jenis_kelamin' => 'P',
            ]),
        ]);

        $response->assertRedirect(route('student'));
        $this->assertSame('Laki-laki', DB::table('siswas')->where('nis', '2601001')->value('jenis_kelamin'));
        $this->assertSame('Perempuan', DB::table('siswas')->where('nis', '2601002')->value('jenis_kelamin'));
    }

    public function test_import_with_one_invalid_row_creates_no_students_or_enrollments(): void
    {
        $response = $this->postImport([
            $this->validRow(['nama' => 'Valid But Atomic', 'nis' => '2601004', 'nisn' => '9900000004']),
            $this->validRow(['nama' => 'Missing Class', 'nis' => '2601005', 'nisn' => '9900000005', 'kelas' => null]),
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('import_errors');
        $this->assertStringContainsString('Baris 3, Missing Class: Kelas belum diisi.', $this->importErrorText());
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

    public function test_student_index_displays_friendly_import_error_details(): void
    {
        $response = $this->actingAs($this->admin, 'web')
            ->withSession([
                'tahun_ajaran_id' => $this->activeYearId,
                'selected_semester' => 1,
                'error' => 'Import siswa dibatalkan. Periksa daftar kesalahan pada file Excel.',
                'import_errors' => [
                    'Baris 8: NIS belum diisi.',
                    'Baris 10, Ahmad Fajar: kelas "1 Ubayy" tidak ditemukan. Gunakan nama kelas sesuai template.',
                ],
            ])
            ->get(route('student'));

        $response->assertOk();
        $response->assertSeeText('Kesalahan import:');
        $response->assertSeeText('Baris 8: NIS belum diisi.');
        $response->assertSeeText('Baris 10, Ahmad Fajar: kelas "1 Ubayy" tidak ditemukan. Gunakan nama kelas sesuai template.');
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

        return $this->postWorkbook($path);
    }

    private function postWorkbook(string $path)
    {
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

    private function importErrorText(): string
    {
        return collect(session('import_errors', []))->implode("\n");
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
            foreach ($row as $columnIndex => $value) {
                $cell = chr(65 + $columnIndex).($index + 2);

                if (is_string($value)) {
                    $sheet->setCellValueExplicit($cell, $value, DataType::TYPE_STRING);
                } else {
                    $sheet->setCellValue($cell, $value);
                }
            }
        }

        return $this->saveWorkbook($spreadsheet);
    }

    private function createWorkbookWithCellCallbacks(callable $callback): string
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->fromArray($this->headers());

        $callback($sheet);

        return $this->saveWorkbook($spreadsheet);
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function writeImportRow($sheet, int $rowNumber, array $row): void
    {
        foreach ($this->headers() as $index => $header) {
            $cell = chr(65 + $index).$rowNumber;
            $value = $row[$header] ?? null;

            if (is_string($value)) {
                $sheet->setCellValueExplicit($cell, $value, DataType::TYPE_STRING);
            } else {
                $sheet->setCellValue($cell, $value);
            }
        }
    }

    private function saveWorkbook(Spreadsheet $spreadsheet): string
    {
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
            'prestasis',
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

        Schema::create('prestasis', function (Blueprint $table) {
            $table->id();
            $table->foreignId('kelas_id')->nullable();
            $table->foreignId('siswa_id')->nullable();
            $table->foreignId('tahun_ajaran_id')->nullable();
            $table->string('jenis_prestasi')->nullable();
            $table->text('keterangan')->nullable();
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

    private function insertActiveClass(string $number, string $name): int
    {
        return DB::table('kelas')->insertGetId([
            'nomor_kelas' => $number,
            'nama_kelas' => $name,
            'tahun_ajaran_id' => $this->activeYearId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function softDeleteClass(int $classId): void
    {
        Kelas::findOrFail($classId)->delete();

        $this->assertNotNull(Kelas::withTrashed()->findOrFail($classId)->deleted_at);
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

<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;
use Tests\TestCase;
use ZipArchive;

class ReportTemplateTypeTextValidationTest extends TestCase
{
    private User $admin;

    private int $activeYearId;

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
        Event::fake();
        Storage::fake('public');

        $this->createSchema();
        $this->seedFixture();
    }

    public function test_uts_upload_passes_when_docx_contains_rapor_tengah_semester_text(): void
    {
        $this->uploadTemplate('UTS', $this->docxUpload('template-uts.docx', 'RAPOR TENGAH SEMESTER'))
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertDatabaseHas('report_templates', [
            'type' => 'UTS',
            'tahun_ajaran_id' => $this->activeYearId,
        ]);
    }

    public function test_uts_upload_is_rejected_when_docx_does_not_contain_rapor_tengah_semester_text(): void
    {
        $this->uploadTemplate('UTS', $this->docxUpload('template-uas.docx', 'RAPOR AKHIR SEMESTER'))
            ->assertUnprocessable()
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'File template tidak terdeteksi sebagai template UTS karena tidak memuat teks "RAPOR TENGAH SEMESTER". Silakan pilih file template UTS yang benar.');

        $this->assertDatabaseCount('report_templates', 0);
    }

    public function test_uas_upload_is_rejected_when_docx_contains_uts_marker_text(): void
    {
        $this->uploadTemplate('UAS', $this->docxUpload('template-uts.docx', 'RAPOR TENGAH SEMESTER'))
            ->assertUnprocessable()
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'File template terlihat sebagai template UTS karena memuat teks "RAPOR TENGAH SEMESTER", tetapi Anda memilih jenis UAS. Silakan pilih jenis UTS atau upload template UAS yang benar.');

        $this->assertDatabaseCount('report_templates', 0);
    }

    public function test_uas_upload_passes_when_docx_does_not_contain_uts_marker_text(): void
    {
        $this->uploadTemplate('UAS', $this->docxUpload('template-uas.docx', 'RAPOR AKHIR SEMESTER'))
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertDatabaseHas('report_templates', [
            'type' => 'UAS',
            'tahun_ajaran_id' => $this->activeYearId,
        ]);
    }

    public function test_uts_marker_matching_is_case_insensitive_and_tolerates_split_runs_and_spacing(): void
    {
        $this->uploadTemplate('UTS', $this->docxUploadWithSplitMarker('template-uts-split.docx'))
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertDatabaseHas('report_templates', [
            'type' => 'UTS',
            'tahun_ajaran_id' => $this->activeYearId,
        ]);
    }

    public function test_unreadable_docx_returns_friendly_error(): void
    {
        $this->uploadTemplate('UTS', $this->corruptDocxUpload('template-corrupt.docx'))
            ->assertUnprocessable()
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'File template tidak dapat dibaca. Pastikan file menggunakan format DOCX yang valid.');

        $this->assertDatabaseCount('report_templates', 0);
    }

    private function uploadTemplate(string $type, UploadedFile $template)
    {
        return $this->actingAs($this->admin, 'web')
            ->withSession([
                'tahun_ajaran_id' => $this->activeYearId,
                'selected_semester' => 1,
                'no_tahun_ajaran' => false,
            ])
            ->postJson(route('report.template.upload'), [
                'template' => $template,
                'type' => $type,
                'tahun_ajaran' => '2026/2027',
                'tahun_ajaran_id' => $this->activeYearId,
                'semester' => 1,
            ]);
    }

    private function docxUpload(string $filename, string $text): UploadedFile
    {
        return $this->uploadedDocxFromPath($filename, $this->createDocxPath($text));
    }

    private function docxUploadWithSplitMarker(string $filename): UploadedFile
    {
        $path = $this->docxTempPath();
        $phpWord = new PhpWord();
        $run = $phpWord->addSection()->addTextRun();
        $run->addText('rapor');
        $run->addText('   tengah');
        $run->addTextBreak();
        $run->addText('semester');

        IOFactory::createWriter($phpWord, 'Word2007')->save($path);

        return $this->uploadedDocxFromPath($filename, $path);
    }

    private function corruptDocxUpload(string $filename): UploadedFile
    {
        $path = $this->createDocxPath('RAPOR TENGAH SEMESTER');
        $zip = new ZipArchive();

        $this->assertTrue($zip->open($path));
        $zip->addFromString('word/document.xml', '<w:document><broken>');
        $zip->close();

        return $this->uploadedDocxFromPath($filename, $path);
    }

    private function createDocxPath(string $text): string
    {
        $path = $this->docxTempPath();
        $phpWord = new PhpWord();
        $phpWord->addSection()->addText($text);

        IOFactory::createWriter($phpWord, 'Word2007')->save($path);

        return $path;
    }

    private function docxTempPath(): string
    {
        $directory = storage_path('framework/testing/report-template-type-validation');

        if (! is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        return $directory.'/template-'.uniqid('', true).'.docx';
    }

    private function uploadedDocxFromPath(string $filename, string $path): UploadedFile
    {
        return new UploadedFile(
            $path,
            $filename,
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            null,
            true
        );
    }

    private function createSchema(): void
    {
        foreach ([
            'report_template_kelas',
            'report_templates',
            'report_placeholders',
            'profil_sekolah',
            'tahun_ajarans',
            'kelas',
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
            $table->timestamp('email_verified_at')->nullable();
            $table->rememberToken();
            $table->timestamps();
        });

        Schema::create('tahun_ajarans', function (Blueprint $table) {
            $table->id();
            $table->string('tahun_ajaran');
            $table->boolean('is_active')->default(false);
            $table->date('tanggal_mulai')->nullable();
            $table->date('tanggal_selesai')->nullable();
            $table->unsignedTinyInteger('semester')->default(1);
            $table->text('deskripsi')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('profil_sekolah', function (Blueprint $table) {
            $table->id();
            $table->string('nama_sekolah')->nullable();
            $table->string('tahun_pelajaran')->nullable();
            $table->unsignedTinyInteger('semester')->nullable();
            $table->timestamps();
        });

        Schema::create('kelas', function (Blueprint $table) {
            $table->id();
            $table->unsignedTinyInteger('nomor_kelas');
            $table->string('nama_kelas');
            $table->foreignId('tahun_ajaran_id')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('report_placeholders', function (Blueprint $table) {
            $table->id();
            $table->string('placeholder_key');
            $table->string('description')->nullable();
            $table->string('category')->nullable();
            $table->string('sample_value')->nullable();
            $table->boolean('is_required')->default(false);
            $table->timestamps();
        });

        Schema::create('report_templates', function (Blueprint $table) {
            $table->id();
            $table->string('filename');
            $table->string('path')->nullable();
            $table->string('type');
            $table->boolean('is_active')->default(false);
            $table->string('tahun_ajaran')->nullable();
            $table->string('tahun_ajaran_text')->nullable();
            $table->unsignedTinyInteger('semester')->nullable();
            $table->foreignId('kelas_id')->nullable();
            $table->foreignId('tahun_ajaran_id')->nullable();
            $table->timestamps();
        });

        Schema::create('report_template_kelas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('report_template_id');
            $table->foreignId('kelas_id');
            $table->timestamps();
        });
    }

    private function seedFixture(): void
    {
        $this->admin = User::create([
            'name' => 'Admin Test',
            'username' => 'admin_test',
            'email' => 'admin@example.test',
            'password' => Hash::make('password'),
        ]);

        $this->activeYearId = DB::table('tahun_ajarans')->insertGetId([
            'tahun_ajaran' => '2026/2027',
            'is_active' => true,
            'semester' => 1,
            'tanggal_mulai' => '2026-07-01',
            'tanggal_selesai' => '2027-06-30',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('profil_sekolah')->insert([
            'nama_sekolah' => 'SDIT Test',
            'tahun_pelajaran' => '2026/2027',
            'semester' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}

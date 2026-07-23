<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;
use Tests\TestCase;

class DefaultReportTemplateSeedCommandTest extends TestCase
{
    private User $admin;

    private int $activeYearId;

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
        Storage::fake('public');

        $this->createSchema();
        $this->seedFixture();
    }

    public function test_command_creates_default_global_uts_and_uas_templates_and_copies_files(): void
    {
        $this->artisan('initial-data:seed-default-report-templates')
            ->expectsOutputToContain('UTS: created')
            ->expectsOutputToContain('UAS: created')
            ->assertSuccessful();

        $this->assertDatabaseHas('report_templates', [
            'filename' => 'Template Default UTS.docx',
            'type' => 'UTS',
            'is_active' => true,
            'kelas_id' => null,
            'tahun_ajaran_id' => $this->activeYearId,
            'semester' => 1,
        ]);

        $this->assertDatabaseHas('report_templates', [
            'filename' => 'Template Default UAS.docx',
            'type' => 'UAS',
            'is_active' => true,
            'kelas_id' => null,
            'tahun_ajaran_id' => $this->activeYearId,
            'semester' => 1,
        ]);

        $utsPath = DB::table('report_templates')->where('type', 'UTS')->value('path');
        $uasPath = DB::table('report_templates')->where('type', 'UAS')->value('path');

        Storage::disk('public')->assertExists($utsPath);
        Storage::disk('public')->assertExists($uasPath);
    }

    public function test_command_is_idempotent_and_does_not_create_duplicates(): void
    {
        $this->artisan('initial-data:seed-default-report-templates')->assertSuccessful();
        $this->artisan('initial-data:seed-default-report-templates')
            ->expectsOutputToContain('UTS: already exists')
            ->expectsOutputToContain('UAS: already exists')
            ->assertSuccessful();

        $this->assertSame(1, DB::table('report_templates')->where('type', 'UTS')->count());
        $this->assertSame(1, DB::table('report_templates')->where('type', 'UAS')->count());
    }

    public function test_command_skips_when_custom_global_template_exists(): void
    {
        DB::table('report_templates')->insert([
            'filename' => 'Template Sekolah UTS.docx',
            'path' => 'templates/custom-uts.docx',
            'type' => 'UTS',
            'is_active' => true,
            'tahun_ajaran' => '2026/2027',
            'tahun_ajaran_text' => '2026/2027',
            'semester' => 1,
            'kelas_id' => null,
            'tahun_ajaran_id' => $this->activeYearId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->artisan('initial-data:seed-default-report-templates')
            ->expectsOutputToContain('UTS: skipped')
            ->expectsOutputToContain('UAS: created')
            ->assertSuccessful();

        $this->assertDatabaseMissing('report_templates', [
            'filename' => 'Template Default UTS.docx',
            'type' => 'UTS',
            'tahun_ajaran_id' => $this->activeYearId,
        ]);
    }

    public function test_command_fails_clearly_when_no_active_tahun_ajaran_exists(): void
    {
        DB::table('tahun_ajarans')->update(['is_active' => false]);

        $this->artisan('initial-data:seed-default-report-templates')
            ->expectsOutput('Tidak ada tahun ajaran aktif. Buat atau aktifkan tahun ajaran terlebih dahulu sebelum memasang template rapor default.')
            ->assertFailed();
    }

    public function test_command_validates_uts_marker(): void
    {
        config()->set('report.default_templates.UTS', $this->createDocxPath('RAPOR AKHIR SEMESTER'));

        $this->artisan('initial-data:seed-default-report-templates')
            ->expectsOutputToContain('File template tidak terdeteksi sebagai template UTS')
            ->assertFailed();

        $this->assertDatabaseCount('report_templates', 0);
    }

    public function test_command_validates_uas_does_not_contain_uts_marker(): void
    {
        config()->set('report.default_templates.UAS', $this->createDocxPath('RAPOR TENGAH SEMESTER'));

        $this->artisan('initial-data:seed-default-report-templates')
            ->expectsOutputToContain('UAS: File template terlihat sebagai template UTS')
            ->assertFailed();

        $this->assertDatabaseHas('report_templates', [
            'filename' => 'Template Default UTS.docx',
            'type' => 'UTS',
            'tahun_ajaran_id' => $this->activeYearId,
        ]);
        $this->assertDatabaseMissing('report_templates', [
            'filename' => 'Template Default UAS.docx',
            'type' => 'UAS',
            'tahun_ajaran_id' => $this->activeYearId,
        ]);
    }

    public function test_admin_format_rapor_page_shows_seeded_default_templates(): void
    {
        $this->artisan('initial-data:seed-default-report-templates')->assertSuccessful();

        $this->actingAs($this->admin, 'web')
            ->withSession([
                'tahun_ajaran_id' => $this->activeYearId,
                'selected_semester' => 1,
                'no_tahun_ajaran' => false,
            ])
            ->get(route('report.template.index'))
            ->assertOk()
            ->assertSee('Template Default UTS')
            ->assertSee('Template Default UAS')
            ->assertSee('Global');
    }

    private function createDocxPath(string $text): string
    {
        $directory = storage_path('framework/testing/default-report-template-seed');

        if (! is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        $path = $directory.'/template-'.uniqid('', true).'.docx';
        $phpWord = new PhpWord();
        $phpWord->addSection()->addText($text);

        IOFactory::createWriter($phpWord, 'Word2007')->save($path);

        return $path;
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
            'audit_logs',
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

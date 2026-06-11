<?php

namespace Tests\Feature;

use App\Models\Guru;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class HelpCenterTest extends TestCase
{
    private User $admin;

    private Guru $guru;

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

        $this->createSchema();
        $this->seedFixture();
    }

    public function test_faq_widget_uses_help_center_label_and_not_ai_assistant_label(): void
    {
        $html = view('components.ai-chatbot')->render();

        $this->assertStringContainsString('Pusat Bantuan Rapor Digital', $html);
        $this->assertStringContainsString('Panduan penggunaan sistem', $html);
        $this->assertStringContainsString('x-data="helpCenter"', $html);
        $this->assertStringNotContainsString('AI Nilai Assistant', $html);
        $this->assertStringNotContainsString('/gemini/send-message', $html);
    }

    public function test_help_widget_is_included_once_in_each_role_layout(): void
    {
        foreach ([
            resource_path('views/layouts/app.blade.php'),
            resource_path('views/layouts/pengajar/app.blade.php'),
            resource_path('views/layouts/wali_kelas/app.blade.php'),
        ] as $layoutPath) {
            $layout = file_get_contents($layoutPath);

            $this->assertSame(1, substr_count($layout, '<x-ai-chatbot />'), $layoutPath);
        }
    }

    public function test_admin_faq_endpoint_returns_admin_topics(): void
    {
        $this->actingAs($this->admin, 'web')
            ->getJson('/admin/help/faq')
            ->assertOk()
            ->assertJsonPath('role', 'admin')
            ->assertJsonFragment(['question' => 'Cara import siswa dari Excel']);
    }

    public function test_pengajar_faq_endpoint_returns_pengajar_topics(): void
    {
        $this->actingAs($this->guru, 'guru')
            ->withSession(['selected_role' => 'pengajar'])
            ->getJson('/pengajar/help/faq')
            ->assertOk()
            ->assertJsonPath('role', 'pengajar')
            ->assertJsonFragment(['question' => 'Cara input nilai']);
    }

    public function test_wali_kelas_faq_endpoint_returns_wali_topics(): void
    {
        $this->actingAs($this->guru, 'guru')
            ->withSession(['selected_role' => 'wali_kelas'])
            ->getJson('/wali-kelas/help/faq')
            ->assertOk()
            ->assertJsonPath('role', 'wali_kelas')
            ->assertJsonFragment(['question' => 'Cara mengisi absensi']);
    }

    public function test_expanded_faq_contains_key_topics_for_each_role(): void
    {
        $adminQuestions = collect(config('help_faq.admin'))->pluck('question');
        $pengajarQuestions = collect(config('help_faq.pengajar'))->pluck('question');
        $waliQuestions = collect(config('help_faq.wali_kelas'))->pluck('question');

        $this->assertCount(20, $adminQuestions);
        $this->assertCount(14, $pengajarQuestions);
        $this->assertCount(14, $waliQuestions);

        foreach ([
            'Kenapa NIS atau NISN duplikat saat import',
            'Kenapa guru tidak muncul saat memilih pengajar mapel',
            'Apa yang tidak boleh dihapus jika sudah ada data nilai',
        ] as $question) {
            $this->assertContains($question, $adminQuestions);
        }

        foreach ([
            'Kenapa mata pelajaran tidak muncul',
            'Apa arti progress nilai 0%',
            'Kenapa akses ditolak',
        ] as $question) {
            $this->assertContains($question, $pengajarQuestions);
        }

        foreach ([
            'Cara mengisi capaian kompetensi',
            'Kenapa nilai siswa belum muncul di rapor',
            'Apa yang harus dilakukan jika ada siswa salah kelas',
        ] as $question) {
            $this->assertContains($question, $waliQuestions);
        }
    }

    public function test_search_returns_deterministic_matching_faq(): void
    {
        $this->actingAs($this->admin, 'web')
            ->getJson('/admin/help/faq?q=kelas%20tidak%20ditemukan')
            ->assertOk()
            ->assertJsonPath('results.0.question', 'Kenapa import siswa gagal karena kelas tidak ditemukan')
            ->assertJsonPath('answer', 'Biasanya terjadi karena kelas di Excel belum dibuat atau tulisannya berbeda. Cek menu Kelas, lalu samakan kolom kelas dengan kelas pada tahun ajaran aktif.');
    }

    public function test_common_search_terms_return_expected_role_faq(): void
    {
        $this->actingAs($this->admin, 'web')
            ->getJson('/admin/help/faq?q=import%20siswa')
            ->assertOk()
            ->assertJsonPath('results.0.question', 'Cara import siswa dari Excel');

        $this->actingAs($this->admin, 'web')
            ->getJson('/admin/help/faq?q=kelas%20tidak%20ditemukan')
            ->assertOk()
            ->assertJsonPath('results.0.question', 'Kenapa import siswa gagal karena kelas tidak ditemukan');

        $this->actingAs($this->guru, 'guru')
            ->withSession(['selected_role' => 'pengajar'])
            ->getJson('/pengajar/help/faq?q=input%20nilai')
            ->assertOk()
            ->assertJsonPath('results.0.question', 'Cara input nilai');

        $this->actingAs($this->guru, 'guru')
            ->withSession(['selected_role' => 'pengajar'])
            ->getJson('/pengajar/help/faq?q=progress')
            ->assertOk()
            ->assertJsonPath('results.0.question', 'Apa arti progress nilai 0%');

        $this->actingAs($this->guru, 'guru')
            ->withSession(['selected_role' => 'wali_kelas'])
            ->getJson('/wali-kelas/help/faq?q=rapor')
            ->assertOk()
            ->assertJsonPath('results.0.question', 'Cara preview rapor');

        $this->actingAs($this->guru, 'guru')
            ->withSession(['selected_role' => 'wali_kelas'])
            ->getJson('/wali-kelas/help/faq?q=pdf')
            ->assertOk()
            ->assertJsonPath('results.0.question', 'Kenapa PDF atau DOCX belum bisa diunduh');

        $this->actingAs($this->guru, 'guru')
            ->withSession(['selected_role' => 'wali_kelas'])
            ->getJson('/wali-kelas/help/faq?q=absensi')
            ->assertOk()
            ->assertJsonPath('results.0.question', 'Cara mengisi absensi');
    }

    public function test_empty_search_returns_suggested_questions(): void
    {
        $this->actingAs($this->guru, 'guru')
            ->withSession(['selected_role' => 'pengajar'])
            ->getJson('/pengajar/help/faq')
            ->assertOk()
            ->assertJsonCount(6, 'suggested_questions')
            ->assertJsonFragment(['question' => 'Cara input nilai']);
    }

    public function test_faq_endpoint_does_not_require_gemini_api_key_or_send_external_http(): void
    {
        putenv('GEMINI_API_KEY');
        Http::fake();

        $this->actingAs($this->admin, 'web')
            ->getJson('/admin/help/faq?q=template')
            ->assertOk()
            ->assertJsonFragment(['question' => 'Cara mengelola template rapor']);

        Http::assertNothingSent();
    }

    public function test_faq_usage_does_not_create_gemini_chat_records(): void
    {
        $this->actingAs($this->admin, 'web')
            ->getJson('/admin/help/faq?question=Cara%20import%20siswa%20dari%20Excel')
            ->assertOk();

        $this->actingAs($this->guru, 'guru')
            ->withSession(['selected_role' => 'wali_kelas'])
            ->getJson('/wali-kelas/help/faq?q=rapor')
            ->assertOk();

        $this->assertDatabaseCount('gemini_chats', 0);
    }

    private function createSchema(): void
    {
        foreach (['gemini_chats', 'profil_sekolah', 'tahun_ajarans', 'audit_logs', 'gurus', 'users'] as $table) {
            Schema::dropIfExists($table);
        }

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            $table->string('email')->nullable()->unique();
            $table->string('username')->nullable()->unique();
            $table->string('password');
            $table->timestamps();
        });

        Schema::create('gurus', function (Blueprint $table) {
            $table->id();
            $table->string('nama');
            $table->string('email')->nullable()->unique();
            $table->string('username')->nullable()->unique();
            $table->string('password');
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
            $table->string('user_agent')->nullable();
            $table->timestamps();
        });

        Schema::create('tahun_ajarans', function (Blueprint $table) {
            $table->id();
            $table->string('tahun_ajaran');
            $table->boolean('is_active')->default(false);
            $table->integer('semester')->default(1);
            $table->date('tanggal_mulai')->nullable();
            $table->date('tanggal_selesai')->nullable();
            $table->text('deskripsi')->nullable();
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

        Schema::create('gemini_chats', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->text('message');
            $table->text('response');
            $table->timestamps();
        });
    }

    private function seedFixture(): void
    {
        $this->admin = User::create([
            'name' => 'Admin Help',
            'email' => 'admin@example.test',
            'username' => 'admin',
            'password' => Hash::make('password'),
        ]);

        $this->guru = Guru::create([
            'nama' => 'Guru Help',
            'email' => 'guru@example.test',
            'username' => 'guru_help',
            'password' => Hash::make('password'),
        ]);

        DB::table('tahun_ajarans')->insert([
            'tahun_ajaran' => '2026/2027',
            'is_active' => true,
            'semester' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('profil_sekolah')->insert([
            'nama_sekolah' => 'SDIT Al Hidayah',
            'tahun_pelajaran' => '2026/2027',
            'semester' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}

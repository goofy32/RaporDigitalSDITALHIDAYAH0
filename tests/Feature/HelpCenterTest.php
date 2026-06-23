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
            ->assertJsonFragment(['question' => 'Bagaimana import siswa dari Excel?']);
    }

    public function test_pengajar_faq_endpoint_returns_pengajar_topics(): void
    {
        $this->actingAs($this->guru, 'guru')
            ->withSession(['selected_role' => 'pengajar'])
            ->getJson('/pengajar/help/faq')
            ->assertOk()
            ->assertJsonPath('role', 'pengajar')
            ->assertJsonFragment(['question' => 'Bagaimana input nilai?']);
    }

    public function test_wali_kelas_faq_endpoint_returns_wali_topics(): void
    {
        $this->actingAs($this->guru, 'guru')
            ->withSession(['selected_role' => 'wali_kelas'])
            ->getJson('/wali-kelas/help/faq')
            ->assertOk()
            ->assertJsonPath('role', 'wali_kelas')
            ->assertJsonFragment(['question' => 'Bagaimana mengubah capaian siswa?']);
    }

    public function test_expanded_faq_contains_key_topics_for_each_role(): void
    {
        $adminQuestions = collect(config('help_faq.admin'))->pluck('question');
        $pengajarQuestions = collect(config('help_faq.pengajar'))->pluck('question');
        $waliQuestions = collect(config('help_faq.wali_kelas'))->pluck('question');

        $this->assertCount(64, $adminQuestions);
        $this->assertCount(19, $pengajarQuestions);
        $this->assertCount(39, $waliQuestions);

        foreach ([
            'Bagaimana mengunduh template Excel siswa?',
            'Bagaimana reset password guru?',
            'Bagaimana upload tanda tangan wali kelas?',
            'Bagaimana menggunakan placeholder ${ttd_wali_kelas}?',
            'Mengapa PDF belum berubah setelah data diperbarui?',
        ] as $question) {
            $this->assertContains($question, $adminQuestions);
        }

        foreach ([
            'Bagaimana memilih role pengajar?',
            'Bagaimana mengganti password setelah reset admin?',
            'Apakah pengajar dapat mengunggah tanda tangan sendiri?',
            'Bagaimana mengelola TP?',
            'Mengapa progress belum penuh?',
        ] as $question) {
            $this->assertContains($question, $pengajarQuestions);
        }

        foreach ([
            'Apa fungsi Pengaturan Kalimat Awal Capaian?',
            'Mengapa hanya ada satu tombol Simpan Semua Perubahan?',
            'Apa arti Belum disimpan?',
            'Apakah deskripsi khusus tertimpa ketika default diubah?',
            'Apakah tanda tangan muncul otomatis?',
        ] as $question) {
            $this->assertContains($question, $waliQuestions);
        }
    }

    public function test_search_returns_deterministic_matching_faq(): void
    {
        $this->actingAs($this->admin, 'web')
            ->getJson('/admin/help/faq?q=kelas%20tidak%20ditemukan')
            ->assertOk()
            ->assertJsonPath('results.0.question', 'Mengapa kelas tidak ditemukan saat import?')
            ->assertJsonPath('answer', 'Kelas di file harus sudah dibuat pada tahun ajaran aktif dan penulisannya harus sama. Cek daftar kelas di template atau menu Kelas, lalu samakan kolom kelas pada file Excel.');
    }

    public function test_common_search_terms_return_expected_role_faq(): void
    {
        $this->actingAs($this->admin, 'web')
            ->getJson('/admin/help/faq?q=import%20siswa')
            ->assertOk()
            ->assertJsonPath('results.0.question', 'Bagaimana import siswa dari Excel?');

        $this->actingAs($this->admin, 'web')
            ->getJson('/admin/help/faq?q=kelas%20tidak%20ditemukan')
            ->assertOk()
            ->assertJsonPath('results.0.question', 'Mengapa kelas tidak ditemukan saat import?');

        $this->actingAs($this->admin, 'web')
            ->getJson('/admin/help/faq?q=download%20template')
            ->assertOk()
            ->assertJsonFragment(['question' => 'Bagaimana mengunduh template Excel siswa?']);

        $this->actingAs($this->admin, 'web')
            ->getJson('/admin/help/faq?q=reset%20password')
            ->assertOk()
            ->assertJsonFragment(['question' => 'Bagaimana reset password guru?']);

        $this->actingAs($this->admin, 'web')
            ->getJson('/admin/help/faq?q=ttd')
            ->assertOk()
            ->assertJsonFragment(['question' => 'Bagaimana upload tanda tangan wali kelas?']);

        $this->actingAs($this->admin, 'web')
            ->getJson('/admin/help/faq?q=tanda%20tangan')
            ->assertOk()
            ->assertJsonFragment(['question' => 'Bagaimana upload tanda tangan wali kelas?']);

        $this->actingAs($this->admin, 'web')
            ->getJson('/admin/help/faq?q=pdf%20belum%20berubah')
            ->assertOk()
            ->assertJsonFragment(['question' => 'Mengapa PDF belum berubah setelah data diperbarui?']);

        $this->actingAs($this->guru, 'guru')
            ->withSession(['selected_role' => 'pengajar'])
            ->getJson('/pengajar/help/faq?q=input%20nilai')
            ->assertOk()
            ->assertJsonPath('results.0.question', 'Bagaimana input nilai?');

        $this->actingAs($this->guru, 'guru')
            ->withSession(['selected_role' => 'pengajar'])
            ->getJson('/pengajar/help/faq?q=progress')
            ->assertOk()
            ->assertJsonFragment(['question' => 'Apa arti progress nilai?']);

        $this->actingAs($this->guru, 'guru')
            ->withSession(['selected_role' => 'pengajar'])
            ->getJson('/pengajar/help/faq?q=ganti%20password')
            ->assertOk()
            ->assertJsonFragment(['question' => 'Bagaimana mengganti password setelah reset admin?']);

        $this->actingAs($this->guru, 'guru')
            ->withSession(['selected_role' => 'wali_kelas'])
            ->getJson('/wali-kelas/help/faq?q=gunakan%20default')
            ->assertOk()
            ->assertJsonFragment(['question' => 'Apa fungsi tombol Gunakan default?']);

        $this->actingAs($this->guru, 'guru')
            ->withSession(['selected_role' => 'wali_kelas'])
            ->getJson('/wali-kelas/help/faq?q=rapor')
            ->assertOk()
            ->assertJsonFragment(['question' => 'Mengapa rapor belum berubah?']);

        $this->actingAs($this->guru, 'guru')
            ->withSession(['selected_role' => 'wali_kelas'])
            ->getJson('/wali-kelas/help/faq?q=pdf')
            ->assertOk()
            ->assertJsonFragment(['question' => 'Mengapa PDF rapor belum berubah setelah data diperbarui?']);

        $this->actingAs($this->guru, 'guru')
            ->withSession(['selected_role' => 'wali_kelas'])
            ->getJson('/wali-kelas/help/faq?q=absensi')
            ->assertOk()
            ->assertJsonFragment(['question' => 'Bagaimana mengisi absensi?']);
    }

    public function test_empty_search_returns_suggested_questions(): void
    {
        $this->actingAs($this->admin, 'web')
            ->getJson('/admin/help/faq')
            ->assertOk()
            ->assertJsonPath('suggested_questions.0', 'Bagaimana import siswa dari Excel?')
            ->assertJsonPath('suggested_questions.1', 'Bagaimana reset password guru?')
            ->assertJsonPath('suggested_questions.2', 'Bagaimana upload tanda tangan wali kelas?')
            ->assertJsonPath('suggested_questions.3', 'Bagaimana berpindah ke semester genap?')
            ->assertJsonPath('suggested_questions.4', 'Mengapa PDF belum berubah setelah data diperbarui?');

        $this->actingAs($this->guru, 'guru')
            ->withSession(['selected_role' => 'pengajar'])
            ->getJson('/pengajar/help/faq')
            ->assertOk()
            ->assertJsonCount(6, 'suggested_questions')
            ->assertJsonPath('suggested_questions.0', 'Bagaimana input nilai?')
            ->assertJsonPath('suggested_questions.1', 'Mengapa siswa tidak muncul di input nilai?')
            ->assertJsonPath('suggested_questions.2', 'Bagaimana mengganti password setelah reset admin?')
            ->assertJsonPath('suggested_questions.3', 'Bagaimana mengelola TP?')
            ->assertJsonPath('suggested_questions.4', 'Apa arti progress nilai?');

        $this->actingAs($this->guru, 'guru')
            ->withSession(['selected_role' => 'wali_kelas'])
            ->getJson('/wali-kelas/help/faq')
            ->assertOk()
            ->assertJsonCount(6, 'suggested_questions')
            ->assertJsonPath('suggested_questions.0', 'Bagaimana mengubah capaian siswa?')
            ->assertJsonPath('suggested_questions.1', 'Apa arti Default dan Khusus?')
            ->assertJsonPath('suggested_questions.2', 'Bagaimana mengembalikan capaian ke default?')
            ->assertJsonPath('suggested_questions.3', 'Mengapa rapor belum berubah?')
            ->assertJsonPath('suggested_questions.4', 'Apakah tanda tangan muncul otomatis?');
    }

    public function test_faq_endpoint_does_not_require_gemini_api_key_or_send_external_http(): void
    {
        putenv('GEMINI_API_KEY');
        Http::fake();

        $this->actingAs($this->admin, 'web')
            ->getJson('/admin/help/faq?q=template')
            ->assertOk()
            ->assertJsonFragment(['question' => 'Bagaimana memeriksa template rapor?']);

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

    public function test_role_specific_faq_does_not_leak_admin_actions_to_other_roles(): void
    {
        $pengajarQuestions = collect(config('help_faq.pengajar'))->pluck('question')->implode(' ');
        $waliQuestions = collect(config('help_faq.wali_kelas'))->pluck('question')->implode(' ');

        $this->assertStringNotContainsString('Bagaimana reset password guru?', $pengajarQuestions);
        $this->assertStringNotContainsString('Bagaimana upload tanda tangan wali kelas?', $pengajarQuestions);
        $this->assertStringNotContainsString('Bagaimana reset password guru?', $waliQuestions);
        $this->assertStringNotContainsString('Bagaimana upload tanda tangan wali kelas?', $waliQuestions);

        $this->actingAs($this->guru, 'guru')
            ->withSession(['selected_role' => 'pengajar'])
            ->getJson('/pengajar/help/faq?q=upload%20tanda%20tangan')
            ->assertOk()
            ->assertJsonFragment(['question' => 'Apakah pengajar dapat mengunggah tanda tangan sendiri?']);
    }

    public function test_help_center_uses_help_endpoint_and_not_gemini_endpoint(): void
    {
        $helpCenterJs = file_get_contents(resource_path('js/features/help-center.js'));
        $widget = view('components.ai-chatbot')->render();

        $this->assertStringContainsString('/admin/help/faq', $helpCenterJs);
        $this->assertStringContainsString('/pengajar/help/faq', $helpCenterJs);
        $this->assertStringContainsString('/wali-kelas/help/faq', $helpCenterJs);
        $this->assertStringNotContainsString('/gemini/send-message', $helpCenterJs);
        $this->assertStringNotContainsString('/gemini/send-message', $widget);
    }

    public function test_faq_answers_avoid_sensitive_or_developer_terms(): void
    {
        $forbiddenTerms = [
            '/gemini/send-message',
            'Gemini',
            'controller',
            'route',
            'migration',
            'middleware',
            'cache key',
            'database table',
            'password123',
            'token rahasia',
        ];

        foreach (config('help_faq') as $role => $items) {
            foreach ($items as $item) {
                $text = implode(' ', [
                    $item['question'],
                    $item['answer'],
                ]);

                foreach ($forbiddenTerms as $term) {
                    $this->assertStringNotContainsString($term, $text, "{$role}: {$item['question']}");
                }
            }
        }
    }

    public function test_no_duplicate_faq_questions_exist(): void
    {
        $questions = collect(config('help_faq'))
            ->flatMap(fn (array $items) => collect($items)->pluck('question'))
            ->values();

        $this->assertSame(
            $questions->count(),
            $questions->unique()->count(),
            'FAQ questions should stay unique so search results are not confusing.'
        );
    }

    private function createSchema(): void
    {
        foreach (['gemini_chats', 'mata_pelajarans', 'guru_kelas', 'kelas', 'profil_sekolah', 'tahun_ajarans', 'audit_logs', 'gurus', 'users'] as $table) {
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

        $tahunAjaranId = DB::table('tahun_ajarans')->insertGetId([
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

        $kelasId = DB::table('kelas')->insertGetId([
            'nomor_kelas' => 5,
            'nama_kelas' => 'A',
            'tahun_ajaran_id' => $tahunAjaranId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('guru_kelas')->insert([
            [
                'guru_id' => $this->guru->id,
                'kelas_id' => $kelasId,
                'is_wali_kelas' => false,
                'role' => 'pengajar',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'guru_id' => $this->guru->id,
                'kelas_id' => $kelasId,
                'is_wali_kelas' => true,
                'role' => 'wali_kelas',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        DB::table('mata_pelajarans')->insert([
            'nama_pelajaran' => 'Matematika',
            'kelas_id' => $kelasId,
            'guru_id' => $this->guru->id,
            'semester' => 1,
            'tahun_ajaran_id' => $tahunAjaranId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}

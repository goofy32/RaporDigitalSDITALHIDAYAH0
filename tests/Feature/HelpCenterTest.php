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

    public function test_help_widget_renders_compact_quick_help_shell(): void
    {
        $html = view('components.ai-chatbot')->render();
        $js = file_get_contents(resource_path('js/features/help-center.js'));

        $this->assertStringContainsString('Pusat Bantuan Rapor Digital', $html);
        $this->assertStringContainsString('Cari bantuan singkat atau buka panduan lengkap.', $html);
        $this->assertStringContainsString('placeholder="Cari bantuan singkat..."', $html);
        $this->assertStringContainsString('Buka Pusat Bantuan Lengkap', $html);
        $this->assertStringContainsString('displayedTopics()', $html);
        $this->assertStringContainsString('x-data="helpCenter"', $html);
        $this->assertStringContainsString('data-testid="help-center-launcher"', $html);
        $this->assertStringContainsString('@click="togglePanel()"', $html);
        $this->assertStringContainsString('aria-label="Buka Pusat Bantuan Rapor Digital"', $html);
        $this->assertStringContainsString('class="inline-flex h-12 w-12 shrink-0 items-center justify-center rounded-full', $html);
        $this->assertStringNotContainsString('hidden sm:inline text-sm font-semibold', $html);
        $this->assertStringContainsString('fullHelpUrl', $js);
        $this->assertStringContainsString("'Mulai cepat untuk Admin'", $js);
        $this->assertStringContainsString("'Memverifikasi email Guru'", $js);
        $this->assertStringContainsString("'Unduh Semua Rapor dalam ZIP'", $js);
        $this->assertStringContainsString("return query.split(' ').every(keyword => haystack.includes(keyword));", $js);
        $this->assertStringNotContainsString("'Kenapa PDF lama disiapkan?'", $js);
        $this->assertStringNotContainsString('activeCategory', $html);
        $this->assertStringNotContainsString('AI Nilai Assistant', $html);
        $this->assertStringNotContainsString('/gemini/send-message', $html);
    }

    public function test_help_widget_lazy_loads_faq_and_guards_stale_requests(): void
    {
        $html = view('components.ai-chatbot')->render();
        $js = file_get_contents(resource_path('js/features/help-center.js'));

        $initBlock = $this->sourceBlock($js, 'init() {', 'togglePanel()');
        $toggleBlock = $this->sourceBlock($js, 'togglePanel() {', 'destroy()');
        $loadBlock = $this->sourceBlock($js, 'async loadTopics({ force = false } = {}) {', 'retryTopics()');

        $this->assertStringNotContainsString('loadTopics', $initBlock);
        $this->assertStringNotContainsString('fetchFaq', $initBlock);
        $this->assertStringContainsString('this.loadTopics();', $toggleBlock);

        $this->assertStringContainsString('const FAQ_CACHE_TTL_MS = 10 * 60 * 1000;', $js);
        $this->assertStringContainsString('const helpCenterInstances = new Set();', $js);
        $this->assertStringContainsString("document.addEventListener('turbo:before-cache'", $js);
        $this->assertStringContainsString('Array.from(helpCenterInstances).forEach', $js);
        $this->assertStringContainsString('prepareForCache()', $js);
        $this->assertStringContainsString('helpCenterInstances.delete(this);', $js);
        $this->assertStringContainsString('faqPromise: null', $js);
        $this->assertStringContainsString('faqAbortController: null', $js);
        $this->assertStringContainsString('faqLoadGeneration: 0', $js);
        $this->assertStringContainsString('faqLoaded: false', $js);
        $this->assertStringContainsString('faqLoadError: false', $js);
        $this->assertStringContainsString('faqLoadedAt: null', $js);
        $this->assertStringContainsString("const FAQ_LOAD_ERROR_MESSAGE = 'FAQ belum dapat dimuat. Silakan coba lagi.';", $js);
        $this->assertStringContainsString('this.$el?.isConnected', $js);
        $this->assertStringContainsString('window.location.pathname === this.pagePath', $js);
        $this->assertStringContainsString('this.faqLoadGeneration === generation', $js);
        $this->assertStringContainsString('this.faqAbortController === controller', $js);
        $this->assertStringContainsString('this.faqAbortController?.abort();', $js);
        $this->assertStringContainsString('new AbortController()', $js);
        $this->assertStringContainsString('signal: options.controller?.signal', $js);
        $this->assertStringContainsString('credentials: \'same-origin\'', $js);
        $this->assertStringContainsString('return this.loadTopics({ force: true });', $js);

        $this->assertStringContainsString('if (force)', $loadBlock);
        $this->assertStringContainsString('this.invalidateActiveFaqLoad();', $loadBlock);
        $this->assertStringContainsString('if (!force && this.isFaqCacheFresh())', $loadBlock);
        $this->assertStringContainsString('if (this.faqPromise)', $loadBlock);
        $this->assertStringContainsString('const generation = this.faqLoadGeneration + 1;', $loadBlock);
        $this->assertStringContainsString('this.faqLoadGeneration = generation;', $loadBlock);
        $this->assertStringContainsString('if (!this.isValidFaqPayload(data))', $loadBlock);
        $this->assertStringContainsString('throw new Error(FAQ_LOAD_ERROR_MESSAGE);', $loadBlock);
        $this->assertStringContainsString('this.topics = this.normalizeFaqTopics(data.results);', $loadBlock);
        $this->assertStringNotContainsString('Array.isArray(data.results) ? data.results : []', $loadBlock);
        $this->assertStringContainsString('this.faqLoaded = true;', $loadBlock);
        $this->assertStringContainsString('this.faqLoadedAt = Date.now();', $loadBlock);
        $this->assertStringContainsString('this.faqLoaded = false;', $loadBlock);
        $this->assertStringContainsString('this.faqPromise = null;', $loadBlock);
        $this->assertStringContainsString('this.faqAbortController = null;', $loadBlock);
        $this->assertStringContainsString('this.isLoading = false;', $loadBlock);

        $this->assertStringContainsString('@click="retryTopics()"', $html);
        $this->assertStringContainsString('Coba lagi', $html);
        $this->assertStringContainsString('!isLoading && !error && displayedTopics().length === 0', $html);
    }

    public function test_help_widget_rejects_malformed_faq_payload_contract(): void
    {
        $js = file_get_contents(resource_path('js/features/help-center.js'));
        $validationBlock = $this->sourceBlock($js, 'isValidFaqPayload(data) {', 'normalizeFaqTopics(results)');
        $normalizationBlock = $this->sourceBlock($js, 'normalizeFaqTopics(results) {', 'displayedTopics()');
        $errorBlock = $this->sourceBlock($js, '.catch(error => {', '.finally(() => {');

        $this->assertStringContainsString('data', $validationBlock);
        $this->assertStringContainsString("typeof data === 'object'", $validationBlock);
        $this->assertStringContainsString('!Array.isArray(data)', $validationBlock);
        $this->assertStringContainsString('Array.isArray(data.results)', $validationBlock);

        $this->assertStringContainsString("topic && typeof topic === 'object' && !Array.isArray(topic)", $normalizationBlock);
        $this->assertStringContainsString('this.error = FAQ_LOAD_ERROR_MESSAGE;', $errorBlock);
        $this->assertStringNotContainsString('this.error = error.message', $errorBlock);
        $this->assertStringContainsString('this.faqLoaded = false;', $errorBlock);
        $this->assertStringContainsString('this.faqLoadedAt = null;', $errorBlock);
        $this->assertStringContainsString('this.faqLoadError = true;', $errorBlock);

        $keywordsGuard = <<<'JS'
const keywords = Array.isArray(topic.keywords) ? topic.keywords.join(' ') : '';
JS;
        $topicKeyFallback = <<<'JS'
return `${topic?.category || 'faq'}-${topic?.question || 'topic'}-${index}`;
JS;

        $this->assertStringContainsString($keywordsGuard, $js);
        $this->assertStringContainsString($topicKeyFallback, $js);
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

    public function test_admin_help_page_renders_all_major_categories(): void
    {
        $this->actingAs($this->admin, 'web')
            ->get('/admin/help')
            ->assertOk()
            ->assertSee('Pusat Bantuan')
            ->assertSee('Mulai cepat untuk Admin')
            ->assertSee('Import Siswa dari Excel')
            ->assertSee('Melanjutkan Semester Ganjil ke Genap')
            ->assertSee('Memilih Jenis Rapor yang Dibuka')
            ->assertSee('Upload dan pemeriksaan template UTS/UAS')
            ->assertSee('Masalah Umum');
    }

    public function test_pengajar_help_page_renders_pengajar_topics(): void
    {
        $this->actingAs($this->guru, 'guru')
            ->withSession(['selected_role' => 'pengajar'])
            ->get('/pengajar/help')
            ->assertOk()
            ->assertSee('Data Mata Pelajaran yang dapat dibuka Pengajar')
            ->assertSee('Input Nilai manual dan cara menyimpan')
            ->assertSee('Upload Semua Nilai Excel per sheet')
            ->assertSee('Reset password Guru dengan email terverifikasi')
            ->assertDontSee('Mulai cepat untuk Admin')
            ->assertDontSee('Unduh Semua Rapor dalam ZIP');
    }

    public function test_wali_kelas_help_page_renders_wali_topics(): void
    {
        $this->actingAs($this->guru, 'guru')
            ->withSession(['selected_role' => 'wali_kelas'])
            ->get('/wali-kelas/help')
            ->assertOk()
            ->assertSee('Siswa yang terlihat pada kelas Wali')
            ->assertSee('Mengelola Capaian Kompetensi')
            ->assertSee('Unduh Semua Rapor dalam ZIP')
            ->assertSee('Kapan pilihan PDF aplikasi tersedia?')
            ->assertDontSee('Download template nilai Excel');
    }

    public function test_admin_can_access_all_help_center_topic_categories(): void
    {
        $this->actingAs($this->admin, 'web')
            ->getJson('/admin/help/faq?all=1')
            ->assertOk()
            ->assertJsonPath('role', 'admin')
            ->assertJsonPath('categories.0', 'Mulai Cepat')
            ->assertJsonFragment(['question' => 'Mulai cepat untuk Admin'])
            ->assertJsonFragment(['question' => 'Recycle Bin, pulihkan, dan hapus permanen'])
            ->assertJsonFragment(['question' => 'Memilih Jenis Rapor yang Dibuka'])
            ->assertJsonFragment(['question' => 'Template khusus kelas, Global, dan beberapa template aktif']);
    }

    public function test_pengajar_can_access_relevant_help_topics(): void
    {
        $response = $this->actingAs($this->guru, 'guru')
            ->withSession(['selected_role' => 'pengajar'])
            ->getJson('/pengajar/help/faq?all=1')
            ->assertOk()
            ->assertJsonPath('role', 'pengajar')
            ->assertJsonFragment(['question' => 'Input Nilai manual dan cara menyimpan'])
            ->assertJsonFragment(['question' => 'Upload Semua Nilai Excel per sheet'])
            ->assertJsonFragment(['question' => 'Nilai yang dipakai pada Rapor UTS'])
            ->assertJsonFragment(['question' => 'Memverifikasi email Guru']);

        $questions = collect($response->json('results'))->pluck('question')->implode(' ');

        $this->assertStringNotContainsString('Mulai cepat untuk Admin', $questions);
        $this->assertStringNotContainsString('Import Siswa dari Excel', $questions);
        $this->assertStringNotContainsString('Unduh Semua Rapor dalam ZIP', $questions);
    }

    public function test_wali_kelas_can_access_relevant_help_topics(): void
    {
        $response = $this->actingAs($this->guru, 'guru')
            ->withSession(['selected_role' => 'wali_kelas'])
            ->getJson('/wali-kelas/help/faq?all=1')
            ->assertOk()
            ->assertJsonPath('role', 'wali_kelas')
            ->assertJsonFragment(['question' => 'Siswa yang terlihat pada kelas Wali'])
            ->assertJsonFragment(['question' => 'Unduh Semua Rapor dalam ZIP'])
            ->assertJsonFragment(['question' => 'Kapan pilihan PDF aplikasi tersedia?'])
            ->assertJsonFragment(['question' => 'Guru tanpa email terverifikasi lupa password']);

        $questions = collect($response->json('results'))->pluck('question')->implode(' ');

        $this->assertStringNotContainsString('Mulai cepat untuk Admin', $questions);
        $this->assertStringNotContainsString('Download template nilai Excel', $questions);
        $this->assertStringNotContainsString('KKM dan Bobot Nilai', $questions);
    }

    public function test_help_topics_are_scoped_to_declared_roles_and_ordered_categories(): void
    {
        $validRoles = array_keys(config('help_center.roles'));
        $topics = collect(config('help_center.topics'));

        $this->assertGreaterThanOrEqual(60, $topics->count());

        foreach ($topics as $topic) {
            $this->assertIsArray($topic['roles'] ?? null, $topic['question']);
            $this->assertNotEmpty($topic['roles'], $topic['question']);
            $this->assertEmpty(array_diff($topic['roles'], $validRoles), $topic['question']);

            foreach ($topic['roles'] as $role) {
                $this->assertContains($topic['category'], config("help_center.roles.{$role}"), $topic['question']);
            }
        }

        foreach ($validRoles as $role) {
            $payload = app(\App\Services\HelpFaqService::class)->responseFor($role, all: true);

            $this->assertSame(config("help_center.roles.{$role}"), $payload['categories']);
            $this->assertNotEmpty($payload['results']);
        }
    }

    public function test_help_page_searches_across_categories_and_has_responsive_navigation(): void
    {
        $response = $this->actingAs($this->admin, 'web')->get('/admin/help');

        $response
            ->assertOk()
            ->assertSee('Semua topik')
            ->assertSee('Pencarian mencakup semua kategori.')
            ->assertSee('Topik bantuan tidak ditemukan')
            ->assertSee('id="help-mobile-category"', false)
            ->assertSee("query.split(' ').every(keyword => searchableText.includes(keyword))", false)
            ->assertSee('const matchesCategory = query || !this.category || topicCategory === this.category;', false);
    }

    public function test_help_center_includes_required_internal_school_topics(): void
    {
        $topics = collect(config('help_center.topics'));
        $questions = $topics->pluck('question');
        $allText = $topics
            ->map(fn (array $topic) => $topic['question'].' '.$topic['answer'])
            ->implode(' ');

        foreach ([
            'Masuk dengan Username atau Email',
            'Memverifikasi email Guru',
            'Melanjutkan Semester Ganjil ke Genap',
            'Input Nilai manual dan cara menyimpan',
            'Nilai yang dipakai pada Rapor UTS',
            'Syarat nilai untuk Rapor UAS',
            'Unduh Semua Rapor dalam ZIP',
            'Kapan pilihan PDF aplikasi tersedia?',
            'Template khusus kelas, Global, dan beberapa template aktif',
        ] as $question) {
            $this->assertContains($question, $questions);
        }

        foreach ([
            'RAPOR TENGAH SEMESTER',
            'UAS tidak sama dengan Semester Genap',
            'Angka 0 adalah nilai nyata',
            'Simpan & Lanjut',
            'kolom status dan tombol PDF tidak ditampilkan',
            'setidaknya satu mapel yang memenuhi syarat',
            'Menyimpan data Pengajar tidak otomatis mengirim email',
            'Menghapus notifikasi tidak menghapus nilai',
        ] as $phrase) {
            $this->assertStringContainsString($phrase, $allText);
        }

        $this->assertStringNotContainsString('PDF dibuat melalui proses latar belakang', $allText);
        $this->assertStringNotContainsString('PDF belum siap: tunggu proses latar belakang', $allText);
    }

    public function test_search_returns_matching_help_topics(): void
    {
        $this->actingAs($this->admin, 'web')
            ->getJson('/admin/help/faq?q=jenis%20rapor%20dibuka')
            ->assertOk()
            ->assertJsonPath('results.0.question', 'Memilih Jenis Rapor yang Dibuka');

        $this->actingAs($this->admin, 'web')
            ->getJson('/admin/help/faq?q=dibuka%20rapor%20jenis')
            ->assertOk()
            ->assertJsonPath('results.0.question', 'Memilih Jenis Rapor yang Dibuka');

        $this->actingAs($this->guru, 'guru')
            ->withSession(['selected_role' => 'pengajar'])
            ->getJson('/pengajar/help/faq?q=simpan%20lanjut%20sheet')
            ->assertOk()
            ->assertJsonFragment(['question' => 'Upload Semua Nilai Excel per sheet']);

        $this->actingAs($this->guru, 'guru')
            ->withSession(['selected_role' => 'wali_kelas'])
            ->getJson('/wali-kelas/help/faq?q=tombol%20pdf%20hilang')
            ->assertOk()
            ->assertJsonFragment(['question' => 'Kapan pilihan PDF aplikasi tersedia?']);
    }

    public function test_empty_endpoint_still_returns_suggested_questions_for_widget_start(): void
    {
        $this->actingAs($this->admin, 'web')
            ->getJson('/admin/help/faq')
            ->assertOk()
            ->assertJsonCount(6, 'suggested_questions')
            ->assertJsonPath('suggested_questions.0', 'Mulai cepat untuk Admin');
    }

    public function test_guests_cannot_access_protected_help_center_endpoints(): void
    {
        $this->get('/admin/help')->assertRedirect(route('login'));
        $this->get('/pengajar/help')->assertRedirect(route('login'));
        $this->get('/wali-kelas/help')->assertRedirect(route('login'));
        $this->get('/admin/help/faq')->assertRedirect(route('login'));
        $this->get('/pengajar/help/faq')->assertRedirect(route('login'));
        $this->get('/wali-kelas/help/faq')->assertRedirect(route('login'));
    }

    public function test_faq_endpoint_does_not_require_gemini_api_key_or_send_external_http(): void
    {
        putenv('GEMINI_API_KEY');
        Http::fake();

        $this->actingAs($this->admin, 'web')
            ->getJson('/admin/help/faq?q=template')
            ->assertOk()
            ->assertJsonFragment(['question' => 'Fungsi Template Rapor']);

        Http::assertNothingSent();
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

    public function test_help_center_answers_avoid_sensitive_or_developer_terms(): void
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

        foreach (config('help_center.topics') as $item) {
            $text = implode(' ', [
                $item['question'],
                $item['answer'],
            ]);

            foreach ($forbiddenTerms as $term) {
                $this->assertStringNotContainsString($term, $text, $item['question']);
            }
        }
    }

    public function test_no_duplicate_help_center_questions_exist(): void
    {
        $questions = collect(config('help_center.topics'))->pluck('question')->values();

        $this->assertSame(
            $questions->count(),
            $questions->unique()->count(),
            'Help center questions should stay unique so search results are not confusing.'
        );
    }

    private function sourceBlock(string $source, string $startNeedle, string $endNeedle): string
    {
        $start = strpos($source, $startNeedle);
        $this->assertNotFalse($start, "Missing source block start: {$startNeedle}");

        $end = strpos($source, $endNeedle, $start);
        $this->assertNotFalse($end, "Missing source block end: {$endNeedle}");

        return substr($source, $start, $end - $start);
    }

    private function createSchema(): void
    {
        foreach (['gemini_chats', 'nilais', 'kkms', 'mata_pelajarans', 'guru_kelas', 'kelas', 'profil_sekolah', 'tahun_ajarans', 'audit_logs', 'gurus', 'users'] as $table) {
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

        Schema::create('kkms', function (Blueprint $table) {
            $table->id();
            $table->foreignId('mata_pelajaran_id');
            $table->foreignId('tahun_ajaran_id')->nullable();
            $table->integer('nilai')->default(75);
            $table->timestamps();
        });

        Schema::create('nilais', function (Blueprint $table) {
            $table->id();
            $table->foreignId('mata_pelajaran_id');
            $table->foreignId('tahun_ajaran_id')->nullable();
            $table->decimal('nilai_akhir_rapor', 5, 2)->nullable();
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

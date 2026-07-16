<?php

namespace Tests\Feature;

use App\Http\Controllers\ScoreController;
use App\Models\Guru;
use App\Models\MataPelajaran;
use App\Models\Notification;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use ReflectionMethod;
use Tests\TestCase;

class NotificationPanelBulkActionsTest extends TestCase
{
    private User $admin;

    private Guru $pengajar;

    private Guru $wali;

    private int $yearId;

    private int $classId;

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

    public function test_admin_can_mark_all_own_notifications_as_read(): void
    {
        $first = $this->insertNotification('Info Admin', 'Pesan untuk admin', 'all');
        $second = $this->insertNotification('Template Rapor', 'Template tersedia', 'all');

        $this->actingAs($this->admin, 'web')
            ->withSession($this->adminSession())
            ->postJson('/admin/information/mark-all-read')
            ->assertOk()
            ->assertJson(['success' => true]);

        foreach ([$first, $second] as $notificationId) {
            $this->assertDatabaseHas('notification_user_states', [
                'notification_id' => $notificationId,
                'user_type' => 'admin',
                'user_id' => $this->admin->id,
            ]);

            $this->assertNotNull(DB::table('notification_user_states')
                ->where('notification_id', $notificationId)
                ->where('user_type', 'admin')
                ->where('user_id', $this->admin->id)
                ->value('read_at'));
        }
    }

    public function test_pengajar_and_wali_can_mark_all_own_notifications_as_read(): void
    {
        $forPengajar = $this->insertNotification('Info Guru', 'Pengumuman guru', 'guru');
        $forWali = $this->insertNotification('Info Wali', 'Pengumuman wali', 'wali_kelas');

        $this->actingAs($this->pengajar, 'guru')
            ->withSession($this->pengajarSession())
            ->postJson('/pengajar/notifications/mark-all-read')
            ->assertOk()
            ->assertJson(['success' => true]);

        $this->assertDatabaseHas('notification_reads', [
            'notification_id' => $forPengajar,
            'guru_id' => $this->pengajar->id,
        ]);
        $this->assertDatabaseMissing('notification_reads', [
            'notification_id' => $forWali,
            'guru_id' => $this->pengajar->id,
        ]);

        $this->actingAs($this->wali, 'guru')
            ->withSession($this->waliSession())
            ->postJson('/wali-kelas/notifications/mark-all-read')
            ->assertOk()
            ->assertJson(['success' => true]);

        $this->assertDatabaseHas('notification_reads', [
            'notification_id' => $forWali,
            'guru_id' => $this->wali->id,
        ]);
    }

    public function test_delete_all_dismisses_only_current_users_notifications(): void
    {
        $shared = $this->insertNotification('Info Bersama', 'Pesan bersama', 'all');
        $guruOnly = $this->insertNotification('Info Guru', 'Pesan guru', 'guru');

        $this->actingAs($this->admin, 'web')
            ->withSession($this->adminSession())
            ->deleteJson('/admin/information/delete-all')
            ->assertOk()
            ->assertJson(['success' => true]);

        $this->assertDatabaseHas('notifications', ['id' => $shared]);
        $this->assertDatabaseHas('notifications', ['id' => $guruOnly]);

        $adminList = $this->actingAs($this->admin, 'web')
            ->withSession($this->adminSession())
            ->getJson('/admin/information/list')
            ->assertOk();
        $this->assertCount(0, $adminList->json('items'));

        $pengajarList = $this->actingAs($this->pengajar, 'guru')
            ->withSession($this->pengajarSession())
            ->getJson('/pengajar/notifications')
            ->assertOk();
        $this->assertCount(2, $pengajarList->json('items'));
    }

    public function test_notification_list_returns_filter_metadata_and_read_status(): void
    {
        $nilai = $this->insertNotification('Nilai Matematika Disimpan', 'Nilai telah disimpan', 'specific', [$this->wali->id]);
        $this->insertNotification('Template UTS', 'Template rapor tersedia', 'all');

        DB::table('notification_reads')->insert([
            'notification_id' => $nilai,
            'guru_id' => $this->wali->id,
            'read_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->actingAs($this->wali, 'guru')
            ->withSession($this->waliSession())
            ->getJson('/wali-kelas/notifications')
            ->assertOk();

        $items = collect($response->json('items'));
        $nilaiItem = $items->firstWhere('id', $nilai);

        $this->assertSame('nilai', $nilaiItem['category']);
        $this->assertSame('Nilai', $nilaiItem['category_label']);
        $this->assertSame('guru', $nilaiItem['source']);
        $this->assertSame('Guru/Pengajar', $nilaiItem['source_label']);
        $this->assertTrue($nilaiItem['is_read']);
    }

    public function test_admin_notification_dashboard_preview_restores_original_timeline_style(): void
    {
        $html = Blade::render('<x-notification-panel :can-create="true" />');
        $dashboardHtml = Str::between($html, 'data-notification-dashboard-preview', 'data-testid="notification-modal"');

        $this->assertStringContainsString('data-testid="notification-open-button"', $html);
        $this->assertStringContainsString('Informasi', $dashboardHtml);
        $this->assertStringContainsString('data-testid="notification-dashboard-timeline"', $dashboardHtml);
        $this->assertStringContainsString('notifications-container', $dashboardHtml);
        $this->assertStringContainsString('notification-item', $dashboardHtml);
        $this->assertStringContainsString('notification-content', $dashboardHtml);
        $this->assertStringContainsString('M3 8l7.89 5.26', $dashboardHtml);
        $this->assertStringContainsString('Hapus informasi', $dashboardHtml);
        $this->assertStringNotContainsString('Tandai semua dibaca', $dashboardHtml);
        $this->assertStringNotContainsString('Hapus semua', $dashboardHtml);
        $this->assertStringNotContainsString('Filter lanjutan', $dashboardHtml);
        $this->assertStringNotContainsString('notification-dashboard-snippets', $dashboardHtml);
        $this->assertStringNotContainsString('mt-1 h-2 w-2', $dashboardHtml);
    }

    public function test_pengajar_and_wali_notification_dashboard_preview_use_original_timeline_style(): void
    {
        $html = Blade::render('<x-notification-panel />');
        $dashboardHtml = Str::between($html, 'data-notification-dashboard-preview', 'data-testid="notification-modal"');

        $this->assertStringContainsString('data-testid="notification-open-button"', $html);
        $this->assertStringContainsString('data-testid="notification-dashboard-timeline"', $dashboardHtml);
        $this->assertStringContainsString('notifications-container', $dashboardHtml);
        $this->assertStringContainsString('notification-item', $dashboardHtml);
        $this->assertStringContainsString('notification-content', $dashboardHtml);
        $this->assertStringContainsString('M3 8l7.89 5.26', $dashboardHtml);
        $this->assertStringNotContainsString('Hapus informasi', $dashboardHtml);
        $this->assertStringNotContainsString('notification-dashboard-snippets', $dashboardHtml);
        $this->assertStringNotContainsString('mt-1 h-2 w-2', $dashboardHtml);
    }

    public function test_notification_modal_keeps_bulk_actions_and_simple_filters(): void
    {
        $html = Blade::render('<x-notification-panel :can-create="true" />');

        $this->assertStringContainsString('data-testid="notification-modal"', $html);
        $this->assertStringContainsString('Tandai semua dibaca', $html);
        $this->assertStringContainsString('Hapus semua', $html);
        $this->assertStringContainsString('Belum dibaca', $html);
        $this->assertStringContainsString('Sudah dibaca', $html);
        $this->assertStringContainsString('Filter lanjutan', $html);
        $this->assertStringContainsString('Semua sumber', $html);
        $this->assertStringContainsString('Guru/Pengajar', $html);
        $this->assertStringContainsString('Baca informasi terbaru dan kelola notifikasi Anda.', $html);
    }

    public function test_score_completion_notification_is_aggregated_per_class_subject_context(): void
    {
        $mataPelajaran = MataPelajaran::with('kelas')->firstOrFail();
        $method = new ReflectionMethod(ScoreController::class, 'sendScoreCompletionNotification');
        $method->setAccessible(true);

        $controller = app(ScoreController::class);
        $method->invoke($controller, $mataPelajaran, 3, 'Pak Budi');
        $method->invoke($controller, $mataPelajaran, 2, 'Pak Budi');

        $this->assertSame(1, Notification::where('title', 'Nilai Matematika Kelas 1 Ubay Disimpan')->count());

        $notification = Notification::where('title', 'Nilai Matematika Kelas 1 Ubay Disimpan')->firstOrFail();
        $this->assertSame('specific', $notification->target);
        $this->assertSame([$this->wali->id], $notification->specific_users);
        $this->assertStringContainsString('2 siswa', $notification->content);
        $this->assertStringNotContainsString('Ahmad', $notification->content);
    }

    public function test_unauthorized_user_cannot_bulk_update_notifications(): void
    {
        $this->postJson('/pengajar/notifications/mark-all-read')->assertUnauthorized();
        $this->deleteJson('/admin/information/delete-all')->assertUnauthorized();
    }

    private function createSchema(): void
    {
        foreach ([
            'notification_user_states',
            'notification_reads',
            'notifications',
            'mata_pelajarans',
            'guru_kelas',
            'kelas',
            'audit_logs',
            'profil_sekolah',
            'tahun_ajarans',
            'gurus',
            'users',
        ] as $table) {
            Schema::dropIfExists($table);
        }

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            $table->string('username')->nullable()->unique();
            $table->string('email')->nullable()->unique();
            $table->string('password');
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

        Schema::create('gurus', function (Blueprint $table) {
            $table->id();
            $table->string('nuptk')->nullable();
            $table->string('nama');
            $table->string('jenis_kelamin')->nullable();
            $table->string('jabatan')->nullable();
            $table->string('username')->nullable();
            $table->string('password');
            $table->boolean('must_change_password')->default(false);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('tahun_ajarans', function (Blueprint $table) {
            $table->id();
            $table->string('tahun_ajaran');
            $table->boolean('is_active')->default(false);
            $table->unsignedTinyInteger('semester')->default(1);
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
            $table->boolean('is_muatan_lokal')->default(false);
            $table->boolean('allow_non_wali')->default(false);
            $table->foreignId('tahun_ajaran_id')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('notifications', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->text('content');
            $table->enum('target', ['all', 'guru', 'wali_kelas', 'specific']);
            $table->json('specific_users')->nullable();
            $table->boolean('is_read')->default(false);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('notification_reads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('notification_id');
            $table->foreignId('guru_id');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
        });

        Schema::create('notification_user_states', function (Blueprint $table) {
            $table->id();
            $table->foreignId('notification_id');
            $table->string('user_type', 32);
            $table->unsignedBigInteger('user_id');
            $table->timestamp('read_at')->nullable();
            $table->timestamp('deleted_at')->nullable();
            $table->timestamps();
            $table->unique(['notification_id', 'user_type', 'user_id'], 'notification_user_state_unique');
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

        $this->yearId = DB::table('tahun_ajarans')->insertGetId([
            'tahun_ajaran' => '2026/2027',
            'is_active' => true,
            'semester' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->classId = DB::table('kelas')->insertGetId([
            'nomor_kelas' => 1,
            'nama_kelas' => 'Ubay',
            'tahun_ajaran_id' => $this->yearId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->pengajar = Guru::create([
            'nama' => 'Pak Budi',
            'jabatan' => 'guru',
            'username' => 'budi',
            'password' => Hash::make('password'),
        ]);

        $this->wali = Guru::create([
            'nama' => 'Bu Wali',
            'jabatan' => 'guru_wali',
            'username' => 'wali',
            'password' => Hash::make('password'),
        ]);

        DB::table('guru_kelas')->insert([
            [
                'guru_id' => $this->pengajar->id,
                'kelas_id' => $this->classId,
                'is_wali_kelas' => false,
                'role' => 'pengajar',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'guru_id' => $this->wali->id,
                'kelas_id' => $this->classId,
                'is_wali_kelas' => true,
                'role' => 'wali_kelas',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        DB::table('mata_pelajarans')->insert([
            'nama_pelajaran' => 'Matematika',
            'kelas_id' => $this->classId,
            'guru_id' => $this->pengajar->id,
            'semester' => 1,
            'tahun_ajaran_id' => $this->yearId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function insertNotification(string $title, string $content, string $target, ?array $specificUsers = null): int
    {
        return DB::table('notifications')->insertGetId([
            'title' => $title,
            'content' => $content,
            'target' => $target,
            'specific_users' => $specificUsers ? json_encode($specificUsers) : null,
            'is_read' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function adminSession(): array
    {
        return [
            'tahun_ajaran_id' => $this->yearId,
            'selected_semester' => 1,
        ];
    }

    private function pengajarSession(): array
    {
        return [
            'tahun_ajaran_id' => $this->yearId,
            'selected_semester' => 1,
            'selected_role' => 'pengajar',
        ];
    }

    private function waliSession(): array
    {
        return [
            'tahun_ajaran_id' => $this->yearId,
            'selected_semester' => 1,
            'selected_role' => 'wali_kelas',
        ];
    }
}

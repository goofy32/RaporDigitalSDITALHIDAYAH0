<?php

namespace Tests\Feature;

use App\Http\Controllers\NotificationController;
use App\Http\Controllers\ScoreController;
use App\Models\Guru;
use App\Models\MataPelajaran;
use App\Models\Notification;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request as HttpRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Carbon;
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

    public function test_admin_can_mark_unread_notification_as_read_with_one_user_state(): void
    {
        $notificationId = $this->insertNotification('Info Baru', 'Pesan admin', 'all');

        $this->actingAs($this->admin, 'web')
            ->withSession($this->adminSession())
            ->postJson("/admin/information/{$notificationId}/read")
            ->assertOk()
            ->assertJson([
                'success' => true,
                'message' => 'Notifikasi telah dibaca',
            ]);

        $this->assertSame(1, DB::table('notification_user_states')
            ->where('notification_id', $notificationId)
            ->where('user_type', 'admin')
            ->where('user_id', $this->admin->id)
            ->count());
        $this->assertNotNull(DB::table('notification_user_states')
            ->where('notification_id', $notificationId)
            ->where('user_type', 'admin')
            ->where('user_id', $this->admin->id)
            ->value('read_at'));
    }

    public function test_mark_as_read_repeatedly_remains_idempotent_and_uses_latest_read_time(): void
    {
        $notificationId = $this->insertNotification('Info Berulang', 'Pesan admin', 'all');

        try {
            Carbon::setTestNow('2026-07-18 08:00:00');
            $this->actingAs($this->admin, 'web')
                ->withSession($this->adminSession())
                ->postJson("/admin/information/{$notificationId}/read")
                ->assertOk()
                ->assertJson(['success' => true]);

            $createdAt = DB::table('notification_user_states')
                ->where('notification_id', $notificationId)
                ->where('user_type', 'admin')
                ->where('user_id', $this->admin->id)
                ->value('created_at');

            Carbon::setTestNow('2026-07-18 08:05:00');
            $this->actingAs($this->admin, 'web')
                ->withSession($this->adminSession())
                ->postJson("/admin/information/{$notificationId}/read")
                ->assertOk()
                ->assertJson(['success' => true]);

            $state = DB::table('notification_user_states')
                ->where('notification_id', $notificationId)
                ->where('user_type', 'admin')
                ->where('user_id', $this->admin->id)
                ->first();

            $this->assertSame(1, DB::table('notification_user_states')
                ->where('notification_id', $notificationId)
                ->where('user_type', 'admin')
                ->where('user_id', $this->admin->id)
                ->count());
            $this->assertSame($createdAt, $state->created_at);
            $this->assertSame('2026-07-18 08:05:00', Carbon::parse($state->read_at)->format('Y-m-d H:i:s'));
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_existing_admin_user_state_is_updated_without_duplicate_insert(): void
    {
        $notificationId = $this->insertNotification('Info Existing', 'Pesan admin', 'all');
        $createdAt = now()->subHour()->format('Y-m-d H:i:s');

        DB::table('notification_user_states')->insert([
            'notification_id' => $notificationId,
            'user_type' => 'admin',
            'user_id' => $this->admin->id,
            'read_at' => null,
            'deleted_at' => null,
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);

        $this->actingAs($this->admin, 'web')
            ->withSession($this->adminSession())
            ->postJson("/admin/information/{$notificationId}/read")
            ->assertOk()
            ->assertJson(['success' => true]);

        $this->assertSame(1, DB::table('notification_user_states')
            ->where('notification_id', $notificationId)
            ->where('user_type', 'admin')
            ->where('user_id', $this->admin->id)
            ->count());
        $state = DB::table('notification_user_states')
            ->where('notification_id', $notificationId)
            ->where('user_type', 'admin')
            ->where('user_id', $this->admin->id)
            ->first();
        $this->assertSame($createdAt, $state->created_at);
        $this->assertNotNull($state->read_at);
    }

    public function test_mark_all_as_read_remains_idempotent_for_existing_states(): void
    {
        $first = $this->insertNotification('Info Satu', 'Pesan admin', 'all');
        $second = $this->insertNotification('Info Dua', 'Pesan admin', 'all');

        $this->actingAs($this->admin, 'web')
            ->withSession($this->adminSession())
            ->postJson('/admin/information/mark-all-read')
            ->assertOk()
            ->assertJson(['success' => true]);

        $this->actingAs($this->admin, 'web')
            ->withSession($this->adminSession())
            ->postJson('/admin/information/mark-all-read')
            ->assertOk()
            ->assertJson(['success' => true]);

        foreach ([$first, $second] as $notificationId) {
            $this->assertSame(1, DB::table('notification_user_states')
                ->where('notification_id', $notificationId)
                ->where('user_type', 'admin')
                ->where('user_id', $this->admin->id)
                ->count());
        }

        $this->assertSame(2, DB::table('notification_user_states')->count());
    }

    public function test_notification_user_state_isolated_by_user_type(): void
    {
        $notificationId = $this->insertNotification('Info Isolasi', 'Pesan bersama', 'all');

        $this->assertSame($this->admin->id, $this->pengajar->id);

        $this->actingAs($this->admin, 'web')
            ->withSession($this->adminSession())
            ->postJson("/admin/information/{$notificationId}/read")
            ->assertOk();

        $this->actingAs($this->pengajar, 'guru')
            ->withSession($this->pengajarSession())
            ->postJson("/pengajar/notifications/{$notificationId}/read")
            ->assertOk();

        $this->assertDatabaseHas('notification_user_states', [
            'notification_id' => $notificationId,
            'user_type' => 'admin',
            'user_id' => $this->admin->id,
        ]);
        $this->assertDatabaseHas('notification_user_states', [
            'notification_id' => $notificationId,
            'user_type' => 'guru',
            'user_id' => $this->pengajar->id,
        ]);
        $this->assertSame(2, DB::table('notification_user_states')->where('notification_id', $notificationId)->count());
    }

    public function test_unread_count_decreases_after_marking_notifications_read(): void
    {
        $first = $this->insertNotification('Info Count Satu', 'Pesan admin', 'all');
        $this->insertNotification('Info Count Dua', 'Pesan admin', 'all');

        $this->actingAs($this->admin, 'web')
            ->withSession($this->adminSession())
            ->getJson('/admin/information/unread-count')
            ->assertOk()
            ->assertJson(['count' => 2]);

        $this->actingAs($this->admin, 'web')
            ->withSession($this->adminSession())
            ->postJson("/admin/information/{$first}/read")
            ->assertOk();

        $this->actingAs($this->admin, 'web')
            ->withSession($this->adminSession())
            ->getJson('/admin/information/unread-count')
            ->assertOk()
            ->assertJson(['count' => 1]);

        $this->actingAs($this->admin, 'web')
            ->withSession($this->adminSession())
            ->postJson('/admin/information/mark-all-read')
            ->assertOk();

        $this->actingAs($this->admin, 'web')
            ->withSession($this->adminSession())
            ->getJson('/admin/information/unread-count')
            ->assertOk()
            ->assertJson(['count' => 0]);
    }

    public function test_unknown_or_unauthorized_notification_cannot_be_marked_read(): void
    {
        $specificForWali = $this->insertNotification('Info Wali Rahasia', 'Pesan wali', 'specific', [$this->wali->id]);

        $this->actingAs($this->admin, 'web')
            ->withSession($this->adminSession())
            ->postJson('/admin/information/999999/read')
            ->assertNotFound();

        $this->actingAs($this->pengajar, 'guru')
            ->withSession($this->pengajarSession())
            ->postJson("/pengajar/notifications/{$specificForWali}/read")
            ->assertForbidden()
            ->assertJson([
                'success' => false,
                'message' => 'Notifikasi tidak tersedia untuk akun ini.',
            ]);

        $this->assertSame(0, DB::table('notification_user_states')->count());
        $this->assertSame(0, DB::table('notification_reads')->count());
    }

    public function test_plain_web_mark_as_read_request_keeps_json_success_response(): void
    {
        $notificationId = $this->insertNotification('Info Web', 'Pesan admin', 'all');

        $this->actingAs($this->admin, 'web')
            ->withSession($this->adminSession())
            ->post("/admin/information/{$notificationId}/read")
            ->assertOk()
            ->assertJson([
                'success' => true,
                'message' => 'Notifikasi telah dibaca',
            ]);
    }

    public function test_static_notification_bulk_routes_are_not_captured_by_dynamic_notification_routes(): void
    {
        $routes = [
            ['POST', '/admin/information/mark-all-read', NotificationController::class . '@markAllAsRead'],
            ['DELETE', '/admin/information/delete-all', NotificationController::class . '@destroyAll'],
            ['POST', '/pengajar/notifications/mark-all-read', NotificationController::class . '@markAllAsRead'],
            ['DELETE', '/pengajar/notifications/delete-all', NotificationController::class . '@destroyAll'],
            ['POST', '/wali-kelas/notifications/mark-all-read', NotificationController::class . '@markAllAsRead'],
            ['DELETE', '/wali-kelas/notifications/delete-all', NotificationController::class . '@destroyAll'],
            ['POST', '/admin/information/123/read', NotificationController::class . '@markAsRead'],
            ['DELETE', '/admin/information/123', NotificationController::class . '@destroy'],
            ['POST', '/pengajar/notifications/123/read', NotificationController::class . '@markAsRead'],
            ['DELETE', '/pengajar/notifications/123', NotificationController::class . '@destroy'],
            ['POST', '/wali-kelas/notifications/123/read', NotificationController::class . '@markAsRead'],
            ['DELETE', '/wali-kelas/notifications/123', NotificationController::class . '@destroy'],
        ];

        foreach ($routes as [$method, $path, $expectedAction]) {
            $route = Route::getRoutes()->match(HttpRequest::create($path, $method));

            $this->assertSame($expectedAction, $route->getActionName(), "{$method} {$path}");
        }
    }

    public function test_dynamic_notification_action_routes_require_numeric_notification_ids(): void
    {
        $adminReadResponse = $this->actingAs($this->admin, 'web')
            ->withSession($this->adminSession())
            ->postJson('/admin/information/not-a-number/read');
        $this->assertContains($adminReadResponse->getStatusCode(), [404, 405]);

        $adminDeleteResponse = $this->actingAs($this->admin, 'web')
            ->withSession($this->adminSession())
            ->deleteJson('/admin/information/delete-all-extra');
        $this->assertContains($adminDeleteResponse->getStatusCode(), [404, 405]);

        $pengajarReadResponse = $this->actingAs($this->pengajar, 'guru')
            ->withSession($this->pengajarSession())
            ->postJson('/pengajar/notifications/not-a-number/read');
        $this->assertContains($pengajarReadResponse->getStatusCode(), [404, 405]);

        $waliDeleteResponse = $this->actingAs($this->wali, 'guru')
            ->withSession($this->waliSession())
            ->deleteJson('/wali-kelas/notifications/not-a-number');
        $this->assertContains($waliDeleteResponse->getStatusCode(), [404, 405]);
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

        $this->assertDatabaseHas('notification_user_states', [
            'notification_id' => $forPengajar,
            'user_type' => 'guru',
            'user_id' => $this->pengajar->id,
        ]);
        $this->assertDatabaseMissing('notification_user_states', [
            'notification_id' => $forWali,
            'user_type' => 'guru',
            'user_id' => $this->pengajar->id,
        ]);

        $this->actingAs($this->wali, 'guru')
            ->withSession($this->waliSession())
            ->postJson('/wali-kelas/notifications/mark-all-read')
            ->assertOk()
            ->assertJson(['success' => true]);

        $this->assertDatabaseHas('notification_user_states', [
            'notification_id' => $forWali,
            'user_type' => 'guru',
            'user_id' => $this->wali->id,
        ]);
        $this->assertSame(0, DB::table('notification_reads')->count());
    }

    public function test_admin_can_delete_one_notification_globally_for_every_recipient(): void
    {
        $notificationId = $this->insertNotification('Info Guru Global', 'Pesan untuk guru', 'guru');

        DB::table('notification_user_states')->insert([
            [
                'notification_id' => $notificationId,
                'user_type' => 'admin',
                'user_id' => $this->admin->id,
                'read_at' => now(),
                'deleted_at' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'notification_id' => $notificationId,
                'user_type' => 'guru',
                'user_id' => $this->pengajar->id,
                'read_at' => null,
                'deleted_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        DB::table('notification_reads')->insert([
            'notification_id' => $notificationId,
            'guru_id' => $this->pengajar->id,
            'read_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($this->admin, 'web')
            ->withSession($this->adminSession())
            ->deleteJson("/admin/information/{$notificationId}")
            ->assertOk()
            ->assertJson([
                'success' => true,
                'message' => 'Notifikasi berhasil dihapus.',
            ]);

        $this->assertNotNull(DB::table('notifications')->where('id', $notificationId)->value('deleted_at'));
        $this->assertSame(0, DB::table('notification_user_states')->where('notification_id', $notificationId)->count());
        $this->assertSame(0, DB::table('notification_reads')->where('notification_id', $notificationId)->count());

        $pengajarList = $this->actingAs($this->pengajar, 'guru')
            ->withSession($this->pengajarSession())
            ->getJson('/pengajar/notifications')
            ->assertOk();
        $this->assertCount(0, $pengajarList->json('items'));
    }

    public function test_admin_single_delete_uses_historical_scope_for_system_classified_notifications(): void
    {
        $notificationId = $this->insertNotification(
            'Batch Rapor UTS Kelas 1 Siap Diunduh',
            'Generate batch rapor UTS telah selesai.',
            'specific',
            [$this->wali->id]
        );

        $adminList = $this->actingAs($this->admin, 'web')
            ->withSession($this->adminSession())
            ->getJson('/admin/information/list')
            ->assertOk();

        $item = collect($adminList->json('items'))->firstWhere('id', $notificationId);
        $this->assertSame('sistem', $item['source']);
        $this->assertSame('rapor', $item['category']);

        $this->actingAs($this->admin, 'web')
            ->withSession($this->adminSession())
            ->deleteJson("/admin/information/{$notificationId}")
            ->assertOk()
            ->assertJson([
                'success' => true,
                'message' => 'Notifikasi berhasil dihapus.',
            ]);

        $this->assertNotNull(DB::table('notifications')->where('id', $notificationId)->value('deleted_at'));
    }

    public function test_admin_can_delete_all_managed_notifications_globally(): void
    {
        $shared = $this->insertNotification('Info Bersama', 'Pesan bersama', 'all');
        $guruOnly = $this->insertNotification('Info Guru', 'Pesan guru', 'guru');
        $waliOnly = $this->insertNotification('Info Wali', 'Pesan wali', 'wali_kelas');
        $specific = $this->insertNotification('Info Spesifik', 'Pesan wali spesifik', 'specific', [$this->wali->id]);
        $systemClassified = $this->insertNotification(
            'Semester Genap 2026/2027 Dimulai',
            'Tahun ajaran semester genap telah dimulai.',
            'guru'
        );

        DB::table('notification_user_states')->insert([
            [
                'notification_id' => $shared,
                'user_type' => 'admin',
                'user_id' => $this->admin->id,
                'read_at' => now(),
                'deleted_at' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'notification_id' => $specific,
                'user_type' => 'guru',
                'user_id' => $this->wali->id,
                'read_at' => now(),
                'deleted_at' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        DB::table('notification_reads')->insert([
            'notification_id' => $guruOnly,
            'guru_id' => $this->pengajar->id,
            'read_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($this->admin, 'web')
            ->withSession($this->adminSession())
            ->deleteJson('/admin/information/delete-all')
            ->assertOk()
            ->assertJson([
                'success' => true,
                'count' => 5,
            ]);

        $this->actingAs($this->admin, 'web')
            ->withSession($this->adminSession())
            ->deleteJson('/admin/information/delete-all')
            ->assertOk()
            ->assertJson([
                'success' => true,
                'count' => 0,
            ]);

        foreach ([$shared, $guruOnly, $waliOnly, $specific, $systemClassified] as $notificationId) {
            $this->assertNotNull(DB::table('notifications')->where('id', $notificationId)->value('deleted_at'));
        }

        $this->assertSame(0, DB::table('notification_user_states')->count());
        $this->assertSame(0, DB::table('notification_reads')->count());

        $adminList = $this->actingAs($this->admin, 'web')
            ->withSession($this->adminSession())
            ->getJson('/admin/information/list')
            ->assertOk();
        $this->assertCount(0, $adminList->json('items'));

        $pengajarList = $this->actingAs($this->pengajar, 'guru')
            ->withSession($this->pengajarSession())
            ->getJson('/pengajar/notifications')
            ->assertOk();
        $this->assertCount(0, $pengajarList->json('items'));

        $waliList = $this->actingAs($this->wali, 'guru')
            ->withSession($this->waliSession())
            ->getJson('/wali-kelas/notifications')
            ->assertOk();
        $this->assertCount(0, $waliList->json('items'));
    }

    public function test_admin_delete_all_with_no_matching_notifications_returns_success_count_zero(): void
    {
        $this->actingAs($this->admin, 'web')
            ->withSession($this->adminSession())
            ->deleteJson('/admin/information/delete-all')
            ->assertOk()
            ->assertJson([
                'success' => true,
                'count' => 0,
            ]);

        $this->assertSame(0, DB::table('notifications')->count());
        $this->assertSame(0, DB::table('notification_user_states')->count());
        $this->assertSame(0, DB::table('notification_reads')->count());
    }

    public function test_pengajar_can_delete_one_notification_only_for_self(): void
    {
        $shared = $this->insertNotification('Info Bersama', 'Pesan bersama', 'all');
        $guruOnly = $this->insertNotification('Info Guru', 'Pesan guru', 'guru');

        $this->actingAs($this->pengajar, 'guru')
            ->withSession($this->pengajarSession())
            ->deleteJson("/pengajar/notifications/{$shared}")
            ->assertOk()
            ->assertJson([
                'success' => true,
                'message' => 'Notifikasi berhasil dihapus dari daftar Anda.',
            ]);

        $this->assertNull(DB::table('notifications')->where('id', $shared)->value('deleted_at'));
        $this->assertDatabaseHas('notification_user_states', [
            'notification_id' => $shared,
            'user_type' => 'guru',
            'user_id' => $this->pengajar->id,
        ]);
        $this->assertNotNull(DB::table('notification_user_states')
            ->where('notification_id', $shared)
            ->where('user_type', 'guru')
            ->where('user_id', $this->pengajar->id)
            ->value('deleted_at'));

        $pengajarList = $this->actingAs($this->pengajar, 'guru')
            ->withSession($this->pengajarSession())
            ->getJson('/pengajar/notifications')
            ->assertOk();
        $this->assertSame([$guruOnly], collect($pengajarList->json('items'))->pluck('id')->all());

        $waliList = $this->actingAs($this->wali, 'guru')
            ->withSession($this->waliSession())
            ->getJson('/wali-kelas/notifications')
            ->assertOk();
        $this->assertContains($shared, collect($waliList->json('items'))->pluck('id')->all());

        $adminList = $this->actingAs($this->admin, 'web')
            ->withSession($this->adminSession())
            ->getJson('/admin/information/list')
            ->assertOk();
        $this->assertContains($shared, collect($adminList->json('items'))->pluck('id')->all());
    }

    public function test_wali_delete_all_dismisses_only_visible_notifications_for_self(): void
    {
        $shared = $this->insertNotification('Info Bersama', 'Pesan bersama', 'all');
        $guruOnly = $this->insertNotification('Info Guru', 'Pesan guru', 'guru');
        $waliOnly = $this->insertNotification('Info Wali', 'Pesan wali', 'wali_kelas');

        $this->actingAs($this->wali, 'guru')
            ->withSession($this->waliSession())
            ->deleteJson('/wali-kelas/notifications/delete-all')
            ->assertOk()
            ->assertJson([
                'success' => true,
                'count' => 2,
            ]);

        $this->actingAs($this->wali, 'guru')
            ->withSession($this->waliSession())
            ->deleteJson('/wali-kelas/notifications/delete-all')
            ->assertOk()
            ->assertJson([
                'success' => true,
                'count' => 0,
            ]);

        foreach ([$shared, $guruOnly, $waliOnly] as $notificationId) {
            $this->assertNull(DB::table('notifications')->where('id', $notificationId)->value('deleted_at'));
        }

        foreach ([$shared, $waliOnly] as $notificationId) {
            $this->assertNotNull(DB::table('notification_user_states')
                ->where('notification_id', $notificationId)
                ->where('user_type', 'guru')
                ->where('user_id', $this->wali->id)
                ->value('deleted_at'));
        }
        $this->assertDatabaseMissing('notification_user_states', [
            'notification_id' => $guruOnly,
            'user_type' => 'guru',
            'user_id' => $this->wali->id,
        ]);

        $waliList = $this->actingAs($this->wali, 'guru')
            ->withSession($this->waliSession())
            ->getJson('/wali-kelas/notifications')
            ->assertOk();
        $this->assertCount(0, $waliList->json('items'));

        $pengajarList = $this->actingAs($this->pengajar, 'guru')
            ->withSession($this->pengajarSession())
            ->getJson('/pengajar/notifications')
            ->assertOk();
        $this->assertCount(2, $pengajarList->json('items'));

        $adminList = $this->actingAs($this->admin, 'web')
            ->withSession($this->adminSession())
            ->getJson('/admin/information/list')
            ->assertOk();
        $this->assertCount(3, $adminList->json('items'));
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
        $this->assertStringContainsString('role="alert"', $html);
        $this->assertStringContainsString('$store.notification.errorMessage', $html);
        $this->assertStringContainsString('Tandai semua dibaca', $html);
        $this->assertStringContainsString('Hapus semua', $html);
        $this->assertStringContainsString('Hapus', $html);
        $this->assertStringContainsString('Belum dibaca', $html);
        $this->assertStringContainsString('Sudah dibaca', $html);
        $this->assertStringContainsString('Filter lanjutan', $html);
        $this->assertStringContainsString('Semua sumber', $html);
        $this->assertStringContainsString('Guru/Pengajar', $html);
        $this->assertStringContainsString('Baca informasi terbaru dan kelola notifikasi Anda.', $html);
        $this->assertStringContainsString('$store.notification.deleteAllNotifications()', $html);
        $this->assertStringNotContainsString('$store.notification.deleteAllOwn()', $html);
    }

    public function test_notification_store_guards_duplicate_mutation_submissions_and_surfaces_errors(): void
    {
        $store = file_get_contents(resource_path('js/stores/notification-store.js'));

        $this->assertStringContainsString('markingReadIds: new Set()', $store);
        $this->assertStringContainsString('this.markingReadIds.has(markingKey)', $store);
        $this->assertStringContainsString('acquiredMarkingLock', $store);
        $this->assertStringContainsString('markingAllAsRead: false', $store);
        $this->assertStringContainsString('deletingNotificationIds: new Set()', $store);
        $this->assertStringContainsString('deletingAllNotifications: false', $store);
        $this->assertStringContainsString('errorMessage: \'\'', $store);
        $this->assertStringContainsString('this.deletingNotificationIds.has(deleteKey)', $store);
        $this->assertStringContainsString('this.deletingAllNotifications', $store);
        $this->assertStringContainsString('setError(message)', $store);
        $this->assertStringContainsString('this.items = this.items.map(entry => String(entry.id) === markingKey ? { ...entry, is_read: true } : entry);', $store);
        $this->assertStringContainsString('this.items = this.items.filter(item => String(item.id) !== deleteKey);', $store);
        $this->assertStringContainsString('Hapus semua notifikasi secara global?', $store);
        $this->assertStringContainsString('deleteAllNotifications()', $store);
        $this->assertStringNotContainsString('deleteAllOwn()', $store);

        $markOneBody = Str::between($store, 'async markAsRead(notificationId, options = {})', 'async markAllAsRead()');
        $this->assertStringNotContainsString('fetchUnreadCount()', $markOneBody);

        $deleteAllBody = Str::between($store, 'async deleteAllNotifications()', 'startAutoRefresh()');
        $this->assertStringNotContainsString('this.items.length === 0', $deleteAllBody);
        $this->assertStringContainsString('fetch(`${baseUrl}/delete-all`', $deleteAllBody);
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

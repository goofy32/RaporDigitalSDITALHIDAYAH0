<?php

namespace App\Http\Controllers;

use App\Events\NotificationCreated;
use App\Models\Guru;
use App\Models\Notification;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class NotificationController extends Controller
{
    public function store(Request $request)
    {
        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'content' => 'required|string',
            'target' => 'required|in:all,guru,wali_kelas,specific',
            'specific_users' => 'required_if:target,specific|array',
        ]);

        try {
            $notification = new Notification();
            $notification->title = $validated['title'];
            $notification->content = $validated['content'];
            $notification->target = $validated['target'];

            if ($validated['target'] === 'specific' && ! empty($validated['specific_users'])) {
                $notification->specific_users = array_map('intval', $validated['specific_users']);
            }

            $notification->save();

            event(new NotificationCreated($notification));

            return response()->json([
                'success' => true,
                'message' => 'Notifikasi berhasil ditambahkan',
                'notification' => $this->serializeNotification($notification, $this->currentActor()),
            ]);
        } catch (\Exception $e) {
            Log::error('Notification creation error: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Gagal menambahkan notifikasi: ' . $e->getMessage(),
            ], 500);
        }
    }

    public function list()
    {
        try {
            $actor = $this->currentActor();

            if (! $actor || $actor['type'] !== 'admin') {
                return response()->json(['items' => []], 403);
            }

            $notifications = $this->visibleNotificationsFor($actor)
                ->latest()
                ->limit(80)
                ->get();

            $specificUserNames = $this->specificUserNames($notifications);

            return response()->json([
                'items' => $notifications
                    ->map(fn ($notification) => $this->serializeNotification($notification, $actor, $specificUserNames))
                    ->values(),
            ]);
        } catch (\Exception $e) {
            Log::error('Error fetching notifications list: ' . $e->getMessage());

            return response()->json(['items' => []], 500);
        }
    }

    public function index()
    {
        try {
            $actor = $this->currentActor();

            if (! $actor || $actor['type'] !== 'guru') {
                return response()->json(['items' => []], 403);
            }

            $notifications = $this->visibleNotificationsFor($actor)
                ->with(['readers' => function ($query) use ($actor) {
                    $query->where('guru_id', $actor['id']);
                }])
                ->latest()
                ->limit(80)
                ->get();

            $specificUserNames = $this->specificUserNames($notifications);

            return response()->json([
                'items' => $notifications
                    ->map(fn ($notification) => $this->serializeNotification($notification, $actor, $specificUserNames))
                    ->values(),
            ]);
        } catch (\Exception $e) {
            Log::error('Error fetching notifications: ' . $e->getMessage());

            return response()->json(['items' => []], 500);
        }
    }

    public function markAsRead(Notification $notification)
    {
        try {
            $actor = $this->currentActor();

            if (! $actor || ! $this->isVisibleToActor($notification, $actor)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Notifikasi tidak tersedia untuk akun ini.',
                ], 403);
            }

            $this->markNotificationReadForActor($notification, $actor);

            return response()->json([
                'success' => true,
                'message' => 'Notifikasi telah dibaca',
            ]);
        } catch (\Exception $e) {
            Log::error('Error marking notification as read: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Gagal menandai notifikasi sebagai telah dibaca',
            ], 500);
        }
    }

    public function markAllAsRead()
    {
        try {
            $actor = $this->currentActor();

            if (! $actor) {
                return response()->json(['success' => false], 403);
            }

            $notifications = $this->visibleNotificationsFor($actor)->get();

            foreach ($notifications as $notification) {
                $this->markNotificationReadForActor($notification, $actor);
            }

            return response()->json([
                'success' => true,
                'message' => 'Semua notifikasi Anda telah ditandai dibaca.',
                'count' => $notifications->count(),
            ]);
        } catch (\Exception $e) {
            Log::error('Error marking all notifications as read: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Gagal menandai semua notifikasi sebagai dibaca.',
            ], 500);
        }
    }

    public function getUnreadCount()
    {
        try {
            $actor = $this->currentActor();

            if (! $actor) {
                return response()->json(['count' => 0], 401);
            }

            $count = $this->visibleNotificationsFor($actor)
                ->get()
                ->filter(fn ($notification) => ! $this->isReadByActor($notification, $actor))
                ->count();

            return response()->json(['count' => $count]);
        } catch (\Exception $e) {
            Log::error('Error getting unread count: ' . $e->getMessage());

            return response()->json(['count' => 0], 500);
        }
    }

    public function destroy(Notification $notification)
    {
        try {
            $actor = $this->currentActor();

            if (! $actor || ! $this->isVisibleToActor($notification, $actor)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Notifikasi tidak tersedia untuk akun ini.',
                ], 403);
            }

            $this->dismissNotificationForActor($notification->id, $actor);

            return response()->json([
                'success' => true,
                'message' => 'Notifikasi berhasil dihapus dari daftar Anda.',
            ]);
        } catch (\Exception $e) {
            Log::error('Error deleting notification: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Gagal menghapus notifikasi',
            ], 500);
        }
    }

    public function destroyAllMine()
    {
        try {
            $actor = $this->currentActor();

            if (! $actor) {
                return response()->json(['success' => false], 403);
            }

            $notificationIds = $this->visibleNotificationsFor($actor)->pluck('id');

            foreach ($notificationIds as $notificationId) {
                $this->dismissNotificationForActor((int) $notificationId, $actor);
            }

            return response()->json([
                'success' => true,
                'message' => 'Semua notifikasi Anda telah dihapus.',
                'count' => $notificationIds->count(),
            ]);
        } catch (\Exception $e) {
            Log::error('Error deleting all notifications: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Gagal menghapus semua notifikasi.',
            ], 500);
        }
    }

    private function currentActor(): ?array
    {
        if (Auth::guard('guru')->check()) {
            $guru = Auth::guard('guru')->user();

            return [
                'type' => 'guru',
                'id' => (int) $guru->id,
                'role' => session('selected_role') === 'wali_kelas' ? 'wali_kelas' : 'guru',
            ];
        }

        if (Auth::guard('web')->check()) {
            return [
                'type' => 'admin',
                'id' => (int) Auth::guard('web')->id(),
                'role' => 'admin',
            ];
        }

        return null;
    }

    private function visibleNotificationsFor(array $actor): Builder
    {
        $query = Notification::select([
            'id',
            'title',
            'content',
            'target',
            'specific_users',
            'is_read',
            'created_at',
        ]);

        if ($actor['type'] === 'guru') {
            $query->where(function ($builder) use ($actor) {
                $builder->where('target', 'all')
                    ->orWhere('target', $actor['role'])
                    ->orWhere(function ($specificQuery) use ($actor) {
                        $specificQuery->where('target', 'specific')
                            ->whereJsonContains('specific_users', (int) $actor['id']);
                    });
            });
        }

        $this->excludeDismissedForActor($query, $actor);

        return $query;
    }

    private function excludeDismissedForActor(Builder $query, array $actor): void
    {
        if (! Schema::hasTable('notification_user_states')) {
            return;
        }

        $query->whereNotExists(function ($subquery) use ($actor) {
            $subquery->select(DB::raw(1))
                ->from('notification_user_states')
                ->whereColumn('notification_user_states.notification_id', 'notifications.id')
                ->where('notification_user_states.user_type', $actor['type'])
                ->where('notification_user_states.user_id', $actor['id'])
                ->whereNotNull('notification_user_states.deleted_at');
        });
    }

    private function isVisibleToActor(Notification $notification, array $actor): bool
    {
        return $this->visibleNotificationsFor($actor)
            ->where('id', $notification->id)
            ->exists();
    }

    private function markNotificationReadForActor(Notification $notification, array $actor): void
    {
        if ($actor['type'] === 'guru') {
            if (! $notification->readers()->where('guru_id', $actor['id'])->exists()) {
                $notification->readers()->attach($actor['id'], ['read_at' => now()]);
            }

            return;
        }

        if (Schema::hasTable('notification_user_states')) {
            $this->upsertUserState($notification->id, $actor, ['read_at' => now()]);
            return;
        }

        $notification->update(['is_read' => true]);
    }

    private function dismissNotificationForActor(int $notificationId, array $actor): void
    {
        if (! Schema::hasTable('notification_user_states')) {
            if ($actor['type'] === 'admin') {
                Notification::whereKey($notificationId)->delete();
            }

            return;
        }

        $this->upsertUserState($notificationId, $actor, [
            'read_at' => now(),
            'deleted_at' => now(),
        ]);
    }

    private function upsertUserState(int $notificationId, array $actor, array $values): void
    {
        $timestamp = now();
        $keys = [
            'notification_id' => $notificationId,
            'user_type' => $actor['type'],
            'user_id' => $actor['id'],
        ];

        $row = array_merge($keys, [
            'read_at' => $values['read_at'] ?? null,
            'deleted_at' => $values['deleted_at'] ?? null,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        $updateColumns = collect(['read_at', 'deleted_at'])
            ->filter(fn (string $column) => array_key_exists($column, $values))
            ->push('updated_at')
            ->values()
            ->all();

        DB::table('notification_user_states')->upsert(
            [$row],
            ['notification_id', 'user_type', 'user_id'],
            $updateColumns
        );
    }

    private function isReadByActor(Notification $notification, array $actor): bool
    {
        if ($actor['type'] === 'guru') {
            return $notification->isReadBy($actor['id']);
        }

        if (Schema::hasTable('notification_user_states')) {
            return DB::table('notification_user_states')
                ->where('notification_id', $notification->id)
                ->where('user_type', $actor['type'])
                ->where('user_id', $actor['id'])
                ->whereNotNull('read_at')
                ->exists();
        }

        return (bool) $notification->is_read;
    }

    private function serializeNotification(Notification $notification, ?array $actor, ?Collection $specificUserNames = null): array
    {
        $targetDisplay = $this->getTargetDisplay($notification, $specificUserNames);

        return [
            'id' => $notification->id,
            'title' => $notification->title,
            'content' => $notification->content,
            'target' => $notification->target,
            'specific_users' => $notification->specific_users,
            'target_display' => $targetDisplay,
            'source' => $this->notificationSource($notification),
            'source_label' => $this->notificationSourceLabel($notification),
            'category' => $this->notificationCategory($notification),
            'category_label' => $this->notificationCategoryLabel($notification),
            'created_at' => $notification->created_at?->toISOString(),
            'is_read' => $actor ? $this->isReadByActor($notification, $actor) : false,
        ];
    }

    private function specificUserNames(Collection $notifications): Collection
    {
        $singleSpecificUserIds = $notifications
            ->filter(function ($notification) {
                return $notification->target === 'specific'
                    && is_array($notification->specific_users)
                    && count($notification->specific_users) === 1;
            })
            ->map(fn ($notification) => (int) $notification->specific_users[0])
            ->unique()
            ->values();

        return Guru::whereIn('id', $singleSpecificUserIds)->pluck('nama', 'id');
    }

    private function notificationSource(Notification $notification): string
    {
        $category = $this->notificationCategory($notification);

        if ($category === 'nilai') {
            return 'guru';
        }

        if ($category === 'tahun_ajaran' || $category === 'rapor') {
            return 'sistem';
        }

        return match ($notification->target) {
            'wali_kelas' => 'admin',
            'specific' => 'admin',
            'guru' => 'admin',
            'all' => 'admin',
            default => 'sistem',
        };
    }

    private function notificationSourceLabel(Notification $notification): string
    {
        return match ($this->notificationSource($notification)) {
            'admin' => 'Admin',
            'guru' => 'Guru/Pengajar',
            'wali_kelas' => 'Wali Kelas',
            default => 'Sistem',
        };
    }

    private function notificationCategory(Notification $notification): string
    {
        $text = mb_strtolower($notification->title . ' ' . $notification->content);

        return match (true) {
            str_contains($text, 'nilai') || str_contains($text, 'score') => 'nilai',
            str_contains($text, 'rapor') || str_contains($text, 'pdf') => 'rapor',
            str_contains($text, 'template') => 'template',
            str_contains($text, 'tahun ajaran') || str_contains($text, 'semester') => 'tahun_ajaran',
            default => 'sistem',
        };
    }

    private function notificationCategoryLabel(Notification $notification): string
    {
        return match ($this->notificationCategory($notification)) {
            'nilai' => 'Nilai',
            'rapor' => 'Rapor',
            'template' => 'Template',
            'tahun_ajaran' => 'Tahun Ajaran',
            default => 'Sistem',
        };
    }

    private function getTargetDisplay($notification, $specificUserNames = null): string
    {
        switch ($notification->target) {
            case 'all':
                return 'Semua';
            case 'guru':
                return 'Semua Guru';
            case 'wali_kelas':
                return 'Semua Wali Kelas';
            case 'specific':
                if (empty($notification->specific_users)) {
                    return 'Guru Tertentu';
                }

                if (count($notification->specific_users) === 1) {
                    $guruId = (int) $notification->specific_users[0];

                    if ($specificUserNames && $specificUserNames->has($guruId)) {
                        return $specificUserNames->get($guruId);
                    }

                    $guru = Guru::find($guruId);
                    if ($guru) {
                        return $guru->nama;
                    }
                }

                return count($notification->specific_users) . ' Guru Tertentu';
            default:
                return 'Semua';
        }
    }
}

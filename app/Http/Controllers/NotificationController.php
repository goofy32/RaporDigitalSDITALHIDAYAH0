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

            $notificationIds = $this->visibleNotificationsFor($actor)
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->values();

            $this->markNotificationsReadForActor($notificationIds, $actor);

            return response()->json([
                'success' => true,
                'message' => 'Semua notifikasi Anda telah ditandai dibaca.',
                'count' => $notificationIds->count(),
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

            $count = $this->unreadNotificationsFor($actor)->count();

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

            if (! $actor) {
                return response()->json([
                    'success' => false,
                    'message' => 'Notifikasi tidak tersedia untuk akun ini.',
                ], 403);
            }

            if ($actor['type'] === 'admin') {
                if (! $this->isAdminManagedNotification($notification)) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Notifikasi tidak tersedia untuk akun ini.',
                    ], 403);
                }

                $this->deleteNotificationGlobally($notification);

                return response()->json([
                    'success' => true,
                    'message' => 'Notifikasi berhasil dihapus.',
                ]);
            }

            if (! $this->isVisibleToActor($notification, $actor)) {
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

    public function destroyAll()
    {
        try {
            $actor = $this->currentActor();

            if (! $actor) {
                return response()->json(['success' => false], 403);
            }

            if ($actor['type'] === 'admin') {
                $deletedCount = $this->deleteAllManagedNotificationsGlobally();

                return response()->json([
                    'success' => true,
                    'message' => 'Semua notifikasi berhasil dihapus.',
                    'count' => $deletedCount,
                ]);
            }

            $notificationIds = $this->visibleNotificationsFor($actor)->pluck('id');

            $this->dismissNotificationsForActor($notificationIds, $actor);

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
        if (request()->is('admin/*') && Auth::guard('web')->check()) {
            return [
                'type' => 'admin',
                'id' => (int) Auth::guard('web')->id(),
                'role' => 'admin',
            ];
        }

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
        $query = $actor['type'] === 'admin'
            ? $this->adminManagedNotificationsQuery()
            : Notification::query();

        $query->select([
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

    private function adminManagedNotificationsQuery(): Builder
    {
        // Historical Admin notification management listed and deleted all active notification rows.
        // The schema has no persisted source/owner flag to separate Admin-created and system-created rows.
        return Notification::query();
    }

    private function isAdminManagedNotification(Notification $notification): bool
    {
        return $this->adminManagedNotificationsQuery()
            ->whereKey($notification->id)
            ->exists();
    }

    private function unreadNotificationsFor(array $actor): Builder
    {
        $query = $this->visibleNotificationsFor($actor);

        if (Schema::hasTable('notification_user_states')) {
            $query->whereNotExists(function ($subquery) use ($actor) {
                $subquery->select(DB::raw(1))
                    ->from('notification_user_states')
                    ->whereColumn('notification_user_states.notification_id', 'notifications.id')
                    ->where('notification_user_states.user_type', $actor['type'])
                    ->where('notification_user_states.user_id', $actor['id'])
                    ->whereNotNull('notification_user_states.read_at');
            });

            if ($actor['type'] !== 'guru' || ! Schema::hasTable('notification_reads')) {
                return $query;
            }
        }

        if ($actor['type'] === 'guru' && Schema::hasTable('notification_reads')) {
            $query->whereDoesntHave('readers', function ($readerQuery) use ($actor) {
                $readerQuery->where('guru_id', $actor['id']);
            });

            return $query;
        }

        if (! Schema::hasTable('notification_user_states')) {
            $query->where('is_read', false);
        }

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
        $this->markNotificationsReadForActor(collect([(int) $notification->id]), $actor);
    }

    private function markNotificationsReadForActor(Collection $notificationIds, array $actor): void
    {
        $notificationIds = $notificationIds
            ->map(fn ($notificationId) => (int) $notificationId)
            ->filter()
            ->unique()
            ->values();

        if ($notificationIds->isEmpty()) {
            return;
        }

        if (Schema::hasTable('notification_user_states')) {
            $this->upsertUserStates($notificationIds, $actor, ['read_at' => now()]);
            return;
        }

        if ($actor['type'] === 'guru') {
            foreach ($notificationIds as $notificationId) {
                $notification = Notification::find($notificationId);
                if ($notification && ! $notification->readers()->where('guru_id', $actor['id'])->exists()) {
                    $notification->readers()->attach($actor['id'], ['read_at' => now()]);
                }
            }

            return;
        }

        Notification::whereIn('id', $notificationIds)->update(['is_read' => true]);
    }

    private function dismissNotificationForActor(int $notificationId, array $actor): void
    {
        $this->dismissNotificationsForActor(collect([$notificationId]), $actor);
    }

    private function dismissNotificationsForActor(Collection $notificationIds, array $actor): void
    {
        if (! Schema::hasTable('notification_user_states')) {
            if ($actor['type'] === 'admin') {
                Notification::whereIn('id', $notificationIds)->delete();
            }

            return;
        }

        $this->upsertUserStates($notificationIds, $actor, [
            'read_at' => now(),
            'deleted_at' => now(),
        ]);
    }

    private function upsertUserState(int $notificationId, array $actor, array $values): void
    {
        $this->upsertUserStates(collect([$notificationId]), $actor, $values);
    }

    private function upsertUserStates(Collection $notificationIds, array $actor, array $values): void
    {
        $timestamp = now();
        $rows = $notificationIds
            ->map(fn ($notificationId) => (int) $notificationId)
            ->filter()
            ->unique()
            ->map(fn (int $notificationId) => [
                'notification_id' => $notificationId,
                'user_type' => $actor['type'],
                'user_id' => $actor['id'],
                'read_at' => $values['read_at'] ?? null,
                'deleted_at' => $values['deleted_at'] ?? null,
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ])
            ->values();

        if ($rows->isEmpty()) {
            return;
        }

        $updateColumns = collect(['read_at', 'deleted_at'])
            ->filter(fn (string $column) => array_key_exists($column, $values))
            ->push('updated_at')
            ->values()
            ->all();

        $rows
            ->chunk(500)
            ->each(function (Collection $chunk) use ($updateColumns) {
                DB::table('notification_user_states')->upsert(
                    $chunk->all(),
                    ['notification_id', 'user_type', 'user_id'],
                    $updateColumns
                );
            });
    }

    private function deleteNotificationGlobally(Notification $notification): void
    {
        DB::transaction(function () use ($notification) {
            $this->deleteNotificationStateRows(collect([(int) $notification->id]));
            $notification->delete();
        });
    }

    private function deleteAllManagedNotificationsGlobally(): int
    {
        $notificationIds = $this->adminManagedNotificationsQuery()
            ->select('id')
            ->orderBy('id')
            ->pluck('id')
            ->map(fn ($notificationId) => (int) $notificationId)
            ->values();

        if ($notificationIds->isEmpty()) {
            return 0;
        }

        DB::transaction(function () use ($notificationIds) {
            $this->deleteNotificationStateRows($notificationIds);
            Notification::whereIn('id', $notificationIds)->delete();
        });

        return $notificationIds->count();
    }

    private function deleteNotificationStateRows(Collection $notificationIds): void
    {
        $notificationIds = $notificationIds
            ->map(fn ($notificationId) => (int) $notificationId)
            ->filter()
            ->unique()
            ->values();

        if ($notificationIds->isEmpty()) {
            return;
        }

        if (Schema::hasTable('notification_user_states')) {
            DB::table('notification_user_states')
                ->whereIn('notification_id', $notificationIds)
                ->delete();
        }

        if (Schema::hasTable('notification_reads')) {
            DB::table('notification_reads')
                ->whereIn('notification_id', $notificationIds)
                ->delete();
        }
    }

    private function isReadByActor(Notification $notification, array $actor): bool
    {
        if (Schema::hasTable('notification_user_states')) {
            $stateIsRead = DB::table('notification_user_states')
                ->where('notification_id', $notification->id)
                ->where('user_type', $actor['type'])
                ->where('user_id', $actor['id'])
                ->whereNotNull('read_at')
                ->exists();

            if ($stateIsRead) {
                return true;
            }
        }

        if ($actor['type'] === 'guru' && Schema::hasTable('notification_reads')) {
            return $notification->isReadBy($actor['id']);
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

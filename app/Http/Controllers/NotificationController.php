<?php

namespace App\Http\Controllers;

use App\Models\Notification;
use App\Models\Guru;
use App\Events\NotificationCreated;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class NotificationController extends Controller
{
    public function store(Request $request)
    {
        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'content' => 'required|string',
            'target' => 'required|in:all,guru,wali_kelas,specific',
            'specific_users' => 'required_if:target,specific|array'
        ]);

        try {
            $notification = new Notification();
            $notification->title = $validated['title'];
            $notification->content = $validated['content'];
            $notification->target = $validated['target'];
            
            if ($validated['target'] === 'specific' && !empty($validated['specific_users'])) {
                $notification->specific_users = array_map('intval', $validated['specific_users']);
            }
            
            $notification->save();
            
            // Dispatch notification event for real-time updates
            event(new NotificationCreated($notification));

            // Return the newly created notification with additional data
            return response()->json([
                'success' => true,
                'message' => 'Notifikasi berhasil ditambahkan',
                'notification' => [
                    'id' => $notification->id,
                    'title' => $notification->title,
                    'content' => $notification->content,
                    'target' => $notification->target,
                    'specific_users' => $notification->specific_users,
                    'created_at' => $notification->created_at->diffForHumans(),
                    'is_read' => false
                ]
            ]);
        } catch (\Exception $e) {
            Log::error('Notification creation error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Gagal menambahkan notifikasi: ' . $e->getMessage()
            ], 500);
        }
    }

    public function list()
    {
        try {
            $notifications = Notification::latest()->get();

            $singleSpecificUserIds = $notifications
                ->filter(function ($notification) {
                    return $notification->target === 'specific'
                        && is_array($notification->specific_users)
                        && count($notification->specific_users) === 1;
                })
                ->map(function ($notification) {
                    return (int) $notification->specific_users[0];
                })
                ->unique()
                ->values();

            $specificUserNames = Guru::whereIn('id', $singleSpecificUserIds)
                ->pluck('nama', 'id');

            $notifications = $notifications->map(function($notification) use ($specificUserNames) {
                // Get target display information
                $targetDisplay = $this->getTargetDisplay($notification, $specificUserNames);
                
                return [
                    'id' => $notification->id,
                    'title' => $notification->title,
                    'content' => $notification->content,
                    'target' => $notification->target,
                    'specific_users' => $notification->specific_users,
                    'target_display' => $targetDisplay,
                    'created_at' => $notification->created_at->diffForHumans()
                ];
            });
        
            return response()->json(['items' => $notifications]);
        } catch (\Exception $e) {
            Log::error('Error fetching notifications list: ' . $e->getMessage());
            return response()->json(['items' => []], 500);
        }
    }
    
    public function index()
    {
        try {
            $guru = Auth::guard('guru')->user();
            $selected_role = session('selected_role');
        
            $notifications = Notification::where(function($query) use ($guru, $selected_role) {
                $query->where('target', 'all')
                      ->orWhere('target', $selected_role) // Menggunakan selected_role dari session
                      ->orWhere(function($q) use ($guru) {
                          $q->where('target', 'specific')
                            ->whereJsonContains('specific_users', $guru->id);
                      });
            })
            ->with(['readers' => function ($query) use ($guru) {
                $query->where('guru_id', $guru->id);
            }])
            ->orderBy('created_at', 'desc') // Tambahkan ordering
            ->take(5) // Batasi 5 notifikasi terakhir
            ->get()
            ->map(function ($notification) use ($guru) {
                return [
                    'id' => $notification->id,
                    'title' => $notification->title,
                    'content' => $notification->content,
                    'target' => $notification->target,
                    'created_at' => $notification->created_at->diffForHumans(),
                    'is_read' => $notification->isReadBy($guru->id)
                ];
            });
        
            return response()->json(['items' => $notifications]);
        } catch (\Exception $e) {
            Log::error('Error fetching notifications: ' . $e->getMessage());
            return response()->json(['items' => []], 500);
        }
    }
    
    public function markAsRead(Notification $notification)
    {
        try {
            $guru = Auth::guard('guru')->user();
            
            if (!$notification->readers()->where('guru_id', $guru->id)->exists()) {
                $notification->readers()->attach($guru->id, [
                    'read_at' => now()
                ]);
            }
    
            return response()->json([
                'success' => true,
                'message' => 'Notifikasi telah dibaca'
            ]);
        } catch (\Exception $e) {
            Log::error('Error marking notification as read: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Gagal menandai notifikasi sebagai telah dibaca'
            ], 500);
        }
    }

    public function getUnreadCount()
    {
        try {
            $guru = Auth::guard('guru')->user();
            $role = session('selected_role');
            
            $count = Notification::where(function($query) use ($guru, $role) {
                $query->where('target', 'all')
                      ->orWhere('target', $role)
                      ->orWhere(function($q) use ($guru) {
                          $q->where('target', 'specific')
                            ->whereRaw("JSON_CONTAINS(specific_users, ?)", [$guru->id]);
                      });
            })
            ->whereDoesntHave('readers', function($query) use ($guru) {
                $query->where('guru_id', $guru->id);
            })
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
            $notification->delete();
            return response()->json([
                'success' => true,
                'message' => 'Notifikasi berhasil dihapus'
            ]);
        } catch (\Exception $e) {
            Log::error('Error deleting notification: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Gagal menghapus notifikasi'
            ], 500);
        }
    }
    
    /**
     * Get display text for notification target
     */
    private function getTargetDisplay($notification, $specificUserNames = null)
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
                
                // For a single specific user, show their name
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
                
                // For multiple users, show the count
                return count($notification->specific_users) . ' Guru Tertentu';
            default:
                return 'Semua';
        }
    }
}

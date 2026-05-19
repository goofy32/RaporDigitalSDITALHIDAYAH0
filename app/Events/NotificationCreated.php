<?php

namespace App\Events;

use App\Models\Notification;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class NotificationCreated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public $notification;

    /**
     * Create a new event instance.
     */
    public function __construct(Notification $notification)
    {
        $this->notification = $notification;
    }

    /**
     * Get the channels the event should broadcast on.
     *
     * @return array<int, \Illuminate\Broadcasting\Channel>
     */
    public function broadcastOn(): array
    {
        $channels = [new PrivateChannel('notifications.admin')];
        
        // Add channels based on notification target
        if ($this->notification->target === 'all') {
            $channels[] = new PrivateChannel('notifications.all');
        } elseif ($this->notification->target === 'guru') {
            $channels[] = new PrivateChannel('notifications.guru');
        } elseif ($this->notification->target === 'wali_kelas') {
            $channels[] = new PrivateChannel('notifications.wali_kelas');
        } elseif ($this->notification->target === 'specific' && is_array($this->notification->specific_users)) {
            foreach ($this->notification->specific_users as $userId) {
                $channels[] = new PrivateChannel('notifications.user.' . $userId);
            }
        }
        
        return $channels;
    }
    
    /**
     * Get the data to broadcast.
     *
     * @return array
     */
    public function broadcastWith(): array
    {
        return [
            'id' => $this->notification->id,
            'title' => $this->notification->title,
            'content' => $this->notification->content,
            'target' => $this->notification->target,
            'specific_users' => $this->notification->specific_users,
            'created_at' => $this->notification->created_at?->toISOString(),
            'is_read' => false
        ];
    }
}

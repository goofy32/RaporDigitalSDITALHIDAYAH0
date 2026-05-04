<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;

class Notification extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'title',
        'content',
        'target',
        'specific_users', // untuk menyimpan array ID guru
        'is_read'
    ];

    protected $casts = [
        'is_read' => 'boolean',
        'specific_users' => 'array',
    ];
    
    protected $attributes = [
        'is_read' => false,
        'specific_users' => null,
    ];

    // Relationship dengan guru yang membaca notifikasi
    public function readers()
    {
        return $this->belongsToMany(Guru::class, 'notification_reads')
                    ->withTimestamps()
                    ->withPivot('read_at');
    }

    // Scope untuk filter berdasarkan target
    public function scopeForTarget($query, $target)
    {
        return $query->where('target', $target);
    }

    // Scope untuk filter notifikasi yang belum dibaca
    public function scopeUnread($query)
    {
        return $query->where('is_read', false);
    }

    // Scope untuk filter notifikasi untuk guru spesifik
    public function scopeForSpecificUser($query, $userId)
    {
        return $query->where(function($q) use ($userId) {
            $q->where('target', 'all')
              ->orWhere('target', 'guru')
              ->orWhere(function($sub) use ($userId) {
                  $sub->where('target', 'specific')
                      ->whereRaw('JSON_CONTAINS(specific_users, ?)', [json_encode($userId)]);
              });
        });
    }

    // Method untuk menandai notifikasi sebagai telah dibaca
    public function markAsRead($guruId)
    {
        if (!$this->readers()->where('guru_id', $guruId)->exists()) {
            $this->readers()->attach($guruId, [
                'read_at' => now()
            ]);
        }
    }

    // Method untuk mengecek apakah notifikasi sudah dibaca oleh guru tertentu
    public function isReadBy($guruId)
    {
        if ($this->relationLoaded('readers')) {
            return $this->readers->contains(function ($guru) use ($guruId) {
                return (int) $guru->id === (int) $guruId;
            });
        }

        return $this->readers()->where('guru_id', $guruId)->exists();
    }

    // Method untuk mendapatkan jumlah pembaca
    public function getReadCountAttribute()
    {
        return $this->readers()->count();
    }

    // Method untuk mendapatkan daftar guru yang ditargetkan
    public function getTargetedUsersAttribute()
    {
        if ($this->target === 'specific') {
            return Guru::whereIn('id', $this->specific_users)->get();
        }
        return collect();
    }

    // Method untuk format created_at yang lebih readable
    public function getCreatedAtFormattedAttribute()
    {
        return $this->created_at->diffForHumans();
    }

    // Boot method untuk setup model events
    protected static function boot()
    {
        parent::boot();

        // Set default values saat membuat notifikasi baru
        static::creating(function ($notification) {
            if (!isset($notification->is_read)) {
                $notification->is_read = false;
            }
        });
    }
}

<?php

namespace App\Models;

use App\Notifications\AdminResetPasswordNotification;
use App\Services\AccountIdentifierService;
use Illuminate\Database\Eloquent\Factories\HasFactory; // Tambahkan ini
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    protected $fillable = [
        'name',
        'username',
        'email',
        'password',
    ];

    protected $hidden = [
        'password',
        'remember_token',
        'pending_email',
        'pending_email_token_hash',
        'pending_email_expires_at',
    ];

    protected $casts = [
        'email_verified_at' => 'datetime',
        'pending_email_expires_at' => 'datetime',
        'password' => 'hashed',
    ];

    public function sendPasswordResetNotification(#[\SensitiveParameter] $token): void
    {
        $this->notify(new AdminResetPasswordNotification($token));
    }

    protected static function booted(): void
    {
        static::saving(function (User $user): void {
            if (
                Schema::hasTable('gurus')
                && app(AccountIdentifierService::class)->conflictsWithGuru($user->username, $user->email)
            ) {
                throw ValidationException::withMessages([
                    'email' => 'Username atau email tersebut sudah digunakan.',
                ]);
            }
        });
    }

    public function setEmailAttribute(?string $email): void
    {
        $this->attributes['email'] = app(AccountIdentifierService::class)->normalizeEmail($email);
    }
}

<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\URL;

class AdminVerifyNewEmailNotification extends Notification
{
    use Queueable;

    public function __construct(
        private readonly int $adminId,
        private readonly string $adminName,
        #[\SensitiveParameter] private readonly string $token
    ) {
    }

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $expiryMinutes = (int) config('auth.verification.expire', 60);
        $url = URL::temporarySignedRoute(
            'admin.account.email.verify',
            now()->addMinutes($expiryMinutes),
            [
                'user' => $this->adminId,
                'token' => $this->token,
            ]
        );

        return (new MailMessage)
            ->subject('Verifikasi Email Baru Admin')
            ->greeting('Halo, '.$this->adminName)
            ->line('Kami menerima permintaan untuk mengganti alamat email Admin Rapor Digital.')
            ->line('Email aktif tidak akan berubah sampai alamat baru ini berhasil diverifikasi.')
            ->action('Verifikasi Email Baru', $url)
            ->line("Tautan ini berlaku selama {$expiryMinutes} menit dan hanya dapat digunakan satu kali.")
            ->line('Jika Anda tidak meminta perubahan ini, abaikan email tersebut.');
    }
}

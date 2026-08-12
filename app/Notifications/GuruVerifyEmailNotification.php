<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\URL;

class GuruVerifyEmailNotification extends Notification
{
    use Queueable;

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $expiryMinutes = (int) config('auth.verification.expire', 60);
        $url = URL::temporarySignedRoute(
            'guru.verification.verify',
            now()->addMinutes($expiryMinutes),
            [
                'id' => $notifiable->getKey(),
                'hash' => sha1($notifiable->getEmailForVerification()),
            ]
        );

        return (new MailMessage)
            ->subject('Verifikasi Email Rapor Digital')
            ->greeting('Halo, '.$notifiable->nama)
            ->line('Silakan verifikasi alamat email Anda agar dapat digunakan untuk pemulihan password.')
            ->action('Verifikasi Email', $url)
            ->line("Tautan ini berlaku selama {$expiryMinutes} menit.")
            ->line('Jika Anda tidak meminta verifikasi ini, abaikan email tersebut.');
    }
}

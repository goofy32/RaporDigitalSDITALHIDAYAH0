<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class GuruResetPasswordNotification extends Notification
{
    use Queueable;

    public function __construct(#[\SensitiveParameter] private readonly string $token)
    {
    }

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $url = route('password.reset', [
            'token' => $this->token,
            'email' => $notifiable->getEmailForPasswordReset(),
        ]);
        $expiryMinutes = (int) config('auth.passwords.gurus.expire', 60);

        return (new MailMessage)
            ->subject('Petunjuk Pengaturan Ulang Password')
            ->greeting('Halo, '.$notifiable->nama)
            ->line('Kami menerima permintaan untuk mengatur ulang password akun Anda.')
            ->action('Atur Ulang Password', $url)
            ->line("Tautan ini berlaku selama {$expiryMinutes} menit dan hanya dapat digunakan satu kali.")
            ->line('Jika Anda tidak meminta pengaturan ulang password, abaikan email ini.');
    }
}

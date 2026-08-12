<?php

namespace App\Services;

use App\Models\Guru;
use Illuminate\Support\Facades\Log;
use Throwable;

class GuruEmailVerificationService
{
    public function sendIfRequired(Guru $guru): void
    {
        if (! is_string($guru->email) || $guru->email === '' || $guru->hasVerifiedEmail()) {
            return;
        }

        try {
            $guru->sendEmailVerificationNotification();
        } catch (Throwable $exception) {
            Log::warning('Guru email verification notification could not be sent.', [
                'guru_id' => $guru->getKey(),
                'exception' => $exception::class,
            ]);
        }
    }
}

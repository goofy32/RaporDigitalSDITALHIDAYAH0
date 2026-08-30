<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AdminPendingEmailChangeService
{
    public const ACTIVATED = 'activated';

    public const INVALID = 'invalid';

    public const COLLISION = 'collision';

    public function __construct(
        private readonly AccountIdentifierService $identifiers,
        private readonly DatabaseSessionRevocationService $sessions
    ) {
    }

    /**
     * @return array{email: string, token: string, token_hash: string}
     */
    public function initiate(int $adminId, string $email): array
    {
        return DB::transaction(function () use ($adminId, $email): array {
            /** @var User $admin */
            $admin = User::query()->lockForUpdate()->findOrFail($adminId);
            $this->assertEmailAvailable($admin, $email);

            $token = Str::random(64);
            $tokenHash = hash('sha256', $token);

            $admin->forceFill([
                'pending_email' => $email,
                'pending_email_token_hash' => $tokenHash,
                'pending_email_expires_at' => now()->addMinutes(
                    (int) config('auth.verification.expire', 60)
                ),
            ])->save();

            AuditService::log(
                'admin_email_change_requested',
                User::class,
                (int) $admin->getKey(),
                'Admin meminta verifikasi alamat email baru.'
            );

            return [
                'email' => $email,
                'token' => $token,
                'token_hash' => $tokenHash,
            ];
        });
    }

    public function clearIfTokenHashMatches(int $adminId, string $tokenHash): void
    {
        User::query()
            ->whereKey($adminId)
            ->where('pending_email_token_hash', $tokenHash)
            ->update($this->clearedAttributes());
    }

    public function cancel(int $adminId): bool
    {
        return DB::transaction(function () use ($adminId): bool {
            /** @var User $admin */
            $admin = User::query()->lockForUpdate()->findOrFail($adminId);

            if (! $this->hasAnyPendingState($admin)) {
                return false;
            }

            $admin->forceFill($this->clearedAttributes())->save();

            AuditService::log(
                'admin_email_change_cancelled',
                User::class,
                (int) $admin->getKey(),
                'Admin membatalkan perubahan email yang masih menunggu verifikasi.'
            );

            return true;
        });
    }

    public function activate(int $adminId, #[\SensitiveParameter] string $token): string
    {
        $suppliedTokenHash = hash('sha256', $token);

        try {
            return DB::transaction(function () use ($adminId, $suppliedTokenHash): string {
                /** @var User|null $admin */
                $admin = User::query()->lockForUpdate()->find($adminId);

                if (! $admin || ! $this->hasCompletePendingState($admin)) {
                    return self::INVALID;
                }

                if (
                    ! hash_equals((string) $admin->pending_email_token_hash, $suppliedTokenHash)
                    || $admin->pending_email_expires_at->isPast()
                ) {
                    return self::INVALID;
                }

                $newEmail = (string) $admin->pending_email;

                if ($this->emailConflicts($admin, $newEmail)) {
                    $admin->forceFill($this->clearedAttributes())->save();

                    return self::COLLISION;
                }

                $oldEmail = (string) $admin->email;
                $this->deleteResetTokensFor([$oldEmail, $newEmail]);

                $admin->forceFill([
                    'email' => $newEmail,
                    'email_verified_at' => now(),
                    'pending_email' => null,
                    'pending_email_token_hash' => null,
                    'pending_email_expires_at' => null,
                    'remember_token' => Str::random(60),
                ])->save();

                $this->sessions->revokeOrFail('web', $admin->getKey());

                AuditService::log(
                    'admin_email_changed',
                    User::class,
                    (int) $admin->getKey(),
                    'Admin mengaktifkan alamat email baru yang telah diverifikasi.',
                    ['email' => $oldEmail],
                    ['email' => $newEmail]
                );

                return self::ACTIVATED;
            });
        } catch (ValidationException) {
            $this->clearIfTokenHashMatches($adminId, $suppliedTokenHash);

            return self::COLLISION;
        }
    }

    /** @return array<string, null> */
    public function clearedAttributes(): array
    {
        return [
            'pending_email' => null,
            'pending_email_token_hash' => null,
            'pending_email_expires_at' => null,
        ];
    }

    private function assertEmailAvailable(User $admin, string $email): void
    {
        if ($this->emailConflicts($admin, $email)) {
            throw ValidationException::withMessages([
                'email' => $this->identifiers->normalizeEmail($admin->email) === $email
                    ? 'Email tersebut sudah menjadi email aktif Admin.'
                    : 'Email tersebut sudah digunakan.',
            ]);
        }
    }

    private function emailConflicts(User $admin, string $email): bool
    {
        return $this->identifiers->normalizeEmail($admin->email) === $email
            || $this->identifiers->conflictsWithOtherUser((int) $admin->getKey(), null, $email)
            || $this->identifiers->conflictsWithGuru(null, $email);
    }

    private function hasCompletePendingState(User $admin): bool
    {
        return is_string($admin->pending_email)
            && $admin->pending_email !== ''
            && is_string($admin->pending_email_token_hash)
            && preg_match('/\A[a-f0-9]{64}\z/', $admin->pending_email_token_hash) === 1
            && $admin->pending_email_expires_at !== null;
    }

    private function hasAnyPendingState(User $admin): bool
    {
        return $admin->pending_email !== null
            || $admin->pending_email_token_hash !== null
            || $admin->pending_email_expires_at !== null;
    }

    /** @param array<int, string> $emails */
    private function deleteResetTokensFor(array $emails): void
    {
        DB::table((string) config('auth.passwords.users.table', 'password_reset_tokens'))
            ->whereIn('email', array_values(array_unique($emails)))
            ->delete();
    }
}

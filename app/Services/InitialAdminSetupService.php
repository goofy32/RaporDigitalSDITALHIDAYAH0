<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class InitialAdminSetupService
{
    private const LOCK_NAME = 'initial-admin-setup';

    public function isAvailable(): bool
    {
        return $this->configuredTokenHash() !== null
            && User::query()->doesntExist();
    }

    public function tokenMatches(string $providedToken): bool
    {
        $configuredTokenHash = $this->configuredTokenHash();

        return $configuredTokenHash !== null
            && hash_equals($configuredTokenHash, hash('sha256', $providedToken));
    }

    /**
     * @param  array{name: string, username: string, email: string, password: string}  $attributes
     */
    public function create(array $attributes, string $providedToken): ?User
    {
        return Cache::lock(self::LOCK_NAME, 15)->block(5, function () use ($attributes, $providedToken) {
            return DB::transaction(function () use ($attributes, $providedToken) {
                if (! $this->tokenMatches($providedToken) || User::query()->exists()) {
                    return null;
                }

                return User::query()->create($attributes);
            });
        });
    }

    private function configuredTokenHash(): ?string
    {
        $tokenHash = config('initial_admin_setup.token_hash');

        return is_string($tokenHash) && preg_match('/\A[a-f0-9]{64}\z/', $tokenHash) === 1
            ? $tokenHash
            : null;
    }
}

<?php

namespace App\Services;

use Illuminate\Contracts\Session\Session;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class GuruSelectedRoleSessionState
{
    public const ROLE_KEY = 'selected_role';

    public const VERSION_KEY = 'selected_role_switched_at';

    private const CACHE_PREFIX = 'guru-selected-role:';

    private const LOCK_PREFIX = 'guru-selected-role-lock:';

    private const VALID_ROLES = ['pengajar', 'wali_kelas'];

    private const LOCK_SECONDS = 3;

    private const LOCK_WAIT_SECONDS = 1;

    private array $reportedCacheFailures = [];

    public function publish(Session $session, string $role): int
    {
        if (! in_array($role, self::VALID_ROLES, true)) {
            throw new \InvalidArgumentException('Role guru tidak valid.');
        }

        try {
            return Cache::lock($this->lockKey($session), self::LOCK_SECONDS)
                ->betweenBlockedAttemptsSleepFor(50)
                ->block(self::LOCK_WAIT_SECONDS, function () use ($session, $role): int {
                    $current = $this->readAuthoritativeState($session);
                    $currentVersion = max(
                        (int) ($current['version'] ?? 0),
                        (int) $session->get(self::VERSION_KEY, 0)
                    );
                    $version = max($this->newVersion(), $currentVersion + 1);

                    Cache::put($this->cacheKey($session), [
                        'role' => $role,
                        'version' => $version,
                    ], $this->ttl());

                    $session->put(self::ROLE_KEY, $role);
                    $session->put(self::VERSION_KEY, $version);

                    return $version;
                });
        } catch (\Throwable $exception) {
            $this->logCacheWarning('publish', $exception);
        }

        $version = max(
            $this->newVersion(),
            (int) $session->get(self::VERSION_KEY, 0) + 1
        );

        $session->put(self::ROLE_KEY, $role);
        $session->put(self::VERSION_KEY, $version);

        return $version;
    }

    public function reconcile(Session $session): bool
    {
        if (! $session->has(self::ROLE_KEY)) {
            return false;
        }

        $state = $this->authoritativeState($session);

        if ($state === null) {
            return false;
        }

        $currentVersion = (int) $session->get(self::VERSION_KEY, 0);

        if ($state['version'] <= $currentVersion) {
            return false;
        }

        $session->put(self::ROLE_KEY, $state['role']);
        $session->put(self::VERSION_KEY, $state['version']);

        return true;
    }

    public function forget(Session $session): void
    {
        try {
            Cache::forget($this->cacheKey($session));
        } catch (\Throwable $exception) {
            $this->logCacheWarning('forget', $exception);
        }
    }

    private function authoritativeState(Session $session): ?array
    {
        try {
            return $this->readAuthoritativeState($session);
        } catch (\Throwable $exception) {
            $this->logCacheWarning('read', $exception);

            return null;
        }
    }

    private function readAuthoritativeState(Session $session): ?array
    {
        $state = Cache::get($this->cacheKey($session));

        if (! is_array($state)) {
            return null;
        }

        $role = $state['role'] ?? null;
        $version = $state['version'] ?? null;

        if (! in_array($role, self::VALID_ROLES, true) || ! is_numeric($version)) {
            return null;
        }

        return [
            'role' => $role,
            'version' => (int) $version,
        ];
    }

    private function cacheKey(Session $session): string
    {
        return self::CACHE_PREFIX.hash_hmac(
            'sha256',
            $session->getId(),
            (string) config('app.key')
        );
    }

    private function lockKey(Session $session): string
    {
        return self::LOCK_PREFIX.hash_hmac(
            'sha256',
            $session->getId(),
            (string) config('app.key')
        );
    }

    private function ttl(): \DateTimeInterface
    {
        return now()->addMinutes((int) config('session.lifetime', 120));
    }

    private function newVersion(): int
    {
        return (int) floor(microtime(true) * 1_000_000);
    }

    private function logCacheWarning(string $operation, \Throwable $exception): void
    {
        $bucket = $operation.':'.$exception::class;

        if (isset($this->reportedCacheFailures[$bucket])) {
            return;
        }

        $this->reportedCacheFailures[$bucket] = true;

        try {
            $rateKey = 'guru-role-sync-warning:'.hash('sha256', $bucket);

            if (! Cache::add($rateKey, true, now()->addMinute())) {
                return;
            }
        } catch (\Throwable) {
            // If cache itself is unavailable, keep the warning best-effort and
            // limited to one occurrence per service instance.
        }

        Log::warning('Gagal menjalankan sinkronisasi role guru.', [
            'operation' => $operation,
            'exception' => $exception::class,
            'lock_timeout' => $exception instanceof LockTimeoutException,
            'message' => $this->safeMessage($exception),
        ]);
    }

    private function safeMessage(\Throwable $exception): string
    {
        return str($exception->getMessage())->limit(160)->toString();
    }
}

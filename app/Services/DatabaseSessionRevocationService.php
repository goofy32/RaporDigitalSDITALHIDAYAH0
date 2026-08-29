<?php

namespace App\Services;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use LogicException;
use RuntimeException;
use Throwable;

class DatabaseSessionRevocationService
{
    public function revoke(string $guard, int|string $accountId): void
    {
        $this->revokeSessions($guard, $accountId, false);
    }

    public function revokeOrFail(string $guard, int|string $accountId): void
    {
        $this->revokeSessions($guard, $accountId, true);
    }

    private function revokeSessions(string $guard, int|string $accountId, bool $failClosed): void
    {
        if (config('session.driver') !== 'database') {
            if ($failClosed) {
                throw new RuntimeException('Required database session revocation is unavailable.');
            }

            return;
        }

        try {
            if ($failClosed) {
                $this->assertRequiredConfiguration();
            }

            $loginKey = Auth::guard($guard)->getName();
            $connection = DB::connection(config('session.connection') ?: null);
            $table = (string) config('session.table', 'sessions');

            $connection->table($table)
                ->select(['id', 'payload'])
                ->orderBy('id')
                ->chunkById(200, function ($sessions) use ($connection, $table, $loginKey, $accountId): void {
                    $matchingIds = [];

                    foreach ($sessions as $session) {
                        $attributes = $this->decodePayload((string) $session->payload);

                        if (is_array($attributes) && (string) ($attributes[$loginKey] ?? '') === (string) $accountId) {
                            $matchingIds[] = $session->id;
                        }
                    }

                    if ($matchingIds !== []) {
                        $connection->table($table)->whereIn('id', $matchingIds)->delete();
                    }
                }, 'id');
        } catch (Throwable $exception) {
            Log::warning('Targeted account session revocation failed after password update.', [
                'guard' => $guard,
                'exception' => $exception::class,
            ]);

            if ($failClosed) {
                throw $exception;
            }
        }
    }

    private function assertRequiredConfiguration(): void
    {
        $connection = config('session.connection');
        $table = config('session.table', 'sessions');
        $serialization = config('session.serialization', 'php');
        $encrypt = config('session.encrypt', false);

        if ($connection !== null && (! is_string($connection) || trim($connection) === '')) {
            throw new RuntimeException('Database session connection configuration is invalid.');
        }

        if (! is_string($table) || trim($table) === '') {
            throw new RuntimeException('Database session table configuration is invalid.');
        }

        if (! is_string($serialization) || ! in_array($serialization, ['php', 'json'], true)) {
            throw new LogicException('Database session serialization configuration is unsupported.');
        }

        if (! is_bool($encrypt)) {
            throw new LogicException('Database session encryption configuration is invalid.');
        }
    }

    /** @return array<string, mixed>|null */
    private function decodePayload(string $payload): ?array
    {
        $serialized = base64_decode($payload, true);

        if (! is_string($serialized)) {
            return null;
        }

        if ((bool) config('session.encrypt', false)) {
            $serialized = app('encrypter')->decrypt($serialized);
        }

        if (config('session.serialization', 'php') === 'json') {
            $attributes = json_decode($serialized, true);
        } else {
            $attributes = @unserialize($serialized, ['allowed_classes' => false]);
        }

        return is_array($attributes) ? $attributes : null;
    }
}

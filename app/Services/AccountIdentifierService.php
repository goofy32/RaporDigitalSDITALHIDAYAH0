<?php

namespace App\Services;

use App\Models\Guru;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Schema;

class AccountIdentifierService
{
    /** @return Collection<int, User> */
    public function matchingUsers(string $identifier): Collection
    {
        $identifier = $this->normalizeIdentifier($identifier);

        return User::query()
            ->where(function ($query) use ($identifier): void {
                $query->whereRaw('LOWER(username) = ?', [$identifier])
                    ->orWhereRaw('LOWER(email) = ?', [$identifier]);
            })
            ->get();
    }

    /** @return Collection<int, Guru> */
    public function matchingGurus(string $identifier): Collection
    {
        $identifier = $this->normalizeIdentifier($identifier);

        return Guru::query()
            ->where(function ($query) use ($identifier): void {
                $query->whereRaw('LOWER(username) = ?', [$identifier])
                    ->orWhereRaw('LOWER(email) = ?', [$identifier]);
            })
            ->get();
    }

    public function conflictsWithGuru(?string $username, ?string $email): bool
    {
        $identifiers = $this->normalizedIdentifiers($username, $email);

        if (
            $identifiers === []
            || ! Schema::hasTable('gurus')
            || ! Schema::hasColumns('gurus', ['username', 'email'])
        ) {
            return false;
        }

        return Guru::withTrashed()->where(function ($query) use ($identifiers): void {
            foreach ($identifiers as $identifier) {
                $query->orWhereRaw('LOWER(username) = ?', [$identifier])
                    ->orWhereRaw('LOWER(email) = ?', [$identifier]);
            }
        })->exists();
    }

    public function conflictsWithUser(?string $username, ?string $email): bool
    {
        $identifiers = $this->normalizedIdentifiers($username, $email);

        if (
            $identifiers === []
            || ! Schema::hasTable('users')
            || ! Schema::hasColumns('users', ['username', 'email'])
        ) {
            return false;
        }

        return User::query()->where(function ($query) use ($identifiers): void {
            foreach ($identifiers as $identifier) {
                $query->orWhereRaw('LOWER(username) = ?', [$identifier])
                    ->orWhereRaw('LOWER(email) = ?', [$identifier]);
            }
        })->exists();
    }

    public function conflictsWithOtherGuru(?int $guruId, ?string $username, ?string $email): bool
    {
        $identifiers = $this->normalizedIdentifiers($username, $email);

        if (
            $identifiers === []
            || ! Schema::hasTable('gurus')
            || ! Schema::hasColumns('gurus', ['username', 'email'])
        ) {
            return false;
        }

        return Guru::withTrashed()
            ->when($guruId !== null, fn ($query) => $query->whereKeyNot($guruId))
            ->where(function ($query) use ($identifiers): void {
                foreach ($identifiers as $identifier) {
                    $query->orWhereRaw('LOWER(username) = ?', [$identifier])
                        ->orWhereRaw('LOWER(email) = ?', [$identifier]);
                }
            })
            ->exists();
    }

    public function normalizeEmail(?string $email): ?string
    {
        if (! is_string($email) || trim($email) === '') {
            return null;
        }

        return mb_strtolower(trim($email));
    }

    private function normalizeIdentifier(string $identifier): string
    {
        return mb_strtolower(trim($identifier));
    }

    /** @return array<int, string> */
    private function normalizedIdentifiers(?string $username, ?string $email): array
    {
        return array_values(array_unique(array_filter([
            $this->normalizeEmail($username),
            $this->normalizeEmail($email),
        ], fn (?string $identifier): bool => $identifier !== null)));
    }
}

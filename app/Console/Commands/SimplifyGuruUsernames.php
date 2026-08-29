<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class SimplifyGuruUsernames extends Command
{
    private const DEGREE_PATTERNS = [
        '/,?\s*S\.?\s*Pd\.?\s*I\.?/iu',
        '/,?\s*S\.?\s*T\.?\s*P\.?/iu',
        '/,?\s*S\.?\s*Pd\.?/iu',
        '/,?\s*S\.?\s*Ag\.?/iu',
        '/,?\s*S\.?\s*Kom\.?/iu',
        '/,?\s*S\.?\s*Si\.?/iu',
        '/,?\s*M\.?\s*Pd\.?/iu',
    ];

    private const IGNORED_TOKENS = [
        'spd',
        'spdi',
        'stp',
        'sag',
        'skom',
        'ssi',
        'mpd',
    ];

    protected $signature = 'initial-data:simplify-guru-usernames
        {--apply : Persist simplified usernames}';

    protected $description = 'Preview or apply short, unique usernames for guru records';

    public function handle(): int
    {
        $gurus = DB::table('gurus')
            ->select(['id', 'nama', 'username'])
            ->orderBy('id')
            ->get();

        if ($gurus->isEmpty()) {
            $this->info('Tidak ada data guru.');

            return self::SUCCESS;
        }

        $targets = $this->targetUsernames($gurus);
        $changes = $gurus
            ->filter(fn ($guru) => (string) $guru->username !== $targets[(int) $guru->id])
            ->values();

        if (! $this->option('apply')) {
            $this->warn('DRY RUN - tidak ada data yang diubah. Gunakan --apply untuk menyimpan.');
        }

        foreach ($changes as $guru) {
            $oldUsername = $guru->username ?: '(kosong)';
            $this->line("#{$guru->id}: {$oldUsername} -> {$targets[(int) $guru->id]}");
        }

        if ($changes->isEmpty()) {
            $this->info('Tidak ada username guru yang perlu diperbarui.');
        } elseif ($this->option('apply')) {
            DB::transaction(function () use ($changes, $targets) {
                $reservedUsernames = DB::table('gurus')
                    ->pluck('username')
                    ->filter()
                    ->mapWithKeys(fn (string $username) => [$username => true])
                    ->all() + $this->reservedAdminIdentifiers();

                foreach ($changes as $guru) {
                    $temporaryUsername = $this->temporaryUsername((int) $guru->id, $reservedUsernames);

                    DB::table('gurus')
                        ->where('id', $guru->id)
                        ->update(['username' => $temporaryUsername]);

                    $reservedUsernames[$temporaryUsername] = true;
                }

                foreach ($changes as $guru) {
                    DB::table('gurus')
                        ->where('id', $guru->id)
                        ->update(['username' => $targets[(int) $guru->id]]);
                }
            });

            $this->info('Username guru berhasil diperbarui.');
        }

        $this->line("Total guru: {$gurus->count()}");
        $this->line("Perubahan: {$changes->count()}");
        $this->line('Tidak berubah: '.($gurus->count() - $changes->count()));

        return self::SUCCESS;
    }

    /**
     * @param  Collection<int, object{id: int, nama: string, username: string|null}>  $gurus
     * @return array<int, string>
     */
    private function targetUsernames(Collection $gurus): array
    {
        $targets = [];
        $used = $this->reservedAdminIdentifiers();

        foreach ($gurus as $guru) {
            $base = $this->baseUsername((string) $guru->nama);
            $username = $this->uniqueUsername($base, $used);
            $targets[(int) $guru->id] = $username;
            $used[$username] = true;
        }

        return $targets;
    }

    private function baseUsername(string $name): string
    {
        $joinedName = str_replace(["'", "\u{2019}", "\u{2018}", '`'], '', $name);
        $withoutDegrees = preg_replace(self::DEGREE_PATTERNS, ' ', Str::ascii($joinedName)) ?? $joinedName;
        $withoutPeriods = str_replace('.', '', $withoutDegrees);
        $normalized = preg_replace('/[^A-Za-z0-9]+/', ' ', $withoutPeriods) ?? '';

        $tokens = collect(preg_split('/\s+/', Str::lower(trim($normalized))) ?: [])
            ->map(fn (string $token) => preg_replace('/[^a-z0-9]+/', '', $token) ?? '')
            ->filter(fn (string $token) => $token !== '' && ! in_array($token, self::IGNORED_TOKENS, true))
            ->take(2)
            ->values();

        return $tokens->isEmpty() ? 'guru' : $tokens->implode('_');
    }

    /**
     * @param  array<string, bool>  $used
     */
    private function uniqueUsername(string $base, array $used): string
    {
        $username = $base;
        $suffix = 2;

        while (isset($used[$username])) {
            $username = "{$base}_{$suffix}";
            $suffix++;
        }

        return $username;
    }

    /**
     * @param  array<string, bool>  $reservedUsernames
     */
    private function temporaryUsername(int $guruId, array $reservedUsernames): string
    {
        $base = "__tmp_guru_username_{$guruId}";
        $username = $base;
        $suffix = 2;

        while (isset($reservedUsernames[$username])) {
            $username = "{$base}_{$suffix}";
            $suffix++;
        }

        return $username;
    }

    /** @return array<string, bool> */
    private function reservedAdminIdentifiers(): array
    {
        if (! Schema::hasTable('users')) {
            return [];
        }

        return DB::table('users')
            ->get(['username', 'email'])
            ->flatMap(fn ($user) => [$user->username, $user->email])
            ->filter(fn ($identifier) => is_string($identifier) && trim($identifier) !== '')
            ->map(fn (string $identifier) => Str::lower(trim($identifier)))
            ->mapWithKeys(fn (string $identifier) => [$identifier => true])
            ->all();
    }
}

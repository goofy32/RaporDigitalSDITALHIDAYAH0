<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

class BaselineFileReferenceInspector
{
    /**
     * @param  array<int, int>  $retainedGuruIds
     * @return array<string, array{references: int, missing: int}>
     */
    public function inspect(array $retainedGuruIds): array
    {
        $profileLogos = DB::table('profil_sekolah')
            ->pluck('logo')
            ->filter(fn ($path): bool => is_string($path) && trim($path) !== '')
            ->values();

        $guruPhotos = collect();
        $guruSignatures = collect();

        if ($retainedGuruIds !== []) {
            $gurus = DB::table('gurus')
                ->whereIn('id', $retainedGuruIds)
                ->select(['photo', 'signature_path'])
                ->get();
            $guruPhotos = $gurus->pluck('photo')
                ->filter(fn ($path): bool => is_string($path) && trim($path) !== '')
                ->values();
            $guruSignatures = $gurus->pluck('signature_path')
                ->filter(fn ($path): bool => is_string($path) && trim($path) !== '')
                ->values();
        }

        return [
            'profile_logo' => $this->summary('public', $profileLogos->all()),
            'guru_photos' => $this->summary('public', $guruPhotos->all()),
            'guru_signatures' => $this->summary('local', $guruSignatures->all()),
        ];
    }

    /**
     * @param  array<int, string>  $paths
     * @return array{references: int, missing: int}
     */
    private function summary(string $disk, array $paths): array
    {
        $missing = 0;

        foreach ($paths as $path) {
            try {
                if (! Storage::disk($disk)->exists(trim($path))) {
                    $missing++;
                }
            } catch (Throwable) {
                $missing++;
            }
        }

        return [
            'references' => count($paths),
            'missing' => $missing,
        ];
    }
}

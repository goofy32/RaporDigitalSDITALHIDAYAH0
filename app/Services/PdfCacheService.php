<?php

namespace App\Services;

use App\Models\Siswa;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class PdfCacheService
{
    const CACHE_PREFIX = 'pdf_rapor_';
    const CACHE_DURATION = 24; // hours
    const STORAGE_DISK = 'public';
    const PDF_DIRECTORY = 'pdf_reports';
    const DOCX_DIRECTORY = 'docx_reports';
    const PROGRESS_TTL_MINUTES = 30;
    const PROCESSING_STALE_MINUTES = 15;

    /**
     * Generate cache key for PDF
     */
    public static function getCacheKey(Siswa $siswa, $type, $tahunAjaranId)
    {
        return self::CACHE_PREFIX . "{$siswa->id}_{$type}_{$tahunAjaranId}";
    }

    public static function getDocxCacheKey(Siswa $siswa, $type, $tahunAjaranId): string
    {
        return self::CACHE_PREFIX . "docx_{$siswa->id}_{$type}_{$tahunAjaranId}";
    }

    public static function getGenerationLockKey(Siswa $siswa, $type, $tahunAjaranId): string
    {
        return self::CACHE_PREFIX . "generation_lock_{$siswa->id}_{$type}_{$tahunAjaranId}";
    }

    public static function getGenerationRequestKey(Siswa $siswa, $type, $tahunAjaranId): string
    {
        return self::CACHE_PREFIX . "generation_request_{$siswa->id}_{$type}_{$tahunAjaranId}";
    }

    public static function getProgressKey(string $requestId): string
    {
        return "pdf_progress_{$requestId}";
    }

    public static function getAutoPrepareTokenKey(Siswa $siswa, $type, $tahunAjaranId): string
    {
        return self::CACHE_PREFIX . "auto_prepare_token_{$siswa->id}_{$type}_{$tahunAjaranId}";
    }

    public static function getPdfPreparationStatus(Siswa $siswa, $type, $tahunAjaranId): string
    {
        if (self::hasValidCachedPdf($siswa, $type, $tahunAjaranId)) {
            return 'ready';
        }

        if (self::hasActiveGenerationRequest($siswa, $type, $tahunAjaranId) ||
            Cache::has(self::getAutoPrepareTokenKey($siswa, $type, $tahunAjaranId))) {
            return 'preparing';
        }

        return 'missing';
    }

    public static function hasValidCachedPdf(Siswa $siswa, $type, $tahunAjaranId): bool
    {
        $cachedData = Cache::get(self::getCacheKey($siswa, $type, $tahunAjaranId));

        if (! $cachedData || ! isset($cachedData['path'], $cachedData['generated_at'])) {
            return false;
        }

        if (! Storage::disk(self::STORAGE_DISK)->exists($cachedData['path'])) {
            return false;
        }

        return now()->diffInHours($cachedData['generated_at']) <= self::CACHE_DURATION;
    }

    public static function hasValidCachedDocx(Siswa $siswa, $type, $tahunAjaranId): bool
    {
        $cachedData = Cache::get(self::getDocxCacheKey($siswa, $type, $tahunAjaranId));

        if (! $cachedData || ! isset($cachedData['path'], $cachedData['generated_at'])) {
            return false;
        }

        if (! Storage::disk(self::STORAGE_DISK)->exists($cachedData['path'])) {
            return false;
        }

        return now()->diffInHours($cachedData['generated_at']) <= self::CACHE_DURATION;
    }

    public static function hasActiveGenerationRequest(Siswa $siswa, $type, $tahunAjaranId): bool
    {
        $requestKey = self::getGenerationRequestKey($siswa, $type, $tahunAjaranId);
        $requestId = Cache::get($requestKey);

        if (! is_string($requestId) || $requestId === '') {
            return false;
        }

        $progress = Cache::get(self::getProgressKey($requestId));

        if (! is_array($progress)) {
            Cache::forget($requestKey);

            return false;
        }

        if (($progress['completed'] ?? false) || ($progress['error'] ?? false)) {
            Cache::forget($requestKey);

            return false;
        }

        $updatedAt = (int) ($progress['updated_at'] ?? 0);
        if ($updatedAt > 0 && $updatedAt < now()->subMinutes(self::PROCESSING_STALE_MINUTES)->timestamp) {
            Cache::forget($requestKey);

            return false;
        }

        return true;
    }

    /**
     * Check if PDF exists in cache
     */
    public static function getCachedPdf(Siswa $siswa, $type, $tahunAjaranId)
    {
        $token = ReportPerformanceTracker::startSegmentIfEnabled('cache_lookup');

        try {
            $cacheKey = self::getCacheKey($siswa, $type, $tahunAjaranId);
            $cachedData = Cache::get($cacheKey);

            if (!$cachedData) {
                ReportPerformanceTracker::setCacheHitIfEnabled(false);

                return null;
            }

            // Verify file still exists
            if (!Storage::disk(self::STORAGE_DISK)->exists($cachedData['path'])) {
                // File missing, remove from cache
                Cache::forget($cacheKey);
                ReportPerformanceTracker::setCacheHitIfEnabled(false);
                Log::warning("Cached PDF file missing, removed from cache", [
                    'cache_key' => $cacheKey,
                    'missing_path' => $cachedData['path']
                ]);
                return null;
            }

            // Check if file is too old (older than cache duration)
            $fileAge = now()->diffInHours($cachedData['generated_at']);
            if ($fileAge > self::CACHE_DURATION) {
                self::removeCachedPdf($siswa, $type, $tahunAjaranId);
                ReportPerformanceTracker::setCacheHitIfEnabled(false);

                return null;
            }

            ReportPerformanceTracker::setCacheHitIfEnabled(true);

            Log::info("PDF found in cache", [
                'cache_key' => $cacheKey,
                'file_age_hours' => $fileAge,
                'file_size' => $cachedData['file_size']
            ]);

            return $cachedData;
        } finally {
            ReportPerformanceTracker::endSegmentIfEnabled($token);
        }
    }

    public static function getCachedDocx(Siswa $siswa, $type, $tahunAjaranId): ?array
    {
        $token = ReportPerformanceTracker::startSegmentIfEnabled('cache_lookup');

        try {
            $cacheKey = self::getDocxCacheKey($siswa, $type, $tahunAjaranId);
            $cachedData = Cache::get($cacheKey);

            if (! $cachedData) {
                ReportPerformanceTracker::setCacheHitIfEnabled(false);

                return null;
            }

            if (! Storage::disk(self::STORAGE_DISK)->exists($cachedData['path'])) {
                Cache::forget($cacheKey);
                ReportPerformanceTracker::setCacheHitIfEnabled(false);

                Log::warning('Cached DOCX file missing, removed from cache', [
                    'cache_key' => $cacheKey,
                    'missing_path' => $cachedData['path'] ?? null,
                ]);

                return null;
            }

            $fileAge = now()->diffInHours($cachedData['generated_at']);
            if ($fileAge > self::CACHE_DURATION) {
                self::removeCachedDocx($siswa, $type, $tahunAjaranId);
                ReportPerformanceTracker::setCacheHitIfEnabled(false);

                return null;
            }

            ReportPerformanceTracker::setCacheHitIfEnabled(true);

            return $cachedData;
        } finally {
            ReportPerformanceTracker::endSegmentIfEnabled($token);
        }
    }

    /**
     * Store PDF in cache
     */
    public static function cachePdf(Siswa $siswa, $type, $tahunAjaranId, $filePath, $filename, $fileSize)
    {
        $cacheKey = self::getCacheKey($siswa, $type, $tahunAjaranId);
        
        $cacheData = [
            'path' => $filePath,
            'filename' => $filename,
            'file_size' => $fileSize,
            'generated_at' => now()->toISOString(),
            'siswa_id' => $siswa->id,
            'siswa_name' => $siswa->nama,
            'type' => $type,
            'tahun_ajaran_id' => $tahunAjaranId,
            'cache_key' => $cacheKey
        ];

        ReportPerformanceTracker::measureSegment('cache_write', function () use ($cacheKey, $cacheData, $siswa, $type, $tahunAjaranId) {
            Cache::put($cacheKey, $cacheData, now()->addHours(self::CACHE_DURATION));
            self::rememberCacheIndex($siswa, $type, $tahunAjaranId);
        });

        Log::info("PDF cached successfully", [
            'cache_key' => $cacheKey,
            'file_path' => $filePath,
            'file_size_mb' => round($fileSize / 1024 / 1024, 2)
        ]);

        return $cacheData;
    }

    public static function cacheDocx(Siswa $siswa, $type, $tahunAjaranId, string $sourcePath, string $filename): ?array
    {
        if (! is_file($sourcePath) || ! is_readable($sourcePath)) {
            return null;
        }

        $cachedPath = self::DOCX_DIRECTORY.'/'.Str::uuid().'.docx';
        $fileSize = filesize($sourcePath) ?: 0;
        $stream = fopen($sourcePath, 'rb');

        if ($stream === false) {
            return null;
        }

        try {
            $stored = ReportPerformanceTracker::measureSegment('cache_write', function () use ($cachedPath, $stream) {
                return Storage::disk(self::STORAGE_DISK)->writeStream($cachedPath, $stream);
            });
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        if ($stored === false) {
            return null;
        }

        $cacheKey = self::getDocxCacheKey($siswa, $type, $tahunAjaranId);
        $cacheData = [
            'path' => $cachedPath,
            'filename' => $filename,
            'file_size' => $fileSize,
            'generated_at' => now()->toISOString(),
            'siswa_id' => $siswa->id,
            'type' => $type,
            'tahun_ajaran_id' => $tahunAjaranId,
            'cache_key' => $cacheKey,
        ];

        ReportPerformanceTracker::measureSegment('cache_write', function () use ($cacheKey, $cacheData, $siswa, $type, $tahunAjaranId) {
            Cache::put($cacheKey, $cacheData, now()->addHours(self::CACHE_DURATION));
            self::rememberCacheIndex($siswa, $type, $tahunAjaranId);
        });

        Log::info('DOCX cached successfully', [
            'cache_key' => $cacheKey,
            'file_path' => $cachedPath,
            'file_size_mb' => round($fileSize / 1024 / 1024, 2),
        ]);

        return $cacheData;
    }

    /**
     * Remove PDF from cache
     */
    public static function removeCachedPdf(Siswa $siswa, $type, $tahunAjaranId)
    {
        $cacheKey = self::getCacheKey($siswa, $type, $tahunAjaranId);
        $cachedData = Cache::get($cacheKey);

        if ($cachedData) {
            // Remove file
            if (Storage::disk(self::STORAGE_DISK)->exists($cachedData['path'])) {
                Storage::disk(self::STORAGE_DISK)->delete($cachedData['path']);
                Log::info("Cached PDF file deleted", ['path' => $cachedData['path']]);
            }

            // Remove from cache
            Cache::forget($cacheKey);
            Log::info("PDF removed from cache", ['cache_key' => $cacheKey]);
        }
    }

    public static function removeCachedDocx(Siswa $siswa, $type, $tahunAjaranId): void
    {
        $cacheKey = self::getDocxCacheKey($siswa, $type, $tahunAjaranId);
        $cachedData = Cache::get($cacheKey);

        if ($cachedData) {
            if (Storage::disk(self::STORAGE_DISK)->exists($cachedData['path'])) {
                Storage::disk(self::STORAGE_DISK)->delete($cachedData['path']);
                Log::info('Cached DOCX file deleted', ['path' => $cachedData['path']]);
            }

            Cache::forget($cacheKey);
            Log::info('DOCX removed from cache', ['cache_key' => $cacheKey]);
        }
    }

    private static function rememberCacheIndex(Siswa $siswa, $type, $tahunAjaranId): void
    {
        $indexKey = "pdf_cache_index_{$siswa->id}";
        $index = Cache::get($indexKey, []);
        $index[] = [
            'type' => $type,
            'tahun_ajaran_id' => $tahunAjaranId,
        ];
        $index = collect($index)
            ->unique(fn ($item) => ($item['type'] ?? '') . '_' . ($item['tahun_ajaran_id'] ?? ''))
            ->values()
            ->toArray();

        Cache::put($indexKey, $index, now()->addDays(30));
    }

    /**
     * Clear all PDF cache for a student
     */
    public static function clearStudentCache(
        Siswa $siswa,
        ?int $tahunAjaranId = null,
        bool $scheduleAutoPrepare = false,
        ?int $autoPrepareDelaySeconds = null
    ): void {
        $types = $tahunAjaranId
            ? app(ReportPeriodService::class)->filterOpenedTypes(['UTS', 'UAS'], null, $tahunAjaranId)
            : ['UTS', 'UAS'];

        if ($tahunAjaranId) {
            foreach ($types as $type) {
                self::removeCachedPdf($siswa, $type, $tahunAjaranId);
                self::removeCachedDocx($siswa, $type, $tahunAjaranId);
            }

            if ($scheduleAutoPrepare) {
                app(ReportPdfAutoPrepareService::class)
                    ->scheduleForStudent($siswa, $tahunAjaranId, $types, 'pdf_cache_invalidated', $autoPrepareDelaySeconds);
            }
        } else {
            $indexKey = "pdf_cache_index_{$siswa->id}";
            $index = Cache::get($indexKey, []);

            foreach ($index as $entry) {
                if (!isset($entry['type'], $entry['tahun_ajaran_id'])) {
                    continue;
                }

                self::removeCachedPdf(
                    $siswa,
                    $entry['type'],
                    $entry['tahun_ajaran_id']
                );
                self::removeCachedDocx(
                    $siswa,
                    $entry['type'],
                    $entry['tahun_ajaran_id']
                );

                if ($scheduleAutoPrepare) {
                    app(ReportPdfAutoPrepareService::class)
                        ->scheduleForStudent(
                            $siswa,
                            (int) $entry['tahun_ajaran_id'],
                            [(string) $entry['type']],
                            'pdf_cache_invalidated',
                            $autoPrepareDelaySeconds
                        );
                }
            }

            Cache::forget($indexKey);
        }

        Log::info("Student PDF cache cleared", ['siswa_id' => $siswa->id]);
    }

    /**
     * Get cache statistics
     */
    public static function getCacheStats()
    {
        // This would need Redis or custom implementation for full stats
        return [
            'total_cached_pdfs' => 'N/A (requires Redis)',
            'cache_hit_rate' => 'N/A (requires Redis)',
            'total_cache_size' => 'N/A (requires Redis)'
        ];
    }

    /**
     * Cleanup old cache entries
     */
    public static function cleanupOldCache()
    {
        // This is a background job task
        Log::info("PDF cache cleanup started");
        
        // In a real implementation, you'd iterate through all cache keys
        // and remove old entries. This requires Redis or custom tracking.
        
        Log::info("PDF cache cleanup completed");
    }
}

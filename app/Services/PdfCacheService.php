<?php

namespace App\Services;

use App\Models\Siswa;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;

class PdfCacheService
{
    const CACHE_PREFIX = 'pdf_rapor_';
    const CACHE_DURATION = 24; // hours
    const STORAGE_DISK = 'public';
    const PDF_DIRECTORY = 'pdf_reports';

    /**
     * Generate cache key for PDF
     */
    public static function getCacheKey(Siswa $siswa, $type, $tahunAjaranId)
    {
        return self::CACHE_PREFIX . "{$siswa->id}_{$type}_{$tahunAjaranId}";
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
        });

        Log::info("PDF cached successfully", [
            'cache_key' => $cacheKey,
            'file_path' => $filePath,
            'file_size_mb' => round($fileSize / 1024 / 1024, 2)
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

    /**
     * Clear all PDF cache for a student
     */
    public static function clearStudentCache(Siswa $siswa, ?int $tahunAjaranId = null): void
    {
        $types = ['UTS', 'UAS'];

        if ($tahunAjaranId) {
            foreach ($types as $type) {
                self::removeCachedPdf($siswa, $type, $tahunAjaranId);
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

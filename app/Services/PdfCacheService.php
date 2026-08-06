<?php

namespace App\Services;

use App\Models\Siswa;
use App\Models\TahunAjaran;
use InvalidArgumentException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
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
    public static function getCacheKey(Siswa $siswa, $type, $tahunAjaranId, ?int $semester = null)
    {
        [$type, $semester] = self::normalizeContext((string) $type, (int) $tahunAjaranId, $semester);

        return self::CACHE_PREFIX . "{$siswa->id}_{$type}_{$tahunAjaranId}_semester_{$semester}";
    }

    public static function getDocxCacheKey(Siswa $siswa, $type, $tahunAjaranId, ?int $semester = null): string
    {
        [$type, $semester] = self::normalizeContext((string) $type, (int) $tahunAjaranId, $semester);

        return self::CACHE_PREFIX . "docx_{$siswa->id}_{$type}_{$tahunAjaranId}_semester_{$semester}";
    }

    public static function getGenerationLockKey(Siswa $siswa, $type, $tahunAjaranId, ?int $semester = null): string
    {
        [$type, $semester] = self::normalizeContext((string) $type, (int) $tahunAjaranId, $semester);

        return self::CACHE_PREFIX . "generation_lock_{$siswa->id}_{$type}_{$tahunAjaranId}_semester_{$semester}";
    }

    public static function getGenerationRequestKey(Siswa $siswa, $type, $tahunAjaranId, ?int $semester = null): string
    {
        [$type, $semester] = self::normalizeContext((string) $type, (int) $tahunAjaranId, $semester);

        return self::CACHE_PREFIX . "generation_request_{$siswa->id}_{$type}_{$tahunAjaranId}_semester_{$semester}";
    }

    public static function getProgressKey(string $requestId): string
    {
        return "pdf_progress_{$requestId}";
    }

    public static function getAutoPrepareTokenKey(Siswa $siswa, $type, $tahunAjaranId, ?int $semester = null): string
    {
        [$type, $semester] = self::normalizeContext((string) $type, (int) $tahunAjaranId, $semester);

        return self::CACHE_PREFIX . "auto_prepare_token_{$siswa->id}_{$type}_{$tahunAjaranId}_semester_{$semester}";
    }

    public static function getFreshnessKey(Siswa $siswa, string $type, int $tahunAjaranId, ?int $semester = null): string
    {
        [$type, $semester] = self::normalizeContext($type, $tahunAjaranId, $semester);

        return self::CACHE_PREFIX . "freshness_{$siswa->id}_{$type}_{$tahunAjaranId}_semester_{$semester}";
    }

    public static function currentFreshnessVersion(
        Siswa $siswa,
        string $type,
        int $tahunAjaranId,
        ?int $semester = null
    ): int
    {
        [$type, $semester] = self::normalizeContext($type, $tahunAjaranId, $semester);
        self::rememberCacheIndex($siswa, $type, $tahunAjaranId, $semester);

        return (int) Cache::get(self::getFreshnessKey($siswa, $type, $tahunAjaranId, $semester), 0);
    }

    public static function freshnessIsCurrent(
        Siswa $siswa,
        string $type,
        int $tahunAjaranId,
        int $expectedVersion,
        ?int $semester = null
    ): bool {
        return self::currentFreshnessVersion($siswa, $type, $tahunAjaranId, $semester) === $expectedVersion;
    }

    public static function getPdfPreparationStatus(Siswa $siswa, $type, $tahunAjaranId, ?int $semester = null): string
    {
        if (self::hasValidCachedPdf($siswa, $type, $tahunAjaranId, $semester)) {
            return 'ready';
        }

        if (self::hasActiveGenerationRequest($siswa, $type, $tahunAjaranId, $semester) ||
            Cache::has(self::getAutoPrepareTokenKey($siswa, $type, $tahunAjaranId, $semester))) {
            return 'preparing';
        }

        return 'missing';
    }

    public static function hasValidCachedPdf(Siswa $siswa, $type, $tahunAjaranId, ?int $semester = null): bool
    {
        $semester = self::resolveSemester((int) $tahunAjaranId, $semester);
        $cachedData = Cache::get(self::getCacheKey($siswa, $type, $tahunAjaranId, $semester));

        if (! $cachedData || ! isset($cachedData['path'], $cachedData['generated_at'])) {
            return false;
        }

        if (! self::cacheMatchesFreshness($cachedData, $siswa, (string) $type, (int) $tahunAjaranId, $semester)) {
            self::removeCachedPdf($siswa, $type, $tahunAjaranId, $semester);

            return false;
        }

        if (! Storage::disk(self::STORAGE_DISK)->exists($cachedData['path'])) {
            return false;
        }

        return now()->diffInHours($cachedData['generated_at']) <= self::CACHE_DURATION;
    }

    public static function hasValidCachedDocx(Siswa $siswa, $type, $tahunAjaranId, ?int $semester = null): bool
    {
        $semester = self::resolveSemester((int) $tahunAjaranId, $semester);
        $cachedData = Cache::get(self::getDocxCacheKey($siswa, $type, $tahunAjaranId, $semester));

        if (! $cachedData || ! isset($cachedData['path'], $cachedData['generated_at'])) {
            return false;
        }

        if (! self::cacheMatchesFreshness($cachedData, $siswa, (string) $type, (int) $tahunAjaranId, $semester)) {
            self::removeCachedDocx($siswa, $type, $tahunAjaranId, $semester);

            return false;
        }

        if (! Storage::disk(self::STORAGE_DISK)->exists($cachedData['path'])) {
            return false;
        }

        return now()->diffInHours($cachedData['generated_at']) <= self::CACHE_DURATION;
    }

    public static function hasActiveGenerationRequest(Siswa $siswa, $type, $tahunAjaranId, ?int $semester = null): bool
    {
        $requestKey = self::getGenerationRequestKey($siswa, $type, $tahunAjaranId, $semester);
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
    public static function getCachedPdf(Siswa $siswa, $type, $tahunAjaranId, ?int $semester = null)
    {
        $token = ReportPerformanceTracker::startSegmentIfEnabled('cache_lookup');
        $semester = self::resolveSemester((int) $tahunAjaranId, $semester);

        try {
            $cacheKey = self::getCacheKey($siswa, $type, $tahunAjaranId, $semester);
            $cachedData = Cache::get($cacheKey);

            if (!$cachedData) {
                ReportPerformanceTracker::setCacheHitIfEnabled(false);

                return null;
            }

            if (! self::cacheMatchesFreshness($cachedData, $siswa, (string) $type, (int) $tahunAjaranId, $semester)) {
                self::removeCachedPdf($siswa, $type, $tahunAjaranId, $semester);
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
                self::removeCachedPdf($siswa, $type, $tahunAjaranId, $semester);
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

    public static function getCachedDocx(Siswa $siswa, $type, $tahunAjaranId, ?int $semester = null): ?array
    {
        $token = ReportPerformanceTracker::startSegmentIfEnabled('cache_lookup');
        $semester = self::resolveSemester((int) $tahunAjaranId, $semester);

        try {
            $cacheKey = self::getDocxCacheKey($siswa, $type, $tahunAjaranId, $semester);
            $cachedData = Cache::get($cacheKey);

            if (! $cachedData) {
                ReportPerformanceTracker::setCacheHitIfEnabled(false);

                return null;
            }

            if (! self::cacheMatchesFreshness($cachedData, $siswa, (string) $type, (int) $tahunAjaranId, $semester)) {
                self::removeCachedDocx($siswa, $type, $tahunAjaranId, $semester);
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
                self::removeCachedDocx($siswa, $type, $tahunAjaranId, $semester);
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
    public static function cachePdf(
        Siswa $siswa,
        $type,
        $tahunAjaranId,
        $filePath,
        $filename,
        $fileSize,
        ?int $expectedFreshnessVersion = null,
        ?int $semester = null
    )
    {
        $semester = self::resolveSemester((int) $tahunAjaranId, $semester);
        $currentVersion = self::currentFreshnessVersion($siswa, (string) $type, (int) $tahunAjaranId, $semester);
        if ($expectedFreshnessVersion !== null && $currentVersion !== $expectedFreshnessVersion) {
            return null;
        }

        $cacheKey = self::getCacheKey($siswa, $type, $tahunAjaranId, $semester);
        
        $cacheData = [
            'path' => $filePath,
            'filename' => $filename,
            'file_size' => $fileSize,
            'generated_at' => now()->toISOString(),
            'siswa_id' => $siswa->id,
            'siswa_name' => $siswa->nama,
            'type' => $type,
            'tahun_ajaran_id' => $tahunAjaranId,
            'semester' => $semester,
            'freshness_version' => $currentVersion,
            'cache_key' => $cacheKey
        ];

        ReportPerformanceTracker::measureSegment('cache_write', function () use ($cacheKey, $cacheData, $siswa, $type, $tahunAjaranId, $semester) {
            Cache::put($cacheKey, $cacheData, now()->addHours(self::CACHE_DURATION));
            self::rememberCacheIndex($siswa, $type, $tahunAjaranId, $semester);
        });

        Log::info("PDF cached successfully", [
            'cache_key' => $cacheKey,
            'file_path' => $filePath,
            'file_size_mb' => round($fileSize / 1024 / 1024, 2)
        ]);

        return $cacheData;
    }

    public static function cacheDocx(
        Siswa $siswa,
        $type,
        $tahunAjaranId,
        string $sourcePath,
        string $filename,
        ?int $expectedFreshnessVersion = null,
        ?int $semester = null
    ): ?array
    {
        $semester = self::resolveSemester((int) $tahunAjaranId, $semester);
        $currentVersion = self::currentFreshnessVersion($siswa, (string) $type, (int) $tahunAjaranId, $semester);
        if ($expectedFreshnessVersion !== null && $currentVersion !== $expectedFreshnessVersion) {
            return null;
        }

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

        if ($expectedFreshnessVersion !== null
            && ! self::freshnessIsCurrent($siswa, (string) $type, (int) $tahunAjaranId, $expectedFreshnessVersion, $semester)) {
            Storage::disk(self::STORAGE_DISK)->delete($cachedPath);

            return null;
        }

        $cacheKey = self::getDocxCacheKey($siswa, $type, $tahunAjaranId, $semester);
        $cacheData = [
            'path' => $cachedPath,
            'filename' => $filename,
            'file_size' => $fileSize,
            'generated_at' => now()->toISOString(),
            'siswa_id' => $siswa->id,
            'type' => $type,
            'tahun_ajaran_id' => $tahunAjaranId,
            'semester' => $semester,
            'freshness_version' => $currentVersion,
            'cache_key' => $cacheKey,
        ];

        ReportPerformanceTracker::measureSegment('cache_write', function () use ($cacheKey, $cacheData, $siswa, $type, $tahunAjaranId, $semester) {
            Cache::put($cacheKey, $cacheData, now()->addHours(self::CACHE_DURATION));
            self::rememberCacheIndex($siswa, $type, $tahunAjaranId, $semester);
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
    public static function removeCachedPdf(Siswa $siswa, $type, $tahunAjaranId, ?int $semester = null)
    {
        $cacheKey = self::getCacheKey($siswa, $type, $tahunAjaranId, $semester);
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

    public static function removeCachedDocx(Siswa $siswa, $type, $tahunAjaranId, ?int $semester = null): void
    {
        $cacheKey = self::getDocxCacheKey($siswa, $type, $tahunAjaranId, $semester);
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

    private static function rememberCacheIndex(Siswa $siswa, $type, $tahunAjaranId, int $semester): void
    {
        $indexKey = "pdf_cache_index_{$siswa->id}";
        $index = Cache::get($indexKey, []);
        $index[] = [
            'type' => $type,
            'tahun_ajaran_id' => $tahunAjaranId,
            'semester' => $semester,
        ];
        $index = collect($index)
            ->unique(fn ($item) => implode('_', [
                $item['type'] ?? '',
                $item['tahun_ajaran_id'] ?? '',
                $item['semester'] ?? '',
            ]))
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
        ?int $autoPrepareDelaySeconds = null,
        ?int $semester = null
    ): void {
        $types = ['UTS', 'UAS'];

        if ($tahunAjaranId) {
            foreach (self::semestersForInvalidation($semester) as $reportSemester) {
                foreach ($types as $type) {
                    self::incrementFreshnessVersion($siswa, $type, $tahunAjaranId, $reportSemester);
                    self::removeCachedPdf($siswa, $type, $tahunAjaranId, $reportSemester);
                    self::removeCachedDocx($siswa, $type, $tahunAjaranId, $reportSemester);
                }
            }

            if ($scheduleAutoPrepare) {
                $openedTypes = app(ReportPeriodService::class)
                    ->filterOpenedTypes($types, null, $tahunAjaranId);
                app(ReportPdfAutoPrepareService::class)
                    ->scheduleForStudent($siswa, $tahunAjaranId, $openedTypes, 'pdf_cache_invalidated', $autoPrepareDelaySeconds);
            }
        } else {
            $indexKey = "pdf_cache_index_{$siswa->id}";
            $index = Cache::get($indexKey, []);

            foreach ($index as $entry) {
                if (!isset($entry['type'], $entry['tahun_ajaran_id'])) {
                    continue;
                }

                foreach (self::semestersForInvalidation(isset($entry['semester']) ? (int) $entry['semester'] : null) as $reportSemester) {
                    self::incrementFreshnessVersion(
                        $siswa,
                        (string) $entry['type'],
                        (int) $entry['tahun_ajaran_id'],
                        $reportSemester
                    );

                    self::removeCachedPdf(
                        $siswa,
                        $entry['type'],
                        $entry['tahun_ajaran_id'],
                        $reportSemester
                    );
                    self::removeCachedDocx(
                        $siswa,
                        $entry['type'],
                        $entry['tahun_ajaran_id'],
                        $reportSemester
                    );
                }

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

    public static function invalidateStudentReportType(
        Siswa $siswa,
        string $type,
        int $tahunAjaranId,
        bool $scheduleAutoPrepare = false,
        ?int $semester = null
    ): void {
        foreach (self::semestersForInvalidation($semester) as $reportSemester) {
            self::incrementFreshnessVersion($siswa, $type, $tahunAjaranId, $reportSemester);
            self::removeCachedPdf($siswa, $type, $tahunAjaranId, $reportSemester);
            self::removeCachedDocx($siswa, $type, $tahunAjaranId, $reportSemester);
        }

        if ($scheduleAutoPrepare) {
            app(ReportPdfAutoPrepareService::class)->scheduleForStudent(
                $siswa,
                $tahunAjaranId,
                [$type],
                'report_template_changed'
            );
        }
    }

    private static function cacheMatchesFreshness(
        array $cachedData,
        Siswa $siswa,
        string $type,
        int $tahunAjaranId,
        int $semester
    ): bool {
        if (! array_key_exists('freshness_version', $cachedData)
            || (int) ($cachedData['semester'] ?? 0) !== $semester) {
            return false;
        }

        return (int) $cachedData['freshness_version']
            === self::currentFreshnessVersion($siswa, $type, $tahunAjaranId, $semester);
    }

    private static function incrementFreshnessVersion(
        Siswa $siswa,
        string $type,
        int $tahunAjaranId,
        int $semester
    ): int
    {
        $key = self::getFreshnessKey($siswa, $type, $tahunAjaranId, $semester);

        if (! Cache::has($key)) {
            Cache::forever($key, 0);
        }

        return (int) Cache::increment($key);
    }

    public static function clearAllStudentCaches(): void
    {
        if (! Schema::hasTable('siswas')) {
            return;
        }

        Siswa::withoutGlobalScopes()
            ->select('id')
            ->chunkById(200, function ($students) {
                $students->each(fn (Siswa $siswa) => self::clearStudentCache($siswa));
            });
    }

    public static function clearYearCaches(int $tahunAjaranId): void
    {
        if (! Schema::hasTable('siswas')) {
            return;
        }

        Siswa::withoutGlobalScopes()
            ->select('id')
            ->chunkById(200, function ($students) use ($tahunAjaranId) {
                $students->each(fn (Siswa $siswa) => self::clearStudentCache($siswa, $tahunAjaranId));
            });
    }

    public static function clearClassCaches(iterable $classIds): void
    {
        $classIds = collect($classIds)->map(fn ($id) => (int) $id)->filter()->unique()->values();
        if ($classIds->isEmpty() || ! Schema::hasTable('siswas')) {
            return;
        }

        $hasCurrentClass = Schema::hasColumn('siswas', 'kelas_id');
        $hasEnrollments = Schema::hasTable('siswa_kelas_semester');
        if (! $hasCurrentClass && ! $hasEnrollments) {
            return;
        }

        Siswa::withoutGlobalScopes()
            ->where(function ($query) use ($classIds, $hasCurrentClass, $hasEnrollments) {
                if ($hasCurrentClass) {
                    $query->whereIn('kelas_id', $classIds);
                }

                if ($hasEnrollments) {
                    $method = $hasCurrentClass ? 'orWhereHas' : 'whereHas';
                    $query->{$method}('semesterEnrollments', fn ($enrollments) => $enrollments->whereIn('kelas_id', $classIds));
                }
            })
            ->select('siswas.id')
            ->distinct()
            ->chunkById(200, function ($students) {
                $students->each(fn (Siswa $siswa) => self::clearStudentCache($siswa));
            }, 'siswas.id', 'id');
    }

    private static function normalizeContext(string $type, int $tahunAjaranId, ?int $semester): array
    {
        $type = app(ReportPeriodService::class)->normalizeType($type);
        if (! $type) {
            throw new InvalidArgumentException('Jenis rapor cache tidak valid.');
        }

        return [$type, self::resolveSemester($tahunAjaranId, $semester)];
    }

    private static function resolveSemester(int $tahunAjaranId, ?int $semester): int
    {
        if ($semester !== null) {
            if (! in_array($semester, [1, 2], true)) {
                throw new InvalidArgumentException('Semester rapor cache tidak valid.');
            }

            return $semester;
        }

        $resolved = TahunAjaran::withTrashed()->whereKey($tahunAjaranId)->value('semester');
        $resolved = $resolved === null ? null : (int) $resolved;

        if (! in_array($resolved, [1, 2], true)) {
            throw new InvalidArgumentException('Konteks semester rapor cache tidak tersedia.');
        }

        return $resolved;
    }

    private static function semestersForInvalidation(?int $semester): array
    {
        if ($semester === null) {
            return [1, 2];
        }

        if (! in_array($semester, [1, 2], true)) {
            throw new InvalidArgumentException('Semester rapor cache tidak valid.');
        }

        return [$semester];
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

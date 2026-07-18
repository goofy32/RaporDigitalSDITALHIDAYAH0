<?php

namespace App\Services;

use App\Models\Siswa;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Throwable;

class SiswaPurgeService
{
    public const NOT_FOUND_MESSAGE = 'Data siswa tidak ditemukan.';
    public const NOT_TRASHED_MESSAGE = 'Data siswa belum berada di recycle bin. Hapus biasa terlebih dahulu sebelum menghapus permanen.';
    public const CONFIRMATION_MISMATCH_MESSAGE = 'Konfirmasi hapus permanen siswa tidak sesuai.';
    public const UNRESOLVED_DEPENDENCY_MESSAGE = 'Hapus permanen siswa dibatalkan karena ada relasi siswa yang belum dapat dipastikan aman.';
    public const FILE_CLEANUP_WARNING = 'Data siswa berhasil dihapus permanen, tetapi ada file atau cache rapor yang perlu dibersihkan oleh administrator sistem.';

    /**
     * Tables whose rows are owned by one Siswa through a direct siswa_id column.
     *
     * @var array<string, string>
     */
    private const STUDENT_REFERENCE_TABLES = [
        'pembelajaran_siswa' => 'pivot pembelajaran siswa',
        'report_generations' => 'riwayat rapor',
        'nilais' => 'nilai',
        'catatan_mata_pelajaran' => 'catatan mata pelajaran',
        'catatan_siswa' => 'catatan siswa',
        'capaian_custom' => 'capaian kompetensi',
        'nilai_ekstrakurikuler' => 'nilai ekstrakurikuler',
        'prestasis' => 'prestasi',
        'absensis' => 'absensi',
        'siswa_kelas_semester' => 'riwayat kelas semester',
    ];

    public function confirmationPhrase(Siswa $siswa): string
    {
        $identifier = trim((string) ($siswa->nis ?: $siswa->nisn ?: $siswa->id));

        return 'HAPUS PERMANEN SISWA '.$identifier;
    }

    public function purge(int $siswaId, string $submittedConfirmation): array
    {
        return DB::transaction(function () use ($siswaId, $submittedConfirmation) {
            $siswa = Siswa::withTrashed()
                ->whereKey($siswaId)
                ->lockForUpdate()
                ->first();

            if (! $siswa) {
                throw new SiswaPurgeException(self::NOT_FOUND_MESSAGE, [
                    'siswa_id' => $siswaId,
                ]);
            }

            if (! $siswa->trashed()) {
                throw new SiswaPurgeException(self::NOT_TRASHED_MESSAGE, [
                    'siswa_id' => $siswaId,
                    'deleted_at' => $siswa->deleted_at,
                ]);
            }

            $expectedConfirmation = $this->confirmationPhrase($siswa);
            if (trim($submittedConfirmation) !== $expectedConfirmation) {
                throw new SiswaPurgeException(self::CONFIRMATION_MISMATCH_MESSAGE, [
                    'siswa_id' => $siswaId,
                    'expected_confirmation' => $expectedConfirmation,
                    'submitted_confirmation_present' => trim($submittedConfirmation) !== '',
                ]);
            }

            $unclassifiedTables = $this->unclassifiedStudentReferencesFor($siswaId);
            if ($unclassifiedTables !== []) {
                throw new SiswaPurgeException(self::UNRESOLVED_DEPENDENCY_MESSAGE, [
                    'siswa_id' => $siswaId,
                    'unclassified_tables' => $unclassifiedTables,
                ]);
            }

            $plan = $this->buildPlan($siswa);

            $this->deleteDependencies($plan);
            $this->afterDependenciesDeleted($plan);
            $this->assertNoRemainingStudentReferences($siswaId);

            $siswaSnapshot = $this->auditSnapshot($siswa);
            $siswa->forceDeleteQuietly();
            $this->writePurgeAudit($siswaId, $siswaSnapshot, $plan['counts']);

            return [
                'siswa_id' => $siswaId,
                'siswa_name' => (string) ($siswaSnapshot['nama'] ?? ''),
                'photo_path' => $plan['photo_path'],
                'generated_report_file_paths' => $plan['generated_report_file_paths'],
                'report_cache_entries' => $plan['report_cache_entries'],
                'counts' => $plan['counts'],
            ];
        });
    }

    public function runPostCommitCleanupSafely(array $purgeResult): bool
    {
        $cleanupComplete = true;
        $siswaId = (int) ($purgeResult['siswa_id'] ?? 0);

        $categories = [
            'student_photo' => fn () => $this->cleanupStudentPhotoAfterCommit($purgeResult['photo_path'] ?? null, $siswaId),
            'generated_report_files' => fn () => $this->cleanupGeneratedReportFilesAfterCommit($purgeResult['generated_report_file_paths'] ?? [], $siswaId),
            'report_caches' => fn () => $this->cleanupReportCachesAfterCommit($siswaId, $purgeResult['report_cache_entries'] ?? []),
        ];

        foreach ($categories as $category => $callback) {
            try {
                if ($callback() === false) {
                    $cleanupComplete = false;
                }
            } catch (Throwable $exception) {
                $cleanupComplete = false;

                $this->logPostCommitCleanupFailure($category, $siswaId, $exception);
            }
        }

        return $cleanupComplete;
    }

    private function buildPlan(Siswa $siswa): array
    {
        $siswaId = (int) $siswa->id;
        $ids = [];

        foreach (array_keys(self::STUDENT_REFERENCE_TABLES) as $table) {
            $ids[$table] = $this->lockIdsWhereStudent($table, $siswaId);
        }

        $reportGenerationIds = $ids['report_generations'] ?? [];

        return [
            'siswa_id' => $siswaId,
            'ids' => $ids,
            'photo_path' => $this->normalizePublicDiskPath($siswa->photo),
            'generated_report_file_paths' => $this->reportGenerationFilePaths($reportGenerationIds),
            'report_cache_entries' => $this->reportCacheEntries($reportGenerationIds, $siswaId),
            'counts' => collect($ids)->map(fn (array $tableIds) => count($tableIds))->all(),
        ];
    }

    private function deleteDependencies(array $plan): void
    {
        foreach (array_keys(self::STUDENT_REFERENCE_TABLES) as $table) {
            $this->deleteWhereIn($table, 'id', $plan['ids'][$table] ?? []);
        }
    }

    protected function afterDependenciesDeleted(array $plan): void
    {
    }

    protected function writePurgeAudit(int $siswaId, array $siswaSnapshot, array $counts): void
    {
        AuditService::log(
            'permanent_purge',
            Siswa::class,
            $siswaId,
            'Siswa dihapus permanen melalui purge aman recycle bin.',
            $siswaSnapshot,
            [
                'safe_flow' => 'recycle_bin_siswa_purge',
                'counts' => $counts,
            ]
        );
    }

    private function auditSnapshot(Siswa $siswa): array
    {
        return [
            'id' => (int) $siswa->id,
            'nis' => $siswa->nis,
            'nisn' => $siswa->nisn,
            'nama' => $siswa->nama,
            'kelas_id' => $siswa->kelas_id !== null ? (int) $siswa->kelas_id : null,
            'tahun_ajaran_id' => $siswa->tahun_ajaran_id !== null ? (int) $siswa->tahun_ajaran_id : null,
            'status' => $siswa->status,
            'deleted_at' => optional($siswa->deleted_at)->toDateTimeString(),
            'created_at' => optional($siswa->created_at)->toDateTimeString(),
            'updated_at' => optional($siswa->updated_at)->toDateTimeString(),
        ];
    }

    private function assertNoRemainingStudentReferences(int $siswaId): void
    {
        $remaining = [];

        foreach (array_keys(self::STUDENT_REFERENCE_TABLES) as $table) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'siswa_id')) {
                continue;
            }

            $count = DB::table($table)->where('siswa_id', $siswaId)->count();
            if ($count > 0) {
                $remaining[$table] = $count;
            }
        }

        if ($remaining !== []) {
            throw new SiswaPurgeException(self::UNRESOLVED_DEPENDENCY_MESSAGE, [
                'siswa_id' => $siswaId,
                'remaining_references' => $remaining,
            ]);
        }
    }

    private function lockIdsWhereStudent(string $table, int $siswaId): array
    {
        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'siswa_id') || ! Schema::hasColumn($table, 'id')) {
            return [];
        }

        return DB::table($table)
            ->where('siswa_id', $siswaId)
            ->orderBy('id')
            ->lockForUpdate()
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();
    }

    private function deleteWhereIn(string $table, string $column, array $ids): void
    {
        if ($ids === [] || ! Schema::hasTable($table) || ! Schema::hasColumn($table, $column)) {
            return;
        }

        DB::table($table)
            ->whereIn($column, $ids)
            ->delete();
    }

    private function reportGenerationFilePaths(array $reportGenerationIds): array
    {
        if ($reportGenerationIds === [] || ! Schema::hasTable('report_generations') || ! Schema::hasColumn('report_generations', 'generated_file')) {
            return [];
        }

        return DB::table('report_generations')
            ->whereIn('id', $reportGenerationIds)
            ->pluck('generated_file')
            ->map(fn ($path) => $this->normalizePublicDiskPath($path))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    private function reportCacheEntries(array $reportGenerationIds, int $siswaId): array
    {
        if (
            $reportGenerationIds === []
            || ! Schema::hasTable('report_generations')
            || ! Schema::hasColumn('report_generations', 'type')
            || ! Schema::hasColumn('report_generations', 'tahun_ajaran_id')
        ) {
            return [];
        }

        return DB::table('report_generations')
            ->whereIn('id', $reportGenerationIds)
            ->get(['type', 'tahun_ajaran_id'])
            ->map(fn ($row) => [
                'siswa_id' => $siswaId,
                'type' => (string) $row->type,
                'tahun_ajaran_id' => $row->tahun_ajaran_id !== null ? (int) $row->tahun_ajaran_id : null,
            ])
            ->filter(fn (array $entry) => $entry['type'] !== '' && $entry['tahun_ajaran_id'] !== null)
            ->unique(fn (array $entry) => $entry['siswa_id'].'_'.$entry['type'].'_'.$entry['tahun_ajaran_id'])
            ->values()
            ->all();
    }

    private function cleanupStudentPhotoAfterCommit(?string $path, int $siswaId): bool
    {
        $normalizedPath = $this->normalizePublicDiskPath($path);

        if (! $normalizedPath || $normalizedPath === 'default-avatar.png') {
            return true;
        }

        try {
            $disk = Storage::disk('public');
        } catch (Throwable $exception) {
            Log::warning('[SiswaPurgeService] Student photo storage disk unavailable after purge.', [
                'siswa_id' => $siswaId,
                'cleanup_category' => 'student_photo',
                'exception_class' => get_class($exception),
                'error' => $exception->getMessage(),
            ]);

            return false;
        }

        try {
            if ($this->remainingStudentUsesPhoto($normalizedPath)) {
                return true;
            }

            if (! $disk->exists($normalizedPath)) {
                return true;
            }

            if (! $disk->delete($normalizedPath)) {
                Log::warning('[SiswaPurgeService] Failed to delete student photo after purge.', [
                    'siswa_id' => $siswaId,
                    'path' => $normalizedPath,
                ]);

                return false;
            }
        } catch (Throwable $exception) {
            Log::warning('[SiswaPurgeService] Student photo cleanup failed after purge.', [
                'siswa_id' => $siswaId,
                'path' => $normalizedPath,
                'cleanup_category' => 'student_photo',
                'exception_class' => get_class($exception),
                'error' => $exception->getMessage(),
            ]);

            return false;
        }

        return true;
    }

    private function cleanupGeneratedReportFilesAfterCommit(array $paths, int $siswaId): bool
    {
        $allFilesCleaned = true;

        try {
            $disk = Storage::disk('public');
        } catch (Throwable $exception) {
            Log::warning('[SiswaPurgeService] Generated report storage disk unavailable after student purge.', [
                'siswa_id' => $siswaId,
                'cleanup_category' => 'generated_report_files',
                'exception_class' => get_class($exception),
                'error' => $exception->getMessage(),
            ]);

            return false;
        }

        foreach (array_values(array_unique($paths)) as $path) {
            $normalizedPath = $this->normalizePublicDiskPath($path);

            if (! $normalizedPath) {
                continue;
            }

            try {
                if ($this->remainingReportGenerationUsesFile($normalizedPath)) {
                    continue;
                }

                if (! $disk->exists($normalizedPath)) {
                    continue;
                }

                if (! $disk->delete($normalizedPath)) {
                    $allFilesCleaned = false;

                    Log::warning('[SiswaPurgeService] Failed to delete generated report file after student purge.', [
                        'siswa_id' => $siswaId,
                        'path' => $normalizedPath,
                    ]);
                }
            } catch (Throwable $exception) {
                $allFilesCleaned = false;

                Log::warning('[SiswaPurgeService] Generated report file cleanup failed after student purge.', [
                    'siswa_id' => $siswaId,
                    'path' => $normalizedPath,
                    'cleanup_category' => 'generated_report_files',
                    'exception_class' => get_class($exception),
                    'error' => $exception->getMessage(),
                ]);
            }
        }

        return $allFilesCleaned;
    }

    private function cleanupReportCachesAfterCommit(int $siswaId, array $entries): bool
    {
        $allCachesCleaned = true;
        $siswa = $this->transientSiswa($siswaId);
        $indexKey = "pdf_cache_index_{$siswaId}";
        $indexedEntries = Cache::get($indexKey, []);

        $entries = collect($entries)
            ->concat(is_array($indexedEntries) ? $indexedEntries : [])
            ->filter(fn ($entry) => is_array($entry) && isset($entry['type'], $entry['tahun_ajaran_id']))
            ->map(fn (array $entry) => [
                'type' => (string) $entry['type'],
                'tahun_ajaran_id' => (int) $entry['tahun_ajaran_id'],
            ])
            ->filter(fn (array $entry) => $entry['type'] !== '' && $entry['tahun_ajaran_id'] > 0)
            ->unique(fn (array $entry) => $entry['type'].'_'.$entry['tahun_ajaran_id'])
            ->values()
            ->all();

        foreach ($entries as $entry) {
            $type = $entry['type'];
            $tahunAjaranId = $entry['tahun_ajaran_id'];

            if (! $this->cleanupSingleCacheCategory('cached_pdf_file', $siswaId, $type, $tahunAjaranId, fn () => PdfCacheService::removeCachedPdf($siswa, $type, $tahunAjaranId))) {
                $allCachesCleaned = false;
            }

            if (! $this->cleanupSingleCacheCategory('cached_docx_file', $siswaId, $type, $tahunAjaranId, fn () => PdfCacheService::removeCachedDocx($siswa, $type, $tahunAjaranId))) {
                $allCachesCleaned = false;
            }

            $requestKey = null;
            $requestId = null;
            if (! $this->cleanupSingleCacheCategory('generation_request_key', $siswaId, $type, $tahunAjaranId, function () use ($siswa, $type, $tahunAjaranId, &$requestKey, &$requestId) {
                $requestKey = PdfCacheService::getGenerationRequestKey($siswa, $type, $tahunAjaranId);
                $requestId = Cache::get($requestKey);
                Cache::forget($requestKey);
            })) {
                $allCachesCleaned = false;
            }

            if (is_string($requestId) && $requestId !== '') {
                if (! $this->cleanupSingleCacheCategory('generation_progress_key', $siswaId, $type, $tahunAjaranId, fn () => Cache::forget(PdfCacheService::getProgressKey($requestId)))) {
                    $allCachesCleaned = false;
                }
            }

            if (! $this->cleanupSingleCacheCategory('auto_prepare_token_key', $siswaId, $type, $tahunAjaranId, fn () => Cache::forget(PdfCacheService::getAutoPrepareTokenKey($siswa, $type, $tahunAjaranId)))) {
                $allCachesCleaned = false;
            }

            if (! $this->cleanupSingleCacheCategory('generation_lock_key', $siswaId, $type, $tahunAjaranId, fn () => Cache::forget(PdfCacheService::getGenerationLockKey($siswa, $type, $tahunAjaranId)))) {
                $allCachesCleaned = false;
            }
        }

        try {
            Cache::forget($indexKey);
        } catch (Throwable $exception) {
            $allCachesCleaned = false;

            $this->logCacheCleanupFailure('cache_index_key', $siswaId, '-', 0, $exception);
        }

        return $allCachesCleaned;
    }

    private function cleanupSingleCacheCategory(string $category, int $siswaId, string $type, int $tahunAjaranId, callable $callback): bool
    {
        try {
            $callback();

            return true;
        } catch (Throwable $exception) {
            $this->logCacheCleanupFailure($category, $siswaId, $type, $tahunAjaranId, $exception);

            return false;
        }
    }

    private function transientSiswa(int $siswaId): Siswa
    {
        $siswa = new Siswa();
        $siswa->setAttribute($siswa->getKeyName(), $siswaId);
        $siswa->exists = false;

        return $siswa;
    }

    private function normalizePublicDiskPath(?string $path): ?string
    {
        $normalized = trim(str_replace('\\', '/', (string) $path));
        $normalized = preg_replace('#/+#', '/', $normalized) ?? '';
        $normalized = ltrim($normalized, '/');

        foreach (['storage/', 'public/', 'app/public/'] as $prefix) {
            while (str_starts_with($normalized, $prefix)) {
                $normalized = substr($normalized, strlen($prefix));
            }
        }

        return $normalized !== '' ? $normalized : null;
    }

    private function remainingStudentUsesPhoto(string $normalizedPath): bool
    {
        if (! Schema::hasTable('siswas') || ! Schema::hasColumn('siswas', 'photo')) {
            return false;
        }

        return DB::table('siswas')
            ->whereNotNull('photo')
            ->pluck('photo')
            ->contains(fn ($path) => $this->normalizePublicDiskPath($path) === $normalizedPath);
    }

    private function remainingReportGenerationUsesFile(string $normalizedPath): bool
    {
        if (! Schema::hasTable('report_generations') || ! Schema::hasColumn('report_generations', 'generated_file')) {
            return false;
        }

        return DB::table('report_generations')
            ->pluck('generated_file')
            ->contains(fn ($path) => $this->normalizePublicDiskPath($path) === $normalizedPath);
    }

    /**
     * @return array<string, int>
     */
    private function unclassifiedStudentReferencesFor(int $siswaId): array
    {
        $matches = [];

        foreach ($this->tableNames() as $table) {
            if (
                ! Schema::hasColumn($table, 'siswa_id')
                || array_key_exists($table, self::STUDENT_REFERENCE_TABLES)
            ) {
                continue;
            }

            $count = DB::table($table)->where('siswa_id', $siswaId)->count();
            if ($count > 0) {
                $matches[$table] = $count;
            }
        }

        return $matches;
    }

    /**
     * @return array<int, string>
     */
    private function tableNames(): array
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'sqlite') {
            return collect(DB::select("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%'"))
                ->pluck('name')
                ->map(fn ($name) => (string) $name)
                ->values()
                ->all();
        }

        $database = DB::connection()->getDatabaseName();

        return DB::table('information_schema.tables')
            ->where('table_schema', $database)
            ->pluck('TABLE_NAME')
            ->map(fn ($name) => (string) $name)
            ->values()
            ->all();
    }

    private function logCacheCleanupFailure(string $category, int $siswaId, string $type, int $tahunAjaranId, Throwable $exception): void
    {
        Log::warning('[SiswaPurgeService] Report cache cleanup failed after student purge.', [
            'siswa_id' => $siswaId,
            'type' => $type,
            'tahun_ajaran_id' => $tahunAjaranId,
            'cleanup_category' => $category,
            'exception_class' => get_class($exception),
            'error' => $exception->getMessage(),
        ]);
    }

    private function logPostCommitCleanupFailure(string $category, int $siswaId, Throwable $exception): void
    {
        Log::warning('[SiswaPurgeService] Post-commit cleanup category failed after student purge.', [
            'siswa_id' => $siswaId,
            'cleanup_category' => $category,
            'exception_class' => get_class($exception),
            'error' => $exception->getMessage(),
        ]);
    }
}

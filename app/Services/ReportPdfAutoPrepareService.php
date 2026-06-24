<?php

namespace App\Services;

use App\Jobs\AutoPreparePdfReportJob;
use App\Models\ReportTemplate;
use App\Models\Siswa;
use App\Models\TahunAjaran;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class ReportPdfAutoPrepareService
{
    private array $scheduledThisScope = [];

    public function scheduleForStudent(
        Siswa $siswa,
        int $tahunAjaranId,
        array $types = ['UTS', 'UAS'],
        ?string $reason = null
    ): void {
        if (! $this->enabled()) {
            return;
        }

        $types = collect($types)
            ->map(fn ($type) => strtoupper((string) $type))
            ->filter(fn ($type) => in_array($type, ['UTS', 'UAS'], true))
            ->unique()
            ->values();

        if ($types->isEmpty()) {
            return;
        }

        foreach ($types as $type) {
            $unavailableReason = $this->unavailableReason($siswa, $type, $tahunAjaranId);

            if ($unavailableReason) {
                $this->logSkippedUnavailable($siswa, $type, $tahunAjaranId, $unavailableReason, $reason);

                continue;
            }

            $scopeKey = $this->scopeKey($siswa->id, $type, $tahunAjaranId);

            if (isset($this->scheduledThisScope[$scopeKey])) {
                continue;
            }

            $token = (string) Str::uuid();

            Cache::put(
                PdfCacheService::getAutoPrepareTokenKey($siswa, $type, $tahunAjaranId),
                $token,
                now()->addHours(PdfCacheService::CACHE_DURATION)
            );

            AutoPreparePdfReportJob::dispatch($siswa->id, $type, $tahunAjaranId, $token, $reason)
                ->delay(now()->addSeconds($this->delaySeconds()))
                ->onQueue($this->queueName());

            $this->scheduledThisScope[$scopeKey] = true;

            Log::info('report.pdf.auto_prepare_scheduled', [
                'siswa_id' => $siswa->id,
                'report_type' => $type,
                'tahun_ajaran_id' => $tahunAjaranId,
                'semester' => $this->semesterForType($type),
                'cache_key' => PdfCacheService::getCacheKey($siswa, $type, $tahunAjaranId),
                'delay_seconds' => $this->delaySeconds(),
                'queue' => $this->queueName(),
                'reason' => $reason,
            ]);
        }
    }

    public function unavailableReason(Siswa $siswa, string $type, int $tahunAjaranId): ?string
    {
        $type = strtoupper($type);

        if (! in_array($type, ['UTS', 'UAS'], true)) {
            return 'invalid_report_type';
        }

        if (! Schema::hasTable('tahun_ajarans')) {
            return null;
        }

        $tahunAjaran = TahunAjaran::find($tahunAjaranId);

        if (! $tahunAjaran) {
            return 'academic_year_unavailable';
        }

        if ((int) $tahunAjaran->semester !== $this->semesterForType($type)) {
            return 'inactive_semester';
        }

        if (! Schema::hasTable('report_templates')) {
            return null;
        }

        if (! $this->hasActiveTemplateForStudent($siswa, $type, $tahunAjaranId, (int) $tahunAjaran->semester)) {
            return 'template_unavailable';
        }

        return null;
    }

    public function isLatestToken(Siswa $siswa, string $type, int $tahunAjaranId, string $token): bool
    {
        return hash_equals(
            (string) Cache::get(PdfCacheService::getAutoPrepareTokenKey($siswa, $type, $tahunAjaranId), ''),
            $token
        );
    }

    public function hasActiveUserGeneration(Siswa $siswa, string $type, int $tahunAjaranId): bool
    {
        $requestKey = PdfCacheService::getGenerationRequestKey($siswa, $type, $tahunAjaranId);
        $requestId = Cache::get($requestKey);

        if (! is_string($requestId) || $requestId === '') {
            return false;
        }

        $progress = Cache::get(PdfCacheService::getProgressKey($requestId));

        if (! is_array($progress)) {
            Cache::forget($requestKey);

            return false;
        }

        if (($progress['completed'] ?? false) || ($progress['error'] ?? false)) {
            Cache::forget($requestKey);

            return false;
        }

        $updatedAt = (int) ($progress['updated_at'] ?? 0);
        if ($updatedAt > 0 && $updatedAt < now()->subMinutes(PdfCacheService::PROCESSING_STALE_MINUTES)->timestamp) {
            Cache::forget($requestKey);

            return false;
        }

        return isset($progress['user_id']) && $progress['user_id'] !== null;
    }

    private function hasActiveTemplateForStudent(Siswa $siswa, string $type, int $tahunAjaranId, int $semester): bool
    {
        $kelasId = $this->resolveReportClassId($siswa, $tahunAjaranId, $semester);

        $baseQuery = fn () => ReportTemplate::query()
            ->where('type', $type)
            ->where('is_active', true)
            ->where('tahun_ajaran_id', $tahunAjaranId)
            ->where(function ($query) use ($semester) {
                $query->whereNull('semester')
                    ->orWhere('semester', $semester);
            });

        if ($kelasId) {
            if (Schema::hasTable('report_template_kelas') &&
                $baseQuery()->whereHas('kelasList', fn ($query) => $query->where('kelas_id', $kelasId))->exists()) {
                return true;
            }

            if ($baseQuery()->where('kelas_id', $kelasId)->exists()) {
                return true;
            }
        }

        $query = $baseQuery()->whereNull('kelas_id');

        if (Schema::hasTable('report_template_kelas')) {
            $query->whereDoesntHave('kelasList');
        }

        return $query->exists();
    }

    private function resolveReportClassId(Siswa $siswa, int $tahunAjaranId, int $semester): ?int
    {
        try {
            return app(SiswaKelasSemesterResolver::class)
                ->resolveClass($siswa, $tahunAjaranId, $semester, true)?->id
                ?: $siswa->kelas_id;
        } catch (\Throwable) {
            return $siswa->kelas_id;
        }
    }

    private function logSkippedUnavailable(
        Siswa $siswa,
        string $type,
        int $tahunAjaranId,
        string $unavailableReason,
        ?string $reason
    ): void {
        Log::info('report.pdf.auto_prepare_skipped_unavailable', [
            'siswa_id' => $siswa->id,
            'report_type' => $type,
            'tahun_ajaran_id' => $tahunAjaranId,
            'semester' => $this->semesterForType($type),
            'cache_key' => PdfCacheService::getCacheKey($siswa, $type, $tahunAjaranId),
            'unavailable_reason' => $unavailableReason,
            'reason' => $reason,
        ]);
    }

    private function enabled(): bool
    {
        return (bool) config('report.pdf_auto_prepare.enabled', false);
    }

    private function delaySeconds(): int
    {
        return max(0, (int) config('report.pdf_auto_prepare.delay_seconds', 60));
    }

    private function queueName(): string
    {
        $queue = trim((string) config('report.pdf_auto_prepare.queue', 'pdf-warm'));

        return $queue !== '' ? $queue : 'pdf-warm';
    }

    private function scopeKey(int $siswaId, string $type, int $tahunAjaranId): string
    {
        return "{$siswaId}:{$type}:{$tahunAjaranId}";
    }

    private function semesterForType(string $type): int
    {
        return strtoupper($type) === 'UTS' ? 1 : 2;
    }
}

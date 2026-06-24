<?php

namespace App\Services;

use App\Jobs\AutoPreparePdfReportJob;
use App\Models\Siswa;
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

<?php

namespace App\Jobs;

use App\Models\Siswa;
use App\Services\PdfCacheService;
use App\Services\ReportPdfAutoPrepareService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class AutoPreparePdfReportJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 300;

    public $tries = 1;

    public function __construct(
        public int $siswaId,
        public string $type,
        public int $tahunAjaranId,
        public string $token,
        public ?string $reason = null,
        public ?int $semester = null
    ) {
        $this->type = strtoupper($this->type);
    }

    public function handle(ReportPdfAutoPrepareService $autoPrepare): void
    {
        $startedAt = microtime(true);
        $siswa = Siswa::find($this->siswaId);

        if (! $siswa) {
            $this->log('report.pdf.auto_prepare_skipped_stale', $startedAt);

            return;
        }

        if (! $autoPrepare->isLatestToken($siswa, $this->type, $this->tahunAjaranId, $this->token, $this->semester)) {
            $this->log('report.pdf.auto_prepare_skipped_stale', $startedAt, $siswa);

            return;
        }

        if ($unavailableReason = $autoPrepare->unavailableReason($siswa, $this->type, $this->tahunAjaranId)) {
            $this->log('report.pdf.auto_prepare_skipped_unavailable', $startedAt, $siswa, [
                'unavailable_reason' => $unavailableReason,
            ]);

            return;
        }

        if (PdfCacheService::getCachedPdf($siswa, $this->type, $this->tahunAjaranId, $this->semester)) {
            $this->log('report.pdf.auto_prepare_skipped_cache_hit', $startedAt, $siswa);

            return;
        }

        if ($autoPrepare->hasActiveUserGeneration($siswa, $this->type, $this->tahunAjaranId, $this->semester)) {
            $this->log('report.pdf.auto_prepare_skipped_user_request', $startedAt, $siswa);

            return;
        }

        try {
            $requestId = 'auto_pdf_'.Str::uuid();

            app()->call([
                new GeneratePdfReportJob(
                    $siswa,
                    $this->type,
                    $this->tahunAjaranId,
                    $requestId,
                    null,
                    $this->semester
                ),
                'handle',
            ]);

            if (! $autoPrepare->isLatestToken($siswa, $this->type, $this->tahunAjaranId, $this->token, $this->semester)) {
                PdfCacheService::removeCachedPdf($siswa, $this->type, $this->tahunAjaranId, $this->semester);
                $this->log('report.pdf.auto_prepare_skipped_stale', $startedAt, $siswa);

                return;
            }

            $this->log('report.pdf.auto_prepare_completed', $startedAt, $siswa);
        } catch (Throwable $exception) {
            $this->log('report.pdf.auto_prepare_failed', $startedAt, $siswa, [
                'exception' => $exception::class,
            ]);
        }
    }

    private function log(string $message, float $startedAt, ?Siswa $siswa = null, array $extra = []): void
    {
        Log::info($message, array_merge([
            'siswa_id' => $siswa?->id ?? $this->siswaId,
            'report_type' => $this->type,
            'tahun_ajaran_id' => $this->tahunAjaranId,
            'semester' => $this->semester,
            'cache_key' => $siswa
                ? PdfCacheService::getCacheKey($siswa, $this->type, $this->tahunAjaranId, $this->semester)
                : null,
            'duration_ms' => round((microtime(true) - $startedAt) * 1000, 2),
            'reason' => $this->reason,
        ], $extra));
    }
}

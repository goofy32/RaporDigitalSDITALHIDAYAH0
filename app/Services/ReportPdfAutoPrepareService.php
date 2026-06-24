<?php

namespace App\Services;

use App\Jobs\AutoPreparePdfReportJob;
use App\Models\Guru;
use App\Models\ReportTemplate;
use App\Models\Siswa;
use App\Models\TahunAjaran;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class ReportPdfAutoPrepareService
{
    private array $scheduledThisScope = [];

    public function scheduleForStudent(
        Siswa $siswa,
        int $tahunAjaranId,
        array $types = ['UTS', 'UAS'],
        ?string $reason = null,
        ?int $delaySeconds = null
    ): int {
        if (! $this->enabled()) {
            return 0;
        }

        $types = collect($types)
            ->map(fn ($type) => strtoupper((string) $type))
            ->filter(fn ($type) => in_array($type, ['UTS', 'UAS'], true))
            ->unique()
            ->values();

        if ($types->isEmpty()) {
            return 0;
        }

        $scheduled = 0;
        $resolvedDelaySeconds = $this->delaySeconds($delaySeconds);

        foreach ($types as $type) {
            $unavailableReason = $this->unavailableReason($siswa, $type, $tahunAjaranId);

            if ($unavailableReason) {
                $this->logSkippedUnavailable($siswa, $type, $tahunAjaranId, $unavailableReason, $reason);

                continue;
            }

            if (PdfCacheService::getPdfPreparationStatus($siswa, $type, $tahunAjaranId) === 'ready') {
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
                ->delay(now()->addSeconds($resolvedDelaySeconds))
                ->onQueue($this->queueName());

            $this->scheduledThisScope[$scopeKey] = true;
            $scheduled++;

            Log::info('report.pdf.auto_prepare_scheduled', [
                'siswa_id' => $siswa->id,
                'report_type' => $type,
                'tahun_ajaran_id' => $tahunAjaranId,
                'semester' => $this->semesterForType($type),
                'cache_key' => PdfCacheService::getCacheKey($siswa, $type, $tahunAjaranId),
                'delay_seconds' => $resolvedDelaySeconds,
                'queue' => $this->queueName(),
                'reason' => $reason,
            ]);
        }

        return $scheduled;
    }

    public function scheduleDashboardWarmupForWali(Guru $guru, TahunAjaran $tahunAjaran, ?int $semester = null): array
    {
        $summary = [
            'classes' => 0,
            'students' => 0,
            'scheduled' => 0,
            'cached' => 0,
            'cooldown' => 0,
            'skipped' => 0,
        ];

        if (! $this->enabled() || ! $this->dashboardWarmupEnabled()) {
            return $summary;
        }

        if (! (bool) $tahunAjaran->is_active) {
            return $summary;
        }

        $semester = (int) ($semester ?: $tahunAjaran->semester);
        $type = $this->typeForSemester($semester);

        if (! $type) {
            return $summary;
        }

        $classes = DB::table('guru_kelas')
            ->join('kelas', 'guru_kelas.kelas_id', '=', 'kelas.id')
            ->where('guru_kelas.guru_id', $guru->id)
            ->where('guru_kelas.is_wali_kelas', true)
            ->where('guru_kelas.role', 'wali_kelas')
            ->where('kelas.tahun_ajaran_id', $tahunAjaran->id)
            ->whereNull('kelas.deleted_at')
            ->select('kelas.id')
            ->get();

        $summary['classes'] = $classes->count();

        foreach ($classes as $kelas) {
            $students = app(SiswaKelasSemesterResolver::class)
                ->studentsForClass((int) $kelas->id, (int) $tahunAjaran->id, $semester, true);
            $pendingStudents = collect();

            foreach ($students as $student) {
                $summary['students']++;

                if (PdfCacheService::getPdfPreparationStatus($student, $type, (int) $tahunAjaran->id) === 'ready') {
                    $summary['cached']++;

                    continue;
                }

                $pendingStudents->push($student);
            }

            if ($pendingStudents->isEmpty()) {
                continue;
            }

            $cooldownKey = $this->dashboardWarmupCooldownKey($guru->id, (int) $kelas->id, $type, (int) $tahunAjaran->id);

            if (! Cache::add($cooldownKey, true, now()->addSeconds($this->dashboardWarmupCooldownSeconds()))) {
                $summary['cooldown']++;
                $summary['skipped'] += $pendingStudents->count();

                continue;
            }

            foreach ($pendingStudents as $student) {
                $scheduled = $this->scheduleForStudent($student, (int) $tahunAjaran->id, [$type], 'dashboard_warmup');

                if ($scheduled > 0) {
                    $summary['scheduled'] += $scheduled;
                } else {
                    $summary['skipped']++;
                }
            }
        }

        return $summary;
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
        if (! PdfCacheService::hasActiveGenerationRequest($siswa, $type, $tahunAjaranId)) {
            return false;
        }

        $requestKey = PdfCacheService::getGenerationRequestKey($siswa, $type, $tahunAjaranId);
        $requestId = Cache::get($requestKey);

        $progress = Cache::get(PdfCacheService::getProgressKey($requestId));

        return is_array($progress) && isset($progress['user_id']) && $progress['user_id'] !== null;
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

    private function dashboardWarmupEnabled(): bool
    {
        return (bool) config('report.pdf_dashboard_warmup.enabled', false);
    }

    private function dashboardWarmupCooldownSeconds(): int
    {
        return max(1, (int) config('report.pdf_dashboard_warmup.cooldown_seconds', 900));
    }

    public function lateStageDelaySeconds(): int
    {
        return max(0, (int) config('report.pdf_auto_prepare.late_stage_delay_seconds', 10));
    }

    private function delaySeconds(?int $overrideSeconds = null): int
    {
        if ($overrideSeconds !== null) {
            return max(0, $overrideSeconds);
        }

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

    private function typeForSemester(int $semester): ?string
    {
        return match ($semester) {
            1 => 'UTS',
            2 => 'UAS',
            default => null,
        };
    }

    private function dashboardWarmupCooldownKey(int $guruId, int $kelasId, string $type, int $tahunAjaranId): string
    {
        return "report_pdf_dashboard_warmup:{$guruId}:{$kelasId}:{$type}:{$tahunAjaranId}";
    }
}

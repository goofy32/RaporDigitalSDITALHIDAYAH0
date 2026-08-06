<?php

namespace App\Console\Commands;

use App\Models\Guru;
use App\Models\Siswa;
use App\Models\TahunAjaran;
use App\Services\PdfCacheService;
use App\Services\ReportPdfAutoPrepareService;
use App\Services\SiswaKelasSemesterResolver;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class SimulateMultiWaliDashboardWarmup extends Command
{
    private const WALI_PREFIX = 'Wali Load Test';

    private const CLASS_PREFIX = 'Kelas Load Test';

    protected $signature = 'staging:simulate-multi-wali-dashboard-warmup
        {--wali=20 : Number of dummy wali dashboards to simulate}
        {--dry-run : Preview dashboard warm-up scheduling without dispatching jobs}
        {--ignore-cooldown : Ignore dashboard warm-up cooldown for repeated explicit load testing}';

    protected $description = 'Simulate staging-only multi-wali dashboard PDF warm-up scheduling for dummy load-test classes.';

    public function handle(ReportPdfAutoPrepareService $warmup, SiswaKelasSemesterResolver $resolver): int
    {
        if (! $this->isAllowedEnvironment()) {
            $this->error('Command ini hanya boleh dijalankan di local, testing, staging, atau saat STAGING_TEST_TOOLS_ENABLED=true.');

            return self::FAILURE;
        }

        $tahunAjaran = TahunAjaran::query()
            ->where('is_active', true)
            ->first();

        if (! $tahunAjaran) {
            $this->error('Tidak ada tahun ajaran aktif. Aktifkan tahun ajaran terlebih dahulu sebelum simulasi warm-up.');

            return self::FAILURE;
        }

        $semester = (int) $tahunAjaran->semester;
        if (! in_array($semester, [1, 2], true)) {
            $this->error("Semester aktif tidak valid: {$semester}. Command hanya mendukung semester 1/UTS atau 2/UAS.");

            return self::FAILURE;
        }

        $reportType = $semester === 1 ? 'UTS' : 'UAS';
        $waliLimit = max(1, (int) $this->option('wali'));
        $dryRun = (bool) $this->option('dry-run');
        $ignoreCooldown = (bool) $this->option('ignore-cooldown');

        if (! config('report.pdf_auto_prepare.enabled')) {
            $this->warn('WARNING: REPORT_PDF_AUTO_PREPARE_ENABLED belum aktif; tidak ada job yang akan dijadwalkan.');
        }

        if (! config('report.pdf_dashboard_warmup.enabled')) {
            $this->warn('WARNING: REPORT_PDF_DASHBOARD_WARMUP_ENABLED belum aktif; tidak ada job dashboard warm-up yang akan dijadwalkan.');
        }

        $waliRows = $this->dummyWaliRows($waliLimit);

        if ($waliRows->isEmpty()) {
            $this->warn('Tidak ada wali dummy load test ditemukan. Jalankan staging:create-multi-wali-load-data terlebih dahulu.');
            $this->displayPendingJobs();

            return self::SUCCESS;
        }

        $totals = [
            'wali_processed' => 0,
            'students_considered' => 0,
            'jobs_scheduled' => 0,
            'skipped_cached' => 0,
            'skipped_cooldown' => 0,
            'skipped_unavailable' => 0,
            'skipped_other' => 0,
        ];

        $rows = [];

        foreach ($waliRows as $waliRow) {
            $guru = Guru::query()->find($waliRow->id);

            if (! $guru || ! $this->isDummyText($guru->nama)) {
                continue;
            }

            $classIds = $this->dummyClassIdsForWali($guru, $tahunAjaran);
            $analysis = $this->analyzeWali($classIds, $tahunAjaran, $semester, $reportType, $resolver, $warmup);

            if (! $dryRun) {
                $scheduled = $warmup->scheduleDashboardWarmupForWali($guru, $tahunAjaran, $semester, $ignoreCooldown, $classIds);
            } else {
                $scheduled = [
                    'classes' => $analysis['classes'],
                    'students' => $analysis['students'],
                    'scheduled' => 0,
                    'cached' => $analysis['cached'],
                    'cooldown' => 0,
                    'cooldown_students' => 0,
                    'skipped' => 0,
                ];
            }

            $totals['wali_processed']++;
            $totals['students_considered'] += $analysis['students'];
            $totals['jobs_scheduled'] += (int) ($scheduled['scheduled'] ?? 0);
            $totals['skipped_cached'] += $analysis['cached'];
            $totals['skipped_cooldown'] += (int) ($scheduled['cooldown_students'] ?? 0);
            $totals['skipped_unavailable'] += $analysis['unavailable'];

            $otherSkipped = max(
                0,
                (int) ($scheduled['skipped'] ?? 0)
                - (int) ($scheduled['cooldown_students'] ?? 0)
                - $analysis['unavailable']
            );
            $totals['skipped_other'] += $otherSkipped;

            $rows[] = [
                $guru->nama,
                $analysis['classes'],
                $analysis['students'],
                (int) ($scheduled['scheduled'] ?? 0),
                $analysis['cached'],
                (int) ($scheduled['cooldown_students'] ?? 0),
                $analysis['unavailable'],
            ];
        }

        $this->info(($dryRun ? 'DRY RUN: ' : '')."Simulasi dashboard warm-up {$reportType} untuk {$tahunAjaran->tahun_ajaran} semester {$semester} selesai.");
        $this->table(
            ['Wali', 'Kelas', 'Siswa', 'Jobs', 'Cached', 'Cooldown', 'Unavailable'],
            $rows
        );
        $this->table(
            ['Metric', 'Count'],
            [
                ['wali processed', $totals['wali_processed']],
                ['students considered', $totals['students_considered']],
                ['jobs scheduled', $totals['jobs_scheduled']],
                ['skipped cached', $totals['skipped_cached']],
                ['skipped cooldown', $totals['skipped_cooldown']],
                ['skipped unavailable', $totals['skipped_unavailable']],
                ['skipped other', $totals['skipped_other']],
            ]
        );

        $this->displayPendingJobs();

        if ($dryRun) {
            $this->line('Dry-run tidak menulis cache cooldown dan tidak enqueue job.');
        }

        return self::SUCCESS;
    }

    private function isAllowedEnvironment(): bool
    {
        $environment = (string) config('app.env');

        return in_array($environment, ['local', 'testing', 'staging'], true)
            || (bool) config('staging_test_tools.enabled');
    }

    private function dummyWaliRows(int $limit)
    {
        return DB::table('gurus')
            ->where('nama', 'like', self::WALI_PREFIX.'%')
            ->orderBy('nama')
            ->limit($limit)
            ->get(['id', 'nama']);
    }

    /**
     * @return array<int, int>
     */
    private function dummyClassIdsForWali(Guru $guru, TahunAjaran $tahunAjaran): array
    {
        return DB::table('guru_kelas')
            ->join('kelas', 'guru_kelas.kelas_id', '=', 'kelas.id')
            ->where('guru_kelas.guru_id', $guru->id)
            ->where('guru_kelas.is_wali_kelas', true)
            ->where('guru_kelas.role', 'wali_kelas')
            ->where('kelas.tahun_ajaran_id', $tahunAjaran->id)
            ->where('kelas.nama_kelas', 'like', self::CLASS_PREFIX.'%')
            ->whereNull('kelas.deleted_at')
            ->pluck('kelas.id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /**
     * @return array{classes: int, students: int, cached: int, unavailable: int}
     */
    private function analyzeWali(
        array $classIds,
        TahunAjaran $tahunAjaran,
        int $semester,
        string $reportType,
        SiswaKelasSemesterResolver $resolver,
        ReportPdfAutoPrepareService $warmup
    ): array {
        $summary = [
            'classes' => 0,
            'students' => 0,
            'cached' => 0,
            'unavailable' => 0,
        ];

        $classes = $classIds === []
            ? collect()
            : DB::table('kelas')
                ->whereIn('id', $classIds)
                ->where('tahun_ajaran_id', $tahunAjaran->id)
                ->where('nama_kelas', 'like', self::CLASS_PREFIX.'%')
                ->whereNull('deleted_at')
                ->select('id', 'nama_kelas')
                ->get();

        $summary['classes'] = $classes->count();

        foreach ($classes as $class) {
            if (! $this->isDummyText($class->nama_kelas)) {
                continue;
            }

            $students = $resolver->studentsForClass((int) $class->id, (int) $tahunAjaran->id, $semester, true);

            foreach ($students as $student) {
                $summary['students']++;

                if (! $student instanceof Siswa || ! $this->isDummyText($student->nama)) {
                    $summary['unavailable']++;

                    continue;
                }

                if (PdfCacheService::getPdfPreparationStatus(
                    $student,
                    $reportType,
                    (int) $tahunAjaran->id,
                    (int) $tahunAjaran->semester
                ) === 'ready') {
                    $summary['cached']++;

                    continue;
                }

                if ($warmup->unavailableReason($student, $reportType, (int) $tahunAjaran->id)) {
                    $summary['unavailable']++;
                }
            }
        }

        return $summary;
    }

    private function displayPendingJobs(): void
    {
        if (! Schema::hasTable('jobs')) {
            $this->line('Pending jobs by queue: jobs table unavailable for this queue driver/schema.');

            return;
        }

        $rows = DB::table('jobs')
            ->select('queue', DB::raw('COUNT(*) as pending'))
            ->groupBy('queue')
            ->orderBy('queue')
            ->get()
            ->map(fn ($row) => [(string) $row->queue, (int) $row->pending])
            ->all();

        if ($rows === []) {
            $rows = [['(none)', 0]];
        }

        $this->table(['Queue', 'Pending Jobs'], $rows);
    }

    private function isDummyText(?string $value): bool
    {
        $value = mb_strtolower((string) $value, 'UTF-8');

        if ($value === '') {
            return false;
        }

        foreach ((array) config('staging_test_tools.dummy_markers', ['dummy', 'test', 'simulasi']) as $marker) {
            if ($marker !== '' && str_contains($value, mb_strtolower((string) $marker, 'UTF-8'))) {
                return true;
            }
        }

        return str_contains($value, 'load test');
    }
}

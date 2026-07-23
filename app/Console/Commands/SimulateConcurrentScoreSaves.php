<?php

namespace App\Console\Commands;

use App\Http\Controllers\ScoreController;
use App\Models\Guru;
use App\Models\TahunAjaran;
use Illuminate\Console\Command;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Symfony\Component\Process\Process;
use Throwable;

class SimulateConcurrentScoreSaves extends Command
{
    private const TEACHER_PREFIX = 'Wali Load Test';

    private const CLASS_PREFIX = 'Kelas Load Test';

    private const STUDENT_PREFIX = 'Siswa Load Test';

    private const SUBJECT_PREFIX = 'Load Test';

    protected $signature = 'staging:simulate-concurrent-score-saves
        {--teachers=20 : Number of dummy teachers to simulate}
        {--students=20 : Number of dummy students per teacher/subject}
        {--subject-limit=1 : Number of dummy subjects per teacher}
        {--changed-values=1 : Number of raw score inputs to change per student}
        {--dry-run : Preview concurrent score saves without writing data}
        {--ignore-pdf-warmup : Disable PDF auto-prepare scheduling for this test run only}
        {--run-teacher : Internal option used by the parent command to execute one teacher}
        {--teacher-id= : Internal teacher id used with --run-teacher}';

    protected $description = 'Staging-only concurrent score save load test for dummy load-test teachers/classes/students.';

    public function handle(): int
    {
        if (! $this->isAllowedEnvironment()) {
            $this->error('Command ini hanya boleh dijalankan di local, testing, staging, atau saat STAGING_TEST_TOOLS_ENABLED=true.');

            return self::FAILURE;
        }

        if ((bool) $this->option('run-teacher')) {
            return $this->handleSingleTeacherProcess();
        }

        $tahunAjaran = $this->activeTahunAjaran();
        if (! $tahunAjaran) {
            $this->error('Tidak ada tahun ajaran aktif. Aktifkan tahun ajaran terlebih dahulu sebelum simulasi score save.');

            return self::FAILURE;
        }

        $options = $this->normalizedOptions();
        $plans = $this->discoverTeacherPlans($tahunAjaran, $options);
        $summary = $this->summarizePlans($plans);
        $dryRun = (bool) $this->option('dry-run');

        Log::info('staging.concurrent_score_save_started', [
            'dry_run' => $dryRun,
            'teachers_requested' => $options['teachers'],
            'teachers_found' => count($plans),
            'students_limit' => $options['students'],
            'subject_limit' => $options['subject_limit'],
            'changed_values' => $options['changed_values'],
            'ignore_pdf_warmup' => $options['ignore_pdf_warmup'],
            'tahun_ajaran_id' => $tahunAjaran->id,
            'semester' => $tahunAjaran->semester,
        ]);

        if ($plans === []) {
            $this->warn('Tidak ada guru dummy load-test dengan kelas, mapel, siswa, dan TP lengkap yang ditemukan.');
            $this->line('Jalankan staging:create-multi-wali-load-data terlebih dahulu, lalu ulangi command ini.');
            $this->displayPendingJobs();

            Log::info('staging.concurrent_score_save_completed', [
                'dry_run' => $dryRun,
                'success_count' => 0,
                'failure_count' => 0,
                'elapsed_seconds' => 0,
            ]);

            return self::SUCCESS;
        }

        if ($dryRun) {
            $this->displayDryRun($tahunAjaran, $plans, $summary, $options);

            Log::info('staging.concurrent_score_save_completed', [
                'dry_run' => true,
                'success_count' => 0,
                'failure_count' => 0,
                'elapsed_seconds' => 0,
                'teachers_found' => count($plans),
                'students_affected' => $summary['students'],
                'subjects_affected' => $summary['subjects'],
                'nilai_rows_changed' => $summary['nilai_rows_changed'],
            ]);

            return self::SUCCESS;
        }

        $jobsBefore = $this->pendingJobsByQueue();
        $failedJobsBefore = $this->failedJobsCount();
        $startedAt = microtime(true);
        $results = $this->runTeacherProcesses($plans, $options);
        $elapsedSeconds = round(microtime(true) - $startedAt, 3);
        $jobsAfter = $this->pendingJobsByQueue();
        $failedJobsAfter = $this->failedJobsCount();

        $successCount = collect($results)->where('success', true)->count();
        $failureCount = count($results) - $successCount;
        $dbLockFailures = collect($results)
            ->filter(fn (array $result) => $this->looksLikeDatabaseLock((string) ($result['message'] ?? '')))
            ->count();

        $this->displayActualRun($results, $summary, $jobsBefore, $jobsAfter, $failedJobsBefore, $failedJobsAfter, $elapsedSeconds, $dbLockFailures);

        Log::info('staging.concurrent_score_save_completed', [
            'dry_run' => false,
            'success_count' => $successCount,
            'failure_count' => $failureCount,
            'db_lock_failures' => $dbLockFailures,
            'elapsed_seconds' => $elapsedSeconds,
            'jobs_before' => array_sum($jobsBefore),
            'jobs_after' => array_sum($jobsAfter),
            'failed_jobs_before' => $failedJobsBefore,
            'failed_jobs_after' => $failedJobsAfter,
        ]);

        return $failureCount === 0 ? self::SUCCESS : self::FAILURE;
    }

    private function handleSingleTeacherProcess(): int
    {
        $teacherId = (int) $this->option('teacher-id');
        if ($teacherId <= 0) {
            $this->writeJsonResult([
                'success' => false,
                'teacher_id' => null,
                'teacher' => null,
                'message' => '--teacher-id wajib diisi untuk --run-teacher.',
            ]);

            return self::FAILURE;
        }

        $tahunAjaran = $this->activeTahunAjaran();
        if (! $tahunAjaran) {
            $this->writeJsonResult([
                'success' => false,
                'teacher_id' => $teacherId,
                'teacher' => null,
                'message' => 'Tidak ada tahun ajaran aktif.',
            ]);

            return self::FAILURE;
        }

        if ((bool) $this->option('ignore-pdf-warmup')) {
            config(['report.pdf_auto_prepare.enabled' => false]);
        }

        $options = $this->normalizedOptions();
        $options['teachers'] = 1;
        $plans = $this->discoverTeacherPlans($tahunAjaran, $options, $teacherId);

        if ($plans === []) {
            $this->writeJsonResult([
                'success' => false,
                'teacher_id' => $teacherId,
                'teacher' => null,
                'message' => 'Guru dummy tidak ditemukan atau tidak memiliki plan score-save yang aman.',
            ]);

            return self::FAILURE;
        }

        $plan = $plans[0];
        $startedAt = microtime(true);
        $jobsBefore = $this->pendingJobsTotal();
        $failedJobsBefore = $this->failedJobsCount();

        try {
            $savedSubjects = $this->saveScoresForTeacherPlan($plan, $tahunAjaran, $options);
            $elapsedMs = (int) round((microtime(true) - $startedAt) * 1000);

            $this->writeJsonResult([
                'success' => true,
                'teacher_id' => $plan['teacher_id'],
                'teacher' => $plan['teacher_name'],
                'classes' => count($plan['class_ids']),
                'subjects' => count($plan['subjects']),
                'students' => $plan['students'],
                'nilai_rows_changed' => $plan['nilai_rows_changed'],
                'saved_subjects' => $savedSubjects,
                'elapsed_ms' => $elapsedMs,
                'jobs_created' => max(0, $this->pendingJobsTotal() - $jobsBefore),
                'failed_jobs_created' => max(0, $this->failedJobsCount() - $failedJobsBefore),
                'message' => 'ok',
            ]);

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $elapsedMs = (int) round((microtime(true) - $startedAt) * 1000);

            $this->writeJsonResult([
                'success' => false,
                'teacher_id' => $plan['teacher_id'],
                'teacher' => $plan['teacher_name'],
                'classes' => count($plan['class_ids']),
                'subjects' => count($plan['subjects']),
                'students' => $plan['students'],
                'nilai_rows_changed' => $plan['nilai_rows_changed'],
                'elapsed_ms' => $elapsedMs,
                'jobs_created' => max(0, $this->pendingJobsTotal() - $jobsBefore),
                'failed_jobs_created' => max(0, $this->failedJobsCount() - $failedJobsBefore),
                'message' => $exception->getMessage(),
            ]);

            return self::FAILURE;
        }
    }

    private function isAllowedEnvironment(): bool
    {
        $environment = (string) config('app.env');

        return in_array($environment, ['local', 'testing', 'staging'], true)
            || (bool) config('staging_test_tools.enabled');
    }

    private function activeTahunAjaran(): ?TahunAjaran
    {
        return TahunAjaran::query()
            ->where('is_active', true)
            ->first();
    }

    /**
     * @return array{teachers: int, students: int, subject_limit: int, changed_values: int, ignore_pdf_warmup: bool}
     */
    private function normalizedOptions(): array
    {
        return [
            'teachers' => max(1, (int) $this->option('teachers')),
            'students' => max(1, (int) $this->option('students')),
            'subject_limit' => max(1, (int) $this->option('subject-limit')),
            'changed_values' => max(1, (int) $this->option('changed-values')),
            'ignore_pdf_warmup' => (bool) $this->option('ignore-pdf-warmup'),
        ];
    }

    /**
     * @param array<string, mixed> $options
     * @return array<int, array<string, mixed>>
     */
    private function discoverTeacherPlans(TahunAjaran $tahunAjaran, array $options, ?int $onlyTeacherId = null): array
    {
        $semester = (int) $tahunAjaran->semester;

        $teacherQuery = DB::table('gurus')
            ->where('nama', 'like', self::TEACHER_PREFIX.'%')
            ->whereNull('deleted_at')
            ->orderBy('nama')
            ->select('id', 'nama');

        if ($onlyTeacherId) {
            $teacherQuery->where('id', $onlyTeacherId);
        } else {
            $teacherQuery->limit((int) $options['teachers']);
        }

        $plans = [];

        foreach ($teacherQuery->get() as $teacher) {
            if (! $this->isDummyText($teacher->nama)) {
                continue;
            }

            $subjects = $this->dummySubjectsForTeacher(
                (int) $teacher->id,
                (int) $tahunAjaran->id,
                $semester,
                (int) $options['subject_limit']
            );

            $planSubjects = [];
            $classIds = [];
            $totalStudents = 0;
            $totalNilaiRowsChanged = 0;

            foreach ($subjects as $subject) {
                $students = $this->dummyStudentsForClass(
                    (int) $subject->kelas_id,
                    (int) $tahunAjaran->id,
                    $semester,
                    (int) $options['students']
                );
                $learning = $this->learningStructure((int) $subject->id);

                if ($students === [] || $learning === []) {
                    continue;
                }

                $payload = $this->buildScorePayload(
                    (int) $subject->id,
                    $students,
                    $learning,
                    (int) $tahunAjaran->id,
                    (int) $options['changed_values']
                );

                $classIds[] = (int) $subject->kelas_id;
                $totalStudents += count($students);
                $totalNilaiRowsChanged += $payload['nilai_rows_changed'];

                $planSubjects[] = [
                    'id' => (int) $subject->id,
                    'name' => (string) $subject->nama_pelajaran,
                    'class_id' => (int) $subject->kelas_id,
                    'class_name' => (string) $subject->nama_kelas,
                    'students' => $students,
                    'learning' => $learning,
                    'nilai_rows_changed' => $payload['nilai_rows_changed'],
                    'payload' => $payload['scores'],
                ];
            }

            if ($planSubjects === []) {
                continue;
            }

            $plans[] = [
                'teacher_id' => (int) $teacher->id,
                'teacher_name' => (string) $teacher->nama,
                'class_ids' => array_values(array_unique($classIds)),
                'subjects' => $planSubjects,
                'students' => $totalStudents,
                'nilai_rows_changed' => $totalNilaiRowsChanged,
            ];
        }

        return $plans;
    }

    private function dummySubjectsForTeacher(int $teacherId, int $tahunAjaranId, int $semester, int $limit)
    {
        return DB::table('mata_pelajarans as mp')
            ->join('kelas as k', 'mp.kelas_id', '=', 'k.id')
            ->join('guru_kelas as gk', function ($join) use ($teacherId) {
                $join->on('gk.kelas_id', '=', 'k.id')
                    ->where('gk.guru_id', '=', $teacherId)
                    ->where('gk.role', '=', 'pengajar')
                    ->where('gk.is_wali_kelas', '=', false);
            })
            ->where('mp.guru_id', $teacherId)
            ->where('mp.tahun_ajaran_id', $tahunAjaranId)
            ->where('mp.semester', $semester)
            ->where('mp.nama_pelajaran', 'like', self::SUBJECT_PREFIX.'%')
            ->where('k.tahun_ajaran_id', $tahunAjaranId)
            ->where('k.nama_kelas', 'like', self::CLASS_PREFIX.'%')
            ->whereNull('mp.deleted_at')
            ->whereNull('k.deleted_at')
            ->orderBy('k.nama_kelas')
            ->orderBy('mp.nama_pelajaran')
            ->limit($limit)
            ->get([
                'mp.id',
                'mp.nama_pelajaran',
                'mp.kelas_id',
                'k.nama_kelas',
            ]);
    }

    /**
     * @return array<int, array{id: int, name: string}>
     */
    private function dummyStudentsForClass(int $classId, int $tahunAjaranId, int $semester, int $limit): array
    {
        return DB::table('siswa_kelas_semester as sks')
            ->join('siswas as s', 'sks.siswa_id', '=', 's.id')
            ->where('sks.kelas_id', $classId)
            ->where('sks.tahun_ajaran_id', $tahunAjaranId)
            ->where('sks.semester', $semester)
            ->where('s.nama', 'like', self::STUDENT_PREFIX.'%')
            ->whereNull('s.deleted_at')
            ->orderBy('s.nama')
            ->limit($limit)
            ->get(['s.id', 's.nama'])
            ->map(fn ($student) => [
                'id' => (int) $student->id,
                'name' => (string) $student->nama,
            ])
            ->all();
    }

    /**
     * @return array<int, array{id: int, tp: array<int, int>}>
     */
    private function learningStructure(int $subjectId): array
    {
        $structure = [];

        $lingkupMateris = DB::table('lingkup_materis')
            ->where('mata_pelajaran_id', $subjectId)
            ->whereNull('deleted_at')
            ->orderBy('id')
            ->get(['id']);

        foreach ($lingkupMateris as $lm) {
            $tpIds = DB::table('tujuan_pembelajarans')
                ->where('lingkup_materi_id', $lm->id)
                ->whereNull('deleted_at')
                ->orderBy('id')
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->all();

            if ($tpIds === []) {
                return [];
            }

            $structure[] = [
                'id' => (int) $lm->id,
                'tp' => $tpIds,
            ];
        }

        return $structure;
    }

    /**
     * @param array<int, array{id: int, name: string}> $students
     * @param array<int, array{id: int, tp: array<int, int>}> $learning
     * @return array{scores: array<int, array<string, mixed>>, nilai_rows_changed: int}
     */
    private function buildScorePayload(int $subjectId, array $students, array $learning, int $tahunAjaranId, int $changedValues): array
    {
        $scores = [];
        $nilaiRowsChanged = 0;

        foreach ($students as $student) {
            $studentId = (int) $student['id'];
            $remainingChanges = $changedValues;
            $studentScores = [
                'tp' => [],
                'lm' => [],
                'nilai_tes' => null,
                'nilai_non_tes' => null,
            ];
            $allTpScores = [];
            $lmScores = [];

            foreach ($learning as $lm) {
                $lmId = (int) $lm['id'];
                $tpScoresForLm = [];

                foreach ($lm['tp'] as $tpId) {
                    $current = $this->currentScoreValue($studentId, $subjectId, $tahunAjaranId, $lmId, (int) $tpId, 'nilai_tp');
                    $newValue = $current ?? $this->deterministicScore($studentId, $subjectId, (int) $tpId, 78, 94);

                    if ($remainingChanges > 0) {
                        $newValue = $this->shiftScore($newValue);
                        $remainingChanges--;
                    }

                    $studentScores['tp'][$lmId][(int) $tpId] = $newValue;
                    $tpScoresForLm[] = $newValue;
                    $allTpScores[] = $newValue;

                    if (! $this->floatEquals($current, $newValue)) {
                        $nilaiRowsChanged++;
                    }
                }

                $lmValue = $this->averageScore($tpScoresForLm);
                $currentLm = $this->currentScoreValue($studentId, $subjectId, $tahunAjaranId, $lmId, null, 'nilai_lm');
                $studentScores['lm'][$lmId] = $lmValue;
                $lmScores[] = $lmValue;

                if (! $this->floatEquals($currentLm, $lmValue)) {
                    $nilaiRowsChanged++;
                }
            }

            $nilaiTes = $this->currentScoreValue($studentId, $subjectId, $tahunAjaranId, null, null, 'nilai_tes')
                ?? $this->deterministicScore($studentId, $subjectId, 701, 80, 92);
            $nilaiNonTes = $this->currentScoreValue($studentId, $subjectId, $tahunAjaranId, null, null, 'nilai_non_tes')
                ?? $this->deterministicScore($studentId, $subjectId, 907, 80, 92);

            if ($remainingChanges > 0) {
                $newNilaiTes = $this->shiftScore($nilaiTes);
                if (! $this->floatEquals($nilaiTes, $newNilaiTes)) {
                    $nilaiTes = $newNilaiTes;
                }
                $remainingChanges--;
            }

            if ($remainingChanges > 0) {
                $newNilaiNonTes = $this->shiftScore($nilaiNonTes);
                if (! $this->floatEquals($nilaiNonTes, $newNilaiNonTes)) {
                    $nilaiNonTes = $newNilaiNonTes;
                }
            }

            $studentScores['nilai_tes'] = $nilaiTes;
            $studentScores['nilai_non_tes'] = $nilaiNonTes;

            if ($this->aggregateRowWouldChange($studentId, $subjectId, $tahunAjaranId, $allTpScores, $lmScores, $nilaiTes, $nilaiNonTes)) {
                $nilaiRowsChanged++;
            }

            $scores[$studentId] = $studentScores;
        }

        return [
            'scores' => $scores,
            'nilai_rows_changed' => $nilaiRowsChanged,
        ];
    }

    private function currentScoreValue(int $studentId, int $subjectId, int $tahunAjaranId, ?int $lmId, ?int $tpId, string $column): ?float
    {
        $row = DB::table('nilais')
            ->where('siswa_id', $studentId)
            ->where('mata_pelajaran_id', $subjectId)
            ->where('tahun_ajaran_id', $tahunAjaranId)
            ->when($lmId === null, fn ($query) => $query->whereNull('lingkup_materi_id'), fn ($query) => $query->where('lingkup_materi_id', $lmId))
            ->when($tpId === null, fn ($query) => $query->whereNull('tujuan_pembelajaran_id'), fn ($query) => $query->where('tujuan_pembelajaran_id', $tpId))
            ->whereNull('deleted_at')
            ->first([$column]);

        if (! $row || $row->{$column} === null) {
            return null;
        }

        return (float) $row->{$column};
    }

    /**
     * @param array<int, float|int> $tpScores
     * @param array<int, float|int> $lmScores
     */
    private function aggregateRowWouldChange(
        int $studentId,
        int $subjectId,
        int $tahunAjaranId,
        array $tpScores,
        array $lmScores,
        float|int $nilaiTes,
        float|int $nilaiNonTes
    ): bool {
        $current = DB::table('nilais')
            ->where('siswa_id', $studentId)
            ->where('mata_pelajaran_id', $subjectId)
            ->where('tahun_ajaran_id', $tahunAjaranId)
            ->whereNull('lingkup_materi_id')
            ->whereNull('tujuan_pembelajaran_id')
            ->whereNull('deleted_at')
            ->first([
                'na_tp',
                'na_lm',
                'nilai_tes',
                'nilai_non_tes',
                'nilai_akhir_semester',
                'nilai_akhir_rapor',
                'is_submitted',
            ]);

        $target = $this->expectedAggregateScores($tpScores, $lmScores, $nilaiTes, $nilaiNonTes, $tahunAjaranId);

        if (! $current) {
            return true;
        }

        return ! $this->floatEquals($current->na_tp !== null ? (float) $current->na_tp : null, $target['na_tp'])
            || ! $this->floatEquals($current->na_lm !== null ? (float) $current->na_lm : null, $target['na_lm'])
            || ! $this->floatEquals($current->nilai_tes !== null ? (float) $current->nilai_tes : null, $target['nilai_tes'])
            || ! $this->floatEquals($current->nilai_non_tes !== null ? (float) $current->nilai_non_tes : null, $target['nilai_non_tes'])
            || ! $this->floatEquals($current->nilai_akhir_semester !== null ? (float) $current->nilai_akhir_semester : null, $target['nilai_akhir_semester'])
            || ! $this->floatEquals($current->nilai_akhir_rapor !== null ? (float) $current->nilai_akhir_rapor : null, $target['nilai_akhir_rapor'])
            || (bool) $current->is_submitted !== true;
    }

    /**
     * @param array<int, float|int> $tpScores
     * @param array<int, float|int> $lmScores
     * @return array<string, float|int|bool>
     */
    private function expectedAggregateScores(array $tpScores, array $lmScores, float|int $nilaiTes, float|int $nilaiNonTes, int $tahunAjaranId): array
    {
        $bobot = DB::table('bobot_nilais')
            ->where('tahun_ajaran_id', $tahunAjaranId)
            ->first(['bobot_tp', 'bobot_lm', 'bobot_as']);

        $bobotTp = (int) ($bobot->bobot_tp ?? 1);
        $bobotLm = (int) ($bobot->bobot_lm ?? 1);
        $bobotAs = (int) ($bobot->bobot_as ?? 2);
        $totalBobot = $bobotTp + $bobotLm + $bobotAs;
        $naTp = $this->averageScore($tpScores);
        $naLm = $this->averageScore($lmScores);
        $nilaiAkhirSemester = round(((float) $nilaiTes + (float) $nilaiNonTes) / 2, 2);
        $nilaiAkhirRapor = $totalBobot > 0
            ? round((($naTp * $bobotTp) + ($naLm * $bobotLm) + ($nilaiAkhirSemester * $bobotAs)) / $totalBobot)
            : 0;

        return [
            'na_tp' => $naTp,
            'na_lm' => $naLm,
            'nilai_tes' => (float) $nilaiTes,
            'nilai_non_tes' => (float) $nilaiNonTes,
            'nilai_akhir_semester' => $nilaiAkhirSemester,
            'nilai_akhir_rapor' => $nilaiAkhirRapor,
            'is_submitted' => true,
        ];
    }

    private function deterministicScore(int $studentId, int $subjectId, int $salt, int $min, int $max): int
    {
        return $min + (($studentId + $subjectId + $salt) % ($max - $min + 1));
    }

    private function shiftScore(float|int $score): int
    {
        $score = (int) round((float) $score);

        return $score >= 95 ? max(70, $score - 1) : min(95, $score + 1);
    }

    /**
     * @param array<int, float|int> $scores
     */
    private function averageScore(array $scores): float
    {
        if ($scores === []) {
            return 0.0;
        }

        return round(array_sum(array_map('floatval', $scores)) / count($scores), 2);
    }

    private function floatEquals(?float $current, float|int|null $target): bool
    {
        if ($current === null || $target === null) {
            return $current === null && $target === null;
        }

        return abs($current - (float) $target) < 0.01;
    }

    /**
     * @param array<string, mixed> $plan
     * @param array<string, mixed> $options
     */
    private function saveScoresForTeacherPlan(array $plan, TahunAjaran $tahunAjaran, array $options): int
    {
        $guru = Guru::query()->findOrFail((int) $plan['teacher_id']);

        if (! $this->isDummyText($guru->nama)) {
            throw new RuntimeException('Guru bukan data dummy load-test.');
        }

        Auth::guard('guru')->setUser($guru);

        $session = app('session.store');
        if (method_exists($session, 'start') && ! $session->isStarted()) {
            $session->start();
        }

        $session->put([
            'selected_role' => 'pengajar',
            'tahun_ajaran_id' => (int) $tahunAjaran->id,
            'selected_semester' => (int) $tahunAjaran->semester,
            'no_tahun_ajaran' => false,
        ]);

        $savedSubjects = 0;

        foreach ($plan['subjects'] as $subject) {
            $this->assertSubjectPlanIsDummy($plan, $subject, $tahunAjaran);

            $request = Request::create(
                '/pengajar/score/'.(int) $subject['id'].'/save',
                'POST',
                ['scores' => $subject['payload']]
            );
            $request->headers->set('Accept', 'application/json');
            $request->setLaravelSession($session);
            app()->instance('request', $request);

            $response = app(ScoreController::class)->saveScore($request, (int) $subject['id']);
            $status = method_exists($response, 'getStatusCode') ? $response->getStatusCode() : 500;
            $payload = method_exists($response, 'getContent')
                ? json_decode((string) $response->getContent(), true)
                : null;

            if ($status >= 400 || ! is_array($payload) || ($payload['success'] ?? false) !== true) {
                throw new RuntimeException("Score save gagal untuk {$subject['name']} (HTTP {$status}).");
            }

            $savedSubjects++;
        }

        return $savedSubjects;
    }

    /**
     * @param array<string, mixed> $plan
     * @param array<string, mixed> $subject
     */
    private function assertSubjectPlanIsDummy(array $plan, array $subject, TahunAjaran $tahunAjaran): void
    {
        $subjectRow = DB::table('mata_pelajarans as mp')
            ->join('kelas as k', 'mp.kelas_id', '=', 'k.id')
            ->where('mp.id', (int) $subject['id'])
            ->where('mp.guru_id', (int) $plan['teacher_id'])
            ->where('mp.tahun_ajaran_id', (int) $tahunAjaran->id)
            ->where('mp.semester', (int) $tahunAjaran->semester)
            ->where('mp.nama_pelajaran', 'like', self::SUBJECT_PREFIX.'%')
            ->where('k.nama_kelas', 'like', self::CLASS_PREFIX.'%')
            ->whereNull('mp.deleted_at')
            ->whereNull('k.deleted_at')
            ->first(['mp.id']);

        if (! $subjectRow) {
            throw new RuntimeException('Subject plan tidak lagi aman/dummy.');
        }

        $studentIds = collect($subject['students'])->pluck('id')->map(fn ($id) => (int) $id)->all();
        $dummyStudentCount = DB::table('siswa_kelas_semester as sks')
            ->join('siswas as s', 'sks.siswa_id', '=', 's.id')
            ->where('sks.kelas_id', (int) $subject['class_id'])
            ->where('sks.tahun_ajaran_id', (int) $tahunAjaran->id)
            ->where('sks.semester', (int) $tahunAjaran->semester)
            ->whereIn('s.id', $studentIds)
            ->where('s.nama', 'like', self::STUDENT_PREFIX.'%')
            ->whereNull('s.deleted_at')
            ->count();

        if ($dummyStudentCount !== count($studentIds)) {
            throw new RuntimeException('Payload mengandung siswa non-dummy atau bukan roster kelas dummy.');
        }
    }

    /**
     * @param array<int, array<string, mixed>> $plans
     * @return array<int, array<string, mixed>>
     */
    private function runTeacherProcesses(array $plans, array $options): array
    {
        $processes = [];

        foreach ($plans as $plan) {
            $command = [
                PHP_BINARY,
                base_path('artisan'),
                'staging:simulate-concurrent-score-saves',
                '--run-teacher',
                '--teacher-id='.(int) $plan['teacher_id'],
                '--students='.(int) $options['students'],
                '--subject-limit='.(int) $options['subject_limit'],
                '--changed-values='.(int) $options['changed_values'],
                '--no-interaction',
            ];

            if ((bool) $options['ignore_pdf_warmup']) {
                $command[] = '--ignore-pdf-warmup';
            }

            $process = new Process($command, base_path(), null, null, 300);
            $process->start();

            $processes[] = [
                'plan' => $plan,
                'process' => $process,
            ];
        }

        while (collect($processes)->contains(fn (array $entry) => $entry['process']->isRunning())) {
            usleep(100000);
        }

        $results = [];

        foreach ($processes as $entry) {
            /** @var Process $process */
            $process = $entry['process'];
            $plan = $entry['plan'];
            $result = $this->parseProcessResult($process, $plan);
            $results[] = $result;

            if ($result['success']) {
                Log::info('staging.concurrent_score_save_teacher_completed', [
                    'teacher_id' => $result['teacher_id'],
                    'teacher' => $result['teacher'],
                    'elapsed_ms' => $result['elapsed_ms'],
                    'students' => $result['students'],
                    'subjects' => $result['subjects'],
                    'nilai_rows_changed' => $result['nilai_rows_changed'],
                    'jobs_created' => $result['jobs_created'] ?? null,
                ]);
            } else {
                Log::error('staging.concurrent_score_save_teacher_failed', [
                    'teacher_id' => $result['teacher_id'],
                    'teacher' => $result['teacher'],
                    'elapsed_ms' => $result['elapsed_ms'] ?? null,
                    'message' => $result['message'],
                    'exit_code' => $process->getExitCode(),
                ]);
            }
        }

        return $results;
    }

    /**
     * @param array<string, mixed> $plan
     * @return array<string, mixed>
     */
    private function parseProcessResult(Process $process, array $plan): array
    {
        $output = trim($process->getOutput());
        $errorOutput = trim($process->getErrorOutput());
        $lines = array_reverse(preg_split('/\R/', $output) ?: []);

        foreach ($lines as $line) {
            $decoded = json_decode($line, true);

            if (is_array($decoded) && array_key_exists('success', $decoded)) {
                return $decoded + [
                    'teacher_id' => $plan['teacher_id'],
                    'teacher' => $plan['teacher_name'],
                    'elapsed_ms' => null,
                    'students' => $plan['students'],
                    'subjects' => count($plan['subjects']),
                    'nilai_rows_changed' => $plan['nilai_rows_changed'],
                    'message' => 'ok',
                ];
            }
        }

        $message = $errorOutput !== '' ? $errorOutput : ($output !== '' ? $output : 'Process finished without JSON result.');

        return [
            'success' => false,
            'teacher_id' => $plan['teacher_id'],
            'teacher' => $plan['teacher_name'],
            'elapsed_ms' => null,
            'students' => $plan['students'],
            'subjects' => count($plan['subjects']),
            'nilai_rows_changed' => $plan['nilai_rows_changed'],
            'jobs_created' => null,
            'failed_jobs_created' => null,
            'message' => $this->excerpt($message, 500),
        ];
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function writeJsonResult(array $payload): void
    {
        $this->line(json_encode($payload, JSON_UNESCAPED_SLASHES));
    }

    /**
     * @param array<int, array<string, mixed>> $plans
     * @return array{teachers: int, classes: int, subjects: int, students: int, nilai_rows_changed: int}
     */
    private function summarizePlans(array $plans): array
    {
        return [
            'teachers' => count($plans),
            'classes' => collect($plans)->flatMap(fn (array $plan) => $plan['class_ids'])->unique()->count(),
            'subjects' => collect($plans)->sum(fn (array $plan) => count($plan['subjects'])),
            'students' => collect($plans)->sum(fn (array $plan) => (int) $plan['students']),
            'nilai_rows_changed' => collect($plans)->sum(fn (array $plan) => (int) $plan['nilai_rows_changed']),
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $plans
     * @param array<string, mixed> $summary
     * @param array<string, mixed> $options
     */
    private function displayDryRun(TahunAjaran $tahunAjaran, array $plans, array $summary, array $options): void
    {
        $this->info("DRY RUN: simulasi concurrent score save untuk {$tahunAjaran->tahun_ajaran} semester {$tahunAjaran->semester}.");
        $this->table(
            ['Metric', 'Count'],
            [
                ['teachers found', $summary['teachers']],
                ['classes found', $summary['classes']],
                ['students affected', $summary['students']],
                ['subjects affected', $summary['subjects']],
                ['nilai rows that would change/create', $summary['nilai_rows_changed']],
                ['PDF/DOCX invalidation would trigger', $summary['nilai_rows_changed'] > 0 ? 'yes' : 'no'],
                ['PDF auto-prepare scheduling', $options['ignore_pdf_warmup'] ? 'disabled by --ignore-pdf-warmup' : 'normal app config'],
            ]
        );

        $this->table(
            ['Teacher', 'Classes', 'Subjects', 'Students', 'Nilai Rows', 'Invalidation'],
            collect($plans)
                ->map(fn (array $plan) => [
                    $plan['teacher_name'],
                    count($plan['class_ids']),
                    count($plan['subjects']),
                    $plan['students'],
                    $plan['nilai_rows_changed'],
                    $plan['nilai_rows_changed'] > 0 ? 'yes' : 'no',
                ])
                ->all()
        );

        $this->line('Dry-run tidak menulis nilai, tidak membersihkan PDF/DOCX cache, dan tidak enqueue job.');
        $this->displayPendingJobs();
    }

    /**
     * @param array<int, array<string, mixed>> $results
     * @param array<string, mixed> $summary
     * @param array<string, int> $jobsBefore
     * @param array<string, int> $jobsAfter
     */
    private function displayActualRun(
        array $results,
        array $summary,
        array $jobsBefore,
        array $jobsAfter,
        int $failedJobsBefore,
        int $failedJobsAfter,
        float $elapsedSeconds,
        int $dbLockFailures
    ): void {
        $this->info("Concurrent score save simulation completed in {$elapsedSeconds} seconds.");
        $this->table(
            ['Teacher', 'Status', 'Elapsed ms', 'Subjects', 'Students', 'Nilai Rows', 'Jobs', 'Message'],
            collect($results)
                ->map(fn (array $result) => [
                    $result['teacher'] ?? 'unknown',
                    $result['success'] ? 'success' : 'failed',
                    $result['elapsed_ms'] ?? '-',
                    $result['subjects'] ?? 0,
                    $result['students'] ?? 0,
                    $result['nilai_rows_changed'] ?? 0,
                    $result['jobs_created'] ?? '-',
                    $this->excerpt((string) ($result['message'] ?? ''), 120),
                ])
                ->all()
        );

        $successCount = collect($results)->where('success', true)->count();
        $failureCount = count($results) - $successCount;

        $this->table(
            ['Metric', 'Count'],
            [
                ['teachers attempted', count($results)],
                ['success count', $successCount],
                ['failure count', $failureCount],
                ['DB deadlock/lock failures detected', $dbLockFailures],
                ['classes affected', $summary['classes']],
                ['subjects affected', $summary['subjects']],
                ['students affected', $summary['students']],
                ['nilai rows changed/created estimate', $summary['nilai_rows_changed']],
                ['jobs created after save', max(0, array_sum($jobsAfter) - array_sum($jobsBefore))],
                ['failed jobs after run', max(0, $failedJobsAfter - $failedJobsBefore)],
                ['total elapsed seconds', $elapsedSeconds],
            ]
        );

        $this->displayQueueDiff($jobsBefore, $jobsAfter);
    }

    /**
     * @param array<string, int> $before
     * @param array<string, int> $after
     */
    private function displayQueueDiff(array $before, array $after): void
    {
        $queues = collect(array_merge(array_keys($before), array_keys($after)))->unique()->sort()->values();

        if ($queues->isEmpty()) {
            $this->table(['Queue', 'Before', 'After', 'Delta'], [['(none)', 0, 0, 0]]);

            return;
        }

        $this->table(
            ['Queue', 'Before', 'After', 'Delta'],
            $queues->map(fn (string $queue) => [
                $queue,
                $before[$queue] ?? 0,
                $after[$queue] ?? 0,
                ($after[$queue] ?? 0) - ($before[$queue] ?? 0),
            ])->all()
        );
    }

    private function pendingJobsTotal(): int
    {
        return array_sum($this->pendingJobsByQueue());
    }

    /**
     * @return array<string, int>
     */
    private function pendingJobsByQueue(): array
    {
        if (! Schema::hasTable('jobs')) {
            return [];
        }

        return DB::table('jobs')
            ->select('queue', DB::raw('COUNT(*) as pending'))
            ->groupBy('queue')
            ->pluck('pending', 'queue')
            ->map(fn ($count) => (int) $count)
            ->all();
    }

    private function failedJobsCount(): int
    {
        return Schema::hasTable('failed_jobs') ? DB::table('failed_jobs')->count() : 0;
    }

    private function displayPendingJobs(): void
    {
        $jobs = $this->pendingJobsByQueue();

        if ($jobs === []) {
            $this->table(['Queue', 'Pending Jobs'], [['(none)', 0]]);

            return;
        }

        $this->table(
            ['Queue', 'Pending Jobs'],
            collect($jobs)->map(fn (int $count, string $queue) => [$queue, $count])->values()->all()
        );
    }

    private function looksLikeDatabaseLock(string $message): bool
    {
        $message = strtolower($message);

        foreach (['deadlock', 'lock wait', 'database is locked', 'database table is locked', '1205', '1213'] as $needle) {
            if (str_contains($message, $needle)) {
                return true;
            }
        }

        return false;
    }

    private function excerpt(string $text, int $limit): string
    {
        $text = trim(preg_replace('/\s+/', ' ', $text) ?? '');

        if (mb_strlen($text) <= $limit) {
            return $text;
        }

        return mb_substr($text, 0, $limit - 3).'...';
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

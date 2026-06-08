<?php

namespace App\Console\Commands;

use App\Http\Controllers\DashboardController;
use App\Models\TahunAjaran;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class GenerateInitialTestScores extends Command
{
    protected $signature = 'initial-data:generate-test-scores
        {--class-id= : Limit generation to one active-year class id}
        {--subject-id= : Limit generation to one active-year subject id}
        {--completion=full : full or partial completion}
        {--overwrite=false : Not supported unless generated test rows can be safely detected}
        {--force : Allow running outside local/testing/demo environments}';

    protected $description = 'Generate deterministic dummy nilai rows for enrolled test students in active-year subjects';

    public function handle(): int
    {
        if (! app()->environment(['local', 'testing', 'demo']) && ! $this->option('force')) {
            $this->error('Generator ini hanya boleh dijalankan di environment local, testing, atau demo kecuali menggunakan --force.');

            return self::FAILURE;
        }

        if ($this->booleanOption('overwrite')) {
            $this->error('Overwrite nilai belum didukung karena tabel nilais tidak memiliki penanda data generated yang aman.');

            return self::FAILURE;
        }

        $completion = strtolower((string) $this->option('completion'));
        if (! in_array($completion, ['full', 'partial'], true)) {
            $this->error('Opsi --completion harus bernilai full atau partial.');

            return self::FAILURE;
        }

        $tahunAjaran = TahunAjaran::where('is_active', true)->first();

        if (! $tahunAjaran) {
            $this->error('Tidak ada tahun ajaran aktif. Buat tahun ajaran aktif terlebih dahulu.');

            return self::FAILURE;
        }

        $subjects = $this->activeSubjects((int) $tahunAjaran->id, (int) $tahunAjaran->semester);

        if ($subjects->isEmpty()) {
            $this->error('Tidak ada mata pelajaran pada tahun ajaran dan semester aktif.');

            return self::FAILURE;
        }

        $stats = [
            'subjects_processed' => 0,
            'subjects_skipped_missing_lm_tp' => 0,
            'students_processed' => 0,
            'score_rows_created' => 0,
            'score_rows_reused' => 0,
        ];
        $processedClassIds = collect();

        DB::transaction(function () use ($subjects, $tahunAjaran, $completion, &$stats, $processedClassIds) {
            foreach ($subjects as $subject) {
                $learningData = $this->learningDataForSubject((int) $subject->id);

                if ($learningData->isEmpty()) {
                    $stats['subjects_skipped_missing_lm_tp']++;
                    $this->warn("Subject #{$subject->id} skipped: LM/TP belum lengkap.");

                    continue;
                }

                $students = $this->enrolledStudentsForSubject($subject, (int) $tahunAjaran->id, (int) $tahunAjaran->semester);

                if ($completion === 'partial') {
                    $students = $students->take(max(1, (int) floor($students->count() / 2)));
                }

                $stats['subjects_processed']++;
                $processedClassIds->push((int) $subject->kelas_id);

                foreach ($students as $student) {
                    $stats['students_processed']++;
                    $scores = $this->scoreSet(
                        (int) $student->id,
                        (int) $subject->id,
                        (int) $tahunAjaran->id,
                        $learningData
                    );

                    foreach ($scores['tp_rows'] as $row) {
                        $this->createScoreRow($row, $stats);
                    }

                    foreach ($scores['lm_rows'] as $row) {
                        $this->createScoreRow($row, $stats);
                    }

                    $this->createScoreRow($scores['aggregate_row'], $stats);
                }
            }
        });

        $this->clearProgressCaches($processedClassIds);

        $this->info("Nilai uji selesai disiapkan untuk {$tahunAjaran->tahun_ajaran} semester {$tahunAjaran->semester}.");
        $this->table(
            ['Metric', 'Count'],
            [
                ['Subjects processed', $stats['subjects_processed']],
                ['Subjects skipped missing LM/TP', $stats['subjects_skipped_missing_lm_tp']],
                ['Student-subjects processed', $stats['students_processed']],
                ['Score rows created', $stats['score_rows_created']],
                ['Score rows reused', $stats['score_rows_reused']],
            ]
        );
        $this->line('Data absensi, catatan, capaian, ekstrakurikuler, dan rapor tidak dibuat.');

        return self::SUCCESS;
    }

    private function booleanOption(string $option): bool
    {
        return filter_var($this->option($option), FILTER_VALIDATE_BOOLEAN);
    }

    private function activeSubjects(int $tahunAjaranId, int $semester): Collection
    {
        return DB::table('mata_pelajarans')
            ->join('kelas', 'mata_pelajarans.kelas_id', '=', 'kelas.id')
            ->select('mata_pelajarans.*')
            ->where('mata_pelajarans.tahun_ajaran_id', $tahunAjaranId)
            ->where('mata_pelajarans.semester', $semester)
            ->where('kelas.tahun_ajaran_id', $tahunAjaranId)
            ->when(Schema::hasColumn('mata_pelajarans', 'deleted_at'), fn ($query) => $query->whereNull('mata_pelajarans.deleted_at'))
            ->when(Schema::hasColumn('kelas', 'deleted_at'), fn ($query) => $query->whereNull('kelas.deleted_at'))
            ->when($this->option('class-id'), fn ($query, $classId) => $query->where('mata_pelajarans.kelas_id', (int) $classId))
            ->when($this->option('subject-id'), fn ($query, $subjectId) => $query->where('mata_pelajarans.id', (int) $subjectId))
            ->orderBy('mata_pelajarans.kelas_id')
            ->orderBy('mata_pelajarans.id')
            ->get();
    }

    private function learningDataForSubject(int $subjectId): Collection
    {
        $query = DB::table('lingkup_materis')
            ->join('tujuan_pembelajarans', 'lingkup_materis.id', '=', 'tujuan_pembelajarans.lingkup_materi_id')
            ->select(
                'lingkup_materis.id as lm_id',
                'tujuan_pembelajarans.id as tp_id',
                'tujuan_pembelajarans.kode_tp'
            )
            ->where('lingkup_materis.mata_pelajaran_id', $subjectId);

        if (Schema::hasColumn('lingkup_materis', 'deleted_at')) {
            $query->whereNull('lingkup_materis.deleted_at');
        }

        if (Schema::hasColumn('tujuan_pembelajarans', 'deleted_at')) {
            $query->whereNull('tujuan_pembelajarans.deleted_at');
        }

        return $query
            ->orderBy('lingkup_materis.id')
            ->orderBy('tujuan_pembelajarans.id')
            ->get()
            ->groupBy('lm_id')
            ->filter(fn ($tpRows) => $tpRows->isNotEmpty());
    }

    private function enrolledStudentsForSubject(object $subject, int $tahunAjaranId, int $semester): Collection
    {
        return DB::table('siswa_kelas_semester')
            ->join('siswas', 'siswa_kelas_semester.siswa_id', '=', 'siswas.id')
            ->select('siswas.id')
            ->where('siswa_kelas_semester.kelas_id', $subject->kelas_id)
            ->where('siswa_kelas_semester.tahun_ajaran_id', $tahunAjaranId)
            ->where('siswa_kelas_semester.semester', $semester)
            ->where(function ($query) {
                $query->whereNull('siswas.nis')
                    ->orWhere('siswas.nis', 'not like', 'S2-%');
            })
            ->where(function ($query) {
                $query->whereNull('siswas.nisn')
                    ->orWhere('siswas.nisn', 'not like', 'S2-%');
            })
            ->when(Schema::hasColumn('siswas', 'deleted_at'), fn ($query) => $query->whereNull('siswas.deleted_at'))
            ->orderBy('siswas.id')
            ->get();
    }

    /**
     * @return array{tp_rows: array<int, array<string, mixed>>, lm_rows: array<int, array<string, mixed>>, aggregate_row: array<string, mixed>}
     */
    private function scoreSet(int $studentId, int $subjectId, int $tahunAjaranId, Collection $learningData): array
    {
        $tpRows = [];
        $lmRows = [];
        $allTpScores = [];
        $lmScores = [];

        foreach ($learningData as $lmId => $tpItems) {
            $scoresForLm = [];

            foreach ($tpItems->values() as $tpIndex => $tp) {
                $score = $this->scoreValue($studentId, $subjectId, (int) $tp->tp_id);
                $scoresForLm[] = $score;
                $allTpScores[] = $score;
                $tpRows[] = [
                    'siswa_id' => $studentId,
                    'mata_pelajaran_id' => $subjectId,
                    'lingkup_materi_id' => (int) $lmId,
                    'tujuan_pembelajaran_id' => (int) $tp->tp_id,
                    'tahun_ajaran_id' => $tahunAjaranId,
                    'nilai_tp' => $score,
                    'tp_number' => $tpIndex + 1,
                ];
            }

            $lmScore = round(array_sum($scoresForLm) / max(1, count($scoresForLm)), 2);
            $lmScores[] = $lmScore;
            $lmRows[] = [
                'siswa_id' => $studentId,
                'mata_pelajaran_id' => $subjectId,
                'lingkup_materi_id' => (int) $lmId,
                'tujuan_pembelajaran_id' => null,
                'tahun_ajaran_id' => $tahunAjaranId,
                'nilai_lm' => $lmScore,
            ];
        }

        $naTp = round(array_sum($allTpScores) / max(1, count($allTpScores)), 2);
        $naLm = round(array_sum($lmScores) / max(1, count($lmScores)), 2);
        $nilaiTes = $this->boundedScore(78 + (($studentId + $subjectId) % 18));
        $nilaiNonTes = $this->boundedScore(80 + (($studentId * 2 + $subjectId) % 16));
        $nilaiAkhirSemester = round(($nilaiTes + $nilaiNonTes) / 2, 2);
        $nilaiAkhirRapor = round(($naTp + $naLm + ($nilaiAkhirSemester * 2)) / 4);

        return [
            'tp_rows' => $tpRows,
            'lm_rows' => $lmRows,
            'aggregate_row' => [
                'siswa_id' => $studentId,
                'mata_pelajaran_id' => $subjectId,
                'lingkup_materi_id' => null,
                'tujuan_pembelajaran_id' => null,
                'tahun_ajaran_id' => $tahunAjaranId,
                'na_tp' => $naTp,
                'na_lm' => $naLm,
                'nilai_tes' => $nilaiTes,
                'nilai_non_tes' => $nilaiNonTes,
                'na_sumatif_semester' => $nilaiAkhirSemester,
                'nilai_akhir_semester' => $nilaiAkhirSemester,
                'nilai_akhir_rapor' => $nilaiAkhirRapor,
                'is_submitted' => true,
            ],
        ];
    }

    private function scoreValue(int $studentId, int $subjectId, int $tpId): float
    {
        return $this->boundedScore(75 + (($studentId + $subjectId + $tpId) % 21));
    }

    private function boundedScore(int $score): float
    {
        return (float) max(75, min(95, $score));
    }

    /**
     * @param  array<string, int>  $stats
     */
    private function createScoreRow(array $row, array &$stats): void
    {
        $attributes = [
            'siswa_id' => $row['siswa_id'],
            'mata_pelajaran_id' => $row['mata_pelajaran_id'],
            'lingkup_materi_id' => $row['lingkup_materi_id'],
            'tujuan_pembelajaran_id' => $row['tujuan_pembelajaran_id'],
            'tahun_ajaran_id' => $row['tahun_ajaran_id'],
        ];

        if ($this->scoreRowExists($attributes)) {
            $stats['score_rows_reused']++;

            return;
        }

        $now = now();
        $payload = array_merge($attributes, $row, [
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('nilais')->insert(
            collect($payload)
                ->only(Schema::getColumnListing('nilais'))
                ->all()
        );

        $stats['score_rows_created']++;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function scoreRowExists(array $attributes): bool
    {
        $query = DB::table('nilais')
            ->where('siswa_id', $attributes['siswa_id'])
            ->where('mata_pelajaran_id', $attributes['mata_pelajaran_id'])
            ->where('tahun_ajaran_id', $attributes['tahun_ajaran_id']);

        foreach (['lingkup_materi_id', 'tujuan_pembelajaran_id'] as $column) {
            $attributes[$column] === null
                ? $query->whereNull($column)
                : $query->where($column, $attributes[$column]);
        }

        if (Schema::hasColumn('nilais', 'deleted_at')) {
            $query->whereNull('deleted_at');
        }

        return $query->exists();
    }

    private function clearProgressCaches(Collection $classIds): void
    {
        if (! Schema::hasTable('guru_kelas')) {
            return;
        }

        $classIds->filter()->unique()->each(function (int $classId) {
            $guruIds = DB::table('mata_pelajarans')
                ->where('kelas_id', $classId)
                ->pluck('guru_id')
                ->filter()
                ->unique();

            if ($guruIds->isEmpty()) {
                DashboardController::clearProgressCacheForKelas($classId);

                return;
            }

            foreach ($guruIds as $guruId) {
                DashboardController::clearProgressCacheForKelas($classId, (int) $guruId);
            }
        });
    }
}

<?php

namespace App\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ScoreCompletionService
{
    /**
     * @param  iterable<int, int>  $subjectIds
     * @param  array<int, int>|null  $studentIds
     * @return Collection<int, int>
     */
    public function completedCountsBySubject(iterable $subjectIds, ?int $tahunAjaranId = null, ?array $studentIds = null): Collection
    {
        $subjectIds = $this->normalizeIds($subjectIds);
        $studentIds = is_array($studentIds) ? $this->normalizeIds($studentIds) : null;

        if ($subjectIds->isEmpty() || ($studentIds !== null && $studentIds->isEmpty())) {
            return collect();
        }

        $requiredStructure = $this->requiredStructureBySubject($subjectIds);
        $scoreRows = $this->scoreRowsForSubjects($subjectIds, $tahunAjaranId, $studentIds);
        $filledBySubject = $this->filledScoresBySubject($scoreRows, $requiredStructure);
        $counts = collect();

        foreach ($subjectIds as $subjectId) {
            $required = $requiredStructure->get($subjectId, [
                'lm_ids' => collect(),
                'tp_ids' => collect(),
            ]);

            if ($required['lm_ids']->isEmpty() || $required['tp_ids']->isEmpty()) {
                $counts->put($subjectId, 0);
                continue;
            }

            $targetStudentIds = $studentIds ?? $scoreRows
                ->where('mata_pelajaran_id', $subjectId)
                ->pluck('siswa_id')
                ->unique()
                ->values();

            $completed = 0;
            foreach ($targetStudentIds as $studentId) {
                if ($this->isStudentComplete(
                    $filledBySubject[$subjectId][$studentId] ?? null,
                    $required['lm_ids'],
                    $required['tp_ids']
                )) {
                    $completed++;
                }
            }

            $counts->put($subjectId, $completed);
        }

        return $counts;
    }

    /**
     * @param  array<int, int>|null  $studentIds
     */
    public function countCompletedStudentsForSubject(int $mataPelajaranId, ?int $tahunAjaranId = null, ?array $studentIds = null): int
    {
        return (int) $this->completedCountsBySubject([$mataPelajaranId], $tahunAjaranId, $studentIds)
            ->get($mataPelajaranId, 0);
    }

    /**
     * @param  array<int, int>  $studentIds
     * @return array{progress: float, completed: int, total: int}
     */
    public function progressForSubject(int $mataPelajaranId, ?int $tahunAjaranId, array $studentIds): array
    {
        $studentIds = $this->normalizeIds($studentIds);
        $total = $studentIds->count();

        if ($total === 0) {
            return [
                'progress' => 0.0,
                'completed' => 0,
                'total' => 0,
            ];
        }

        $completed = $this->countCompletedStudentsForSubject($mataPelajaranId, $tahunAjaranId, $studentIds->all());

        return [
            'progress' => min(100, ($completed / $total) * 100),
            'completed' => $completed,
            'total' => $total,
        ];
    }

    /**
     * @param  iterable<int, int>  $ids
     * @return Collection<int, int>
     */
    private function normalizeIds(iterable $ids): Collection
    {
        return collect($ids)
            ->filter(fn ($id) => $id !== null && $id !== '')
            ->map(fn ($id) => (int) $id)
            ->filter(fn (int $id) => $id > 0)
            ->unique()
            ->values();
    }

    /**
     * @param  Collection<int, int>  $subjectIds
     * @return Collection<int, array{lm_ids: Collection<int, int>, tp_ids: Collection<int, int>}>
     */
    private function requiredStructureBySubject(Collection $subjectIds): Collection
    {
        $lingkupMateris = DB::table('lingkup_materis')
            ->select('id', 'mata_pelajaran_id')
            ->whereIn('mata_pelajaran_id', $subjectIds)
            ->whereNull('deleted_at')
            ->get();

        $lingkupMateriIds = $lingkupMateris->pluck('id')->map(fn ($id) => (int) $id)->values();

        $tujuanPembelajarans = $lingkupMateriIds->isEmpty()
            ? collect()
            : DB::table('tujuan_pembelajarans')
                ->join('lingkup_materis', 'tujuan_pembelajarans.lingkup_materi_id', '=', 'lingkup_materis.id')
                ->select(
                    'tujuan_pembelajarans.id',
                    'tujuan_pembelajarans.lingkup_materi_id',
                    'lingkup_materis.mata_pelajaran_id'
                )
                ->whereIn('tujuan_pembelajarans.lingkup_materi_id', $lingkupMateriIds)
                ->whereNull('tujuan_pembelajarans.deleted_at')
                ->whereNull('lingkup_materis.deleted_at')
                ->get();

        return $subjectIds->mapWithKeys(function (int $subjectId) use ($lingkupMateris, $tujuanPembelajarans) {
            return [
                $subjectId => [
                    'lm_ids' => $lingkupMateris
                        ->where('mata_pelajaran_id', $subjectId)
                        ->pluck('id')
                        ->map(fn ($id) => (int) $id)
                        ->values(),
                    'tp_ids' => $tujuanPembelajarans
                        ->where('mata_pelajaran_id', $subjectId)
                        ->pluck('id')
                        ->map(fn ($id) => (int) $id)
                        ->values(),
                ],
            ];
        });
    }

    /**
     * @param  Collection<int, int>  $subjectIds
     * @param  Collection<int, int>|null  $studentIds
     */
    private function scoreRowsForSubjects(Collection $subjectIds, ?int $tahunAjaranId, ?Collection $studentIds): Collection
    {
        return DB::table('nilais')
            ->select(
                'mata_pelajaran_id',
                'siswa_id',
                'lingkup_materi_id',
                'tujuan_pembelajaran_id',
                'nilai_tp',
                'nilai_lm',
                'nilai_akhir_rapor'
            )
            ->whereIn('mata_pelajaran_id', $subjectIds)
            ->whereNull('deleted_at')
            ->when($tahunAjaranId, function ($query) use ($tahunAjaranId) {
                return $query->where('tahun_ajaran_id', $tahunAjaranId);
            })
            ->when($studentIds !== null, function ($query) use ($studentIds) {
                return $query->whereIn('siswa_id', $studentIds);
            })
            ->get();
    }

    /**
     * @param  Collection<int, object>  $scoreRows
     * @param  Collection<int, array{lm_ids: Collection<int, int>, tp_ids: Collection<int, int>}>  $requiredStructure
     * @return array<int, array<int, array{lm_ids: array<int, bool>, tp_ids: array<int, bool>, has_final: bool}>>
     */
    private function filledScoresBySubject(Collection $scoreRows, Collection $requiredStructure): array
    {
        $requiredSets = $requiredStructure->map(function (array $required) {
            return [
                'lm_ids' => array_fill_keys($required['lm_ids']->all(), true),
                'tp_ids' => array_fill_keys($required['tp_ids']->all(), true),
            ];
        });

        $filled = [];

        foreach ($scoreRows as $row) {
            $subjectId = (int) $row->mata_pelajaran_id;
            $studentId = (int) $row->siswa_id;
            $required = $requiredSets->get($subjectId);

            if (! $required) {
                continue;
            }

            $filled[$subjectId][$studentId] ??= [
                'lm_ids' => [],
                'tp_ids' => [],
                'has_final' => false,
            ];

            $tpId = $row->tujuan_pembelajaran_id !== null ? (int) $row->tujuan_pembelajaran_id : null;
            if ($tpId !== null && $row->nilai_tp !== null && isset($required['tp_ids'][$tpId])) {
                $filled[$subjectId][$studentId]['tp_ids'][$tpId] = true;
            }

            $lmId = $row->lingkup_materi_id !== null ? (int) $row->lingkup_materi_id : null;
            if ($tpId === null && $lmId !== null && $row->nilai_lm !== null && isset($required['lm_ids'][$lmId])) {
                $filled[$subjectId][$studentId]['lm_ids'][$lmId] = true;
            }

            if ($row->nilai_akhir_rapor !== null) {
                $filled[$subjectId][$studentId]['has_final'] = true;
            }
        }

        return $filled;
    }

    /**
     * @param  array{lm_ids: array<int, bool>, tp_ids: array<int, bool>, has_final: bool}|null  $filled
     * @param  Collection<int, int>  $requiredLmIds
     * @param  Collection<int, int>  $requiredTpIds
     */
    private function isStudentComplete(?array $filled, Collection $requiredLmIds, Collection $requiredTpIds): bool
    {
        if (! $filled || ! $filled['has_final']) {
            return false;
        }

        foreach ($requiredLmIds as $lmId) {
            if (! isset($filled['lm_ids'][(int) $lmId])) {
                return false;
            }
        }

        foreach ($requiredTpIds as $tpId) {
            if (! isset($filled['tp_ids'][(int) $tpId])) {
                return false;
            }
        }

        return true;
    }
}

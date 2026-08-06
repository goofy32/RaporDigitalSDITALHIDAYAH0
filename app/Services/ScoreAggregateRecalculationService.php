<?php

namespace App\Services;

use App\Models\BobotNilai;
use App\Models\Nilai;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

class ScoreAggregateRecalculationService
{
    /**
     * @param  iterable<int, array{siswa_id:mixed, mata_pelajaran_id:mixed, tahun_ajaran_id:mixed}>  $contexts
     */
    public function recalculateMany(iterable $contexts): void
    {
        collect($contexts)
            ->map(fn (array $context) => [
                'siswa_id' => (int) ($context['siswa_id'] ?? 0),
                'mata_pelajaran_id' => (int) ($context['mata_pelajaran_id'] ?? 0),
                'tahun_ajaran_id' => (int) ($context['tahun_ajaran_id'] ?? 0),
            ])
            ->filter(fn (array $context) => ! in_array(0, $context, true))
            ->unique(fn (array $context) => implode(':', $context))
            ->sortBy(fn (array $context) => implode(':', $context))
            ->each(fn (array $context) => $this->recalculate(
                $context['siswa_id'],
                $context['mata_pelajaran_id'],
                $context['tahun_ajaran_id']
            ));
    }

    public function recalculate(int $siswaId, int $mataPelajaranId, int $tahunAjaranId): ?Nilai
    {
        if (! $this->schemaAvailable()) {
            return null;
        }

        $lockKey = "score_aggregate_recalculation:{$siswaId}:{$mataPelajaranId}:{$tahunAjaranId}";

        return Cache::lock($lockKey, 15)->block(5, fn () => DB::transaction(
            fn () => $this->recalculateLocked($siswaId, $mataPelajaranId, $tahunAjaranId)
        ));
    }

    private function recalculateLocked(int $siswaId, int $mataPelajaranId, int $tahunAjaranId): ?Nilai
    {

        $aggregate = Nilai::withTrashed()
            ->where('siswa_id', $siswaId)
            ->where('mata_pelajaran_id', $mataPelajaranId)
            ->where('tahun_ajaran_id', $tahunAjaranId)
            ->whereNull('lingkup_materi_id')
            ->whereNull('tujuan_pembelajaran_id')
            ->orderBy('id')
            ->lockForUpdate()
            ->first();

        $tpValues = $this->activeTpValues($siswaId, $mataPelajaranId, $tahunAjaranId);
        $lmValues = $this->activeLmValues($siswaId, $mataPelajaranId, $tahunAjaranId);

        if (! $aggregate && $tpValues === [] && $lmValues === []) {
            return null;
        }

        if (! $aggregate) {
            $aggregate = new Nilai([
                'siswa_id' => $siswaId,
                'mata_pelajaran_id' => $mataPelajaranId,
                'tahun_ajaran_id' => $tahunAjaranId,
            ]);
        } elseif ($aggregate->trashed()) {
            $aggregate->restore();
        }

        $naTp = $this->average($tpValues);
        $naLm = $this->average($lmValues);
        $nilaiTes = $aggregate->nilai_tes !== null ? (float) $aggregate->nilai_tes : null;
        $nilaiNonTes = $aggregate->nilai_non_tes !== null ? (float) $aggregate->nilai_non_tes : null;
        $nilaiAkhirSemester = $nilaiTes !== null && $nilaiNonTes !== null
            ? round(($nilaiTes + $nilaiNonTes) / 2, 2)
            : null;

        $aggregate->fill([
            'na_tp' => $naTp,
            'na_lm' => $naLm,
            'nilai_akhir_semester' => $nilaiAkhirSemester,
            'nilai_akhir_rapor' => $this->finalScore(
                $naTp,
                $naLm,
                $nilaiAkhirSemester,
                BobotNilai::resolveForRead($tahunAjaranId)
            ),
            'is_submitted' => $tpValues !== []
                && $lmValues !== []
                && $nilaiTes !== null
                && $nilaiNonTes !== null,
        ]);

        $aggregate->save();

        return $aggregate->refresh();
    }

    /**
     * @return array<int, float>
     */
    private function activeTpValues(int $siswaId, int $mataPelajaranId, int $tahunAjaranId): array
    {
        $query = DB::table('nilais as score_tp')
            ->join('tujuan_pembelajarans as tp', 'tp.id', '=', 'score_tp.tujuan_pembelajaran_id')
            ->join('lingkup_materis as lm', function ($join) {
                $join->on('lm.id', '=', 'tp.lingkup_materi_id')
                    ->on('lm.id', '=', 'score_tp.lingkup_materi_id');
            })
            ->where('score_tp.siswa_id', $siswaId)
            ->where('score_tp.mata_pelajaran_id', $mataPelajaranId)
            ->where('score_tp.tahun_ajaran_id', $tahunAjaranId)
            ->where('lm.mata_pelajaran_id', $mataPelajaranId)
            ->whereNull('score_tp.deleted_at')
            ->whereNull('tp.deleted_at')
            ->whereNull('lm.deleted_at')
            ->whereNotNull('score_tp.nilai_tp');

        if (Schema::hasColumn('lingkup_materis', 'is_active')) {
            $query->where('lm.is_active', true);
        }

        return $query
            ->pluck('score_tp.nilai_tp')
            ->map(fn ($value) => (float) $value)
            ->all();
    }

    /**
     * @return array<int, float>
     */
    private function activeLmValues(int $siswaId, int $mataPelajaranId, int $tahunAjaranId): array
    {
        $query = DB::table('nilais as score_lm')
            ->join('lingkup_materis as lm', 'lm.id', '=', 'score_lm.lingkup_materi_id')
            ->where('score_lm.siswa_id', $siswaId)
            ->where('score_lm.mata_pelajaran_id', $mataPelajaranId)
            ->where('score_lm.tahun_ajaran_id', $tahunAjaranId)
            ->where('lm.mata_pelajaran_id', $mataPelajaranId)
            ->whereNull('score_lm.tujuan_pembelajaran_id')
            ->whereNull('score_lm.deleted_at')
            ->whereNull('lm.deleted_at')
            ->whereNotNull('score_lm.nilai_lm');

        if (Schema::hasColumn('lingkup_materis', 'is_active')) {
            $query->where('lm.is_active', true);
        }

        return $query
            ->pluck('score_lm.nilai_lm')
            ->map(fn ($value) => (float) $value)
            ->all();
    }

    /**
     * @param  array<int, float>  $values
     */
    private function average(array $values): ?float
    {
        return $values === [] ? null : round(array_sum($values) / count($values), 2);
    }

    private function finalScore(
        ?float $naTp,
        ?float $naLm,
        ?float $nilaiAkhirSemester,
        BobotNilai $bobot
    ): ?float {
        $weightedTotal = 0.0;
        $totalWeight = 0;

        foreach ([
            [$naTp, (int) $bobot->bobot_tp],
            [$naLm, (int) $bobot->bobot_lm],
            [$nilaiAkhirSemester, (int) $bobot->bobot_as],
        ] as [$value, $weight]) {
            if ($value !== null) {
                $weightedTotal += $value * $weight;
                $totalWeight += $weight;
            }
        }

        return $totalWeight === 0 ? null : round($weightedTotal / $totalWeight);
    }

    private function schemaAvailable(): bool
    {
        return Schema::hasTable('nilais')
            && Schema::hasTable('tujuan_pembelajarans')
            && Schema::hasTable('lingkup_materis');
    }
}

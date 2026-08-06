<?php

namespace App\Services;

use App\Models\Nilai;
use InvalidArgumentException;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Collection;

class ReportScoreEligibilityService
{
    public function apply($query, string $type, ?string $table = null, ?int $kelasId = null)
    {
        $type = app(ReportPeriodService::class)->normalizeType($type);

        if (! $type) {
            throw new InvalidArgumentException('Jenis rapor tidak valid.');
        }

        $outerTable = $table ?: $query->getModel()->getTable();
        $column = static fn (string $name): string => "{$outerTable}.{$name}";

        if ($kelasId !== null) {
            $query->whereExists(function ($subjectQuery) use ($column, $kelasId) {
                $subjectQuery->selectRaw('1')
                    ->from('mata_pelajarans as report_subject')
                    ->whereColumn('report_subject.id', $column('mata_pelajaran_id'))
                    ->where('report_subject.kelas_id', $kelasId)
                    ->whereNull('report_subject.deleted_at');
            });
        }

        if ($type === 'UTS') {
            $query->whereNotNull($column('na_tp'))
                ->whereNotNull($column('na_lm'))
                ->whereNotNull($column('nilai_akhir_rapor'));

            if ($this->activeSourceSchemaAvailable()) {
                $this->requireActiveTpSource($query, $column);
                $this->requireActiveLmSource($query, $column);
            }

            return $query;
        }

        return $query->where($column('is_submitted'), true);
    }

    public function isEligible(Nilai $nilai, string $type, ?int $kelasId = null): bool
    {
        $type = app(ReportPeriodService::class)->normalizeType($type);

        if (! $type) {
            throw new InvalidArgumentException('Jenis rapor tidak valid.');
        }

        return $this->apply(
            Nilai::query()->whereKey($nilai->getKey()),
            $type,
            null,
            $kelasId
        )->exists();
    }

    /**
     * @param  iterable<int, Nilai>  $nilais
     * @return Collection<int, int>
     */
    public function eligibleIds(iterable $nilais, string $type, ?int $kelasId = null): Collection
    {
        $ids = collect($nilais)
            ->map(fn (Nilai $nilai) => (int) $nilai->getKey())
            ->filter()
            ->unique()
            ->values();

        if ($ids->isEmpty()) {
            return collect();
        }

        return $this->apply(Nilai::query()->whereKey($ids->all()), $type, null, $kelasId)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->values();
    }

    private function requireActiveTpSource($query, callable $column): void
    {
        $hasActiveFlag = Schema::hasColumn('lingkup_materis', 'is_active');

        $query->whereExists(function ($sourceQuery) use ($column, $hasActiveFlag) {
            $sourceQuery->selectRaw('1')
                ->from('nilais as report_tp_score')
                ->join('tujuan_pembelajarans as report_tp', 'report_tp.id', '=', 'report_tp_score.tujuan_pembelajaran_id')
                ->join('lingkup_materis as report_tp_lm', function ($join) {
                    $join->on('report_tp_lm.id', '=', 'report_tp.lingkup_materi_id')
                        ->on('report_tp_lm.id', '=', 'report_tp_score.lingkup_materi_id');
                })
                ->whereColumn('report_tp_score.siswa_id', $column('siswa_id'))
                ->whereColumn('report_tp_score.mata_pelajaran_id', $column('mata_pelajaran_id'))
                ->whereColumn('report_tp_score.tahun_ajaran_id', $column('tahun_ajaran_id'))
                ->whereColumn('report_tp_lm.mata_pelajaran_id', $column('mata_pelajaran_id'))
                ->whereNull('report_tp_score.deleted_at')
                ->whereNull('report_tp.deleted_at')
                ->whereNull('report_tp_lm.deleted_at')
                ->when($hasActiveFlag, fn ($query) => $query->where('report_tp_lm.is_active', true))
                ->whereNotNull('report_tp_score.nilai_tp');
        });
    }

    private function requireActiveLmSource($query, callable $column): void
    {
        $hasActiveFlag = Schema::hasColumn('lingkup_materis', 'is_active');

        $query->whereExists(function ($sourceQuery) use ($column, $hasActiveFlag) {
            $sourceQuery->selectRaw('1')
                ->from('nilais as report_lm_score')
                ->join('lingkup_materis as report_lm', 'report_lm.id', '=', 'report_lm_score.lingkup_materi_id')
                ->whereColumn('report_lm_score.siswa_id', $column('siswa_id'))
                ->whereColumn('report_lm_score.mata_pelajaran_id', $column('mata_pelajaran_id'))
                ->whereColumn('report_lm_score.tahun_ajaran_id', $column('tahun_ajaran_id'))
                ->whereColumn('report_lm.mata_pelajaran_id', $column('mata_pelajaran_id'))
                ->whereNull('report_lm_score.tujuan_pembelajaran_id')
                ->whereNull('report_lm_score.deleted_at')
                ->whereNull('report_lm.deleted_at')
                ->when($hasActiveFlag, fn ($query) => $query->where('report_lm.is_active', true))
                ->whereNotNull('report_lm_score.nilai_lm');
        });
    }

    private function activeSourceSchemaAvailable(): bool
    {
        return Schema::hasTable('nilais')
            && Schema::hasTable('tujuan_pembelajarans')
            && Schema::hasTable('lingkup_materis')
            && Schema::hasColumn('nilais', 'nilai_tp')
            && Schema::hasColumn('nilais', 'nilai_lm')
            && Schema::hasColumn('nilais', 'tujuan_pembelajaran_id')
            && Schema::hasColumn('nilais', 'lingkup_materi_id')
            && Schema::hasColumn('nilais', 'deleted_at')
            && Schema::hasColumn('tujuan_pembelajarans', 'deleted_at')
            && Schema::hasColumn('lingkup_materis', 'deleted_at');
    }
}

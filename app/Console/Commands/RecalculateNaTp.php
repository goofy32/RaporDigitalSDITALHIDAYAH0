<?php

namespace App\Console\Commands;

use App\Models\BobotNilai;
use App\Models\Nilai;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

class RecalculateNaTp extends Command
{
    protected $signature = 'nilai:recalculate-na-tp';

    protected $description = 'Recalculate NA TP, NA LM, nilai akhir semester, and nilai akhir rapor using filled scores only.';

    public function handle(): int
    {
        $groups = Nilai::query()
            ->select('siswa_id', 'mata_pelajaran_id', 'tahun_ajaran_id')
            ->distinct()
            ->orderBy('tahun_ajaran_id')
            ->orderBy('mata_pelajaran_id')
            ->orderBy('siswa_id')
            ->get();

        if ($groups->isEmpty()) {
            $this->info('Tidak ada data nilai untuk dihitung ulang.');

            return self::SUCCESS;
        }

        $updatedCount = 0;

        $this->withProgressBar($groups, function ($group) use (&$updatedCount) {
            $records = Nilai::query()
                ->where('siswa_id', $group->siswa_id)
                ->where('mata_pelajaran_id', $group->mata_pelajaran_id)
                ->when(
                    $group->tahun_ajaran_id === null,
                    fn ($query) => $query->whereNull('tahun_ajaran_id'),
                    fn ($query) => $query->where('tahun_ajaran_id', $group->tahun_ajaran_id)
                )
                ->orderBy('id')
                ->get();

            if ($records->isEmpty()) {
                return;
            }

            $aggregateCarrier = $records->first();
            $bobotNilai = $this->resolveBobotNilai($group->tahun_ajaran_id);

            $naTp = $this->calculateAverage(
                $records->pluck('nilai_tp')
            );
            $naLm = $this->calculateAverage(
                $records->pluck('nilai_lm')
            );
            $nilaiTes = $this->firstFilledNumericValue($records, 'nilai_tes');
            $nilaiNonTes = $this->firstFilledNumericValue($records, 'nilai_non_tes');
            $nilaiAkhirSemester = $this->calculateNilaiAkhirSemester($nilaiTes, $nilaiNonTes);
            $nilaiAkhirRapor = $this->calculateNilaiAkhirRapor($naTp, $naLm, $nilaiAkhirSemester, $bobotNilai);

            $changes = [
                'na_tp' => $naTp,
                'na_lm' => $naLm,
                'nilai_tes' => $nilaiTes,
                'nilai_non_tes' => $nilaiNonTes,
                'nilai_akhir_semester' => $nilaiAkhirSemester,
                'nilai_akhir_rapor' => $nilaiAkhirRapor,
            ];

            if ($this->hasChanges($aggregateCarrier, $changes)) {
                $aggregateCarrier->update($changes);
                $updatedCount++;
            }
        });

        $this->newLine(2);
        $this->info("Rekalkulasi selesai. Total record agregat yang diperbarui: {$updatedCount}");

        return self::SUCCESS;
    }

    protected function resolveBobotNilai(?int $tahunAjaranId): BobotNilai
    {
        return BobotNilai::query()
            ->where('tahun_ajaran_id', $tahunAjaranId)
            ->first()
            ?? new BobotNilai([
                'tahun_ajaran_id' => $tahunAjaranId,
                'bobot_tp' => 1,
                'bobot_lm' => 1,
                'bobot_as' => 2,
            ]);
    }

    protected function calculateAverage(Collection $values): float
    {
        $sum = 0;
        $count = 0;

        foreach ($values as $value) {
            if ($value === '' || $value === null || !is_numeric($value)) {
                continue;
            }

            $sum += (float) $value;
            $count++;
        }

        if ($count === 0) {
            return 0.0;
        }

        return round($sum / $count, 2);
    }

    protected function firstFilledNumericValue(Collection $records, string $field): ?float
    {
        foreach ($records as $record) {
            $value = $record->{$field};

            if ($value === '' || $value === null || !is_numeric($value)) {
                continue;
            }

            return (float) $value;
        }

        return null;
    }

    protected function calculateNilaiAkhirSemester(?float $nilaiTes, ?float $nilaiNonTes): float
    {
        if ($nilaiTes === null || $nilaiNonTes === null) {
            return 0.0;
        }

        return round(($nilaiTes + $nilaiNonTes) / 2, 2);
    }

    protected function calculateNilaiAkhirRapor(
        float $naTp,
        float $naLm,
        float $nilaiAkhirSemester,
        BobotNilai $bobotNilai
    ): float {
        $totalBobot = $bobotNilai->getTotal();

        if ($totalBobot === 0) {
            return 0.0;
        }

        return round(
            (
                ($naTp * (int) $bobotNilai->bobot_tp) +
                ($naLm * (int) $bobotNilai->bobot_lm) +
                ($nilaiAkhirSemester * (int) $bobotNilai->bobot_as)
            ) / $totalBobot
        );
    }

    protected function hasChanges(Nilai $nilai, array $changes): bool
    {
        foreach ($changes as $field => $value) {
            if ((float) ($nilai->{$field} ?? 0) !== (float) ($value ?? 0)) {
                return true;
            }
        }

        return false;
    }
}

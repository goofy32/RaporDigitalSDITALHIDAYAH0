<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bobot_nilais', function (Blueprint $table) {
            $table->unsignedInteger('bobot_tp_ratio')->default(1);
            $table->unsignedInteger('bobot_lm_ratio')->default(1);
            $table->unsignedInteger('bobot_as_ratio')->default(2);
        });

        $this->convertExistingDecimalBobotToRatio();

        Schema::table('bobot_nilais', function (Blueprint $table) {
            $table->dropColumn(['bobot_tp', 'bobot_lm', 'bobot_as']);
        });

        Schema::table('bobot_nilais', function (Blueprint $table) {
            $table->renameColumn('bobot_tp_ratio', 'bobot_tp');
            $table->renameColumn('bobot_lm_ratio', 'bobot_lm');
            $table->renameColumn('bobot_as_ratio', 'bobot_as');
        });

        $this->recalculateNilaiAkhirRapor();
    }

    public function down(): void
    {
        Schema::table('bobot_nilais', function (Blueprint $table) {
            $table->float('bobot_tp_decimal', 5, 2)->default(0.25);
            $table->float('bobot_lm_decimal', 5, 2)->default(0.25);
            $table->float('bobot_as_decimal', 5, 2)->default(0.50);
        });

        $this->convertExistingRatioBobotToDecimal();

        Schema::table('bobot_nilais', function (Blueprint $table) {
            $table->dropColumn(['bobot_tp', 'bobot_lm', 'bobot_as']);
        });

        Schema::table('bobot_nilais', function (Blueprint $table) {
            $table->renameColumn('bobot_tp_decimal', 'bobot_tp');
            $table->renameColumn('bobot_lm_decimal', 'bobot_lm');
            $table->renameColumn('bobot_as_decimal', 'bobot_as');
        });

        $this->recalculateNilaiAkhirRapor();
    }

    private function convertExistingDecimalBobotToRatio(): void
    {
        $bobots = DB::table('bobot_nilais')
            ->select('id', 'bobot_tp', 'bobot_lm', 'bobot_as')
            ->get();

        foreach ($bobots as $bobot) {
            [$tp, $lm, $as] = $this->convertDecimalValuesToRatio(
                (float) $bobot->bobot_tp,
                (float) $bobot->bobot_lm,
                (float) $bobot->bobot_as
            );

            DB::table('bobot_nilais')
                ->where('id', $bobot->id)
                ->update([
                    'bobot_tp_ratio' => $tp,
                    'bobot_lm_ratio' => $lm,
                    'bobot_as_ratio' => $as,
                ]);
        }
    }

    private function convertExistingRatioBobotToDecimal(): void
    {
        $bobots = DB::table('bobot_nilais')
            ->select('id', 'bobot_tp', 'bobot_lm', 'bobot_as')
            ->get();

        foreach ($bobots as $bobot) {
            $total = (int) $bobot->bobot_tp + (int) $bobot->bobot_lm + (int) $bobot->bobot_as;

            if ($total <= 0) {
                $tp = 0.25;
                $lm = 0.25;
                $as = 0.50;
            } else {
                $tp = round(((int) $bobot->bobot_tp / $total), 2);
                $lm = round(((int) $bobot->bobot_lm / $total), 2);
                $as = round(((int) $bobot->bobot_as / $total), 2);
            }

            DB::table('bobot_nilais')
                ->where('id', $bobot->id)
                ->update([
                    'bobot_tp_decimal' => $tp,
                    'bobot_lm_decimal' => $lm,
                    'bobot_as_decimal' => $as,
                ]);
        }
    }

    private function convertDecimalValuesToRatio(float $tp, float $lm, float $as): array
    {
        $scaledTp = max(1, (int) round($tp * 100));
        $scaledLm = max(1, (int) round($lm * 100));
        $scaledAs = max(1, (int) round($as * 100));

        $gcd = $this->findGreatestCommonDivisor(
            $this->findGreatestCommonDivisor($scaledTp, $scaledLm),
            $scaledAs
        );

        if ($gcd <= 0) {
            return [1, 1, 2];
        }

        return [
            max(1, (int) ($scaledTp / $gcd)),
            max(1, (int) ($scaledLm / $gcd)),
            max(1, (int) ($scaledAs / $gcd)),
        ];
    }

    private function findGreatestCommonDivisor(int $a, int $b): int
    {
        $a = abs($a);
        $b = abs($b);

        while ($b !== 0) {
            $remainder = $a % $b;
            $a = $b;
            $b = $remainder;
        }

        return $a;
    }

    private function recalculateNilaiAkhirRapor(): void
    {
        $bobots = DB::table('bobot_nilais')
            ->select('tahun_ajaran_id', 'bobot_tp', 'bobot_lm', 'bobot_as')
            ->get();

        foreach ($bobots as $bobot) {
            $total = (float) $bobot->bobot_tp + (float) $bobot->bobot_lm + (float) $bobot->bobot_as;

            if ($total <= 0) {
                continue;
            }

            $nilaiQuery = DB::table('nilais')
                ->select('id', 'na_tp', 'na_lm', 'nilai_akhir_semester');

            if ($bobot->tahun_ajaran_id === null) {
                $nilaiQuery->whereNull('tahun_ajaran_id');
            } else {
                $nilaiQuery->where('tahun_ajaran_id', $bobot->tahun_ajaran_id);
            }

            $nilaiQuery
                ->whereNotNull('na_tp')
                ->whereNotNull('na_lm')
                ->whereNotNull('nilai_akhir_semester')
                ->orderBy('id')
                ->chunkById(100, function ($nilais) use ($bobot, $total) {
                    foreach ($nilais as $nilai) {
                        $nilaiAkhirRapor = round(
                            (
                                ((float) $nilai->na_tp * (float) $bobot->bobot_tp) +
                                ((float) $nilai->na_lm * (float) $bobot->bobot_lm) +
                                ((float) $nilai->nilai_akhir_semester * (float) $bobot->bobot_as)
                            ) / $total
                        );

                        DB::table('nilais')
                            ->where('id', $nilai->id)
                            ->update(['nilai_akhir_rapor' => $nilaiAkhirRapor]);
                    }
                });
        }
    }
};

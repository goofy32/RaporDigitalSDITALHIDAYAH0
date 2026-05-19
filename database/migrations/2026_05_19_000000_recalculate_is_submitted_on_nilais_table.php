<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('nilais')->update([
            'is_submitted' => false,
        ]);

        DB::table('nilais')
            ->whereNull('lingkup_materi_id')
            ->whereNull('tujuan_pembelajaran_id')
            ->orderBy('id')
            ->chunkById(100, function ($aggregateRows) {
                foreach ($aggregateRows as $aggregateRow) {
                    $baseQuery = DB::table('nilais')
                        ->where('siswa_id', $aggregateRow->siswa_id)
                        ->where('mata_pelajaran_id', $aggregateRow->mata_pelajaran_id)
                        ->where('tahun_ajaran_id', $aggregateRow->tahun_ajaran_id)
                        ->whereNull('deleted_at');

                    $hasAnyTp = (clone $baseQuery)
                        ->whereNotNull('tujuan_pembelajaran_id')
                        ->whereNotNull('nilai_tp')
                        ->exists();

                    $hasAnyLm = (clone $baseQuery)
                        ->whereNotNull('lingkup_materi_id')
                        ->whereNull('tujuan_pembelajaran_id')
                        ->whereNotNull('nilai_lm')
                        ->exists();

                    $isSubmitted = $hasAnyTp
                        && $hasAnyLm
                        && $aggregateRow->nilai_tes !== null
                        && $aggregateRow->nilai_non_tes !== null;

                    DB::table('nilais')
                        ->where('id', $aggregateRow->id)
                        ->update([
                            'is_submitted' => $isSubmitted,
                        ]);
                }
            });
    }

    public function down(): void
    {
        DB::table('nilais')->update([
            'is_submitted' => false,
        ]);

        DB::table('nilais')
            ->whereNotNull('nilai_akhir_rapor')
            ->update([
                'is_submitted' => true,
            ]);
    }
};

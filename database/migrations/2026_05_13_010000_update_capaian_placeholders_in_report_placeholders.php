<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $oldMapelCapaian = [];
        for ($i = 1; $i <= 10; $i++) {
            $oldMapelCapaian[] = "capaian_kompetensi{$i}";
        }

        $oldMulokCapaian = [];
        for ($i = 1; $i <= 5; $i++) {
            $oldMulokCapaian[] = "capaian_mulok{$i}";
        }

        DB::table('report_placeholders')
            ->whereIn('placeholder_key', array_merge($oldMapelCapaian, $oldMulokCapaian))
            ->update(['is_required' => false]);

        for ($i = 1; $i <= 10; $i++) {
            DB::table('report_placeholders')->updateOrInsert(
                ['placeholder_key' => "capaian_tertinggi{$i}"],
                [
                    'description' => "Capaian tertinggi mapel {$i}",
                    'category' => 'mapel',
                    'sample_value' => null,
                    'is_required' => $i <= 4,
                    'updated_at' => now(),
                    'created_at' => now(),
                ]
            );

            DB::table('report_placeholders')->updateOrInsert(
                ['placeholder_key' => "capaian_terendah{$i}"],
                [
                    'description' => "Capaian terendah mapel {$i}",
                    'category' => 'mapel',
                    'sample_value' => null,
                    'is_required' => $i <= 4,
                    'updated_at' => now(),
                    'created_at' => now(),
                ]
            );
        }

        for ($i = 1; $i <= 5; $i++) {
            DB::table('report_placeholders')->updateOrInsert(
                ['placeholder_key' => "capaian_tertinggi_mulok{$i}"],
                [
                    'description' => "Capaian tertinggi muatan lokal {$i}",
                    'category' => 'mulok',
                    'sample_value' => null,
                    'is_required' => false,
                    'updated_at' => now(),
                    'created_at' => now(),
                ]
            );

            DB::table('report_placeholders')->updateOrInsert(
                ['placeholder_key' => "capaian_terendah_mulok{$i}"],
                [
                    'description' => "Capaian terendah muatan lokal {$i}",
                    'category' => 'mulok',
                    'sample_value' => null,
                    'is_required' => false,
                    'updated_at' => now(),
                    'created_at' => now(),
                ]
            );
        }
    }

    public function down(): void
    {
        for ($i = 1; $i <= 10; $i++) {
            DB::table('report_placeholders')
                ->where('placeholder_key', "capaian_kompetensi{$i}")
                ->update(['is_required' => $i <= 4]);
        }

        for ($i = 1; $i <= 5; $i++) {
            DB::table('report_placeholders')
                ->where('placeholder_key', "capaian_mulok{$i}")
                ->update(['is_required' => false]);
        }

        $newKeys = [];
        for ($i = 1; $i <= 10; $i++) {
            $newKeys[] = "capaian_tertinggi{$i}";
            $newKeys[] = "capaian_terendah{$i}";
        }

        for ($i = 1; $i <= 5; $i++) {
            $newKeys[] = "capaian_tertinggi_mulok{$i}";
            $newKeys[] = "capaian_terendah_mulok{$i}";
        }

        DB::table('report_placeholders')
            ->whereIn('placeholder_key', $newKeys)
            ->delete();
    }
};

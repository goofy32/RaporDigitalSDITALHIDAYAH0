<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('nilai_ekstrakurikuler')
            ->where('predikat', 'Sangat Baik')
            ->update(['predikat' => 'A']);

        DB::table('nilai_ekstrakurikuler')
            ->where('predikat', 'Baik')
            ->update(['predikat' => 'B']);

        DB::table('nilai_ekstrakurikuler')
            ->where('predikat', 'Cukup')
            ->update(['predikat' => 'C']);

        DB::table('nilai_ekstrakurikuler')
            ->where('predikat', 'Kurang')
            ->update(['predikat' => 'D']);
    }

    public function down(): void
    {
        DB::table('nilai_ekstrakurikuler')
            ->where('predikat', 'A')
            ->update(['predikat' => 'Sangat Baik']);

        DB::table('nilai_ekstrakurikuler')
            ->where('predikat', 'B')
            ->update(['predikat' => 'Baik']);

        DB::table('nilai_ekstrakurikuler')
            ->where('predikat', 'C')
            ->update(['predikat' => 'Cukup']);

        DB::table('nilai_ekstrakurikuler')
            ->where('predikat', 'D')
            ->update(['predikat' => 'Kurang']);
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('nilais', function (Blueprint $table) {
            $table->boolean('is_submitted')
                ->default(false)
                ->after('nilai_akhir_rapor');
        });

        DB::table('nilais')
            ->whereNotNull('nilai_akhir_rapor')
            ->update([
                'is_submitted' => true,
            ]);
    }

    public function down(): void
    {
        Schema::table('nilais', function (Blueprint $table) {
            $table->dropColumn('is_submitted');
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('capaian_custom', function (Blueprint $table) {
            $table->text('custom_capaian_tertinggi')->nullable()->after('custom_capaian');
            $table->text('custom_capaian_terendah')->nullable()->after('custom_capaian_tertinggi');
        });

        DB::table('capaian_custom')->truncate();
    }

    public function down(): void
    {
        Schema::table('capaian_custom', function (Blueprint $table) {
            $table->dropColumn(['custom_capaian_tertinggi', 'custom_capaian_terendah']);
        });
    }
};

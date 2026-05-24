<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('nilais', function (Blueprint $table) {
            $table->index(
                ['siswa_id', 'mata_pelajaran_id', 'tahun_ajaran_id', 'lingkup_materi_id', 'tujuan_pembelajaran_id'],
                'idx_nilais_logical_key'
            );

            $table->index(
                ['mata_pelajaran_id', 'tahun_ajaran_id', 'is_submitted', 'deleted_at', 'siswa_id'],
                'idx_nilais_progress'
            );

            $table->index(
                ['siswa_id', 'tahun_ajaran_id', 'deleted_at'],
                'idx_nilais_siswa_year'
            );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('nilais', function (Blueprint $table) {
            $table->dropIndex('idx_nilais_logical_key');
            $table->dropIndex('idx_nilais_progress');
            $table->dropIndex('idx_nilais_siswa_year');
        });
    }
};

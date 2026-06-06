<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('profil_sekolah')) {
            return;
        }

        Schema::table('profil_sekolah', function (Blueprint $table) {
            if (Schema::hasColumn('profil_sekolah', 'tahun_pelajaran')) {
                $table->string('tahun_pelajaran')->nullable()->change();
            }

            if (Schema::hasColumn('profil_sekolah', 'semester')) {
                $table->integer('semester')->nullable()->change();
            }
        });
    }

    public function down(): void
    {
        // Intentionally non-destructive: clean setup profiles may legitimately
        // have no academic-year context until Tahun Ajaran is configured.
    }
};

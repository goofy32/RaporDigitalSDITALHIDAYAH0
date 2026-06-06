<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('gurus') || ! Schema::hasColumn('gurus', 'nuptk')) {
            return;
        }

        if (Schema::getConnection()->getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE `gurus` MODIFY `nuptk` VARCHAR(255) NULL');
        }
    }

    public function down(): void
    {
        // Intentionally left nullable: reversing could fail when valid teachers have no NUPTK.
    }
};

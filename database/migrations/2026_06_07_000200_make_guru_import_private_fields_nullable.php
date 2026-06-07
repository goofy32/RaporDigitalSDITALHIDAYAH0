<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('gurus')) {
            return;
        }

        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        if (Schema::hasColumn('gurus', 'tanggal_lahir')) {
            DB::statement('ALTER TABLE `gurus` MODIFY `tanggal_lahir` DATE NULL');
        }

        if (Schema::hasColumn('gurus', 'no_handphone')) {
            DB::statement('ALTER TABLE `gurus` MODIFY `no_handphone` VARCHAR(255) NULL');
        }

        if (Schema::hasColumn('gurus', 'email')) {
            DB::statement('ALTER TABLE `gurus` MODIFY `email` VARCHAR(255) NULL');
        }

        if (Schema::hasColumn('gurus', 'alamat')) {
            DB::statement('ALTER TABLE `gurus` MODIFY `alamat` TEXT NULL');
        }
    }

    public function down(): void
    {
        // Intentionally non-destructive. Imported guru rows may validly omit these private fields.
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (! Schema::hasColumn('siswas', 'photo')) {
            return;
        }

        if (DB::connection()->getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE `siswas` MODIFY `photo` VARCHAR(255) NULL DEFAULT NULL');

            return;
        }

        Schema::table('siswas', function ($table) {
            $table->string('photo')->nullable()->default(null)->change();
        });
    }

    /**
     * Reverse the migrations.
     *
     * Intentionally non-destructive: generated and manually entered students
     * may now legitimately have no photo, so rollback must not force a value.
     */
    public function down(): void
    {
        //
    }
};

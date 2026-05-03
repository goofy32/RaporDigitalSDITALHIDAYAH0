<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('gurus') && Schema::hasColumn('gurus', 'password_plain')) {
            DB::table('gurus')->update(['password_plain' => null]);
        }
    }

    public function down(): void
    {
        // Intentionally left blank. Cleared plaintext passwords cannot be restored.
    }
};

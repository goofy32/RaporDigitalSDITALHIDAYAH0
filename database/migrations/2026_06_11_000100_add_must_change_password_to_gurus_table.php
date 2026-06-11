<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('gurus') || Schema::hasColumn('gurus', 'must_change_password')) {
            return;
        }

        Schema::table('gurus', function (Blueprint $table) {
            $table->boolean('must_change_password')->default(false)->after('password');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('gurus') || ! Schema::hasColumn('gurus', 'must_change_password')) {
            return;
        }

        Schema::table('gurus', function (Blueprint $table) {
            $table->dropColumn('must_change_password');
        });
    }
};

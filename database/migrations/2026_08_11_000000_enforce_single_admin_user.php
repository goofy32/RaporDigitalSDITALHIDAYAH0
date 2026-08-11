<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::table('users')->count() > 1) {
            throw new RuntimeException('Single Admin invariant cannot be applied while multiple users exist.');
        }

        Schema::table('users', function (Blueprint $table) {
            $table->tinyInteger('admin_singleton')->virtualAs('1');
            $table->unique('admin_singleton', 'users_admin_singleton_unique');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique('users_admin_singleton_unique');
            $table->dropColumn('admin_singleton');
        });
    }
};

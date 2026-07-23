<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('gurus') && ! Schema::hasColumn('gurus', 'signature_path')) {
            Schema::table('gurus', function (Blueprint $table) {
                $table->string('signature_path')->nullable()->after('photo');
            });
        }

        if (! Schema::hasTable('report_placeholders')) {
            return;
        }

        if (DB::table('report_placeholders')->where('placeholder_key', 'ttd_wali_kelas')->exists()) {
            return;
        }

        DB::table('report_placeholders')->insert([
            'placeholder_key' => 'ttd_wali_kelas',
            'description' => 'Gambar tanda tangan wali kelas',
            'category' => 'Data Sekolah',
            'sample_value' => '',
            'is_required' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        if (Schema::hasTable('gurus') && Schema::hasColumn('gurus', 'signature_path')) {
            Schema::table('gurus', function (Blueprint $table) {
                $table->dropColumn('signature_path');
            });
        }

        // Keep report_placeholders data intact on rollback. The row may have
        // been customized after migration, so deleting it would be destructive.
    }
};

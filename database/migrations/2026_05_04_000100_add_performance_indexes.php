<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('nilais')) {
            Schema::table('nilais', function (Blueprint $table) {
                $table->index(['mata_pelajaran_id', 'tahun_ajaran_id', 'nilai_akhir_rapor'], 'idx_nilais_subject_year_final');
                $table->index(['siswa_id', 'tahun_ajaran_id', 'nilai_akhir_rapor'], 'idx_nilais_student_year_final');
            });
        }

        if (Schema::hasTable('mata_pelajarans')) {
            Schema::table('mata_pelajarans', function (Blueprint $table) {
                $table->index(['guru_id', 'tahun_ajaran_id', 'kelas_id'], 'idx_mapel_guru_year_class');
                $table->index(['kelas_id', 'tahun_ajaran_id', 'semester'], 'idx_mapel_kelas_year_semester');
            });
        }

        if (Schema::hasTable('guru_kelas')) {
            Schema::table('guru_kelas', function (Blueprint $table) {
                $table->index(['guru_id', 'is_wali_kelas', 'role'], 'idx_guru_kelas_guru_wali_role');
                $table->index(['kelas_id', 'is_wali_kelas', 'role'], 'idx_guru_kelas_kelas_wali_role');
            });
        }

        if (Schema::hasTable('notification_reads')) {
            Schema::table('notification_reads', function (Blueprint $table) {
                $table->index(['notification_id', 'guru_id'], 'idx_notification_reads_notification_guru');
            });
        }

        if (Schema::hasTable('notifications')) {
            Schema::table('notifications', function (Blueprint $table) {
                $table->index(['target', 'created_at'], 'idx_notifications_target_created');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('notifications')) {
            Schema::table('notifications', function (Blueprint $table) {
                $table->dropIndex('idx_notifications_target_created');
            });
        }

        if (Schema::hasTable('notification_reads')) {
            Schema::table('notification_reads', function (Blueprint $table) {
                $table->dropIndex('idx_notification_reads_notification_guru');
            });
        }

        if (Schema::hasTable('guru_kelas')) {
            Schema::table('guru_kelas', function (Blueprint $table) {
                $table->dropIndex('idx_guru_kelas_guru_wali_role');
                $table->dropIndex('idx_guru_kelas_kelas_wali_role');
            });
        }

        if (Schema::hasTable('mata_pelajarans')) {
            Schema::table('mata_pelajarans', function (Blueprint $table) {
                $table->dropIndex('idx_mapel_guru_year_class');
                $table->dropIndex('idx_mapel_kelas_year_semester');
            });
        }

        if (Schema::hasTable('nilais')) {
            Schema::table('nilais', function (Blueprint $table) {
                $table->dropIndex('idx_nilais_subject_year_final');
                $table->dropIndex('idx_nilais_student_year_final');
            });
        }
    }
};

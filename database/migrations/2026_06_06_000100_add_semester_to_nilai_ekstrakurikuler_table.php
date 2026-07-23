<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('nilai_ekstrakurikuler')) {
            return;
        }

        if (! Schema::hasColumn('nilai_ekstrakurikuler', 'tahun_ajaran_id')) {
            throw new RuntimeException('Cannot add semester context to nilai_ekstrakurikuler without tahun_ajaran_id.');
        }

        if (! Schema::hasColumn('nilai_ekstrakurikuler', 'semester')) {
            Schema::table('nilai_ekstrakurikuler', function (Blueprint $table) {
                $table->unsignedTinyInteger('semester')->nullable()->after('tahun_ajaran_id');
            });
        }

        $needsStudentContextIndex = ! $this->hasIndexForColumns(
            'nilai_ekstrakurikuler',
            ['siswa_id', 'ekstrakurikuler_id', 'tahun_ajaran_id', 'semester']
        );
        $needsYearSemesterIndex = ! $this->hasIndexForColumns(
            'nilai_ekstrakurikuler',
            ['tahun_ajaran_id', 'semester']
        );

        if ($needsStudentContextIndex || $needsYearSemesterIndex) {
            Schema::table('nilai_ekstrakurikuler', function (Blueprint $table) use ($needsStudentContextIndex, $needsYearSemesterIndex) {
                if ($needsStudentContextIndex) {
                    $table->index(
                        ['siswa_id', 'ekstrakurikuler_id', 'tahun_ajaran_id', 'semester'],
                        'idx_nilai_ekskul_student_context'
                    );
                }

                if ($needsYearSemesterIndex) {
                    $table->index(
                        ['tahun_ajaran_id', 'semester'],
                        'idx_nilai_ekskul_year_semester'
                    );
                }
            });
        }
    }

    private function hasIndexForColumns(string $table, array $columns): bool
    {
        try {
            foreach (Schema::getIndexes($table) as $index) {
                if (($index['columns'] ?? []) === $columns) {
                    return true;
                }
            }
        } catch (\Throwable) {
            return false;
        }

        return false;
    }

    public function down(): void
    {
        // Intentionally keep semester data on rollback to avoid destroying historical result context.
    }
};

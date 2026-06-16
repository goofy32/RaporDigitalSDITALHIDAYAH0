<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('capaian_phrase_defaults')) {
            Schema::create('capaian_phrase_defaults', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('tahun_ajaran_id');
                $table->unsignedTinyInteger('semester');
                $table->unsignedBigInteger('kelas_id');
                $table->unsignedBigInteger('mata_pelajaran_id');
                $table->string('type');
                $table->string('mode')->default('preset');
                $table->text('phrase');
                $table->timestamps();

                $table->unique(
                    ['tahun_ajaran_id', 'semester', 'kelas_id', 'mata_pelajaran_id', 'type'],
                    'capaian_phrase_defaults_context_unique'
                );
                $table->index(['tahun_ajaran_id', 'semester', 'kelas_id']);
                $table->index('mata_pelajaran_id');
            });
        }

        if (! Schema::hasTable('capaian_custom')) {
            return;
        }

        $needsTertinggiMode = ! Schema::hasColumn('capaian_custom', 'tertinggi_prefix_mode');
        $needsTertinggiText = ! Schema::hasColumn('capaian_custom', 'tertinggi_prefix_text');
        $needsTerendahMode = ! Schema::hasColumn('capaian_custom', 'terendah_prefix_mode');
        $needsTerendahText = ! Schema::hasColumn('capaian_custom', 'terendah_prefix_text');

        if (! $needsTertinggiMode && ! $needsTertinggiText && ! $needsTerendahMode && ! $needsTerendahText) {
            return;
        }

        Schema::table('capaian_custom', function (Blueprint $table) use (
            $needsTertinggiMode,
            $needsTertinggiText,
            $needsTerendahMode,
            $needsTerendahText
        ) {
            if ($needsTertinggiMode) {
                $table->string('tertinggi_prefix_mode')->nullable();
            }

            if ($needsTertinggiText) {
                $table->text('tertinggi_prefix_text')->nullable();
            }

            if ($needsTerendahMode) {
                $table->string('terendah_prefix_mode')->nullable();
            }

            if ($needsTerendahText) {
                $table->text('terendah_prefix_text')->nullable();
            }
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('capaian_custom')) {
            $columns = collect([
                'tertinggi_prefix_mode',
                'tertinggi_prefix_text',
                'terendah_prefix_mode',
                'terendah_prefix_text',
            ])->filter(fn (string $column) => Schema::hasColumn('capaian_custom', $column))
                ->values()
                ->all();

            if (! empty($columns)) {
                Schema::table('capaian_custom', function (Blueprint $table) use ($columns) {
                    $table->dropColumn($columns);
                });
            }
        }

        Schema::dropIfExists('capaian_phrase_defaults');
    }
};

<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class CleanupStagingSchoolData extends Command
{
    private const CONFIRMATION = 'RESET DATA SEKOLAH STAGING';

    protected $signature = 'staging:cleanup-school-data
        {--scope=dummy : Cleanup scope: dummy or academic}
        {--apply : Actually delete/detach data. Without this option the command only previews.}
        {--confirm= : Required typed confirmation for --apply}
        {--include-templates : Delete report template rows in the selected scope instead of keeping/detaching them}';

    protected $description = 'Preview or apply staging-only cleanup for dummy load-test data or school academic data before import/review.';

    public function handle(): int
    {
        if (! $this->isAllowedEnvironment()) {
            $this->error('Command ini hanya boleh dijalankan di local, testing, staging, atau saat STAGING_TEST_TOOLS_ENABLED=true.');

            return self::FAILURE;
        }

        $scope = strtolower((string) $this->option('scope'));
        if (! in_array($scope, ['dummy', 'academic'], true)) {
            $this->error('Scope tidak valid. Gunakan --scope=dummy atau --scope=academic.');

            return self::FAILURE;
        }

        $apply = (bool) $this->option('apply');
        if ($apply && (string) $this->option('confirm') !== self::CONFIRMATION) {
            $this->error('Konfirmasi tidak valid. Gunakan --confirm="'.self::CONFIRMATION.'" untuk menjalankan cleanup.');

            return self::FAILURE;
        }

        $plan = $scope === 'academic'
            ? $this->buildAcademicResetPlan((bool) $this->option('include-templates'))
            : $this->buildDummyCleanupPlan((bool) $this->option('include-templates'));

        $this->displayPlan($scope, $apply, $plan);

        if (! $apply) {
            $this->info('DRY RUN: tidak ada data yang dihapus atau diubah.');
            $this->line('Apply command: php artisan staging:cleanup-school-data --scope='.$scope.' --apply --confirm="'.self::CONFIRMATION.'"');

            return self::SUCCESS;
        }

        DB::transaction(function () use (&$plan): void {
            foreach ($plan['actions'] as &$action) {
                $action['applied'] = $this->applyAction($action);
            }
            unset($action);
        });

        $this->info('Cleanup staging selesai diterapkan dalam transaksi database.');
        $this->table(
            ['Target', 'Action', 'Rows'],
            collect($plan['actions'])->map(fn (array $action) => [
                $action['label'],
                $action['operation'],
                $action['applied'] ?? $action['count'],
            ])->all()
        );

        return self::SUCCESS;
    }

    private function isAllowedEnvironment(): bool
    {
        $environment = (string) config('app.env');

        return in_array($environment, ['local', 'testing', 'staging'], true)
            || (bool) config('staging_test_tools.enabled');
    }

    /**
     * @return array{actions: array<int, array<string, mixed>>, notes: array<int, string>}
     */
    private function buildDummyCleanupPlan(bool $includeTemplates): array
    {
        $guruIds = $this->mergeIds(
            $this->idsByLike('gurus', 'nama', ['Wali Load Test%', 'Guru Dummy Simulasi Load Test%']),
            $this->idsByLike('gurus', 'username', ['wali_load_test_%', 'dummy_simulasi_load'])
        );
        $classIds = $this->idsByLike('kelas', 'nama_kelas', ['Kelas Load Test%', 'Kelas Simulasi Load Test']);
        $studentIds = $this->mergeIds(
            $this->idsByLike('siswas', 'nama', ['Siswa Load Test%', 'Siswa Dummy Simulasi Load Test%']),
            $this->idsWhereIn('siswas', 'kelas_id', $classIds)
        );
        $subjectIds = $this->mergeIds(
            $this->idsByLike('mata_pelajarans', 'nama_pelajaran', ['Load Test%', 'Mapel Dummy Simulasi Load Test']),
            $this->idsWhereIn('mata_pelajarans', 'kelas_id', $classIds),
            $this->idsWhereIn('mata_pelajarans', 'guru_id', $guruIds)
        );
        $lingkupMateriIds = $this->mergeIds(
            $this->idsWhereIn('lingkup_materis', 'mata_pelajaran_id', $subjectIds),
            $this->idsByLike('lingkup_materis', 'judul_lingkup_materi', ['Load Test%', '%Simulasi Load Test%'])
        );
        $tujuanPembelajaranIds = $this->mergeIds(
            $this->idsWhereIn('tujuan_pembelajarans', 'lingkup_materi_id', $lingkupMateriIds),
            $this->idsByLike('tujuan_pembelajarans', 'deskripsi_tp', ['%Load Test%', '%Simulasi Load Test%'])
        );
        $ekstrakurikulerIds = $this->mergeIds(
            $this->idsByLike('ekstrakurikulers', 'nama_ekstrakurikuler', ['Load Test%', '%Simulasi Load Test%']),
            $this->idsByLike('ekstrakurikulers', 'pembina', ['Wali Load Test%', 'Guru Dummy Simulasi Load Test%'])
        );

        $actions = $this->academicDependencyActions(
            studentIds: $studentIds,
            classIds: $classIds,
            subjectIds: $subjectIds,
            lingkupMateriIds: $lingkupMateriIds,
            tujuanPembelajaranIds: $tujuanPembelajaranIds,
            guruIds: $guruIds,
            ekstrakurikulerIds: $ekstrakurikulerIds,
            includeTemplates: $includeTemplates
        );

        $actions[] = $this->deleteAction('tujuan_pembelajarans', $tujuanPembelajaranIds, 'tujuan_pembelajarans');
        $actions[] = $this->deleteAction('lingkup_materis', $lingkupMateriIds, 'lingkup_materis');
        $actions[] = $this->deleteAction('mata_pelajarans', $subjectIds, 'mata_pelajarans');
        $actions[] = $this->deleteAction('siswas', $studentIds, 'siswas');
        $actions[] = $this->deleteAction('kelas', $classIds, 'kelas');
        $actions[] = $this->deleteAction('gurus', $guruIds, 'dummy guru accounts');

        return [
            'actions' => $this->filterActions($actions),
            'notes' => [
                'Scope dummy hanya menargetkan nama/prefix load-test/simulasi yang jelas.',
                'Akun admin/user tidak disentuh. Akun guru dummy load-test ikut dihapus karena dibuat khusus oleh staging tools.',
                'Report templates disimpan secara default; tautan template ke kelas dummy dilepas agar tidak menunjuk kelas yang dihapus.',
            ],
        ];
    }

    /**
     * @return array{actions: array<int, array<string, mixed>>, notes: array<int, string>}
     */
    private function buildAcademicResetPlan(bool $includeTemplates): array
    {
        $classIds = $this->allIds('kelas');
        $studentIds = $this->allIds('siswas');
        $subjectIds = $this->allIds('mata_pelajarans');
        $lingkupMateriIds = $this->allIds('lingkup_materis');
        $tujuanPembelajaranIds = $this->allIds('tujuan_pembelajarans');
        $ekstrakurikulerIds = $this->allIds('ekstrakurikulers');

        $actions = $this->academicDependencyActions(
            studentIds: $studentIds,
            classIds: $classIds,
            subjectIds: $subjectIds,
            lingkupMateriIds: $lingkupMateriIds,
            tujuanPembelajaranIds: $tujuanPembelajaranIds,
            guruIds: [],
            ekstrakurikulerIds: $ekstrakurikulerIds,
            includeTemplates: $includeTemplates
        );

        $actions[] = $this->deleteAction('capaian_phrase_defaults', $this->allIds('capaian_phrase_defaults'), 'capaian_phrase_defaults');
        $actions[] = $this->deleteAction('bobot_nilais', $this->allIds('bobot_nilais'), 'bobot_nilais');
        $actions[] = $this->deleteAction('tujuan_pembelajarans', $tujuanPembelajaranIds, 'tujuan_pembelajarans');
        $actions[] = $this->deleteAction('lingkup_materis', $lingkupMateriIds, 'lingkup_materis');
        $actions[] = $this->deleteAction('mata_pelajarans', $subjectIds, 'mata_pelajarans');
        $actions[] = $this->deleteAction('ekstrakurikulers', $ekstrakurikulerIds, 'ekstrakurikulers');
        $actions[] = $this->deleteAction('siswas', $studentIds, 'siswas');
        $actions[] = $this->deleteAction('kelas', $classIds, 'kelas');

        return [
            'actions' => $this->filterActions($actions),
            'notes' => [
                'Scope academic menghapus data akademik sekolah untuk persiapan import ulang, tetapi tetap mempertahankan users, gurus, tahun_ajarans, profil sekolah, dan core settings.',
                'Report templates disimpan secara default. Jika template terkait kelas yang dihapus, class link/pivot dilepas dan admin perlu menautkan ulang template setelah kelas baru dibuat.',
                'Gunakan scope academic hanya jika cleanup dummy saja belum cukup untuk final review.',
            ],
        ];
    }

    /**
     * @param array<int, int> $studentIds
     * @param array<int, int> $classIds
     * @param array<int, int> $subjectIds
     * @param array<int, int> $lingkupMateriIds
     * @param array<int, int> $tujuanPembelajaranIds
     * @param array<int, int> $guruIds
     * @param array<int, int> $ekstrakurikulerIds
     * @return array<int, array<string, mixed>>
     */
    private function academicDependencyActions(
        array $studentIds,
        array $classIds,
        array $subjectIds,
        array $lingkupMateriIds,
        array $tujuanPembelajaranIds,
        array $guruIds,
        array $ekstrakurikulerIds,
        bool $includeTemplates
    ): array {
        $nilaiIds = $this->mergeIds(
            $this->idsWhereIn('nilais', 'siswa_id', $studentIds),
            $this->idsWhereIn('nilais', 'mata_pelajaran_id', $subjectIds),
            $this->idsWhereIn('nilais', 'lingkup_materi_id', $lingkupMateriIds),
            $this->idsWhereIn('nilais', 'tujuan_pembelajaran_id', $tujuanPembelajaranIds)
        );
        $nilaiEkstrakurikulerIds = $this->mergeIds(
            $this->idsWhereIn('nilai_ekstrakurikuler', 'siswa_id', $studentIds),
            $this->idsWhereIn('nilai_ekstrakurikuler', 'ekstrakurikuler_id', $ekstrakurikulerIds)
        );
        $reportGenerationIds = $this->mergeIds(
            $this->idsWhereIn('report_generations', 'siswa_id', $studentIds),
            $this->idsWhereIn('report_generations', 'kelas_id', $classIds),
            $this->idsWhereIn('report_generations', 'generated_by', $guruIds)
        );
        $reportTemplateIds = $this->mergeIds(
            $this->idsWhereIn('report_templates', 'kelas_id', $classIds),
            $this->idsWhereIn('report_templates', 'id', $this->idsWhereIn('report_template_kelas', 'kelas_id', $classIds, 'report_template_id'))
        );

        return [
            $this->deleteAction('report_generations', $reportGenerationIds, 'report_generations'),
            $this->deleteAction('nilais', $nilaiIds, 'nilais'),
            $this->deleteAction('absensis', $this->idsWhereIn('absensis', 'siswa_id', $studentIds), 'absensis'),
            $this->deleteAction('catatan_siswa', $this->idsWhereIn('catatan_siswa', 'siswa_id', $studentIds), 'catatan_siswa'),
            $this->deleteAction('catatan_mata_pelajaran', $this->mergeIds(
                $this->idsWhereIn('catatan_mata_pelajaran', 'siswa_id', $studentIds),
                $this->idsWhereIn('catatan_mata_pelajaran', 'mata_pelajaran_id', $subjectIds)
            ), 'catatan_mata_pelajaran'),
            $this->deleteAction('capaian_custom', $this->mergeIds(
                $this->idsWhereIn('capaian_custom', 'siswa_id', $studentIds),
                $this->idsWhereIn('capaian_custom', 'mata_pelajaran_id', $subjectIds)
            ), 'capaian_custom'),
            $this->deleteAction('prestasis', $this->mergeIds(
                $this->idsWhereIn('prestasis', 'siswa_id', $studentIds),
                $this->idsWhereIn('prestasis', 'kelas_id', $classIds)
            ), 'prestasis'),
            $this->deleteAction('nilai_ekstrakurikuler', $nilaiEkstrakurikulerIds, 'nilai_ekstrakurikuler'),
            $this->deleteAction('kkms', $this->mergeIds(
                $this->idsWhereIn('kkms', 'kelas_id', $classIds),
                $this->idsWhereIn('kkms', 'mata_pelajaran_id', $subjectIds)
            ), 'kkms'),
            $this->deleteAction('guru_kelas', $this->mergeIds(
                $this->idsWhereIn('guru_kelas', 'kelas_id', $classIds),
                $this->idsWhereIn('guru_kelas', 'guru_id', $guruIds)
            ), 'guru_kelas assignments'),
            $this->deleteAction('siswa_kelas_semester', $this->mergeIds(
                $this->idsWhereIn('siswa_kelas_semester', 'siswa_id', $studentIds),
                $this->idsWhereIn('siswa_kelas_semester', 'kelas_id', $classIds)
            ), 'siswa_kelas_semester'),
            $this->deleteAction('report_template_kelas', $this->idsWhereIn('report_template_kelas', 'kelas_id', $classIds), 'report_template_kelas'),
            $includeTemplates
                ? $this->deleteAction('report_templates', $reportTemplateIds, 'report_templates')
                : $this->updateNullAction('report_templates', $reportTemplateIds, 'kelas_id', 'report_templates.kelas_id detach'),
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $actions
     * @return array<int, array<string, mixed>>
     */
    private function filterActions(array $actions): array
    {
        return collect($actions)
            ->filter(fn (array $action) => $action['available'])
            ->values()
            ->all();
    }

    /**
     * @param array<int, int> $ids
     * @return array<string, mixed>
     */
    private function deleteAction(string $table, array $ids, string $label): array
    {
        return [
            'type' => 'delete',
            'operation' => 'delete',
            'table' => $table,
            'ids' => $ids,
            'label' => $label,
            'count' => count($ids),
            'available' => $this->hasTableAndId($table),
        ];
    }

    /**
     * @param array<int, int> $ids
     * @return array<string, mixed>
     */
    private function updateNullAction(string $table, array $ids, string $column, string $label): array
    {
        return [
            'type' => 'update_null',
            'operation' => 'detach',
            'table' => $table,
            'column' => $column,
            'ids' => $ids,
            'label' => $label,
            'count' => count($ids),
            'available' => $this->hasTableAndId($table) && Schema::hasColumn($table, $column),
        ];
    }

    /**
     * @param array<string, mixed> $action
     */
    private function applyAction(array $action): int
    {
        if ($action['count'] === 0 || empty($action['ids'])) {
            return 0;
        }

        if ($action['type'] === 'update_null') {
            return DB::table($action['table'])
                ->whereIn('id', $action['ids'])
                ->update([
                    $action['column'] => null,
                    'updated_at' => now(),
                ]);
        }

        return DB::table($action['table'])
            ->whereIn('id', $action['ids'])
            ->delete();
    }

    /**
     * @param array{actions: array<int, array<string, mixed>>, notes: array<int, string>} $plan
     */
    private function displayPlan(string $scope, bool $apply, array $plan): void
    {
        $this->warn('BACKUP FIRST: buat snapshot database atau mysqldump staging sebelum menjalankan --apply. Command ini tidak membuat backup otomatis.');
        $this->line('Mode: '.($apply ? 'APPLY' : 'DRY RUN'));
        $this->line('Scope: '.$scope);
        $this->line('Preserved by design: users, akun admin, akun guru nyata, tahun_ajarans, profil_sekolah, dan core settings.');
        $this->line('Confirmation text for apply: '.self::CONFIRMATION);

        foreach ($plan['notes'] as $note) {
            $this->line('- '.$note);
        }

        $this->table(
            ['Target', 'Action', 'Rows'],
            collect($plan['actions'])->map(fn (array $action) => [
                $action['label'],
                $action['operation'],
                $action['count'],
            ])->all()
        );
    }

    /**
     * @return array<int, int>
     */
    private function allIds(string $table): array
    {
        if (! $this->hasTableAndId($table)) {
            return [];
        }

        return DB::table($table)->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    /**
     * @param array<int, string> $patterns
     * @return array<int, int>
     */
    private function idsByLike(string $table, string $column, array $patterns, string $idColumn = 'id'): array
    {
        if (! $this->hasTableAndColumn($table, $idColumn) || ! Schema::hasColumn($table, $column)) {
            return [];
        }

        return DB::table($table)
            ->where(function (Builder $query) use ($column, $patterns): void {
                foreach ($patterns as $pattern) {
                    $query->orWhere($column, 'like', $pattern);
                }
            })
            ->pluck($idColumn)
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /**
     * @param array<int, int> $ids
     * @return array<int, int>
     */
    private function idsWhereIn(string $table, string $column, array $ids, string $idColumn = 'id'): array
    {
        if ($ids === [] || ! $this->hasTableAndColumn($table, $idColumn) || ! Schema::hasColumn($table, $column)) {
            return [];
        }

        return DB::table($table)
            ->whereIn($column, $ids)
            ->pluck($idColumn)
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    private function hasTableAndId(string $table): bool
    {
        return $this->hasTableAndColumn($table, 'id');
    }

    private function hasTableAndColumn(string $table, string $column): bool
    {
        return Schema::hasTable($table) && Schema::hasColumn($table, $column);
    }

    /**
     * @param array<int, int> ...$idLists
     * @return array<int, int>
     */
    private function mergeIds(array ...$idLists): array
    {
        return collect($idLists)
            ->flatten()
            ->filter(fn ($id) => $id !== null)
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();
    }
}

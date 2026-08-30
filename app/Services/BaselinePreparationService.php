<?php

namespace App\Services;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

class BaselinePreparationService
{
    public const MODE_DATABASES = [
        'minimal' => 'u975086294_raporbaselineA',
        'school-structure' => 'u975086294_raporbaselineB',
    ];

    public const PRODUCTION_DATABASE = 'u975086294_rapor_digital';

    public const TARGET_YEAR = '2026/2027';

    public const TARGET_SEMESTER = 1;

    public const EXPECTED_GURUS = 18;

    public const EXPECTED_CLASSES = 12;

    public const CONTROL_TABLES = [
        'migrations',
        'users',
        'profil_sekolah',
        'settings',
        'report_placeholders',
    ];

    public const STRUCTURE_TABLES = [
        'guru_kelas',
        'kelas',
        'gurus',
        'tahun_ajarans',
    ];

    public const KNOWN_SETTINGS = [
        'active_wali_report_period',
        'kkm_notification_complete_scores_only',
    ];

    public const VOLATILE_TABLES = [
        'sessions',
        'cache',
        'cache_locks',
        'jobs',
        'failed_jobs',
        'job_batches',
        'password_reset_tokens',
        'guru_password_reset_tokens',
        'batch_downloads',
    ];

    /**
     * Child tables precede every FK parent. Query Builder deletes intentionally
     * avoid model observers and filesystem side effects.
     */
    public const CLEAR_TABLES = [
        'notification_reads',
        'notification_user_states',
        'report_template_kelas',
        'pembelajaran_siswa',
        'siswa_kelas_semester',
        'report_generations',
        'nilais',
        'absensis',
        'nilai_ekstrakurikuler',
        'prestasis',
        'catatan_siswa',
        'catatan_mata_pelajaran',
        'capaian_custom',
        'capaian_phrase_defaults',
        'capaian_templates',
        'capaian_range',
        'kkms',
        'bobot_nilais',
        'semester_snapshots',
        'notifications',
        'audit_logs',
        'gemini_chats',
        'tujuan_pembelajarans',
        'lingkup_materis',
        'pembelajarans',
        'mata_pelajarans',
        'ekstrakurikulers',
        'siswas',
        'report_mappings',
        'report_templates',
        'format_rapor',
    ];

    private const ADMIN_TRANSIENT_FIELDS = [
        'remember_token',
        'pending_email',
        'pending_email_token_hash',
        'pending_email_expires_at',
    ];

    private const PROFILE_NORMALIZED_FIELDS = [
        'tahun_pelajaran',
        'semester',
        'jumlah_siswa',
        'guru_kelas',
        'kelas',
    ];

    public function __construct(
        private readonly BaselineDatabaseIdentity $identityInspector,
        private readonly BaselineSchemaInspector $schemaInspector,
        private readonly BaselineFileReferenceInspector $fileInspector
    ) {
    }

    public function confirmationFor(string $mode): string
    {
        $this->assertMode($mode);

        return 'PREPARE BASELINE '.$mode.' ON '.self::MODE_DATABASES[$mode];
    }

    /**
     * @return array{configured: string, physical: string}
     */
    public function assertAllowedIdentity(string $mode): array
    {
        $this->assertMode($mode);
        $identity = $this->identityInspector->inspect();

        if (
            $identity['configured'] === self::PRODUCTION_DATABASE
            || $identity['physical'] === self::PRODUCTION_DATABASE
        ) {
            throw new RuntimeException('DITOLAK: database production tidak boleh digunakan oleh command baseline.');
        }

        if ($identity['configured'] !== $identity['physical']) {
            throw new RuntimeException('DITOLAK: configured database tidak sama dengan SELECT DATABASE().');
        }

        $allowed = self::MODE_DATABASES[$mode];
        if ($identity['configured'] !== $allowed || $identity['physical'] !== $allowed) {
            throw new RuntimeException('DITOLAK: database bukan allow-list untuk mode baseline yang dipilih.');
        }

        return $identity;
    }

    /**
     * @return array<string, mixed>
     */
    public function inspect(string $mode): array
    {
        $identity = $this->assertAllowedIdentity($mode);
        $this->schemaInspector->assertCurrentSchema();
        $this->assertManifestCoverage();

        $counts = $this->tableCounts();
        $admin = $this->assertAndReadSingleAdmin();
        $profile = $this->assertAndReadSingleProfile();
        $targetYear = $this->assertAndReadTargetYear();
        $settingPlan = $this->settingsPlan();

        $retainedGuruIds = [];
        $retainedClassIds = [];
        $retainedPivotIds = [];
        $roleCounts = ['wali_kelas' => 0, 'pengajar' => 0];
        $pengajarGuruIds = [];

        if ($mode === 'school-structure') {
            $structure = $this->schoolStructurePlan((int) $targetYear->id);
            $retainedGuruIds = $structure['guru_ids'];
            $retainedClassIds = $structure['class_ids'];
            $retainedPivotIds = $structure['pivot_ids'];
            $roleCounts = $structure['role_counts'];
            $pengajarGuruIds = $structure['pengajar_guru_ids'];
        }

        $files = $this->fileInspector->inspect($retainedGuruIds);
        $missingFiles = collect($files)->sum('missing');
        if ($missingFiles > 0) {
            throw new RuntimeException(sprintf(
                'Referensi file wajib tidak lengkap (logo=%d, foto_guru=%d, tanda_tangan=%d).',
                $files['profile_logo']['missing'],
                $files['guru_photos']['missing'],
                $files['guru_signatures']['missing']
            ));
        }

        $snapshots = [
            'admin' => Arr::except((array) $admin, self::ADMIN_TRANSIENT_FIELDS),
            'profile_identity' => Arr::except((array) $profile, self::PROFILE_NORMALIZED_FIELDS),
            'target_year' => (array) $targetYear,
            'migrations' => $this->rows('migrations', 'migration'),
            'report_placeholders' => $this->rows('report_placeholders'),
            'kkm_setting_value' => $settingPlan['kkm_value'],
            'gurus' => $mode === 'school-structure'
                ? $this->rowsWhereIn('gurus', 'id', $retainedGuruIds, ['password_plain'])
                : [],
            'classes' => $mode === 'school-structure'
                ? $this->rowsWhereIn('kelas', 'id', $retainedClassIds)
                : [],
            'pivots' => $mode === 'school-structure'
                ? $this->rowsWhereIn('guru_kelas', 'id', $retainedPivotIds)
                : [],
            'files' => $files,
        ];

        $plan = [
            'mode' => $mode,
            'identity' => $identity,
            'counts' => $counts,
            'target_year_id' => (int) $targetYear->id,
            'retained_guru_ids' => $retainedGuruIds,
            'retained_class_ids' => $retainedClassIds,
            'retained_pivot_ids' => $retainedPivotIds,
            'role_counts' => $roleCounts,
            'pengajar_guru_ids' => $pengajarGuruIds,
            'settings' => $settingPlan,
            'files' => $files,
            'snapshots' => $snapshots,
        ];
        $plan['removal_counts'] = $this->removalCounts($plan);
        $plan['state_fingerprint'] = $this->stateFingerprint($plan);

        return $plan;
    }

    private function assertManifestCoverage(): void
    {
        $classified = [
            ...self::CONTROL_TABLES,
            ...self::STRUCTURE_TABLES,
            ...self::CLEAR_TABLES,
            ...self::VOLATILE_TABLES,
        ];

        if (count($classified) !== count(array_unique($classified))) {
            throw new RuntimeException('Manifest baseline memiliki klasifikasi tabel yang tumpang tindih.');
        }

        $classified = collect($classified)->sort()->values()->all();
        $expected = collect(BaselineSchemaInspector::EXPECTED_TABLES)->sort()->values()->all();

        if ($classified !== $expected) {
            throw new RuntimeException('Manifest baseline tidak mencakup persis seluruh schema aplikasi.');
        }
    }

    /**
     * @param  array<string, mixed>  $plan
     */
    public function apply(string $mode, array $plan): void
    {
        $this->assertAllowedIdentity($mode);

        DB::transaction(function () use ($mode, $plan): void {
            $this->assertAllowedIdentity($mode);
            $this->lockPreservedRows($plan);

            $freshPlan = $this->inspect($mode);
            if (! hash_equals((string) $plan['state_fingerprint'], (string) $freshPlan['state_fingerprint'])) {
                throw new RuntimeException('Data berubah setelah dry-run/preflight. Apply dibatalkan.');
            }

            $this->deleteAllRows(self::VOLATILE_TABLES);
            $this->deleteAllRows(self::CLEAR_TABLES);
            $this->reduceStructure($mode, $plan);
            $this->normalizeSettings($plan);
            $this->normalizeProfile($mode);
            $this->normalizeAdmin();

            if ($mode === 'school-structure') {
                DB::table('gurus')
                    ->whereIn('id', $plan['retained_guru_ids'])
                    ->update(['password_plain' => null]);
            }

            $this->assertPostconditions($mode, $plan);
        }, 1);
    }

    /**
     * @return array<string, int>
     */
    private function tableCounts(): array
    {
        $counts = [];

        foreach (BaselineSchemaInspector::EXPECTED_TABLES as $table) {
            $counts[$table] = DB::table($table)->count();
        }

        return $counts;
    }

    private function assertAndReadSingleAdmin(): object
    {
        if (DB::table('users')->count() !== 1) {
            throw new RuntimeException('Tabel users harus berisi tepat satu Admin.');
        }

        $admin = DB::table('users')->first();
        if ($admin === null) {
            throw new RuntimeException('Admin tidak dapat dibaca.');
        }

        return $admin;
    }

    private function assertAndReadSingleProfile(): object
    {
        if (DB::table('profil_sekolah')->count() !== 1) {
            throw new RuntimeException('Tabel profil_sekolah harus berisi tepat satu record.');
        }

        $profile = DB::table('profil_sekolah')->first();
        if ($profile === null) {
            throw new RuntimeException('Profil Sekolah tidak dapat dibaca.');
        }

        return $profile;
    }

    private function assertAndReadTargetYear(): object
    {
        $query = DB::table('tahun_ajarans')
            ->where('tahun_ajaran', self::TARGET_YEAR)
            ->where('semester', self::TARGET_SEMESTER);

        if ($query->count() !== 1) {
            throw new RuntimeException('Target Tahun Ajaran 2026/2027 semester 1 harus berjumlah tepat satu.');
        }

        $target = $query->first();
        if ($target === null || ! (bool) $target->is_active || $target->deleted_at !== null) {
            throw new RuntimeException('Target Tahun Ajaran harus aktif dan tidak berada di Data Terhapus.');
        }

        return $target;
    }

    /**
     * @return array{known_keys: array<int, string>, active_period: string, kkm_value: mixed, inserts_default_kkm: bool}
     */
    private function settingsPlan(): array
    {
        $rows = DB::table('settings')->orderBy('key')->get();
        $keys = $rows->pluck('key')->map(fn ($key): string => (string) $key)->all();
        $unknown = array_values(array_diff($keys, self::KNOWN_SETTINGS));

        if ($unknown !== []) {
            throw new RuntimeException('Ditemukan '.count($unknown).' setting tidak dikenal. Review manual diwajibkan.');
        }

        $kkm = $rows->firstWhere('key', 'kkm_notification_complete_scores_only');

        return [
            'known_keys' => self::KNOWN_SETTINGS,
            'active_period' => 'UTS',
            'kkm_value' => $kkm?->value ?? '0',
            'inserts_default_kkm' => $kkm === null,
        ];
    }

    /**
     * @return array{guru_ids: array<int, int>, class_ids: array<int, int>, pivot_ids: array<int, int>, role_counts: array{wali_kelas: int, pengajar: int}, pengajar_guru_ids: array<int, int>}
     */
    private function schoolStructurePlan(int $targetYearId): array
    {
        $guruIds = DB::table('gurus')->orderBy('id')->pluck('id')->map(fn ($id): int => (int) $id)->all();
        if (count($guruIds) !== self::EXPECTED_GURUS) {
            throw new RuntimeException('Mode school-structure membutuhkan tepat 18 Guru.');
        }

        if (DB::table('gurus')->whereNotNull('deleted_at')->exists()) {
            throw new RuntimeException('Mode school-structure menolak Guru soft-deleted.');
        }

        $classIds = DB::table('kelas')
            ->where('tahun_ajaran_id', $targetYearId)
            ->whereNull('deleted_at')
            ->orderBy('id')
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        if (count($classIds) !== self::EXPECTED_CLASSES) {
            throw new RuntimeException('Mode school-structure membutuhkan tepat 12 Kelas pada target Tahun Ajaran.');
        }

        if (DB::table('kelas')->whereNotNull('deleted_at')->exists()) {
            throw new RuntimeException('Mode school-structure menolak Kelas soft-deleted.');
        }

        $danglingGuru = DB::table('guru_kelas')
            ->leftJoin('gurus', 'guru_kelas.guru_id', '=', 'gurus.id')
            ->whereNull('gurus.id')
            ->exists();
        $danglingClass = DB::table('guru_kelas')
            ->leftJoin('kelas', 'guru_kelas.kelas_id', '=', 'kelas.id')
            ->whereNull('kelas.id')
            ->exists();
        if ($danglingGuru || $danglingClass) {
            throw new RuntimeException('Ditemukan guru_kelas yang tidak memiliki parent valid.');
        }

        if (DB::table('guru_kelas')->whereNotIn('role', ['wali_kelas', 'pengajar'])->exists()) {
            throw new RuntimeException('Ditemukan role guru_kelas yang tidak dikenal.');
        }

        if (
            DB::table('guru_kelas')->where('role', 'wali_kelas')->where('is_wali_kelas', '!=', 1)->exists()
            || DB::table('guru_kelas')->where('role', 'pengajar')->where('is_wali_kelas', '!=', 0)->exists()
        ) {
            throw new RuntimeException('Ditemukan kombinasi role/is_wali_kelas yang tidak valid.');
        }

        $duplicates = DB::table('guru_kelas')
            ->select(['guru_id', 'kelas_id', 'role'])
            ->groupBy(['guru_id', 'kelas_id', 'role'])
            ->havingRaw('COUNT(*) > 1')
            ->exists();
        if ($duplicates) {
            throw new RuntimeException('Ditemukan duplicate semantic role pada guru_kelas.');
        }

        $retained = DB::table('guru_kelas')
            ->whereIn('guru_id', $guruIds)
            ->whereIn('kelas_id', $classIds)
            ->orderBy('id')
            ->get();
        $waliCounts = $retained
            ->where('role', 'wali_kelas')
            ->where('is_wali_kelas', 1)
            ->countBy(fn (object $pivot): int => (int) $pivot->kelas_id);

        foreach ($classIds as $classId) {
            if ((int) $waliCounts->get($classId, 0) !== 1) {
                throw new RuntimeException('Setiap Kelas target harus memiliki tepat satu Wali Kelas.');
            }
        }

        $pivotPengajarGuruIds = $retained
            ->where('role', 'pengajar')
            ->pluck('guru_id')
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->sort()
            ->values()
            ->all();
        $subjectPengajarGuruIds = DB::table('mata_pelajarans')
            ->whereIn('guru_id', $guruIds)
            ->whereIn('kelas_id', $classIds)
            ->where('tahun_ajaran_id', $targetYearId)
            ->where('semester', self::TARGET_SEMESTER)
            ->whereNull('deleted_at')
            ->pluck('guru_id')
            ->map(fn ($id): int => (int) $id)
            ->all();
        $currentPengajarGuruIds = collect([...$pivotPengajarGuruIds, ...$subjectPengajarGuruIds])
            ->unique()
            ->sort()
            ->values()
            ->all();

        if ($currentPengajarGuruIds !== $pivotPengajarGuruIds) {
            throw new RuntimeException('Role Pengajar aktif belum seluruhnya terwakili oleh pivot guru_kelas yang dapat dipertahankan.');
        }

        return [
            'guru_ids' => $guruIds,
            'class_ids' => $classIds,
            'pivot_ids' => $retained->pluck('id')->map(fn ($id): int => (int) $id)->all(),
            'role_counts' => [
                'wali_kelas' => $retained->where('role', 'wali_kelas')->count(),
                'pengajar' => $retained->where('role', 'pengajar')->count(),
            ],
            'pengajar_guru_ids' => $pivotPengajarGuruIds,
        ];
    }

    /**
     * @param  array<string, mixed>  $plan
     * @return array<string, int>
     */
    private function removalCounts(array $plan): array
    {
        $remove = [];
        foreach ([...self::CLEAR_TABLES, ...self::VOLATILE_TABLES] as $table) {
            $remove[$table] = (int) $plan['counts'][$table];
        }

        $remove['guru_kelas'] = (int) $plan['counts']['guru_kelas'] - count($plan['retained_pivot_ids']);
        $remove['kelas'] = (int) $plan['counts']['kelas'] - count($plan['retained_class_ids']);
        $remove['gurus'] = (int) $plan['counts']['gurus'] - count($plan['retained_guru_ids']);
        $remove['tahun_ajarans'] = (int) $plan['counts']['tahun_ajarans'] - 1;

        return $remove;
    }

    /**
     * @param  array<string, mixed>  $plan
     */
    private function lockPreservedRows(array $plan): void
    {
        DB::table('users')->where('id', $plan['snapshots']['admin']['id'])->lockForUpdate()->get();
        DB::table('profil_sekolah')->lockForUpdate()->get();
        DB::table('tahun_ajarans')->where('id', $plan['target_year_id'])->lockForUpdate()->get();
        DB::table('settings')->lockForUpdate()->get();
        DB::table('report_placeholders')->lockForUpdate()->get();

        if ($plan['mode'] === 'school-structure') {
            DB::table('gurus')->whereIn('id', $plan['retained_guru_ids'])->lockForUpdate()->get();
            DB::table('kelas')->whereIn('id', $plan['retained_class_ids'])->lockForUpdate()->get();
            DB::table('guru_kelas')->whereIn('id', $plan['retained_pivot_ids'])->lockForUpdate()->get();
        }
    }

    /**
     * @param  array<int, string>  $tables
     */
    private function deleteAllRows(array $tables): void
    {
        foreach ($tables as $table) {
            DB::table($table)->delete();
        }
    }

    /**
     * @param  array<string, mixed>  $plan
     */
    private function reduceStructure(string $mode, array $plan): void
    {
        if ($mode === 'minimal') {
            DB::table('guru_kelas')->delete();
            DB::table('kelas')->delete();
            DB::table('gurus')->delete();
        } else {
            $this->deleteExceptIds('guru_kelas', $plan['retained_pivot_ids']);
            $this->deleteExceptIds('kelas', $plan['retained_class_ids']);
            $this->deleteExceptIds('gurus', $plan['retained_guru_ids']);
        }

        DB::table('tahun_ajarans')->where('id', '!=', $plan['target_year_id'])->delete();
    }

    /**
     * @param  array<int, int>  $ids
     */
    private function deleteExceptIds(string $table, array $ids): void
    {
        if ($ids === []) {
            DB::table($table)->delete();

            return;
        }

        DB::table($table)->whereNotIn('id', $ids)->delete();
    }

    /**
     * @param  array<string, mixed>  $plan
     */
    private function normalizeSettings(array $plan): void
    {
        $this->upsertSetting('kkm_notification_complete_scores_only', $plan['settings']['kkm_value']);
        $this->upsertSetting('active_wali_report_period', 'UTS');
    }

    private function upsertSetting(string $key, mixed $value): void
    {
        if (DB::table('settings')->where('key', $key)->exists()) {
            DB::table('settings')->where('key', $key)->update(['value' => $value]);

            return;
        }

        DB::table('settings')->insert([
            'key' => $key,
            'value' => $value,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function normalizeProfile(string $mode): void
    {
        DB::table('profil_sekolah')->update([
            'tahun_pelajaran' => self::TARGET_YEAR,
            'semester' => self::TARGET_SEMESTER,
            'jumlah_siswa' => 0,
            'guru_kelas' => $mode === 'school-structure' ? self::EXPECTED_GURUS : 0,
            'kelas' => $mode === 'school-structure' ? self::EXPECTED_CLASSES : 0,
        ]);
    }

    private function normalizeAdmin(): void
    {
        $values = [];
        foreach (self::ADMIN_TRANSIENT_FIELDS as $column) {
            if (Schema::hasColumn('users', $column)) {
                $values[$column] = null;
            }
        }

        if ($values !== []) {
            DB::table('users')->update($values);
        }
    }

    /**
     * @param  array<string, mixed>  $plan
     */
    private function assertPostconditions(string $mode, array $plan): void
    {
        $this->assertAllowedIdentity($mode);
        $this->schemaInspector->assertCurrentSchema();

        if (DB::table('users')->count() !== 1) {
            throw new RuntimeException('Postcondition gagal: users bukan tepat satu.');
        }

        $admin = DB::table('users')->first();
        if ($admin === null || Arr::except((array) $admin, self::ADMIN_TRANSIENT_FIELDS) !== $plan['snapshots']['admin']) {
            throw new RuntimeException('Postcondition gagal: identitas atau password hash Admin berubah.');
        }
        foreach (self::ADMIN_TRANSIENT_FIELDS as $field) {
            if (property_exists($admin, $field) && $admin->{$field} !== null) {
                throw new RuntimeException('Postcondition gagal: state transient Admin belum kosong.');
            }
        }

        if (DB::table('tahun_ajarans')->count() !== 1) {
            throw new RuntimeException('Postcondition gagal: Tahun Ajaran bukan tepat satu.');
        }
        $target = DB::table('tahun_ajarans')->first();
        if ($target === null || (array) $target !== $plan['snapshots']['target_year']) {
            throw new RuntimeException('Postcondition gagal: target Tahun Ajaran berubah.');
        }

        if (DB::table('profil_sekolah')->count() !== 1) {
            throw new RuntimeException('Postcondition gagal: Profil Sekolah bukan tepat satu.');
        }
        $profile = DB::table('profil_sekolah')->first();
        if ($profile === null || Arr::except((array) $profile, self::PROFILE_NORMALIZED_FIELDS) !== $plan['snapshots']['profile_identity']) {
            throw new RuntimeException('Postcondition gagal: identitas Profil Sekolah berubah.');
        }
        $expectedGuruCount = $mode === 'school-structure' ? self::EXPECTED_GURUS : 0;
        $expectedClassCount = $mode === 'school-structure' ? self::EXPECTED_CLASSES : 0;
        if (
            (string) $profile->tahun_pelajaran !== self::TARGET_YEAR
            || (int) $profile->semester !== self::TARGET_SEMESTER
            || (int) $profile->jumlah_siswa !== 0
            || (int) $profile->guru_kelas !== $expectedGuruCount
            || (int) $profile->kelas !== $expectedClassCount
        ) {
            throw new RuntimeException('Postcondition gagal: ringkasan Profil Sekolah tidak sesuai baseline.');
        }

        foreach ([...self::CLEAR_TABLES, ...self::VOLATILE_TABLES] as $table) {
            if (DB::table($table)->count() !== 0) {
                throw new RuntimeException("Postcondition gagal: {$table} belum kosong.");
            }
        }

        if ($this->rows('migrations', 'migration') !== $plan['snapshots']['migrations']) {
            throw new RuntimeException('Postcondition gagal: migration records berubah.');
        }
        if ($this->rows('report_placeholders') !== $plan['snapshots']['report_placeholders']) {
            throw new RuntimeException('Postcondition gagal: report_placeholders berubah.');
        }

        $settingRows = DB::table('settings')->orderBy('key')->get();
        if ($settingRows->pluck('key')->map(fn ($key): string => (string) $key)->all() !== collect(self::KNOWN_SETTINGS)->sort()->values()->all()) {
            throw new RuntimeException('Postcondition gagal: settings tidak persis known-setting policy.');
        }
        if (DB::table('settings')->where('key', 'active_wali_report_period')->value('value') !== 'UTS') {
            throw new RuntimeException('Postcondition gagal: active_wali_report_period bukan UTS.');
        }
        if (DB::table('settings')->where('key', 'kkm_notification_complete_scores_only')->value('value') != $plan['snapshots']['kkm_setting_value']) {
            throw new RuntimeException('Postcondition gagal: setting teknis KKM berubah.');
        }

        if ($mode === 'minimal') {
            if (DB::table('gurus')->count() !== 0 || DB::table('kelas')->count() !== 0 || DB::table('guru_kelas')->count() !== 0) {
                throw new RuntimeException('Postcondition gagal: struktur Guru/Kelas Baseline A belum kosong.');
            }
        } else {
            $this->assertSchoolStructurePostconditions($plan);
        }

        $files = $this->fileInspector->inspect($plan['retained_guru_ids']);
        if ($files !== $plan['snapshots']['files'] || collect($files)->sum('missing') !== 0) {
            throw new RuntimeException('Postcondition gagal: referensi file wajib berubah atau hilang.');
        }
    }

    /**
     * @param  array<string, mixed>  $plan
     */
    private function assertSchoolStructurePostconditions(array $plan): void
    {
        if (DB::table('gurus')->count() !== self::EXPECTED_GURUS || DB::table('gurus')->whereNotNull('deleted_at')->exists()) {
            throw new RuntimeException('Postcondition gagal: Guru Baseline B tidak valid.');
        }
        if (DB::table('gurus')->whereNotNull('password_plain')->exists()) {
            throw new RuntimeException('Postcondition gagal: password_plain Guru belum NULL.');
        }
        if ($this->rowsWhereIn('gurus', 'id', $plan['retained_guru_ids'], ['password_plain']) !== $plan['snapshots']['gurus']) {
            throw new RuntimeException('Postcondition gagal: data legitimate atau hash Guru berubah.');
        }

        if (DB::table('kelas')->count() !== self::EXPECTED_CLASSES || DB::table('kelas')->whereNotNull('deleted_at')->exists()) {
            throw new RuntimeException('Postcondition gagal: Kelas Baseline B tidak valid.');
        }
        if ($this->rowsWhereIn('kelas', 'id', $plan['retained_class_ids']) !== $plan['snapshots']['classes']) {
            throw new RuntimeException('Postcondition gagal: data Kelas target berubah.');
        }

        if ($this->rowsWhereIn('guru_kelas', 'id', $plan['retained_pivot_ids']) !== $plan['snapshots']['pivots']) {
            throw new RuntimeException('Postcondition gagal: manifest guru_kelas berubah.');
        }
        if (DB::table('guru_kelas')->count() !== count($plan['retained_pivot_ids'])) {
            throw new RuntimeException('Postcondition gagal: jumlah guru_kelas tidak sesuai manifest.');
        }

        $structure = $this->schoolStructurePlan((int) $plan['target_year_id']);
        if (
            $structure['guru_ids'] !== $plan['retained_guru_ids']
            || $structure['class_ids'] !== $plan['retained_class_ids']
            || $structure['pivot_ids'] !== $plan['retained_pivot_ids']
            || $structure['role_counts'] !== $plan['role_counts']
            || $structure['pengajar_guru_ids'] !== $plan['pengajar_guru_ids']
        ) {
            throw new RuntimeException('Postcondition gagal: struktur role Guru/Kelas berubah.');
        }
    }

    /**
     * @param  array<string, mixed>  $plan
     */
    private function stateFingerprint(array $plan): string
    {
        return hash('sha256', serialize([
            'mode' => $plan['mode'],
            'counts' => $plan['counts'],
            'target_year_id' => $plan['target_year_id'],
            'retained_guru_ids' => $plan['retained_guru_ids'],
            'retained_class_ids' => $plan['retained_class_ids'],
            'retained_pivot_ids' => $plan['retained_pivot_ids'],
            'role_counts' => $plan['role_counts'],
            'pengajar_guru_ids' => $plan['pengajar_guru_ids'],
            'settings' => $plan['settings'],
            'snapshots' => $plan['snapshots'],
        ]));
    }

    /**
     * @param  array<int, string>  $except
     * @return array<int, array<string, mixed>>
     */
    private function rows(string $table, string $orderBy = 'id', array $except = []): array
    {
        return DB::table($table)
            ->orderBy($orderBy)
            ->get()
            ->map(fn (object $row): array => Arr::except((array) $row, $except))
            ->all();
    }

    /**
     * @param  array<int, int>  $ids
     * @param  array<int, string>  $except
     * @return array<int, array<string, mixed>>
     */
    private function rowsWhereIn(string $table, string $column, array $ids, array $except = []): array
    {
        return DB::table($table)
            ->whereIn($column, $ids)
            ->orderBy($column)
            ->get()
            ->map(fn (object $row): array => Arr::except((array) $row, $except))
            ->all();
    }

    private function assertMode(string $mode): void
    {
        if (! array_key_exists($mode, self::MODE_DATABASES)) {
            throw new RuntimeException('Mode baseline tidak valid. Gunakan minimal atau school-structure.');
        }
    }
}

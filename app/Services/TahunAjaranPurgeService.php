<?php

namespace App\Services;

use App\Models\Siswa;
use App\Models\ReportTemplate;
use App\Models\TahunAjaran;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Throwable;

class TahunAjaranPurgeService
{
    public const ACTIVE_TARGET_MESSAGE = 'Tahun ajaran aktif tidak dapat dihapus permanen. Nonaktifkan atau pilih tahun ajaran lain terlebih dahulu.';
    public const NOT_ARCHIVED_MESSAGE = 'Hanya tahun ajaran yang sudah diarsipkan yang dapat dihapus permanen.';
    public const NO_ACTIVE_REPLACEMENT_MESSAGE = 'Tidak ada tahun ajaran aktif pengganti. Aktifkan periode yang akan dipakai sebelum menghapus permanen.';
    public const MULTIPLE_ACTIVE_REPLACEMENTS_MESSAGE = 'Purge dibatalkan karena terdapat lebih dari satu tahun ajaran aktif. Tentukan satu periode aktif terlebih dahulu.';
    public const STUDENT_REMAP_BLOCK_MESSAGE = 'Purge dibatalkan karena ada siswa yang belum memiliki kelas pengganti di tahun ajaran aktif.';
    public const UNRESOLVED_DEPENDENCY_MESSAGE = 'Purge dibatalkan karena ada data periode lain yang masih mengarah ke data periode ini. Hubungi administrator sistem untuk pemeriksaan data.';
    public const PROTECTED_NOTICE = 'Data tahun ajaran ini belum dapat dihapus permanen karena ada prasyarat purge yang belum aman.';
    public const FILE_CLEANUP_WARNING = 'Tahun ajaran berhasil dihapus permanen, tetapi ada file periode yang perlu dibersihkan oleh administrator sistem.';
    public const CONFIRMATION_MISMATCH_MESSAGE = 'Konfirmasi tidak sesuai. Ketik kalimat yang diminta untuk menghapus permanen.';

    public function preview(TahunAjaran $tahunAjaran): array
    {
        if (! $tahunAjaran->trashed()) {
            return $this->blockedPreview($tahunAjaran, self::NOT_ARCHIVED_MESSAGE);
        }

        if ((bool) $tahunAjaran->is_active) {
            return $this->blockedPreview($tahunAjaran, self::ACTIVE_TARGET_MESSAGE);
        }

        $activeReplacementResult = $this->activeReplacementResult((int) $tahunAjaran->id);
        if ($activeReplacementResult['message']) {
            return $this->blockedPreview($tahunAjaran, $activeReplacementResult['message']);
        }

        $activeReplacement = $activeReplacementResult['replacement'];
        $plan = $this->buildPlan($tahunAjaran, $activeReplacement);
        $blockedMessage = $this->blockedMessageFromPlan($plan);

        return $this->previewFromPlan($tahunAjaran, $activeReplacement, $plan, $blockedMessage);
    }

    public function protectionMessage(TahunAjaran $tahunAjaran): ?string
    {
        if (! $tahunAjaran->trashed()) {
            return null;
        }

        $preview = $this->preview($tahunAjaran);

        return $preview['can_purge'] ? null : ($preview['blocked_message'] ?: self::PROTECTED_NOTICE);
    }

    public function protectionMessagesFor(Collection $tahunAjarans): array
    {
        return $tahunAjarans
            ->filter(fn (TahunAjaran $tahunAjaran) => $tahunAjaran->trashed())
            ->mapWithKeys(fn (TahunAjaran $tahunAjaran) => [
                $tahunAjaran->id => $this->protectionMessage($tahunAjaran),
            ])
            ->filter()
            ->all();
    }

    public function confirmationPhrase(TahunAjaran $tahunAjaran): string
    {
        return sprintf(
            'HAPUS PERMANEN %s SEMESTER %s',
            $tahunAjaran->tahun_ajaran,
            ((int) $tahunAjaran->semester) === 1 ? 'GANJIL' : 'GENAP'
        );
    }

    public function purge(int $tahunAjaranId, string $submittedConfirmation): array
    {
        return DB::transaction(function () use ($tahunAjaranId, $submittedConfirmation) {
            $target = TahunAjaran::withTrashed()
                ->whereKey($tahunAjaranId)
                ->lockForUpdate()
                ->first();

            if (! $target) {
                throw new TahunAjaranPurgeException('Tahun ajaran tidak ditemukan.');
            }

            $expectedConfirmation = $this->confirmationPhrase($target);
            if (trim($submittedConfirmation) !== $expectedConfirmation) {
                throw new TahunAjaranPurgeException(self::CONFIRMATION_MISMATCH_MESSAGE, [
                    'tahun_ajaran_id' => $tahunAjaranId,
                    'expected_confirmation' => $expectedConfirmation,
                    'submitted_confirmation_present' => trim($submittedConfirmation) !== '',
                ]);
            }

            if (! $target->trashed()) {
                throw new TahunAjaranPurgeException(self::NOT_ARCHIVED_MESSAGE, [
                    'tahun_ajaran_id' => $tahunAjaranId,
                ]);
            }

            if ((bool) $target->is_active) {
                throw new TahunAjaranPurgeException(self::ACTIVE_TARGET_MESSAGE, [
                    'tahun_ajaran_id' => $tahunAjaranId,
                ]);
            }

            $activeReplacementResult = $this->activeReplacementResult($tahunAjaranId, true);
            if ($activeReplacementResult['message']) {
                throw new TahunAjaranPurgeException($activeReplacementResult['message'], [
                    'tahun_ajaran_id' => $tahunAjaranId,
                    'active_replacement_ids' => $activeReplacementResult['ids'],
                ]);
            }

            $activeReplacement = $activeReplacementResult['replacement'];
            $plan = $this->buildPlan($target, $activeReplacement, true);
            $blockedMessage = $this->blockedMessageFromPlan($plan);

            if ($blockedMessage) {
                throw new TahunAjaranPurgeException($blockedMessage, [
                    'tahun_ajaran_id' => $tahunAjaranId,
                    'unresolved_references' => $plan['unresolved_references'],
                    'unresolved_student_ids' => $plan['unresolved_student_ids'],
                ]);
            }

            $this->remapStudentClassPointers($plan);
            $this->assertNoStudentsReferenceTarget($plan);
            $this->deleteTargetOwnedRows($plan);

            $targetSnapshot = $target->attributesToArray();
            $target->forceDelete();

            if (Schema::hasTable('audit_logs')) {
                AuditService::log(
                    'permanent_purge',
                    TahunAjaran::class,
                    (int) $target->id,
                    'Tahun ajaran dihapus permanen melalui purge aman.',
                    $targetSnapshot,
                    [
                        'active_replacement_id' => (int) $activeReplacement->id,
                        'counts' => $plan['counts'],
                    ]
                );
            }

            return [
                'target_id' => $tahunAjaranId,
                'target_label' => $target->tahun_ajaran,
                'active_replacement_id' => (int) $activeReplacement->id,
                'template_file_paths' => $plan['template_file_paths'],
                'report_generation_file_paths' => $plan['report_generation_file_paths'],
                'report_cache_entries' => $plan['report_cache_entries'],
                'counts' => $plan['counts'],
            ];
        });
    }

    public function runPostCommitCleanupSafely(array $purgeResult): bool
    {
        $targetId = (int) ($purgeResult['target_id'] ?? 0);
        $activeReplacementId = (int) ($purgeResult['active_replacement_id'] ?? 0);
        $cleanupComplete = true;

        $categories = [
            'report_template_files' => fn () => $this->cleanupTemplateFilesAfterCommit(
                $purgeResult['template_file_paths'] ?? [],
                $targetId
            ),
            'generated_report_files' => fn () => $this->cleanupReportGenerationFilesAfterCommit(
                $purgeResult['report_generation_file_paths'] ?? [],
                $targetId
            ),
            'report_caches' => fn () => $this->cleanupReportCachesAfterCommit(
                $purgeResult['report_cache_entries'] ?? [],
                $targetId
            ),
            'target_tahun_ajaran_caches' => fn () => $this->clearTahunAjaranCachesAfterCommit($targetId),
            'active_replacement_tahun_ajaran_caches' => fn () => $this->clearTahunAjaranCachesAfterCommit($activeReplacementId),
        ];

        foreach ($categories as $category => $callback) {
            try {
                if ($callback() === false) {
                    $cleanupComplete = false;
                }
            } catch (Throwable $exception) {
                $cleanupComplete = false;

                $this->logPostCommitCleanupFailure($category, $targetId, $activeReplacementId, $exception);
            }
        }

        return $cleanupComplete;
    }

    public function finalizeSessionAfterCommitSafely(
        int $targetId,
        int $activeReplacementId,
        ?int $adminId,
        callable $currentSessionResolver,
        callable $sessionReplacer
    ): bool {
        try {
            $currentSessionYearId = $currentSessionResolver();

            if ((int) $currentSessionYearId !== $targetId) {
                return true;
            }

            $sessionReplacer($activeReplacementId);

            return true;
        } catch (Throwable $exception) {
            Log::warning('[TahunAjaranPurgeService] Session finalization failed after academic year purge.', [
                'tahun_ajaran_id' => $targetId,
                'active_replacement_id' => $activeReplacementId,
                'admin_id' => $adminId,
                'exception_class' => get_class($exception),
                'error' => $exception->getMessage(),
            ]);

            return false;
        }
    }

    public function cleanupTemplateFilesAfterCommit(array $paths, int $tahunAjaranId): bool
    {
        $allFilesCleaned = true;

        try {
            $disk = Storage::disk('public');
        } catch (Throwable $exception) {
            Log::warning('[TahunAjaranPurgeService] Report template storage disk unavailable after academic year purge.', [
                'tahun_ajaran_id' => $tahunAjaranId,
                'cleanup_category' => 'report_template_files',
                'exception_class' => get_class($exception),
                'error' => $exception->getMessage(),
            ]);

            return false;
        }

        foreach (array_values(array_unique($paths)) as $path) {
            $normalizedPath = $this->normalizePublicDiskPath($path);

            if (! $normalizedPath) {
                continue;
            }

            try {
                if ($this->remainingTemplateUsesPath($normalizedPath)) {
                    continue;
                }

                $classification = $this->classifyTemplateCleanupPath($normalizedPath);
                if ($classification === 'preserve') {
                    continue;
                }

                if ($classification === 'ambiguous') {
                    $allFilesCleaned = false;

                    Log::warning('[TahunAjaranPurgeService] Ambiguous report template file path preserved after academic year purge.', [
                        'tahun_ajaran_id' => $tahunAjaranId,
                        'path' => $normalizedPath,
                    ]);

                    continue;
                }

                if (! $disk->exists($normalizedPath)) {
                    continue;
                }

                if (! $disk->delete($normalizedPath)) {
                    $allFilesCleaned = false;

                    Log::warning('[TahunAjaranPurgeService] Failed to delete report template file after academic year purge.', [
                        'tahun_ajaran_id' => $tahunAjaranId,
                        'path' => $normalizedPath,
                    ]);
                }
            } catch (Throwable $exception) {
                $allFilesCleaned = false;

                Log::warning('[TahunAjaranPurgeService] Report template file cleanup failed after academic year purge.', [
                    'tahun_ajaran_id' => $tahunAjaranId,
                    'path' => $normalizedPath,
                    'cleanup_category' => 'report_template_files',
                    'exception_class' => get_class($exception),
                    'error' => $exception->getMessage(),
                ]);
            }
        }

        return $allFilesCleaned;
    }

    public function cleanupReportCachesAfterCommit(array $entries, int $tahunAjaranId): bool
    {
        $allCachesCleaned = true;

        foreach ($entries as $entry) {
            $siswaId = (int) ($entry['siswa_id'] ?? 0);
            $type = (string) ($entry['type'] ?? '');
            $entryTahunAjaranId = (int) ($entry['tahun_ajaran_id'] ?? 0);
            $entrySemester = (int) ($entry['semester'] ?? 0);

            if ($siswaId <= 0 || $type === '' || $entryTahunAjaranId !== $tahunAjaranId) {
                continue;
            }

            $siswa = null;
            try {
                $siswa = Siswa::find($siswaId);
            } catch (Throwable $exception) {
                $allCachesCleaned = false;

                $this->logCacheCleanupFailure('report_cache_student_lookup', $tahunAjaranId, $siswaId, $type, $exception);
            }

            if (! $siswa) {
                continue;
            }

            $semesters = in_array($entrySemester, [1, 2], true) ? [$entrySemester] : [1, 2];
            foreach ($semesters as $semester) {
                if (! $this->cleanupSingleCacheCategory('cached_pdf_file', $tahunAjaranId, $siswaId, $type, fn () => PdfCacheService::removeCachedPdf($siswa, $type, $tahunAjaranId, $semester))) {
                    $allCachesCleaned = false;
                }

                if (! $this->cleanupSingleCacheCategory('cached_docx_file', $tahunAjaranId, $siswaId, $type, fn () => PdfCacheService::removeCachedDocx($siswa, $type, $tahunAjaranId, $semester))) {
                    $allCachesCleaned = false;
                }

                $requestKey = null;
                $requestId = null;
                if (! $this->cleanupSingleCacheCategory('generation_request_key', $tahunAjaranId, $siswaId, $type, function () use ($siswa, $type, $tahunAjaranId, $semester, &$requestKey, &$requestId) {
                    $requestKey = PdfCacheService::getGenerationRequestKey($siswa, $type, $tahunAjaranId, $semester);
                    $requestId = Cache::get($requestKey);
                    Cache::forget($requestKey);
                })) {
                    $allCachesCleaned = false;
                }

                if (is_string($requestId) && $requestId !== '') {
                    if (! $this->cleanupSingleCacheCategory('generation_progress_key', $tahunAjaranId, $siswaId, $type, fn () => Cache::forget(PdfCacheService::getProgressKey($requestId)))) {
                        $allCachesCleaned = false;
                    }
                }

                if (! $this->cleanupSingleCacheCategory('auto_prepare_token_key', $tahunAjaranId, $siswaId, $type, fn () => Cache::forget(PdfCacheService::getAutoPrepareTokenKey($siswa, $type, $tahunAjaranId, $semester)))) {
                    $allCachesCleaned = false;
                }
            }
        }

        return $allCachesCleaned;
    }

    public function cleanupReportGenerationFilesAfterCommit(array $paths, int $tahunAjaranId): bool
    {
        $allFilesCleaned = true;

        try {
            $disk = Storage::disk('public');
        } catch (Throwable $exception) {
            Log::warning('[TahunAjaranPurgeService] Generated report storage disk unavailable after academic year purge.', [
                'tahun_ajaran_id' => $tahunAjaranId,
                'cleanup_category' => 'generated_report_files',
                'exception_class' => get_class($exception),
                'error' => $exception->getMessage(),
            ]);

            return false;
        }

        foreach (array_values(array_unique($paths)) as $path) {
            $normalizedPath = $this->normalizePublicDiskPath($path);

            if (! $normalizedPath) {
                continue;
            }

            try {
                if ($this->remainingReportGenerationUsesFile($normalizedPath)) {
                    continue;
                }

                if (! $disk->exists($normalizedPath)) {
                    continue;
                }

                if (! $disk->delete($normalizedPath)) {
                    $allFilesCleaned = false;

                    Log::warning('[TahunAjaranPurgeService] Failed to delete generated report file after academic year purge.', [
                        'tahun_ajaran_id' => $tahunAjaranId,
                        'path' => $normalizedPath,
                    ]);
                }
            } catch (Throwable $exception) {
                $allFilesCleaned = false;

                Log::warning('[TahunAjaranPurgeService] Generated report file cleanup failed after academic year purge.', [
                    'tahun_ajaran_id' => $tahunAjaranId,
                    'path' => $normalizedPath,
                    'cleanup_category' => 'generated_report_files',
                    'exception_class' => get_class($exception),
                    'error' => $exception->getMessage(),
                ]);
            }
        }

        return $allFilesCleaned;
    }

    private function activeReplacementResult(int $targetId, bool $lock = false): array
    {
        $query = TahunAjaran::query()
            ->where('is_active', true)
            ->whereKeyNot($targetId)
            ->orderBy('id');

        if ($lock) {
            $query->lockForUpdate();
        }

        $replacements = $query->get();
        $ids = $replacements->pluck('id')->map(fn ($id) => (int) $id)->all();

        if ($replacements->isEmpty()) {
            return [
                'replacement' => null,
                'message' => self::NO_ACTIVE_REPLACEMENT_MESSAGE,
                'ids' => [],
            ];
        }

        if ($replacements->count() > 1) {
            return [
                'replacement' => null,
                'message' => self::MULTIPLE_ACTIVE_REPLACEMENTS_MESSAGE,
                'ids' => $ids,
            ];
        }

        return [
            'replacement' => $replacements->first(),
            'message' => null,
            'ids' => $ids,
        ];
    }

    private function buildPlan(TahunAjaran $target, TahunAjaran $activeReplacement, bool $lockForPurge = false): array
    {
        $targetId = (int) $target->id;
        $activeReplacementId = (int) $activeReplacement->id;

        $targetClassIds = $lockForPurge
            ? $this->lockClassIdsForYear($targetId)
            : $this->idsWhere('kelas', 'tahun_ajaran_id', $targetId);
        $activeClassIds = $lockForPurge
            ? $this->lockClassIdsForYear($activeReplacementId)
            : $this->idsWhere('kelas', 'tahun_ajaran_id', $activeReplacementId);

        if ($lockForPurge) {
            $this->afterClassLocksAcquired($target, $activeReplacement, $targetClassIds, $activeClassIds);
        }

        $studentClassPointerIds = $lockForPurge
            ? $this->lockAffectedStudentIds($targetClassIds, $targetId)
            : $this->idsWhereIn('siswas', 'kelas_id', $targetClassIds);
        $staleStudentYearIds = $lockForPurge
            ? []
            : $this->idsWhere('siswas', 'tahun_ajaran_id', $targetId);
        $affectedStudentIds = $lockForPurge
            ? $studentClassPointerIds
            : $this->mergeIds($studentClassPointerIds, $staleStudentYearIds);

        if ($lockForPurge) {
            $this->lockRelevantEnrollmentRows($targetId, $activeReplacementId, $affectedStudentIds, $targetClassIds);
            $affectedStudentIds = $this->lockAffectedStudentIds($targetClassIds, $targetId);
            $studentClassPointerIds = $this->idsWhereIn('siswas', 'kelas_id', $targetClassIds);
            $staleStudentYearIds = $this->idsWhere('siswas', 'tahun_ajaran_id', $targetId);
        }

        $ambiguousReferences = [];

        $targetEnrollmentClassification = $this->classifyTargetOwnedIdsByYearOrReferences('siswa_kelas_semester', $targetId, [
            'kelas_id' => $targetClassIds,
        ]);
        $targetEnrollmentIds = $targetEnrollmentClassification['owned'];
        $this->addAmbiguousReference($ambiguousReferences, 'enrollment ambigu tanpa tahun ajaran', $targetEnrollmentClassification['ambiguous']);
        $targetTeacherAssignmentIds = $this->idsWhereIn('guru_kelas', 'kelas_id', $targetClassIds);
        $targetSubjectClassification = $this->classifyTargetOwnedIdsByYearOrReferences('mata_pelajarans', $targetId, [
            'kelas_id' => $targetClassIds,
        ]);
        $targetSubjectIds = $targetSubjectClassification['owned'];
        $this->addAmbiguousReference($ambiguousReferences, 'mata pelajaran ambigu tanpa tahun ajaran', $targetSubjectClassification['ambiguous']);
        $targetLmIds = $this->idsWhereIn('lingkup_materis', 'mata_pelajaran_id', $targetSubjectIds);
        $targetTpIds = $this->idsWhereIn('tujuan_pembelajarans', 'lingkup_materi_id', $targetLmIds);
        $targetPembelajaranClassification = $this->classifyNoYearIdsByReferences('pembelajarans', [
            'kelas_id' => $targetClassIds,
            'mata_pelajaran_id' => $targetSubjectIds,
        ]);
        $targetPembelajaranIds = $targetPembelajaranClassification['owned'];
        $this->addAmbiguousReference($ambiguousReferences, 'pembelajaran ambigu tanpa tahun ajaran', $targetPembelajaranClassification['ambiguous']);
        $targetPembelajaranStudentIds = $this->idsWhereIn('pembelajaran_siswa', 'pembelajaran_id', $targetPembelajaranIds);

        $targetEkskulIds = $this->idsWhere('ekstrakurikulers', 'tahun_ajaran_id', $targetId);
        $targetKkmClassification = $this->classifyTargetOwnedIdsByYearOrReferences('kkms', $targetId, [
            'kelas_id' => $targetClassIds,
            'mata_pelajaran_id' => $targetSubjectIds,
        ]);
        $targetKkmIds = $targetKkmClassification['owned'];
        $this->addAmbiguousReference($ambiguousReferences, 'KKM ambigu tanpa tahun ajaran', $targetKkmClassification['ambiguous']);
        $targetBobotIds = $this->idsWhere('bobot_nilais', 'tahun_ajaran_id', $targetId);
        $targetScoreClassification = $this->classifyTargetOwnedIdsByYearOrReferences('nilais', $targetId, [
            'mata_pelajaran_id' => $targetSubjectIds,
            'lingkup_materi_id' => $targetLmIds,
            'tujuan_pembelajaran_id' => $targetTpIds,
        ]);
        $targetScoreIds = $targetScoreClassification['owned'];
        $this->addAmbiguousReference($ambiguousReferences, 'nilai ambigu tanpa tahun ajaran', $targetScoreClassification['ambiguous']);
        $targetAttendanceIds = $this->idsWhere('absensis', 'tahun_ajaran_id', $targetId);
        $targetStudentNoteIds = $this->idsWhere('catatan_siswa', 'tahun_ajaran_id', $targetId);
        $targetSubjectNoteClassification = $this->classifyTargetOwnedIdsByYearOrReferences('catatan_mata_pelajaran', $targetId, [
            'mata_pelajaran_id' => $targetSubjectIds,
        ]);
        $targetSubjectNoteIds = $targetSubjectNoteClassification['owned'];
        $this->addAmbiguousReference($ambiguousReferences, 'catatan mapel ambigu tanpa tahun ajaran', $targetSubjectNoteClassification['ambiguous']);
        $targetCompetencyClassification = $this->classifyTargetOwnedIdsByYearOrReferences('capaian_custom', $targetId, [
            'mata_pelajaran_id' => $targetSubjectIds,
        ]);
        $targetCompetencyIds = $targetCompetencyClassification['owned'];
        $this->addAmbiguousReference($ambiguousReferences, 'capaian ambigu tanpa tahun ajaran', $targetCompetencyClassification['ambiguous']);
        $targetExtracurricularScoreClassification = $this->classifyTargetOwnedIdsByYearOrReferences('nilai_ekstrakurikuler', $targetId, [
            'ekstrakurikuler_id' => $targetEkskulIds,
        ]);
        $targetExtracurricularScoreIds = $targetExtracurricularScoreClassification['owned'];
        $this->addAmbiguousReference($ambiguousReferences, 'nilai ekstrakurikuler ambigu tanpa tahun ajaran', $targetExtracurricularScoreClassification['ambiguous']);
        $targetAchievementClassification = $this->classifyTargetOwnedIdsByYearOrReferences('prestasis', $targetId, [
            'kelas_id' => $targetClassIds,
        ]);
        $targetAchievementIds = $targetAchievementClassification['owned'];
        $this->addAmbiguousReference($ambiguousReferences, 'prestasi ambigu tanpa tahun ajaran', $targetAchievementClassification['ambiguous']);
        $targetTemplateClassification = $this->classifyTargetOwnedIdsByYearOrReferences('report_templates', $targetId, [
            'kelas_id' => $targetClassIds,
        ]);
        $targetTemplateIds = $targetTemplateClassification['owned'];
        $this->addAmbiguousReference($ambiguousReferences, 'template ambigu tanpa tahun ajaran', $targetTemplateClassification['ambiguous']);
        $targetReportMappingClassification = $this->classifyTargetOwnedIdsByYearOrReferences('report_mappings', $targetId, [
            'report_template_id' => $targetTemplateIds,
        ]);
        $targetReportMappingIds = $targetReportMappingClassification['owned'];
        $this->addAmbiguousReference($ambiguousReferences, 'mapping template ambigu tanpa tahun ajaran', $targetReportMappingClassification['ambiguous']);
        $targetTemplateClassPivotClassification = $this->targetTemplateClassPivotClassification($targetTemplateIds, $targetClassIds);
        $targetTemplateClassPivotIds = $targetTemplateClassPivotClassification['owned'];
        $this->addAmbiguousReference($ambiguousReferences, 'pivot template-kelas ambigu tanpa tahun ajaran', $targetTemplateClassPivotClassification['ambiguous']);
        $targetSnapshotIds = $this->idsWhere('semester_snapshots', 'tahun_ajaran_id', $targetId);
        $targetCapaianTemplateClassification = $this->classifyTargetOwnedIdsByYearOrReferences('capaian_templates', $targetId, [
            'kelas_id' => $targetClassIds,
            'mata_pelajaran_id' => $targetSubjectIds,
        ]);
        $targetCapaianTemplateIds = $targetCapaianTemplateClassification['owned'];
        $this->addAmbiguousReference($ambiguousReferences, 'template capaian ambigu tanpa tahun ajaran', $targetCapaianTemplateClassification['ambiguous']);
        $targetCapaianRangeClassification = $this->classifyTargetOwnedIdsByYearOrReferences('capaian_range', $targetId, [
            'kelas_id' => $targetClassIds,
            'mata_pelajaran_id' => $targetSubjectIds,
        ]);
        $targetCapaianRangeIds = $targetCapaianRangeClassification['owned'];
        $this->addAmbiguousReference($ambiguousReferences, 'range capaian ambigu tanpa tahun ajaran', $targetCapaianRangeClassification['ambiguous']);
        $targetCapaianPhraseDefaultClassification = $this->classifyTargetOwnedIdsByYearOrReferences('capaian_phrase_defaults', $targetId, [
            'kelas_id' => $targetClassIds,
            'mata_pelajaran_id' => $targetSubjectIds,
        ]);
        $targetCapaianPhraseDefaultIds = $targetCapaianPhraseDefaultClassification['owned'];
        $this->addAmbiguousReference($ambiguousReferences, 'default frasa capaian ambigu tanpa tahun ajaran', $targetCapaianPhraseDefaultClassification['ambiguous']);

        $targetReportGenerationClassification = $this->classifyTargetOwnedIdsByYearOrReferences('report_generations', $targetId, [
            'kelas_id' => $targetClassIds,
            'report_template_id' => $targetTemplateIds,
        ]);
        $targetReportGenerationIds = $targetReportGenerationClassification['owned'];
        $this->addAmbiguousReference($ambiguousReferences, 'riwayat rapor ambigu tanpa tahun ajaran', $targetReportGenerationClassification['ambiguous']);

        $affectedStudentIds = $this->mergeIds($studentClassPointerIds, $staleStudentYearIds);
        $studentRemaps = $this->studentConsistencyUpdates(
            $affectedStudentIds,
            $targetClassIds,
            $activeClassIds,
            $activeReplacementId,
            (int) $activeReplacement->semester
        );
        $unresolvedStudentIds = array_values(array_diff($affectedStudentIds, array_keys($studentRemaps)));
        $unresolvedReferences = array_filter(array_merge($ambiguousReferences, $this->unresolvedReferences(
            $targetId,
            $targetClassIds,
            $targetSubjectIds,
            $targetLmIds,
            $targetTpIds,
            $targetEkskulIds,
            $targetTemplateIds
        )), fn (int $count) => $count > 0);

        $templateFilePaths = $this->templateFilePaths($targetTemplateIds);
        $reportGenerationFilePaths = $this->reportGenerationFilePaths($targetReportGenerationIds);
        $reportCacheEntries = $this->reportCacheEntries($targetReportGenerationIds, $targetId);

        $ids = [
            'classes' => $targetClassIds,
            'enrollments' => $targetEnrollmentIds,
            'teacher_assignments' => $targetTeacherAssignmentIds,
            'subjects' => $targetSubjectIds,
            'lingkup_materi' => $targetLmIds,
            'tujuan_pembelajaran' => $targetTpIds,
            'pembelajarans' => $targetPembelajaranIds,
            'pembelajaran_students' => $targetPembelajaranStudentIds,
            'kkm' => $targetKkmIds,
            'bobot' => $targetBobotIds,
            'scores' => $targetScoreIds,
            'attendance' => $targetAttendanceIds,
            'student_notes' => $targetStudentNoteIds,
            'subject_notes' => $targetSubjectNoteIds,
            'competencies' => $targetCompetencyIds,
            'ekstrakurikuler' => $targetEkskulIds,
            'extracurricular_scores' => $targetExtracurricularScoreIds,
            'achievements' => $targetAchievementIds,
            'report_templates' => $targetTemplateIds,
            'report_mappings' => $targetReportMappingIds,
            'report_template_class_pivots' => $targetTemplateClassPivotIds,
            'report_generations' => $targetReportGenerationIds,
            'snapshots' => $targetSnapshotIds,
            'capaian_templates' => $targetCapaianTemplateIds,
            'capaian_ranges' => $targetCapaianRangeIds,
            'capaian_phrase_defaults' => $targetCapaianPhraseDefaultIds,
        ];

        $counts = $this->counts($ids, $studentRemaps);

        return [
            'target_id' => $targetId,
            'active_replacement_id' => $activeReplacementId,
            'target_class_ids' => $targetClassIds,
            'target_enrollment_ids' => $targetEnrollmentIds,
            'target_teacher_assignment_ids' => $targetTeacherAssignmentIds,
            'target_subject_ids' => $targetSubjectIds,
            'target_lm_ids' => $targetLmIds,
            'target_tp_ids' => $targetTpIds,
            'target_pembelajaran_ids' => $targetPembelajaranIds,
            'target_pembelajaran_student_ids' => $targetPembelajaranStudentIds,
            'target_kkm_ids' => $targetKkmIds,
            'target_bobot_ids' => $targetBobotIds,
            'target_score_ids' => $targetScoreIds,
            'target_attendance_ids' => $targetAttendanceIds,
            'target_student_note_ids' => $targetStudentNoteIds,
            'target_subject_note_ids' => $targetSubjectNoteIds,
            'target_competency_ids' => $targetCompetencyIds,
            'target_ekskul_ids' => $targetEkskulIds,
            'target_extracurricular_score_ids' => $targetExtracurricularScoreIds,
            'target_achievement_ids' => $targetAchievementIds,
            'target_template_ids' => $targetTemplateIds,
            'target_report_mapping_ids' => $targetReportMappingIds,
            'target_template_class_pivot_ids' => $targetTemplateClassPivotIds,
            'target_report_generation_ids' => $targetReportGenerationIds,
            'target_snapshot_ids' => $targetSnapshotIds,
            'target_capaian_template_ids' => $targetCapaianTemplateIds,
            'target_capaian_range_ids' => $targetCapaianRangeIds,
            'target_capaian_phrase_default_ids' => $targetCapaianPhraseDefaultIds,
            'student_class_pointer_ids' => $studentClassPointerIds,
            'stale_student_year_ids' => $staleStudentYearIds,
            'affected_student_ids' => $affectedStudentIds,
            'student_remaps' => $studentRemaps,
            'unresolved_student_ids' => $unresolvedStudentIds,
            'unresolved_references' => $unresolvedReferences,
            'template_file_paths' => $templateFilePaths,
            'report_generation_file_paths' => $reportGenerationFilePaths,
            'report_cache_entries' => $reportCacheEntries,
            'counts' => $counts,
        ];
    }

    private function blockedMessageFromPlan(array $plan): ?string
    {
        if ($plan['unresolved_student_ids'] !== []) {
            return self::STUDENT_REMAP_BLOCK_MESSAGE.' Jumlah siswa: '.count($plan['unresolved_student_ids']).'.';
        }

        if ($plan['unresolved_references'] !== []) {
            $count = array_sum($plan['unresolved_references']);

            return self::UNRESOLVED_DEPENDENCY_MESSAGE.' Jumlah relasi: '.$count.'.';
        }

        return null;
    }

    private function remapStudentClassPointers(array $plan): void
    {
        foreach ($plan['student_remaps'] as $siswaId => $update) {
            $values = [
                'kelas_id' => $update['kelas_id'],
                'updated_at' => now(),
            ];

            if (Schema::hasColumn('siswas', 'tahun_ajaran_id')) {
                $values['tahun_ajaran_id'] = $update['tahun_ajaran_id'];
            }

            DB::table('siswas')
                ->where('id', $siswaId)
                ->update($values);
        }
    }

    private function assertNoStudentsReferenceTarget(array $plan): void
    {
        $remainingClassPointers = $this->countWhereIn('siswas', 'kelas_id', $plan['target_class_ids']);
        $remainingYearPointers = $this->countWhere('siswas', 'tahun_ajaran_id', $plan['target_id']);
        $remaining = $remainingClassPointers + $remainingYearPointers;

        if ($remaining > 0) {
            throw new TahunAjaranPurgeException(
                self::STUDENT_REMAP_BLOCK_MESSAGE.' Jumlah siswa: '.$remaining.'.',
                [
                    'tahun_ajaran_id' => $plan['target_id'],
                    'remaining_class_pointer_count' => $remainingClassPointers,
                    'remaining_year_pointer_count' => $remainingYearPointers,
                ]
            );
        }
    }

    private function deleteTargetOwnedRows(array $plan): void
    {
        $this->deleteWhereIn('pembelajaran_siswa', 'id', $plan['target_pembelajaran_student_ids']);
        $this->deleteWhereIn('pembelajarans', 'id', $plan['target_pembelajaran_ids']);

        $this->deleteWhereIn('report_generations', 'id', $plan['target_report_generation_ids']);
        $this->deleteWhereIn('report_template_kelas', 'id', $plan['target_template_class_pivot_ids']);
        $this->deleteWhereIn('report_mappings', 'id', $plan['target_report_mapping_ids']);

        $this->deleteWhereIn('nilais', 'id', $plan['target_score_ids']);
        $this->deleteWhereIn('catatan_mata_pelajaran', 'id', $plan['target_subject_note_ids']);
        $this->deleteWhereIn('catatan_siswa', 'id', $plan['target_student_note_ids']);
        $this->deleteWhereIn('capaian_custom', 'id', $plan['target_competency_ids']);
        $this->deleteWhereIn('absensis', 'id', $plan['target_attendance_ids']);

        $this->deleteWhereIn('nilai_ekstrakurikuler', 'id', $plan['target_extracurricular_score_ids']);
        $this->deleteWhereIn('prestasis', 'id', $plan['target_achievement_ids']);

        $this->deleteWhereIn('kkms', 'id', $plan['target_kkm_ids']);
        $this->deleteWhereIn('bobot_nilais', 'id', $plan['target_bobot_ids']);
        $this->deleteWhereIn('semester_snapshots', 'id', $plan['target_snapshot_ids']);
        $this->deleteWhereIn('capaian_templates', 'id', $plan['target_capaian_template_ids']);
        $this->deleteWhereIn('capaian_range', 'id', $plan['target_capaian_range_ids']);
        $this->deleteWhereIn('capaian_phrase_defaults', 'id', $plan['target_capaian_phrase_default_ids']);

        $this->deleteWhereIn('report_templates', 'id', $plan['target_template_ids']);
        $this->deleteWhereIn('tujuan_pembelajarans', 'id', $plan['target_tp_ids']);
        $this->deleteWhereIn('lingkup_materis', 'id', $plan['target_lm_ids']);
        $this->deleteWhereIn('mata_pelajarans', 'id', $plan['target_subject_ids']);
        $this->deleteWhereIn('siswa_kelas_semester', 'id', $plan['target_enrollment_ids']);
        $this->deleteWhereIn('guru_kelas', 'id', $plan['target_teacher_assignment_ids']);
        $this->deleteWhereIn('ekstrakurikulers', 'id', $plan['target_ekskul_ids']);
        $this->deleteWhereIn('kelas', 'id', $plan['target_class_ids']);
    }

    private function studentConsistencyUpdates(
        array $studentIds,
        array $targetClassIds,
        array $activeClassIds,
        int $activeReplacementId,
        int $activeSemester
    ): array {
        if ($studentIds === [] || ! Schema::hasTable('siswa_kelas_semester')) {
            return [];
        }

        $studentColumns = ['id', 'kelas_id'];
        if (Schema::hasColumn('siswas', 'tahun_ajaran_id')) {
            $studentColumns[] = 'tahun_ajaran_id';
        }

        $students = DB::table('siswas')
            ->whereIn('id', $studentIds)
            ->get($studentColumns)
            ->keyBy('id');

        $rows = DB::table('siswa_kelas_semester')
            ->whereIn('siswa_id', $studentIds)
            ->where('tahun_ajaran_id', $activeReplacementId)
            ->where('semester', $activeSemester)
            ->get(['siswa_id', 'kelas_id'])
            ->groupBy('siswa_id');

        $activeClassLookup = array_fill_keys($activeClassIds, true);
        $targetClassLookup = array_fill_keys($targetClassIds, true);
        $updates = [];

        foreach ($studentIds as $studentId) {
            $student = $students->get($studentId);
            if (! $student) {
                continue;
            }

            $matches = $rows->get($studentId, collect());
            if ($matches->count() !== 1) {
                continue;
            }

            $kelasId = (int) $matches->first()->kelas_id;
            if (! isset($activeClassLookup[$kelasId])) {
                continue;
            }

            $currentClassId = $student->kelas_id !== null ? (int) $student->kelas_id : null;
            $pointsToTargetClass = $currentClassId !== null && isset($targetClassLookup[$currentClassId]);
            $pointsToActiveClass = $currentClassId !== null && isset($activeClassLookup[$currentClassId]);

            if (! $pointsToTargetClass && $currentClassId !== null) {
                if (! $pointsToActiveClass || $currentClassId !== $kelasId) {
                    continue;
                }
            }

            $updates[(int) $studentId] = [
                'kelas_id' => $kelasId,
                'tahun_ajaran_id' => $activeReplacementId,
            ];
        }

        return $updates;
    }

    private function lockClassIdsForYear(int $tahunAjaranId): array
    {
        if (! Schema::hasTable('kelas') || ! Schema::hasColumn('kelas', 'tahun_ajaran_id')) {
            return [];
        }

        return DB::table('kelas')
            ->where('tahun_ajaran_id', $tahunAjaranId)
            ->orderBy('id')
            ->lockForUpdate()
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();
    }

    private function lockAffectedStudentIds(array $targetClassIds, int $targetId): array
    {
        if (! Schema::hasTable('siswas')) {
            return [];
        }

        $hasClassColumn = Schema::hasColumn('siswas', 'kelas_id');
        $hasYearColumn = Schema::hasColumn('siswas', 'tahun_ajaran_id');

        if ((! $hasClassColumn || $targetClassIds === []) && ! $hasYearColumn) {
            return [];
        }

        return DB::table('siswas')
            ->where(function ($query) use ($targetClassIds, $targetId, $hasClassColumn, $hasYearColumn) {
                $hasCondition = false;

                if ($hasClassColumn && $targetClassIds !== []) {
                    $query->whereIn('kelas_id', $targetClassIds);
                    $hasCondition = true;
                }

                if ($hasYearColumn) {
                    $method = $hasCondition ? 'orWhere' : 'where';
                    $query->{$method}('tahun_ajaran_id', $targetId);
                }
            })
            ->orderBy('id')
            ->lockForUpdate()
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();
    }

    private function lockRelevantEnrollmentRows(int $targetId, int $activeReplacementId, array $affectedStudentIds, array $targetClassIds): void
    {
        if (! Schema::hasTable('siswa_kelas_semester')) {
            return;
        }

        $hasYearColumn = Schema::hasColumn('siswa_kelas_semester', 'tahun_ajaran_id');
        $hasStudentColumn = Schema::hasColumn('siswa_kelas_semester', 'siswa_id');
        $hasClassColumn = Schema::hasColumn('siswa_kelas_semester', 'kelas_id');

        if (! $hasYearColumn && ! $hasStudentColumn && ! $hasClassColumn) {
            return;
        }

        DB::table('siswa_kelas_semester')
            ->where(function ($query) use ($targetId, $activeReplacementId, $affectedStudentIds, $targetClassIds, $hasYearColumn, $hasStudentColumn, $hasClassColumn) {
                $hasCondition = false;

                if ($hasYearColumn) {
                    $query->where('tahun_ajaran_id', $targetId);
                    $hasCondition = true;

                    if ($affectedStudentIds !== [] && $hasStudentColumn) {
                        $query->orWhere(function ($query) use ($activeReplacementId, $affectedStudentIds) {
                            $query->where('tahun_ajaran_id', $activeReplacementId)
                                ->whereIn('siswa_id', $affectedStudentIds);
                        });
                    }
                }

                if ($affectedStudentIds !== [] && $hasStudentColumn) {
                    $method = $hasCondition ? 'orWhereIn' : 'whereIn';
                    $query->{$method}('siswa_id', $affectedStudentIds);
                    $hasCondition = true;
                }

                if ($targetClassIds !== [] && $hasClassColumn) {
                    if ($hasYearColumn) {
                        $query->orWhere(function ($query) use ($targetClassIds) {
                            $query->whereNull('tahun_ajaran_id')
                                ->whereIn('kelas_id', $targetClassIds);
                        });
                    } else {
                        $method = $hasCondition ? 'orWhereIn' : 'whereIn';
                        $query->{$method}('kelas_id', $targetClassIds);
                    }
                }
            })
            ->orderBy('id')
            ->lockForUpdate()
            ->get(['id']);
    }

    protected function afterClassLocksAcquired(TahunAjaran $target, TahunAjaran $activeReplacement, array $targetClassIds, array $activeClassIds): void
    {
    }

    private function unresolvedReferences(
        int $targetId,
        array $targetClassIds,
        array $targetSubjectIds,
        array $targetLmIds,
        array $targetTpIds,
        array $targetEkskulIds,
        array $targetTemplateIds
    ): array {
        $checks = [
            'enrollment kelas periode lain' => count($this->survivingYearReferenceIds('siswa_kelas_semester', $targetId, [
                'kelas_id' => $targetClassIds,
            ])),
            'mata pelajaran periode lain' => count($this->survivingYearReferenceIds('mata_pelajarans', $targetId, [
                'kelas_id' => $targetClassIds,
            ])),
            'nilai periode lain' => count($this->survivingYearReferenceIds('nilais', $targetId, [
                'mata_pelajaran_id' => $targetSubjectIds,
                'lingkup_materi_id' => $targetLmIds,
                'tujuan_pembelajaran_id' => $targetTpIds,
            ])),
            'catatan mapel periode lain' => count($this->survivingYearReferenceIds('catatan_mata_pelajaran', $targetId, [
                'mata_pelajaran_id' => $targetSubjectIds,
            ])),
            'capaian periode lain' => count($this->survivingYearReferenceIds('capaian_custom', $targetId, [
                'mata_pelajaran_id' => $targetSubjectIds,
            ])),
            'capaian template periode lain' => count($this->survivingYearReferenceIds('capaian_templates', $targetId, [
                'kelas_id' => $targetClassIds,
                'mata_pelajaran_id' => $targetSubjectIds,
            ])),
            'range capaian periode lain' => count($this->survivingYearReferenceIds('capaian_range', $targetId, [
                'kelas_id' => $targetClassIds,
                'mata_pelajaran_id' => $targetSubjectIds,
            ])),
            'default frasa capaian periode lain' => count($this->survivingYearReferenceIds('capaian_phrase_defaults', $targetId, [
                'kelas_id' => $targetClassIds,
                'mata_pelajaran_id' => $targetSubjectIds,
            ])),
            'nilai ekstrakurikuler periode lain' => count($this->survivingYearReferenceIds('nilai_ekstrakurikuler', $targetId, [
                'ekstrakurikuler_id' => $targetEkskulIds,
            ])),
            'prestasi periode lain' => count($this->survivingYearReferenceIds('prestasis', $targetId, [
                'kelas_id' => $targetClassIds,
            ])),
            'KKM periode lain' => count($this->survivingYearReferenceIds('kkms', $targetId, [
                'kelas_id' => $targetClassIds,
                'mata_pelajaran_id' => $targetSubjectIds,
            ])),
            'mapping template periode lain' => count($this->survivingYearReferenceIds('report_mappings', $targetId, [
                'report_template_id' => $targetTemplateIds,
            ])),
            'template periode lain' => count($this->survivingYearReferenceIds('report_templates', $targetId, [
                'kelas_id' => $targetClassIds,
            ])),
            'riwayat rapor periode lain' => count($this->survivingYearReferenceIds('report_generations', $targetId, [
                'kelas_id' => $targetClassIds,
                'report_template_id' => $targetTemplateIds,
            ])),
        ];

        return array_filter($checks, fn (int $count) => $count > 0);
    }

    private function survivingYearReferenceIds(string $table, int $targetId, array $references): array
    {
        $references = $this->existingReferenceSets($table, $references);

        if ($references === [] || ! Schema::hasColumn($table, 'tahun_ajaran_id')) {
            return [];
        }

        return DB::table($table)
            ->whereNotNull('tahun_ajaran_id')
            ->where('tahun_ajaran_id', '<>', $targetId)
            ->where(function ($query) use ($references) {
                foreach ($references as $column => $ids) {
                    $query->orWhereIn($column, $ids);
                }
            })
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    private function targetReportGenerationIds(int $targetId, array $targetClassIds, array $targetTemplateIds): array
    {
        return $this->classifyTargetOwnedIdsByYearOrReferences('report_generations', $targetId, [
            'kelas_id' => $targetClassIds,
            'report_template_id' => $targetTemplateIds,
        ])['owned'];
    }

    private function targetTemplateClassPivotClassification(array $targetTemplateIds, array $targetClassIds): array
    {
        if (
            ! Schema::hasTable('report_template_kelas')
            || ! Schema::hasColumn('report_template_kelas', 'report_template_id')
            || ! Schema::hasColumn('report_template_kelas', 'kelas_id')
        ) {
            return ['owned' => [], 'ambiguous' => []];
        }

        if ($targetTemplateIds === [] && $targetClassIds === []) {
            return ['owned' => [], 'ambiguous' => []];
        }

        $targetTemplateLookup = array_fill_keys($targetTemplateIds, true);
        $targetClassLookup = array_fill_keys($targetClassIds, true);
        $rows = DB::table('report_template_kelas')
            ->where(function ($query) use ($targetTemplateIds, $targetClassIds) {
                if ($targetTemplateIds !== []) {
                    $query->whereIn('report_template_id', $targetTemplateIds);
                }

                if ($targetClassIds !== []) {
                    $method = $targetTemplateIds !== [] ? 'orWhereIn' : 'whereIn';
                    $query->{$method}('kelas_id', $targetClassIds);
                }
            })
            ->get(['id', 'report_template_id', 'kelas_id']);

        $owned = [];
        $ambiguous = [];

        foreach ($rows as $row) {
            $templateId = $row->report_template_id !== null ? (int) $row->report_template_id : null;
            $kelasId = $row->kelas_id !== null ? (int) $row->kelas_id : null;
            $templateOwned = $templateId !== null && isset($targetTemplateLookup[$templateId]);
            $classOwned = $kelasId !== null && isset($targetClassLookup[$kelasId]);
            $classIsCompatible = $kelasId === null || $classOwned;

            if ($templateOwned && $classIsCompatible) {
                $owned[] = (int) $row->id;
            } elseif ($templateOwned || $classOwned) {
                $ambiguous[] = (int) $row->id;
            }
        }

        return [
            'owned' => $this->mergeIds($owned),
            'ambiguous' => $this->mergeIds($ambiguous),
        ];
    }

    private function classifyTargetOwnedIdsByYearOrReferences(string $table, int $targetId, array $references): array
    {
        $ids = $this->idsWhere($table, 'tahun_ajaran_id', $targetId);

        if (! Schema::hasTable($table)) {
            return ['owned' => $ids, 'ambiguous' => []];
        }

        if (! Schema::hasColumn($table, 'tahun_ajaran_id')) {
            return $this->classifyNoYearIdsByReferences($table, $references);
        }

        $classification = $this->classifyNoYearIdsByReferences(
            $table,
            $references,
            fn ($query) => $query->whereNull('tahun_ajaran_id')
        );

        return [
            'owned' => $this->mergeIds($ids, $classification['owned']),
            'ambiguous' => $classification['ambiguous'],
        ];
    }

    private function classifyNoYearIdsByReferences(string $table, array $references, ?callable $scope = null): array
    {
        if (! Schema::hasTable($table)) {
            return ['owned' => [], 'ambiguous' => []];
        }

        $references = $this->referenceSetsForOwnershipClassification($table, $references);
        $candidateReferences = array_filter($references, fn (array $ids) => $ids !== []);

        if ($candidateReferences === []) {
            return ['owned' => [], 'ambiguous' => []];
        }

        $columns = array_keys($references);
        $query = DB::table($table);

        if ($scope) {
            $scope($query);
        }

        $rows = $query
            ->where(function ($query) use ($candidateReferences) {
                foreach ($candidateReferences as $column => $ids) {
                    $query->orWhereIn($column, $ids);
                }
            })
            ->get(array_merge(['id'], $columns));

        $owned = [];
        $ambiguous = [];

        foreach ($rows as $row) {
            $hasTargetReference = false;
            $hasForeignReference = false;

            foreach ($references as $column => $targetIds) {
                $value = $row->{$column};

                if ($value === null) {
                    continue;
                }

                if (in_array((int) $value, $targetIds, true)) {
                    $hasTargetReference = true;
                } else {
                    $hasForeignReference = true;
                }
            }

            if ($hasTargetReference && ! $hasForeignReference) {
                $owned[] = (int) $row->id;
            } elseif ($hasTargetReference) {
                $ambiguous[] = (int) $row->id;
            }
        }

        return [
            'owned' => $this->mergeIds($owned),
            'ambiguous' => $this->mergeIds($ambiguous),
        ];
    }

    private function existingReferenceSets(string $table, array $references): array
    {
        if (! Schema::hasTable($table)) {
            return [];
        }

        $sets = [];

        foreach ($references as $column => $ids) {
            $ids = $this->mergeIds($ids);

            if ($ids !== [] && Schema::hasColumn($table, $column)) {
                $sets[$column] = $ids;
            }
        }

        return $sets;
    }

    private function referenceSetsForOwnershipClassification(string $table, array $references): array
    {
        if (! Schema::hasTable($table)) {
            return [];
        }

        $sets = [];

        foreach ($references as $column => $ids) {
            if (Schema::hasColumn($table, $column)) {
                $sets[$column] = $this->mergeIds($ids);
            }
        }

        return $sets;
    }

    private function templateFilePaths(array $templateIds): array
    {
        if ($templateIds === [] || ! Schema::hasTable('report_templates') || ! Schema::hasColumn('report_templates', 'path')) {
            return [];
        }

        return DB::table('report_templates')
            ->whereIn('id', $templateIds)
            ->pluck('path')
            ->map(fn ($path) => $this->normalizePublicDiskPath($path))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    private function reportGenerationFilePaths(array $reportGenerationIds): array
    {
        if ($reportGenerationIds === [] || ! Schema::hasTable('report_generations') || ! Schema::hasColumn('report_generations', 'generated_file')) {
            return [];
        }

        return DB::table('report_generations')
            ->whereIn('id', $reportGenerationIds)
            ->pluck('generated_file')
            ->map(fn ($path) => $this->normalizePublicDiskPath($path))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    private function reportCacheEntries(array $reportGenerationIds, int $targetId): array
    {
        if (
            $reportGenerationIds === []
            || ! Schema::hasTable('report_generations')
            || ! Schema::hasColumn('report_generations', 'siswa_id')
            || ! Schema::hasColumn('report_generations', 'type')
        ) {
            return [];
        }

        $columns = ['siswa_id', 'type'];
        if (Schema::hasColumn('report_generations', 'semester')) {
            $columns[] = 'semester';
        }

        return DB::table('report_generations')
            ->whereIn('id', $reportGenerationIds)
            ->get($columns)
            ->map(fn ($row) => [
                'siswa_id' => (int) $row->siswa_id,
                'type' => (string) $row->type,
                'tahun_ajaran_id' => $targetId,
                'semester' => isset($row->semester) ? (int) $row->semester : null,
            ])
            ->unique(fn (array $entry) => implode('_', [
                $entry['siswa_id'], $entry['type'], $entry['tahun_ajaran_id'], $entry['semester'] ?? '',
            ]))
            ->values()
            ->all();
    }

    private function counts(array $ids, array $studentRemaps): array
    {
        return [
            'classes' => count($ids['classes']),
            'student_class_remaps' => count($studentRemaps),
            'enrollments' => count($ids['enrollments']),
            'teacher_assignments' => count($ids['teacher_assignments']),
            'subjects' => count($ids['subjects']),
            'learning_scopes' => count($ids['lingkup_materi']),
            'learning_goals' => count($ids['tujuan_pembelajaran']),
            'pembelajarans' => count($ids['pembelajarans']),
            'pembelajaran_students' => count($ids['pembelajaran_students']),
            'kkm' => count($ids['kkm']),
            'bobot' => count($ids['bobot']),
            'scores' => count($ids['scores']),
            'attendance' => count($ids['attendance']),
            'student_notes' => count($ids['student_notes']),
            'subject_notes' => count($ids['subject_notes']),
            'competencies' => count($ids['competencies']),
            'extracurriculars' => count($ids['ekstrakurikuler']),
            'extracurricular_scores' => count($ids['extracurricular_scores']),
            'achievements' => count($ids['achievements']),
            'report_generations' => count($ids['report_generations']),
            'report_templates' => count($ids['report_templates']),
            'report_mappings' => count($ids['report_mappings']),
            'report_template_class_pivots' => count($ids['report_template_class_pivots']),
            'snapshots' => count($ids['snapshots']),
            'capaian_templates' => count($ids['capaian_templates']),
            'capaian_ranges' => count($ids['capaian_ranges']),
            'capaian_phrase_defaults' => count($ids['capaian_phrase_defaults']),
        ];
    }

    private function previewFromPlan(TahunAjaran $target, TahunAjaran $activeReplacement, array $plan, ?string $blockedMessage): array
    {
        return [
            'can_purge' => $blockedMessage === null,
            'blocked_message' => $blockedMessage,
            'confirmation_phrase' => $this->confirmationPhrase($target),
            'active_replacement' => [
                'id' => (int) $activeReplacement->id,
                'tahun_ajaran' => $activeReplacement->tahun_ajaran,
                'semester' => (int) $activeReplacement->semester,
                'semester_label' => ((int) $activeReplacement->semester) === 1 ? 'Ganjil' : 'Genap',
            ],
            'counts' => $plan['counts'],
            'unresolved_references' => $plan['unresolved_references'],
            'unresolved_student_count' => count($plan['unresolved_student_ids']),
        ];
    }

    private function blockedPreview(TahunAjaran $target, string $message): array
    {
        return [
            'can_purge' => false,
            'blocked_message' => $message,
            'confirmation_phrase' => $this->confirmationPhrase($target),
            'active_replacement' => null,
            'counts' => [],
            'unresolved_references' => [],
            'unresolved_student_count' => 0,
        ];
    }

    private function idsWhere(string $table, string $column, int $value): array
    {
        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $column)) {
            return [];
        }

        return DB::table($table)
            ->where($column, $value)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();
    }

    private function idsWhereIn(string $table, string $column, array $ids): array
    {
        if ($ids === [] || ! Schema::hasTable($table) || ! Schema::hasColumn($table, $column)) {
            return [];
        }

        return DB::table($table)
            ->whereIn($column, $ids)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();
    }

    private function countWhereIn(string $table, string $column, array $ids): int
    {
        if ($ids === [] || ! Schema::hasTable($table) || ! Schema::hasColumn($table, $column)) {
            return 0;
        }

        return (int) DB::table($table)->whereIn($column, $ids)->count();
    }

    private function countWhere(string $table, string $column, int $value): int
    {
        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $column)) {
            return 0;
        }

        return (int) DB::table($table)->where($column, $value)->count();
    }

    private function deleteWhereIn(string $table, string $column, array $ids): void
    {
        if ($ids === [] || ! Schema::hasTable($table) || ! Schema::hasColumn($table, $column)) {
            return;
        }

        DB::table($table)->whereIn($column, $ids)->delete();
    }

    private function mergeIds(array ...$sets): array
    {
        return collect($sets)
            ->flatten()
            ->map(fn ($id) => (int) $id)
            ->filter(fn (int $id) => $id > 0)
            ->unique()
            ->values()
            ->all();
    }

    private function normalizePublicDiskPath(?string $path): ?string
    {
        $normalized = trim(str_replace('\\', '/', (string) $path));
        $normalized = preg_replace('#/+#', '/', $normalized) ?? '';
        $normalized = ltrim($normalized, '/');

        foreach (['storage/', 'public/', 'app/public/'] as $prefix) {
            while (str_starts_with($normalized, $prefix)) {
                $normalized = substr($normalized, strlen($prefix));
            }
        }

        return $normalized !== '' ? $normalized : null;
    }

    private function classifyTemplateCleanupPath(string $normalizedPath): string
    {
        if (! str_starts_with($normalizedPath, 'templates/defaults/')) {
            return 'delete';
        }

        $filename = basename($normalizedPath);

        if (preg_match('/^template-default-(uts|uas)-[a-z0-9-]+-s[12]\.docx$/i', $filename)) {
            return 'preserve';
        }

        if (preg_match('/^semester2_.*\.docx$/i', $filename)) {
            return 'delete';
        }

        return 'ambiguous';
    }

    private function addAmbiguousReference(array &$references, string $label, array $ids): void
    {
        if ($ids === []) {
            return;
        }

        $references[$label] = count($ids);
    }

    private function cleanupSingleCacheCategory(string $category, int $tahunAjaranId, int $siswaId, string $type, callable $callback): bool
    {
        try {
            $callback();

            return true;
        } catch (Throwable $exception) {
            $this->logCacheCleanupFailure($category, $tahunAjaranId, $siswaId, $type, $exception);

            return false;
        }
    }

    private function clearTahunAjaranCachesAfterCommit(?int $tahunAjaranId = null): bool
    {
        Cache::forget('active_tahun_ajaran');
        Cache::forget('latest_tahun_ajaran');
        Cache::forget('all_tahun_ajaran_selector');
        Cache::forget('all_tahun_ajaran_selector_archived');

        if ($tahunAjaranId) {
            Cache::forget("tahun_ajaran_{$tahunAjaranId}");
        }

        return true;
    }

    private function logCacheCleanupFailure(string $category, int $tahunAjaranId, int $siswaId, string $type, Throwable $exception): void
    {
        Log::warning('[TahunAjaranPurgeService] Report cache cleanup failed after academic year purge.', [
            'tahun_ajaran_id' => $tahunAjaranId,
            'siswa_id' => $siswaId,
            'type' => $type,
            'cleanup_category' => $category,
            'exception_class' => get_class($exception),
            'error' => $exception->getMessage(),
        ]);
    }

    private function logPostCommitCleanupFailure(string $category, int $targetId, int $activeReplacementId, Throwable $exception): void
    {
        Log::warning('[TahunAjaranPurgeService] Post-commit cleanup category failed after academic year purge.', [
            'tahun_ajaran_id' => $targetId,
            'active_replacement_id' => $activeReplacementId,
            'cleanup_category' => $category,
            'exception_class' => get_class($exception),
            'error' => $exception->getMessage(),
        ]);
    }

    private function remainingTemplateUsesPath(string $normalizedPath): bool
    {
        if (! Schema::hasTable('report_templates') || ! Schema::hasColumn('report_templates', 'path')) {
            return false;
        }

        return DB::table('report_templates')
            ->pluck('path')
            ->contains(fn ($path) => $this->normalizePublicDiskPath($path) === $normalizedPath);
    }

    private function remainingReportGenerationUsesFile(string $normalizedPath): bool
    {
        if (! Schema::hasTable('report_generations') || ! Schema::hasColumn('report_generations', 'generated_file')) {
            return false;
        }

        return DB::table('report_generations')
            ->pluck('generated_file')
            ->contains(fn ($path) => $this->normalizePublicDiskPath($path) === $normalizedPath);
    }
}

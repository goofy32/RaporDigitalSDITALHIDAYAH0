<?php

namespace App\Http\Controllers;

use App\Events\NotificationCreated;
use App\Traits\RequiresTahunAjaran;
use App\Models\Kelas;
use App\Models\MataPelajaran;
use App\Models\Siswa;
use App\Models\Nilai;
use App\Models\Notification;
use App\Models\TujuanPembelajaran;
use App\Models\LingkupMateri;
use App\Models\Kkm;
use App\Models\BobotNilai;
use App\Models\TahunAjaran;
use App\Services\PdfCacheService;
use App\Services\ReportPdfAutoPrepareService;
use App\Services\SiswaKelasSemesterResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;

class ScoreController extends Controller
{
    use RequiresTahunAjaran;

    private function restoreOrUpdateNilai(array $attributes, array $nilaiData): ?Nilai
    {
        $lookupAttributes = $this->normalizeNilaiLookupAttributes($attributes);
        $existingNilai = Nilai::withTrashed()
            ->where('siswa_id', $lookupAttributes['siswa_id'])
            ->where('mata_pelajaran_id', $lookupAttributes['mata_pelajaran_id'])
            ->where('tahun_ajaran_id', $lookupAttributes['tahun_ajaran_id'])
            ->when(
                $lookupAttributes['lingkup_materi_id'] === null,
                fn ($query) => $query->whereNull('lingkup_materi_id'),
                fn ($query) => $query->where('lingkup_materi_id', $lookupAttributes['lingkup_materi_id'])
            )
            ->when(
                $lookupAttributes['tujuan_pembelajaran_id'] === null,
                fn ($query) => $query->whereNull('tujuan_pembelajaran_id'),
                fn ($query) => $query->where('tujuan_pembelajaran_id', $lookupAttributes['tujuan_pembelajaran_id'])
            )
            ->first();

        $hasMeaningfulScore = $this->hasMeaningfulScoreData($nilaiData);

        if (!$hasMeaningfulScore && !$existingNilai) {
            return null;
        }

        if ($existingNilai) {
            if ($existingNilai->trashed()) {
                if (!$hasMeaningfulScore) {
                    return null;
                }

                $existingNilai->restore();
            }

            $existingNilai->update($nilaiData);
            $existingNilai->refresh();

            if (!$this->nilaiHasPersistedScores($existingNilai)) {
                $existingNilai->delete();
                return null;
            }

            return $existingNilai;
        }

        $nilai = Nilai::create(array_merge($lookupAttributes, $nilaiData));

        if (!$this->nilaiHasPersistedScores($nilai)) {
            $nilai->delete();
            return null;
        }

        return $nilai;
    }

    private function normalizeNilaiLookupAttributes(array $attributes): array
    {
        return [
            'siswa_id' => $attributes['siswa_id'],
            'mata_pelajaran_id' => $attributes['mata_pelajaran_id'],
            'lingkup_materi_id' => $attributes['lingkup_materi_id'] ?? null,
            'tujuan_pembelajaran_id' => $attributes['tujuan_pembelajaran_id'] ?? null,
            'tahun_ajaran_id' => $attributes['tahun_ajaran_id'],
        ];
    }

    private function clearScorePdfCacheForStudents(array $studentIds, int $tahunAjaranId): array
    {
        $studentIds = collect($studentIds)
            ->map(fn ($id) => (int) $id)
            ->filter()
            ->unique()
            ->values();

        if ($studentIds->isEmpty()) {
            return [
                'students' => 0,
                'cache_invalidation_ms' => 0.0,
                'pdf_warmup_scheduling_ms' => 0.0,
                'jobs_scheduled' => 0,
            ];
        }

        $students = Siswa::whereIn('id', $studentIds)->get();

        if (! $this->scoreSaveProfilingEnabled()) {
            $startedAt = microtime(true);

            $students->each(fn (Siswa $siswa) => PdfCacheService::clearStudentCache($siswa, $tahunAjaranId, true));

            return [
                'students' => $students->count(),
                'cache_invalidation_ms' => $this->elapsedMs($startedAt),
                'pdf_warmup_scheduling_ms' => 0.0,
                'jobs_scheduled' => 0,
            ];
        }

        $cacheStartedAt = microtime(true);

        foreach ($students as $siswa) {
            foreach (['UTS', 'UAS'] as $type) {
                PdfCacheService::removeCachedPdf($siswa, $type, $tahunAjaranId);
                PdfCacheService::removeCachedDocx($siswa, $type, $tahunAjaranId);
            }

            Log::info('Student PDF cache cleared', ['siswa_id' => $siswa->id]);
        }

        $cacheInvalidationMs = $this->elapsedMs($cacheStartedAt);
        $warmupStartedAt = microtime(true);
        $jobsScheduled = 0;

        if (config('report.pdf_auto_prepare.enabled', false)) {
            $autoPrepare = app(ReportPdfAutoPrepareService::class);

            foreach ($students as $siswa) {
                $jobsScheduled += $autoPrepare->scheduleForStudent(
                    $siswa,
                    $tahunAjaranId,
                    ['UTS', 'UAS'],
                    'pdf_cache_invalidated'
                );
            }
        }

        return [
            'students' => $students->count(),
            'cache_invalidation_ms' => $cacheInvalidationMs,
            'pdf_warmup_scheduling_ms' => $this->elapsedMs($warmupStartedAt),
            'jobs_scheduled' => $jobsScheduled,
        ];
    }

    private function scoreSaveProfilingEnabled(): bool
    {
        return (bool) config('report.score_save_profiling.enabled', false)
            && in_array((string) config('app.env'), ['local', 'testing', 'staging'], true);
    }

    private function elapsedMs(float $startedAt): float
    {
        return round((microtime(true) - $startedAt) * 1000, 2);
    }

    /**
     * @param array<string, float> $steps
     */
    private function addProfileStep(array &$steps, string $step, float $durationMs): void
    {
        $steps[$step] = round(($steps[$step] ?? 0.0) + $durationMs, 2);
    }

    /**
     * @param array<string, float> $steps
     */
    private function logScoreSaveProfile(array $steps, array $context, float $startedAt, int $queryCount): void
    {
        if (! $this->scoreSaveProfilingEnabled()) {
            return;
        }

        $slowestStep = collect($steps)->sortDesc()->keys()->first();
        $baseContext = $context + [
            'query_count' => $queryCount,
            'total_duration_ms' => $this->elapsedMs($startedAt),
            'slowest_step' => $slowestStep,
        ];

        foreach ($steps as $step => $durationMs) {
            Log::info('score_save.profile_step', $baseContext + [
                'step' => $step,
                'duration_ms' => $durationMs,
            ]);
        }

        Log::info('score_save.profile_completed', $baseContext + [
            'steps' => $steps,
        ]);
    }

    private function nilaiLookupKey(int $siswaId, int $mataPelajaranId, int $tahunAjaranId, ?int $lingkupMateriId = null, ?int $tujuanPembelajaranId = null): string
    {
        return implode('|', [
            $siswaId,
            $mataPelajaranId,
            $tahunAjaranId,
            $lingkupMateriId ?? 'null',
            $tujuanPembelajaranId ?? 'null',
        ]);
    }

    /**
     * @return array<string, Nilai>
     */
    private function preloadNilaisByLogicalKey(array $studentIds, int $mataPelajaranId, int $tahunAjaranId): array
    {
        return Nilai::withTrashed()
            ->whereIn('siswa_id', $studentIds)
            ->where('mata_pelajaran_id', $mataPelajaranId)
            ->where('tahun_ajaran_id', $tahunAjaranId)
            ->orderBy('id')
            ->get()
            ->mapWithKeys(fn (Nilai $nilai) => [
                $this->nilaiLookupKey(
                    (int) $nilai->siswa_id,
                    (int) $nilai->mata_pelajaran_id,
                    (int) $nilai->tahun_ajaran_id,
                    $nilai->lingkup_materi_id !== null ? (int) $nilai->lingkup_materi_id : null,
                    $nilai->tujuan_pembelajaran_id !== null ? (int) $nilai->tujuan_pembelajaran_id : null
                ) => $nilai,
            ])
            ->all();
    }

    /**
     * @return array{lm_ids: array<int, int>, tp_ids: array<int, int>}
     */
    private function scorePayloadLearningIds(array $scores): array
    {
        $lmIds = [];
        $tpIds = [];

        foreach ($scores as $scoreData) {
            foreach (($scoreData['tp'] ?? []) as $lmId => $tpScores) {
                $lmIds[] = (int) $lmId;

                foreach ((array) $tpScores as $tpId => $_) {
                    $tpIds[] = (int) $tpId;
                }
            }

            foreach (($scoreData['lm'] ?? []) as $lmId => $_) {
                $lmIds[] = (int) $lmId;
            }
        }

        return [
            'lm_ids' => array_values(array_unique(array_filter($lmIds))),
            'tp_ids' => array_values(array_unique(array_filter($tpIds))),
        ];
    }

    private function valuesAreEqual($current, $new): bool
    {
        if ($current === null || $new === null) {
            return $current === null && $new === null;
        }

        if (is_bool($current) || is_bool($new)) {
            return (bool) $current === (bool) $new;
        }

        if (is_numeric($current) && is_numeric($new)) {
            return abs((float) $current - (float) $new) < 0.01;
        }

        return (string) $current === (string) $new;
    }

    private function nilaiDataChanged(Nilai $nilai, array $nilaiData): bool
    {
        foreach ($nilaiData as $key => $value) {
            if (! $this->valuesAreEqual($nilai->{$key}, $value)) {
                return true;
            }
        }

        return false;
    }

    private function findNilaiByLookupAttributes(array $lookupAttributes): ?Nilai
    {
        return Nilai::withTrashed()
            ->where('siswa_id', $lookupAttributes['siswa_id'])
            ->where('mata_pelajaran_id', $lookupAttributes['mata_pelajaran_id'])
            ->where('tahun_ajaran_id', $lookupAttributes['tahun_ajaran_id'])
            ->when(
                $lookupAttributes['lingkup_materi_id'] === null,
                fn ($query) => $query->whereNull('lingkup_materi_id'),
                fn ($query) => $query->where('lingkup_materi_id', $lookupAttributes['lingkup_materi_id'])
            )
            ->when(
                $lookupAttributes['tujuan_pembelajaran_id'] === null,
                fn ($query) => $query->whereNull('tujuan_pembelajaran_id'),
                fn ($query) => $query->where('tujuan_pembelajaran_id', $lookupAttributes['tujuan_pembelajaran_id'])
            )
            ->orderBy('id')
            ->first();
    }

    /**
     * @param array<string, Nilai> $existingNilais
     * @return array{nilai: ?Nilai, changed: bool}
     */
    private function restoreOrUpdatePreloadedNilai(array &$existingNilais, array $attributes, array $nilaiData): array
    {
        $lookupAttributes = $this->normalizeNilaiLookupAttributes($attributes);
        $key = $this->nilaiLookupKey(
            (int) $lookupAttributes['siswa_id'],
            (int) $lookupAttributes['mata_pelajaran_id'],
            (int) $lookupAttributes['tahun_ajaran_id'],
            $lookupAttributes['lingkup_materi_id'] !== null ? (int) $lookupAttributes['lingkup_materi_id'] : null,
            $lookupAttributes['tujuan_pembelajaran_id'] !== null ? (int) $lookupAttributes['tujuan_pembelajaran_id'] : null
        );
        $existingNilai = $existingNilais[$key] ?? null;
        $hasMeaningfulScore = $this->hasMeaningfulScoreData($nilaiData);

        if (! $hasMeaningfulScore && ! $existingNilai) {
            return ['nilai' => null, 'changed' => false];
        }

        if ($existingNilai) {
            $changed = false;

            if ($existingNilai->trashed()) {
                if (! $hasMeaningfulScore) {
                    return ['nilai' => null, 'changed' => false];
                }

                try {
                    $existingNilai->restore();
                } catch (\Exception $exception) {
                    $reloaded = $this->findNilaiByLookupAttributes($lookupAttributes);

                    if (! $reloaded || $reloaded->trashed()) {
                        throw $exception;
                    }

                    $existingNilai = $reloaded;
                }

                $changed = true;
            }

            if ($this->nilaiDataChanged($existingNilai, $nilaiData)) {
                $existingNilai->fill($nilaiData);

                try {
                    $existingNilai->save();
                } catch (\Exception $exception) {
                    $reloaded = $this->findNilaiByLookupAttributes($lookupAttributes);

                    if (! $reloaded || $this->nilaiDataChanged($reloaded, $nilaiData)) {
                        throw $exception;
                    }

                    $existingNilai = $reloaded;
                }

                $changed = true;
            }

            if (! $this->nilaiHasPersistedScores($existingNilai)) {
                if (! $existingNilai->trashed()) {
                    $existingNilai->delete();
                    $changed = true;
                }

                return ['nilai' => null, 'changed' => $changed];
            }

            $existingNilais[$key] = $existingNilai;

            return ['nilai' => $existingNilai, 'changed' => $changed];
        }

        try {
            $nilai = Nilai::create(array_merge($lookupAttributes, $nilaiData));
        } catch (\Exception $exception) {
            $nilai = $this->findNilaiByLookupAttributes($lookupAttributes);

            if (! $nilai || $this->nilaiDataChanged($nilai, $nilaiData)) {
                throw $exception;
            }
        }

        if (! $this->nilaiHasPersistedScores($nilai)) {
            $nilai->delete();

            return ['nilai' => null, 'changed' => false];
        }

        $existingNilais[$key] = $nilai;

        return ['nilai' => $nilai, 'changed' => true];
    }

    private function studentScoreSnapshot(int $siswaId, int $mataPelajaranId, int $tahunAjaranId): array
    {
        return Nilai::query()
            ->where('siswa_id', $siswaId)
            ->where('mata_pelajaran_id', $mataPelajaranId)
            ->where('tahun_ajaran_id', $tahunAjaranId)
            ->orderBy('lingkup_materi_id')
            ->orderBy('tujuan_pembelajaran_id')
            ->orderBy('id')
            ->get([
                'siswa_id',
                'mata_pelajaran_id',
                'tujuan_pembelajaran_id',
                'lingkup_materi_id',
                'nilai_tp',
                'nilai_lm',
                'nilai_akhir_semester',
                'na_tp',
                'na_lm',
                'tp_number',
                'nilai_tes',
                'nilai_non_tes',
                'nilai_akhir_rapor',
                'is_submitted',
                'tahun_ajaran_id',
            ])
            ->map(fn (Nilai $nilai) => [
                'siswa_id' => (int) $nilai->siswa_id,
                'mata_pelajaran_id' => (int) $nilai->mata_pelajaran_id,
                'tujuan_pembelajaran_id' => $nilai->tujuan_pembelajaran_id !== null ? (int) $nilai->tujuan_pembelajaran_id : null,
                'lingkup_materi_id' => $nilai->lingkup_materi_id !== null ? (int) $nilai->lingkup_materi_id : null,
                'nilai_tp' => $nilai->nilai_tp,
                'nilai_lm' => $nilai->nilai_lm,
                'nilai_akhir_semester' => $nilai->nilai_akhir_semester,
                'na_tp' => $nilai->na_tp,
                'na_lm' => $nilai->na_lm,
                'tp_number' => $nilai->tp_number,
                'nilai_tes' => $nilai->nilai_tes,
                'nilai_non_tes' => $nilai->nilai_non_tes,
                'nilai_akhir_rapor' => $nilai->nilai_akhir_rapor,
                'is_submitted' => (bool) $nilai->is_submitted,
                'tahun_ajaran_id' => (int) $nilai->tahun_ajaran_id,
            ])
            ->values()
            ->all();
    }

    private function hasMeaningfulScoreData(array $nilaiData): bool
    {
        foreach ($nilaiData as $key => $value) {
            if ($key === 'tahun_ajaran_id') {
                continue;
            }

            if ($key === 'is_submitted') {
                if ($value === true || $value === 1 || $value === '1') {
                    return true;
                }

                continue;
            }

            if ($value !== null && $value !== '') {
                return true;
            }
        }

        return false;
    }

    private function nilaiHasPersistedScores(Nilai $nilai): bool
    {
        return collect([
            $nilai->nilai_tp,
            $nilai->nilai_lm,
            $nilai->na_tp,
            $nilai->na_lm,
            $nilai->nilai_tes,
            $nilai->nilai_non_tes,
            $nilai->nilai_akhir_semester,
            $nilai->nilai_akhir_rapor,
            $nilai->is_submitted === true,
        ])->contains(function ($value) {
            if (is_bool($value)) {
                return $value === true;
            }

            return $value !== null;
        });
    }

    private function hasFilledScores(array $scores): bool
    {
        $hasFilled = false;

        array_walk_recursive($scores, function ($value) use (&$hasFilled) {
            if ($value !== '' && $value !== null && is_numeric($value)) {
                $hasFilled = true;
            }
        });

        return $hasFilled;
    }

    private function studentHasActualInput(array $scoreData): bool
    {
        return $this->hasFilledScores($scoreData['tp'] ?? [])
            || $this->hasFilledScores($scoreData['lm'] ?? [])
            || $this->normalizeScoreValue($scoreData['nilai_tes'] ?? null) !== null
            || $this->normalizeScoreValue($scoreData['nilai_non_tes'] ?? null) !== null;
    }

    private function detectIsSubmitted(
        array $tpScores,
        array $lmScores,
        ?float $nilaiTes,
        ?float $nilaiNonTes
    ): bool {
        $hasAnyTp = false;
        array_walk_recursive($tpScores, function ($value) use (&$hasAnyTp) {
            if ($value !== null && $value !== '') {
                $hasAnyTp = true;
            }
        });

        $hasAnyLm = false;
        array_walk_recursive($lmScores, function ($value) use (&$hasAnyLm) {
            if ($value !== null && $value !== '') {
                $hasAnyLm = true;
            }
        });

        return $hasAnyTp
            && $hasAnyLm
            && $nilaiTes !== null
            && $nilaiNonTes !== null;
    }

    private function getAggregateNilaiFromCollection($nilais): ?Nilai
    {
        return $nilais->first(function ($nilai) {
            return $nilai->deleted_at === null
                && is_null($nilai->lingkup_materi_id)
                && is_null($nilai->tujuan_pembelajaran_id);
        });
    }

    private function sendScoreCompletionNotification(MataPelajaran $mataPelajaran, Siswa $siswa, string $guruNama): void
    {
        $waliKelasGuru = DB::table('guru_kelas')
            ->where('kelas_id', $mataPelajaran->kelas_id)
            ->where('is_wali_kelas', true)
            ->where('role', 'wali_kelas')
            ->value('guru_id');

        if (!$waliKelasGuru) {
            return;
        }

        $mapelNama = $mataPelajaran->nama_pelajaran;
        $siswaNama = $siswa->nama;

        $notification = new Notification();
        $notification->title = "Nilai {$mapelNama} Selesai";
        $notification->content = "{$guruNama}: nilai {$mapelNama} {$siswaNama} selesai diinput";
        $notification->target = 'specific';
        $notification->specific_users = [(int) $waliKelasGuru];
        $notification->save();

        event(new NotificationCreated($notification));
    }

    private function currentSemesterForTahunAjaran(int $tahunAjaranId): ?int
    {
        return TahunAjaran::whereKey($tahunAjaranId)->value('semester');
    }

    private function isAuthorizedPengajarSubject(
        MataPelajaran $mataPelajaran,
        int $tahunAjaranId,
        ?int $semester = null
    ): bool {
        $guru = Auth::guard('guru')->user();
        $semester = $semester ?? $this->currentSemesterForTahunAjaran($tahunAjaranId);

        if (!$guru || session('selected_role') !== 'pengajar' || !$semester) {
            return false;
        }

        $mataPelajaran->loadMissing('kelas');

        return (int) $mataPelajaran->guru_id === (int) $guru->id
            && (int) $mataPelajaran->tahun_ajaran_id === $tahunAjaranId
            && (int) $mataPelajaran->semester === (int) $semester
            && $mataPelajaran->kelas
            && (int) $mataPelajaran->kelas->tahun_ajaran_id === $tahunAjaranId;
    }

    private function authorizePengajarSubjectForSave($mataPelajaranId, int $tahunAjaranId): MataPelajaran
    {
        $mataPelajaran = MataPelajaran::with('kelas')->find($mataPelajaranId);

        if (!$mataPelajaran || !$this->isAuthorizedPengajarSubject($mataPelajaran, $tahunAjaranId)) {
            abort(403);
        }

        return $mataPelajaran;
    }

    private function assertScorePayloadBelongsToSubject(Request $request, MataPelajaran $mataPelajaran, int $tahunAjaranId): void
    {
        $scores = $request->input('scores', []);

        if (!is_array($scores)) {
            abort(403);
        }

        $studentIds = collect(array_keys($scores))
            ->map(fn ($id) => (int) $id)
            ->filter()
            ->unique()
            ->values();

        if ($studentIds->isNotEmpty()) {
            $semester = (int) ($mataPelajaran->semester ?: $this->currentSemesterForTahunAjaran($tahunAjaranId));

            if (!$mataPelajaran->kelas_id || !$semester) {
                abort(403);
            }

            $authorizedStudentCount = app(SiswaKelasSemesterResolver::class)
                ->studentQueryForClass((int) $mataPelajaran->kelas_id, $tahunAjaranId, $semester, true)
                ->whereIn('siswas.id', $studentIds)
                ->count();

            if ($authorizedStudentCount !== $studentIds->count()) {
                abort(403);
            }
        }

        $lingkupMateriIds = collect();
        $tujuanPembelajaranIds = collect();

        foreach ($scores as $scoreData) {
            foreach (array_keys($scoreData['lm'] ?? []) as $lingkupMateriId) {
                $lingkupMateriIds->push((int) $lingkupMateriId);
            }

            foreach (($scoreData['tp'] ?? []) as $lingkupMateriId => $tpScores) {
                $lingkupMateriIds->push((int) $lingkupMateriId);

                foreach (array_keys($tpScores ?? []) as $tujuanPembelajaranId) {
                    $tujuanPembelajaranIds->push((int) $tujuanPembelajaranId);
                }
            }
        }

        $lingkupMateriIds = $lingkupMateriIds->filter()->unique()->values();
        $tujuanPembelajaranIds = $tujuanPembelajaranIds->filter()->unique()->values();

        if ($lingkupMateriIds->isNotEmpty()) {
            $validLingkupMateriCount = LingkupMateri::whereIn('id', $lingkupMateriIds)
                ->where('mata_pelajaran_id', $mataPelajaran->id)
                ->count();

            if ($validLingkupMateriCount !== $lingkupMateriIds->count()) {
                abort(403);
            }
        }

        if ($tujuanPembelajaranIds->isNotEmpty()) {
            $validTujuanPembelajaranCount = TujuanPembelajaran::query()
                ->join('lingkup_materis', 'tujuan_pembelajarans.lingkup_materi_id', '=', 'lingkup_materis.id')
                ->whereIn('tujuan_pembelajarans.id', $tujuanPembelajaranIds)
                ->where('lingkup_materis.mata_pelajaran_id', $mataPelajaran->id)
                ->count();

            if ($validTujuanPembelajaranCount !== $tujuanPembelajaranIds->count()) {
                abort(403);
            }
        }
    }

    private function studentsForSubjectRoster(MataPelajaran $mataPelajaran, int $tahunAjaranId)
    {
        $semester = (int) ($mataPelajaran->semester ?: $this->currentSemesterForTahunAjaran($tahunAjaranId));

        if (!$mataPelajaran->kelas_id || !$semester) {
            return collect();
        }

        return app(SiswaKelasSemesterResolver::class)
            ->studentsForClass((int) $mataPelajaran->kelas_id, $tahunAjaranId, $semester, true);
    }

    private function studentOptionsForRoster($siswas)
    {
        return $siswas->sortBy('nama')
            ->values()
            ->map(function ($siswa) {
                return [
                    'id' => $siswa->id,
                    'name' => $siswa->nama,
                ];
            });
    }

    private function authorizeDeleteNilaiRequest(Request $request, int $tahunAjaranId): array
    {
        $validated = $request->validate([
            'siswa_id' => ['required', 'integer'],
            'mata_pelajaran_id' => ['required', 'integer'],
        ]);

        $mataPelajaran = MataPelajaran::with('kelas')->find($validated['mata_pelajaran_id']);

        if (!$mataPelajaran || !$this->isAuthorizedPengajarSubject($mataPelajaran, $tahunAjaranId)) {
            abort(403);
        }

        $semester = (int) ($mataPelajaran->semester ?: $this->currentSemesterForTahunAjaran($tahunAjaranId));

        if (!$mataPelajaran->kelas_id || !$semester) {
            abort(403);
        }

        $isAuthorizedStudent = app(SiswaKelasSemesterResolver::class)
            ->isEnrolledInClass(
                (int) $validated['siswa_id'],
                (int) $mataPelajaran->kelas_id,
                $tahunAjaranId,
                $semester,
                true
            );

        if (!$isAuthorizedStudent) {
            abort(403);
        }

        return [
            'siswa_id' => (int) $validated['siswa_id'],
            'mata_pelajaran' => $mataPelajaran,
        ];
    }

    public function index()
    {
        $guru = Auth::guard('guru')->user();
        $tahunAjaranId = session('tahun_ajaran_id');
        Log::info('Guru ID: ' . $guru->id);
        
        $kelasData = Kelas::with(['mataPelajarans' => function($query) use ($guru, $tahunAjaranId) {
            $query->where('guru_id', $guru->id);
            $query->with([
                'lingkupMateris.tujuanPembelajarans',
            ])->withCount('nilais');
            if ($tahunAjaranId) {
                $query->where('tahun_ajaran_id', $tahunAjaranId);
            }
        }])
        ->whereHas('mataPelajarans', function($query) use ($guru, $tahunAjaranId) {
            $query->where('guru_id', $guru->id);
            if ($tahunAjaranId) {
                $query->where('tahun_ajaran_id', $tahunAjaranId);
            }
        })
        ->when($tahunAjaranId, function($query) use ($tahunAjaranId) {
            return $query->where('tahun_ajaran_id', $tahunAjaranId);
        })
        ->get();

        $kelasData->each(function ($kelas) {
            $kelas->mataPelajarans->each(function ($mapel) {
                $hasLm = $mapel->lingkupMateris->isNotEmpty();
                $hasCompleteTp = $hasLm && $mapel->lingkupMateris->every(function ($lm) {
                    return $lm->tujuanPembelajarans->isNotEmpty();
                });

                $mapel->setAttribute('has_lm', $hasLm);
                $mapel->setAttribute('has_complete_tp', $hasCompleteTp);
                $mapel->setAttribute('requires_lm_tp_setup', !($hasLm && $hasCompleteTp));
                $mapel->setAttribute(
                    'lm_tp_warning_message',
                    !$hasLm
                        ? 'Mata pelajaran ini belum memiliki Lingkup Materi dan Tujuan Pembelajaran. Silakan lengkapi terlebih dahulu sebelum melakukan input nilai.'
                        : 'Lengkapi Tujuan Pembelajaran pada setiap Lingkup Materi terlebih dahulu sebelum melakukan input nilai.'
                );
                $mapel->setAttribute('has_saved_scores', (int) ($mapel->nilais_count ?? 0) > 0);
            });
        });
        
        return view('pengajar.score', ['kelasData' => $kelasData]);
    }


    public function saveScore(Request $request, $id)
    {
        $profileStartedAt = microtime(true);
        $profileSteps = [];
        $profilingEnabled = $this->scoreSaveProfilingEnabled();

        if ($profilingEnabled) {
            DB::connection()->flushQueryLog();
            DB::connection()->enableQueryLog();
        }

        if ($profilingEnabled) {
            Log::info('score_save.profile_started', [
                'guru_id' => Auth::guard('guru')->id(),
                'mata_pelajaran_id' => (int) $id,
            ]);
        }

        $stepStartedAt = microtime(true);
        $tahunAjaranId = $this->getValidTahunAjaranId();
        $this->addProfileStep($profileSteps, 'request_controller_setup', $this->elapsedMs($stepStartedAt));

        if (!$tahunAjaranId) {
            if ($profilingEnabled) {
                DB::connection()->disableQueryLog();
            }

            return $this->failTahunAjaranNotSet($request, true);
        }

        $stepStartedAt = microtime(true);
        $mataPelajaran = $this->authorizePengajarSubjectForSave($id, $tahunAjaranId);
        $this->addProfileStep($profileSteps, 'authorization_context', $this->elapsedMs($stepStartedAt));

        $stepStartedAt = microtime(true);
        $this->assertScorePayloadBelongsToSubject($request, $mataPelajaran, $tahunAjaranId);
        $this->addProfileStep($profileSteps, 'validation', $this->elapsedMs($stepStartedAt));

        $scores = $request->input('scores', []);
        $studentIds = collect(array_keys(is_array($scores) ? $scores : []))
            ->map(fn ($studentId) => (int) $studentId)
            ->filter()
            ->unique()
            ->values()
            ->all();
        $rowsChanged = 0;
        $cacheStats = [
            'students' => 0,
            'cache_invalidation_ms' => 0.0,
            'pdf_warmup_scheduling_ms' => 0.0,
            'jobs_scheduled' => 0,
        ];

        try {
            $stepStartedAt = microtime(true);
            $learningIds = $this->scorePayloadLearningIds($scores);
            $studentsById = Siswa::whereIn('id', $studentIds)->get()->keyBy('id');
            $existingNilaisByKey = $this->preloadNilaisByLogicalKey($studentIds, (int) $id, (int) $tahunAjaranId);
            $existingNilaisByStudent = collect($existingNilaisByKey)->groupBy(fn (Nilai $nilai) => (int) $nilai->siswa_id);
            $tujuanPembelajarans = TujuanPembelajaran::whereIn('id', $learningIds['tp_ids'])->get()->keyBy('id');
            $lingkupMateris = LingkupMateri::whereIn('id', $learningIds['lm_ids'])->get()->keyBy('id');
            $bobotNilai = BobotNilai::getDefault();
            $this->addProfileStep($profileSteps, 'preload_context', $this->elapsedMs($stepStartedAt));

            app()->instance('score_save.defer_nilai_pdf_cache_invalidation', true);
            DB::beginTransaction();
            $savedData = [];
            $notSavedData = [];
            $newlySubmittedStudents = [];
            $affectedStudentIds = [];

            foreach($scores as $siswaId => $scoreData) {
                $siswaId = (int) $siswaId;
                $siswa = $studentsById->get($siswaId);

                if (!$siswa) {
                    continue;
                }

                $existingStudentNilais = $existingNilaisByStudent->get($siswaId, collect());
                $wasSubmitted = $existingStudentNilais->contains(
                    fn (Nilai $nilai) => ! $nilai->trashed() && (bool) $nilai->is_submitted
                );
                $hasActualInput = $this->studentHasActualInput($scoreData);
                if (!$hasActualInput && $existingStudentNilais->isEmpty()) {
                    continue;
                }

                $studentData = [
                    'nama' => $siswa->nama,
                    'nilai' => []
                ];
                $studentNotSaved = [];
                $studentChanged = false;

                if (isset($scoreData['tp']) && is_array($scoreData['tp'])) {
                    foreach($scoreData['tp'] as $lmId => $tpScores) {
                        foreach($tpScores as $tpId => $nilai) {
                            try {
                                $tp = $tujuanPembelajarans->get((int) $tpId);
                                $lm = $lingkupMateris->get((int) $lmId);

                                if (! $tp || ! $lm) {
                                    throw new \RuntimeException('TP atau Lingkup Materi tidak ditemukan.');
                                }
                                
                                $nilaiData = [
                                    'nilai_tp' => $this->normalizeScoreValue($nilai)
                                ];
                                
                                if ($tahunAjaranId) {
                                    $nilaiData['tahun_ajaran_id'] = $tahunAjaranId;
                                }

                                $stepStartedAt = microtime(true);
                                $result = $this->restoreOrUpdatePreloadedNilai(
                                    $existingNilaisByKey,
                                    [
                                        'siswa_id' => $siswaId,
                                        'mata_pelajaran_id' => $id,
                                        'lingkup_materi_id' => $lmId,
                                        'tujuan_pembelajaran_id' => $tpId,
                                        'tahun_ajaran_id' => $tahunAjaranId,
                                    ],
                                    $nilaiData
                                );
                                $this->addProfileStep($profileSteps, 'nilai_update_create', $this->elapsedMs($stepStartedAt));
                                $studentChanged = $studentChanged || $result['changed'];
                                $rowsChanged += $result['changed'] ? 1 : 0;

                                if ($nilai !== '' && $nilai !== null) {
                                    $studentData['nilai'][] = [
                                        'tipe' => 'TP',
                                        'kode' => $tp->kode_tp,
                                        'nilai' => $nilai
                                    ];
                                }
                            } catch (\Exception $e) {
                                $studentNotSaved[] = 'TP '.($tp->kode_tp ?? $tpId).": {$e->getMessage()}";
                            }
                        }
                    }
                }
                
                if (isset($scoreData['lm']) && is_array($scoreData['lm'])) {
                    foreach($scoreData['lm'] as $lmId => $nilai) {
                        try {
                            $lm = $lingkupMateris->get((int) $lmId);

                            if (! $lm) {
                                throw new \RuntimeException('Lingkup Materi tidak ditemukan.');
                            }
                            
                            $nilaiData = [
                                'nilai_lm' => $this->normalizeScoreValue($nilai)
                            ];
                            
                            if ($tahunAjaranId) {
                                $nilaiData['tahun_ajaran_id'] = $tahunAjaranId;
                            }

                            $stepStartedAt = microtime(true);
                            $result = $this->restoreOrUpdatePreloadedNilai(
                                $existingNilaisByKey,
                                [
                                    'siswa_id' => $siswaId,
                                    'mata_pelajaran_id' => $id,
                                    'lingkup_materi_id' => $lmId,
                                    'tahun_ajaran_id' => $tahunAjaranId,
                                ],
                                $nilaiData
                            );
                            $this->addProfileStep($profileSteps, 'nilai_update_create', $this->elapsedMs($stepStartedAt));
                            $studentChanged = $studentChanged || $result['changed'];
                            $rowsChanged += $result['changed'] ? 1 : 0;

                            if ($nilai !== '' && $nilai !== null) {
                                $studentData['nilai'][] = [
                                    'tipe' => 'LM',
                                    'kode' => $lm->judul_lingkup_materi,
                                    'nilai' => $nilai
                                ];
                            }
                        } catch (\Exception $e) {
                            $studentNotSaved[] = 'LM '.($lm->judul_lingkup_materi ?? $lmId).": {$e->getMessage()}";
                        }
                    }
                }

                $stepStartedAt = microtime(true);
                $finalScores = [];
                $hasTpInput = $this->hasFilledScores($scoreData['tp'] ?? []);
                $hasLmInput = $this->hasFilledScores($scoreData['lm'] ?? []);
                $naTp = $hasTpInput ? $this->calculateAverageScore($scoreData['tp'] ?? []) : null;
                $naLm = $hasLmInput ? $this->calculateAverageScore($scoreData['lm'] ?? []) : null;
                $nilaiTes = $this->normalizeScoreValue($scoreData['nilai_tes'] ?? null);
                $nilaiNonTes = $this->normalizeScoreValue($scoreData['nilai_non_tes'] ?? null);
                $isSubmitted = $this->detectIsSubmitted(
                    $scoreData['tp'] ?? [],
                    $scoreData['lm'] ?? [],
                    $nilaiTes,
                    $nilaiNonTes
                );
                $nilaiAkhirSemester = ($nilaiTes !== null && $nilaiNonTes !== null)
                    ? $this->calculateNilaiAkhirSemester($nilaiTes, $nilaiNonTes)
                    : null;
                $nilaiAkhirRapor = $nilaiAkhirSemester !== null
                    ? $this->calculateNilaiAkhirRapor($naTp ?? 0.0, $naLm ?? 0.0, $nilaiAkhirSemester, $bobotNilai)
                    : null;
                $this->addProfileStep($profileSteps, 'final_score_calculation', $this->elapsedMs($stepStartedAt));

                $finalScores = [
                    'na_tp' => $naTp,
                    'na_lm' => $naLm,
                    'nilai_tes' => $nilaiTes,
                    'nilai_non_tes' => $nilaiNonTes,
                    'nilai_akhir_semester' => $nilaiAkhirSemester,
                    'nilai_akhir_rapor' => $nilaiAkhirRapor,
                    'is_submitted' => $isSubmitted,
                ];

                if ($tahunAjaranId) {
                    $finalScores['tahun_ajaran_id'] = $tahunAjaranId;
                }
                
                try {
                    if (!empty($finalScores)) {
                        $stepStartedAt = microtime(true);
                        $result = $this->restoreOrUpdatePreloadedNilai(
                            $existingNilaisByKey,
                            [
                                'siswa_id' => $siswaId,
                                'mata_pelajaran_id' => $id,
                                'tahun_ajaran_id' => $tahunAjaranId,
                            ],
                            $finalScores
                        );
                        $this->addProfileStep($profileSteps, 'nilai_update_create', $this->elapsedMs($stepStartedAt));
                        $savedFinalNilai = $result['nilai'];
                        $studentChanged = $studentChanged || $result['changed'];
                        $rowsChanged += $result['changed'] ? 1 : 0;

                        foreach($finalScores as $key => $value) {
                            if (!in_array($key, ['tahun_ajaran_id', 'is_submitted'], true) && $value !== null) {
                                $studentData['nilai'][] = [
                                    'tipe' => str_replace('_', ' ', ucwords($key)),
                                    'nilai' => $value
                                ];
                            }
                        }

                        $isNowSubmitted = (bool) ($savedFinalNilai?->is_submitted);
                        if (!$wasSubmitted && $isNowSubmitted) {
                            $newlySubmittedStudents[] = $siswa;
                        }
                    }
                } catch (\Exception $e) {
                    $studentNotSaved[] = "Nilai Akhir: {$e->getMessage()}";
                }

                if (!empty($studentData['nilai'])) {
                    $savedData[] = $studentData;
                }
                if (!empty($studentNotSaved)) {
                    $notSavedData[$studentData['nama']] = $studentNotSaved;
                }

                if ($studentChanged) {
                    $affectedStudentIds[] = (int) $siswaId;
                }
            }

            DB::commit();
            app()->forgetInstance('score_save.defer_nilai_pdf_cache_invalidation');

            $stepStartedAt = microtime(true);
            $guru = Auth::guard('guru')->user();
            DashboardController::clearProgressCacheForKelas(
                $mataPelajaran->kelas_id,
                $guru?->id
            );
            $cacheStats = $this->clearScorePdfCacheForStudents($affectedStudentIds, $tahunAjaranId);
            $this->addProfileStep($profileSteps, 'dashboard_cache_clear', max(0, $this->elapsedMs($stepStartedAt) - $cacheStats['cache_invalidation_ms'] - $cacheStats['pdf_warmup_scheduling_ms']));
            $this->addProfileStep($profileSteps, 'cache_invalidation', $cacheStats['cache_invalidation_ms']);
            $this->addProfileStep($profileSteps, 'pdf_warmup_scheduling', $cacheStats['pdf_warmup_scheduling_ms']);

            $stepStartedAt = microtime(true);
            foreach (collect($newlySubmittedStudents)->unique('id') as $completedStudent) {
                try {
                    $this->sendScoreCompletionNotification(
                        $mataPelajaran,
                        $completedStudent,
                        $guru?->nama ?? 'Guru'
                    );
                } catch (\Exception $notificationException) {
                    Log::warning('Notification failed', [
                        'error' => $notificationException->getMessage(),
                        'siswa_id' => $completedStudent->id,
                        'mata_pelajaran_id' => $mataPelajaran->id,
                        'guru_id' => $guru?->id,
                    ]);
                }
            }
            $this->addProfileStep($profileSteps, 'notifications', $this->elapsedMs($stepStartedAt));

            $queryCount = $profilingEnabled ? count(DB::getQueryLog()) : 0;
            if ($profilingEnabled) {
                DB::connection()->disableQueryLog();
            }
            $this->logScoreSaveProfile($profileSteps, [
                'guru_id' => $guru?->id,
                'kelas_id' => $mataPelajaran->kelas_id,
                'mata_pelajaran_id' => (int) $id,
                'student_count' => count($studentIds),
                'students_changed' => count(array_unique($affectedStudentIds)),
                'rows_changed' => $rowsChanged,
                'pdf_warmup_jobs_scheduled' => $cacheStats['jobs_scheduled'],
            ], $profileStartedAt, $queryCount);

            return response()->json([
                'success' => true,
                'message' => 'Nilai berhasil disimpan!',
                'details' => $savedData,
                'warnings' => $notSavedData
            ]);

        } catch (\Exception $e) {
            if (DB::transactionLevel() > 0) {
                DB::rollback();
            }

            app()->forgetInstance('score_save.defer_nilai_pdf_cache_invalidation');

            if ($profilingEnabled) {
                DB::connection()->disableQueryLog();
            }
            Log::error('[ScoreController] Save score failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'user_id' => Auth::guard('guru')->id(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'mata_pelajaran_id' => $id,
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Terjadi kesalahan. Silakan coba lagi.'
            ], 500);
        }
    }

    public function inputScore($id)
    {
        try {
            $mataPelajaran = MataPelajaran::findOrFail($id);

            $guru = Auth::guard('guru')->user();

            // Add debug logging
            Log::info('Checking guru access for mata pelajaran:', [
                'mata_pelajaran_id' => $id,
                'mata_pelajaran_guru_id' => $mataPelajaran->guru_id,
                'mata_pelajaran_guru_id_type' => gettype($mataPelajaran->guru_id),
                'current_guru_id' => $guru->id,
                'current_guru_id_type' => gettype($guru->id),
                'tahun_ajaran_mapel' => $mataPelajaran->tahun_ajaran_id,
                'tahun_ajaran_session' => session('tahun_ajaran_id')
            ]);
            
            $tahunAjaranId = $this->getValidTahunAjaranId();

            if (!$tahunAjaranId || !$this->isAuthorizedPengajarSubject($mataPelajaran, $tahunAjaranId)) {
                return redirect()->route('pengajar.score.index')
                    ->with('error', 'Anda tidak memiliki akses ke mata pelajaran ini');
            }

            $hasLm = DB::table('lingkup_materis')
                ->where('mata_pelajaran_id', $mataPelajaran->id)
                ->whereNull('deleted_at')
                ->exists();

            $hasTp = DB::table('tujuan_pembelajarans')
                ->join('lingkup_materis', 'tujuan_pembelajarans.lingkup_materi_id', '=', 'lingkup_materis.id')
                ->where('lingkup_materis.mata_pelajaran_id', $mataPelajaran->id)
                ->whereNull('lingkup_materis.deleted_at')
                ->whereNull('tujuan_pembelajarans.deleted_at')
                ->exists();

            if (!$hasLm || !$hasTp) {
                return redirect()->back()->with(
                    'error',
                    'Mata pelajaran ini belum memiliki Lingkup Materi dan Tujuan Pembelajaran. Silakan lengkapi terlebih dahulu sebelum melakukan input nilai.'
                );
            }

            $mataPelajaran->load([
                'kelas',
                'lingkupMateris.tujuanPembelajarans',
            ]);

            $hasCompleteTp = $mataPelajaran->lingkupMateris->every(function($lm) {
                return $lm->tujuanPembelajarans->isNotEmpty();
            });

            if (!$hasCompleteTp) {
                return redirect()->back()->with(
                    'error',
                    'Lengkapi Tujuan Pembelajaran pada setiap Lingkup Materi terlebih dahulu sebelum melakukan input nilai.'
                );
            }

            // Siapkan data
            $subject = [
                'id' => $mataPelajaran->id,
                'name' => $mataPelajaran->nama_pelajaran,
                'class' => $mataPelajaran->kelas->nomor_kelas . ' ' . $mataPelajaran->kelas->nama_kelas
            ];

            // Filter siswa berdasarkan enrollment kelas/tahun ajaran/semester mata pelajaran
            $siswas = $this->studentsForSubjectRoster($mataPelajaran, $tahunAjaranId);
            $students = $this->studentOptionsForRoster($siswas);

            // Inisialisasi struktur data nilai
            $existingScores = [];
            foreach ($siswas as $siswa) {
                $existingScores[$siswa->id] = [
                    'tp' => [],
                    'lm' => [],
                    'na_tp' => null,
                    'na_lm' => null,
                    'nilai_tes' => null,
                    'nilai_non_tes' => null,
                    'nilai_akhir_semester' => null,
                    'nilai_akhir_rapor' => null,
                    'is_submitted' => false,
                ];
                foreach ($mataPelajaran->lingkupMateris as $lm) {
                    $existingScores[$siswa->id]['lm'][$lm->id] = null;
                    foreach ($lm->tujuanPembelajarans as $tp) {
                        $existingScores[$siswa->id]['tp'][$lm->id][$tp->id] = null;
                    }
                }
            }

            // Ambil semua nilai yang sudah ada dengan filter tahun ajaran jika ada
            $existingNilaisQuery = Nilai::where('mata_pelajaran_id', $id);
            if ($tahunAjaranId) {
                $existingNilaisQuery->where('tahun_ajaran_id', $tahunAjaranId);
            }
            $existingNilais = $existingNilaisQuery->get();
            
            // Isi struktur data dengan nilai yang ada
            foreach ($existingNilais as $nilai) {
                if (!isset($existingScores[$nilai->siswa_id])) {
                    continue;
                }

                if ($nilai->nilai_tp !== null) {
                    $existingScores[$nilai->siswa_id]['tp'][$nilai->lingkup_materi_id][$nilai->tujuan_pembelajaran_id] = $nilai->nilai_tp;
                }
                if ($nilai->nilai_lm !== null) {
                    $existingScores[$nilai->siswa_id]['lm'][$nilai->lingkup_materi_id] = $nilai->nilai_lm;
                }
                if ($nilai->na_tp !== null) {
                    $existingScores[$nilai->siswa_id]['na_tp'] = $nilai->na_tp;
                }
                if ($nilai->na_lm !== null) {
                    $existingScores[$nilai->siswa_id]['na_lm'] = $nilai->na_lm;
                }
                if ($nilai->nilai_akhir_semester !== null) {
                    $existingScores[$nilai->siswa_id]['nilai_akhir_semester'] = $nilai->nilai_akhir_semester;
                }
                if ($nilai->nilai_tes !== null) {
                    $existingScores[$nilai->siswa_id]['nilai_tes'] = $nilai->nilai_tes;
                }
                if ($nilai->nilai_non_tes !== null) {
                    $existingScores[$nilai->siswa_id]['nilai_non_tes'] = $nilai->nilai_non_tes;
                }
                if ($nilai->nilai_akhir_rapor !== null) {
                    $existingScores[$nilai->siswa_id]['nilai_akhir_rapor'] = $nilai->nilai_akhir_rapor;
                }
                if ($nilai->is_submitted) {
                    $existingScores[$nilai->siswa_id]['is_submitted'] = true;
                }
            }

            $mataPelajaranList = MataPelajaran::where('kelas_id', $mataPelajaran->kelas_id)
                ->where('guru_id', $guru->id)
                ->when($tahunAjaranId, function($query) use ($tahunAjaranId) {
                    return $query->where('tahun_ajaran_id', $tahunAjaranId);
                })
                ->get();

            $kkm = Kkm::where('mata_pelajaran_id', $id)
            ->where('tahun_ajaran_id', session('tahun_ajaran_id'))
            ->first();
            
            $kkmValue = $kkm ? $kkm->nilai : 70;
            
            // Ambil bobot nilai
            $bobotNilai = BobotNilai::getDefault();
            
            return view('pengajar.input_score', compact(
                'subject',
                'students',
                'mataPelajaran',
                'existingScores',
                'mataPelajaranList',
                'kkmValue',
                'bobotNilai'
            ));

        } catch (\Exception $e) {
            Log::error('Error in ScoreController@inputScore: ' . $e->getMessage());
            return redirect()->route('pengajar.score.index')
                ->with('error', 'Terjadi kesalahan saat memuat data');
        }
    }

    private function compareScores($existingNilai, $newScoreData) 
    {
        if (!$existingNilai) return false;
    
        // Bandingkan semua jenis nilai
        return (float)$existingNilai->nilai_tp === (float)($newScoreData['tp'] ?? null) &&
               (float)$existingNilai->nilai_lm === (float)($newScoreData['lm'] ?? null) &&
               (float)$existingNilai->na_tp === (float)($newScoreData['na_tp'] ?? null) &&
               (float)$existingNilai->na_lm === (float)($newScoreData['na_lm'] ?? null) &&
               (float)$existingNilai->nilai_tes === (float)($newScoreData['nilai_tes'] ?? null) &&
               (float)$existingNilai->nilai_non_tes === (float)($newScoreData['nilai_non_tes'] ?? null) &&
               (float)$existingNilai->nilai_akhir_semester === (float)($newScoreData['nilai_akhir'] ?? null) &&
               (float)$existingNilai->nilai_akhir_rapor === (float)($newScoreData['nilai_akhir_rapor'] ?? null);
    }

    
    private function hasChanges($existing, $new)
    {
        foreach ($new as $key => $value) {
            if ($existing->$key != $value) {
                return true;
            }
        }
        return false;
    }

    private function normalizeScoreValue($value): ?float
    {
        if ($value === '' || $value === null) {
            return null;
        }

        return is_numeric($value) ? (float) $value : null;
    }

    private function calculateAverageScore(array $scores): float
    {
        $sum = 0;
        $count = 0;

        array_walk_recursive($scores, function ($value) use (&$sum, &$count) {
            if ($value === '' || $value === null || !is_numeric($value)) {
                return;
            }

            $count++;
            $sum += (float) $value;
        });

        if ($count === 0) {
            return 0.0;
        }

        return round($sum / $count, 2);
    }

    private function calculateNilaiAkhirSemester(?float $nilaiTes, ?float $nilaiNonTes): float
    {
        if ($nilaiTes === null || $nilaiNonTes === null) {
            return 0.0;
        }

        return round(($nilaiTes + $nilaiNonTes) / 2, 2);
    }

    private function calculateNilaiAkhirRapor(
        float $naTp,
        float $naLm,
        float $nilaiAkhirSemester,
        BobotNilai $bobotNilai
    ): float {
        $totalBobot = $bobotNilai->getTotal();

        if ($totalBobot === 0) {
            return 0.0;
        }

        return round(
            (
                ($naTp * (int) $bobotNilai->bobot_tp) +
                ($naLm * (int) $bobotNilai->bobot_lm) +
                ($nilaiAkhirSemester * (int) $bobotNilai->bobot_as)
            ) / $totalBobot
        );
    }
      
    public function previewScore($id)
    {
        try {
            $tahunAjaranId = $this->getValidTahunAjaranId();

            if (!$tahunAjaranId) {
                return $this->failTahunAjaranNotSet(request(), false);
            }
            
            // Load mata pelajaran dengan relasi yang diperlukan
            $mataPelajaran = MataPelajaran::with([
                'kelas',
                'lingkupMateris.tujuanPembelajarans',
                'lingkupMateris.nilais' => function($query) use ($tahunAjaranId) {
                    $query->select(
                        'nilais.*',
                        'siswa_id',
                        'lingkup_materi_id',
                        'tujuan_pembelajaran_id',
                        'nilai_tp',
                        'nilai_lm',
                        'na_tp',
                        'na_lm',
                        'nilai_tes',
                        'nilai_non_tes',
                        'nilai_akhir_semester',
                        'nilai_akhir_rapor'
                    );
                    
                    // Filter nilai berdasarkan tahun ajaran yang aktif
                    if ($tahunAjaranId) {
                        $query->where('tahun_ajaran_id', $tahunAjaranId);
                    }
                }
            ])->findOrFail($id);
    
            // Validasi akses guru
            $guru = Auth::guard('guru')->user();
                        Log::info('Checking guru access for mata pelajaran preview:', [
                'mata_pelajaran_id' => $id,
                'mata_pelajaran_guru_id' => $mataPelajaran->guru_id, 
                'mata_pelajaran_guru_id_type' => gettype($mataPelajaran->guru_id),
                'current_guru_id' => $guru->id,
                'current_guru_id_type' => gettype($guru->id),
                'tahun_ajaran_mapel' => $mataPelajaran->tahun_ajaran_id,
                'tahun_ajaran_session' => $tahunAjaranId
            ]);
            if (!$this->isAuthorizedPengajarSubject($mataPelajaran, $tahunAjaranId)) {
                return redirect()->route('pengajar.score.index')
                    ->with('error', 'Anda tidak memiliki akses ke mata pelajaran ini');
            }
    
            // Filter siswa berdasarkan enrollment kelas/tahun ajaran/semester mata pelajaran
            $siswas = $this->studentsForSubjectRoster($mataPelajaran, $tahunAjaranId);
            $students = $this->studentOptionsForRoster($siswas);
            
            // Inisialisasi struktur data nilai
            $existingScores = [];
            foreach ($students as $student) {
                $existingScores[$student['id']] = [
                    'tp' => [],
                    'lm' => [],
                    'na_tp' => null,
                    'na_lm' => null,
                    'nilai_tes' => null,
                    'nilai_non_tes' => null,
                    'nilai_akhir_semester' => null,
                    'nilai_akhir_rapor' => null,
                    'is_submitted' => false,
                ];
                
                foreach ($mataPelajaran->lingkupMateris as $lm) {
                    $existingScores[$student['id']]['lm'][$lm->id] = null;
                    foreach ($lm->tujuanPembelajarans as $tp) {
                        $existingScores[$student['id']]['tp'][$lm->id][$tp->id] = null;
                    }
                }
            }
    
            // Ambil semua nilai dengan single query dan filter berdasarkan tahun ajaran
            $nilaiQuery = Nilai::where('mata_pelajaran_id', $id);
            
            if ($tahunAjaranId) {
                $nilaiQuery->where('tahun_ajaran_id', $tahunAjaranId);
            }
            
            $nilais = $nilaiQuery->get()->groupBy('siswa_id');
    
            // Isi struktur data dengan nilai yang ada
            foreach ($nilais as $siswaId => $nilaiSiswa) {
                if (!isset($existingScores[$siswaId])) continue;
                
                foreach ($nilaiSiswa as $nilai) {
                    // Isi nilai TP
                    if ($nilai->nilai_tp !== null && $nilai->tujuan_pembelajaran_id && $nilai->lingkup_materi_id) {
                        $existingScores[$siswaId]['tp'][$nilai->lingkup_materi_id][$nilai->tujuan_pembelajaran_id] = $nilai->nilai_tp;
                    }
                    
                    // Isi nilai LM
                    if ($nilai->nilai_lm !== null && $nilai->lingkup_materi_id) {
                        $existingScores[$siswaId]['lm'][$nilai->lingkup_materi_id] = $nilai->nilai_lm;
                    }
                    
                    // Isi nilai agregat
                    if ($nilai->na_tp !== null) {
                        $existingScores[$siswaId]['na_tp'] = $nilai->na_tp;
                    }
                    if ($nilai->na_lm !== null) {
                        $existingScores[$siswaId]['na_lm'] = $nilai->na_lm;
                    }
                    if ($nilai->nilai_tes !== null) {
                        $existingScores[$siswaId]['nilai_tes'] = $nilai->nilai_tes;
                    }
                    if ($nilai->nilai_non_tes !== null) {
                        $existingScores[$siswaId]['nilai_non_tes'] = $nilai->nilai_non_tes;
                    }
                    if ($nilai->nilai_akhir_semester !== null) {
                        $existingScores[$siswaId]['nilai_akhir_semester'] = $nilai->nilai_akhir_semester;
                    }
                    if ($nilai->nilai_akhir_rapor !== null) {
                        $existingScores[$siswaId]['nilai_akhir_rapor'] = $nilai->nilai_akhir_rapor;
                    }
                    if ($nilai->is_submitted) {
                        $existingScores[$siswaId]['is_submitted'] = true;
                    }
                }
            }
    
            $kkm = Kkm::where('mata_pelajaran_id', $id)
            ->where('tahun_ajaran_id', session('tahun_ajaran_id'))
            ->first();
            
            $kkmValue = $kkm ? $kkm->nilai : 70; // Default ke 70 jika tidak ada KKM
            
            // Tambahkan ini: Ambil bobot nilai
            $bobotNilai = BobotNilai::getDefault();
            
            // Kirim variabel tambahan ke view
            return view('pengajar.preview_score', compact(
                'mataPelajaran', 
                'existingScores', 
                'students',
                'kkmValue',    // Tambahkan ini
                'bobotNilai'   // Tambahkan ini
            ));
        } catch (\Exception $e) {
            Log::error('[ScoreController] Preview score failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'user_id' => Auth::guard('guru')->id(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'mata_pelajaran_id' => $id,
            ]);
            return redirect()->route('pengajar.score.index')
                ->with('error', 'Terjadi kesalahan. Silakan coba lagi.');
        }
    }

    public function deleteNilai(Request $request)
    {
        $tahunAjaranId = $this->getValidTahunAjaranId();

        if (!$tahunAjaranId) {
            return $this->failTahunAjaranNotSet($request, true);
        }

        $authorizedDelete = $this->authorizeDeleteNilaiRequest($request, $tahunAjaranId);
        $mataPelajaran = $authorizedDelete['mata_pelajaran'];
        $siswaId = $authorizedDelete['siswa_id'];

        try {
            DB::transaction(function () use ($siswaId, $mataPelajaran, $tahunAjaranId) {
                Nilai::where([
                    'siswa_id' => $siswaId,
                    'mata_pelajaran_id' => $mataPelajaran->id,
                    'tahun_ajaran_id' => $tahunAjaranId,
                ])->delete();
            });

            $guru = Auth::guard('guru')->user();
            DashboardController::clearProgressCacheForKelas(
                $mataPelajaran->kelas_id,
                $guru?->id
            );
            $this->clearScorePdfCacheForStudents([$siswaId], $tahunAjaranId);

            return response()->json([
                'success' => true,
                'message' => 'Nilai berhasil dihapus'
            ]);
        } catch (\Exception $e) {
            Log::error('Gagal menghapus nilai', [
                'siswa_id' => $siswaId,
                'mata_pelajaran_id' => $mataPelajaran->id,
                'tahun_ajaran_id' => $tahunAjaranId,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false, 
                'message' => 'Gagal menghapus nilai.'
            ], 500);
        }
    }
}

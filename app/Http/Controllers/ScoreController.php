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

    private function clearScorePdfCacheForStudents(array $studentIds, int $tahunAjaranId): void
    {
        $studentIds = collect($studentIds)
            ->map(fn ($id) => (int) $id)
            ->filter()
            ->unique()
            ->values();

        if ($studentIds->isEmpty()) {
            return;
        }

        Siswa::whereIn('id', $studentIds)
            ->get()
            ->each(fn (Siswa $siswa) => PdfCacheService::clearStudentCache($siswa, $tahunAjaranId));
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
        $tahunAjaranId = $this->getValidTahunAjaranId();

        if (!$tahunAjaranId) {
            return $this->failTahunAjaranNotSet($request, true);
        }

        $mataPelajaran = $this->authorizePengajarSubjectForSave($id, $tahunAjaranId);
        $this->assertScorePayloadBelongsToSubject($request, $mataPelajaran, $tahunAjaranId);

        try {
            DB::beginTransaction();
            $bobotNilai = BobotNilai::getDefault();
            $savedData = [];
            $notSavedData = []; // Tracking data yang tidak tersimpan
            $newlySubmittedStudents = [];
            $affectedStudentIds = [];

            foreach($request->scores as $siswaId => $scoreData) {
                $siswa = Siswa::find($siswaId);

                if (!$siswa) {
                    continue;
                }

                $existingStudentNilais = Nilai::withTrashed()
                    ->where('siswa_id', $siswaId)
                    ->where('mata_pelajaran_id', $id)
                    ->where('tahun_ajaran_id', $tahunAjaranId)
                    ->get();

                $wasSubmitted = Nilai::where('siswa_id', $siswaId)
                    ->where('mata_pelajaran_id', $id)
                    ->where('tahun_ajaran_id', $tahunAjaranId)
                    ->where('is_submitted', true)
                    ->exists();
                $hasActualInput = $this->studentHasActualInput($scoreData);
                if (!$hasActualInput && $existingStudentNilais->isEmpty()) {
                    continue;
                }
                $affectedStudentIds[] = (int) $siswaId;

                $studentData = [
                    'nama' => $siswa->nama,
                    'nilai' => []
                ];
                $studentNotSaved = []; // Tracking nilai yang tidak tersimpan per siswa

                // Simpan nilai TP dan LM
                if (isset($scoreData['tp']) && is_array($scoreData['tp'])) {
                    foreach($scoreData['tp'] as $lmId => $tpScores) {
                        foreach($tpScores as $tpId => $nilai) {
                            try {
                                $tp = TujuanPembelajaran::find($tpId);
                                $lm = LingkupMateri::find($lmId);
                                
                                $nilaiData = [
                                    'nilai_tp' => $this->normalizeScoreValue($nilai)
                                ];
                                
                                if ($tahunAjaranId) {
                                    $nilaiData['tahun_ajaran_id'] = $tahunAjaranId;
                                }
                                
                                $this->restoreOrUpdateNilai(
                                    [
                                        'siswa_id' => $siswaId,
                                        'mata_pelajaran_id' => $id,
                                        'lingkup_materi_id' => $lmId,
                                        'tujuan_pembelajaran_id' => $tpId,
                                        'tahun_ajaran_id' => $tahunAjaranId,
                                    ],
                                    $nilaiData
                                );

                                if ($nilai !== '' && $nilai !== null) {
                                    $studentData['nilai'][] = [
                                        'tipe' => 'TP',
                                        'kode' => $tp->kode_tp,
                                        'nilai' => $nilai
                                    ];
                                }
                            } catch (\Exception $e) {
                                $studentNotSaved[] = "TP {$tp->kode_tp}: {$e->getMessage()}";
                            }
                        }
                    }
                }
                
                // Tambahkan kode untuk simpan nilai Lingkup Materi (LM)
                if (isset($scoreData['lm']) && is_array($scoreData['lm'])) {
                    foreach($scoreData['lm'] as $lmId => $nilai) {
                        try {
                            $lm = LingkupMateri::find($lmId);
                            
                                $nilaiData = [
                                    'nilai_lm' => $this->normalizeScoreValue($nilai)
                                ];
                            
                            if ($tahunAjaranId) {
                                $nilaiData['tahun_ajaran_id'] = $tahunAjaranId;
                            }
                            
                            $this->restoreOrUpdateNilai(
                                [
                                    'siswa_id' => $siswaId,
                                    'mata_pelajaran_id' => $id,
                                    'lingkup_materi_id' => $lmId,
                                    'tahun_ajaran_id' => $tahunAjaranId,
                                ],
                                $nilaiData
                            );

                            if ($nilai !== '' && $nilai !== null) {
                                $studentData['nilai'][] = [
                                    'tipe' => 'LM',
                                    'kode' => $lm->judul_lingkup_materi,
                                    'nilai' => $nilai
                                ];
                            }
                        } catch (\Exception $e) {
                            $studentNotSaved[] = "LM {$lm->judul_lingkup_materi}: {$e->getMessage()}";
                        }
                    }
                }

                // Simpan nilai agregat
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
                        $savedFinalNilai = $this->restoreOrUpdateNilai(
                            [
                                'siswa_id' => $siswaId,
                                'mata_pelajaran_id' => $id,
                                'tahun_ajaran_id' => $tahunAjaranId,
                            ],
                            $finalScores
                        );

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
            }

            DB::commit();

            $guru = Auth::guard('guru')->user();
            DashboardController::clearProgressCacheForKelas(
                $mataPelajaran->kelas_id,
                $guru?->id
            );
            $this->clearScorePdfCacheForStudents($affectedStudentIds, $tahunAjaranId);

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

            return response()->json([
                'success' => true,
                'message' => 'Nilai berhasil disimpan!',
                'details' => $savedData,
                'warnings' => $notSavedData
            ]);

        } catch (\Exception $e) {
            DB::rollback();
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

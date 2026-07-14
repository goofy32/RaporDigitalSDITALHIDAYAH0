<?php

namespace App\Http\Controllers;

use App\Models\Guru;
use App\Models\Kelas;
use App\Models\LingkupMateri;
use App\Models\MataPelajaran;
use App\Models\TahunAjaran;
use App\Services\LearningStructureCopyService;
use App\Services\SubjectTeacherAssignmentValidator;
use InvalidArgumentException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\MessageBag;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class SubjectController extends Controller
{
    public function index(Request $request)
    {
        // Ambil tahun ajaran dari session
        $tahunAjaranId = session('tahun_ajaran_id');

        // Gunakan left join agar mapel dengan relasi kelas/guru yang hilang tetap terlihat di admin.
        $query = MataPelajaran::leftJoin('kelas', 'mata_pelajarans.kelas_id', '=', 'kelas.id')
            ->select('mata_pelajarans.*') // Pastikan hanya mengambil kolom dari mata_pelajarans
            ->with(['kelas', 'guru', 'lingkupMateris']); // Load relasi yang ditampilkan di tabel

        // Filter berdasarkan tahun ajaran jika ada
        if ($tahunAjaranId) {
            $query->where('mata_pelajarans.tahun_ajaran_id', $tahunAjaranId);
        }

        // Handle pencarian
        if ($request->has('search')) {
            $search = strtolower($request->search);
            $terms = explode(' ', trim($search));

            $query->where(function ($q) use ($terms, $search) {
                // Jika kata pertama adalah "kelas"
                if (count($terms) > 0 && $terms[0] === 'kelas') {
                    $q->whereHas('kelas', function ($kelasQ) use ($terms) {
                        if (count($terms) > 1 && is_numeric($terms[1])) {
                            // Jika ada nomor kelas yang dispecifikkan
                            $kelasQ->where('nomor_kelas', $terms[1]);
                        }
                    });
                } else {
                    // Pencarian normal
                    $q->where('mata_pelajarans.nama_pelajaran', 'LIKE', "%{$search}%")
                        ->orWhereHas('kelas', function ($kelasQ) use ($search) {
                            $kelasQ->where('nama_kelas', 'LIKE', "%{$search}%")
                                ->orWhere('nomor_kelas', 'LIKE', "%{$search}%");
                        })
                        ->orWhereHas('guru', function ($guruQ) use ($search) {
                            $guruQ->where('nama', 'LIKE', "%{$search}%");
                        });
                }
            });
        }

        // Default sorting: urutkan berdasarkan nomor kelas (ascending) lalu nama kelas
        $query->orderBy('kelas.nomor_kelas', 'asc')
            ->orderBy('kelas.nama_kelas', 'asc')
            ->orderBy('mata_pelajarans.nama_pelajaran', 'asc');

        $subjects = $query->paginate(10);

        // Pass data tahun ajaran ke view untuk menampilkan informasi
        $activeTahunAjaran = null;
        if ($tahunAjaranId) {
            $activeTahunAjaran = \App\Models\TahunAjaran::find($tahunAjaranId);
        }

        return view('admin.subject', compact('subjects', 'activeTahunAjaran'));
    }

    public function create(LearningStructureCopyService $copyService)
    {
        $tahunAjaranId = session('tahun_ajaran_id');
        $semester = $tahunAjaranId ? $this->copySemesterContext((int) $tahunAjaranId) : null;

        $classes = Kelas::when($tahunAjaranId, function ($query) use ($tahunAjaranId) {
            return $query->where('tahun_ajaran_id', $tahunAjaranId);
        })
            ->orderBy('nomor_kelas')
            ->orderBy('nama_kelas')
            ->get();

        $teachers = Guru::orderBy('nama')->get();
        $teacherWaliClassIds = $this->teacherWaliClassIds($tahunAjaranId);
        $teacherTeachingClassIds = $this->teacherTeachingClassIds($tahunAjaranId);

        // Ambil semua mata pelajaran untuk validasi JavaScript
        $mataPelajaranList = MataPelajaran::select('id', 'nama_pelajaran', 'kelas_id', 'semester')
            ->when($tahunAjaranId, function ($query) use ($tahunAjaranId) {
                return $query->where('tahun_ajaran_id', $tahunAjaranId);
            })
            ->get();

        // Kode ini akan dimanfaatkan oleh JavaScript
        $waliKelasMap = Kelas::getWaliKelasMap($tahunAjaranId);
        $lmTpCopyCandidates = $tahunAjaranId && $semester
            ? $this->learningCopyCandidatePayload($copyService->copyableSourceCandidates((int) $tahunAjaranId, (int) $semester))
            : [];

        return view('data.add_subject', compact('classes', 'teachers', 'teacherWaliClassIds', 'teacherTeachingClassIds', 'waliKelasMap', 'mataPelajaranList', 'lmTpCopyCandidates'));
    }

    public function show($id)
    {
        $subject = MataPelajaran::findOrFail($id);

        return redirect()->route('subject.edit', $subject->id);
    }

    public function store(Request $request, LearningStructureCopyService $copyService)
    {
        \Log::info('Subject store method called with data:', $request->all());

        try {
            $tahunAjaranId = session('tahun_ajaran_id');

            // Validasi array subjects
            $request->validate([
                'subjects' => 'required|array',
                'subjects.*.mata_pelajaran' => 'required|string|max:255',
                'subjects.*.kelas' => ['required', $this->kelasExistsRule($tahunAjaranId)],
                'subjects.*.guru_pengampu' => 'required|exists:gurus,id',
                'subjects.*.semester' => 'required|integer|min:1|max:2',
                'subjects.*.teaching_type' => 'nullable|in:regular,muatan_lokal,specialist',
                'subjects.*.lingkup_materi' => 'nullable|array',
                'subjects.*.lingkup_materi.*' => 'nullable|string|max:255',
                'subjects.*.copy_lm_tp' => 'nullable|boolean',
                'subjects.*.copy_lm_tp_source_id' => 'nullable|integer',
            ], [
                'subjects.*.guru_pengampu.required' => 'Harap pilih guru pengampu untuk mata pelajaran',
                'subjects.*.kelas.required' => 'Harap pilih kelas untuk mata pelajaran',
                'subjects.*.mata_pelajaran.required' => 'Nama mata pelajaran harus diisi',
                'subjects.*.semester.required' => 'Semester harus dipilih',
                'subjects.*.lingkup_materi.required' => 'Lingkup materi harus diisi',
            ]);

            $errorBag = new MessageBag;
            $seenSubjects = [];
            $copySourceIds = [];
            $manualLingkupMateriByIndex = [];

            foreach ($request->subjects as $index => $subjectData) {
                // Get data for this entry
                $kelasId = $subjectData['kelas'];
                $kelas = Kelas::find($kelasId);
                $guruId = $subjectData['guru_pengampu'];
                $guru = Guru::find($guruId);
                $flags = $this->subjectFlags($subjectData);

                if (! $kelas || ! $guru) {
                    $errorBag->add("subjects.$index.guru_pengampu", "Data kelas atau guru tidak valid untuk mata pelajaran {$subjectData['mata_pelajaran']}.");

                    continue;
                }

                $this->addAssignmentErrors(
                    $errorBag,
                    "subjects.$index",
                    $this->validateSubjectAssignment($guru, $kelas, $flags)
                );

                if ($errorBag->has("subjects.$index.guru_pengampu") || $errorBag->has("subjects.$index.teaching_type")) {
                    continue;
                }

                $duplicateKey = strtolower(trim($subjectData['mata_pelajaran'])).'|'.$kelasId.'|'.$subjectData['semester'];
                if (in_array($duplicateKey, $seenSubjects, true)) {
                    $errorBag->add("subjects.$index.mata_pelajaran", "Mata pelajaran {$subjectData['mata_pelajaran']} diduplikasi lebih dari sekali dalam form yang sama.");

                    continue;
                }
                $seenSubjects[] = $duplicateKey;

                // Cek duplikasi nama mata pelajaran dalam satu kelas untuk semester yang sama
                $exists = MataPelajaran::where('kelas_id', $kelasId)
                    ->where('nama_pelajaran', $subjectData['mata_pelajaran'])
                    ->where('semester', $subjectData['semester'])
                    ->exists();

                if ($exists) {
                    $errorBag->add("subjects.$index.mata_pelajaran", "Mata pelajaran {$subjectData['mata_pelajaran']} untuk kelas {$kelas->nomor_kelas} {$kelas->nama_kelas} semester {$subjectData['semester']} sudah ada.");

                    continue;
                }

                try {
                    $copySource = $this->resolveInlineCopySource(
                        $copyService,
                        $subjectData['mata_pelajaran'],
                        (int) $kelasId,
                        (int) $tahunAjaranId,
                        (int) $subjectData['semester'],
                        null,
                        null,
                        (int) ($subjectData['copy_lm_tp_source_id'] ?? 0),
                        $this->inlineCopyRequested($subjectData)
                    );

                    if ($copySource) {
                        $copySourceIds[$index] = $copySource->id;
                    }
                } catch (InvalidArgumentException $exception) {
                    $errorBag->add("subjects.$index.copy_lm_tp_source_id", $exception->getMessage());

                    continue;
                }

                $manualLingkupMateri = $this->manualLingkupMateriInput($subjectData['lingkup_materi'] ?? []);
                $manualLingkupMateriByIndex[$index] = $manualLingkupMateri;

                if (! $copySource && $manualLingkupMateri === []) {
                    $errorBag->add("subjects.$index.lingkup_materi", 'Lingkup materi harus diisi jika tidak menyalin LM/TP dari kelas paralel.');
                } elseif (! $copySource && $this->hasBlankLingkupMateriInput($subjectData['lingkup_materi'] ?? [])) {
                    $errorBag->add("subjects.$index.lingkup_materi", 'Semua lingkup materi harus diisi.');
                }
            }

            if ($errorBag->any()) {
                return back()->withErrors($errorBag)->withInput();
            }

            DB::beginTransaction();
            $successCount = 0;
            $copySummaries = [];

            foreach ($request->subjects as $index => $subjectData) {
                $flags = $this->subjectFlags($subjectData);
                $kelasId = $subjectData['kelas'];
                $guruId = $subjectData['guru_pengampu'];

                // Simpan Mata Pelajaran
                $mataPelajaran = MataPelajaran::create([
                    'nama_pelajaran' => $subjectData['mata_pelajaran'],
                    'kelas_id' => $kelasId,
                    'guru_id' => $guruId,
                    'semester' => $subjectData['semester'],
                    'is_muatan_lokal' => $flags['is_muatan_lokal'],
                    'allow_non_wali' => $flags['allow_non_wali'],
                ]);

                // Simpan Lingkup Materi
                foreach ($manualLingkupMateriByIndex[$index] ?? [] as $judulLingkupMateri) {
                    LingkupMateri::create([
                        'mata_pelajaran_id' => $mataPelajaran->id,
                        'judul_lingkup_materi' => $judulLingkupMateri,
                    ]);
                }

                if (isset($copySourceIds[$index])) {
                    $copySummaries[] = $this->copyInlineLearningStructure(
                        $copyService,
                        (int) $copySourceIds[$index],
                        $mataPelajaran
                    );
                }

                $successCount++;
            }

            DB::commit();

            $copyMessage = $this->aggregateCopyLmTpMessage($copySummaries);

            return redirect()->route('subject.index')
                ->with('success', trim("Berhasil menambahkan {$successCount} mata pelajaran! {$copyMessage}"));
        } catch (ValidationException $e) {
            throw $e;
        } catch (InvalidArgumentException $e) {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }

            return back()->withInput()->with('error', $e->getMessage());
        } catch (\Exception $e) {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            Log::error('Failed to store admin subject data', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'user_id' => auth()->id(),
            ]);

            return back()->withInput()->with('error', 'Terjadi kesalahan. Silakan coba lagi.');
        }
    }

    public function checkLingkupMateriDependencies($id)
    {
        try {
            $lingkupMateri = LingkupMateri::findOrFail($id);

            // Verify permission
            if (auth()->guard('guru')->check()) {
                $guru = auth()->guard('guru')->user();
                $mataPelajaran = $lingkupMateri->mataPelajaran;

                if ($mataPelajaran->guru_id != $guru->id) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Tidak memiliki izin untuk memeriksa data ini',
                    ], 403);
                }
            }

            // Cek apakah ada tujuan pembelajaran terkait
            $hasDependents = $lingkupMateri->tujuanPembelajarans()->exists();

            return response()->json([
                'success' => true,
                'hasDependents' => $hasDependents,
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to check lingkup materi dependencies', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'user_id' => auth()->guard('guru')->id() ?? auth()->id(),
                'lingkup_materi_id' => $id,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Terjadi kesalahan. Silakan coba lagi.',
            ], 500);
        }
    }

    public function deleteLingkupMateri($id)
    {
        try {
            $lingkupMateri = LingkupMateri::findOrFail($id);

            // Validate user has permission (either admin or the assigned teacher)
            if (auth()->guard('guru')->check()) {
                $guru = auth()->guard('guru')->user();
                $mataPelajaran = $lingkupMateri->mataPelajaran;

                if ($mataPelajaran->guru_id != $guru->id) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Tidak memiliki izin untuk menghapus data ini',
                    ], 403);
                }
            }

            // Mulai transaksi database untuk memastikan semua operasi berhasil atau gagal bersama
            DB::beginTransaction();
            $lingkupMateri->delete();

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Lingkup materi dan semua tujuan pembelajaran terkait berhasil dihapus',
            ]);

        } catch (\Exception $e) {
            DB::rollBack();

            Log::error('Failed to delete lingkup materi', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'user_id' => auth()->guard('guru')->id() ?? auth()->id(),
                'lingkup_materi_id' => $id,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Terjadi kesalahan. Silakan coba lagi.',
            ], 500);
        }
    }

    public function edit($id, LearningStructureCopyService $copyService)
    {
        $tahunAjaranId = session('tahun_ajaran_id');
        $subject = MataPelajaran::with('lingkupMateris')->findOrFail($id);

        $classes = Kelas::when($tahunAjaranId, function ($query) use ($tahunAjaranId) {
            return $query->where('tahun_ajaran_id', $tahunAjaranId);
        })
            ->orderBy('nomor_kelas')
            ->orderBy('nama_kelas')
            ->get();

        $teachers = Guru::orderBy('nama')->get();
        $teacherWaliClassIds = $this->teacherWaliClassIds($tahunAjaranId);
        $teacherTeachingClassIds = $this->teacherTeachingClassIds($tahunAjaranId);

        // Ambil semua mata pelajaran untuk validasi JavaScript
        $mataPelajaranList = MataPelajaran::select('id', 'nama_pelajaran', 'kelas_id', 'semester')
            ->when($tahunAjaranId, function ($query) use ($tahunAjaranId) {
                return $query->where('tahun_ajaran_id', $tahunAjaranId);
            })
            ->get();

        // Panggil getWaliKelasMap sebagai method statis dengan parameter tahun ajaran
        $waliKelasMap = Kelas::getWaliKelasMap($tahunAjaranId);
        $lmTpCopyCandidates = $this->learningCopyCandidatePayload(
            $copyService->sourceCandidatesForContext(
                $subject->nama_pelajaran,
                (int) $subject->kelas_id,
                (int) $tahunAjaranId,
                (int) $subject->semester,
                null,
                (int) $subject->id
            )
        );

        return view('data.edit_subject', compact('subject', 'classes', 'teachers', 'teacherWaliClassIds', 'teacherTeachingClassIds', 'waliKelasMap', 'mataPelajaranList', 'lmTpCopyCandidates'));
    }

    public function updateLingkupMateri(Request $request, $id)
    {
        try {
            $lingkupMateri = LingkupMateri::findOrFail($id);

            // Validate user has permission (either admin or the assigned teacher)
            if (auth()->guard('guru')->check()) {
                $guru = auth()->guard('guru')->user();
                $mataPelajaran = $lingkupMateri->mataPelajaran;

                if ($mataPelajaran->guru_id != $guru->id) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Tidak memiliki izin untuk mengubah data ini',
                    ], 403);
                }
            }

            $request->validate([
                'judul_lingkup_materi' => 'required|string|max:255',
            ]);

            $lingkupMateri->update([
                'judul_lingkup_materi' => $request->judul_lingkup_materi,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Lingkup materi berhasil diperbarui',
                'data' => $lingkupMateri,
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to update lingkup materi', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'user_id' => auth()->guard('guru')->id() ?? auth()->id(),
                'lingkup_materi_id' => $id,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Terjadi kesalahan. Silakan coba lagi.',
            ], 500);
        }
    }

    public function update(Request $request, $id, LearningStructureCopyService $copyService)
    {
        try {
            $subject = MataPelajaran::findOrFail($id);
            $tahunAjaranId = session('tahun_ajaran_id') ?: $subject->tahun_ajaran_id;

            $validated = $request->validate([
                'mata_pelajaran' => 'required|string|max:255',
                'kelas' => ['required', $this->kelasExistsRule($tahunAjaranId)],
                'guru_pengampu' => 'required|exists:gurus,id',
                'semester' => 'required|integer|min:1|max:2',
                'teaching_type' => 'nullable|in:regular,muatan_lokal,specialist',
                'lingkup_materi' => 'nullable|array',
                'lingkup_materi.*' => 'nullable|string|max:255',
                'delete_ids' => 'nullable|array',
                'delete_ids.*' => 'integer|exists:lingkup_materis,id',
                'copy_lm_tp' => 'nullable|boolean',
                'copy_lm_tp_source_id' => 'nullable|integer',
            ]);

            // Ambil data kelas dan guru
            $kelas = Kelas::find($validated['kelas']);
            $guru = Guru::find($validated['guru_pengampu']);

            $flags = $this->subjectFlags($request->all());
            $assignmentErrors = $kelas && $guru
                ? $this->validateSubjectAssignment($guru, $kelas, $flags)
                : ['guru_pengampu' => 'Data kelas atau guru tidak valid.'];

            if (! empty($assignmentErrors)) {
                return back()->withErrors($assignmentErrors)->withInput();
            }

            // Cek duplikasi nama mata pelajaran dalam satu kelas untuk semester yang sama
            // Kecuali mata pelajaran yang sedang diedit
            $exists = MataPelajaran::where('kelas_id', $validated['kelas'])
                ->where('nama_pelajaran', $validated['mata_pelajaran'])
                ->where('semester', $validated['semester'])
                ->where('id', '!=', $id) // Kecuali mata pelajaran yang sedang diedit
                ->exists();

            if ($exists) {
                return back()->withErrors([
                    'mata_pelajaran' => 'Mata pelajaran dengan nama yang sama sudah ada di kelas ini untuk semester yang sama.',
                ])->withInput();
            }

            try {
                $copySource = $this->resolveInlineCopySource(
                    $copyService,
                    $validated['mata_pelajaran'],
                    (int) $validated['kelas'],
                    (int) $tahunAjaranId,
                    (int) $validated['semester'],
                    null,
                    (int) $subject->id,
                    (int) $request->input('copy_lm_tp_source_id', 0),
                    $request->boolean('copy_lm_tp')
                );
            } catch (InvalidArgumentException $exception) {
                return back()->withErrors([
                    'copy_lm_tp_source_id' => $exception->getMessage(),
                ])->withInput();
            }

            $manualLingkupMateri = $this->manualLingkupMateriInput($validated['lingkup_materi'] ?? []);
            if (! $copySource && $manualLingkupMateri === []) {
                return back()->withErrors([
                    'lingkup_materi' => 'Lingkup materi harus diisi jika tidak menyalin LM/TP dari kelas paralel.',
                ])->withInput();
            }

            if (! $copySource && $this->hasBlankLingkupMateriInput($validated['lingkup_materi'] ?? [])) {
                return back()->withErrors([
                    'lingkup_materi' => 'Semua lingkup materi harus diisi.',
                ])->withInput();
            }

            DB::beginTransaction();

            // Update data mata pelajaran
            $subject->update([
                'nama_pelajaran' => $validated['mata_pelajaran'],
                'kelas_id' => $validated['kelas'],
                'guru_id' => $validated['guru_pengampu'],
                'semester' => $validated['semester'],
                'is_muatan_lokal' => $flags['is_muatan_lokal'],
                'allow_non_wali' => $flags['allow_non_wali'],
            ]);

            $deleteIds = collect($request->input('delete_ids', []))
                ->map(fn ($deleteId) => (int) $deleteId)
                ->filter()
                ->values();

            if ($deleteIds->isNotEmpty()) {
                $subject->lingkupMateris()
                    ->whereIn('id', $deleteIds)
                    ->get()
                    ->each(function ($lingkupMateri) {
                        $lingkupMateri->delete();
                    });
            }

            if ($manualLingkupMateri !== [] || ! $copySource) {
                // Dapatkan lingkup materi yang sudah ada
                $existingLingkupMateriTitles = $subject->lingkupMateris()->pluck('judul_lingkup_materi')->toArray();
                $newLingkupMateriTitles = $manualLingkupMateri;

                // Lingkup materi yang akan dihapus (ada di existing tapi tidak ada di input baru)
                $toBeDeletedTitles = array_diff($existingLingkupMateriTitles, $newLingkupMateriTitles);

                // Hapus lingkup materi yang tidak ada lagi
                if (! empty($toBeDeletedTitles)) {
                    $subject->lingkupMateris()
                        ->whereIn('judul_lingkup_materi', $toBeDeletedTitles)
                        ->get()
                        ->each(function ($lingkupMateri) {
                            $lingkupMateri->delete();
                        });
                }

                // Tambahkan lingkup materi baru yang belum ada
                foreach ($newLingkupMateriTitles as $judulLingkupMateri) {
                    if (! in_array($judulLingkupMateri, $existingLingkupMateriTitles)) {
                        LingkupMateri::create([
                            'mata_pelajaran_id' => $subject->id,
                            'judul_lingkup_materi' => $judulLingkupMateri,
                        ]);
                    }
                }
            }

            $copySummary = null;
            if ($copySource) {
                $copySummary = $this->copyInlineLearningStructure(
                    $copyService,
                    (int) $copySource->id,
                    $subject->fresh(['kelas', 'lingkupMateris.tujuanPembelajarans'])
                );
            }

            DB::commit();

            $copyMessage = $copySummary ? $this->aggregateCopyLmTpMessage([$copySummary]) : '';

            return redirect()->route('subject.index')->with('success', trim("Mata Pelajaran berhasil diperbarui! {$copyMessage}"));

        } catch (ValidationException $e) {
            throw $e;
        } catch (InvalidArgumentException $e) {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }

            return back()->with('error', $e->getMessage())->withInput();
        } catch (\Exception $e) {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            Log::error('Failed to update admin subject data', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'user_id' => auth()->id(),
                'subject_id' => $id,
            ]);

            return back()->with('error', 'Terjadi kesalahan. Silakan coba lagi.')->withInput();
        }
    }

    public function destroy($id)
    {
        $subject = MataPelajaran::findOrFail($id);
        $subject->delete();

        return redirect()->route('subject.index')->with('success', 'Mata Pelajaran berhasil dihapus!');
    }

    private function copySemesterContext(int $tahunAjaranId): int
    {
        return (int) (session('selected_semester')
            ?: TahunAjaran::find($tahunAjaranId)?->semester
            ?: 1);
    }

    private function inlineCopyRequested(array $subjectData): bool
    {
        return filter_var($subjectData['copy_lm_tp'] ?? false, FILTER_VALIDATE_BOOLEAN);
    }

    private function manualLingkupMateriInput(mixed $values): array
    {
        return collect(is_array($values) ? $values : [])
            ->map(fn ($value) => trim((string) $value))
            ->filter(fn ($value) => $value !== '')
            ->values()
            ->all();
    }

    private function hasBlankLingkupMateriInput(mixed $values): bool
    {
        return collect(is_array($values) ? $values : [])
            ->contains(fn ($value) => trim((string) $value) === '');
    }

    private function resolveInlineCopySource(
        LearningStructureCopyService $copyService,
        string $subjectName,
        int $kelasId,
        int $tahunAjaranId,
        int $semester,
        ?Guru $guru,
        ?int $excludeSubjectId,
        int $sourceId,
        bool $requested
    ): ?MataPelajaran {
        if (! $requested) {
            return null;
        }

        if (! $sourceId) {
            throw new InvalidArgumentException('Sumber LM/TP tidak valid atau tidak dapat digunakan. Silakan pilih sumber yang tersedia.');
        }

        $source = $copyService->sourceCandidatesForContext(
            $subjectName,
            $kelasId,
            $tahunAjaranId,
            $semester,
            $guru,
            $excludeSubjectId
        )->firstWhere('id', $sourceId);

        if (! $source) {
            throw new InvalidArgumentException('Sumber LM/TP tidak valid atau tidak dapat digunakan. Silakan pilih sumber yang tersedia.');
        }

        return $source;
    }

    private function copyInlineLearningStructure(
        LearningStructureCopyService $copyService,
        int $sourceId,
        MataPelajaran $target,
        ?Guru $guru = null
    ): array {
        $source = $copyService->sourceCandidates($target, $guru)->firstWhere('id', $sourceId);

        if (! $source) {
            throw new InvalidArgumentException('Sumber LM/TP tidak valid atau tidak dapat digunakan. Silakan pilih sumber yang tersedia.');
        }

        return $copyService->copy($source, $target);
    }

    private function aggregateCopyLmTpMessage(array $summaries): string
    {
        $summaries = collect($summaries)->filter();

        if ($summaries->isEmpty()) {
            return '';
        }

        $copiedLm = $summaries->sum(fn ($summary) => (int) ($summary['copied_lm_count'] ?? 0));
        $copiedTp = $summaries->sum(fn ($summary) => (int) ($summary['copied_tp_count'] ?? 0));
        $skipped = $summaries->sum(fn ($summary) => (int) ($summary['skipped_lm_count'] ?? 0) + (int) ($summary['skipped_tp_count'] ?? 0));

        if ($copiedLm === 0 && $copiedTp === 0) {
            return 'Tidak ada LM/TP baru yang disalin karena data tujuan sudah lengkap.';
        }

        $message = sprintf('%d Lingkup Materi dan %d Tujuan Pembelajaran berhasil disalin.', $copiedLm, $copiedTp);

        if ($skipped > 0) {
            $message .= ' Beberapa LM/TP dilewati karena sudah ada.';
        }

        return $message;
    }

    private function learningCopyCandidatePayload($candidates): array
    {
        return collect($candidates)
            ->map(function (MataPelajaran $candidate) {
                $candidate->loadMissing(['kelas', 'lingkupMateris.tujuanPembelajarans']);

                return [
                    'id' => (int) $candidate->id,
                    'subject' => $candidate->nama_pelajaran,
                    'subject_key' => mb_strtolower(trim(preg_replace('/\s+/u', ' ', (string) $candidate->nama_pelajaran) ?? ''), 'UTF-8'),
                    'kelas_id' => (int) $candidate->kelas_id,
                    'kelas_nomor' => (int) ($candidate->kelas?->nomor_kelas ?? 0),
                    'kelas_nama' => (string) ($candidate->kelas?->nama_kelas ?? ''),
                    'semester' => (int) $candidate->semester,
                    'tahun_ajaran_id' => (int) $candidate->tahun_ajaran_id,
                    'label' => sprintf(
                        'Kelas %s %s - %s',
                        $candidate->kelas?->nomor_kelas,
                        $candidate->kelas?->nama_kelas,
                        $candidate->nama_pelajaran
                    ),
                    'lm_count' => $candidate->lingkupMateris->count(),
                    'tp_count' => $candidate->lingkupMateris->sum(fn ($lm) => $lm->tujuanPembelajarans->count()),
                ];
            })
            ->values()
            ->all();
    }

    public function teacherIndex()
    {
        $guru = auth()->guard('guru')->user();
        $tahunAjaranId = session('tahun_ajaran_id');

        // Gunakan join dengan tabel kelas untuk memungkinkan pengurutan yang lebih baik
        $subjects = MataPelajaran::join('kelas', 'mata_pelajarans.kelas_id', '=', 'kelas.id')
            ->select('mata_pelajarans.*') // Pastikan hanya mengambil kolom dari mata pelajaran
            ->with(['kelas', 'guru', 'lingkupMateris'])
            ->where('mata_pelajarans.guru_id', $guru->id)
            ->when($tahunAjaranId, function ($query) use ($tahunAjaranId) {
                return $query->where('mata_pelajarans.tahun_ajaran_id', $tahunAjaranId);
            })
            ->orderBy('kelas.nomor_kelas', 'asc') // Urutkan berdasarkan nomor kelas
            ->orderBy('kelas.nama_kelas', 'asc')  // Kemudian nama kelas (A, B, C, dll)
            ->orderBy('mata_pelajarans.nama_pelajaran', 'asc') // Terakhir berdasarkan nama mata pelajaran
            ->paginate(10);

        return view('pengajar.subject', compact('subjects'));
    }

    public function teacherCreate(LearningStructureCopyService $copyService)
    {
        // Ambil ID guru yang sedang login
        $guruId = Auth::guard('guru')->id();
        $guru = Auth::guard('guru')->user();
        $tahunAjaranId = session('tahun_ajaran_id');

        // Query untuk mendapatkan kelas
        $classesQuery = Kelas::query();

        // Filter kelas berdasarkan tahun ajaran
        $classesQuery->when($tahunAjaranId, function ($query) use ($tahunAjaranId) {
            return $query->where('tahun_ajaran_id', $tahunAjaranId);
        });

        // Jika guru ini adalah wali kelas, tambahkan kelas walinya ke dalam daftar
        if ($guru->isWaliKelas()) {
            $kelasWali = $guru->kelasWali()->first();

            // Ambil kelas yang diajar oleh guru (sebagai pengajar) atau kelas wali
            $classesQuery->where(function ($query) use ($guruId, $kelasWali) {
                $query->whereHas('guru', function ($q) use ($guruId) {
                    $q->where('guru_id', $guruId)
                        ->where('role', 'pengajar');
                });

                // Jika punya kelas wali, tambahkan sebagai OR condition
                if ($kelasWali) {
                    $query->orWhere('id', $kelasWali->id);
                }
            });
        } else {
            // Jika bukan wali kelas, hanya ambil kelas yang diajar sebagai pengajar biasa
            $classesQuery->whereHas('guru', function ($query) use ($guruId) {
                $query->where('guru_id', $guruId)
                    ->where('role', 'pengajar');
            });
        }

        // Ambil hasil query dan urutkan
        $classes = $classesQuery->orderBy('nomor_kelas')
            ->orderBy('nama_kelas')
            ->get();
        $semester = $tahunAjaranId ? $this->copySemesterContext((int) $tahunAjaranId) : null;
        $lmTpCopyCandidates = $tahunAjaranId && $semester
            ? $this->learningCopyCandidatePayload($copyService->copyableSourceCandidates((int) $tahunAjaranId, (int) $semester, $guru))
            : [];

        return view('pengajar.add_subject', compact('classes', 'lmTpCopyCandidates'));
    }

    public function teacherStore(Request $request, LearningStructureCopyService $copyService)
    {
        $guru = auth()->guard('guru')->user();

        try {
            // Validasi array subjects
            $request->validate([
                'subjects' => 'required|array',
                'subjects.*.mata_pelajaran' => 'required|string|max:255',
                'subjects.*.kelas' => 'required|exists:kelas,id',
                'subjects.*.semester' => 'required|integer|min:1|max:2',
                'subjects.*.teaching_type' => 'nullable|in:regular,muatan_lokal,specialist',
                'subjects.*.lingkup_materi' => 'nullable|array',
                'subjects.*.lingkup_materi.*' => 'nullable|string|max:255',
                'subjects.*.copy_lm_tp' => 'nullable|boolean',
                'subjects.*.copy_lm_tp_source_id' => 'nullable|integer',
            ]);

            $errorBag = new MessageBag;
            $seenSubjects = [];
            $copySourceIds = [];
            $manualLingkupMateriByIndex = [];
            $tahunAjaranId = session('tahun_ajaran_id');

            foreach ($request->subjects as $index => $subjectData) {
                $kelasId = $subjectData['kelas'];
                $kelas = Kelas::find($kelasId);

                if (! $kelas) {
                    $errorBag->add("subjects.$index.kelas", "Kelas tidak valid untuk mata pelajaran {$subjectData['mata_pelajaran']}.");

                    continue;
                }

                if (! $guru->canTeachClass($kelasId)) {
                    $errorBag->add("subjects.$index.kelas", 'Anda tidak memiliki akses untuk mengajar di kelas ini.');

                    continue;
                }

                $flags = $this->subjectFlags($subjectData);
                $this->addAssignmentErrors(
                    $errorBag,
                    "subjects.$index",
                    $this->validateSubjectAssignment($guru, $kelas, $flags)
                );

                if ($errorBag->has("subjects.$index.guru_pengampu") || $errorBag->has("subjects.$index.teaching_type")) {
                    continue;
                }

                $duplicateKey = strtolower(trim($subjectData['mata_pelajaran'])).'|'.$kelasId.'|'.$subjectData['semester'];
                if (in_array($duplicateKey, $seenSubjects, true)) {
                    $errorBag->add("subjects.$index.mata_pelajaran", "Mata pelajaran {$subjectData['mata_pelajaran']} diduplikasi lebih dari sekali dalam form yang sama.");

                    continue;
                }
                $seenSubjects[] = $duplicateKey;

                // Cek duplikasi mata pelajaran
                $exists = MataPelajaran::where('kelas_id', $kelasId)
                    ->where('nama_pelajaran', $subjectData['mata_pelajaran'])
                    ->where('semester', $subjectData['semester'])
                    ->exists();

                if ($exists) {
                    $errorBag->add("subjects.$index.mata_pelajaran", "Mata pelajaran {$subjectData['mata_pelajaran']} untuk kelas {$kelas->nomor_kelas} {$kelas->nama_kelas} semester {$subjectData['semester']} sudah ada.");

                    continue;
                }

                try {
                    $copySource = $this->resolveInlineCopySource(
                        $copyService,
                        $subjectData['mata_pelajaran'],
                        (int) $kelasId,
                        (int) $tahunAjaranId,
                        (int) $subjectData['semester'],
                        $guru,
                        null,
                        (int) ($subjectData['copy_lm_tp_source_id'] ?? 0),
                        $this->inlineCopyRequested($subjectData)
                    );

                    if ($copySource) {
                        $copySourceIds[$index] = $copySource->id;
                    }
                } catch (InvalidArgumentException $exception) {
                    $errorBag->add("subjects.$index.copy_lm_tp_source_id", $exception->getMessage());

                    continue;
                }

                $manualLingkupMateri = $this->manualLingkupMateriInput($subjectData['lingkup_materi'] ?? []);
                $manualLingkupMateriByIndex[$index] = $manualLingkupMateri;

                if (! $copySource && $manualLingkupMateri === []) {
                    $errorBag->add("subjects.$index.lingkup_materi", 'Lingkup materi harus diisi jika tidak menyalin LM/TP dari kelas paralel.');
                } elseif (! $copySource && $this->hasBlankLingkupMateriInput($subjectData['lingkup_materi'] ?? [])) {
                    $errorBag->add("subjects.$index.lingkup_materi", 'Semua lingkup materi harus diisi.');
                }
            }

            if ($errorBag->any()) {
                return redirect()->back()
                    ->withErrors($errorBag)
                    ->withInput();
            }

            DB::beginTransaction();
            $successCount = 0;
            $copySummaries = [];

            foreach ($request->subjects as $index => $subjectData) {
                $kelasId = $subjectData['kelas'];
                $flags = $this->subjectFlags($subjectData);

                // Simpan Mata Pelajaran
                $mataPelajaran = MataPelajaran::create([
                    'nama_pelajaran' => $subjectData['mata_pelajaran'],
                    'kelas_id' => $kelasId,
                    'guru_id' => $guru->id,
                    'semester' => $subjectData['semester'],
                    'is_muatan_lokal' => $flags['is_muatan_lokal'],
                    'allow_non_wali' => $flags['allow_non_wali'],
                ]);

                // Simpan Lingkup Materi
                foreach ($manualLingkupMateriByIndex[$index] ?? [] as $judulLingkupMateri) {
                    LingkupMateri::create([
                        'mata_pelajaran_id' => $mataPelajaran->id,
                        'judul_lingkup_materi' => $judulLingkupMateri,
                    ]);
                }

                if (isset($copySourceIds[$index])) {
                    $copySummaries[] = $this->copyInlineLearningStructure(
                        $copyService,
                        (int) $copySourceIds[$index],
                        $mataPelajaran,
                        $guru
                    );
                }

                $successCount++;
            }

            DB::commit();

            $copyMessage = $this->aggregateCopyLmTpMessage($copySummaries);

            return redirect()->route('pengajar.subject.index')
                ->with('success', trim("Berhasil menambahkan {$successCount} mata pelajaran! {$copyMessage}"));
        } catch (ValidationException $e) {
            throw $e;
        } catch (InvalidArgumentException $e) {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }

            return redirect()->back()
                ->with('error', $e->getMessage())
                ->withInput();
        } catch (\Exception $e) {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            Log::error('Failed to store teacher subject data', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'user_id' => auth()->guard('guru')->id(),
            ]);

            return redirect()->back()
                ->with('error', 'Terjadi kesalahan saat menyimpan data. Silakan coba lagi.')
                ->withInput();
        }
    }

    public function teacherEdit($id, LearningStructureCopyService $copyService)
    {
        // Ambil data mata pelajaran yang akan diedit
        $subject = MataPelajaran::with('lingkupMateris')->findOrFail($id);

        // Ambil ID guru yang sedang login
        $guruId = Auth::guard('guru')->id();
        $guru = Auth::guard('guru')->user();

        // Gunakan tahun ajaran dari mata pelajaran
        $tahunAjaranId = $subject->tahun_ajaran_id;

        // Default value - set terlebih dahulu
        $disableKelasDropdown = false;

        // Verifikasi guru adalah pemilik mata pelajaran
        if ($subject->guru_id != $guruId) {
            abort(403, 'Anda tidak memiliki akses untuk mengedit mata pelajaran ini.');
        }

        // Query untuk mendapatkan kelas
        $classesQuery = Kelas::query();

        // Filter kelas berdasarkan tahun ajaran mata pelajaran
        $classesQuery->where('tahun_ajaran_id', $tahunAjaranId);

        // Jika guru ini adalah wali kelas
        if ($guru->isWaliKelas()) {
            $kelasWaliId = $guru->getWaliKelasId();

            // Jika mata pelajaran ini di kelas wali, kita akan menonaktifkan dropdown
            $isWaliKelasMatajar = ($subject->kelas_id == $kelasWaliId);

            // Beri tahu view bahwa ini mata pelajaran di kelas wali
            $disableKelasDropdown = $isWaliKelasMatajar;

            // Ambil kelas yang diajar oleh guru (sebagai pengajar) atau kelas wali
            $classesQuery->whereHas('guru', function ($query) use ($guruId) {
                $query->where('guru_id', $guruId);
            });
        } else {
            // Jika bukan wali kelas, hanya ambil kelas yang diajar sebagai pengajar biasa
            $classesQuery->whereHas('guru', function ($query) use ($guruId) {
                $query->where('guru_id', $guruId);
            });
        }

        // Ambil hasil query dan urutkan
        $classes = $classesQuery->orderBy('nomor_kelas')
            ->orderBy('nama_kelas')
            ->get();
        $lmTpCopyCandidates = $this->learningCopyCandidatePayload(
            $copyService->sourceCandidatesForContext(
                $subject->nama_pelajaran,
                (int) $subject->kelas_id,
                (int) $tahunAjaranId,
                (int) $subject->semester,
                $guru,
                (int) $subject->id
            )
        );

        return view('pengajar.edit_subject', compact('subject', 'classes', 'disableKelasDropdown', 'lmTpCopyCandidates'));
    }

    public function teacherUpdate(Request $request, $id, LearningStructureCopyService $copyService)
    {
        $guru = auth()->guard('guru')->user();
        $subject = MataPelajaran::where('guru_id', $guru->id)
            ->findOrFail($id);

        $validated = $request->validate([
            'mata_pelajaran' => 'required|string|max:255',
            'kelas' => 'required|exists:kelas,id',
            'semester' => 'required|integer|min:1|max:2',
            'teaching_type' => 'nullable|in:regular,muatan_lokal,specialist',
            'lingkup_materi' => 'nullable|array',
            'lingkup_materi.*' => 'nullable|string|max:255',
            'delete_ids' => 'nullable|array',
            'delete_ids.*' => 'integer|exists:lingkup_materis,id',
            'copy_lm_tp' => 'nullable|boolean',
            'copy_lm_tp_source_id' => 'nullable|integer',
        ]);

        $kelasId = $validated['kelas'];
        $kelas = Kelas::find($kelasId);
        $flags = $this->subjectFlags($request->all());

        if (! $kelas) {
            return back()->withErrors(['kelas' => 'Kelas tidak valid.'])->withInput();
        }

        // Check for duplicates excluding current record
        $exists = MataPelajaran::where('kelas_id', $validated['kelas'])
            ->where('nama_pelajaran', $validated['mata_pelajaran'])
            ->where('semester', $validated['semester'])
            ->where('id', '!=', $id) // This is critical - exclude the current record
            ->exists();

        if ($exists) {
            return back()->withErrors([
                'mata_pelajaran' => 'Mata pelajaran dengan nama yang sama sudah ada di kelas ini untuk semester yang sama.',
            ])->withInput();
        }

        // Verify teacher can teach in selected class
        if (! $guru->canTeachClass($kelasId)) {
            return back()->withErrors([
                'kelas' => 'Anda tidak memiliki akses untuk mengajar di kelas ini.',
            ])->withInput();
        }

        $assignmentErrors = $this->validateSubjectAssignment($guru, $kelas, $flags);
        if (! empty($assignmentErrors)) {
            return back()->withErrors($assignmentErrors)->withInput();
        }

        try {
            $copySource = $this->resolveInlineCopySource(
                $copyService,
                $validated['mata_pelajaran'],
                (int) $validated['kelas'],
                (int) $subject->tahun_ajaran_id,
                (int) $validated['semester'],
                $guru,
                (int) $subject->id,
                (int) $request->input('copy_lm_tp_source_id', 0),
                $request->boolean('copy_lm_tp')
            );
        } catch (InvalidArgumentException $exception) {
            return back()->withErrors([
                'copy_lm_tp_source_id' => $exception->getMessage(),
            ])->withInput();
        }

        $manualLingkupMateri = $this->manualLingkupMateriInput($validated['lingkup_materi'] ?? []);
        if (! $copySource && $manualLingkupMateri === []) {
            return back()->withErrors([
                'lingkup_materi' => 'Lingkup materi harus diisi jika tidak menyalin LM/TP dari kelas paralel.',
            ])->withInput();
        }

        if (! $copySource && $this->hasBlankLingkupMateriInput($validated['lingkup_materi'] ?? [])) {
            return back()->withErrors([
                'lingkup_materi' => 'Semua lingkup materi harus diisi.',
            ])->withInput();
        }

        try {
            DB::beginTransaction();

            // Update the subject with the server-determined is_muatan_lokal value
            $subject->update([
                'nama_pelajaran' => $validated['mata_pelajaran'],
                'kelas_id' => $validated['kelas'],
                'semester' => $validated['semester'],
                'is_muatan_lokal' => $flags['is_muatan_lokal'],
                'allow_non_wali' => $flags['allow_non_wali'],
            ]);

            $deleteIds = collect($request->input('delete_ids', []))
                ->map(fn ($deleteId) => (int) $deleteId)
                ->filter()
                ->values();

            if ($deleteIds->isNotEmpty()) {
                $subject->lingkupMateris()
                    ->whereIn('id', $deleteIds)
                    ->get()
                    ->each(function ($lingkupMateri) {
                        $lingkupMateri->delete();
                    });
            }

            if ($manualLingkupMateri !== [] || ! $copySource) {
                // Handle lingkup materi updates
                $existingLingkupMateris = $subject->lingkupMateris()->get();
                $existingTitles = $existingLingkupMateris->pluck('judul_lingkup_materi')->toArray();
                $newTitles = $manualLingkupMateri;

                // Process existing entries
                foreach ($existingLingkupMateris as $existingLM) {
                    $newTitleIndex = array_search($existingLM->judul_lingkup_materi, $newTitles);

                    if ($newTitleIndex !== false) {
                        // Keep and remove from new titles list
                        unset($newTitles[$newTitleIndex]);
                    } else {
                        // Delete if not in new list
                        $existingLM->delete();
                    }
                }

                // Add new entries
                foreach ($newTitles as $newTitle) {
                    LingkupMateri::create([
                        'mata_pelajaran_id' => $subject->id,
                        'judul_lingkup_materi' => $newTitle,
                    ]);
                }
            }

            $copySummary = null;
            if ($copySource) {
                $copySummary = $this->copyInlineLearningStructure(
                    $copyService,
                    (int) $copySource->id,
                    $subject->fresh(['kelas', 'lingkupMateris.tujuanPembelajarans']),
                    $guru
                );
            }

            DB::commit();

            $copyMessage = $copySummary ? $this->aggregateCopyLmTpMessage([$copySummary]) : '';

            return redirect()->route('pengajar.subject.index')
                ->with('success', trim("Mata Pelajaran berhasil diperbarui! {$copyMessage}"));
        } catch (ValidationException $e) {
            throw $e;
        } catch (InvalidArgumentException $e) {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }

            return back()->with('error', $e->getMessage())->withInput();
        } catch (\Exception $e) {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            Log::error('Failed to update teacher subject data', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'user_id' => auth()->guard('guru')->id(),
                'subject_id' => $id,
            ]);

            return back()->with('error', 'Terjadi kesalahan. Silakan coba lagi.')->withInput();
        }
    }

    public function teacherDestroy($id)
    {
        $guru = auth()->guard('guru')->user();
        $subject = MataPelajaran::where('guru_id', $guru->id)
            ->findOrFail($id);

        try {
            $subject->delete();

            return redirect()->route('pengajar.subject.index')
                ->with('success', 'Mata Pelajaran berhasil dihapus!');
        } catch (\Exception $e) {
            Log::error('Failed to delete teacher subject data', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'user_id' => auth()->guard('guru')->id(),
                'subject_id' => $id,
            ]);

            return redirect()->back()
                ->with('error', 'Terjadi kesalahan saat menghapus data. Silakan coba lagi.');
        }
    }

    /**
     * @param  array<string, mixed>  $subjectData
     * @return array{is_muatan_lokal: bool, allow_non_wali: bool}
     */
    private function subjectFlags(array $subjectData): array
    {
        return app(SubjectTeacherAssignmentValidator::class)->flagsFromRequest($subjectData);
    }

    private function kelasExistsRule(?int $tahunAjaranId)
    {
        $rule = Rule::exists('kelas', 'id');

        if ($tahunAjaranId) {
            $rule->where(fn ($query) => $query->where('tahun_ajaran_id', $tahunAjaranId));
        }

        return $rule;
    }

    /**
     * @param  array{is_muatan_lokal: bool, allow_non_wali: bool}  $flags
     * @return array<string, string>
     */
    private function validateSubjectAssignment(Guru $guru, Kelas $kelas, array $flags): array
    {
        return app(SubjectTeacherAssignmentValidator::class)->validate(
            $guru,
            $kelas,
            $flags['is_muatan_lokal'],
            $flags['allow_non_wali']
        );
    }

    /**
     * @return array<int, array<int, int>>
     */
    private function teacherWaliClassIds(?int $tahunAjaranId): array
    {
        return DB::table('guru_kelas')
            ->join('kelas', 'guru_kelas.kelas_id', '=', 'kelas.id')
            ->where('guru_kelas.is_wali_kelas', true)
            ->where('guru_kelas.role', 'wali_kelas')
            ->whereNull('kelas.deleted_at')
            ->when($tahunAjaranId, function ($query) use ($tahunAjaranId) {
                $query->where('kelas.tahun_ajaran_id', $tahunAjaranId);
            })
            ->select('guru_kelas.guru_id', 'guru_kelas.kelas_id')
            ->get()
            ->groupBy('guru_id')
            ->map(fn ($rows) => $rows->pluck('kelas_id')->map(fn ($kelasId) => (int) $kelasId)->values()->all())
            ->all();
    }

    /**
     * @return array<int, array<int, int>>
     */
    private function teacherTeachingClassIds(?int $tahunAjaranId): array
    {
        return DB::table('guru_kelas')
            ->join('kelas', 'guru_kelas.kelas_id', '=', 'kelas.id')
            ->where('guru_kelas.is_wali_kelas', false)
            ->where('guru_kelas.role', 'pengajar')
            ->whereNull('kelas.deleted_at')
            ->when($tahunAjaranId, function ($query) use ($tahunAjaranId) {
                $query->where('kelas.tahun_ajaran_id', $tahunAjaranId);
            })
            ->select('guru_kelas.guru_id', 'guru_kelas.kelas_id')
            ->get()
            ->groupBy('guru_id')
            ->map(fn ($rows) => $rows->pluck('kelas_id')->map(fn ($kelasId) => (int) $kelasId)->values()->all())
            ->all();
    }

    /**
     * @param  array<string, string>  $errors
     */
    private function addAssignmentErrors(MessageBag $errorBag, string $prefix, array $errors): void
    {
        foreach ($errors as $field => $message) {
            $errorBag->add("{$prefix}.{$field}", $message);
        }
    }
}

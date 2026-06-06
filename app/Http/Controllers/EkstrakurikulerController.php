<?php

namespace App\Http\Controllers;

use App\Models\Ekstrakurikuler;
use App\Models\NilaiEkstrakurikuler;
use App\Models\TahunAjaran;
use App\Services\SiswaKelasSemesterResolver;
use App\Traits\RequiresTahunAjaran;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class EkstrakurikulerController extends Controller
{
    use RequiresTahunAjaran;

    public function index(Request $request)
    {
        $tahunAjaranId = session('tahun_ajaran_id');
        $query = Ekstrakurikuler::query();

        if ($tahunAjaranId && Schema::hasColumn('ekstrakurikulers', 'tahun_ajaran_id')) {
            $query->where('tahun_ajaran_id', $tahunAjaranId);
        }

        if ($request->has('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('nama_ekstrakurikuler', 'LIKE', "%{$search}%")
                    ->orWhere('pembina', 'LIKE', "%{$search}%");
            });
        }

        $ekstrakurikulers = $query->paginate(10);

        return view('admin.ekstrakulikuler', compact('ekstrakurikulers'));
    }

    public function create()
    {
        return view('data.add_data_extracurriculer');
    }

    public function store(Request $request)
    {
        try {
            $validated = $request->validate([
                'nama_ekstrakurikuler' => 'required|string|max:255',
                'pembina' => 'required|string|max:255',
            ], [
                'nama_ekstrakurikuler.required' => 'Nama ekstrakurikuler wajib diisi',
                'pembina.required' => 'Nama pembina wajib diisi',
            ]);

            Ekstrakurikuler::create($validated);

            return redirect()->route('ekstra.index')
                ->with('success', 'Data ekstrakurikuler berhasil ditambahkan');
        } catch (\Exception $e) {
            Log::error('Error creating ekstrakurikuler: ' . $e->getMessage());

            return back()
                ->with('error', 'Terjadi kesalahan sistem')
                ->withInput();
        }
    }

    public function edit($id)
    {
        $ekstrakurikuler = Ekstrakurikuler::findOrFail($id);

        return view('data.edit_data_extracurriculer', compact('ekstrakurikuler'));
    }

    public function update(Request $request, $id)
    {
        try {
            $validated = $request->validate([
                'nama_ekstrakurikuler' => 'required|string|max:255',
                'pembina' => 'required|string|max:255',
            ], [
                'nama_ekstrakurikuler.required' => 'Nama ekstrakurikuler wajib diisi',
                'pembina.required' => 'Nama pembina wajib diisi',
            ]);

            $ekstrakurikuler = Ekstrakurikuler::findOrFail($id);
            $ekstrakurikuler->update($validated);

            return redirect()->route('ekstra.index')
                ->with('success', 'Data ekstrakurikuler berhasil diperbarui');
        } catch (\Exception $e) {
            Log::error('Error updating ekstrakurikuler: ' . $e->getMessage());

            return back()
                ->with('error', 'Terjadi kesalahan sistem')
                ->withInput();
        }
    }

    public function destroy($id)
    {
        try {
            $ekstrakurikuler = Ekstrakurikuler::findOrFail($id);
            $ekstrakurikuler->delete();

            return redirect()->route('ekstra.index')
                ->with('success', 'Data ekstrakurikuler berhasil dihapus');
        } catch (\Exception $e) {
            Log::error('Error deleting ekstrakurikuler: ' . $e->getMessage());

            return back()->with('error', 'Terjadi kesalahan sistem');
        }
    }

    public function waliKelasIndex(Request $request)
    {
        $tahunAjaranId = $this->getValidTahunAjaranId();

        if (!$tahunAjaranId) {
            return $this->failTahunAjaranNotSet($request);
        }

        $context = $this->resolveWaliEkstrakurikulerContext($tahunAjaranId);

        $siswas = $this->getWaliKelasSiswas($context['kelas_id'], $tahunAjaranId, $context['semester']);
        $masterEkskul = $this->getMasterEkstrakurikuler($tahunAjaranId);
        $pramukaId = $masterEkskul->first(function ($ekskul) {
            return strcasecmp($ekskul->nama_ekstrakurikuler, 'Pramuka') === 0;
        })?->id;

        $ekskulData = $this->buildEkstrakurikulerDataMap($siswas, $tahunAjaranId, $context['semester']);

        return view('wali_kelas.ekstrakurikuler', compact(
            'siswas',
            'ekskulData',
            'masterEkskul',
            'pramukaId'
        ));
    }

    public function bulkSave(Request $request): JsonResponse
    {
        $tahunAjaranId = $this->getValidTahunAjaranId();

        if (!$tahunAjaranId) {
            return $this->failTahunAjaranNotSet($request, true);
        }

        $context = $this->resolveWaliEkstrakurikulerContext($tahunAjaranId);

        $siswas = $this->getWaliKelasSiswas($context['kelas_id'], $tahunAjaranId, $context['semester']);
        $allowedStudentIds = $siswas->pluck('id')->map(fn ($id) => (int) $id)->all();
        $masterEkskul = $this->getMasterEkstrakurikuler($tahunAjaranId);
        $allowedEkskulIds = $masterEkskul->pluck('id')->map(fn ($id) => (int) $id)->all();

        $validator = Validator::make($request->all(), [
            'rows' => 'nullable|array',
            'rows.*.id' => 'nullable|integer',
            'rows.*.siswa_id' => 'required|exists:siswas,id',
            'rows.*.ekstrakurikuler_id' => 'required|exists:ekstrakurikulers,id',
            'rows.*.deskripsi' => 'nullable|string|max:500',
            'deleted_ids' => 'nullable|array',
            'deleted_ids.*' => 'exists:nilai_ekstrakurikuler,id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validasi data ekstrakurikuler gagal.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $validated = $validator->validated();

        $rows = collect($validated['rows'] ?? [])->values();
        $deletedIds = collect($validated['deleted_ids'] ?? [])
            ->map(fn ($id) => (int) $id)
            ->filter()
            ->unique()
            ->values();

        try {
            $this->validateBulkEkstrakurikulerRows($rows, $allowedStudentIds, $allowedEkskulIds);
            $this->validateDeletedEkstrakurikulerIds($deletedIds, $allowedStudentIds, $tahunAjaranId, $context['semester']);

            DB::transaction(function () use ($rows, $deletedIds, $allowedStudentIds, $tahunAjaranId, $context) {
                if ($deletedIds->isNotEmpty()) {
                    NilaiEkstrakurikuler::whereIn('id', $deletedIds->all())
                        ->whereIn('siswa_id', $allowedStudentIds)
                        ->context($tahunAjaranId, $context['semester'])
                        ->get()
                        ->each(function (NilaiEkstrakurikuler $nilaiEkstrakurikuler) {
                            $nilaiEkstrakurikuler->delete();
                        });
                }

                foreach ($rows as $index => $row) {
                    $duplicateQuery = NilaiEkstrakurikuler::where('siswa_id', $row['siswa_id'])
                        ->where('ekstrakurikuler_id', $row['ekstrakurikuler_id'])
                        ->context($tahunAjaranId, $context['semester']);

                    if (!empty($row['id'])) {
                        $duplicateQuery->where('id', '!=', $row['id']);
                    }

                    if ($duplicateQuery->exists()) {
                        throw ValidationException::withMessages([
                            "rows.{$index}.ekstrakurikuler_id" => ['Siswa sudah memiliki data untuk ekstrakurikuler yang sama.'],
                        ]);
                    }

                    $payload = [
                        'siswa_id' => $row['siswa_id'],
                        'ekstrakurikuler_id' => $row['ekstrakurikuler_id'],
                        'deskripsi' => $row['deskripsi'] ?? '',
                        'tahun_ajaran_id' => $tahunAjaranId,
                        'semester' => $context['semester'],
                    ];

                    if (!empty($row['id'])) {
                        $nilaiEkstrakurikuler = NilaiEkstrakurikuler::where('id', $row['id'])
                            ->whereIn('siswa_id', $allowedStudentIds)
                            ->context($tahunAjaranId, $context['semester'])
                            ->first();

                        if (!$nilaiEkstrakurikuler) {
                            abort(403);
                        }

                        abort_unless((int) $nilaiEkstrakurikuler->siswa_id === (int) $row['siswa_id'], 403);

                        $nilaiEkstrakurikuler->update($payload);
                    } else {
                        NilaiEkstrakurikuler::create($payload);
                    }
                }
            });
        } catch (ValidationException $exception) {
            return response()->json([
                'success' => false,
                'message' => 'Validasi data ekstrakurikuler gagal.',
                'errors' => $exception->errors(),
            ], 422);
        }

        return response()->json([
            'success' => true,
            'message' => 'Data ekstrakurikuler berhasil disimpan.',
            'ekskul_data' => $this->buildEkstrakurikulerDataMap($siswas, $tahunAjaranId, $context['semester']),
        ]);
    }

    public function waliKelasCreate()
    {
        $guru = auth()->guard('guru')->user();
        $tahunAjaranId = $this->getValidTahunAjaranId();
        abort_unless($tahunAjaranId, 403);

        $context = $this->resolveWaliEkstrakurikulerContext($tahunAjaranId);

        Log::info('EkstrakurikulerController::waliKelasCreate', [
            'guru_id' => $guru->id,
            'tahun_ajaran_id' => $tahunAjaranId,
            'semester' => $context['semester'],
            'kelas_wali_id' => $context['kelas_id'],
        ]);

        $siswa = $this->getWaliKelasSiswas($context['kelas_id'], $tahunAjaranId, $context['semester']);

        $ekstrakurikuler = $this->getMasterEkstrakurikuler($tahunAjaranId);

        Log::info('EkstrakurikulerController found:', [
            'siswa_count' => $siswa->count(),
            'ekstrakurikuler_count' => $ekstrakurikuler->count(),
        ]);

        $kelasWaliId = $context['kelas_id'];

        return view('wali_kelas.add_ekstrakurikuler', compact('ekstrakurikuler', 'siswa', 'kelasWaliId'));
    }

    public function waliKelasStore(Request $request)
    {
        $tahunAjaranId = $this->getValidTahunAjaranId();

        if (!$tahunAjaranId) {
            return $this->failTahunAjaranNotSet($request);
        }

        $context = $this->resolveWaliEkstrakurikulerContext($tahunAjaranId);

        $validated = $request->validate([
            'siswa_id' => 'required|exists:siswas,id',
            'ekstrakurikuler_id' => 'required|exists:ekstrakurikulers,id',
            'predikat' => 'required|in:A,B,C,D',
            'deskripsi' => 'nullable|string',
        ]);

        $siswas = $this->getWaliKelasSiswas($context['kelas_id'], $tahunAjaranId, $context['semester']);
        $allowedStudentIds = $siswas->pluck('id')->map(fn ($id) => (int) $id)->all();
        $allowedEkskulIds = $this->getMasterEkstrakurikuler($tahunAjaranId)->pluck('id')->map(fn ($id) => (int) $id)->all();

        abort_unless(in_array((int) $validated['siswa_id'], $allowedStudentIds, true), 403);
        abort_unless(in_array((int) $validated['ekstrakurikuler_id'], $allowedEkskulIds, true), 403);

        $exists = NilaiEkstrakurikuler::where('siswa_id', $validated['siswa_id'])
            ->where('ekstrakurikuler_id', $validated['ekstrakurikuler_id'])
            ->context($tahunAjaranId, $context['semester'])
            ->exists();

        if ($exists) {
            return back()->with('error', 'Siswa sudah memiliki nilai untuk ekstrakurikuler ini pada semester yang sama.');
        }

        $nilaiEkstrakurikuler = new NilaiEkstrakurikuler([
            'siswa_id' => $validated['siswa_id'],
            'ekstrakurikuler_id' => $validated['ekstrakurikuler_id'],
            'predikat' => $validated['predikat'],
            'deskripsi' => $validated['deskripsi'],
            'tahun_ajaran_id' => $tahunAjaranId,
            'semester' => $context['semester'],
        ]);

        $nilaiEkstrakurikuler->save();

        return redirect()->route('wali_kelas.ekstrakurikuler.index')
            ->with('success', 'Data ekstrakurikuler berhasil ditambahkan');
    }

    public function waliKelasEdit($id)
    {
        $tahunAjaranId = $this->getValidTahunAjaranId();
        abort_unless($tahunAjaranId, 403);

        $context = $this->resolveWaliEkstrakurikulerContext($tahunAjaranId);

        try {
            $nilaiEkstrakurikuler = $this->findAuthorizedNilaiEkstrakurikuler(
                (int) $id,
                $context['kelas_id'],
                $tahunAjaranId,
                $context['semester']
            );

            return view('wali_kelas.edit_ekstrakurikuler', compact('nilaiEkstrakurikuler'));
        } catch (\Exception $e) {
            if ($e instanceof HttpExceptionInterface && $e->getStatusCode() === 403) {
                throw $e;
            }

            Log::error('Error editing ekstrakurikuler: ' . $e->getMessage());

            return redirect()->route('wali_kelas.ekstrakurikuler.index')
                ->with('error', 'Data ekstrakurikuler tidak ditemukan atau Anda tidak memiliki akses.');
        }
    }

    public function waliKelasUpdate(Request $request, $id)
    {
        $tahunAjaranId = $this->getValidTahunAjaranId();

        if (!$tahunAjaranId) {
            return $this->failTahunAjaranNotSet($request);
        }

        $context = $this->resolveWaliEkstrakurikulerContext($tahunAjaranId);

        $validated = $request->validate([
            'predikat' => 'required|in:A,B,C,D',
            'deskripsi' => 'nullable|string',
        ]);

        $validated['tahun_ajaran_id'] = $tahunAjaranId;

        try {
            $nilaiEkstrakurikuler = $this->findAuthorizedNilaiEkstrakurikuler(
                (int) $id,
                $context['kelas_id'],
                $tahunAjaranId,
                $context['semester']
            );

            $validated['semester'] = $context['semester'];
            $nilaiEkstrakurikuler->update($validated);

            return redirect()->route('wali_kelas.ekstrakurikuler.index')
                ->with('success', 'Data ekstrakurikuler berhasil diperbarui');
        } catch (\Exception $e) {
            if ($e instanceof HttpExceptionInterface && $e->getStatusCode() === 403) {
                throw $e;
            }

            Log::error('Error updating ekstrakurikuler: ' . $e->getMessage());

            return redirect()->route('wali_kelas.ekstrakurikuler.index')
                ->with('error', 'Data ekstrakurikuler tidak ditemukan atau Anda tidak memiliki akses.');
        }
    }

    public function waliKelasDestroy($id)
    {
        $tahunAjaranId = $this->getValidTahunAjaranId();
        abort_unless($tahunAjaranId, 403);

        $context = $this->resolveWaliEkstrakurikulerContext($tahunAjaranId);
        $nilaiEkstrakurikuler = $this->findAuthorizedNilaiEkstrakurikuler(
            (int) $id,
            $context['kelas_id'],
            $tahunAjaranId,
            $context['semester']
        );

        $nilaiEkstrakurikuler->delete();

        return redirect()->route('wali_kelas.ekstrakurikuler.index')
            ->with('success', 'Data ekstrakurikuler berhasil dihapus');
    }

    private function getKelasWaliId(int $guruId, ?int $tahunAjaranId): ?int
    {
        return DB::table('guru_kelas')
            ->join('kelas', 'guru_kelas.kelas_id', '=', 'kelas.id')
            ->where('guru_kelas.guru_id', $guruId)
            ->where('guru_kelas.is_wali_kelas', true)
            ->where('guru_kelas.role', 'wali_kelas')
            ->when($tahunAjaranId, function ($query) use ($tahunAjaranId) {
                $query->where('kelas.tahun_ajaran_id', $tahunAjaranId);
            })
            ->value('kelas.id');
    }

    private function resolveWaliEkstrakurikulerContext(int $tahunAjaranId): array
    {
        $waliKelas = auth()->guard('guru')->user();

        abort_unless($waliKelas && session('selected_role') === 'wali_kelas', 403);

        $semester = $this->getSemesterForTahunAjaran($tahunAjaranId);
        $kelasWaliId = $this->getKelasWaliId($waliKelas->id, $tahunAjaranId);

        abort_unless($kelasWaliId, 403);

        return [
            'guru_id' => (int) $waliKelas->id,
            'kelas_id' => (int) $kelasWaliId,
            'tahun_ajaran_id' => $tahunAjaranId,
            'semester' => $semester,
        ];
    }

    private function getSemesterForTahunAjaran(int $tahunAjaranId): int
    {
        $semester = TahunAjaran::whereKey($tahunAjaranId)->value('semester');

        abort_unless(in_array((int) $semester, [1, 2], true), 403);

        return (int) $semester;
    }

    private function getWaliKelasSiswas(int $kelasWaliId, int $tahunAjaranId, int $semester): Collection
    {
        return app(SiswaKelasSemesterResolver::class)
            ->studentsForClass($kelasWaliId, $tahunAjaranId, $semester, true)
            ->unique('id')
            ->values();
    }

    private function getMasterEkstrakurikuler(?int $tahunAjaranId): Collection
    {
        return Ekstrakurikuler::query()
            ->when($tahunAjaranId, function ($query) use ($tahunAjaranId) {
                $query->where('tahun_ajaran_id', $tahunAjaranId);
            })
            ->orderBy('nama_ekstrakurikuler')
            ->get(['id', 'nama_ekstrakurikuler']);
    }

    private function buildEkstrakurikulerDataMap(Collection $siswas, ?int $tahunAjaranId, int $semester): array
    {
        $studentIds = $siswas->pluck('id');

        $records = NilaiEkstrakurikuler::with('ekstrakurikuler:id,nama_ekstrakurikuler')
            ->whereIn('siswa_id', $studentIds)
            ->when($tahunAjaranId, function ($query) use ($tahunAjaranId) {
                $query->where('tahun_ajaran_id', $tahunAjaranId);
            })
            ->where('semester', $semester)
            ->orderBy('siswa_id')
            ->orderBy('id')
            ->get()
            ->groupBy('siswa_id');

        return $siswas->mapWithKeys(function ($siswa) use ($records) {
            return [
                (string) $siswa->id => $records->get($siswa->id, collect())
                    ->map(function (NilaiEkstrakurikuler $nilai) {
                        return [
                            'id' => (int) $nilai->id,
                            'siswa_id' => (int) $nilai->siswa_id,
                            'ekstrakurikuler_id' => (int) $nilai->ekstrakurikuler_id,
                            'ekstrakurikuler_nama' => $nilai->ekstrakurikuler?->nama_ekstrakurikuler ?? '-',
                            'predikat' => $nilai->predikat,
                            'deskripsi' => $nilai->deskripsi ?? '',
                        ];
                    })
                    ->values()
                    ->all(),
            ];
        })->all();
    }

    private function validateDeletedEkstrakurikulerIds(Collection $deletedIds, array $allowedStudentIds, int $tahunAjaranId, int $semester): void
    {
        if ($deletedIds->isEmpty()) {
            return;
        }

        $authorizedCount = NilaiEkstrakurikuler::whereIn('id', $deletedIds->all())
            ->whereIn('siswa_id', $allowedStudentIds)
            ->context($tahunAjaranId, $semester)
            ->count();

        abort_unless($authorizedCount === $deletedIds->count(), 403);
    }

    private function findAuthorizedNilaiEkstrakurikuler(
        int $id,
        int $kelasWaliId,
        int $tahunAjaranId,
        int $semester
    ): NilaiEkstrakurikuler {
        $allowedStudentIds = $this->getWaliKelasSiswas($kelasWaliId, $tahunAjaranId, $semester)
            ->pluck('id')
            ->map(fn ($studentId) => (int) $studentId)
            ->all();

        $nilaiEkstrakurikuler = NilaiEkstrakurikuler::with(['siswa', 'ekstrakurikuler'])
            ->whereKey($id)
            ->whereIn('siswa_id', $allowedStudentIds)
            ->context($tahunAjaranId, $semester)
            ->first();

        abort_unless($nilaiEkstrakurikuler, 403);

        return $nilaiEkstrakurikuler;
    }

    private function validateBulkEkstrakurikulerRows(Collection $rows, array $allowedStudentIds, array $allowedEkskulIds): void
    {
        $seenPairs = [];

        foreach ($rows as $index => $row) {
            $siswaId = (int) $row['siswa_id'];
            $ekstrakurikulerId = (int) $row['ekstrakurikuler_id'];

            if (!in_array($siswaId, $allowedStudentIds, true)) {
                abort(403);
            }

            if (!in_array($ekstrakurikulerId, $allowedEkskulIds, true)) {
                abort(403);
            }

            $pairKey = $siswaId . ':' . $ekstrakurikulerId;
            if (isset($seenPairs[$pairKey])) {
                throw ValidationException::withMessages([
                    "rows.{$index}.ekstrakurikuler_id" => ['Siswa tidak boleh memiliki ekstrakurikuler yang sama lebih dari satu kali.'],
                ]);
            }

            $seenPairs[$pairKey] = true;
        }
    }
}

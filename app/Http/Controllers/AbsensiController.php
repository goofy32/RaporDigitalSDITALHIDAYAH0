<?php

namespace App\Http\Controllers;

use App\Models\Absensi;
use App\Models\Siswa;
use App\Models\TahunAjaran;
use App\Traits\RequiresTahunAjaran;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AbsensiController extends Controller
{
    use RequiresTahunAjaran;

    public function index(Request $request)
    {
        $tahunAjaranId = $this->getValidTahunAjaranId();

        if (!$tahunAjaranId) {
            return $this->failTahunAjaranNotSet($request);
        }

        $waliKelas = auth()->guard('guru')->user();
        $kelasWaliId = $this->getKelasWaliId($waliKelas->id, $tahunAjaranId) ?? $waliKelas->getWaliKelasId();

        if (!$kelasWaliId) {
            return redirect()->back()->with('error', 'Anda belum ditugaskan sebagai wali kelas untuk kelas manapun.');
        }

        $currentSemester = $this->getCurrentSemester($tahunAjaranId);
        $siswas = $this->getWaliKelasSiswas($kelasWaliId);
        $absensiData = $this->buildAbsensiPayload($siswas, $currentSemester, $tahunAjaranId);

        return view('wali_kelas.absence', compact('siswas', 'absensiData', 'currentSemester'));
    }

    public function bulkSave(Request $request): JsonResponse
    {
        $tahunAjaranId = $this->getValidTahunAjaranId();

        if (!$tahunAjaranId) {
            return $this->failTahunAjaranNotSet($request, true);
        }

        $waliKelas = auth()->guard('guru')->user();
        $kelasWaliId = $this->getKelasWaliId($waliKelas->id, $tahunAjaranId) ?? $waliKelas->getWaliKelasId();

        if (!$kelasWaliId) {
            return response()->json([
                'success' => false,
                'message' => 'Anda belum ditugaskan sebagai wali kelas untuk kelas manapun.',
            ], 422);
        }

        $validated = $request->validate([
            'rows' => 'required|array',
            'rows.*.siswa_id' => 'required|exists:siswas,id',
            'rows.*.sakit' => 'required|integer|min:0',
            'rows.*.izin' => 'required|integer|min:0',
            'rows.*.tanpa_keterangan' => 'required|integer|min:0',
        ]);

        $currentSemester = $this->getCurrentSemester($tahunAjaranId);
        $siswas = $this->getWaliKelasSiswas($kelasWaliId);
        $allowedStudentIds = $siswas->pluck('id')->map(fn ($id) => (int) $id)->all();
        $rows = collect($validated['rows'])->values();

        foreach ($rows as $index => $row) {
            if (!in_array((int) $row['siswa_id'], $allowedStudentIds, true)) {
                throw ValidationException::withMessages([
                    "rows.{$index}.siswa_id" => ['Siswa tidak terdaftar di kelas Anda.'],
                ]);
            }
        }

        DB::transaction(function () use ($rows, $currentSemester, $tahunAjaranId) {
            foreach ($rows as $row) {
                Absensi::updateOrCreate(
                    [
                        'siswa_id' => $row['siswa_id'],
                        'semester' => $currentSemester,
                        'tahun_ajaran_id' => $tahunAjaranId,
                    ],
                    [
                        'sakit' => $row['sakit'],
                        'izin' => $row['izin'],
                        'tanpa_keterangan' => $row['tanpa_keterangan'],
                    ]
                );
            }
        });

        return response()->json([
            'success' => true,
            'message' => 'Data absensi berhasil disimpan.',
            'rows' => $this->buildAbsensiPayload($siswas, $currentSemester, $tahunAjaranId),
        ]);
    }

    public function create()
    {
        $guru = auth()->guard('guru')->user();
        $tahunAjaranId = session('tahun_ajaran_id');
        $kelasWaliId = $this->getKelasWaliId($guru->id, $tahunAjaranId);

        $tahunAjaran = TahunAjaran::find($tahunAjaranId);
        $currentSemester = $tahunAjaran ? $tahunAjaran->semester : 1;

        \Log::info('AbsensiController::create', [
            'guru_id' => $guru->id,
            'tahun_ajaran_id' => $tahunAjaranId,
            'kelas_wali_id' => $kelasWaliId,
            'current_semester' => $currentSemester,
        ]);

        if (!$kelasWaliId) {
            return redirect()->back()->with('error', 'Anda belum ditugaskan sebagai wali kelas untuk kelas manapun pada tahun ajaran ini.');
        }

        $siswa = Siswa::where('kelas_id', $kelasWaliId)
            ->orderBy('nama')
            ->get();

        \Log::info('AbsensiController found:', [
            'siswa_count' => $siswa->count(),
            'siswa_ids' => $siswa->pluck('id')->toArray(),
        ]);

        return view('wali_kelas.add_absence', compact('siswa', 'kelasWaliId', 'currentSemester', 'tahunAjaran'));
    }

    public function store(Request $request)
    {
        $tahunAjaranId = $this->getValidTahunAjaranId();

        if (!$tahunAjaranId) {
            return $this->failTahunAjaranNotSet($request);
        }

        $tahunAjaran = TahunAjaran::find($tahunAjaranId);
        $currentSemester = $tahunAjaran ? $tahunAjaran->semester : $request->semester;

        $request->validate([
            'siswa_id' => 'required|exists:siswas,id',
            'sakit' => 'required|integer|min:0',
            'izin' => 'required|integer|min:0',
            'tanpa_keterangan' => 'required|integer|min:0',
        ]);

        $existingAbsensi = Absensi::where('siswa_id', $request->siswa_id)
            ->where('semester', $currentSemester)
            ->where('tahun_ajaran_id', $tahunAjaranId)
            ->first();

        if ($existingAbsensi) {
            return redirect()->back()
                ->withInput()
                ->with('error', 'Data absensi untuk siswa ini di semester yang sama sudah ada');
        }

        $data = $request->all();
        $data['semester'] = $currentSemester;
        $data['tahun_ajaran_id'] = $tahunAjaranId;

        Absensi::create($data);

        return redirect()->route('wali_kelas.absence.index')
            ->with('success', 'Data absensi berhasil ditambahkan');
    }

    public function edit($id)
    {
        try {
            $waliKelas = auth()->guard('guru')->user();
            $tahunAjaranId = session('tahun_ajaran_id');
            $kelasWaliId = $this->getKelasWaliId($waliKelas->id, $tahunAjaranId) ?? $waliKelas->getWaliKelasId();

            \Log::info('Editing absensi', [
                'id' => $id,
                'kelasWaliId' => $kelasWaliId,
            ]);

            $absensi = Absensi::with('siswa')
                ->where('tahun_ajaran_id', $tahunAjaranId)
                ->whereHas('siswa', function ($query) use ($kelasWaliId) {
                    $query->where('kelas_id', $kelasWaliId);
                })
                ->findOrFail($id);

            $tahunAjaran = TahunAjaran::find($tahunAjaranId);

            return view('wali_kelas.edit_absence', compact('absensi', 'tahunAjaran'));
        } catch (\Exception $e) {
            \Log::error('Error editing absensi: ' . $e->getMessage());

            return redirect()->route('wali_kelas.absence.index')
                ->with('error', 'Data absensi tidak ditemukan atau Anda tidak memiliki akses.');
        }
    }

    public function update(Request $request, $id)
    {
        $tahunAjaranId = $this->getValidTahunAjaranId();

        if (!$tahunAjaranId) {
            return $this->failTahunAjaranNotSet($request);
        }

        $request->validate([
            'sakit' => 'required|integer|min:0',
            'izin' => 'required|integer|min:0',
            'tanpa_keterangan' => 'required|integer|min:0',
        ]);

        $absensi = Absensi::findOrFail($id);

        $data = $request->all();
        $data['semester'] = $absensi->semester;
        $data['tahun_ajaran_id'] = $tahunAjaranId;

        $absensi->update($data);

        return redirect()->route('wali_kelas.absence.index')
            ->with('success', 'Data absensi berhasil diperbarui');
    }

    public function destroy($id)
    {
        $absensi = Absensi::findOrFail($id);
        $absensi->delete();

        return redirect()->route('wali_kelas.absence.index')
            ->with('success', 'Data absensi berhasil dihapus');
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

    private function getCurrentSemester(int $tahunAjaranId): int
    {
        return (int) (TahunAjaran::find($tahunAjaranId)?->semester ?? 1);
    }

    private function getWaliKelasSiswas(int $kelasWaliId): Collection
    {
        return Siswa::where('kelas_id', $kelasWaliId)
            ->orderBy('nama')
            ->get(['id', 'nis', 'nama']);
    }

    private function buildAbsensiPayload(Collection $siswas, int $currentSemester, int $tahunAjaranId): array
    {
        $absensiMap = Absensi::whereIn('siswa_id', $siswas->pluck('id'))
            ->where('semester', $currentSemester)
            ->where('tahun_ajaran_id', $tahunAjaranId)
            ->get()
            ->keyBy('siswa_id');

        return $siswas->map(function ($siswa) use ($absensiMap) {
            $absensi = $absensiMap->get($siswa->id);

            return [
                'siswa_id' => (int) $siswa->id,
                'nis' => $siswa->nis,
                'nama' => $siswa->nama,
                'sakit' => (int) ($absensi->sakit ?? 0),
                'izin' => (int) ($absensi->izin ?? 0),
                'tanpa_keterangan' => (int) ($absensi->tanpa_keterangan ?? 0),
            ];
        })->values()->all();
    }
}

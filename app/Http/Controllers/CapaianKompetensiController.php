<?php
// app/Http/Controllers/CapaianKompetensiController.php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\CapaianKompetensiTemplate;
use App\Models\CapaianKompetensiCustom;
use App\Models\Kelas;
use App\Models\MataPelajaran;
use App\Models\Nilai;
use App\Models\Siswa;
use App\Models\TahunAjaran;
use App\Services\SiswaKelasSemesterResolver;
use App\Traits\RequiresTahunAjaran;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CapaianKompetensiController extends Controller
{
    use RequiresTahunAjaran;

    // =============== ADMIN METHODS ===============
    
    /**
     * Tampilkan halaman pengaturan template capaian kompetensi (Admin)
     */
    public function adminIndex()
    {
        $tahunAjaranId = session('tahun_ajaran_id');
        
        // Ambil semua mata pelajaran unik di tahun ajaran ini
        $mataPelajarans = MataPelajaran::where('tahun_ajaran_id', $tahunAjaranId)
            ->select('nama_pelajaran')
            ->distinct()
            ->orderBy('nama_pelajaran')
            ->pluck('nama_pelajaran');

        // Ambil templates yang sudah ada
        $templates = CapaianKompetensiTemplate::where('tahun_ajaran_id', $tahunAjaranId)
            ->orderBy('mata_pelajaran')
            ->orderBy('nilai_min', 'desc')
            ->get()
            ->groupBy('mata_pelajaran');

        return view('admin.capaian_kompetensi.index', compact('mataPelajarans', 'templates'));
    }

    /**
     * Simpan template capaian kompetensi (Admin)
     */
    public function adminStore(Request $request)
    {
        $tahunAjaranId = $this->getValidTahunAjaranId();

        if (!$tahunAjaranId) {
            return $this->failTahunAjaranNotSet($request);
        }

        $request->validate([
            'mata_pelajaran' => 'required|string|max:255',
            'nilai_min' => 'required|numeric|min:0|max:100',
            'nilai_max' => 'required|numeric|min:0|max:100|gte:nilai_min',
            'template_text' => 'required|string|max:1000',
        ]);
        // Cek overlap range nilai untuk mata pelajaran yang sama
        $overlap = CapaianKompetensiTemplate::where('mata_pelajaran', $request->mata_pelajaran)
            ->where('tahun_ajaran_id', $tahunAjaranId)
            ->where(function($query) use ($request) {
                $query->whereBetween('nilai_min', [$request->nilai_min, $request->nilai_max])
                      ->orWhereBetween('nilai_max', [$request->nilai_min, $request->nilai_max])
                      ->orWhere(function($q) use ($request) {
                          $q->where('nilai_min', '<=', $request->nilai_min)
                            ->where('nilai_max', '>=', $request->nilai_max);
                      });
            })
            ->exists();

        if ($overlap) {
            return redirect()->back()
                ->withInput()
                ->withErrors(['nilai_min' => 'Range nilai bertabrakan dengan template yang sudah ada.']);
        }

        CapaianKompetensiTemplate::create([
            'mata_pelajaran' => $request->mata_pelajaran,
            'nilai_min' => $request->nilai_min,
            'nilai_max' => $request->nilai_max,
            'template_text' => $request->template_text,
            'tahun_ajaran_id' => $tahunAjaranId,
        ]);

        return redirect()->back()->with('success', 'Template capaian kompetensi berhasil ditambahkan.');
    }

    /**
     * Hapus template capaian kompetensi (Admin)
     */
    public function adminDestroy($id)
    {
        try {
            $template = CapaianKompetensiTemplate::findOrFail($id);
            $template->delete();

            return response()->json([
                'success' => true,
                'message' => 'Template berhasil dihapus.'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Gagal menghapus template: ' . $e->getMessage()
            ], 500);
        }
    }

    // =============== WALI KELAS METHODS ===============
    
    /**
     * Tampilkan daftar mata pelajaran untuk capaian kompetensi (Wali Kelas)
     */
    public function waliKelasIndex()
    {
        $guru = auth()->guard('guru')->user();
        abort_unless($guru && session('selected_role') === 'wali_kelas', 403);

        $tahunAjaranId = $this->getValidTahunAjaranId();

        if (!$tahunAjaranId) {
            return $this->failTahunAjaranNotSet(request());
        }

        $semester = $this->getCurrentSemester($tahunAjaranId);

        // Ambil kelas yang diwalikan
        $kelas = $this->getWaliKelasKelas($guru, $tahunAjaranId);
        abort_unless($kelas, 403);

        // Ambil mata pelajaran di kelas ini
        $mataPelajarans = MataPelajaran::where('kelas_id', $kelas->id)
            ->where('tahun_ajaran_id', $tahunAjaranId)
            ->where('semester', $semester)
            ->with(['guru'])
            ->orderBy('nama_pelajaran')
            ->get();

        $siswaList = $this->studentsForWaliClass((int) $kelas->id, $tahunAjaranId, $semester);
        $totalSiswa = $siswaList->count();
        $studentIds = $siswaList->pluck('id');
        $customCounts = CapaianKompetensiCustom::whereIn('mata_pelajaran_id', $mataPelajarans->pluck('id'))
            ->whereIn('siswa_id', $studentIds)
            ->where('tahun_ajaran_id', $tahunAjaranId)
            ->where('semester', $semester)
            ->select('mata_pelajaran_id', DB::raw('count(*) as aggregate'))
            ->groupBy('mata_pelajaran_id')
            ->pluck('aggregate', 'mata_pelajaran_id');

        return view('wali_kelas.capaian_kompetensi.index', compact(
            'mataPelajarans',
            'kelas',
            'totalSiswa',
            'customCounts',
            'tahunAjaranId',
            'semester'
        ));
    }

    private function getWaliKelasKelas($guru, $tahunAjaranId)
    {
        $kelasId = DB::table('guru_kelas')
            ->join('kelas', function ($join) {
                $join->on('guru_kelas.kelas_id', '=', 'kelas.id')
                    ->whereNull('kelas.deleted_at');
            })
            ->where('guru_kelas.guru_id', $guru->id)
            ->where('guru_kelas.is_wali_kelas', true)
            ->where('guru_kelas.role', 'wali_kelas')
            ->where('kelas.tahun_ajaran_id', $tahunAjaranId)
            ->value('kelas.id');

        return $kelasId ? Kelas::find($kelasId) : null;
    }

    /**
     * Tampilkan form edit capaian kompetensi untuk mata pelajaran tertentu (Wali Kelas)
     */
    public function waliKelasEdit($mataPelajaranId)
    {
        $tahunAjaranId = $this->getValidTahunAjaranId();

        if (!$tahunAjaranId) {
            return $this->failTahunAjaranNotSet(request());
        }

        $semester = $this->getCurrentSemester($tahunAjaranId);

        $mataPelajaran = MataPelajaran::findOrFail($mataPelajaranId);

        // Cek akses wali kelas
        $kelas = $this->authorizeWaliSubject($mataPelajaran, $tahunAjaranId, $semester);

        // Ambil semua siswa di kelas
        $siswaList = $this->studentsForWaliClass((int) $kelas->id, $tahunAjaranId, $semester);
        $studentIds = $siswaList->pluck('id');

        // Ambil capaian kompetensi custom yang sudah ada
        $existingCapaian = CapaianKompetensiCustom::where('mata_pelajaran_id', $mataPelajaranId)
            ->whereIn('siswa_id', $studentIds)
            ->where('tahun_ajaran_id', $tahunAjaranId)
            ->where('semester', $semester)
            ->get()
            ->keyBy('siswa_id');

        return view('wali_kelas.capaian_kompetensi.edit', compact(
            'mataPelajaran',
            'siswaList', 
            'existingCapaian',
            'tahunAjaranId',
            'semester'
        ));
    }

    /**
     * Update capaian kompetensi custom (Wali Kelas)
     */
    public function waliKelasUpdate(Request $request, $mataPelajaranId)
    {
        $tahunAjaranId = $this->getValidTahunAjaranId();

        if (!$tahunAjaranId) {
            return $this->failTahunAjaranNotSet($request);
        }

        $semester = $this->getCurrentSemester($tahunAjaranId);

        $mataPelajaran = MataPelajaran::findOrFail($mataPelajaranId);

        // Cek akses wali kelas
        $kelas = $this->authorizeWaliSubject($mataPelajaran, $tahunAjaranId, $semester);

        $request->validate([
            'capaian_tertinggi' => 'array',
            'capaian_tertinggi.*' => 'nullable|string|max:1000',
            'capaian_terendah' => 'array',
            'capaian_terendah.*' => 'nullable|string|max:1000',
        ]);

        $capaianTertinggi = $request->input('capaian_tertinggi', []);
        $capaianTerendah = $request->input('capaian_terendah', []);
        $siswaIds = collect(array_keys($capaianTertinggi))
            ->merge(array_keys($capaianTerendah))
            ->unique()
            ->values();

        $this->assertAllStudentsBelongToWaliClass(
            $siswaIds->all(),
            (int) $kelas->id,
            $tahunAjaranId,
            $semester
        );

        try {
            DB::transaction(function () use ($siswaIds, $capaianTertinggi, $capaianTerendah, $mataPelajaranId, $tahunAjaranId, $semester) {
                foreach ($siswaIds as $siswaId) {
                    $siswaId = (int) $siswaId;
                    $customTertinggi = trim((string) ($capaianTertinggi[$siswaId] ?? ''));
                    $customTerendah = trim((string) ($capaianTerendah[$siswaId] ?? ''));

                    if ($customTertinggi !== '' || $customTerendah !== '') {
                        CapaianKompetensiCustom::updateOrCreate(
                            [
                                'siswa_id' => $siswaId,
                                'mata_pelajaran_id' => $mataPelajaranId,
                                'tahun_ajaran_id' => $tahunAjaranId,
                                'semester' => $semester,
                            ],
                            [
                                'custom_capaian_tertinggi' => $customTertinggi !== '' ? $customTertinggi : null,
                                'custom_capaian_terendah' => $customTerendah !== '' ? $customTerendah : null,
                            ]
                        );
                    } else {
                        // Hapus jika kosong
                        CapaianKompetensiCustom::where([
                            'siswa_id' => $siswaId,
                            'mata_pelajaran_id' => $mataPelajaranId,
                            'tahun_ajaran_id' => $tahunAjaranId,
                            'semester' => $semester,
                        ])->delete();
                    }
                }
            });

            return redirect()->route('wali_kelas.capaian_kompetensi.index')
                ->with('success', 'Capaian kompetensi berhasil disimpan.');

        } catch (\Exception $e) {
            Log::error('Error updating capaian kompetensi: ' . $e->getMessage());
            return redirect()->back()->with('error', 'Gagal menyimpan capaian kompetensi: ' . $e->getMessage());
        }
    }

    private function getCurrentSemester(int $tahunAjaranId): int
    {
        return (int) (TahunAjaran::find($tahunAjaranId)?->semester ?? 1);
    }

    private function authorizeWaliSubject(MataPelajaran $mataPelajaran, int $tahunAjaranId, int $semester): Kelas
    {
        $guru = auth()->guard('guru')->user();
        abort_unless($guru && session('selected_role') === 'wali_kelas', 403);

        $kelas = $this->getWaliKelasKelas($guru, $tahunAjaranId);

        abort_unless(
            $kelas
            && (int) $mataPelajaran->kelas_id === (int) $kelas->id
            && (int) $mataPelajaran->tahun_ajaran_id === $tahunAjaranId
            && (int) $mataPelajaran->semester === $semester,
            403
        );

        return $kelas;
    }

    private function studentsForWaliClass(int $kelasId, int $tahunAjaranId, int $semester)
    {
        return app(SiswaKelasSemesterResolver::class)
            ->studentsForClass($kelasId, $tahunAjaranId, $semester, true);
    }

    private function assertAllStudentsBelongToWaliClass(array $studentIds, int $kelasId, int $tahunAjaranId, int $semester): void
    {
        $submittedCount = count($studentIds);
        $studentIds = collect($studentIds)
            ->map(fn ($id) => (int) $id)
            ->filter()
            ->unique()
            ->values();

        abort_unless($studentIds->count() === $submittedCount, 403);

        $authorizedCount = app(SiswaKelasSemesterResolver::class)
            ->studentQueryForClass($kelasId, $tahunAjaranId, $semester, true)
            ->whereIn('siswas.id', $studentIds)
            ->count();

        abort_unless($authorizedCount === $studentIds->count(), 403);
    }

    public static function generateCapaianTertinggiTerendah(
        $siswaId,
        $mataPelajaranId,
        $tahunAjaranId = null
    ): array {
        $tahunAjaranId = $tahunAjaranId ?: session('tahun_ajaran_id');
        $tahunAjaran = TahunAjaran::find($tahunAjaranId);
        $semester = $tahunAjaran ? $tahunAjaran->semester : 1;

        $custom = CapaianKompetensiCustom::where([
            'siswa_id' => $siswaId,
            'mata_pelajaran_id' => $mataPelajaranId,
            'tahun_ajaran_id' => $tahunAjaranId,
            'semester' => $semester,
        ])->first();

        $autoCapaian = self::generateAutoCapaianTertinggiTerendah($siswaId, $mataPelajaranId, $tahunAjaranId);

        return [
            'tertinggi' => $custom?->custom_capaian_tertinggi ?: $autoCapaian['tertinggi'],
            'terendah' => $custom?->custom_capaian_terendah ?: $autoCapaian['terendah'],
        ];
    }

    public static function preloadCapaianData(
        int $siswaId,
        array $mataPelajaranIds,
        int $tahunAjaranId
    ): array {
        $mataPelajaranIds = array_values(array_unique(array_filter($mataPelajaranIds)));

        if (empty($mataPelajaranIds)) {
            return [];
        }

        $tahunAjaran = TahunAjaran::find($tahunAjaranId);
        $semester = $tahunAjaran ? $tahunAjaran->semester : 1;
        $siswa = Siswa::find($siswaId);
        $namaSiswa = $siswa ? $siswa->nama : '';

        $customCapaians = CapaianKompetensiCustom::where('siswa_id', $siswaId)
            ->where('tahun_ajaran_id', $tahunAjaranId)
            ->where('semester', $semester)
            ->whereIn('mata_pelajaran_id', $mataPelajaranIds)
            ->get()
            ->keyBy('mata_pelajaran_id');

        $lmData = Nilai::query()
            ->join('lingkup_materis', 'nilais.lingkup_materi_id', '=', 'lingkup_materis.id')
            ->join('mata_pelajarans', 'nilais.mata_pelajaran_id', '=', 'mata_pelajarans.id')
            ->where('nilais.siswa_id', $siswaId)
            ->where('nilais.tahun_ajaran_id', $tahunAjaranId)
            ->where('mata_pelajarans.tahun_ajaran_id', $tahunAjaranId)
            ->where('mata_pelajarans.semester', $semester)
            ->whereIn('nilais.mata_pelajaran_id', $mataPelajaranIds)
            ->whereNull('nilais.deleted_at')
            ->whereNull('lingkup_materis.deleted_at')
            ->whereNull('mata_pelajarans.deleted_at')
            ->whereNotNull('nilais.nilai_lm')
            ->select([
                'nilais.mata_pelajaran_id',
                'lingkup_materis.judul_lingkup_materi',
                'nilais.nilai_lm',
            ])
            ->get()
            ->groupBy('mata_pelajaran_id');

        $result = [];

        foreach ($mataPelajaranIds as $mapelId) {
            $custom = $customCapaians->get($mapelId);
            $lms = $lmData->get($mapelId, collect())
                ->groupBy('judul_lingkup_materi')
                ->map(function ($rows, $judul) {
                    return (object) [
                        'judul_lingkup_materi' => $judul,
                        'nilai_lm' => $rows->max('nilai_lm'),
                    ];
                })
                ->values();

            $lmTertinggi = $lms->sortByDesc('nilai_lm')->first();
            $lmTerendah = $lms->sortBy('nilai_lm')->first();

            $autoTertinggi = $lmTertinggi
                ? "{$namaSiswa} menunjukkan pemahaman dalam {$lmTertinggi->judul_lingkup_materi}."
                : "{$namaSiswa} menunjukkan pemahaman yang baik.";

            $autoTerendah = $lmTerendah
                ? "{$namaSiswa} berkembang dalam {$lmTerendah->judul_lingkup_materi}."
                : "{$namaSiswa} terus berkembang dalam pembelajaran.";

            $result[$mapelId] = [
                'tertinggi' => $custom?->custom_capaian_tertinggi ?: $autoTertinggi,
                'terendah' => $custom?->custom_capaian_terendah ?: $autoTerendah,
            ];
        }

        return $result;
    }

    public static function generateAutoCapaianTertinggiTerendah(
        $siswaId,
        $mataPelajaranId,
        $tahunAjaranId = null
    ): array {
        $tahunAjaranId = $tahunAjaranId ?: session('tahun_ajaran_id');
        $tahunAjaran = TahunAjaran::find($tahunAjaranId);
        $semester = $tahunAjaran ? $tahunAjaran->semester : 1;
        $siswa = Siswa::find($siswaId);

        if (!$siswa) {
            return [
                'tertinggi' => 'Data siswa tidak tersedia.',
                'terendah' => 'Data siswa tidak tersedia.',
            ];
        }

        $lmData = DB::table('nilais')
            ->join('lingkup_materis', 'nilais.lingkup_materi_id', '=', 'lingkup_materis.id')
            ->join('mata_pelajarans', 'nilais.mata_pelajaran_id', '=', 'mata_pelajarans.id')
            ->where('nilais.siswa_id', $siswaId)
            ->where('nilais.mata_pelajaran_id', $mataPelajaranId)
            ->where('nilais.tahun_ajaran_id', $tahunAjaranId)
            ->where('mata_pelajarans.tahun_ajaran_id', $tahunAjaranId)
            ->where('mata_pelajarans.semester', $semester)
            ->whereNull('nilais.deleted_at')
            ->whereNull('lingkup_materis.deleted_at')
            ->whereNull('mata_pelajarans.deleted_at')
            ->whereNotNull('nilais.nilai_lm')
            ->groupBy('lingkup_materis.id', 'lingkup_materis.judul_lingkup_materi')
            ->select(
                'lingkup_materis.id',
                'lingkup_materis.judul_lingkup_materi',
                DB::raw('MAX(nilais.nilai_lm) as nilai_lm')
            )
            ->get();

        if ($lmData->isEmpty()) {
            return [
                'tertinggi' => "{$siswa->nama} menunjukkan pemahaman yang baik.",
                'terendah' => "{$siswa->nama} terus berkembang dalam pembelajaran.",
            ];
        }

        $lmTertinggi = $lmData->sortByDesc('nilai_lm')->first();
        $lmTerendah = $lmData->sortBy('nilai_lm')->first();

        return [
            'tertinggi' => "{$siswa->nama} menunjukkan pemahaman dalam {$lmTertinggi->judul_lingkup_materi}.",
            'terendah' => "{$siswa->nama} berkembang dalam {$lmTerendah->judul_lingkup_materi}.",
        ];
    }

    /**
     * Generate capaian kompetensi untuk rapor
     */
    public static function generateCapaianForRapor($siswaId, $mataPelajaranId, $tahunAjaranId = null)
    {
        $tahunAjaranId = $tahunAjaranId ?: session('tahun_ajaran_id');
        $tahunAjaran = TahunAjaran::find($tahunAjaranId);
        $semester = $tahunAjaran ? $tahunAjaran->semester : 1;

        // Cek apakah ada custom capaian
        $customCapaian = CapaianKompetensiCustom::where([
            'siswa_id' => $siswaId,
            'mata_pelajaran_id' => $mataPelajaranId,
            'tahun_ajaran_id' => $tahunAjaranId,
            'semester' => $semester,
        ])->first();

        if ($customCapaian) {
            return $customCapaian->generateFinalCapaian();
        }

        // Jika tidak ada custom, generate otomatis
        $siswa = Siswa::find($siswaId);
        $mataPelajaran = MataPelajaran::find($mataPelajaranId);

        if (
            !$siswa
            || !$mataPelajaran
            || (int) $mataPelajaran->tahun_ajaran_id !== (int) $tahunAjaranId
            || (int) $mataPelajaran->semester !== (int) $semester
        ) {
            return 'Data tidak lengkap.';
        }

        // Ambil nilai siswa
        $nilai = $siswa->nilais()
            ->where('mata_pelajaran_id', $mataPelajaranId)
            ->where('tahun_ajaran_id', $tahunAjaranId)
            ->whereNotNull('nilai_akhir_rapor')
            ->first();

        if (!$nilai || is_null($nilai->nilai_akhir_rapor)) {
            return 'Nilai belum tersedia.';
        }

        // Cari template
        $template = CapaianKompetensiTemplate::getTemplateByNilai(
            $mataPelajaran->nama_pelajaran,
            $nilai->nilai_akhir_rapor,
            $tahunAjaranId
        );

        if ($template) {
            return $template->generateCapaianText($siswa->nama);
        }

        // Fallback ke template default
        return self::generateDefaultCapaian($siswa->nama, $mataPelajaran->nama_pelajaran, $nilai->nilai_akhir_rapor);
    }

    /**
     * Generate default capaian
     */
    private static function generateDefaultCapaian($namaSiswa, $namaMapel, $nilai)
    {
        if ($nilai >= 90) {
            return "{$namaSiswa} menunjukkan penguasaan yang sangat baik dalam mata pelajaran {$namaMapel}.";
        } elseif ($nilai >= 80) {
            return "{$namaSiswa} menunjukkan penguasaan yang baik dalam mata pelajaran {$namaMapel}.";
        } elseif ($nilai >= 70) {
            return "{$namaSiswa} menunjukkan penguasaan yang cukup dalam mata pelajaran {$namaMapel}.";
        } else {
            return "{$namaSiswa} perlu meningkatkan penguasaan dalam mata pelajaran {$namaMapel}.";
        }
    }
}

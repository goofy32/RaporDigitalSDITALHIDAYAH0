<?php
// app/Http/Controllers/CapaianKompetensiController.php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\CapaianKompetensiTemplate;
use App\Models\CapaianKompetensiCustom;
use App\Models\MataPelajaran;
use App\Models\Siswa;
use App\Models\TahunAjaran;
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
        $guru = auth()->user();
        $tahunAjaranId = session('tahun_ajaran_id');
        $tahunAjaran = TahunAjaran::find($tahunAjaranId);
        $semester = $tahunAjaran ? $tahunAjaran->semester : 1;

        // Ambil kelas yang diwalikan
        $kelas = DB::table('guru_kelas')
            ->join('kelas', 'guru_kelas.kelas_id', '=', 'kelas.id')
            ->where('guru_kelas.guru_id', $guru->id)
            ->where('guru_kelas.is_wali_kelas', true)
            ->where('guru_kelas.role', 'wali_kelas')
            ->where('kelas.tahun_ajaran_id', $tahunAjaranId)
            ->select('kelas.*')
            ->first();

        if (!$kelas) {
            return redirect()->back()->with('error', 'Anda tidak menjadi wali kelas untuk tahun ajaran yang dipilih.');
        }

        // Ambil mata pelajaran di kelas ini
        $mataPelajarans = MataPelajaran::where('kelas_id', $kelas->id)
            ->where('tahun_ajaran_id', $tahunAjaranId)
            ->where('semester', $semester)
            ->with(['guru'])
            ->orderBy('nama_pelajaran')
            ->get();

        return view('wali_kelas.capaian_kompetensi.index', compact('mataPelajarans', 'kelas'));
    }

    /**
     * Tampilkan form edit capaian kompetensi untuk mata pelajaran tertentu (Wali Kelas)
     */
    public function waliKelasEdit($mataPelajaranId)
    {
        $guru = auth()->user();
        $tahunAjaranId = session('tahun_ajaran_id');
        $tahunAjaran = TahunAjaran::find($tahunAjaranId);
        $semester = $tahunAjaran ? $tahunAjaran->semester : 1;

        $mataPelajaran = MataPelajaran::findOrFail($mataPelajaranId);

        // Cek akses wali kelas
        $kelas = $guru->kelasWali()->first();
        if (!$kelas || $mataPelajaran->kelas_id !== $kelas->id) {
            return redirect()->back()->with('error', 'Anda tidak memiliki akses untuk mengedit capaian kompetensi mata pelajaran ini.');
        }

        // Ambil semua siswa di kelas
        $siswaList = Siswa::where('kelas_id', $kelas->id)->orderBy('nama')->get();

        // Ambil capaian kompetensi custom yang sudah ada
        $existingCapaian = CapaianKompetensiCustom::where('mata_pelajaran_id', $mataPelajaranId)
            ->where('tahun_ajaran_id', $tahunAjaranId)
            ->where('semester', $semester)
            ->get()
            ->keyBy('siswa_id');

        return view('wali_kelas.capaian_kompetensi.edit', compact(
            'mataPelajaran',
            'siswaList', 
            'existingCapaian'
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

        $guru = auth()->user();
        $tahunAjaran = TahunAjaran::find($tahunAjaranId);
        $semester = $tahunAjaran ? $tahunAjaran->semester : 1;

        $mataPelajaran = MataPelajaran::findOrFail($mataPelajaranId);

        // Cek akses wali kelas
        $kelas = $guru->kelasWali()->first();
        if (!$kelas || $mataPelajaran->kelas_id !== $kelas->id) {
            return redirect()->back()->with('error', 'Anda tidak memiliki akses untuk mengedit capaian kompetensi mata pelajaran ini.');
        }

        $request->validate([
            'capaian' => 'array',
            'capaian.*' => 'nullable|string|max:1000',
        ]);

        DB::beginTransaction();

        try {
            $capaianData = $request->input('capaian', []);

            foreach ($capaianData as $siswaId => $customCapaian) {
                if (!empty($customCapaian)) {
                    CapaianKompetensiCustom::updateOrCreate(
                        [
                            'siswa_id' => $siswaId,
                            'mata_pelajaran_id' => $mataPelajaranId,
                            'tahun_ajaran_id' => $tahunAjaranId,
                            'semester' => $semester,
                        ],
                        [
                            'custom_capaian' => $customCapaian,
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

            DB::commit();

            return redirect()->route('wali_kelas.capaian_kompetensi.index')
                ->with('success', 'Capaian kompetensi berhasil disimpan.');

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error updating capaian kompetensi: ' . $e->getMessage());
            return redirect()->back()->with('error', 'Gagal menyimpan capaian kompetensi: ' . $e->getMessage());
        }
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

        if (!$siswa || !$mataPelajaran) {
            return 'Data tidak lengkap.';
        }

        // Ambil nilai siswa
        $nilai = $siswa->nilais()
            ->where('mata_pelajaran_id', $mataPelajaranId)
            ->where('tahun_ajaran_id', $tahunAjaranId)
            ->first();

        if (!$nilai || !$nilai->nilai_akhir_rapor) {
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

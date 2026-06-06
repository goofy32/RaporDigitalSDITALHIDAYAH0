<?php

namespace App\Http\Controllers;

use App\Models\Kelas;
use App\Models\Siswa;
use App\Models\TahunAjaran;
use App\Services\SiswaKelasSemesterResolver;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class KenaikanKelasController extends Controller
{
    private const PROMOTION_WRITES_DISABLED_MESSAGE = 'Kenaikan kelas berbasis enrollment belum diaktifkan. Gunakan setelah Phase 2E-B3.';

    /**
     * Menampilkan halaman untuk proses kenaikan kelas
     */
    public function index(Request $request, SiswaKelasSemesterResolver $enrollmentResolver)
    {
        // Ambil tahun ajaran aktif
        $tahunAjaranAktif = $this->resolveSourceTahunAjaran($request);
        
        if (!$tahunAjaranAktif) {
            return view('admin.kenaikan_kelas.index', [
                'error' => 'Tidak ada tahun ajaran yang aktif. Anda perlu mengaktifkan tahun ajaran terlebih dahulu.'
            ]);
        }
        
        // Cari tahun ajaran baru (tahun ajaran selanjutnya)
        $tahunAjaranBaru = $this->findNextSemesterOneYear($tahunAjaranAktif);
        
        // Ambil kelas dari tahun ajaran aktif untuk ditampilkan
        $kelasAktif = Kelas::where('tahun_ajaran_id', $tahunAjaranAktif->id)
                    ->orderBy('nomor_kelas')
                    ->orderBy('nama_kelas')
                    ->get();

        $this->attachPromotionRosterCounts($kelasAktif, $tahunAjaranAktif, $enrollmentResolver);
        $promotionWritesEnabled = false;
        $promotionWritesDisabledMessage = self::PROMOTION_WRITES_DISABLED_MESSAGE;
        
        // Jika tidak ada tahun ajaran berikutnya, berikan peringatan
        if (!$tahunAjaranBaru) {
            return view('admin.kenaikan_kelas.index', compact('kelasAktif', 'tahunAjaranAktif', 'promotionWritesEnabled', 'promotionWritesDisabledMessage'))
                ->with('warning', 'Belum ada tahun ajaran selanjutnya. Silakan buat tahun ajaran baru dengan tahun yang lebih tinggi dari ' . $tahunAjaranAktif->tahun_ajaran . '. Anda bisa membuat tahun ajaran baru dengan menyalin data dari tahun ajaran yang sudah ada.');
        }
        
        // Cek apakah ada kelas di tahun ajaran baru
        $kelasBaru = Kelas::where('tahun_ajaran_id', $tahunAjaranBaru->id)
                    ->orderBy('nomor_kelas')
                    ->orderBy('nama_kelas')
                    ->get();
        
        // Jika tidak ada kelas di tahun ajaran baru, berikan peringatan
        if ($kelasBaru->isEmpty()) {
            return view('admin.kenaikan_kelas.index', compact('kelasAktif', 'tahunAjaranAktif', 'tahunAjaranBaru', 'promotionWritesEnabled', 'promotionWritesDisabledMessage'))
                ->with('warning', 'Tahun ajaran ' . $tahunAjaranBaru->tahun_ajaran . ' belum memiliki kelas. Untuk melakukan kenaikan kelas, Anda perlu membuat kelas-kelas di tahun ajaran tersebut terlebih dahulu.');
        }
        
        return view('admin.kenaikan_kelas.index', compact('kelasAktif', 'kelasBaru', 'tahunAjaranAktif', 'tahunAjaranBaru', 'promotionWritesEnabled', 'promotionWritesDisabledMessage'));
    }
    
    public function showKelasSiswa(Request $request, SiswaKelasSemesterResolver $enrollmentResolver, $kelasId)
    {
        $kelas = Kelas::findOrFail($kelasId);

        $tahunAjaranAktif = $this->resolveSourceTahunAjaran($request);

        if (!$tahunAjaranAktif || (int) $tahunAjaranAktif->semester !== 2) {
            return redirect()->route('admin.kenaikan-kelas.index')
                ->with('error', 'Perencanaan kenaikan kelas hanya dapat dilakukan dari tahun ajaran aktif semester genap.');
        }

        if ((int) $kelas->tahun_ajaran_id !== (int) $tahunAjaranAktif->id) {
            abort(404);
        }

        $siswaList = $this->studentsForPromotionClass($kelas, $tahunAjaranAktif, $enrollmentResolver);
        
        // Check if this is the final grade (for graduation)
        $isKelasAkhir = $kelas->nomor_kelas == 6; // For SD, grade 6 is the final grade
        
        $tahunAjaranBaru = $this->findNextSemesterOneYear($tahunAjaranAktif);
        
        // Get classes that can be promotion targets
        $kelasTujuan = collect();
        $kelasTinggal = collect();
        if ($tahunAjaranBaru) {
            // If not the final grade, only show classes with nomor +1
            if (!$isKelasAkhir) {
                $kelasTujuan = Kelas::where('tahun_ajaran_id', $tahunAjaranBaru->id)
                             ->where('nomor_kelas', $kelas->nomor_kelas + 1)
                             ->orderBy('nama_kelas')
                             ->get();
            }

            $kelasTinggal = Kelas::where('tahun_ajaran_id', $tahunAjaranBaru->id)
                         ->where('nomor_kelas', $kelas->nomor_kelas)
                         ->orderBy('nama_kelas')
                         ->get();
        }

        $targetClassCapability = [
            'can_promote_to_next_grade' => !$isKelasAkhir && $kelasTujuan->isNotEmpty(),
            'can_repeat_same_grade' => $kelasTinggal->isNotEmpty(),
            'is_final_grade' => $isKelasAkhir,
        ];
        
        // Check report status for each student
        $raporStatus = [];
        foreach ($siswaList as $siswa) {
            // Check if reports have been generated for this student
            $hasReport = \App\Models\ReportGeneration::where('siswa_id', $siswa->id)
                ->where('tahun_ajaran_id', $tahunAjaranAktif->id)
                ->where('semester', 2)
                ->exists();
            
            $raporStatus[$siswa->id] = $hasReport;
        }

        $promotionWritesEnabled = false;
        $promotionWritesDisabledMessage = self::PROMOTION_WRITES_DISABLED_MESSAGE;
        
        return view('admin.kenaikan_kelas.show_siswa', compact(
            'kelas',
            'siswaList',
            'isKelasAkhir',
            'kelasTujuan',
            'kelasTinggal',
            'tahunAjaranAktif',
            'tahunAjaranBaru',
            'raporStatus',
            'targetClassCapability',
            'promotionWritesEnabled',
            'promotionWritesDisabledMessage'
        ));
    }
    /**
     * Proses kenaikan kelas untuk sekelompok siswa
     */
    public function processKenaikanKelas(Request $request)
    {
        if (! $this->promotionWritesEnabled()) {
            return $this->legacyPromotionActionDisabledResponse();
        }

        $request->validate([
            'siswa_ids' => 'required|array',
            'siswa_ids.*' => 'exists:siswas,id',
            'kelas_tujuan_id' => 'required|exists:kelas,id',
        ]);
    
        DB::beginTransaction();
        try {
            $kelasTujuan = Kelas::findOrFail($request->kelas_tujuan_id);
            $siswaDetails = [];
    
            foreach ($request->siswa_ids as $siswaId) {
                $siswa = Siswa::findOrFail($siswaId);
                $kelasAsal = $siswa->kelas;
                
                $siswa->kelas_id = $request->kelas_tujuan_id;
                $siswa->is_naik_kelas = true;
                $siswa->save();
                
                // Simpan detail untuk feedback
                $siswaDetails[] = [
                    'id' => $siswa->id,
                    'nama' => $siswa->nama,
                    'kelas_asal' => "Kelas {$kelasAsal->nomor_kelas} {$kelasAsal->nama_kelas}",
                    'kelas_tujuan' => "Kelas {$kelasTujuan->nomor_kelas} {$kelasTujuan->nama_kelas}"
                ];
            }
            
            DB::commit();
            
            // Kirim data detail untuk SweetAlert
            return redirect()->back()->with([
                'success' => 'Berhasil memproses kenaikan kelas untuk ' . count($request->siswa_ids) . ' siswa',
                'siswa_details' => $siswaDetails,
                'action_type' => 'kenaikan'
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return redirect()->back()->with('error', 'Gagal memproses kenaikan kelas: ' . $e->getMessage());
        }
    }
    

    /**
     * Proses tinggal kelas untuk sekelompok siswa
     */
    public function processTinggalKelas(Request $request)
    {
        if (! $this->promotionWritesEnabled()) {
            return $this->legacyPromotionActionDisabledResponse();
        }

        $request->validate([
            'siswa_ids' => 'required|array',
            'siswa_ids.*' => 'exists:siswas,id',
            'kelas_tujuan_id' => 'required|exists:kelas,id',
        ]);

        DB::beginTransaction();
        try {
            $kelasTujuan = Kelas::findOrFail($request->kelas_tujuan_id);
            $siswaDetails = [];

            foreach ($request->siswa_ids as $siswaId) {
                $siswa = Siswa::findOrFail($siswaId);
                $kelasAsal = $siswa->kelas;
                
                $siswa->kelas_id = $request->kelas_tujuan_id;
                $siswa->is_naik_kelas = false;
                $siswa->save();
                
                // Simpan detail untuk feedback
                $siswaDetails[] = [
                    'id' => $siswa->id,
                    'nama' => $siswa->nama,
                    'kelas_asal' => "Kelas {$kelasAsal->nomor_kelas} {$kelasAsal->nama_kelas}",
                    'kelas_tujuan' => "Kelas {$kelasTujuan->nomor_kelas} {$kelasTujuan->nama_kelas}"
                ];
            }
            
            DB::commit();
            
            // Kirim data detail untuk SweetAlert
            return redirect()->back()->with([
                'success' => 'Berhasil memproses siswa tinggal kelas untuk ' . count($request->siswa_ids) . ' siswa',
                'siswa_details' => $siswaDetails,
                'action_type' => 'tinggal'
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return redirect()->back()->with('error', 'Gagal memproses tinggal kelas: ' . $e->getMessage());
        }
    }

    /**
     * Proses kenaikan kelas massal untuk semua siswa
     */
    public function processMassPromotion()
    {
        if (! $this->promotionWritesEnabled()) {
            return $this->legacyPromotionActionDisabledResponse();
        }

        // Cek tahun ajaran aktif dan tahun ajaran baru
        $tahunAjaranAktif = TahunAjaran::where('is_active', true)->first();
        
        // Cari tahun ajaran baru
        $tahunParts = explode('/', $tahunAjaranAktif->tahun_ajaran);
        $tahunAwal = (int)$tahunParts[0];
            
        $tahunAjaranBaru = TahunAjaran::where(function($query) use ($tahunAwal) {
                $query->whereRaw("SUBSTRING_INDEX(tahun_ajaran, '/', 1) > ?", [$tahunAwal]);
            })
            ->orderBy('tahun_ajaran')
            ->first();
        
        if (!$tahunAjaranBaru) {
            return redirect()->back()->with('error', 'Tidak dapat menemukan tahun ajaran berikutnya.');
        }
        
        DB::beginTransaction();
        try {
            // Ambil semua kelas dari tahun ajaran aktif yang bukan kelas 6
            $kelasAktif = Kelas::where('tahun_ajaran_id', $tahunAjaranAktif->id)
                        ->where('nomor_kelas', '<', 6) // Exclude kelas 6
                        ->orderBy('nomor_kelas')
                        ->get();
            
            // Siapkan penghitung untuk statistik
            $promoted = 0;
            $graduated = 0;
            $notProcessed = 0;
            
            // Array untuk menyimpan detail siswa 
            $promotedDetails = [];
            $graduatedDetails = [];
            $notProcessedDetails = [];
            
            // Group kelas berdasarkan tingkat (nomor_kelas)
            $kelasByTingkat = $kelasAktif->groupBy('nomor_kelas');
            
            // Proses setiap tingkat
            foreach ($kelasByTingkat as $nomorKelas => $kelasGroup) {
                // Kumpulkan semua siswa dari semua kelas di tingkat ini
                $allSiswaInTingkat = collect();
                
                foreach ($kelasGroup as $kelas) {
                    $siswaList = Siswa::where('kelas_id', $kelas->id)
                                ->where('status', 'aktif')
                                ->get();
                    $allSiswaInTingkat = $allSiswaInTingkat->merge($siswaList);
                }
                
                if ($allSiswaInTingkat->isEmpty()) {
                    continue;
                }
                
                // Cari semua kelas tujuan di tingkat berikutnya
                $kelasTujuanList = Kelas::where('tahun_ajaran_id', $tahunAjaranBaru->id)
                            ->where('nomor_kelas', $nomorKelas + 1)
                            ->orderBy('nama_kelas')
                            ->get();
                
                if ($kelasTujuanList->isEmpty()) {
                    // Tidak ada kelas tujuan
                    $notProcessed += $allSiswaInTingkat->count();
                    
                    foreach ($allSiswaInTingkat as $siswa) {
                        $notProcessedDetails[] = [
                            'id' => $siswa->id,
                            'nama' => $siswa->nama,
                            'kelas_asal' => "Kelas {$nomorKelas} {$siswa->kelas->nama_kelas}",
                            'alasan' => "Tidak ada kelas tujuan untuk tingkat " . ($nomorKelas + 1)
                        ];
                    }
                    continue;
                }
                
                // Randomkan urutan siswa
                $shuffledSiswa = $allSiswaInTingkat->shuffle();
                
                // Distribusikan siswa secara merata ke kelas-kelas tujuan
                $jumlahKelasTujuan = $kelasTujuanList->count();
                $siswaPerKelas = [];
                
                // Inisialisasi array untuk setiap kelas tujuan
                foreach ($kelasTujuanList as $index => $kelasTujuan) {
                    $siswaPerKelas[$index] = [];
                }
                
                // Distribusikan siswa secara round-robin
                foreach ($shuffledSiswa as $index => $siswa) {
                    $kelasIndex = $index % $jumlahKelasTujuan;
                    $siswaPerKelas[$kelasIndex][] = $siswa;
                }
                
                // Pindahkan siswa ke kelas tujuan masing-masing
                foreach ($siswaPerKelas as $kelasIndex => $siswaArray) {
                    $kelasTujuan = $kelasTujuanList[$kelasIndex];
                    
                    foreach ($siswaArray as $siswa) {
                        $kelasAsal = $siswa->kelas;
                        
                        $siswa->kelas_id = $kelasTujuan->id;
                        $siswa->is_naik_kelas = true;
                        $siswa->kelas_tujuan_id = null;
                        $siswa->save();
                        $promoted++;
                        
                        // Tambahkan detail
                        $promotedDetails[] = [
                            'id' => $siswa->id,
                            'nama' => $siswa->nama,
                            'kelas_asal' => "Kelas {$kelasAsal->nomor_kelas} {$kelasAsal->nama_kelas}",
                            'kelas_tujuan' => "Kelas {$kelasTujuan->nomor_kelas} {$kelasTujuan->nama_kelas}"
                        ];
                    }
                }
            }
            
            // Proses khusus untuk kelas 6 (kelulusan)
            $kelas6List = Kelas::where('tahun_ajaran_id', $tahunAjaranAktif->id)
                        ->where('nomor_kelas', 6)
                        ->get();
            
            foreach ($kelas6List as $kelas) {
                $siswaList = Siswa::where('kelas_id', $kelas->id)
                            ->where('status', 'aktif')
                            ->get();
                
                foreach ($siswaList as $siswa) {
                    $siswa->status = 'lulus';
                    $siswa->is_naik_kelas = true;
                    $siswa->kelas_tujuan_id = null;
                    $siswa->save();
                    $graduated++;
                    
                    // Tambahkan detail
                    $graduatedDetails[] = [
                        'id' => $siswa->id,
                        'nama' => $siswa->nama,
                        'kelas_asal' => "Kelas {$kelas->nomor_kelas} {$kelas->nama_kelas}"
                    ];
                }
            }
            
            DB::commit();
            
            // Buat pesan sukses dengan statistik
            $message = "Kenaikan kelas berhasil diproses. Detail: {$promoted} siswa naik kelas, {$graduated} siswa lulus";
            if ($notProcessed > 0) {
                $message .= ", {$notProcessed} siswa tidak dapat diproses karena tidak ada kelas tujuan.";
            }
            
            return redirect()->route('admin.kenaikan-kelas.index')->with([
                'success' => $message,
                'mass_promotion' => true,
                'stats' => [
                    'promoted' => $promoted,
                    'graduated' => $graduated,
                    'notProcessed' => $notProcessed
                ],
                'details' => [
                    'promoted' => $promotedDetails,
                    'graduated' => $graduatedDetails,
                    'notProcessed' => $notProcessedDetails
                ]
            ]);
        } catch (\Exception $e) {
            DB::rollback();
            return redirect()->back()->with('error', 'Gagal memproses kenaikan kelas: ' . $e->getMessage());
        }
    }

    /**
     * Proses kelulusan untuk sekelompok siswa
     */
    public function processKelulusan(Request $request)
    {
        if (! $this->promotionWritesEnabled()) {
            return $this->legacyPromotionActionDisabledResponse();
        }

        $request->validate([
            'siswa_ids' => 'required|array',
            'siswa_ids.*' => 'exists:siswas,id',
            'status' => 'required|in:lulus,pindah,dropout',
            'kelas_tinggal_id' => 'required_if:status,pindah|exists:kelas,id',
        ]);

        DB::beginTransaction();
        try {
            $siswaDetails = [];
            
            foreach ($request->siswa_ids as $siswaId) {
                $siswa = Siswa::findOrFail($siswaId);
                $kelasAsal = $siswa->kelas;
                
                $siswa->status = $request->status;
                
                // Jika status "pindah" (tidak lulus), siswa ditempatkan di kelas yang dipilih
                if ($request->status === 'pindah' && $request->has('kelas_tinggal_id')) {
                    $siswa->kelas_id = $request->kelas_tinggal_id;
                    $siswa->is_naik_kelas = false; // Tandai sebagai tidak naik kelas
                    
                    // Ambil informasi kelas tujuan
                    $kelasTujuan = Kelas::findOrFail($request->kelas_tinggal_id);
                    
                    // Simpan detail untuk feedback
                    $siswaDetails[] = [
                        'id' => $siswa->id,
                        'nama' => $siswa->nama,
                        'kelas_asal' => "Kelas {$kelasAsal->nomor_kelas} {$kelasAsal->nama_kelas}",
                        'status' => 'Tidak Lulus',
                        'kelas_tujuan' => "Kelas {$kelasTujuan->nomor_kelas} {$kelasTujuan->nama_kelas}"
                    ];
                } else {
                    // Untuk status lulus atau lainnya, info normal
                    $siswaDetails[] = [
                        'id' => $siswa->id,
                        'nama' => $siswa->nama,
                        'kelas_asal' => "Kelas {$kelasAsal->nomor_kelas} {$kelasAsal->nama_kelas}",
                        'status' => $request->status === 'lulus' ? 'Lulus' : ucfirst($request->status)
                    ];
                }
                
                $siswa->save();
            }
            
            DB::commit();
            
            // Ubah pesan sukses berdasarkan status
            $statusLabel = $request->status === 'lulus' ? 'Lulus' : 'Tidak Lulus';
            
            // Kirim data detail untuk SweetAlert
            return redirect()->back()->with([
                'success' => "Berhasil memproses {$statusLabel} untuk " . count($request->siswa_ids) . " siswa",
                'siswa_details' => $siswaDetails,
                'action_type' => 'kelulusan',
                'status' => $request->status
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return redirect()->back()->with('error', 'Gagal memproses: ' . $e->getMessage());
        }
    }

    private function resolveSourceTahunAjaran(Request $request): ?TahunAjaran
    {
        $requestedTahunAjaranId = $request->query('tahun_ajaran_id');

        if ($requestedTahunAjaranId !== null && $requestedTahunAjaranId !== '') {
            $requestedTahunAjaranId = (int) $requestedTahunAjaranId;
            $semester = DB::table('tahun_ajarans')
                ->where('id', $requestedTahunAjaranId)
                ->value('semester');

            if ((int) $semester !== 2) {
                abort(422, 'Perencanaan kenaikan kelas hanya mendukung sumber semester genap.');
            }

            return TahunAjaran::findOrFail($requestedTahunAjaranId);
        }

        return TahunAjaran::where('is_active', true)->first();
    }

    private function findNextSemesterOneYear(TahunAjaran $sourceTahunAjaran): ?TahunAjaran
    {
        if (! preg_match('/^(\d{4})\/(\d{4})$/', (string) $sourceTahunAjaran->tahun_ajaran, $matches)) {
            return null;
        }

        $nextLabel = ((int) $matches[1] + 1) . '/' . ((int) $matches[2] + 1);

        return TahunAjaran::where('tahun_ajaran', $nextLabel)
            ->where('semester', 1)
            ->orderBy('tanggal_mulai')
            ->first();
    }

    private function attachPromotionRosterCounts(Collection $kelasAktif, TahunAjaran $tahunAjaran, SiswaKelasSemesterResolver $enrollmentResolver): void
    {
        foreach ($kelasAktif as $kelas) {
            $kelas->promotion_roster_count = $this->studentsForPromotionClass($kelas, $tahunAjaran, $enrollmentResolver)->count();
        }
    }

    private function studentsForPromotionClass(Kelas $kelas, TahunAjaran $tahunAjaran, SiswaKelasSemesterResolver $enrollmentResolver): Collection
    {
        if ((int) $tahunAjaran->semester !== 2) {
            return collect();
        }

        $query = $enrollmentResolver
            ->studentQueryForClass($kelas->id, $tahunAjaran->id, 2, true);

        if (Schema::hasColumn('siswas', 'status')) {
            $query->where('status', 'aktif');
        }

        return $query->orderBy('nama')
            ->get()
            ->unique('id')
            ->values();
    }

    private function promotionWritesEnabled(): bool
    {
        return false;
    }

    private function legacyPromotionActionDisabledResponse()
    {
        return redirect()->route('admin.kenaikan-kelas.index')
            ->with('error', self::PROMOTION_WRITES_DISABLED_MESSAGE);
    }
}

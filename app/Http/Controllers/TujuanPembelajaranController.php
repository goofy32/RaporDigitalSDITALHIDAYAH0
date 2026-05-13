<?php

namespace App\Http\Controllers;

use App\Models\TujuanPembelajaran;
use App\Models\LingkupMateri;
use App\Models\MataPelajaran;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class TujuanPembelajaranController extends Controller
{
    // Method untuk menampilkan halaman tambah tujuan pembelajaran
    public function create($mataPelajaranId)
    {
        $mataPelajaran = MataPelajaran::with('lingkupMateris')->findOrFail($mataPelajaranId);
        return view('data.add_tp', compact('mataPelajaran'));
    }
    
    // Method untuk mengambil semua tujuan pembelajaran berdasarkan mata pelajaran
    public function listByMataPelajaran($mataPelajaranId)
    {
        try {
            $mataPelajaran = MataPelajaran::with(['lingkupMateris.tujuanPembelajarans'])->findOrFail($mataPelajaranId);
            
            // Kumpulkan semua tujuan pembelajaran dari semua lingkup materi
            $tujuanPembelajarans = collect();
            foreach ($mataPelajaran->lingkupMateris as $lingkupMateri) {
                // Ambil setiap tujuan pembelajaran dan tambahkan lingkupMateri
                foreach ($lingkupMateri->tujuanPembelajarans as $tp) {
                    $tp->lingkupMateri; // Load relasi untuk setiap item
                    $tujuanPembelajarans->push($tp);
                }
            }
            
            return response()->json([
                'success' => true,
                'tujuanPembelajarans' => $tujuanPembelajarans
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error fetching data: ' . $e->getMessage()
            ], 500);
        }
    }

    // Method untuk menampilkan view semua tujuan pembelajaran
    public function view($mataPelajaranId)
    {
        $mataPelajaran = MataPelajaran::with(['lingkupMateris.tujuanPembelajarans', 'kelas'])->findOrFail($mataPelajaranId);
        return view('data.add_tp', compact('mataPelajaran'));
    }

    // Method untuk menyimpan tujuan pembelajaran
    public function store(Request $request)
    {
        $request->validate([
            'tpData' => 'required|array',
            'tpData.*.lingkupMateriId' => 'required|integer|exists:lingkup_materis,id',
            'tpData.*.kodeTP' => 'required|integer|min:1',
            'tpData.*.deskripsiTP' => 'required|string',
            'mataPelajaranId' => 'required|exists:mata_pelajarans,id'
        ]);

        try {
            DB::beginTransaction();

            foreach ($request->tpData as $tp) {
                // Verifikasi lingkup materi terkait dengan mata pelajaran
                $lingkupMateri = LingkupMateri::where('id', $tp['lingkupMateriId'])
                    ->where('mata_pelajaran_id', $request->mataPelajaranId)
                    ->firstOrFail();

                // Cek apakah kode TP sudah ada untuk lingkup materi ini
                $existingTP = TujuanPembelajaran::where('lingkup_materi_id', $tp['lingkupMateriId'])
                    ->where('kode_tp', $tp['kodeTP'])
                    ->first();

                if ($existingTP) {
                    throw new \Exception("Kode TP {$tp['kodeTP']} sudah ada untuk lingkup materi ini.");
                }

                // Simpan tujuan pembelajaran baru
                TujuanPembelajaran::create([
                    'lingkup_materi_id' => $tp['lingkupMateriId'],
                    'kode_tp' => $tp['kodeTP'],
                    'deskripsi_tp' => $tp['deskripsiTP']
                ]);
            }

            DB::commit();
            return response()->json(['success' => true, 'message' => 'Data berhasil disimpan!']);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }
    
    // Method untuk hapus tujuan pembelajaran
    public function destroy($id)
    {
        try {
            $tp = TujuanPembelajaran::findOrFail($id);
            
            // Start a transaction to ensure all related data is deleted properly
            DB::beginTransaction();
            
            // Delete the TP itself.
            // Nilai terkait akan ikut soft delete lewat model event.
            $tp->delete();
            
            DB::commit();
            
            return response()->json([
                'success' => true,
                'message' => 'Tujuan pembelajaran berhasil dihapus!'
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            
            return response()->json([
                'success' => false,
                'message' => 'Error deleting data: ' . $e->getMessage()
            ], 500);
        }
    }

    public function checkDependencies($id)
    {
        try {
            $tp = TujuanPembelajaran::findOrFail($id);
            
            // Check if there are associated grades
            $hasDependents = $tp->nilais()->exists();
            
            return response()->json([
                'success' => true,
                'hasDependents' => $hasDependents
            ]);
            
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error checking dependencies: ' . $e->getMessage(),
                'hasDependents' => true // Assume there are dependents in case of error (safer)
            ], 500);
        }
    }
    
    // Method untuk guru
    public function teacherCreate($mataPelajaranId)
    {
        $guruId = Auth::guard('guru')->id();
        
        $mataPelajaran = MataPelajaran::with('lingkupMateris')
            ->where('id', $mataPelajaranId)
            ->where('guru_id', $guruId)
            ->firstOrFail();
            
        return view('pengajar.add_tp', compact('mataPelajaran'));
    }

    public function teacherStore(Request $request)
    {
        $request->validate([
            'tpData' => 'required|array',
            'tpData.*.lingkupMateriId' => 'required|integer|exists:lingkup_materis,id',
            'tpData.*.kodeTP' => 'required|integer|min:1',
            'tpData.*.deskripsiTP' => 'required|string',
            'mataPelajaranId' => 'required|exists:mata_pelajarans,id'
        ]);

        try {
            $guruId = Auth::guard('guru')->id();
            
            // Verify teacher owns the mata pelajaran
            $mataPelajaran = MataPelajaran::where('id', $request->mataPelajaranId)
                ->where('guru_id', $guruId)
                ->firstOrFail();
                
            DB::beginTransaction();

            foreach ($request->tpData as $tp) {
                // Verify lingkup materi belongs to this mata pelajaran
                $lingkupMateri = LingkupMateri::where('id', $tp['lingkupMateriId'])
                    ->where('mata_pelajaran_id', $request->mataPelajaranId)
                    ->firstOrFail();

                // Check if kode TP already exists
                $existingTP = TujuanPembelajaran::where('lingkup_materi_id', $tp['lingkupMateriId'])
                    ->where('kode_tp', $tp['kodeTP'])
                    ->first();

                if ($existingTP) {
                    throw new \Exception("Kode TP {$tp['kodeTP']} sudah ada untuk lingkup materi ini.");
                }

                TujuanPembelajaran::create([
                    'lingkup_materi_id' => $tp['lingkupMateriId'],
                    'kode_tp' => $tp['kodeTP'],
                    'deskripsi_tp' => $tp['deskripsiTP']
                ]);
            }

            DB::commit();
            return response()->json(['success' => true, 'message' => 'Data berhasil disimpan!']);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }
    
    // Method untuk melengkapi implementasi view TP untuk guru
    public function teacherView($mataPelajaranId)
    {
        $guruId = Auth::guard('guru')->id();
        
        $mataPelajaran = MataPelajaran::with(['lingkupMateris.tujuanPembelajarans', 'kelas'])
            ->where('id', $mataPelajaranId)
            ->where('guru_id', $guruId)
            ->firstOrFail();
            
        return view('pengajar.add_tp', compact('mataPelajaran'));
    }
    
    // Method untuk hapus tujuan pembelajaran khusus guru
    public function teacherDestroy($id)
    {
        try {
            $guruId = Auth::guard('guru')->id();
            $tp = TujuanPembelajaran::findOrFail($id);
            
            // Verifikasi kepemilikan
            if (!$tp->belongsToGuru($guruId)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Anda tidak memiliki akses untuk menghapus data ini.'
                ], 403);
            }
            
            // Cek apakah tujuan pembelajaran ini sudah digunakan dalam nilai
            $hasNilai = $tp->nilais()->exists();
            
            if ($hasNilai) {
                return response()->json([
                    'success' => false,
                    'message' => 'Tujuan pembelajaran ini sudah digunakan dalam penilaian dan tidak dapat dihapus.'
                ], 400);
            }
            
            $tp->delete();
            
            return response()->json([
                'success' => true,
                'message' => 'Tujuan pembelajaran berhasil dihapus!'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error deleting data: ' . $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * Check for TPs that are dependent on a Lingkup Materi
     * This is used to determine if a Lingkup Materi can be safely deleted
     */
    public function checkLingkupMateriDependencies($lingkupMateriId)
    {
        try {
            $lingkupMateri = LingkupMateri::findOrFail($lingkupMateriId);
            $hasTPs = $lingkupMateri->tujuanPembelajarans()->exists();
            
            return response()->json([
                'success' => true,
                'hasDependents' => $hasTPs
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error checking dependencies: ' . $e->getMessage(),
                'hasDependents' => true // Assume there are dependents in case of error (safer)
            ], 500);
        }
    }
}

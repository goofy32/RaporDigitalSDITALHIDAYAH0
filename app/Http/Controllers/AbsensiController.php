<?php

namespace App\Http\Controllers;

use App\Traits\RequiresTahunAjaran;
use App\Models\Absensi;
use App\Models\Siswa;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AbsensiController extends Controller
{
    use RequiresTahunAjaran;

    public function index(Request $request)
    {
        $waliKelas = auth()->guard('guru')->user();
        $kelasWaliId = $waliKelas->getWaliKelasId();
        $tahunAjaranId = session('tahun_ajaran_id');
        
        if (!$kelasWaliId) {
            return redirect()->back()->with('error', 'Anda belum ditugaskan sebagai wali kelas untuk kelas manapun.');
        }
        
        $query = Absensi::with('siswa')
            ->whereHas('siswa', function($query) use ($kelasWaliId) {
                $query->where('kelas_id', $kelasWaliId);
            });
    
        // Filter berdasarkan tahun ajaran
        if ($tahunAjaranId) {
            $query->where('tahun_ajaran_id', $tahunAjaranId);
        }
    
        if ($request->has('search')) {
            $search = $request->search;
            $query->whereHas('siswa', function($q) use ($search) {
                $q->where('nama', 'LIKE', "%{$search}%")
                  ->orWhere('nis', 'LIKE', "%{$search}%");
            });
        }
    
        // Tambah filter semester
        if ($request->has('semester')) {
            $query->where('semester', $request->semester);
        }
    
        $absensis = $query->orderBy('created_at', 'desc')->paginate(10);
        return view('wali_kelas.absence', compact('absensis'));
    }
    
    public function create()
    {
        $guru = auth()->guard('guru')->user();
        $tahunAjaranId = session('tahun_ajaran_id');
        
        // Get wali kelas ID directly from the relation table
        $kelasWali = DB::table('guru_kelas')
            ->join('kelas', 'guru_kelas.kelas_id', '=', 'kelas.id')
            ->where('guru_kelas.guru_id', $guru->id)
            ->where('guru_kelas.is_wali_kelas', true)
            ->where('guru_kelas.role', 'wali_kelas')
            ->where('kelas.tahun_ajaran_id', $tahunAjaranId)
            ->select('kelas.id as kelas_id')
            ->first();
        
        $kelasWaliId = $kelasWali ? $kelasWali->kelas_id : null;
        
        // Get the active semester from the tahun ajaran
        $tahunAjaran = \App\Models\TahunAjaran::find($tahunAjaranId);
        $currentSemester = $tahunAjaran ? $tahunAjaran->semester : 1; // Default to 1 if not found
        
        \Log::info("AbsensiController::create", [
            'guru_id' => $guru->id,
            'tahun_ajaran_id' => $tahunAjaranId,
            'kelas_wali_id' => $kelasWaliId,
            'current_semester' => $currentSemester
        ]);
        
        if (!$kelasWaliId) {
            return redirect()->back()->with('error', 'Anda belum ditugaskan sebagai wali kelas untuk kelas manapun pada tahun ajaran ini.');
        }
        
        // Only filter by kelas_id, not tahun_ajaran_id
        $siswa = \App\Models\Siswa::where('kelas_id', $kelasWaliId)
                ->orderBy('nama')
                ->get();
        
        \Log::info("AbsensiController found:", [
            'siswa_count' => $siswa->count(),
            'siswa_ids' => $siswa->pluck('id')->toArray()
        ]);
        
        return view('wali_kelas.add_absence', compact('siswa', 'kelasWaliId', 'currentSemester', 'tahunAjaran'));
    }

    public function store(Request $request)
    {
        $tahunAjaranId = $this->getValidTahunAjaranId();

        if (!$tahunAjaranId) {
            return $this->failTahunAjaranNotSet($request);
        }

        $tahunAjaran = \App\Models\TahunAjaran::find($tahunAjaranId);
        $currentSemester = $tahunAjaran ? $tahunAjaran->semester : $request->semester;
        
        $request->validate([
            'siswa_id' => 'required|exists:siswas,id',
            'sakit' => 'required|integer|min:0',
            'izin' => 'required|integer|min:0',
            'tanpa_keterangan' => 'required|integer|min:0',
        ]);

        // Cek apakah sudah ada data absensi untuk siswa dan semester ini pada tahun ajaran yang sama
        $existingAbsensi = Absensi::where('siswa_id', $request->siswa_id)
                                ->where('semester', $currentSemester)
                                ->where('tahun_ajaran_id', $tahunAjaranId)
                                ->first();

        if ($existingAbsensi) {
            return redirect()->back()
                        ->withInput()
                        ->with('error', 'Data absensi untuk siswa ini di semester yang sama sudah ada');
        }

        // Create data with the current semester
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
            $kelasWaliId = $waliKelas->getWaliKelasId();
            $tahunAjaranId = session('tahun_ajaran_id');
            
            \Log::info('Editing absensi', [
                'id' => $id,
                'kelasWaliId' => $kelasWaliId
            ]);
            
            $absensi = Absensi::with('siswa')
                ->whereHas('siswa', function($query) use ($kelasWaliId) {
                    $query->where('kelas_id', $kelasWaliId);
                })
                ->findOrFail($id);
            
            // Get the active tahun ajaran for context
            $tahunAjaran = \App\Models\TahunAjaran::find($tahunAjaranId);
            
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
        
        // Keep the original semester
        $data = $request->all();
        $data['semester'] = $absensi->semester; // Maintain the original semester
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
}

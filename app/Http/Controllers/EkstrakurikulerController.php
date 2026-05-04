<?php

namespace App\Http\Controllers;

use App\Models\Ekstrakurikuler;
use App\Models\NilaiEkstrakurikuler;
use App\Models\Siswa;
use App\Traits\RequiresTahunAjaran;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

class EkstrakurikulerController extends Controller
{
    use RequiresTahunAjaran;

    public function index(Request $request)
    {
        $tahunAjaranId = session('tahun_ajaran_id');
        $query = Ekstrakurikuler::query();
        
        // Filter berdasarkan tahun ajaran (jika kolom tahun_ajaran_id ada di tabel)
        if ($tahunAjaranId && Schema::hasColumn('ekstrakurikulers', 'tahun_ajaran_id')) {
            $query->where('tahun_ajaran_id', $tahunAjaranId);
        }
        
        // Handle search
        if ($request->has('search')) {
            $search = $request->search;
            $query->where(function($q) use ($search) {
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
        // Ambil data wali kelas yang sedang login
        $waliKelas = auth()->guard('guru')->user();
        $kelasWaliId = $waliKelas->getWaliKelasId();
        $tahunAjaranId = session('tahun_ajaran_id');
        
        if (!$kelasWaliId) {
            return redirect()->back()->with('error', 'Anda belum ditugaskan sebagai wali kelas untuk kelas manapun.');
        }
        
        $query = NilaiEkstrakurikuler::with(['siswa', 'ekstrakurikuler'])
            ->whereHas('siswa', function($query) use ($kelasWaliId) {
                $query->where('kelas_id', $kelasWaliId);
            });

        // Filter berdasarkan tahun ajaran
        if ($tahunAjaranId) {
            $query->where('tahun_ajaran_id', $tahunAjaranId);
        }
    
        // Tambah fitur pencarian
        if ($request->has('search')) {
            $search = $request->search;
            $query->where(function($q) use ($search) {
                $q->whereHas('siswa', function($sq) use ($search) {
                    $sq->where('nama', 'LIKE', "%{$search}%")
                       ->orWhere('nis', 'LIKE', "%{$search}%");
                })->orWhereHas('ekstrakurikuler', function($eq) use ($search) {
                    $eq->where('nama_ekstrakurikuler', 'LIKE', "%{$search}%");
                });
            });
        }
    
        // Urutkan berdasarkan yang terbaru
        $nilaiEkstrakurikuler = $query->orderBy('created_at', 'desc')->paginate(10);
        return view('wali_kelas.ekstrakurikuler', compact('nilaiEkstrakurikuler'));
    }
    
    public function waliKelasCreate()
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
        
        \Log::info("EkstrakurikulerController::waliKelasCreate", [
            'guru_id' => $guru->id,
            'tahun_ajaran_id' => $tahunAjaranId,
            'kelas_wali_id' => $kelasWaliId
        ]);
        
        if (!$kelasWaliId) {
            return redirect()->back()->with('error', 'Anda belum ditugaskan sebagai wali kelas untuk kelas manapun pada tahun ajaran ini.');
        }
        
        // IMPORTANT: Only filter by kelas_id, not tahun_ajaran_id
        // This is the key fix based on the debug output
        $siswa = \App\Models\Siswa::where('kelas_id', $kelasWaliId)
                ->orderBy('nama')
                ->get();
        
        $ekstrakurikuler = \App\Models\Ekstrakurikuler::where('tahun_ajaran_id', $tahunAjaranId)
                        ->orderBy('nama_ekstrakurikuler')
                        ->get();
        
        \Log::info("EkstrakurikulerController found:", [
            'siswa_count' => $siswa->count(),
            'ekstrakurikuler_count' => $ekstrakurikuler->count()
        ]);
        
        return view('wali_kelas.add_ekstrakurikuler', compact('ekstrakurikuler', 'siswa', 'kelasWaliId'));
    }
    public function waliKelasStore(Request $request)
    {
        $tahunAjaranId = $this->getValidTahunAjaranId();

        if (!$tahunAjaranId) {
            return $this->failTahunAjaranNotSet($request);
        }

        $waliKelas = auth()->guard('guru')->user();
        $kelasWaliId = $waliKelas->getWaliKelasId();
        
        if (!$kelasWaliId) {
            return redirect()->back()->with('error', 'Anda belum ditugaskan sebagai wali kelas untuk kelas manapun.');
        }
        
        // Validasi bahwa siswa berada di kelas yang diajar
        $validated = $request->validate([
            'siswa_id' => [
                'required',
                'exists:siswas,id',
                function ($attribute, $value, $fail) use ($kelasWaliId) {
                    $siswa = Siswa::find($value);
                    if ($siswa->kelas_id !== $kelasWaliId) {
                        $fail('Siswa tidak terdaftar di kelas Anda.');
                    }
                },
            ],
            'ekstrakurikuler_id' => 'required|exists:ekstrakurikulers,id',
            'predikat' => 'required|string',
            'deskripsi' => 'nullable|string',
        ]);
    
        // Cek apakah siswa sudah memiliki nilai untuk ekstrakurikuler ini pada tahun ajaran yang sama
        $exists = NilaiEkstrakurikuler::where('siswa_id', $validated['siswa_id'])
            ->where('ekstrakurikuler_id', $validated['ekstrakurikuler_id'])
            ->where('tahun_ajaran_id', $tahunAjaranId)
            ->exists();
            
        if ($exists) {
            return back()->with('error', 'Siswa sudah memiliki nilai untuk ekstrakurikuler ini pada tahun ajaran yang sama.');
        }
    
        // Set nilai tahun_ajaran_id secara eksplisit sebelum membuat record
        $nilaiEkstrakurikuler = new NilaiEkstrakurikuler([
            'siswa_id' => $validated['siswa_id'],
            'ekstrakurikuler_id' => $validated['ekstrakurikuler_id'],
            'predikat' => $validated['predikat'],
            'deskripsi' => $validated['deskripsi'],
            'tahun_ajaran_id' => $tahunAjaranId  // Set ini secara eksplisit
        ]);
        
        $nilaiEkstrakurikuler->save();
    
        return redirect()->route('wali_kelas.ekstrakurikuler.index')
            ->with('success', 'Data ekstrakurikuler berhasil ditambahkan');
    }

    public function waliKelasEdit($id)
    {
        $waliKelas = auth()->guard('guru')->user();
        $kelasWaliId = $waliKelas->getWaliKelasId();
        
        if (!$kelasWaliId) {
            return redirect()->back()->with('error', 'Anda belum ditugaskan sebagai wali kelas untuk kelas manapun.');
        }
        
        try {
            $nilaiEkstrakurikuler = NilaiEkstrakurikuler::with(['siswa', 'ekstrakurikuler'])
                ->whereHas('siswa', function($query) use ($kelasWaliId) {
                    $query->where('kelas_id', $kelasWaliId);
                })
                ->findOrFail($id);
            
            return view('wali_kelas.edit_ekstrakurikuler', compact('nilaiEkstrakurikuler'));
        } catch (\Exception $e) {
            \Log::error('Error editing ekstrakurikuler: ' . $e->getMessage());
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

        $waliKelas = auth()->guard('guru')->user();
        $kelasWaliId = $waliKelas->getWaliKelasId();
        
        if (!$kelasWaliId) {
            return redirect()->back()->with('error', 'Anda belum ditugaskan sebagai wali kelas untuk kelas manapun.');
        }
        
        $validated = $request->validate([
            'predikat' => 'required|string',
            'deskripsi' => 'nullable|string',
        ]);
    
        // Tambahkan tahun ajaran ke data
        $validated['tahun_ajaran_id'] = $tahunAjaranId;
        
        try {
            $nilaiEkstrakurikuler = NilaiEkstrakurikuler::whereHas('siswa', function($query) use ($kelasWaliId) {
                $query->where('kelas_id', $kelasWaliId);
            })->findOrFail($id);
            
            $nilaiEkstrakurikuler->update($validated);
    
            return redirect()->route('wali_kelas.ekstrakurikuler.index')
                ->with('success', 'Data ekstrakurikuler berhasil diperbarui');
        } catch (\Exception $e) {
            \Log::error('Error updating ekstrakurikuler: ' . $e->getMessage());
            return redirect()->route('wali_kelas.ekstrakurikuler.index')
                ->with('error', 'Data ekstrakurikuler tidak ditemukan atau Anda tidak memiliki akses.');
        }
    }
}

<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Prestasi;
use App\Models\Kelas;
use App\Models\Siswa;
use App\Traits\RequiresTahunAjaran;
use Illuminate\Support\Facades\Log;


class AchievementController extends Controller
{
    use RequiresTahunAjaran;

    // Menampilkan semua data prestasi
    public function index(Request $request)
    {
        $tahunAjaranId = session('tahun_ajaran_id');
        $query = Prestasi::with(['kelas', 'siswa']);
        
        // Filter berdasarkan tahun ajaran
        if ($tahunAjaranId) {
            $query->where('tahun_ajaran_id', $tahunAjaranId);
        }
        
        if ($request->has('search')) {
            $search = strtolower($request->search);
            $terms = explode(' ', trim($search));
            
            $query->where(function($q) use ($terms, $search) {
                // Jika kata pertama adalah "kelas"
                if (count($terms) > 0 && $terms[0] === 'kelas') {
                    $q->whereHas('kelas', function($kelasQ) use ($terms) {
                        if (count($terms) > 1 && is_numeric($terms[1])) {
                            // Jika ada nomor kelas yang dispecifikkan
                            $kelasQ->where('nomor_kelas', $terms[1]);
                        } else {
                            // Jika hanya "kelas", urutkan berdasarkan nomor_kelas
                            $kelasQ->orderBy('nomor_kelas', 'asc');
                        }
                    });
                } else {
                    // Pencarian normal
                    $q->where('jenis_prestasi', 'LIKE', "%{$search}%")
                      ->orWhere('keterangan', 'LIKE', "%{$search}%")
                      ->orWhereHas('siswa', function($siswaQ) use ($search) {
                          $siswaQ->where('nama', 'LIKE', "%{$search}%")
                                ->orWhere('nis', 'LIKE', "%{$search}%")
                                ->orWhere('nisn', 'LIKE', "%{$search}%");
                      })
                      ->orWhereHas('kelas', function($kelasQ) use ($search) {
                          $kelasQ->where('nama_kelas', 'LIKE', "%{$search}%")
                                ->orWhere('nomor_kelas', 'LIKE', "%{$search}%");
                      });
                }
            });
        }
        
        $prestasis = $query->paginate(10);
        return view('admin.achievement', compact('prestasis'));
    }

    // Menampilkan form tambah prestasi
    public function create()
    {
        $tahunAjaranId = session('tahun_ajaran_id');
        
        $kelas = Kelas::when($tahunAjaranId, function($query) use ($tahunAjaranId) {
                return $query->where('tahun_ajaran_id', $tahunAjaranId);
            })
            ->orderBy('nomor_kelas')
            ->orderBy('nama_kelas')
            ->get();
            
        $siswa = Siswa::with('kelas')
            ->whereHas('kelas', function($query) use ($tahunAjaranId) {
                if ($tahunAjaranId) {
                    $query->where('tahun_ajaran_id', $tahunAjaranId);
                }
            })
            ->orderBy('nama')
            ->get();
            
        return view('data.add_prestasi', compact('kelas', 'siswa'));
    }

    // Menyimpan data prestasi
    public function store(Request $request)
    {
        $tahunAjaranId = $this->getValidTahunAjaranId();

        if (!$tahunAjaranId) {
            return $this->failTahunAjaranNotSet($request);
        }

        $validated = $request->validate([
            'kelas_id' => 'required|exists:kelas,id',
            'siswa_id' => 'required|exists:siswas,id',
            'jenis_prestasi' => 'required|string|max:255',
            'keterangan' => 'nullable|string|max:500',
        ]);
    
        $validated['tahun_ajaran_id'] = $tahunAjaranId;
    
        Prestasi::create($validated);
    
        return redirect()->route('achievement.index')->with('success', 'Data Prestasi berhasil ditambahkan');
    }

    // Menampilkan form edit
    public function edit($id)
    {
        $tahunAjaranId = session('tahun_ajaran_id');
        $prestasi = Prestasi::with('siswa')->findOrFail($id);
        
        $kelas = Kelas::when($tahunAjaranId, function($query) use ($tahunAjaranId) {
                return $query->where('tahun_ajaran_id', $tahunAjaranId);
            })
            ->orderBy('nomor_kelas')
            ->orderBy('nama_kelas')
            ->get();
        
        // Ambil semua siswa untuk referensi tapi siswa tidak akan bisa diubah di form edit
        $siswa = Siswa::with('kelas')
            ->whereHas('kelas', function($query) use ($tahunAjaranId) {
                if ($tahunAjaranId) {
                    $query->where('tahun_ajaran_id', $tahunAjaranId);
                }
            })
            ->orderBy('nama')
            ->get();
        
        return view('data.edit_prestasi', compact('prestasi', 'kelas', 'siswa'));
    }
    
    // Memperbarui data prestasi
    public function update(Request $request, $id)
    {
        $tahunAjaranId = $this->getValidTahunAjaranId();

        if (!$tahunAjaranId) {
            return $this->failTahunAjaranNotSet($request);
        }

        $validated = $request->validate([
            'kelas_id' => 'required|exists:kelas,id',
            'siswa_id' => 'required|exists:siswas,id',
            'jenis_prestasi' => 'required|string|max:255',
            'keterangan' => 'nullable|string|max:500',
        ]);
    
        $validated['tahun_ajaran_id'] = $tahunAjaranId;
        
        $prestasi = Prestasi::findOrFail($id);
        
        // Pastikan siswa dan kelas tidak berubah
        if ($prestasi->siswa_id != $validated['siswa_id']) {
            return redirect()->back()->with('error', 'Siswa tidak boleh diubah saat mengedit prestasi.');
        }
        
        if ($prestasi->kelas_id != $validated['kelas_id']) {
            return redirect()->back()->with('error', 'Kelas tidak boleh diubah saat mengedit prestasi.');
        }
        
        // Hanya update field jenis_prestasi dan keterangan
        $prestasi->update([
            'jenis_prestasi' => $validated['jenis_prestasi'],
            'keterangan' => $validated['keterangan'],
            'tahun_ajaran_id' => $tahunAjaranId
        ]);
    
        return redirect()->route('achievement.index')->with('success', 'Data Prestasi berhasil diperbarui');
    }
    
    // Menghapus data prestasi
    public function destroy($id)
    {
        $prestasi = Prestasi::findOrFail($id);
        $prestasi->delete();

        return redirect()->route('achievement.index')->with('success', 'Data Prestasi berhasil dihapus');
    }
}

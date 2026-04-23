<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Kelas;
use App\Models\Siswa;
use App\Models\Guru;
use App\Models\MataPelajaran;
use App\Models\Nilai;
use App\Models\Ekstrakurikuler;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ClassController extends Controller
{
    // Menampilkan daftar kelas
    public function index(Request $request)
    {
        // Ambil tahun ajaran dari session
        $tahunAjaranId = session('tahun_ajaran_id');
        
        // Buat query dasar
        $query = Kelas::query();
        
        // Filter berdasarkan tahun ajaran jika ada
        if ($tahunAjaranId) {
            $query->where('tahun_ajaran_id', $tahunAjaranId);
        }
        
        // Handle pencarian
        if ($request->has('search')) {
            $search = strtolower($request->search);
            $terms = explode(' ', trim($search));
            
            $query->where(function($q) use ($terms, $search) {
                // Jika kata pertama adalah "kelas"
                if (count($terms) > 0 && $terms[0] === 'kelas') {
                    if (count($terms) > 1 && is_numeric($terms[1])) {
                        // Jika ada nomor kelas yang dispecifikkan (kelas 1, kelas 2, dst)
                        $q->where('nomor_kelas', $terms[1]);
                    } else {
                        // Jika hanya "kelas", urutkan berdasarkan nomor_kelas
                        $q->orderBy('nomor_kelas', 'asc');
                    }
                } else {
                    // Pencarian normal untuk term lainnya
                    $q->where('nama_kelas', 'like', '%' . $search . '%')
                      ->orWhere('nomor_kelas', 'like', '%' . $search . '%')
                      ->orWhereHas('guru', function($guruQuery) use ($search) {
                          $guruQuery->where('nama', 'like', '%' . $search . '%')
                                   ->where('guru_kelas.is_wali_kelas', true);
                      });
                }
            });
        }
        
        // Default ordering jika tidak ada pencarian
        if (!$request->has('search') || 
            (isset($terms) && count($terms) === 1 && $terms[0] === 'kelas')) {
            $query->orderBy('nomor_kelas', 'asc')
                  ->orderBy('nama_kelas', 'asc');
        }
        
        $kelasList = $query->paginate(10);
        
        // Pass data tahun ajaran ke view untuk menampilkan informasi
        $activeTahunAjaran = null;
        if ($tahunAjaranId) {
            $activeTahunAjaran = \App\Models\TahunAjaran::find($tahunAjaranId);
        }
        
        return view('admin.class', compact('kelasList', 'activeTahunAjaran'));
    }

    // Menampilkan form tambah data kelas
    public function create()
    {
        $tahunAjaranId = session('tahun_ajaran_id');
        
        $assignedWaliKelasIds = DB::table('guru_kelas')
            ->join('kelas', 'guru_kelas.kelas_id', '=', 'kelas.id')
            ->where('guru_kelas.is_wali_kelas', true)
            ->where('guru_kelas.role', 'wali_kelas')
            ->when($tahunAjaranId, function ($query) use ($tahunAjaranId) {
                $query->where('kelas.tahun_ajaran_id', $tahunAjaranId);
            })
            ->pluck('guru_kelas.guru_id');

        $guruList = Guru::whereNotIn('id', $assignedWaliKelasIds)
            ->orderBy('nama')
            ->get();
    
        return view('data.create_class', compact('guruList'));
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'nomor_kelas' => 'required|integer|min:1|max:99',
            'nama_kelas' => 'required|string|max:255',
        ], [
            'nomor_kelas.required' => 'Nomor kelas harus diisi',
            'nomor_kelas.integer' => 'Nomor kelas harus berupa angka',
            'nomor_kelas.min' => 'Nomor kelas minimal 1',
            'nomor_kelas.max' => 'Nomor kelas maksimal 99',
            'nama_kelas.required' => 'Nama kelas harus diisi',
            'nama_kelas.max' => 'Nama kelas maksimal 255 karakter'
        ]);
    
        $tahunAjaranId = session('tahun_ajaran_id');

        // Check for existing class with the same name
        $existingClass = Kelas::where('nomor_kelas', $request->nomor_kelas)
                            ->where('nama_kelas', $request->nama_kelas)
                            ->where('tahun_ajaran_id', $tahunAjaranId) // ← Pastikan ada ini
                            ->first();
                            
        if ($existingClass) {
            return back()
                ->withInput()
                ->withErrors(['nama_kelas' => 'Kelas ' . $request->nomor_kelas . ' ' . $request->nama_kelas . ' sudah ada di tahun ajaran ini. Gunakan nama kelas yang berbeda.']);
        }
    
        DB::beginTransaction();
        try {
            // Buat kelas baru
            $kelas = Kelas::create([
                'nomor_kelas' => $request->nomor_kelas,
                'nama_kelas' => $request->nama_kelas
            ]);
    
            // Jika ada wali kelas yang dipilih
            if ($request->filled('wali_kelas_id')) {
                // Ambil data guru
                $guru = Guru::find($request->wali_kelas_id);
                
                if (!$guru) {
                    throw new \Exception('Guru tidak ditemukan');
                }
                
                // Attach guru sebagai wali kelas
                $kelas->guru()->attach($request->wali_kelas_id, [
                    'is_wali_kelas' => true,
                    'role' => 'wali_kelas'
                ]);
    
                // Update jabatan guru menjadi guru_wali
                $guru->jabatan = 'guru_wali';
                $guru->save();
                
                // Tambahkan logging untuk debugging
                Log::info('Mengubah jabatan guru', [
                    'guru_id' => $guru->id,
                    'nama' => $guru->nama,
                    'jabatan_baru' => 'guru_wali'
                ]);
            }
    
            DB::commit();
            return redirect()->route('kelas.index')
                ->with('success', 'Data kelas berhasil ditambahkan');
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error saat membuat kelas: ' . $e->getMessage(), [
                'stacktrace' => $e->getTraceAsString()
            ]);
            return back()->with('error', 'Terjadi kesalahan: ' . $e->getMessage())->withInput();
        }
    }

    public function edit($id)
    {
        $tahunAjaranId = session('tahun_ajaran_id');
        $kelas = Kelas::findOrFail($id);
        
        // Ambil data wali kelas saat ini (jika ada)
        $waliKelas = $kelas->guru()
                        ->wherePivot('is_wali_kelas', true)
                        ->wherePivot('role', 'wali_kelas')
                        ->first();
        
        $assignedWaliKelasIds = DB::table('guru_kelas')
            ->join('kelas', 'guru_kelas.kelas_id', '=', 'kelas.id')
            ->where('guru_kelas.is_wali_kelas', true)
            ->where('guru_kelas.role', 'wali_kelas')
            ->when($tahunAjaranId, function ($query) use ($tahunAjaranId) {
                $query->where('kelas.tahun_ajaran_id', $tahunAjaranId);
            })
            ->when($waliKelas, function ($query) use ($waliKelas) {
                $query->where('guru_kelas.guru_id', '!=', $waliKelas->id);
            })
            ->pluck('guru_kelas.guru_id');

        $availableGuruList = Guru::whereNotIn('id', $assignedWaliKelasIds)
            ->orderBy('nama')
            ->get();
        
        return view('data.edit_class', compact('kelas', 'waliKelas', 'availableGuruList'));
    }
        
    public function update(Request $request, $id)
    {
        $validated = $request->validate([
            'nomor_kelas' => 'required|integer|min:1|max:99',
            'nama_kelas' => 'required|string|max:255',
            'wali_kelas_id' => 'nullable|exists:gurus,id',
            'change_wali_kelas' => 'nullable|boolean',
            'current_wali_kelas_id' => 'nullable|exists:gurus,id',
        ], [
            'nomor_kelas.required' => 'Nomor kelas harus diisi',
            'nomor_kelas.integer' => 'Nomor kelas harus berupa angka',
            'nomor_kelas.min' => 'Nomor kelas minimal 1',
            'nomor_kelas.max' => 'Nomor kelas maksimal 99',
            'nama_kelas.required' => 'Nama kelas harus diisi',
            'nama_kelas.max' => 'Nama kelas maksimal 255 karakter',
            'wali_kelas_id.exists' => 'Guru yang dipilih tidak valid',
            'current_wali_kelas_id.exists' => 'Wali kelas saat ini tidak valid'
        ]);

        // Ambil tahun ajaran dari kelas yang sedang diedit
        $kelas = Kelas::findOrFail($id);
        $tahunAjaranId = $kelas->tahun_ajaran_id;

        // PERBAIKAN: Tambahkan filter tahun_ajaran_id pada validasi
        $existingClass = Kelas::where('id', '!=', $id)
            ->where('nomor_kelas', $request->nomor_kelas)
            ->where('nama_kelas', $request->nama_kelas)
            ->where('tahun_ajaran_id', $tahunAjaranId) // ← Tambahkan ini
            ->first();
            
        if ($existingClass) {
            return back()
                ->withInput()
                ->withErrors(['nama_kelas' => 'Kelas ' . $request->nomor_kelas . ' ' . $request->nama_kelas . ' sudah ada di tahun ajaran ini. Gunakan nama kelas yang berbeda.']);
        }

        DB::beginTransaction();
        try {
            // PERBAIKAN: Update validasi dalam transaction juga
            $existingClass = Kelas::where('id', '!=', $id)
                                ->where('nomor_kelas', $validated['nomor_kelas'])
                                ->where('nama_kelas', $validated['nama_kelas'])
                                ->where('tahun_ajaran_id', $tahunAjaranId) // ← Tambahkan ini
                                ->first();
            
            if ($existingClass) {
                return back()
                    ->withInput()
                    ->with('error', 'Kelas ' . $validated['nomor_kelas'] . ' ' . $validated['nama_kelas'] . ' sudah ada di tahun ajaran ini. Silakan gunakan nama kelas yang berbeda.');
            }
            
            // Update data kelas
            $kelas->update([
                'nomor_kelas' => $validated['nomor_kelas'],
                'nama_kelas' => $validated['nama_kelas']
            ]);
            
                        // Menangani perubahan wali kelas
            $currentWaliKelas = $kelas->guru()
                                ->wherePivot('is_wali_kelas', true)
                                ->wherePivot('role', 'wali_kelas')
                                ->first();
            
            // Kasus 1: Ada wali kelas saat ini dan pengguna ingin menggantinya
            if ($currentWaliKelas && $request->has('change_wali_kelas') && $request->change_wali_kelas == 1) {
                // Validasi wali kelas baru harus dipilih
                if (!$request->filled('wali_kelas_id')) {
                    return back()
                        ->withInput()
                        ->with('error', 'Anda harus memilih wali kelas baru.');
                }
                
                // Validasi wali kelas baru tidak boleh sama dengan wali kelas lama
                if ($request->wali_kelas_id == $currentWaliKelas->id) {
                    return back()
                        ->withInput()
                        ->with('error', 'Wali kelas baru tidak boleh sama dengan wali kelas lama.');
                }
                
                // Ambil data guru baru
                $newWaliKelas = Guru::find($request->wali_kelas_id);
                
                if (!$newWaliKelas) {
                    throw new \Exception('Guru baru tidak ditemukan');
                }
                
                // Pastikan guru baru belum menjadi wali kelas di kelas lain
                $guruAlreadyWaliKelas = DB::table('guru_kelas')
                    ->where('guru_id', $newWaliKelas->id)
                    ->where('is_wali_kelas', true)
                    ->where('role', 'wali_kelas')
                    ->exists();
                    
                if ($guruAlreadyWaliKelas) {
                    return back()
                        ->withInput()
                        ->with('error', 'Guru yang dipilih sudah menjadi wali kelas di kelas lain.');
                }
                
                // 1. Hapus relasi wali kelas lama
                DB::table('guru_kelas')
                    ->where('guru_id', $currentWaliKelas->id)
                    ->where('kelas_id', $kelas->id)
                    ->where('is_wali_kelas', true)
                    ->where('role', 'wali_kelas')
                    ->delete();
                
                // 2. Cek apakah guru lama masih menjadi wali kelas di kelas lain
                $stillWaliKelasElsewhere = DB::table('guru_kelas')
                    ->where('guru_id', $currentWaliKelas->id)
                    ->where('kelas_id', '!=', $kelas->id)
                    ->where('is_wali_kelas', true)
                    ->where('role', 'wali_kelas')
                    ->exists();
                    
                // 3. Jika tidak menjadi wali kelas di kelas lain, ubah jabatan guru lama menjadi 'guru'
                if (!$stillWaliKelasElsewhere) {
                    $currentWaliKelas->jabatan = 'guru';
                    $currentWaliKelas->save();
                    
                    Log::info('Mengubah jabatan guru dari guru_wali menjadi guru', [
                        'guru_id' => $currentWaliKelas->id,
                        'nama' => $currentWaliKelas->nama
                    ]);
                }
                
                // 4. Attach guru baru sebagai wali kelas
                $kelas->guru()->attach($newWaliKelas->id, [
                    'is_wali_kelas' => true,
                    'role' => 'wali_kelas'
                ]);
                
                // 5. Update jabatan guru baru menjadi guru_wali
                $newWaliKelas->jabatan = 'guru_wali';
                $newWaliKelas->save();
                
                Log::info('Mengganti wali kelas', [
                    'kelas_id' => $kelas->id,
                    'old_wali_kelas' => $currentWaliKelas->id,
                    'new_wali_kelas' => $newWaliKelas->id
                ]);
            }
            // Kasus 2: Belum ada wali kelas dan pengguna ingin menambahkan wali kelas
            elseif (!$currentWaliKelas && $request->filled('wali_kelas_id')) {
                // Ambil data guru
                $guru = Guru::find($request->wali_kelas_id);
                
                if (!$guru) {
                    throw new \Exception('Guru tidak ditemukan');
                }
                
                // Pastikan guru belum menjadi wali kelas di kelas lain
                $guruAlreadyWaliKelas = DB::table('guru_kelas')
                    ->where('guru_id', $guru->id)
                    ->where('is_wali_kelas', true)
                    ->where('role', 'wali_kelas')
                    ->exists();
                    
                if ($guruAlreadyWaliKelas) {
                    return back()
                        ->withInput()
                        ->with('error', 'Guru yang dipilih sudah menjadi wali kelas di kelas lain.');
                }
                
                // Attach guru sebagai wali kelas
                $kelas->guru()->attach($guru->id, [
                    'is_wali_kelas' => true,
                    'role' => 'wali_kelas'
                ]);
                
                // Update jabatan guru menjadi guru_wali
                $guru->jabatan = 'guru_wali';
                $guru->save();
                
                Log::info('Menambahkan wali kelas pada kelas yang sudah ada', [
                    'kelas_id' => $kelas->id,
                    'guru_id' => $guru->id,
                    'nama_guru' => $guru->nama
                ]);
            }

            
            DB::commit();
            return redirect()->route('kelas.index')
                ->with('success', 'Data kelas berhasil diperbarui');
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error saat mengupdate kelas: ' . $e->getMessage(), [
                'kelas_id' => $id,
                'stacktrace' => $e->getTraceAsString()
            ]);
            
            return back()
                ->with('error', 'Terjadi kesalahan: ' . $e->getMessage())
                ->withInput();
        }
    }
    // Menghapus data kelas
    public function destroy($id)
    {
        $kelas = Kelas::findOrFail($id);
        
        // Ambil wali kelas jika ada
        $waliKelas = $kelas->guru()
            ->wherePivot('is_wali_kelas', true)
            ->wherePivot('role', 'wali_kelas')
            ->first();
        
        DB::beginTransaction();
        try {
            // Jika ada wali kelas, update jabatannya kembali menjadi 'guru'
            if ($waliKelas) {
                // Hitung apakah guru masih menjadi wali kelas di kelas lain
                $otherWaliKelasCount = DB::table('guru_kelas')
                    ->where('guru_id', $waliKelas->id)
                    ->where('kelas_id', '!=', $id)
                    ->where('is_wali_kelas', true)
                    ->where('role', 'wali_kelas')
                    ->count();
                
                // Jika tidak menjadi wali kelas di kelas lain, ubah jabatan menjadi 'guru'
                if ($otherWaliKelasCount == 0) {
                    $waliKelas->jabatan = 'guru';
                    $waliKelas->save();
                }
            }
            
            $kelas->delete();
            
            DB::commit();
            return redirect()->route('kelas.index')
                ->with('success', 'Data kelas berhasil dihapus.');
        } catch (\Exception $e) {
            DB::rollBack();
            return redirect()->route('kelas.index')
                ->with('error', 'Terjadi kesalahan saat menghapus kelas: ' . $e->getMessage());
        }
    }

    // Fungsi-fungsi lainnya tetap seperti sebelumnya
    public function adminDashboard()
    {
        $totalStudents = Siswa::count();
        $totalTeachers = Guru::count();
        $totalSubjects = MataPelajaran::count();
        $totalClasses = Kelas::count();
        $totalExtracurriculars = Ekstrakurikuler::count();
        $overallProgress = $this->calculateOverallProgressForAdmin();
        $informationItems = $this->getInformationItems();
        $kelas = Kelas::all();
    
        return view('admin.dashboard', compact(
            'totalStudents',
            'totalTeachers',
            'totalSubjects',
            'totalClasses',
            'totalExtracurriculars',
            'overallProgress',
            'kelas',
            'informationItems'
        ));
    }
    
    private function calculateOverallProgressForAdmin()
    {
        $totalNilai = Nilai::count();
        $completedNilai = Nilai::whereNotNull('nilai_akhir_rapor')->count();

        return $totalNilai > 0 ? ($completedNilai / $totalNilai) * 100 : 0;
    }

    private function calculateClassProgress()
    {
        $classes = Kelas::with('mataPelajarans')->get();
        $progress = [];

        foreach ($classes as $class) {
            $totalScores = 0;
            $completedScores = 0;

            foreach ($class->mataPelajarans as $subject) {
                $totalStudents = $class->siswas()->count();
                $totalScores += $totalStudents;
                $completedScores += Nilai::where('mata_pelajaran_id', $subject->id)
                    ->whereNotNull('nilai_akhir_rapor')
                    ->count();
            }

            $progress[$class->nama_kelas] = $totalScores > 0 ? ($completedScores / $totalScores) * 100 : 0;
        }

        return $progress;
    }

    public function getKelasProgressAdmin($kelasId)
    {
        try {
            $kelas = Kelas::with(['siswas', 'mataPelajarans'])->findOrFail($kelasId);
            $progress = $this->calculateClassProgressForSingleClass($kelas);
            
            Log::info('Admin Kelas progress calculated:', [
                'kelas_id' => $kelasId,
                'progress' => $progress
            ]);
            
            return response()->json([
                'success' => true,
                'progress' => $progress
            ]);
            
        } catch (\Exception $e) {
            Log::error('Error calculating class progress:', [
                'error' => $e->getMessage(),
                'kelas_id' => $kelasId
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Error calculating progress'
            ], 500);
        }
    }

    private function calculateOverallProgress()
    {
        $totalScores = DB::table('siswas')
            ->join('kelas', 'siswas.kelas_id', '=', 'kelas.id')
            ->join('mata_pelajarans', 'kelas.id', '=', 'mata_pelajarans.kelas_id')
            ->count();

        $completedScores = Nilai::whereNotNull('nilai_akhir_rapor')->count();

        return $totalScores > 0 ? ($completedScores / $totalScores) * 100 : 0;
    }

    private function getInformationItems()
    {
        // Implement this method to fetch information items
        // For now, we'll return an empty array
        return [];
    }

    
    public function getKelasProgress($kelasId)
    {
        try {
            $kelas = Kelas::findOrFail($kelasId);
            $progress = $this->calculateClassProgressForSingleClass($kelas);
            
            Log::info('Kelas progress calculated:', [
                'kelas_id' => $kelasId,
                'progress' => $progress
            ]);
            
            return response()->json([
                'success' => true,
                'progress' => $progress,
                'details' => [
                    'kelas' => $kelas->nama_kelas,
                    'total_students' => $kelas->siswas()->count()
                ]
            ]);
        } catch (\Exception $e) {
            Log::error('Error calculating kelas progress:', [
                'kelas_id' => $kelasId,
                'error' => $e->getMessage()
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Error calculating progress',
                'progress' => 0
            ]);
        }
    }
    

    private function calculateClassProgressForSingleClass($kelas)
    {
        $totalPossibleNilai = 0;
        $completedNilai = 0;
    
        // Hitung untuk setiap siswa dan mata pelajaran
        $kelas->siswas->each(function($siswa) use (&$totalPossibleNilai, &$completedNilai) {
            $siswa->mataPelajarans->each(function($mapel) use (&$totalPossibleNilai, &$completedNilai, $siswa) {
                $totalPossibleNilai++;
                
                $nilai = Nilai::where([
                    'siswa_id' => $siswa->id,
                    'mata_pelajaran_id' => $mapel->id
                ])->whereNotNull('nilai_akhir_rapor')->first();
                
                if ($nilai) {
                    $completedNilai++;
                }
            });
        });
    
        return $totalPossibleNilai > 0 ? round(($completedNilai / $totalPossibleNilai) * 100, 2) : 0;
    }
}

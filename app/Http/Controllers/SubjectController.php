<?php

namespace App\Http\Controllers;

use App\Models\MataPelajaran;
use App\Models\Kelas;
use App\Models\Guru;
use App\Models\Siswa;
use App\Models\LingkupMateri;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;

class SubjectController extends Controller
{
    public function index(Request $request)
    {
        // Ambil tahun ajaran dari session
        $tahunAjaranId = session('tahun_ajaran_id');
        
        // Gunakan join untuk memastikan kita bisa mengurutkan berdasarkan kolom dari tabel kelas
        $query = MataPelajaran::join('kelas', 'mata_pelajarans.kelas_id', '=', 'kelas.id')
            ->select('mata_pelajarans.*') // Pastikan hanya mengambil kolom dari mata_pelajarans
            ->with(['kelas', 'guru']); // Load relasi kelas dan guru
            
        // Filter berdasarkan tahun ajaran jika ada
        if ($tahunAjaranId) {
            $query->where('mata_pelajarans.tahun_ajaran_id', $tahunAjaranId);
        }
            
        // Handle pencarian
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
                        }
                    });
                } else {
                    // Pencarian normal
                    $q->where('mata_pelajarans.nama_pelajaran', 'LIKE', "%{$search}%")
                      ->orWhereHas('kelas', function($kelasQ) use ($search) {
                          $kelasQ->where('nama_kelas', 'LIKE', "%{$search}%")
                                ->orWhere('nomor_kelas', 'LIKE', "%{$search}%");
                      })
                      ->orWhereHas('guru', function($guruQ) use ($search) {
                          $guruQ->where('nama', 'LIKE', "%{$search}%");
                      });
                }
            });
        }
    
        // Default sorting: urutkan berdasarkan nomor kelas (ascending) lalu nama kelas
        $query->orderBy('kelas.nomor_kelas', 'asc')
              ->orderBy('kelas.nama_kelas', 'asc')
              ->orderBy('mata_pelajarans.nama_pelajaran', 'asc');
        
        $subjects = $query->paginate(10);
        
        // Pass data tahun ajaran ke view untuk menampilkan informasi
        $activeTahunAjaran = null;
        if ($tahunAjaranId) {
            $activeTahunAjaran = \App\Models\TahunAjaran::find($tahunAjaranId);
        }
        
        return view('admin.subject', compact('subjects', 'activeTahunAjaran'));
    }

    public function create()
    {
        $tahunAjaranId = session('tahun_ajaran_id');
        
        $classes = Kelas::when($tahunAjaranId, function($query) use ($tahunAjaranId) {
                return $query->where('tahun_ajaran_id', $tahunAjaranId);
            })
            ->orderBy('nomor_kelas')
            ->orderBy('nama_kelas')
            ->get();
            
        $teachers = Guru::orderBy('nama')->get();
        
        // Ambil semua mata pelajaran untuk validasi JavaScript
        $mataPelajaranList = MataPelajaran::select('id', 'nama_pelajaran', 'kelas_id', 'semester')
            ->when($tahunAjaranId, function($query) use ($tahunAjaranId) {
                return $query->where('tahun_ajaran_id', $tahunAjaranId);
            })
            ->get();
        
        // Kode ini akan dimanfaatkan oleh JavaScript
        $waliKelasMap = Kelas::getWaliKelasMap($tahunAjaranId);
        
        return view('data.add_subject', compact('classes', 'teachers', 'waliKelasMap', 'mataPelajaranList'));
    }

    public function show($id)
    {
        $subject = MataPelajaran::findOrFail($id);

        return redirect()->route('subject.edit', $subject->id);
    }
    
    public function store(Request $request)
    {
        \Log::info('Subject store method called with data:', $request->all());
    
        try {
            // Validasi array subjects
            $request->validate([
                'subjects' => 'required|array',
                'subjects.*.mata_pelajaran' => 'required|string|max:255',
                'subjects.*.kelas' => 'required|exists:kelas,id',
                'subjects.*.guru_pengampu' => 'required|exists:gurus,id',
                'subjects.*.semester' => 'required|integer|min:1|max:2',
                'subjects.*.lingkup_materi' => 'required|array',
                'subjects.*.lingkup_materi.*' => 'required|string|max:255',
            ], [
                'subjects.*.guru_pengampu.required' => 'Harap pilih guru pengampu untuk mata pelajaran',
                'subjects.*.kelas.required' => 'Harap pilih kelas untuk mata pelajaran',
                'subjects.*.mata_pelajaran.required' => 'Nama mata pelajaran harus diisi',
                'subjects.*.semester.required' => 'Semester harus dipilih',
                'subjects.*.lingkup_materi.required' => 'Lingkup materi harus diisi'
            ]);
    
            DB::beginTransaction();
            $successCount = 0;
            $errorMessages = [];
    
            foreach ($request->subjects as $index => $subjectData) {
                // Convert checkbox value to boolean 
                $isMuatanLokal = isset($subjectData['is_muatan_lokal']);
                $allowNonWali = isset($subjectData['allow_non_wali']);
    
                // Get data for this entry
                $kelasId = $subjectData['kelas'];
                $kelas = Kelas::find($kelasId);
                $guruId = $subjectData['guru_pengampu'];
                $guru = Guru::find($guruId);
                
                if (!$kelas || !$guru) {
                    $errorMessages[] = "Data kelas atau guru tidak valid untuk mata pelajaran {$subjectData['mata_pelajaran']}.";
                    continue;
                }
                
                // Validasi sesuai status muatan lokal dan allow_non_wali
                if ($isMuatanLokal) {
                    // Jika ini adalah mata pelajaran muatan lokal: 
                    // Hanya guru biasa (bukan wali kelas) yang dapat mengajar
                    if ($guru->jabatan == 'guru_wali') {
                        $errorMessages[] = "Mata pelajaran {$subjectData['mata_pelajaran']} (muatan lokal) untuk kelas {$kelas->nomor_kelas} {$kelas->nama_kelas} hanya dapat diajar oleh guru dengan jabatan guru (bukan wali kelas).";
                        continue;
                    }
                } else {
                    // Ini adalah mata pelajaran biasa (bukan muatan lokal)
                    if ($kelas->hasWaliKelas()) {
                        $waliKelasId = $kelas->getWaliKelasId();
                        
                        // Jika tidak diizinkan untuk guru bukan wali kelas
                        if (!$allowNonWali) {
                            if ($waliKelasId != $guruId) {
                                $errorMessages[] = "Mata pelajaran {$subjectData['mata_pelajaran']} untuk kelas {$kelas->nomor_kelas} {$kelas->nama_kelas} harus diajar oleh wali kelasnya.";
                                continue;
                            }
                        }
                    } else {
                        // Kelas tidak memiliki wali kelas
                        if (!$allowNonWali) {
                            $errorMessages[] = "Kelas {$kelas->nomor_kelas} {$kelas->nama_kelas} belum memiliki wali kelas. Harap tambahkan wali kelas terlebih dahulu, atau centang opsi 'Pelajaran non-muatan lokal dengan guru bukan wali kelas'.";
                            continue;
                        }
                    }
                }
    
                // Cek duplikasi nama mata pelajaran dalam satu kelas untuk semester yang sama
                $exists = MataPelajaran::where('kelas_id', $kelasId)
                    ->where('nama_pelajaran', $subjectData['mata_pelajaran'])
                    ->where('semester', $subjectData['semester'])
                    ->exists();
                    
                if ($exists) {
                    $errorMessages[] = "Mata pelajaran {$subjectData['mata_pelajaran']} untuk kelas {$kelas->nomor_kelas} {$kelas->nama_kelas} semester {$subjectData['semester']} sudah ada.";
                    continue;
                }
    
                // Simpan Mata Pelajaran
                $mataPelajaran = MataPelajaran::create([
                    'nama_pelajaran' => $subjectData['mata_pelajaran'],
                    'kelas_id' => $kelasId,
                    'guru_id' => $guruId,
                    'semester' => $subjectData['semester'],
                    'is_muatan_lokal' => $isMuatanLokal,
                    'allow_non_wali' => $allowNonWali,
                ]);
    
                // Simpan Lingkup Materi
                foreach ($subjectData['lingkup_materi'] as $judulLingkupMateri) {
                    LingkupMateri::create([
                        'mata_pelajaran_id' => $mataPelajaran->id,
                        'judul_lingkup_materi' => $judulLingkupMateri,
                    ]);
                }
    
                $successCount++;
            }
    
            DB::commit();
            
            $message = $successCount > 0 
                ? "Berhasil menambahkan {$successCount} mata pelajaran!" 
                : "Tidak ada mata pelajaran yang ditambahkan.";
                
            if (count($errorMessages) > 0) {
                $message .= " Terdapat " . count($errorMessages) . " kesalahan.";
                \Log::warning('Errors during subject creation:', $errorMessages);
            }
    
            return redirect()->route('subject.index')
                ->with('success', $message)
                ->with('errors', $errorMessages);
        } catch (\Exception $e) {
            DB::rollBack();
            \Log::error('Error in subject store method:', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return back()->withInput()->with('error', 'Terjadi kesalahan: ' . $e->getMessage());
        }
    }

    public function checkLingkupMateriDependencies($id)
    {
        try {
            $lingkupMateri = LingkupMateri::findOrFail($id);
            
            // Verify permission
            if (auth()->guard('guru')->check()) {
                $guru = auth()->guard('guru')->user();
                $mataPelajaran = $lingkupMateri->mataPelajaran;
                
                if ($mataPelajaran->guru_id != $guru->id) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Tidak memiliki izin untuk memeriksa data ini'
                    ], 403);
                }
            }
            
            // Cek apakah ada tujuan pembelajaran terkait
            $hasDependents = $lingkupMateri->tujuanPembelajarans()->exists();
            
            return response()->json([
                'success' => true,
                'hasDependents' => $hasDependents
            ]);
            
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Terjadi kesalahan: ' . $e->getMessage()
            ], 500);
        }
    }
    
    public function deleteLingkupMateri($id)
    {
        try {
            $lingkupMateri = LingkupMateri::findOrFail($id);
            
            // Validate user has permission (either admin or the assigned teacher)
            if (auth()->guard('guru')->check()) {
                $guru = auth()->guard('guru')->user();
                $mataPelajaran = $lingkupMateri->mataPelajaran;
                
                if ($mataPelajaran->guru_id != $guru->id) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Tidak memiliki izin untuk menghapus data ini'
                    ], 403);
                }
            }
            
            // Mulai transaksi database untuk memastikan semua operasi berhasil atau gagal bersama
            DB::beginTransaction();
            
            // Hapus semua tujuan pembelajaran terkait terlebih dahulu
            if ($lingkupMateri->tujuanPembelajarans()->exists()) {
                $lingkupMateri->tujuanPembelajarans()->delete();
            }
            
            // Kemudian hapus lingkup materi
            $lingkupMateri->delete();
            
            DB::commit();
            
            return response()->json([
                'success' => true,
                'message' => 'Lingkup materi dan semua tujuan pembelajaran terkait berhasil dihapus'
            ]);
            
        } catch (\Exception $e) {
            DB::rollBack();
            
            return response()->json([
                'success' => false,
                'message' => 'Terjadi kesalahan: ' . $e->getMessage()
            ], 500);
        }
    }
    
    public function edit($id)
    {
        $tahunAjaranId = session('tahun_ajaran_id');
        $subject = MataPelajaran::with('lingkupMateris')->findOrFail($id);
        
        $classes = Kelas::when($tahunAjaranId, function($query) use ($tahunAjaranId) {
                return $query->where('tahun_ajaran_id', $tahunAjaranId);
            })
            ->orderBy('nomor_kelas')
            ->orderBy('nama_kelas')
            ->get();
            
        $teachers = Guru::orderBy('nama')->get();
        
        // Ambil semua mata pelajaran untuk validasi JavaScript
        $mataPelajaranList = MataPelajaran::select('id', 'nama_pelajaran', 'kelas_id', 'semester')
            ->when($tahunAjaranId, function($query) use ($tahunAjaranId) {
                return $query->where('tahun_ajaran_id', $tahunAjaranId);
            })
            ->get();
        
        // Panggil getWaliKelasMap sebagai method statis dengan parameter tahun ajaran
        $waliKelasMap = Kelas::getWaliKelasMap($tahunAjaranId);
        
        return view('data.edit_subject', compact('subject', 'classes', 'teachers', 'waliKelasMap', 'mataPelajaranList'));
    }
 
    public function updateLingkupMateri(Request $request, $id)
    {
        try {
            $lingkupMateri = LingkupMateri::findOrFail($id);
            
            // Validate user has permission (either admin or the assigned teacher)
            if (auth()->guard('guru')->check()) {
                $guru = auth()->guard('guru')->user();
                $mataPelajaran = $lingkupMateri->mataPelajaran;
                
                if ($mataPelajaran->guru_id != $guru->id) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Tidak memiliki izin untuk mengubah data ini'
                    ], 403);
                }
            }
            
            $request->validate([
                'judul_lingkup_materi' => 'required|string|max:255'
            ]);
            
            $lingkupMateri->update([
                'judul_lingkup_materi' => $request->judul_lingkup_materi
            ]);
            
            return response()->json([
                'success' => true,
                'message' => 'Lingkup materi berhasil diperbarui',
                'data' => $lingkupMateri
            ]);
            
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Terjadi kesalahan: ' . $e->getMessage()
            ], 500);
        }
    }

    public function update(Request $request, $id)
    {
        try {
            DB::beginTransaction();
    
            $subject = MataPelajaran::findOrFail($id);
    
            $validated = $request->validate([
                'mata_pelajaran' => 'required|string|max:255',
                'kelas' => 'required|exists:kelas,id',
                'guru_pengampu' => 'required|exists:gurus,id',
                'semester' => 'required|integer|min:1|max:2',
                'lingkup_materi' => 'required|array',
                'lingkup_materi.*' => 'required|string|max:255',
            ]);
    
            // Convert checkbox value to boolean
            $isMuatanLokal = $request->has('is_muatan_lokal');
            $allowNonWali = $request->has('allow_non_wali');
            
            // Ambil data kelas dan guru
            $kelas = Kelas::find($validated['kelas']);
            $guru = Guru::find($validated['guru_pengampu']);
            
            // Validasi sesuai status muatan lokal
            if ($isMuatanLokal) {
                // Jika ini adalah mata pelajaran muatan lokal: 
                // Hanya guru biasa (bukan wali kelas) yang dapat mengajar
                if ($guru && $guru->jabatan == 'guru_wali') {
                    return back()->withErrors([
                        'guru_pengampu' => 'Mata pelajaran muatan lokal hanya dapat diajar oleh guru dengan jabatan guru (bukan wali kelas).'
                    ])->withInput();
                }
            } else {
                // Ini adalah mata pelajaran biasa (bukan muatan lokal)
                if ($kelas && $kelas->hasWaliKelas()) {
                    $waliKelasId = $kelas->getWaliKelasId();
                    
                    // Jika tidak diizinkan untuk guru bukan wali kelas
                    if (!$allowNonWali && $waliKelasId != $validated['guru_pengampu']) {
                        return back()->withErrors([
                            'guru_pengampu' => 'Untuk mata pelajaran wajib (bukan muatan lokal), guru pengampu harus wali kelas dari kelas ini.'
                        ])->withInput();
                    }
                } else {
                    // Kelas tidak memiliki wali kelas
                    if (!$allowNonWali) {
                        return back()->withErrors([
                            'kelas' => 'Kelas ini belum memiliki wali kelas. Harap tambahkan wali kelas terlebih dahulu, atau centang opsi "Pelajaran non-muatan lokal dengan guru bukan wali kelas".'
                        ])->withInput();
                    }
                }
            }
    
            // Cek duplikasi nama mata pelajaran dalam satu kelas untuk semester yang sama
            // Kecuali mata pelajaran yang sedang diedit
            $exists = MataPelajaran::where('kelas_id', $validated['kelas'])
                ->where('nama_pelajaran', $validated['mata_pelajaran'])
                ->where('semester', $validated['semester'])
                ->where('id', '!=', $id) // Kecuali mata pelajaran yang sedang diedit
                ->exists();
                
            if ($exists) {
                return back()->withErrors([
                    'mata_pelajaran' => 'Mata pelajaran dengan nama yang sama sudah ada di kelas ini untuk semester yang sama.'
                ])->withInput();
            }
    
            // Update data mata pelajaran
            $subject->update([
                'nama_pelajaran' => $validated['mata_pelajaran'],
                'kelas_id' => $validated['kelas'],
                'guru_id' => $validated['guru_pengampu'],
                'semester' => $validated['semester'],
                'is_muatan_lokal' => $isMuatanLokal,
                'allow_non_wali' => $allowNonWali,
            ]);
    
            // Dapatkan lingkup materi yang sudah ada
            $existingLingkupMateriIds = $subject->lingkupMateris()->pluck('id')->toArray();
            $existingLingkupMateriTitles = $subject->lingkupMateris()->pluck('judul_lingkup_materi')->toArray();
            $newLingkupMateriTitles = $validated['lingkup_materi'];
            
            // Lingkup materi yang akan dihapus (ada di existing tapi tidak ada di input baru)
            $toBeDeletedTitles = array_diff($existingLingkupMateriTitles, $newLingkupMateriTitles);
            
            // Hapus lingkup materi yang tidak ada lagi
            if (!empty($toBeDeletedTitles)) {
                $subject->lingkupMateris()
                    ->whereIn('judul_lingkup_materi', $toBeDeletedTitles)
                    ->delete();
            }
            
            // Tambahkan lingkup materi baru yang belum ada
            foreach ($newLingkupMateriTitles as $judulLingkupMateri) {
                if (!in_array($judulLingkupMateri, $existingLingkupMateriTitles)) {
                    LingkupMateri::create([
                        'mata_pelajaran_id' => $subject->id,
                        'judul_lingkup_materi' => $judulLingkupMateri,
                    ]);
                }
            }
    
            DB::commit();
            return redirect()->route('subject.index')->with('success', 'Mata Pelajaran berhasil diperbarui!');
    
        } catch (\Exception $e) {
            DB::rollback();
            return back()->with('error', 'Terjadi kesalahan: ' . $e->getMessage())->withInput();
        }
    }

    public function destroy($id)
    {
        $subject = MataPelajaran::findOrFail($id);
        $subject->delete();

        return redirect()->route('subject.index')->with('success', 'Mata Pelajaran berhasil dihapus!');
    }

    public function teacherIndex()
    {
        $guru = auth()->guard('guru')->user();
        $tahunAjaranId = session('tahun_ajaran_id');
        
        // Gunakan join dengan tabel kelas untuk memungkinkan pengurutan yang lebih baik
        $subjects = MataPelajaran::join('kelas', 'mata_pelajarans.kelas_id', '=', 'kelas.id')
            ->select('mata_pelajarans.*') // Pastikan hanya mengambil kolom dari mata pelajaran
            ->with(['kelas', 'guru', 'lingkupMateris'])
            ->where('mata_pelajarans.guru_id', $guru->id)
            ->when($tahunAjaranId, function($query) use ($tahunAjaranId) {
                return $query->where('mata_pelajarans.tahun_ajaran_id', $tahunAjaranId);
            })
            ->orderBy('kelas.nomor_kelas', 'asc') // Urutkan berdasarkan nomor kelas
            ->orderBy('kelas.nama_kelas', 'asc')  // Kemudian nama kelas (A, B, C, dll)
            ->orderBy('mata_pelajarans.nama_pelajaran', 'asc') // Terakhir berdasarkan nama mata pelajaran
            ->paginate(10);
        
        return view('pengajar.subject', compact('subjects'));
    }

    public function teacherCreate()
    {
        // Ambil ID guru yang sedang login
        $guruId = Auth::guard('guru')->id();
        $guru = Auth::guard('guru')->user();
        $tahunAjaranId = session('tahun_ajaran_id');
        
        // Query untuk mendapatkan kelas
        $classesQuery = Kelas::query();
        
        // Filter kelas berdasarkan tahun ajaran
        $classesQuery->when($tahunAjaranId, function($query) use ($tahunAjaranId) {
            return $query->where('tahun_ajaran_id', $tahunAjaranId);
        });
        
        // Jika guru ini adalah wali kelas, tambahkan kelas walinya ke dalam daftar
        if ($guru->isWaliKelas()) {
            $kelasWali = $guru->kelasWali()->first();
            
            // Ambil kelas yang diajar oleh guru (sebagai pengajar) atau kelas wali
            $classesQuery->where(function($query) use ($guruId, $kelasWali) {
                $query->whereHas('guru', function($q) use ($guruId) {
                    $q->where('guru_id', $guruId)
                      ->where('role', 'pengajar');
                });
                
                // Jika punya kelas wali, tambahkan sebagai OR condition
                if ($kelasWali) {
                    $query->orWhere('id', $kelasWali->id);
                }
            });
        } else {
            // Jika bukan wali kelas, hanya ambil kelas yang diajar sebagai pengajar biasa
            $classesQuery->whereHas('guru', function($query) use ($guruId) {
                $query->where('guru_id', $guruId)
                      ->where('role', 'pengajar');
            });
        }
        
        // Ambil hasil query dan urutkan
        $classes = $classesQuery->orderBy('nomor_kelas')
            ->orderBy('nama_kelas')
            ->get();
    
        return view('pengajar.add_subject', compact('classes'));
    }
    
    public function teacherStore(Request $request)
    {
        $guru = auth()->guard('guru')->user();
        
        try {
            // Validasi array subjects
            $request->validate([
                'subjects' => 'required|array',
                'subjects.*.mata_pelajaran' => 'required|string|max:255',
                'subjects.*.kelas' => 'required|exists:kelas,id',
                'subjects.*.semester' => 'required|integer|min:1|max:2',
                'subjects.*.lingkup_materi' => 'required|array',
                'subjects.*.lingkup_materi.*' => 'required|string|max:255',
            ]);
    
            DB::beginTransaction();
            $successCount = 0;
            $errorMessages = [];
    
            foreach ($request->subjects as $index => $subjectData) {
                $kelasId = $subjectData['kelas'];
                $kelas = Kelas::find($kelasId);
                
                if (!$kelas) {
                    $errorMessages[] = "Kelas tidak valid untuk mata pelajaran {$subjectData['mata_pelajaran']}.";
                    continue;
                }
                
                // Validasi sesuai dengan peran guru
                $isWaliKelas = $guru->isWaliKelas();
                $isWaliKelasForThisClass = $guru->getWaliKelasId() == $kelasId;
                
                // Set default values
                $isMuatanLokal = false;
                $allowNonWali = false;
                
                if ($isWaliKelas) {
                    // Guru Wali Kelas
                    if ($isWaliKelasForThisClass) {
                        // Mengajar di kelas yang diwalikan: selalu non-muatan lokal
                        $isMuatanLokal = false;
                        $allowNonWali = false;
                    } else {
                        // Mengajar di kelas yang bukan diwalikan
                        // Harus muatan lokal atau diizinkan oleh guru non-wali
                        $isMuatanLokal = isset($subjectData['is_muatan_lokal']);
                        
                        if (!$isMuatanLokal) {
                            // Jika ini non-muatan lokal di kelas yang bukan diwalikan, 
                            // maka harus ada flag allow_non_wali
                            $allowNonWali = isset($subjectData['allow_non_wali']);
                            
                            if (!$allowNonWali) {
                                $errorMessages[] = "Mata pelajaran {$subjectData['mata_pelajaran']} adalah non-muatan lokal dan hanya bisa diajar oleh wali kelas dari kelas tersebut. Anda perlu mencentang opsi 'Mengajar di kelas selain kelas wali'.";
                                continue;
                            }
                        }
                    }
                } else {
                    // Guru biasa (bukan wali kelas): selalu muatan lokal
                    $isMuatanLokal = true;
                    $allowNonWali = false;
                }
    
                // Cek duplikasi mata pelajaran
                $exists = MataPelajaran::where('kelas_id', $kelasId)
                    ->where('nama_pelajaran', $subjectData['mata_pelajaran'])
                    ->where('semester', $subjectData['semester'])
                    ->exists();
                    
                if ($exists) {
                    $errorMessages[] = "Mata pelajaran {$subjectData['mata_pelajaran']} untuk kelas {$kelas->nomor_kelas} {$kelas->nama_kelas} semester {$subjectData['semester']} sudah ada.";
                    continue;
                }
    
                // Simpan Mata Pelajaran
                $mataPelajaran = MataPelajaran::create([
                    'nama_pelajaran' => $subjectData['mata_pelajaran'],
                    'kelas_id' => $kelasId,
                    'guru_id' => $guru->id,
                    'semester' => $subjectData['semester'],
                    'is_muatan_lokal' => $isMuatanLokal,
                    'allow_non_wali' => $allowNonWali,
                ]);
                
                // Simpan Lingkup Materi
                foreach ($subjectData['lingkup_materi'] as $judulLingkupMateri) {
                    LingkupMateri::create([
                        'mata_pelajaran_id' => $mataPelajaran->id,
                        'judul_lingkup_materi' => $judulLingkupMateri,
                    ]);
                }
    
                $successCount++;
            }
    
            DB::commit();
            
            $message = $successCount > 0 
                ? "Berhasil menambahkan {$successCount} mata pelajaran!" 
                : "Tidak ada mata pelajaran yang ditambahkan.";
                
            if (count($errorMessages) > 0) {
                $message .= " Terdapat " . count($errorMessages) . " kesalahan.";
                \Log::warning('Errors during teacher subject creation:', $errorMessages);
            }
    
            return redirect()->route('pengajar.subject.index')
                ->with('success', $message)
                ->with('errors', $errorMessages);
        } catch (\Exception $e) {
            DB::rollBack();
            \Log::error('Error in teacher subject store method:', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return redirect()->back()
                ->with('error', 'Terjadi kesalahan saat menyimpan data: ' . $e->getMessage())
                ->withInput();
        }
    }

    public function teacherEdit($id)
    {
        // Ambil data mata pelajaran yang akan diedit
        $subject = MataPelajaran::with('lingkupMateris')->findOrFail($id);
        
        // Ambil ID guru yang sedang login
        $guruId = Auth::guard('guru')->id();
        $guru = Auth::guard('guru')->user();
        
        // Gunakan tahun ajaran dari mata pelajaran
        $tahunAjaranId = $subject->tahun_ajaran_id;
        
        // Default value - set terlebih dahulu
        $disableKelasDropdown = false;
        
        // Verifikasi guru adalah pemilik mata pelajaran
        if ($subject->guru_id != $guruId) {
            abort(403, 'Anda tidak memiliki akses untuk mengedit mata pelajaran ini.');
        }
        
        // Query untuk mendapatkan kelas
        $classesQuery = Kelas::query();
        
        // Filter kelas berdasarkan tahun ajaran mata pelajaran
        $classesQuery->where('tahun_ajaran_id', $tahunAjaranId);
        
        // Jika guru ini adalah wali kelas
        if ($guru->isWaliKelas()) {
            $kelasWaliId = $guru->getWaliKelasId();
            
            // Jika mata pelajaran ini di kelas wali, kita akan menonaktifkan dropdown
            $isWaliKelasMatajar = ($subject->kelas_id == $kelasWaliId);
            
            // Beri tahu view bahwa ini mata pelajaran di kelas wali
            $disableKelasDropdown = $isWaliKelasMatajar;
            
            // Ambil kelas yang diajar oleh guru (sebagai pengajar) atau kelas wali
            $classesQuery->whereHas('guru', function($query) use ($guruId) {
                $query->where('guru_id', $guruId);
            });
        } else {
            // Jika bukan wali kelas, hanya ambil kelas yang diajar sebagai pengajar biasa
            $classesQuery->whereHas('guru', function($query) use ($guruId) {
                $query->where('guru_id', $guruId);
            });
        }
        
        // Ambil hasil query dan urutkan
        $classes = $classesQuery->orderBy('nomor_kelas')
            ->orderBy('nama_kelas')
            ->get();
        
        return view('pengajar.edit_subject', compact('subject', 'classes', 'disableKelasDropdown'));
    }

    public function teacherUpdate(Request $request, $id)
    {
        $guru = auth()->guard('guru')->user();
        $subject = MataPelajaran::where('guru_id', $guru->id)
            ->findOrFail($id);
    
        $validated = $request->validate([
            'mata_pelajaran' => 'required|string|max:255',
            'kelas' => 'required|exists:kelas,id',
            'semester' => 'required|integer|min:1|max:2',
            'lingkup_materi' => 'required|array',
            'lingkup_materi.*' => 'required|string|max:255',
        ]);
    
        $kelasId = $validated['kelas'];
        $isWaliKelas = $guru->isWaliKelas();
        $isWaliKelasForThisClass = $guru->getWaliKelasId() == $kelasId;
        
        // Set default values
        $isMuatanLokal = false;
        $allowNonWali = false;
        
        if ($isWaliKelas) {
            // Guru Wali Kelas
            if ($isWaliKelasForThisClass) {
                // Mengajar di kelas yang diwalikan: selalu non-muatan lokal
                $isMuatanLokal = false;
                $allowNonWali = false;
            } else {
                // Mengajar di kelas yang bukan diwalikan
                // Harus muatan lokal atau diizinkan oleh guru non-wali
                $isMuatanLokal = $request->has('is_muatan_lokal');
                
                if (!$isMuatanLokal) {
                    // Jika ini non-muatan lokal di kelas yang bukan diwalikan, 
                    // maka harus ada flag allow_non_wali
                    $allowNonWali = $request->has('allow_non_wali');
                    
                    if (!$allowNonWali) {
                        return back()->withErrors([
                            'kelas' => 'Mata pelajaran non-muatan lokal hanya bisa diajar oleh wali kelas dari kelas tersebut. Centang opsi "Mengajar di kelas selain kelas wali" untuk melanjutkan.'
                        ])->withInput();
                    }
                }
            }
        } else {
            // Guru biasa (bukan wali kelas): selalu muatan lokal
            $isMuatanLokal = true;
            $allowNonWali = false;
        }
    
        // Check for duplicates excluding current record
        $exists = MataPelajaran::where('kelas_id', $validated['kelas'])
            ->where('nama_pelajaran', $validated['mata_pelajaran'])
            ->where('semester', $validated['semester'])
            ->where('id', '!=', $id) // This is critical - exclude the current record
            ->exists();
            
        if ($exists) {
            return back()->withErrors([
                'mata_pelajaran' => 'Mata pelajaran dengan nama yang sama sudah ada di kelas ini untuk semester yang sama.'
            ])->withInput();
        }
    
        // Verify teacher can teach in selected class
        if (!$guru->canTeachClass($kelasId)) {
            return back()->withErrors([
                'kelas' => 'Anda tidak memiliki akses untuk mengajar di kelas ini.'
            ])->withInput();
        }
    
        try {
            DB::beginTransaction();
    
            // Update the subject with the server-determined is_muatan_lokal value
            $subject->update([
                'nama_pelajaran' => $validated['mata_pelajaran'],
                'kelas_id' => $validated['kelas'],
                'semester' => $validated['semester'],
                'is_muatan_lokal' => $isMuatanLokal,
                'allow_non_wali' => $allowNonWali,
            ]);
            
            // Handle lingkup materi updates
            $existingLingkupMateris = $subject->lingkupMateris()->get();
            $existingTitles = $existingLingkupMateris->pluck('judul_lingkup_materi')->toArray();
            $newTitles = $validated['lingkup_materi'];
            
            // Process existing entries
            foreach ($existingLingkupMateris as $existingLM) {
                $newTitleIndex = array_search($existingLM->judul_lingkup_materi, $newTitles);
                
                if ($newTitleIndex !== false) {
                    // Keep and remove from new titles list
                    unset($newTitles[$newTitleIndex]);
                } else {
                    // Delete if not in new list
                    $existingLM->delete();
                }
            }
            
            // Add new entries
            foreach ($newTitles as $newTitle) {
                LingkupMateri::create([
                    'mata_pelajaran_id' => $subject->id,
                    'judul_lingkup_materi' => $newTitle,
                ]);
            }
    
            DB::commit();
            return redirect()->route('pengajar.subject.index')
                ->with('success', 'Mata Pelajaran berhasil diperbarui!');
        } catch (\Exception $e) {
            DB::rollback();
            return back()->with('error', 'Terjadi kesalahan: ' . $e->getMessage())->withInput();
        }
    }

    public function teacherDestroy($id)
    {
        $guru = auth()->guard('guru')->user();
        $subject = MataPelajaran::where('guru_id', $guru->id)
            ->findOrFail($id);

        try {
            $subject->delete();
            return redirect()->route('pengajar.subject.index')
                ->with('success', 'Mata Pelajaran berhasil dihapus!');
        } catch (\Exception $e) {
            return redirect()->back()
                ->with('error', 'Terjadi kesalahan saat menghapus data: ' . $e->getMessage());
        }
    }
}

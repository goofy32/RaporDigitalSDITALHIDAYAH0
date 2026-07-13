<?php

namespace App\Http\Controllers;

use App\Traits\RequiresTahunAjaran;
use Illuminate\Http\Request;
use App\Models\Siswa;
use App\Models\Kelas;
use App\Models\TahunAjaran;
use App\Services\StudentExcelImportService;
use App\Services\StudentImportTemplateService;
use App\Services\SiswaKelasSemesterResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class StudentController extends Controller
{
    use RequiresTahunAjaran;

    public function index(Request $request)
    {
        // Ambil tahun ajaran dari session
        $tahunAjaranId = session('tahun_ajaran_id');
        $activeTahunAjaran = $tahunAjaranId ? TahunAjaran::find($tahunAjaranId) : null;

        if ($activeTahunAjaran) {
            $students = $this->enrollmentAwareAdminStudentQuery($request, $activeTahunAjaran)
                ->paginate(10);

            $this->attachAdminStudentContextClasses($students->getCollection());

            return view('admin.student', compact('students', 'activeTahunAjaran'));
        }
        
        // Buat query dasar dengan join ke tabel kelas untuk sorting
        $query = Siswa::join('kelas', 'siswas.kelas_id', '=', 'kelas.id')
            ->select('siswas.*'); // Make sure to select only from siswas table
        
        // Filter berdasarkan tahun ajaran jika ada
        if ($tahunAjaranId) {
            $query->where('kelas.tahun_ajaran_id', $tahunAjaranId);
        }
        
        // Handle pencarian
        if ($request->has('search')) {
            $search = strtolower($request->search);
            $terms = explode(' ', trim($search));
            
            $query->where(function($q) use ($terms, $search) {
                // Jika kata pertama adalah "kelas"
                if (count($terms) > 0 && $terms[0] === 'kelas') {
                    // Jika ada nomor kelas yang dispecifikkan (kelas 1, kelas 2, dst)
                    if (count($terms) > 1 && is_numeric($terms[1])) {
                        $q->where('kelas.nomor_kelas', $terms[1]);
                    }
                    // Else clause tidak perlu karena kita selalu order by nomor_kelas & nama_kelas
                } else {
                    // Pencarian normal untuk term lainnya menggunakan $search
                    $q->where(function($subQ) use ($search) {
                        $subQ->where('siswas.nama', 'LIKE', "%{$search}%")
                            ->orWhere('siswas.nis', 'LIKE', "%{$search}%")
                            ->orWhere('siswas.nisn', 'LIKE', "%{$search}%")
                            ->orWhere('kelas.nama_kelas', 'LIKE', "%{$search}%")
                            ->orWhere('kelas.nomor_kelas', 'LIKE', "%{$search}%");
                    });
                }
            });
        }
        
        // Always apply this sorting regardless of search
        $query->orderBy('kelas.nomor_kelas', 'asc')
            ->orderBy('kelas.nama_kelas', 'asc')
            ->orderBy('siswas.nama', 'asc');
        
        $students = $query->paginate(10);
        
        // Eager load the kelas relationship for the paginated results
        $students->load('kelas');
        
        return view('admin.student', compact('students', 'activeTahunAjaran'));
    }

    private function enrollmentAwareAdminStudentQuery(Request $request, TahunAjaran $tahunAjaran)
    {
        $tahunAjaranId = (int) $tahunAjaran->id;
        $semester = (int) $tahunAjaran->semester;

        $query = Siswa::query()
            ->leftJoin('siswa_kelas_semester as enrollment_context', function ($join) use ($tahunAjaranId, $semester) {
                $join->on('enrollment_context.siswa_id', '=', 'siswas.id')
                    ->where('enrollment_context.tahun_ajaran_id', '=', $tahunAjaranId)
                    ->where('enrollment_context.semester', '=', $semester);
            })
            ->leftJoin('kelas as enrollment_kelas', 'enrollment_context.kelas_id', '=', 'enrollment_kelas.id')
            ->leftJoin('kelas as legacy_kelas', 'siswas.kelas_id', '=', 'legacy_kelas.id')
            ->select('siswas.*')
            ->addSelect(DB::raw('COALESCE(enrollment_context.kelas_id, legacy_kelas.id) as context_kelas_id'))
            ->where(function ($query) use ($tahunAjaranId) {
                $query->whereNotNull('enrollment_context.id')
                    ->orWhere(function ($query) use ($tahunAjaranId) {
                        $query->whereDoesntHave('semesterEnrollments')
                            ->where('legacy_kelas.tahun_ajaran_id', $tahunAjaranId);
                    });
            });

        if ($request->has('search')) {
            $search = strtolower($request->search);
            $terms = explode(' ', trim($search));

            $query->where(function ($q) use ($terms, $search) {
                if (count($terms) > 0 && $terms[0] === 'kelas') {
                    if (count($terms) > 1 && is_numeric($terms[1])) {
                        $q->where('enrollment_kelas.nomor_kelas', $terms[1])
                            ->orWhere('legacy_kelas.nomor_kelas', $terms[1]);
                    }
                } else {
                    $q->where(function ($subQ) use ($search) {
                        $subQ->where('siswas.nama', 'LIKE', "%{$search}%")
                            ->orWhere('siswas.nis', 'LIKE', "%{$search}%")
                            ->orWhere('siswas.nisn', 'LIKE', "%{$search}%")
                            ->orWhere('enrollment_kelas.nama_kelas', 'LIKE', "%{$search}%")
                            ->orWhere('enrollment_kelas.nomor_kelas', 'LIKE', "%{$search}%")
                            ->orWhere('legacy_kelas.nama_kelas', 'LIKE', "%{$search}%")
                            ->orWhere('legacy_kelas.nomor_kelas', 'LIKE', "%{$search}%");
                    });
                }
            });
        }

        return $query
            ->orderByRaw('COALESCE(enrollment_kelas.nomor_kelas, legacy_kelas.nomor_kelas) asc')
            ->orderByRaw('COALESCE(enrollment_kelas.nama_kelas, legacy_kelas.nama_kelas) asc')
            ->orderBy('siswas.nama', 'asc');
    }

    private function attachAdminStudentContextClasses($students): void
    {
        $classIds = $students->pluck('context_kelas_id')
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();

        if ($classIds->isEmpty()) {
            return;
        }

        $classes = Kelas::with('tahunAjaran')
            ->whereIn('id', $classIds)
            ->get()
            ->keyBy('id');

        $students->each(function (Siswa $student) use ($classes) {
            $contextClassId = (int) ($student->context_kelas_id ?? 0);

            if ($contextClassId && $classes->has($contextClassId)) {
                $contextClass = $classes->get($contextClassId);
                $student->setRelation('kelas', $contextClass);
                $student->setAttribute('admin_kelas_label', $contextClass->full_kelas);
            }
        });
    }
    public function create()
    {
        $tahunAjaranId = session('tahun_ajaran_id');
        
        $kelas = Kelas::when($tahunAjaranId, function($query) use ($tahunAjaranId) {
                return $query->where('tahun_ajaran_id', $tahunAjaranId);
            })
            ->orderBy('nomor_kelas')
            ->orderBy('nama_kelas')
            ->get();
            
        return view('data.add_student', compact('kelas'));
    }

    public function store(Request $request)
    {
        $tahunAjaranId = $this->getValidTahunAjaranId();

        if (!$tahunAjaranId || !$this->isActiveTahunAjaran($tahunAjaranId)) {
            return $this->failTahunAjaranNotSet($request);
        }

        $validated = $request->validate([
            'nis' => [
                'required',
                'numeric',          // Memastikan hanya angka
                'digits_between:5,10', // Minimal 5 digit, maksimal 10 digit
                'unique:siswas,nis'
            ],
            'nisn' => [
                'required',
                'numeric',         // Memastikan hanya angka
                'digits:10',       // Harus 10 digit
                'unique:siswas,nisn'
            ],
            'nama' => [
                'required',
                'string',
                'max:255',
                'regex:/^[a-zA-Z\s]*$/'  // Hanya huruf dan spasi
            ],
            'tanggal_lahir' => 'required|date|before:today', // Pastikan tanggal lahir sebelum hari ini
            'jenis_kelamin' => 'required|in:Laki-laki,Perempuan',
            'agama' => 'required|string|in:Islam,Kristen,Katolik,Hindu,Buddha,Konghucu',
            'alamat' => 'required|string|max:500',
            'kelas_id' => [
                'required',
                Rule::exists('kelas', 'id')->where(fn ($query) => $query->where('tahun_ajaran_id', $tahunAjaranId)),
            ],
            'photo' => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
            'nama_ayah' => 'required|string|max:255',
            'nama_ibu' => 'required|string|max:255',
            'pekerjaan_ayah' => 'nullable|string|max:100',
            'pekerjaan_ibu' => 'nullable|string|max:100',
            'alamat_orangtua' => 'nullable|string|max:500',
            'wali_siswa' => 'nullable|string|max:255',
            'pekerjaan_wali' => 'nullable|string|max:100',
        ], [
            'nis.unique' => 'NIS sudah digunakan.',
            'nisn.unique' => 'NISN sudah digunakan.',
            'tanggal_lahir.before' => 'Tanggal lahir harus sebelum hari ini.',
        ]);
    
        // Set default empty string untuk field nullable
        $validated['alamat_orangtua'] = $validated['alamat_orangtua'] ?? '';
        $validated['pekerjaan_ayah'] = $validated['pekerjaan_ayah'] ?? '';
        $validated['pekerjaan_ibu'] = $validated['pekerjaan_ibu'] ?? '';
        $validated['wali_siswa'] = $validated['wali_siswa'] ?? '';
        $validated['pekerjaan_wali'] = $validated['pekerjaan_wali'] ?? '';
    
        if ($request->hasFile('photo')) {
            $validated['photo'] = $this->storePublicUpload($request->file('photo'), 'photos');
        }

        $validated['tahun_ajaran_id'] = $tahunAjaranId;
    
        try {
            Siswa::create($validated);
            return redirect()->route('student')->with('success', 'Data siswa berhasil ditambahkan!');
        } catch (\Exception $e) {
            Log::error('Failed to create student', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'user_id' => auth()->id(),
            ]);

            return back()->with('error', 'Terjadi kesalahan. Silakan coba lagi.')
                ->withInput();
        }
    }

    public function show($id)
    {
        $student = Siswa::with('kelas')->findOrFail($id);
        return view('data.siswa_data', compact('student'));
    }
    
    public function edit($id)
    {
        $tahunAjaranId = session('tahun_ajaran_id');
        $student = Siswa::findOrFail($id);
        
        $kelas = Kelas::when($tahunAjaranId, function($query) use ($tahunAjaranId) {
                return $query->where('tahun_ajaran_id', $tahunAjaranId);
            })
            ->orderBy('nomor_kelas')
            ->orderBy('nama_kelas')
            ->get();
            
        return view('data.edit_student', compact('student', 'kelas'));
    }

    public function update(Request $request, $id)
    {
        $tahunAjaranId = $this->getValidTahunAjaranId();

        if (!$tahunAjaranId || !$this->isActiveTahunAjaran($tahunAjaranId)) {
            return $this->failTahunAjaranNotSet($request);
        }

        $student = Siswa::findOrFail($id);
        $validated = $request->validate([
            'nis' => 'required|string|max:20|unique:siswas,nis,'.$id,
            'nisn' => 'required|string|max:20|unique:siswas,nisn,'.$id,
            'nama' => 'required|string|max:255',
            'tanggal_lahir' => 'required|date|before:today',
            'jenis_kelamin' => 'required|in:Laki-laki,Perempuan',
            'agama' => 'required|string|in:Islam,Kristen,Katolik,Hindu,Buddha,Konghucu',
            'alamat' => 'required|string|max:500',
            'kelas_id' => [
                'required',
                Rule::exists('kelas', 'id')->where(fn ($query) => $query->where('tahun_ajaran_id', $tahunAjaranId)),
            ],
            'photo' => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
            'nama_ayah' => 'required|string|max:255',
            'nama_ibu' => 'required|string|max:255',
            'pekerjaan_ayah' => 'nullable|string|max:100',
            'pekerjaan_ibu' => 'nullable|string|max:100',
            'alamat_orangtua' => 'nullable|string|max:500',
            'wali_siswa' => 'nullable|string|max:255',
            'pekerjaan_wali' => 'nullable|string|max:100',
        ], [
            'nis.unique' => 'NIS sudah digunakan.',
            'nisn.unique' => 'NISN sudah digunakan.',
            'tanggal_lahir.before' => 'Tanggal lahir harus sebelum hari ini.',
        ]);
    
        if ($request->hasFile('photo')) {
            // Hapus foto lama jika ada
            if ($student->photo) {
                Storage::delete($student->photo);
            }
            $validated['photo'] = $this->storePublicUpload($request->file('photo'), 'photos');
        }

        $validated['tahun_ajaran_id'] = $tahunAjaranId;
    
        $student->update($validated);
        return redirect()->route('student')->with('success', 'Data siswa berhasil diperbarui!');
    }

    public function destroy($id)
    {
        $student = Siswa::findOrFail($id);

        $student->delete();
        return redirect()->route('student')->with('success', 'Data siswa berhasil dihapus!');
    }

    public function waliKelasIndex(Request $request, SiswaKelasSemesterResolver $enrollmentResolver)
    {
        $guru = auth()->guard('guru')->user();
        $tahunAjaranId = session('tahun_ajaran_id');
        
        \Log::info("Wali Kelas Index", [
            'guru_id' => $guru->id,
            'tahun_ajaran_id' => $tahunAjaranId
        ]);
        
        if (!$guru) {
            return redirect()->route('login')->with('error', 'Silakan login terlebih dahulu');
        }

        $tahunAjaran = $tahunAjaranId ? TahunAjaran::find($tahunAjaranId) : null;

        if (!$tahunAjaran) {
            return redirect()->route('wali_kelas.dashboard')
                ->with('error', 'Data tahun ajaran tidak ditemukan.');
        }
        
        // Ambil kelas wali untuk guru ini
        $kelasWali = DB::table('guru_kelas')
            ->join('kelas', 'guru_kelas.kelas_id', '=', 'kelas.id')
            ->where('guru_kelas.guru_id', $guru->id)
            ->where('guru_kelas.is_wali_kelas', true)
            ->where('guru_kelas.role', 'wali_kelas')
            ->where('kelas.tahun_ajaran_id', $tahunAjaranId)
            ->select('kelas.id as kelas_id', 'kelas.nomor_kelas', 'kelas.nama_kelas')
            ->first();
        
        \Log::info("Kelas wali result:", ['kelas_wali' => $kelasWali]);
        
        if (!$kelasWali) {
            // Log all guru-kelas relations for this guru
            $relations = DB::table('guru_kelas')
                ->join('kelas', 'guru_kelas.kelas_id', '=', 'kelas.id')
                ->where('guru_kelas.guru_id', $guru->id)
                ->select('guru_kelas.*', 'kelas.tahun_ajaran_id', 'kelas.nomor_kelas', 'kelas.nama_kelas')
                ->get();
                
            \Log::info("All guru-kelas relations:", ['relations' => $relations]);
            
            return redirect()->route('wali_kelas.dashboard')
                ->with('error', 'Anda belum ditugaskan sebagai wali kelas untuk tahun ajaran yang dipilih.');
        }
        
        $query = $enrollmentResolver
            ->studentQueryForClass((int) $kelasWali->kelas_id, (int) $tahunAjaranId, (int) $tahunAjaran->semester, true);
        
        \Log::info("Query students for kelas_id: " . $kelasWali->kelas_id);
        
        if ($request->has('search')) {
            $search = $request->search;
            $query->where(function($q) use ($search) {
                $q->where('nama', 'LIKE', "%{$search}%")
                ->orWhere('nis', 'LIKE', "%{$search}%")
                ->orWhere('nisn', 'LIKE', "%{$search}%");
            });
        }
        
        $students = $query->orderBy('nama')->paginate(10);

        $kelasModel = Kelas::find($kelasWali->kelas_id);
        $students->getCollection()->each(function (Siswa $student) use ($kelasModel) {
            if ($kelasModel) {
                $student->setRelation('kelas', $kelasModel);
            }
        });
        
        \Log::info("Students found:", ['count' => $students->count()]);
        
        return view('wali_kelas.student', compact('students'));
    }

    public function waliKelasShow($id)
    {
        $waliKelas = auth()->guard('guru')->user();
        $kelasWaliId = $waliKelas->getWaliKelasId();
        
        // Ensure we're only showing students from the wali's class
        $student = Siswa::with('kelas')
            ->where('kelas_id', $kelasWaliId)
            ->findOrFail($id);
            
        return view('wali_kelas.detail_student', compact('student'));
    }

    public function waliKelasCreate()
    {
        $waliKelas = auth()->guard('guru')->user();
        
        // Cek wali kelas melalui relasi guru_kelas
        $kelas = $waliKelas->kelasWali()->first();
        
        if (!$kelas) {
            return redirect()->route('wali_kelas.student.index')
                ->with('error', 'Anda belum ditugaskan sebagai wali kelas.');
        }
        
        return view('wali_kelas.add_student', compact('kelas'));
    }
    

    public function waliKelasStore(Request $request)
    {
        try {
            // Get wali kelas
            $waliKelas = auth()->guard('guru')->user();
            
            // Cek wali kelas melalui relasi guru_kelas
            $kelas = $waliKelas->kelasWali()->first();
            
            if (!$kelas) {
                return redirect()->route('wali_kelas.student.index')
                    ->with('error', 'Anda belum ditugaskan sebagai wali kelas.');
            }
            
            // Get tahun ajaran from session or from kelas
            $tahunAjaranId = session('tahun_ajaran_id') ?? $kelas->tahun_ajaran_id;
            
            if (!$tahunAjaranId) {
                return redirect()->route('wali_kelas.student.index')
                    ->with('error', 'Tahun ajaran belum dipilih. Silakan pilih tahun ajaran terlebih dahulu.');
            }
            
            // Validate the request
            $validated = $request->validate([
                'nis' => 'required|unique:siswas',
                'nisn' => 'required|unique:siswas',
                'nama' => 'required',
                'tanggal_lahir' => 'required|date|before:today',
                'jenis_kelamin' => 'required',
                'agama' => 'required',
                'alamat' => 'required',
                'photo' => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
                'nama_ayah' => 'required|string',
                'nama_ibu' => 'required|string',
                'pekerjaan_ayah' => 'nullable|string',
                'pekerjaan_ibu' => 'nullable|string',
                'alamat_orangtua' => 'nullable|string',
                'wali_siswa' => 'nullable|string',
                'pekerjaan_wali' => 'nullable|string',
            ], [
                'nis.required' => 'NIS wajib diisi.',
                'nis.unique' => 'NIS sudah digunakan oleh siswa lain.',
                'nisn.required' => 'NISN wajib diisi.',
                'nisn.unique' => 'NISN sudah digunakan oleh siswa lain.',
                'nama.required' => 'Nama siswa wajib diisi.',
                'tanggal_lahir.required' => 'Tanggal lahir wajib diisi.',
                'tanggal_lahir.before' => 'Tanggal lahir harus sebelum hari ini.',
                'jenis_kelamin.required' => 'Jenis kelamin wajib dipilih.',
                'agama.required' => 'Agama wajib dipilih.',
                'alamat.required' => 'Alamat wajib diisi.',
                'nama_ayah.required' => 'Nama ayah wajib diisi.',
                'nama_ibu.required' => 'Nama ibu wajib diisi.',
            ]);
    
            // Set kelas_id from the selected class
            $validated['kelas_id'] = $kelas->id;
            
            // Explicitly set the tahun_ajaran_id
            $validated['tahun_ajaran_id'] = $tahunAjaranId;
            
            // Handle photo upload
            if ($request->hasFile('photo')) {
                $validated['photo'] = $this->storePublicUpload($request->file('photo'), 'photos');
            }
    
            // Use database transaction
            DB::beginTransaction();
            
            // Create student
            $siswa = Siswa::create($validated);
            
            DB::commit();
            
            return redirect()->route('wali_kelas.student.index')
                ->with('success', 'Data siswa berhasil ditambahkan!');
        } catch (\Illuminate\Validation\ValidationException $e) {
            // Simpan sebagai plain text agar aman ditampilkan via SweetAlert text:
            $errorMessages = collect($e->errors())->flatten()->implode("\n");
            
            // Kembali dengan validation_errors dalam session
            \Log::info('Validation error: ' . $errorMessages);
            return back()->with('swal_validation_error', $errorMessages)->withInput();
        } catch (\Exception $e) {
            // Rollback transaction in case of error
            if (isset($e) && DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            
            Log::error('Failed to create wali kelas student', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'user_id' => auth()->guard('guru')->id(),
            ]);
            
            return back()->with('error', 'Terjadi kesalahan. Silakan coba lagi.')
                ->withInput();
        }
    }

    public function waliKelasEdit($id)
    {
        $waliKelas = auth()->guard('guru')->user();
        $kelasWaliId = $waliKelas->getWaliKelasId(); // Menggunakan method getWaliKelasId() bukan kelas_pengajar_id
        
        $student = Siswa::where('kelas_id', $kelasWaliId)
            ->findOrFail($id);
        $kelas = Kelas::where('id', $kelasWaliId)->first();
    
        return view('wali_kelas.edit_student', compact('student', 'kelas'));
    }

    public function waliKelasUpdate(Request $request, $id)
    {
        $tahunAjaranId = $this->getValidTahunAjaranId();

        if (!$tahunAjaranId) {
            return $this->failTahunAjaranNotSet($request);
        }

        $waliKelas = auth()->guard('guru')->user();
        $kelasWaliId = $waliKelas->getWaliKelasId(); // Menggunakan method getWaliKelasId() bukan kelas_pengajar_id
        
        $student = Siswa::where('kelas_id', $kelasWaliId)
            ->findOrFail($id);

        $validated = $request->validate([
            'nis' => 'required|unique:siswas,nis,' . $id,
            'nisn' => 'required|unique:siswas,nisn,' . $id,
            'nama' => 'required',
            'tanggal_lahir' => 'required|date|before:today',
            'jenis_kelamin' => 'required',
            'agama' => 'required',
            'alamat' => 'required',
            'photo' => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
            'nama_ayah' => 'nullable',
            'nama_ibu' => 'nullable',
            'pekerjaan_ayah' => 'nullable',
            'pekerjaan_ibu' => 'nullable',
            'alamat_orangtua' => 'nullable',
        ], [
            'tanggal_lahir.before' => 'Tanggal lahir harus sebelum hari ini.',
        ]);

        if ($request->hasFile('photo')) {
            if ($student->photo) {
                Storage::delete('public/' . $student->photo);
            }
            $validated['photo'] = $this->storePublicUpload($request->file('photo'), 'photos');
        }

        $validated['tahun_ajaran_id'] = $tahunAjaranId;

        $student->update($validated);
        return redirect()->route('wali_kelas.student.index')
            ->with('success', 'Data siswa berhasil diperbarui!');
    }

    public function waliKelasDestroy($id)
    {
        $waliKelas = auth()->guard('guru')->user();
        $kelasWaliId = $waliKelas->getWaliKelasId(); // Menggunakan method getWaliKelasId() bukan kelas_pengajar_id
        
        $student = Siswa::where('kelas_id', $kelasWaliId)
            ->findOrFail($id);

        $student->delete();
        return redirect()->route('wali_kelas.student.index')
            ->with('success', 'Data siswa berhasil dihapus!');
    }
    
    public function uploadPage()
    {
        return view('data.upload_student');
    }

    public function importExcel(Request $request, StudentExcelImportService $studentImportService)
    {
        $request->validate([
            'file' => 'required|mimes:xlsx,xls|max:2048',
        ], [
            'file.required' => 'File Excel siswa wajib dipilih.',
            'file.mimes' => 'File harus berformat Excel (.xlsx atau .xls).',
            'file.max' => 'Ukuran file Excel maksimal 2 MB.',
        ]);

        $activeTahunAjaran = TahunAjaran::where('is_active', true)->first();
        if (! $activeTahunAjaran) {
            return back()->with('error', 'Tidak ada tahun ajaran aktif. Buat tahun ajaran aktif terlebih dahulu.');
        }

        try {
            $result = $studentImportService->import($request->file('file'), $activeTahunAjaran);

            if (! $result['success']) {
                return back()
                    ->with('error', 'Import siswa dibatalkan. Periksa daftar kesalahan pada file Excel.')
                    ->with('import_errors', $result['errors']);
            }

            return redirect()->route('student')
                ->with('success', "Data siswa berhasil diimpor ({$result['imported_count']} siswa).");

        } catch (\Exception $e) {
            Log::error('Student import failed', [
                'error' => $e->getMessage(),
                'user_id' => auth()->id(),
            ]);

            return back()->with('error', 'File Excel tidak dapat dibaca. Pastikan menggunakan template dari aplikasi ini.');
        }
    }
    
    public function downloadTemplate(StudentImportTemplateService $templateService)
    {
        try {
            $activeTahunAjaran = TahunAjaran::where('is_active', true)->first();
            if (! $activeTahunAjaran) {
                return back()->with('error', 'Tidak ada tahun ajaran aktif. Buat tahun ajaran aktif terlebih dahulu.');
            }

            $spreadsheet = $templateService->createWorkbook($activeTahunAjaran);
            $filename = 'Template_Import_Siswa_'.$activeTahunAjaran->tahun_ajaran.'_Semester_'.$activeTahunAjaran->semester.'.xlsx';
            $filename = str_replace(['/', '\\'], '-', $filename);

            return response()->streamDownload(function () use ($spreadsheet) {
                (new Xlsx($spreadsheet))->save('php://output');
                $spreadsheet->disconnectWorksheets();
            }, $filename, [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to download student import template', [
                'error' => $e->getMessage(),
                'user_id' => auth()->id(),
            ]);

            return back()->with('error', 'Terjadi kesalahan saat mengunduh template.');
        }
    }

    private function storePublicUpload(\Illuminate\Http\UploadedFile $file, string $folder, ?string $fileName = null): string
    {
        Storage::disk('public')->makeDirectory($folder);

        $extension = $file->getClientOriginalExtension() ?: $file->extension() ?: 'bin';
        $baseName = $fileName
            ? pathinfo($fileName, PATHINFO_FILENAME)
            : pathinfo($file->hashName(), PATHINFO_FILENAME);
        $safeBaseName = Str::slug($baseName ?: pathinfo($file->hashName(), PATHINFO_FILENAME));
        $finalFileName = $safeBaseName . '.' . $extension;

        $destinationDirectory = Storage::disk('public')->path($folder);
        $file->move($destinationDirectory, $finalFileName);

        $filePath = $folder . '/' . $finalFileName;

        if (!Storage::disk('public')->exists($filePath)) {
            throw new \RuntimeException('File gagal disimpan.');
        }

        return $filePath;
    }

    private function isActiveTahunAjaran(int $tahunAjaranId): bool
    {
        return TahunAjaran::whereKey($tahunAjaranId)
            ->where('is_active', true)
            ->exists();
    }
}

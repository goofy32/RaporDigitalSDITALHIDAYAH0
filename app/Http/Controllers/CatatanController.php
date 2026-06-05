<?php
// app/Http/Controllers/CatatanController.php
namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\CatatanSiswa;
use App\Models\CatatanMataPelajaran;
use App\Models\Siswa;
use App\Models\MataPelajaran;
use App\Models\TahunAjaran;
use App\Services\SiswaKelasSemesterResolver;
use App\Traits\RequiresTahunAjaran;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CatatanController extends Controller
{
    use RequiresTahunAjaran;

    // =================== CATATAN SISWA ===================
    
    /**
     * Show form for adding/editing student notes
     */
    public function showCatatanSiswa(Siswa $siswa)
    {
        $tahunAjaranId = $this->getValidTahunAjaranId();

        if (!$tahunAjaranId) {
            return $this->failTahunAjaranNotSet(request());
        }

        $selectedSemester = $this->getCurrentSemester($tahunAjaranId);
        $kelas = $this->authorizeWaliStudent($siswa, $tahunAjaranId, $selectedSemester);
        $siswa->setRelation('kelas', $kelas);
        
        // Get existing notes for current context
        $catatanList = CatatanSiswa::where('siswa_id', $siswa->id)
            ->where('tahun_ajaran_id', $tahunAjaranId)
            ->where('semester', $selectedSemester)
            ->orderBy('type')
            ->get()
            ->keyBy('type');
        
        return view('wali_kelas.catatan.siswa', compact('siswa', 'catatanList'));
    }
    
    /**
     * Store or update student notes
     */
    public function storeCatatanSiswa(Request $request, Siswa $siswa)
    {
        $tahunAjaranId = $this->getValidTahunAjaranId();

        if (!$tahunAjaranId) {
            return $this->failTahunAjaranNotSet($request);
        }

        $request->validate([
            'catatan_umum' => 'nullable|string|max:1000',
            'catatan_uts' => 'nullable|string|max:1000',
            'catatan_uas' => 'nullable|string|max:1000',
        ]);
        
        $guru = Auth::guard('guru')->user();
        $selectedSemester = $this->getCurrentSemester($tahunAjaranId);
        $this->authorizeWaliStudent($siswa, $tahunAjaranId, $selectedSemester);
        
        DB::beginTransaction();
        
        try {
            $types = ['umum', 'uts', 'uas'];
            
            foreach ($types as $type) {
                $fieldName = "catatan_{$type}";
                $catatanText = $request->input($fieldName);
                
                if (!empty($catatanText)) {
                    CatatanSiswa::updateOrCreate(
                        [
                            'siswa_id' => $siswa->id,
                            'tahun_ajaran_id' => $tahunAjaranId,
                            'semester' => $selectedSemester,
                            'type' => $type,
                        ],
                        [
                            'catatan' => $catatanText,
                            'created_by' => $guru->id,
                        ]
                    );
                } else {
                    // Delete if empty
                    CatatanSiswa::where([
                        'siswa_id' => $siswa->id,
                        'tahun_ajaran_id' => $tahunAjaranId,
                        'semester' => $selectedSemester,
                        'type' => $type,
                    ])->delete();
                }
            }
            
            DB::commit();
            
            return redirect()->back()->with('success', 'Catatan siswa berhasil disimpan.');
            
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('[CatatanController] Store student note failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'user_id' => Auth::guard('guru')->id(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'siswa_id' => $siswa->id,
            ]);
            return redirect()->back()->with('error', 'Terjadi kesalahan. Silakan coba lagi.');
        }
    }
    
    // =================== CATATAN MATA PELAJARAN ===================
    
    /**
     * Show list of subjects for adding notes
     */
public function indexCatatanMataPelajaran()
{
    $tahunAjaranId = $this->getValidTahunAjaranId();

    if (!$tahunAjaranId) {
        return $this->failTahunAjaranNotSet(request());
    }
    
    // FIX: Ambil semester dari tahun ajaran aktif, bukan dari session
    $tahunAjaran = TahunAjaran::find($tahunAjaranId);
    $correctSemester = $tahunAjaran ? $tahunAjaran->semester : 1;
    
    // Update session jika tidak sesuai
    if (session('selected_semester') != $correctSemester) {
        session(['selected_semester' => $correctSemester]);
        \Log::info('Updated selected_semester to match tahun ajaran', [
            'old_semester' => session('selected_semester'),
            'new_semester' => $correctSemester,
            'tahun_ajaran' => $tahunAjaran->tahun_ajaran ?? 'unknown'
        ]);
    }
    
    $selectedSemester = $correctSemester; // Gunakan semester yang benar

    $kelas = $this->authorizeWaliKelasForYear($tahunAjaranId);
    
    if (!$kelas) {
        return redirect()->back()->with('error', 'Anda tidak memiliki kelas yang diwalikan untuk tahun ajaran ini.');
    }
    
    \Log::info('CatatanController Fixed Debug', [
        'guru_id' => Auth::guard('guru')->id(),
        'tahun_ajaran_id' => $tahunAjaranId,
        'correct_semester' => $correctSemester,
        'kelas_id' => $kelas->id,
        'tahun_ajaran_info' => $tahunAjaran ? [
            'tahun_ajaran' => $tahunAjaran->tahun_ajaran,
            'semester' => $tahunAjaran->semester,
            'is_active' => $tahunAjaran->is_active
        ] : null
    ]);
    
    // Query dengan semester yang benar
    $mataPelajarans = MataPelajaran::where('kelas_id', $kelas->id)
        ->where('tahun_ajaran_id', $tahunAjaranId)
        ->where('semester', $selectedSemester)
        ->with(['guru'])
        ->orderBy('nama_pelajaran')
        ->get();
    
    \Log::info('Final MataPelajaran Query Result (Fixed)', [
        'total' => $mataPelajarans->count(),
        'query_conditions' => [
            'kelas_id' => $kelas->id,
            'tahun_ajaran_id' => $tahunAjaranId,
            'semester' => $selectedSemester
        ]
    ]);
    
    return view('wali_kelas.catatan.mata_pelajaran.index', compact('mataPelajarans', 'kelas'));
}
    
    /**
     * Show form for adding notes to a specific subject for all students
     */
    public function showCatatanMataPelajaran(MataPelajaran $mataPelajaran)
    {
        $tahunAjaranId = $this->getValidTahunAjaranId();

        if (!$tahunAjaranId) {
            return $this->failTahunAjaranNotSet(request());
        }

        $selectedSemester = $this->getCurrentSemester($tahunAjaranId);
        $kelas = $this->authorizeWaliSubject($mataPelajaran, $tahunAjaranId, $selectedSemester);
        
        // Get all students in the class
        $siswaList = $this->studentsForWaliClass((int) $kelas->id, $tahunAjaranId, $selectedSemester);
        
        // Get existing notes for this subject and all students
        $existingCatatan = CatatanMataPelajaran::where('mata_pelajaran_id', $mataPelajaran->id)
            ->where('tahun_ajaran_id', $tahunAjaranId)
            ->where('semester', $selectedSemester)
            ->get()
            ->groupBy(['siswa_id', 'type']);
        
        return view('wali_kelas.catatan.mata_pelajaran.form', compact(
            'mataPelajaran', 
            'siswaList', 
            'existingCatatan'
        ));
    }
    
    /**
     * Store subject notes for all students
     */
    public function storeCatatanMataPelajaran(Request $request, MataPelajaran $mataPelajaran)
    {
        $tahunAjaranId = $this->getValidTahunAjaranId();

        if (!$tahunAjaranId) {
            return $this->failTahunAjaranNotSet($request);
        }

        $guru = Auth::guard('guru')->user();
        $selectedSemester = $this->getCurrentSemester($tahunAjaranId);
        $kelas = $this->authorizeWaliSubject($mataPelajaran, $tahunAjaranId, $selectedSemester);
        
        $request->validate([
            'catatan' => 'required|array',
            'catatan.*.umum' => 'nullable|string|max:1000',
            'catatan.*.uts' => 'nullable|string|max:1000',
            'catatan.*.uas' => 'nullable|string|max:1000',
        ]);

        $catatanData = $request->input('catatan', []);
        $this->assertAllStudentsBelongToWaliClass(
            array_keys($catatanData),
            (int) $kelas->id,
            $tahunAjaranId,
            $selectedSemester
        );
        
        DB::beginTransaction();
        
        try {
            foreach ($catatanData as $siswaId => $catatan) {
                $siswaId = (int) $siswaId;
                $types = ['umum', 'uts', 'uas'];
                
                foreach ($types as $type) {
                    $catatanText = $catatan[$type] ?? '';
                    
                    if (!empty($catatanText)) {
                        CatatanMataPelajaran::updateOrCreate(
                            [
                                'mata_pelajaran_id' => $mataPelajaran->id,
                                'siswa_id' => $siswaId,
                                'tahun_ajaran_id' => $tahunAjaranId,
                                'semester' => $selectedSemester,
                                'type' => $type,
                            ],
                            [
                                'catatan' => $catatanText,
                                'created_by' => $guru->id,
                            ]
                        );
                    } else {
                        // Delete if empty
                        CatatanMataPelajaran::where([
                            'mata_pelajaran_id' => $mataPelajaran->id,
                            'siswa_id' => $siswaId,
                            'tahun_ajaran_id' => $tahunAjaranId,
                            'semester' => $selectedSemester,
                            'type' => $type,
                        ])->delete();
                    }
                }
            }
            
            DB::commit();
            
            return redirect()->route('wali_kelas.catatan.mata_pelajaran.index')
                ->with('success', 'Catatan mata pelajaran berhasil disimpan.');
            
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('[CatatanController] Store subject note failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'user_id' => Auth::guard('guru')->id(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'mata_pelajaran_id' => $mataPelajaran->id,
            ]);
            return redirect()->back()->with('error', 'Terjadi kesalahan. Silakan coba lagi.');
        }
    }
    
    /**
     * Get notes for a specific student and subject (for AJAX)
     */
    public function getCatatanForSiswa(Request $request)
    {
        $validated = $request->validate([
            'siswa_id' => ['required', 'integer'],
            'mata_pelajaran_id' => ['required', 'integer'],
            'type' => ['nullable', 'in:umum,uts,uas'],
        ]);

        $siswaId = (int) $validated['siswa_id'];
        $mataPelajaranId = (int) $validated['mata_pelajaran_id'];
        $type = $validated['type'] ?? 'umum';
        
        $tahunAjaranId = $this->getValidTahunAjaranId();
        abort_unless($tahunAjaranId, 403);

        $selectedSemester = $this->getCurrentSemester($tahunAjaranId);
        $mataPelajaran = MataPelajaran::find($mataPelajaranId);
        $kelas = $mataPelajaran
            ? $this->authorizeWaliSubject($mataPelajaran, $tahunAjaranId, $selectedSemester)
            : null;
        abort_unless(
            $kelas && $this->isStudentInClass($siswaId, (int) $kelas->id, $tahunAjaranId, $selectedSemester),
            403
        );
        
        $catatan = CatatanMataPelajaran::where([
            'siswa_id' => $siswaId,
            'mata_pelajaran_id' => $mataPelajaranId,
            'tahun_ajaran_id' => $tahunAjaranId,
            'semester' => $selectedSemester,
            'type' => $type,
        ])->first();
        
        return response()->json([
            'success' => true,
            'catatan' => $catatan ? $catatan->catatan : ''
        ]);
    }

    private function getCurrentSemester(int $tahunAjaranId): int
    {
        return (int) (TahunAjaran::find($tahunAjaranId)?->semester ?? 1);
    }

    private function authorizeWaliKelasForYear(int $tahunAjaranId)
    {
        $guru = Auth::guard('guru')->user();
        abort_unless($guru && session('selected_role') === 'wali_kelas', 403);

        $kelasId = DB::table('guru_kelas')
            ->join('kelas', 'guru_kelas.kelas_id', '=', 'kelas.id')
            ->where('guru_kelas.guru_id', $guru->id)
            ->where('guru_kelas.is_wali_kelas', true)
            ->where('guru_kelas.role', 'wali_kelas')
            ->where('kelas.tahun_ajaran_id', $tahunAjaranId)
            ->value('kelas.id');

        abort_unless($kelasId, 403);

        return \App\Models\Kelas::find($kelasId);
    }

    private function authorizeWaliStudent(Siswa $siswa, int $tahunAjaranId, int $semester)
    {
        $kelas = $this->authorizeWaliKelasForYear($tahunAjaranId);

        abort_unless(
            $kelas && $this->isStudentInClass((int) $siswa->id, (int) $kelas->id, $tahunAjaranId, $semester),
            403
        );

        return $kelas;
    }

    private function authorizeWaliSubject(MataPelajaran $mataPelajaran, int $tahunAjaranId, int $semester)
    {
        $kelas = $this->authorizeWaliKelasForYear($tahunAjaranId);

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

    private function isStudentInClass(int $siswaId, int $kelasId, int $tahunAjaranId, int $semester): bool
    {
        return app(SiswaKelasSemesterResolver::class)
            ->isEnrolledInClass($siswaId, $kelasId, $tahunAjaranId, $semester, true);
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
}

<?php

namespace App\Http\Controllers;

use App\Models\TahunAjaran;
use App\Models\Kelas;
use App\Models\MataPelajaran;
use App\Models\ReportTemplate;
use App\Models\Siswa;
use App\Models\SiswaKelasSemester;
use App\Models\ProfilSekolah;
use App\Models\BobotNilai;
use App\Services\SiswaKelasSemesterResolver;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use DomainException;
use RuntimeException;
use Throwable;

class TahunAjaranController extends Controller
{
    private const PERMANENT_DELETE_BLOCKED_MESSAGE = 'Tahun ajaran ini tidak dapat dihapus permanen karena masih terhubung dengan alur akademik, siswa, nilai, atau rapor. Gunakan arsip sebagai penyimpanan aman, atau pulihkan jika masih diperlukan.';
    private const PERMANENT_DELETE_PROTECTED_NOTICE = 'Data tahun ajaran ini tidak dapat dihapus permanen karena masih terhubung dengan alur akademik.';
    private const PERMANENT_DELETE_SUCCESS_MESSAGE = 'Tahun ajaran berhasil dihapus permanen.';
    private const PERMANENT_DELETE_TEMPLATE_CLEANUP_WARNING = 'Tahun ajaran berhasil dihapus permanen, tetapi ada file template yang perlu dibersihkan oleh administrator sistem.';
    private const SEMESTER_GENAP_CONFIRMATION = 'LANJUTKAN KE SEMESTER GENAP';
    private const NEXT_YEAR_CONFIRMATION = 'BUAT TAHUN AJARAN BERIKUTNYA';
    private const CONFIRMATION_MISMATCH_MESSAGE = 'Konfirmasi tidak sesuai. Ketik kalimat yang diminta untuk melanjutkan.';

    /**
     * Display a listing of tahun ajaran.
     */
    public function index(Request $request)
    {
        // Check if we should show archived items
        $tampilkanArsip = $request->has('showArchived');
        
        // Query utama untuk tampilan
        $query = TahunAjaran::orderBy('tahun_ajaran', 'desc')
                        ->orderBy('semester', 'asc');
                        
        if ($tampilkanArsip) {
            $query->withTrashed();
        }
        
        $tahunAjarans = $query->get();
        $permanentDeleteProtectionMessages = $this->permanentDeleteProtectionMessagesFor($tahunAjarans);
        
        // Hitung jumlah arsip secara terpisah
        $archivedCount = TahunAjaran::onlyTrashed()->count();
        
        return view('admin.tahun_ajaran.index', compact('tahunAjarans', 'tampilkanArsip', 'archivedCount', 'permanentDeleteProtectionMessages'));
    }

    /**
     * Copy related data from one semester to the next semester within the same academic year
     * 
     * @param TahunAjaran $sourceTahunAjaran The source (semester 1) academic year
     * @param TahunAjaran $newTahunAjaran The target (semester 2) academic year
     * @return void
     */
    private function copyRelatedDataToNewSemester($sourceTahunAjaran, $newTahunAjaran, array &$copiedStoragePaths = [])
    {
        \Log::info("Copying related data from semester 1 to semester 2", [
            'source_id' => $sourceTahunAjaran->id,
            'source_semester' => $sourceTahunAjaran->semester,
            'target_id' => $newTahunAjaran->id,
            'target_semester' => $newTahunAjaran->semester
        ]);

        $kelasMapping = [];
        $sourceClassStudents = [];

        $sourceKelas = Kelas::where('tahun_ajaran_id', $sourceTahunAjaran->id)->get();
        foreach ($sourceKelas as $kelas) {
            $students = $this->studentsForSemesterTransitionClass($kelas, $sourceTahunAjaran);
            $this->assertNoLegacyS2StudentsInTransition($students, $kelas, $sourceTahunAjaran);

            $newKelas = $kelas->replicate();
            $newKelas->tahun_ajaran_id = $newTahunAjaran->id;
            $newKelas->save();

            \Log::info("Created new kelas for semester 2", [
                'original_kelas_id' => $kelas->id,
                'new_kelas_id' => $newKelas->id,
                'kelas_name' => $kelas->nomor_kelas . ' ' . $kelas->nama_kelas
            ]);

            $kelasMapping[$kelas->id] = $newKelas->id;
            $sourceClassStudents[$kelas->id] = $students;

            $guruRelations = DB::table('guru_kelas')
                ->where('kelas_id', $kelas->id)
                ->get();

            foreach ($guruRelations as $relation) {
                DB::table('guru_kelas')->insert([
                    'guru_id' => $relation->guru_id,
                    'kelas_id' => $newKelas->id,
                    'is_wali_kelas' => $relation->is_wali_kelas,
                    'role' => $relation->role,
                    'created_at' => now(),
                    'updated_at' => now()
                ]);

                \Log::info("Copied guru relationship", [
                    'guru_id' => $relation->guru_id,
                    'kelas_id' => $newKelas->id,
                    'is_wali_kelas' => $relation->is_wali_kelas,
                    'role' => $relation->role
                ]);
            }

            foreach ($students as $student) {
                SiswaKelasSemester::firstOrCreate([
                    'siswa_id' => $student->id,
                    'tahun_ajaran_id' => $newTahunAjaran->id,
                    'semester' => 2,
                ], [
                    'kelas_id' => $newKelas->id,
                ]);

                \Log::info("Created semester 2 enrollment for existing student", [
                    'siswa_id' => $student->id,
                    'source_kelas_id' => $kelas->id,
                    'target_kelas_id' => $newKelas->id,
                    'target_tahun_ajaran_id' => $newTahunAjaran->id,
                ]);
            }
        }

        $mapelMapping = [];

        $sourceMataPelajaran = MataPelajaran::where('tahun_ajaran_id', $sourceTahunAjaran->id)->get();

        foreach ($sourceMataPelajaran as $mapel) {
            $newMapel = $mapel->replicate();
            $newMapel->tahun_ajaran_id = $newTahunAjaran->id;
            $newMapel->semester = 2;

            if (isset($kelasMapping[$mapel->kelas_id])) {
                $newMapel->kelas_id = $kelasMapping[$mapel->kelas_id];
            }

            $newMapel->save();
            $mapelMapping[$mapel->id] = $newMapel->id;

            \Log::info("Created new mata pelajaran for semester 2", [
                'original_mapel_id' => $mapel->id,
                'new_mapel_id' => $newMapel->id,
                'mapel_name' => $mapel->nama_pelajaran
            ]);

            foreach ($mapel->lingkupMateris as $lm) {
                $newLM = $lm->replicate();
                $newLM->mata_pelajaran_id = $newMapel->id;
                $newLM->save();

                foreach ($lm->tujuanPembelajarans as $tp) {
                    $newTP = $tp->replicate();
                    $newTP->lingkup_materi_id = $newLM->id;
                    $newTP->save();
                }
            }
        }

        $ekstrakurikulers = \App\Models\Ekstrakurikuler::where('tahun_ajaran_id', $sourceTahunAjaran->id)->get();
        foreach ($ekstrakurikulers as $ekskul) {
            $newEkskul = $ekskul->replicate();
            $newEkskul->tahun_ajaran_id = $newTahunAjaran->id;
            $newEkskul->save();
        }

        $kkms = \App\Models\Kkm::where('tahun_ajaran_id', $sourceTahunAjaran->id)->get();
        foreach ($kkms as $kkm) {
            if (isset($kelasMapping[$kkm->kelas_id])) {
                $newKkm = $kkm->replicate();
                $newKkm->tahun_ajaran_id = $newTahunAjaran->id;
                $newKkm->kelas_id = $kelasMapping[$kkm->kelas_id];

                if ($kkm->mata_pelajaran_id && isset($mapelMapping[$kkm->mata_pelajaran_id])) {
                    $newKkm->mata_pelajaran_id = $mapelMapping[$kkm->mata_pelajaran_id];
                }

                $newKkm->save();
            }
        }

        $bobotNilai = \App\Models\BobotNilai::where('tahun_ajaran_id', $sourceTahunAjaran->id)->first();
        if ($bobotNilai) {
            $newBobotNilai = $bobotNilai->replicate();
            $newBobotNilai->tahun_ajaran_id = $newTahunAjaran->id;
            $newBobotNilai->save();
        }

        $reportTemplates = \App\Models\ReportTemplate::where('tahun_ajaran_id', $sourceTahunAjaran->id)->get();
        foreach ($reportTemplates as $template) {
            $newPath = $this->copyReportTemplateFileForSemester($template, $copiedStoragePaths);

            $newTemplate = $template->replicate();
            $newTemplate->tahun_ajaran_id = $newTahunAjaran->id;
            $newTemplate->semester = 2;
            $newTemplate->path = $newPath;
            $newTemplate->is_active = false;

            if ($template->kelas_id && isset($kelasMapping[$template->kelas_id])) {
                $newTemplate->kelas_id = $kelasMapping[$template->kelas_id];
            }

            $newTemplate->save();

            if (Schema::hasTable('report_template_kelas')) {
                $templateClassIds = DB::table('report_template_kelas')
                    ->where('report_template_id', $template->id)
                    ->pluck('kelas_id');

                foreach ($templateClassIds as $templateClassId) {
                    if (isset($kelasMapping[$templateClassId])) {
                        DB::table('report_template_kelas')->insert([
                            'report_template_id' => $newTemplate->id,
                            'kelas_id' => $kelasMapping[$templateClassId],
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]);
                    }
                }
            }

            foreach ($template->mappings as $mapping) {
                $newMapping = $mapping->replicate();
                $newMapping->report_template_id = $newTemplate->id;
                $newMapping->save();
            }
        }

        foreach ($sourceClassStudents as $sourceKelasId => $students) {
            $targetKelasId = $kelasMapping[$sourceKelasId] ?? null;

            if (! $targetKelasId) {
                continue;
            }

            foreach ($students as $student) {
                \App\Models\Absensi::firstOrCreate([
                    'siswa_id' => $student->id,
                    'semester' => 2,
                    'tahun_ajaran_id' => $newTahunAjaran->id,
                ], [
                    'sakit' => 0,
                    'izin' => 0,
                    'tanpa_keterangan' => 0,
                ]);
            }
        }

        app(SiswaKelasSemesterResolver::class)->resetMemoization();

        \Log::info("Successfully prepared all related data for semester 2", [
            'target_id' => $newTahunAjaran->id,
            'classes_copied' => count($kelasMapping),
            'subjects_copied' => count($mapelMapping),
        ]);
    }

    private function studentsForSemesterTransitionClass(Kelas $kelas, TahunAjaran $sourceTahunAjaran)
    {
        return app(SiswaKelasSemesterResolver::class)
            ->studentsForClass($kelas->id, $sourceTahunAjaran->id, 1, true);
    }

    private function assertNoLegacyS2StudentsInTransition($students, Kelas $kelas, TahunAjaran $sourceTahunAjaran): void
    {
        $hasLegacyS2Student = $students->contains(function ($student) {
            return str_starts_with((string) $student->nis, 'S2-')
                || str_starts_with((string) $student->nisn, 'S2-');
        });

        if ($hasLegacyS2Student) {
            throw new DomainException(
                "Source class {$kelas->id} in tahun_ajaran {$sourceTahunAjaran->id} contains legacy S2 student rows."
            );
        }
    }

    private function buildTransitionReadiness(TahunAjaran $sourceTahunAjaran, string $workflow): array
    {
        $kelasCount = $this->countRowsForTahunAjaran('kelas', (int) $sourceTahunAjaran->id);
        $rosterCount = $this->countRosterStudentsForSource($sourceTahunAjaran);
        $kelasTanpaWali = $this->countClassesWithoutHomeroom((int) $sourceTahunAjaran->id);
        $mapelCount = $this->countRowsForTahunAjaran('mata_pelajarans', (int) $sourceTahunAjaran->id);
        $mapelTanpaGuru = $this->countSubjectsWithoutTeacher((int) $sourceTahunAjaran->id);
        $mapelBelumLengkap = $this->countSubjectsWithIncompleteLearningStructure((int) $sourceTahunAjaran->id);
        $kkmCount = $this->countRowsForTahunAjaran('kkms', (int) $sourceTahunAjaran->id);
        $ekstrakurikulerCount = $this->countRowsForTahunAjaran('ekstrakurikulers', (int) $sourceTahunAjaran->id);
        $templateCount = $this->countRowsForTahunAjaran('report_templates', (int) $sourceTahunAjaran->id);
        $activeTemplateCount = $this->countActiveReportTemplates((int) $sourceTahunAjaran->id);
        $bobot = Schema::hasTable('bobot_nilais')
            ? BobotNilai::resolveForRead((int) $sourceTahunAjaran->id)
            : null;

        $ready = [];
        $warnings = [];
        $info = [];

        if ($kelasCount > 0) {
            $ready[] = ['label' => 'Kelas', 'value' => "{$kelasCount} kelas tersedia"];
        } else {
            $warnings[] = ['label' => 'Kelas', 'value' => 'Belum ada kelas pada tahun ajaran sumber.'];
        }

        if ($workflow === 'semester_genap') {
            $info[] = ['label' => 'Roster siswa sumber', 'value' => "{$rosterCount} siswa terdeteksi dari kelas sumber"];
        }

        if ($kelasTanpaWali === 0 && $kelasCount > 0) {
            $ready[] = ['label' => 'Wali kelas', 'value' => 'Semua kelas memiliki wali kelas'];
        } elseif ($kelasCount > 0) {
            $warnings[] = ['label' => 'Wali kelas', 'value' => "{$kelasTanpaWali} kelas belum memiliki wali kelas"];
        }

        if ($mapelCount > 0) {
            $ready[] = ['label' => 'Mata pelajaran', 'value' => "{$mapelCount} mata pelajaran tersedia"];
        } else {
            $warnings[] = ['label' => 'Mata pelajaran', 'value' => 'Belum ada mata pelajaran pada tahun ajaran sumber.'];
        }

        if ($mapelTanpaGuru === 0 && $mapelCount > 0) {
            $ready[] = ['label' => 'Guru pengajar', 'value' => 'Semua mata pelajaran memiliki guru pengajar'];
        } elseif ($mapelCount > 0) {
            $warnings[] = ['label' => 'Guru pengajar', 'value' => "{$mapelTanpaGuru} mata pelajaran belum memiliki guru pengajar"];
        }

        if ($mapelBelumLengkap === 0 && $mapelCount > 0) {
            $ready[] = ['label' => 'LM/TP', 'value' => 'Struktur LM/TP sudah tersedia'];
        } elseif ($mapelCount > 0) {
            $warnings[] = ['label' => 'LM/TP', 'value' => "{$mapelBelumLengkap} mata pelajaran belum memiliki LM/TP lengkap"];
        }

        if ($kkmCount > 0) {
            $ready[] = ['label' => 'KKM', 'value' => "{$kkmCount} pengaturan KKM tersedia"];
        } else {
            $warnings[] = ['label' => 'KKM', 'value' => 'Belum ada pengaturan KKM tersimpan.'];
        }

        if ($bobot) {
            $info[] = [
                'label' => 'Bobot Nilai',
                'value' => $bobot->exists
                    ? "Tersimpan ({$bobot->bobot_tp}:{$bobot->bobot_lm}:{$bobot->bobot_as})"
                    : "Menggunakan default sementara ({$bobot->bobot_tp}:{$bobot->bobot_lm}:{$bobot->bobot_as}), belum tersimpan",
            ];
        }

        if ($workflow === 'next_year') {
            $info[] = ['label' => 'Ekstrakurikuler', 'value' => "{$ekstrakurikulerCount} data ekstrakurikuler tersedia"];
        }

        if ($templateCount > 0) {
            $info[] = ['label' => 'Template rapor', 'value' => "{$templateCount} template tersedia, {$activeTemplateCount} aktif"];
        } else {
            $warnings[] = ['label' => 'Template rapor', 'value' => 'Belum ada template rapor pada tahun ajaran sumber.'];
        }

        $info[] = [
            'label' => $workflow === 'semester_genap' ? 'Data pekerjaan siswa' : 'Data siswa',
            'value' => $workflow === 'semester_genap'
                ? 'Nilai, catatan, rapor, dan hasil pekerjaan siswa Semester Ganjil tidak disalin sebagai pekerjaan Semester Genap.'
                : 'Siswa tidak disalin sebagai data baru; penempatan tahun ajaran berikutnya melalui Kenaikan Kelas.',
        ];

        return [
            'workflow' => $workflow,
            'source' => [
                'id' => $sourceTahunAjaran->id,
                'tahun_ajaran' => $sourceTahunAjaran->tahun_ajaran,
                'semester' => (int) $sourceTahunAjaran->semester,
            ],
            'counts' => [
                'kelas' => $kelasCount,
                'roster_siswa' => $rosterCount,
                'kelas_tanpa_wali' => $kelasTanpaWali,
                'mata_pelajaran' => $mapelCount,
                'mata_pelajaran_tanpa_guru' => $mapelTanpaGuru,
                'mata_pelajaran_lm_tp_belum_lengkap' => $mapelBelumLengkap,
                'kkm' => $kkmCount,
                'bobot_persisted' => $bobot?->exists ?? false,
                'template_rapor' => $templateCount,
                'template_rapor_aktif' => $activeTemplateCount,
                'ekstrakurikuler' => $ekstrakurikulerCount,
            ],
            'siap' => $ready,
            'perlu_diperiksa' => $warnings,
            'informasi' => $info,
        ];
    }

    private function countRowsForTahunAjaran(string $table, int $tahunAjaranId): int
    {
        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'tahun_ajaran_id')) {
            return 0;
        }

        $query = DB::table($table)->where('tahun_ajaran_id', $tahunAjaranId);

        if (Schema::hasColumn($table, 'deleted_at')) {
            $query->whereNull('deleted_at');
        }

        return (int) $query->count();
    }

    private function countRosterStudentsForSource(TahunAjaran $sourceTahunAjaran): int
    {
        if (! Schema::hasTable('kelas') || ! Schema::hasTable('siswas')) {
            return 0;
        }

        $studentIds = [];
        $classes = Kelas::where('tahun_ajaran_id', $sourceTahunAjaran->id)->get();

        foreach ($classes as $kelas) {
            foreach ($this->studentsForSemesterTransitionClass($kelas, $sourceTahunAjaran) as $student) {
                $studentIds[(int) $student->id] = true;
            }
        }

        return count($studentIds);
    }

    private function countClassesWithoutHomeroom(int $tahunAjaranId): int
    {
        if (! Schema::hasTable('kelas') || ! Schema::hasTable('guru_kelas')) {
            return $this->countRowsForTahunAjaran('kelas', $tahunAjaranId);
        }

        return (int) DB::table('kelas as k')
            ->where('k.tahun_ajaran_id', $tahunAjaranId)
            ->when(Schema::hasColumn('kelas', 'deleted_at'), fn ($query) => $query->whereNull('k.deleted_at'))
            ->whereNotExists(function ($query) {
                $query->select(DB::raw(1))
                    ->from('guru_kelas as gk')
                    ->whereColumn('gk.kelas_id', 'k.id')
                    ->where(function ($nested) {
                        $nested->where('gk.is_wali_kelas', true)
                            ->orWhere('gk.role', 'wali_kelas');
                    });
            })
            ->count();
    }

    private function countSubjectsWithoutTeacher(int $tahunAjaranId): int
    {
        if (! Schema::hasTable('mata_pelajarans') || ! Schema::hasColumn('mata_pelajarans', 'guru_id')) {
            return 0;
        }

        $query = DB::table('mata_pelajarans')
            ->where('tahun_ajaran_id', $tahunAjaranId)
            ->whereNull('guru_id');

        if (Schema::hasColumn('mata_pelajarans', 'deleted_at')) {
            $query->whereNull('deleted_at');
        }

        return (int) $query->count();
    }

    private function countSubjectsWithIncompleteLearningStructure(int $tahunAjaranId): int
    {
        if (! Schema::hasTable('mata_pelajarans')) {
            return 0;
        }

        $subjects = DB::table('mata_pelajarans')
            ->where('tahun_ajaran_id', $tahunAjaranId)
            ->when(Schema::hasColumn('mata_pelajarans', 'deleted_at'), fn ($query) => $query->whereNull('deleted_at'))
            ->pluck('id');

        if ($subjects->isEmpty() || ! Schema::hasTable('lingkup_materis') || ! Schema::hasTable('tujuan_pembelajarans')) {
            return 0;
        }

        $incomplete = 0;

        foreach ($subjects as $subjectId) {
            $lingkupMateriIds = DB::table('lingkup_materis')
                ->where('mata_pelajaran_id', $subjectId)
                ->when(Schema::hasColumn('lingkup_materis', 'deleted_at'), fn ($query) => $query->whereNull('deleted_at'))
                ->pluck('id');

            if ($lingkupMateriIds->isEmpty()) {
                $incomplete++;
                continue;
            }

            $hasLmWithoutTp = $lingkupMateriIds->contains(function ($lingkupMateriId) {
                $tpQuery = DB::table('tujuan_pembelajarans')
                    ->where('lingkup_materi_id', $lingkupMateriId);

                if (Schema::hasColumn('tujuan_pembelajarans', 'deleted_at')) {
                    $tpQuery->whereNull('deleted_at');
                }

                return ! $tpQuery->exists();
            });

            if ($hasLmWithoutTp) {
                $incomplete++;
            }
        }

        return $incomplete;
    }

    private function countActiveReportTemplates(int $tahunAjaranId): int
    {
        if (! Schema::hasTable('report_templates') || ! Schema::hasColumn('report_templates', 'tahun_ajaran_id')) {
            return 0;
        }

        $query = DB::table('report_templates')
            ->where('tahun_ajaran_id', $tahunAjaranId)
            ->where('is_active', true);

        if (Schema::hasColumn('report_templates', 'deleted_at')) {
            $query->whereNull('deleted_at');
        }

        return (int) $query->count();
    }

    private function copyReportTemplateFileForSemester(ReportTemplate $template, array &$copiedStoragePaths): ?string
    {
        if (! $template->path) {
            return $template->path;
        }

        $sourcePath = 'public/' . $template->path;

        if (! Storage::exists($sourcePath)) {
            Log::warning('Report template file missing during semester transition; copied template metadata will reuse existing path.', [
                'report_template_id' => $template->id,
                'path' => $template->path,
            ]);

            return $template->path;
        }

        $newPath = str_replace(
            basename($template->path),
            'semester2_' . basename($template->path),
            $template->path
        );
        $targetPath = 'public/' . $newPath;

        if (! Storage::copy($sourcePath, $targetPath)) {
            throw new RuntimeException("Failed to copy report template file for template {$template->id}.");
        }

        $copiedStoragePaths[] = $targetPath;

        return $newPath;
    }

    private function cleanupCopiedTransitionFiles(array $copiedStoragePaths): void
    {
        foreach ($copiedStoragePaths as $path) {
            try {
                if (Storage::exists($path)) {
                    Storage::delete($path);
                }
            } catch (Throwable $exception) {
                Log::warning('Failed to clean up copied report template file after transition rollback.', [
                    'path' => $path,
                    'error' => $exception->getMessage(),
                ]);
            }
        }
    }

    private function preserveTeacherAssignments($sourceTahunAjaran, $newTahunAjaran)
    {
        \Log::info("Starting teacher preservation process", [
            'source_year' => $sourceTahunAjaran->id,
            'new_year' => $newTahunAjaran->id
        ]);
        
        // Step 1: Get ALL teachers from the source year
        $sourceTeachers = DB::table('guru_kelas')
            ->join('kelas', 'guru_kelas.kelas_id', '=', 'kelas.id')
            ->where('kelas.tahun_ajaran_id', $sourceTahunAjaran->id)
            ->select(
                'guru_kelas.guru_id',
                'guru_kelas.is_wali_kelas',
                'guru_kelas.role',
                'kelas.nomor_kelas',
                'kelas.nama_kelas'
            )
            ->get();
        
        \Log::info("Found {$sourceTeachers->count()} teacher assignments in source year");
        
        // Step 2: For each teacher, find the SAME GRADE LEVEL class in the new year
        foreach ($sourceTeachers as $teacher) {
            // Look for the same grade level and section in the new year
            $targetClass = Kelas::where('tahun_ajaran_id', $newTahunAjaran->id)
                ->where('nomor_kelas', $teacher->nomor_kelas)
                ->where('nama_kelas', $teacher->nama_kelas)
                ->first();
            
            if ($targetClass) {
                // Check if this assignment already exists to avoid duplicates
                $exists = DB::table('guru_kelas')
                    ->where('guru_id', $teacher->guru_id)
                    ->where('kelas_id', $targetClass->id)
                    ->where('role', $teacher->role)
                    ->exists();
                
                if (!$exists) {
                    // Create the new teacher assignment
                    DB::table('guru_kelas')->insert([
                        'guru_id' => $teacher->guru_id,
                        'kelas_id' => $targetClass->id,
                        'is_wali_kelas' => $teacher->is_wali_kelas,
                        'role' => $teacher->role,
                        'created_at' => now(),
                        'updated_at' => now()
                    ]);
                    
                    \Log::info("Assigned teacher to new class", [
                        'guru_id' => $teacher->guru_id,
                        'nomor_kelas' => $teacher->nomor_kelas,
                        'nama_kelas' => $teacher->nama_kelas,
                        'new_kelas_id' => $targetClass->id
                    ]);
                }
            } else {
                \Log::warning("Could not find matching class in new year", [
                    'grade' => $teacher->nomor_kelas,
                    'section' => $teacher->nama_kelas
                ]);
            }
        }
    }


    public function advanceToNextSemester(Request $request, $id)
    {
        $copiedStoragePaths = [];

        try {
            $sourceTahunAjaran = TahunAjaran::withTrashed()->findOrFail($id);
            $this->assertSemesterTransitionSourceIsEligible($sourceTahunAjaran);
        } catch (DomainException $e) {
            Log::warning('[TahunAjaranController] Advance semester rejected before confirmation', [
                'error' => $e->getMessage(),
                'user_id' => auth()->id(),
                'tahun_ajaran_id' => $id,
            ]);

            return redirect()->back()->with('error', $e->getMessage());
        }

        if (! $this->hasValidTransitionConfirmation($request, self::SEMESTER_GENAP_CONFIRMATION)) {
            return $this->rejectTransitionConfirmation($request);
        }

        DB::beginTransaction();
        
        try {
            // Find the source academic year
            $sourceTahunAjaran = TahunAjaran::withTrashed()
                ->whereKey($id)
                ->lockForUpdate()
                ->firstOrFail();

            $this->assertSemesterTransitionSourceIsEligible($sourceTahunAjaran);

            if (TahunAjaran::where('is_active', true)->where('id', '!=', $sourceTahunAjaran->id)->exists()) {
                throw new DomainException('Ada tahun ajaran lain yang sedang aktif. Transisi semester tidak dapat dilanjutkan dengan aman.');
            }

            $existingSemesterGenap = TahunAjaran::withTrashed()
                ->where('tahun_ajaran', $sourceTahunAjaran->tahun_ajaran)
                ->where('semester', 2)
                ->where('id', '!=', $sourceTahunAjaran->id)
                ->first();

            if ($existingSemesterGenap) {
                if ($existingSemesterGenap->trashed()) {
                    throw new DomainException('Semester Genap untuk tahun ajaran ini sudah ada di arsip. Pulihkan Semester Genap dari arsip terlebih dahulu, lalu aktifkan semester tersebut jika ingin melanjutkan.');
                }

                throw new DomainException('Semester Genap untuk tahun ajaran ini sudah ada. Aktifkan Semester Genap tersebut dari daftar tahun ajaran; transisi tidak dibuat ulang.');
            }
            
            // Create a new academic year record with semester 2
            $newTahunAjaran = $sourceTahunAjaran->replicate();
            $newTahunAjaran->semester = 2;
            $newTahunAjaran->is_active = false;
            $newTahunAjaran->deskripsi = $sourceTahunAjaran->deskripsi . ' (Semester Genap)';
            $newTahunAjaran->save();
            
            // Copy related data (similar to your existing copy methods)
            $this->copyRelatedDataToNewSemester($sourceTahunAjaran, $newTahunAjaran, $copiedStoragePaths);

            // Switch active year only after the target semester is fully prepared
            $sourceTahunAjaran->is_active = false;
            $sourceTahunAjaran->save();

            $newTahunAjaran->is_active = true;
            $newTahunAjaran->save();
            
            // Update school profile to use the new semester
            $this->updateProfilSekolah($newTahunAjaran);
            
            // Set both tahun_ajaran_id and selected_semester in session
            session(['tahun_ajaran_id' => $newTahunAjaran->id]);
            session(['selected_semester' => 2]); // Set semester to 2 (genap)
            
            DB::commit();
            $this->clearTahunAjaranCaches($newTahunAjaran->id);

            try {
                $notification = new \App\Models\Notification();
                $notification->title = "Semester Genap {$newTahunAjaran->tahun_ajaran} Dimulai";
                $notification->content = 'Semester Genap telah dimulai. '
                    . 'Silakan perbarui Tujuan Pembelajaran dan Lingkup Materi '
                    . 'untuk semester genap jika diperlukan. Perubahan bersifat '
                    . 'opsional sesuai kebutuhan mengajar.';
                $notification->target = 'guru';
                $notification->save();

                event(new \App\Events\NotificationCreated($notification));
            } catch (\Exception $notificationException) {
                Log::warning('[TahunAjaranController] Failed to send semester notification', [
                    'error' => $notificationException->getMessage(),
                    'tahun_ajaran_id' => $newTahunAjaran->id,
                ]);
            }
            
            return redirect()->route('tahun.ajaran.index')
                ->with('success', 'Berhasil melanjutkan ke semester Genap. Data semester Ganjil tetap tersimpan.');
        } catch (DomainException $e) {
            DB::rollback();
            $this->cleanupCopiedTransitionFiles($copiedStoragePaths);

            Log::warning('[TahunAjaranController] Advance semester rejected', [
                'error' => $e->getMessage(),
                'user_id' => auth()->id(),
                'tahun_ajaran_id' => $id,
            ]);

            return redirect()->back()->with('error', $e->getMessage());
        } catch (\Exception $e) {
            DB::rollback();
            $this->cleanupCopiedTransitionFiles($copiedStoragePaths);

            Log::error('[TahunAjaranController] Advance semester failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'user_id' => auth()->id(),
                'tahun_ajaran_id' => $id,
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            return redirect()->back()->with('error', 'Gagal melanjutkan semester. Silakan coba lagi.');
        }
    }

    private function assertSemesterTransitionSourceIsEligible(TahunAjaran $sourceTahunAjaran): void
    {
        if ($sourceTahunAjaran->trashed()) {
            throw new DomainException('Tahun ajaran yang berada di arsip harus dipulihkan terlebih dahulu sebelum dapat dilanjutkan ke semester berikutnya.');
        }

        if (! $sourceTahunAjaran->is_active) {
            throw new DomainException('Hanya Semester Ganjil yang sedang aktif yang dapat dilanjutkan ke Semester Genap.');
        }

        if ((int) $sourceTahunAjaran->semester !== 1) {
            throw new DomainException('Pembuatan Semester Genap hanya dapat dilakukan dari Semester Ganjil.');
        }
    }

    private function assertNextYearCopySourceIsEligible(TahunAjaran $sourceTahunAjaran): void
    {
        if ($sourceTahunAjaran->trashed()) {
            throw new DomainException('Tahun ajaran yang berada di arsip harus dipulihkan terlebih dahulu sebelum dapat digunakan untuk membuat tahun ajaran berikutnya.');
        }

        if ((int) $sourceTahunAjaran->semester !== 2) {
            throw new DomainException('Pembuatan tahun ajaran berikutnya hanya dapat dilakukan dari Semester Genap.');
        }
    }

    private function hasValidTransitionConfirmation(Request $request, string $requiredPhrase): bool
    {
        return trim((string) $request->input('transition_confirmation')) === $requiredPhrase;
    }

    private function rejectTransitionConfirmation(Request $request)
    {
        if ($request->expectsJson()) {
            return response()->json([
                'success' => false,
                'message' => self::CONFIRMATION_MISMATCH_MESSAGE,
                'errors' => [
                    'transition_confirmation' => [self::CONFIRMATION_MISMATCH_MESSAGE],
                ],
            ], 422);
        }

        return redirect()->back()
            ->withErrors(['transition_confirmation' => self::CONFIRMATION_MISMATCH_MESSAGE])
            ->with('error', self::CONFIRMATION_MISMATCH_MESSAGE)
            ->withInput();
    }

    private function rejectCopyRequest(Request $request, string $message, int $status = 422)
    {
        if ($request->expectsJson()) {
            return response()->json([
                'success' => false,
                'message' => $message,
            ], $status);
        }

        return redirect()->back()
            ->with('error', $message)
            ->withInput();
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        return view('admin.tahun_ajaran.create');
    }

    /**
     * Store a newly created resource in storage.
     */

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'tahun_ajaran' => [
                'required',
                'string',
                'regex:/^\d{4}\/\d{4}$/',
                function ($attribute, $value, $fail) {
                    $exists = TahunAjaran::withTrashed()
                                ->where('tahun_ajaran', $value)
                                ->exists();
                    
                    if ($exists) {
                        $fail('Tahun ajaran ini sudah ada (termasuk yang diarsipkan). Gunakan nama yang berbeda atau pulihkan yang sudah diarsipkan.');
                    }
                }
            ],
            'tanggal_mulai' => 'required|date',
            'tanggal_selesai' => 'required|date|after:tanggal_mulai',
            'semester' => 'required|integer|in:1,2',
            'deskripsi' => 'nullable|string',
            'is_active' => 'boolean'
        ]);

        if ($validator->fails()) {
            return redirect()->back()
                        ->withErrors($validator)
                        ->withInput();
        }

        DB::beginTransaction();
        
        try {
            // Jika menandai sebagai aktif, nonaktifkan tahun ajaran lain
            if ($request->has('is_active') && $request->is_active) {
                TahunAjaran::where('is_active', true)
                    ->update(['is_active' => false]);
            }

            // Buat tahun ajaran baru
            $tahunAjaran = TahunAjaran::create($request->all());

            // Jika aktif, update profil sekolah
            if ($request->has('is_active') && $request->is_active) {
                $this->updateProfilSekolah($tahunAjaran);
            }
            
            DB::commit();
            $this->clearTahunAjaranCaches($tahunAjaran->id);

            return redirect()->route('tahun.ajaran.index')
                        ->with('success', 'Tahun ajaran berhasil dibuat.');
        } catch (\Exception $e) {
            DB::rollback();

            \Log::error('Gagal membuat tahun ajaran manual', [
                'tahun_ajaran' => $request->input('tahun_ajaran'),
                'semester' => $request->input('semester'),
                'is_active' => $request->boolean('is_active'),
                'error' => $e->getMessage(),
                'exception' => get_class($e),
            ]);

            return redirect()->back()
                    ->with('error', 'Gagal membuat tahun ajaran. Silakan coba lagi.')
                    ->withInput();
        }
    }


    public function checkSemesterGenap()
    {
        try {
            // Cari tahun ajaran dengan semester genap (semester 2)
            $semesterGenap = TahunAjaran::where('semester', 2)
                                    ->orderBy('tanggal_mulai', 'desc')
                                    ->first();
            
            if ($semesterGenap) {
                return response()->json([
                    'hasSemseterGenap' => true,
                    'tahunAjaran' => $semesterGenap->tahun_ajaran,
                    'tahunAjaranId' => $semesterGenap->id,
                    'copyUrl' => route('tahun.ajaran.copy', $semesterGenap->id),
                    'message' => "Ditemukan tahun ajaran {$semesterGenap->tahun_ajaran} semester genap. Disarankan menggunakan fitur copy untuk melanjutkan ke tahun ajaran berikutnya."
                ]);
            }
            
            return response()->json([
                'hasSemseterGenap' => false,
                'message' => 'Tidak ada tahun ajaran semester genap. Anda dapat membuat tahun ajaran baru.'
            ]);
            
        } catch (\Exception $e) {
            return response()->json([
                'hasSemseterGenap' => false,
                'error' => 'Terjadi kesalahan saat mengecek status tahun ajaran',
                'message' => $e->getMessage()
            ], 500);
        }
    }
    /**
     * Copy struktur dasar dari tahun ajaran sebelumnya
     * HANYA: Kelas + Assignment Guru
     */
    private function copyBasicStructureFromPrevious($sourceTahunAjaran, $newTahunAjaran)
    {
        \Log::info("Copying basic structure (classes + guru assignments) from {$sourceTahunAjaran->tahun_ajaran} to {$newTahunAjaran->tahun_ajaran}");
        
        // 1. Copy kelas dengan struktur yang sama
        $sourceKelas = Kelas::where('tahun_ajaran_id', $sourceTahunAjaran->id)
                            ->orderBy('nomor_kelas')
                            ->orderBy('nama_kelas')
                            ->get();
        
        $kelasMapping = [];
        $assignmentCount = 0;
        
        foreach ($sourceKelas as $kelas) {
            // Copy kelas
            $newKelas = $kelas->replicate();
            $newKelas->tahun_ajaran_id = $newTahunAjaran->id;
            $newKelas->save();
            
            $kelasMapping[$kelas->id] = $newKelas->id;
            
            \Log::info("Created class: {$kelas->nomor_kelas}{$kelas->nama_kelas} (ID: {$newKelas->id})");
            
            // 2. Copy assignment guru untuk kelas ini
            $guruRelations = DB::table('guru_kelas')
                ->where('kelas_id', $kelas->id)
                ->get();
                
            foreach ($guruRelations as $relation) {
                // Cek apakah assignment sudah ada (prevent duplicate)
                $exists = DB::table('guru_kelas')
                    ->where('guru_id', $relation->guru_id)
                    ->where('kelas_id', $newKelas->id)
                    ->where('role', $relation->role)
                    ->exists();
                
                if (!$exists) {
                    DB::table('guru_kelas')->insert([
                        'guru_id' => $relation->guru_id,
                        'kelas_id' => $newKelas->id,
                        'is_wali_kelas' => $relation->is_wali_kelas,
                        'role' => $relation->role,
                        'created_at' => now(),
                        'updated_at' => now()
                    ]);
                    
                    $assignmentCount++;
                    
                    // Log assignment untuk debugging
                    $guru = \App\Models\Guru::find($relation->guru_id);
                    $roleText = $relation->is_wali_kelas ? 'Wali Kelas' : 'Pengajar';
                    \Log::info("Assigned: {$guru->nama} → {$kelas->nomor_kelas}{$kelas->nama_kelas} ({$roleText})");
                }
            }
        }
        
        // ❌ TIDAK copy mata pelajaran - biar admin setup manual
        // Admin bisa create mata pelajaran sesuai kebutuhan kurikulum tahun ini
        
        \Log::info("Structure copy completed", [
            'classes_created' => count($kelasMapping),
            'guru_assignments' => $assignmentCount,
            'note' => 'Mata pelajaran tidak di-copy, silakan setup manual'
        ]);
        
        return [
            'classes_created' => count($kelasMapping),
            'assignments_created' => $assignmentCount
        ];
    }

    /**
     * Display the specified resource.
     */
    public function show($id)
    {
        // Tambahkan withTrashed() untuk bisa mengakses tahun ajaran yang diarsipkan
        $tahunAjaran = TahunAjaran::withTrashed()->findOrFail($id);
        
        // Hitung statistik
        $totalKelas = Kelas::where('tahun_ajaran_id', $id)->count();
        $totalSiswa = Siswa::whereHas('kelas', function($query) use ($id) {
            $query->where('tahun_ajaran_id', $id);
        })->count();
        $totalMataPelajaran = MataPelajaran::where('tahun_ajaran_id', $id)->count();
        $permanentDeleteProtectionMessage = $this->permanentDeleteProtectionMessage($tahunAjaran);
        $semesterGenapReadiness = (! $tahunAjaran->trashed() && $tahunAjaran->is_active && (int) $tahunAjaran->semester === 1)
            ? $this->buildTransitionReadiness($tahunAjaran, 'semester_genap')
            : null;
        
        return view('admin.tahun_ajaran.show', compact(
            'tahunAjaran', 
            'totalKelas', 
            'totalSiswa', 
            'totalMataPelajaran',
            'permanentDeleteProtectionMessage',
            'semesterGenapReadiness'
        ));
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit($id)
    {
        // Tambahkan withTrashed() untuk bisa mengedit tahun ajaran yang diarsipkan
        $tahunAjaran = TahunAjaran::withTrashed()->findOrFail($id);
        return view('admin.tahun_ajaran.edit', compact('tahunAjaran'));
    }
    
    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            'tahun_ajaran' => [
                'required',
                'string',
                'regex:/^\d{4}\/\d{4}$/',
                function ($attribute, $value, $fail) use ($id) {
                    // Cek keunikan termasuk dengan yang diarsipkan, kecuali dirinya sendiri
                    $exists = TahunAjaran::withTrashed()
                                ->where('tahun_ajaran', $value)
                                ->where('id', '!=', $id)
                                ->exists();
                    
                    if ($exists) {
                        $fail('Tahun ajaran ini sudah ada (termasuk yang diarsipkan). Gunakan nama yang berbeda.');
                    }
                }
            ],
            'tanggal_mulai' => 'required|date',
            'tanggal_selesai' => 'required|date|after:tanggal_mulai',
            'semester' => 'required|integer|in:1,2',
            'deskripsi' => 'nullable|string',
            'is_active' => 'boolean'
        ]);
    
        if ($validator->fails()) {
            return redirect()->back()
                         ->withErrors($validator)
                         ->withInput();
        }
    
        $tahunAjaran = TahunAjaran::withTrashed()->findOrFail($id);
        
        // Simpan semester lama untuk dibandingkan
        $oldSemester = $tahunAjaran->semester;
        $newSemester = $request->semester;
        
        // Cek jika tahun ajaran sedang aktif dan akan dinonaktifkan
        if ($tahunAjaran->is_active && !$request->has('is_active')) {
            // Hitung apakah ini tahun ajaran aktif satu-satunya
            $activeCount = TahunAjaran::where('is_active', true)->count();
            
            if ($activeCount <= 1) {
                return redirect()->back()
                         ->withInput()
                         ->with('error', 'Harus ada minimal satu tahun ajaran yang aktif. Aktifkan tahun ajaran lain terlebih dahulu sebelum menonaktifkan yang ini.');
            }
        }
    
        // Jika menandai sebagai aktif, nonaktifkan tahun ajaran lain
        if ($request->has('is_active') && $request->is_active && !$tahunAjaran->is_active) {
            TahunAjaran::where('is_active', true)
                   ->update(['is_active' => false]);
        }
    
        DB::beginTransaction();
        try {
            // Jika tahun ajaran di-softdelete, restore terlebih dahulu
            if ($tahunAjaran->trashed() && $request->has('is_active') && $request->is_active) {
                $tahunAjaran->restore();
            }
            
            // Update tahun ajaran
            $tahunAjaran->update($request->all());
            
            // Jika ini adalah tahun ajaran aktif, perbarui profil sekolah
            if ($tahunAjaran->is_active) {
                $this->updateProfilSekolah($tahunAjaran);
                
                // Jika semester berubah, update data terkait
                if ($oldSemester != $newSemester) {
                    $this->updateRelatedData($tahunAjaran->id, $newSemester);
                }
            }
            
            DB::commit();
            $this->clearTahunAjaranCaches($tahunAjaran->id);
            
            // Pesan sukses khusus untuk perubahan semester
            if ($oldSemester != $newSemester) {
                $semesterLabel = $newSemester == 1 ? 'Ganjil' : 'Genap';
                return redirect()->route('tahun.ajaran.index')
                            ->with('success', "Tahun ajaran berhasil diperbarui! Semester diubah menjadi {$semesterLabel}.");
            }
            
            return redirect()->route('tahun.ajaran.index')
                            ->with('success', 'Tahun ajaran berhasil diperbarui!');
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('[TahunAjaranController] Update tahun ajaran failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'user_id' => auth()->id(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
            return redirect()->back()
                    ->with('error', 'Gagal memproses tahun ajaran. Silakan coba lagi.')
                    ->withInput();
        }
    }
    
    /**
     * Update profil sekolah dengan informasi tahun ajaran
     */
    private function updateProfilSekolah(TahunAjaran $tahunAjaran)
    {
        $profil = ProfilSekolah::first();
        if ($profil) {
            $profil->update([
                'tahun_pelajaran' => $tahunAjaran->tahun_ajaran,
                'semester' => $tahunAjaran->semester
            ]);
            Cache::forget('profil_sekolah');
            
            Log::info('Profil sekolah diperbarui dengan tahun ajaran aktif', [
                'tahun_ajaran' => $tahunAjaran->tahun_ajaran,
                'semester' => $tahunAjaran->semester
            ]);
        }
    }

    /**
     * Update data yang terkait dengan tahun ajaran saat semester berubah
     * 
     * @param int $tahunAjaranId
     * @param int $newSemester
     * @return void
     */
    private function updateRelatedData($tahunAjaranId, $newSemester)
    {
        try {
            Log::info("Memperbarui data terkait untuk tahun ajaran #{$tahunAjaranId} ke semester {$newSemester}");
            
            // Update absensi dengan semester baru if column exists
            if (Schema::hasColumn('absensis', 'semester')) {
                $absensiCount = DB::table('absensis')
                    ->where('tahun_ajaran_id', $tahunAjaranId)
                    ->update(['semester' => $newSemester]);
                
                Log::info("Updated {$absensiCount} absensi records to semester {$newSemester}");
            }
            
            // Update mata pelajaran dengan semester baru if column exists
            if (Schema::hasColumn('mata_pelajarans', 'semester')) {
                $mapelCount = DB::table('mata_pelajarans')
                    ->where('tahun_ajaran_id', $tahunAjaranId)
                    ->update(['semester' => $newSemester]);
                
                Log::info("Updated {$mapelCount} mata pelajaran records to semester {$newSemester}");
            }
            
            // Update template rapor dengan semester baru if column exists
            if (Schema::hasColumn('report_templates', 'semester')) {
                $templateCount = DB::table('report_templates')
                    ->where('tahun_ajaran_id', $tahunAjaranId)
                    ->update(['semester' => $newSemester]);
                
                Log::info("Updated {$templateCount} report template records to semester {$newSemester}");
            }
            
            // Tambahkan model lain yang memiliki field semester dan tahun_ajaran_id jika ada
            
            // Log perubahan untuk debugging
            \Log::info("Semester diperbarui untuk tahun ajaran #{$tahunAjaranId} ke semester {$newSemester}");
        } catch (\Exception $e) {
            \Log::error("Error updating related data: " . $e->getMessage());
            throw $e; // Re-throw to handle in the caller
        }
    }

    public function setSessionSemester($tahunAjaranId, $semester)
    {
        try {
            $tahunAjaran = TahunAjaran::withTrashed()->findOrFail($tahunAjaranId);
            
            // Validasi semester
            if (!in_array($semester, [1, 2])) {
                return redirect()->back()->with('error', 'Semester tidak valid');
            }
            
            // Set both tahun_ajaran_id and selected_semester in session
            session(['tahun_ajaran_id' => $tahunAjaranId]);
            session(['selected_semester' => (int)$semester]); // Cast to integer untuk konsistensi
            
            // Add semester info to flash message
            $semesterLabel = $semester == 1 ? 'Ganjil' : 'Genap';
            
            \Log::info("Session semester diatur", [
                'tahun_ajaran_id' => $tahunAjaranId,
                'semester' => $semester,
                'user_id' => auth()->id() ?? auth()->guard('guru')->id() ?? 'guest'
            ]);
            
            return redirect()->back()->with('success', 'Tampilan data diubah ke tahun ajaran ' . 
                $tahunAjaran->tahun_ajaran . ' semester ' . $semesterLabel);
        } catch (\Exception $e) {
            \Log::error("Error setting session semester", [
                'tahun_ajaran_id' => $tahunAjaranId,
                'semester' => $semester,
                'error' => $e->getMessage()
            ]);
            
            return redirect()->back()->with('error', 'Gagal memproses tahun ajaran. Silakan coba lagi.');
        }
    }
    /**
     * Set a tahun ajaran as active.
     */
    public function setActive($id)
    {
        DB::beginTransaction();
        
        try {
            // Nonaktifkan semua tahun ajaran
            TahunAjaran::where('is_active', true)
                ->update(['is_active' => false]);
                
            // Aktifkan tahun ajaran yang dipilih (with trashed untuk termasuk yang diarsipkan)
            $tahunAjaran = TahunAjaran::withTrashed()->findOrFail($id);
            
            // Restore if the academic year was soft deleted
            if ($tahunAjaran->trashed()) {
                $tahunAjaran->restore();
            }
            
            $tahunAjaran->update(['is_active' => true]);
            
            // Update juga di profil sekolah
            $this->updateProfilSekolah($tahunAjaran);
            
            // Set session untuk tampilan data
            session(['tahun_ajaran_id' => $id]);
            
            DB::commit();
            $this->clearTahunAjaranCaches($tahunAjaran->id);
            
            return redirect()->route('tahun.ajaran.index')
            ->with('success', 'Tahun ajaran ' . $tahunAjaran->tahun_ajaran . ' berhasil diaktifkan!');
        } catch (\Exception $e) {
            DB::rollback();
            
            // Coba ambil tahun ajaran yang sebelumnya aktif
            $oldActive = TahunAjaran::where('is_active', true)->first();
            
            // Jika tidak ada yang aktif, aktifkan yang terakhir
            if (!$oldActive) {
                $latest = TahunAjaran::latest('tanggal_mulai')->first();
                if ($latest) {
                    $latest->update(['is_active' => true]);
                    session(['tahun_ajaran_id' => $latest->id]);
                }
            }
            
            return redirect()->back()->with('error', 'Gagal mengaktifkan tahun ajaran: ' . $e->getMessage());
        }
    }

    /**
     * Menghapus secara permanen tahun ajaran yang sudah diarsipkan.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function forceDelete($id)
    {
        try {
            // Cari tahun ajaran yang sudah diarsipkan
            $tahunAjaran = TahunAjaran::withTrashed()->findOrFail($id);
            
            // Pastikan tahun ajaran sudah diarsipkan
            if (!$tahunAjaran->trashed()) {
                if (request()->expectsJson()) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Hanya tahun ajaran yang sudah diarsipkan yang dapat dihapus permanen.'
                    ], 422);
                }

                return redirect()->back()
                    ->with('error', 'Hanya tahun ajaran yang sudah diarsipkan yang dapat dihapus permanen.');
            }

            $activeFlowMessage = $this->activeAcademicYearFlowDeleteBlockMessage($tahunAjaran);
            if ($activeFlowMessage) {
                Log::info('[TahunAjaranController] Permanent delete blocked because academic year belongs to active semester flow', [
                    'tahun_ajaran_id' => $tahunAjaran->id,
                    'user_id' => auth()->id(),
                ]);

                if (request()->expectsJson()) {
                    return response()->json([
                        'success' => false,
                        'message' => $activeFlowMessage,
                    ], 422);
                }

                return redirect()->back()->with('error', $activeFlowMessage);
            }

            $blockingDependencies = $this->permanentDeleteBlockingDependencies((int) $tahunAjaran->id);

            if (! empty($blockingDependencies)) {
                Log::info('[TahunAjaranController] Permanent delete blocked because academic year has dependencies', [
                    'tahun_ajaran_id' => $tahunAjaran->id,
                    'dependencies' => $blockingDependencies,
                    'user_id' => auth()->id(),
                ]);

                $message = self::PERMANENT_DELETE_BLOCKED_MESSAGE . ' Data terkait: ' . implode(', ', $blockingDependencies) . '.';

                if (request()->expectsJson()) {
                    return response()->json([
                        'success' => false,
                        'message' => $message,
                    ], 422);
                }

                return redirect()->back()->with('error', $message);
            }
            
            // Pastikan tidak sedang digunakan di session
            if (session('tahun_ajaran_id') == $id) {
                // Cari tahun ajaran aktif lain untuk diset ke session
                $newTahunAjaran = TahunAjaran::where('is_active', true)->first();
                
                if (!$newTahunAjaran) {
                    // Jika tidak ada yang aktif, ambil yang terbaru
                    $newTahunAjaran = TahunAjaran::orderBy('tanggal_mulai', 'desc')->first();
                }
                
                if ($newTahunAjaran) {
                    session(['tahun_ajaran_id' => $newTahunAjaran->id]);
                } else {
                    session()->forget('tahun_ajaran_id');
                }
            }
            
            $templateFilePaths = [];

            DB::beginTransaction();

            try {
                $ownedTemplates = ReportTemplate::where('tahun_ajaran_id', $tahunAjaran->id)->get();
                $templateFilePaths = $this->reportTemplateFilePaths($ownedTemplates);

                foreach ($ownedTemplates as $template) {
                    $template->delete();
                }

                $tahunAjaran->forceDelete();

                DB::commit();
            } catch (Throwable $exception) {
                DB::rollBack();

                throw $exception;
            }

            $templateFilesCleaned = $this->cleanupReportTemplateFilesAfterPermanentDelete($templateFilePaths, (int) $id);
            $this->clearTahunAjaranCaches($id);
            $message = $templateFilesCleaned
                ? self::PERMANENT_DELETE_SUCCESS_MESSAGE
                : self::PERMANENT_DELETE_TEMPLATE_CLEANUP_WARNING;

            if (request()->expectsJson()) {
                return response()->json([
                    'success' => true,
                    'message' => $message
                ]);
            }
            
            return redirect()->route('tahun.ajaran.index', ['showArchived' => 'true'])
                ->with('success', $message);
                
        } catch (\Exception $e) {
            \Log::error('Error saat menghapus permanen tahun ajaran: ' . $e->getMessage(), [
                'tahun_ajaran_id' => $id,
                'trace' => $e->getTraceAsString()
            ]);

            if (request()->expectsJson()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Terjadi kesalahan saat menghapus permanen tahun ajaran.'
                ], 500);
            }
            
            return redirect()->back()
                ->with('error', 'Terjadi kesalahan saat menghapus permanen tahun ajaran. Silakan coba lagi.');
        }
    }

    private function activeAcademicYearFlowDeleteBlockMessage(TahunAjaran $tahunAjaran): ?string
    {
        $activeCounterpart = TahunAjaran::query()
            ->where('tahun_ajaran', $tahunAjaran->tahun_ajaran)
            ->where('id', '!=', $tahunAjaran->id)
            ->where('is_active', true)
            ->first();

        if (! $activeCounterpart) {
            return null;
        }

        return sprintf(
            'Tahun ajaran ini tidak dapat dihapus permanen karena masih terhubung dengan alur akademik aktif semester %s. Gunakan arsip sebagai penyimpanan aman, atau pulihkan jika masih diperlukan.',
            $activeCounterpart->semester == 1 ? 'Ganjil' : 'Genap'
        );
    }

    private function permanentDeleteProtectionMessage(TahunAjaran $tahunAjaran): ?string
    {
        if (! $tahunAjaran->trashed()) {
            return null;
        }

        if ($this->activeAcademicYearFlowDeleteBlockMessage($tahunAjaran)) {
            return self::PERMANENT_DELETE_PROTECTED_NOTICE;
        }

        if ($this->permanentDeleteBlockingDependencies((int) $tahunAjaran->id) !== []) {
            return self::PERMANENT_DELETE_PROTECTED_NOTICE;
        }

        return null;
    }

    private function permanentDeleteProtectionMessagesFor($tahunAjarans): array
    {
        return $tahunAjarans
            ->filter(fn (TahunAjaran $tahunAjaran) => $tahunAjaran->trashed())
            ->mapWithKeys(function (TahunAjaran $tahunAjaran) {
                return [$tahunAjaran->id => $this->permanentDeleteProtectionMessage($tahunAjaran)];
            })
            ->filter()
            ->all();
    }

    private function permanentDeleteBlockingDependencies(int $tahunAjaranId): array
    {
        $dependencies = [
            'siswa_kelas_semester' => ['column' => 'tahun_ajaran_id', 'label' => 'enrollment siswa'],
            'kelas' => ['column' => 'tahun_ajaran_id', 'label' => 'kelas'],
            'mata_pelajarans' => ['column' => 'tahun_ajaran_id', 'label' => 'mata pelajaran'],
            'nilais' => ['column' => 'tahun_ajaran_id', 'label' => 'nilai'],
            'absensis' => ['column' => 'tahun_ajaran_id', 'label' => 'absensi'],
            'catatan_siswa' => ['column' => 'tahun_ajaran_id', 'label' => 'catatan siswa'],
            'catatan_mata_pelajaran' => ['column' => 'tahun_ajaran_id', 'label' => 'catatan mata pelajaran'],
            'capaian_custom' => ['column' => 'tahun_ajaran_id', 'label' => 'capaian kompetensi'],
            'nilai_ekstrakurikuler' => ['column' => 'tahun_ajaran_id', 'label' => 'nilai ekstrakurikuler'],
            'kkms' => ['column' => 'tahun_ajaran_id', 'label' => 'KKM'],
            'ekstrakurikulers' => ['column' => 'tahun_ajaran_id', 'label' => 'ekstrakurikuler'],
            'prestasis' => ['column' => 'tahun_ajaran_id', 'label' => 'prestasi'],
            'semester_snapshots' => ['column' => 'tahun_ajaran_id', 'label' => 'snapshot semester'],
            'siswas' => ['column' => 'tahun_ajaran_id', 'label' => 'siswa legacy'],
            'capaian_templates' => ['column' => 'tahun_ajaran_id', 'label' => 'template capaian'],
            'capaian_range' => ['column' => 'tahun_ajaran_id', 'label' => 'range capaian'],
        ];

        $blocking = [];

        foreach ($dependencies as $table => $dependency) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $dependency['column'])) {
                continue;
            }

            $count = DB::table($table)
                ->where($dependency['column'], $tahunAjaranId)
                ->count();

            if ($count > 0) {
                $blocking[] = "{$dependency['label']} ({$count})";
            }
        }

        $reportGenerationCount = $this->countPermanentDeleteBlockingReportGenerations($tahunAjaranId);
        if ($reportGenerationCount > 0) {
            $blocking[] = "riwayat rapor ({$reportGenerationCount})";
        }

        return $blocking;
    }

    private function countPermanentDeleteBlockingReportGenerations(int $tahunAjaranId): int
    {
        if (! Schema::hasTable('report_generations')) {
            return 0;
        }

        $templateIds = $this->reportTemplateIdsForTahunAjaran($tahunAjaranId);
        $hasTahunAjaranColumn = Schema::hasColumn('report_generations', 'tahun_ajaran_id');
        $hasTemplateColumn = Schema::hasColumn('report_generations', 'report_template_id');

        if (! $hasTahunAjaranColumn && (! $hasTemplateColumn || $templateIds === [])) {
            return 0;
        }

        $query = DB::table('report_generations')
            ->where(function ($query) use ($tahunAjaranId, $templateIds, $hasTahunAjaranColumn, $hasTemplateColumn) {
                $hasCondition = false;

                if ($hasTahunAjaranColumn) {
                    $query->where('tahun_ajaran_id', $tahunAjaranId);
                    $hasCondition = true;
                }

                if ($hasTemplateColumn && $templateIds !== []) {
                    $hasCondition
                        ? $query->orWhereIn('report_template_id', $templateIds)
                        : $query->whereIn('report_template_id', $templateIds);
                }
            });

        return (int) $query->distinct()->count('id');
    }

    private function reportTemplateIdsForTahunAjaran(int $tahunAjaranId): array
    {
        if (! Schema::hasTable('report_templates') || ! Schema::hasColumn('report_templates', 'tahun_ajaran_id')) {
            return [];
        }

        return ReportTemplate::where('tahun_ajaran_id', $tahunAjaranId)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->filter()
            ->values()
            ->all();
    }

    private function reportTemplateFilePaths($templates): array
    {
        return $templates
            ->map(fn (ReportTemplate $template) => $this->normalizeReportTemplateStoragePath($template->path))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    private function normalizeReportTemplateStoragePath(?string $path): ?string
    {
        $path = trim(str_replace('\\', '/', (string) $path));
        $path = ltrim($path, '/');

        return $path !== '' ? $path : null;
    }

    private function cleanupReportTemplateFilesAfterPermanentDelete(array $paths, int $tahunAjaranId): bool
    {
        $allFilesCleaned = true;
        $disk = Storage::disk('public');

        foreach ($paths as $path) {
            try {
                if (! is_string($path) || $path === '') {
                    continue;
                }

                if (ReportTemplate::where('path', $path)->exists()) {
                    continue;
                }

                if (! $disk->exists($path)) {
                    continue;
                }

                if (! $disk->delete($path)) {
                    $allFilesCleaned = false;

                    Log::warning('[TahunAjaranController] Failed to delete report template file after permanent delete.', [
                        'tahun_ajaran_id' => $tahunAjaranId,
                        'path' => $path,
                    ]);
                }
            } catch (Throwable $exception) {
                $allFilesCleaned = false;

                Log::warning('[TahunAjaranController] Report template file cleanup failed after permanent delete.', [
                    'tahun_ajaran_id' => $tahunAjaranId,
                    'path' => $path,
                    'error' => $exception->getMessage(),
                ]);
            }
        }

        return $allFilesCleaned;
    }
    
    /**
     * Menghapus tahun ajaran yang spesifik.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function destroy($id)
    {
        try {
            $tahunAjaran = TahunAjaran::findOrFail($id);
            
            // Cek apakah tahun ajaran sedang aktif
            if ($tahunAjaran->is_active) {
                return redirect()->back()
                    ->with('error', 'Tidak dapat mengarsipkan tahun ajaran yang sedang aktif. Aktifkan tahun ajaran lain terlebih dahulu.');
            }
            
            // Cek apakah ini adalah satu-satunya tahun ajaran
            $totalTahunAjaran = TahunAjaran::count();
            if ($totalTahunAjaran <= 1) {
                return redirect()->back()
                    ->with('error', 'Tidak dapat mengarsipkan tahun ajaran karena minimal harus ada satu tahun ajaran dalam sistem.');
            }
            
            // Check if the currently deleted item is the one in session
            if (session('tahun_ajaran_id') == $id) {
                // Find a new tahun ajaran to set in session
                $newTahunAjaran = TahunAjaran::where('id', '!=', $id)
                                            ->where('is_active', true)
                                            ->first();
                
                if (!$newTahunAjaran) {
                    $newTahunAjaran = TahunAjaran::where('id', '!=', $id)
                                                ->orderBy('tanggal_mulai', 'desc')
                                                ->first();
                }
                
                if ($newTahunAjaran) {
                    session(['tahun_ajaran_id' => $newTahunAjaran->id]);
                } else {
                    session()->forget('tahun_ajaran_id');
                }
            }
            
            // Soft delete tahun ajaran daripada menghapusnya permanen
            $tahunAjaran->delete();
            $this->clearTahunAjaranCaches($id);
            
            return redirect()->route('tahun.ajaran.index')
                ->with('success', 'Tahun ajaran berhasil diarsipkan. Data terkait masih dapat diakses dengan menampilkan tahun ajaran terarsip.');
                
        } catch (\Exception $e) {
            \Log::error('Error saat mengarsipkan tahun ajaran: ' . $e->getMessage(), [
                'tahun_ajaran_id' => $id,
                'trace' => $e->getTraceAsString()
            ]);
            
            return redirect()->back()
                ->with('error', 'Terjadi kesalahan saat mengarsipkan tahun ajaran: ' . $e->getMessage());
        }
    }


    /**
     * Generate tahun ajaran baru berdasarkan tahun ajaran yang sudah ada.
     * Hanya bisa dilakukan dari semester genap (semester 2)
     */
    public function copy($id)
    {
        $sourceTahunAjaran = TahunAjaran::withTrashed()->findOrFail($id);
        
        try {
            $this->assertNextYearCopySourceIsEligible($sourceTahunAjaran);
        } catch (DomainException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }
        
        // Generate tahun ajaran baru
        $tahunParts = explode('/', $sourceTahunAjaran->tahun_ajaran);
        $newTahunAjaran = (intval($tahunParts[0]) + 1) . '/' . (intval($tahunParts[1]) + 1);
        $nextYearReadiness = $this->buildTransitionReadiness($sourceTahunAjaran, 'next_year');
        
        return view('admin.tahun_ajaran.copy', compact('sourceTahunAjaran', 'newTahunAjaran', 'nextYearReadiness'));
    }

    private function findCopyConflictRecord(string $tahunAjaran, int $semester): ?TahunAjaran
    {
        return TahunAjaran::withTrashed()
            ->where('tahun_ajaran', $tahunAjaran)
            ->where('semester', $semester)
            ->first();
    }

    private function respondToCopyConflict(Request $request, TahunAjaran $existing, string $tahunAjaran)
    {
        $semesterLabel = (int) $existing->semester === 1 ? 'Ganjil' : 'Genap';

        if ($request->expectsJson()) {
            if ($existing->trashed()) {
                return response()->json([
                    'success' => false,
                    'conflict' => 'archived',
                    'message' => 'Tahun ajaran ' . $tahunAjaran . ' semester ' . $semesterLabel . ' sudah ada di arsip.',
                    'archived_id' => $existing->id
                ], 409);
            }

            return response()->json([
                'success' => false,
                'conflict' => 'active',
                'message' => 'Tahun ajaran ' . $tahunAjaran . ' semester ' . $semesterLabel . ' sudah ada dan belum diarsipkan.'
            ], 409);
        }

        $message = $existing->trashed()
            ? 'Tahun ajaran ' . $tahunAjaran . ' semester ' . $semesterLabel . ' sudah ada di arsip. Hapus dari arsip terlebih dahulu untuk melanjutkan copy.'
            : 'Tahun ajaran ' . $tahunAjaran . ' semester ' . $semesterLabel . ' sudah ada dan belum diarsipkan.';

        return redirect()->back()
            ->with('error', $message)
            ->withInput();
    }


    /**
     * Process copying data from one academic year to another.
     * Updated messages untuk konteks "tahun ajaran berikutnya"
     */


    public function processCopy(Request $request, $id)
    {
        $sourceTahunAjaran = TahunAjaran::withTrashed()->findOrFail($id);

        try {
            $this->assertNextYearCopySourceIsEligible($sourceTahunAjaran);
        } catch (DomainException $e) {
            return $this->rejectCopyRequest($request, $e->getMessage());
        }

        if (! $this->hasValidTransitionConfirmation($request, self::NEXT_YEAR_CONFIRMATION)) {
            return $this->rejectTransitionConfirmation($request);
        }

        $validator = Validator::make($request->all(), [
            'tahun_ajaran' => [
                'required',
                'string',
                'regex:/^\d{4}\/\d{4}$/',
                function ($attribute, $value, $fail) use ($sourceTahunAjaran) {
                    if (! preg_match('/^(\d{4})\/(\d{4})$/', $value, $targetMatches)) {
                        return;
                    }

                    $targetStart = (int) $targetMatches[1];
                    $targetEnd = (int) $targetMatches[2];

                    if ($targetEnd !== $targetStart + 1) {
                        $fail('Format tahun ajaran target harus berurutan, misalnya 2027/2028.');

                        return;
                    }

                    if (preg_match('/^(\d{4})\/(\d{4})$/', (string) $sourceTahunAjaran->tahun_ajaran, $sourceMatches)) {
                        $expectedStart = (int) $sourceMatches[1] + 1;
                        $expectedEnd = (int) $sourceMatches[2] + 1;

                        if ($targetStart !== $expectedStart || $targetEnd !== $expectedEnd) {
                            $fail('Tahun ajaran target harus tahun ajaran berikutnya dari sumber.');
                        }
                    }
                },
            ],
            'tanggal_mulai' => 'required|date',
            'tanggal_selesai' => 'required|date|after:tanggal_mulai',
            'semester' => 'required|integer|in:1',
            'copy_kelas' => 'boolean',
            'copy_mata_pelajaran' => 'boolean',
            'copy_templates' => 'boolean',
            'copy_ekstrakurikuler' => 'boolean',
            'copy_kkm' => 'boolean',
            'copy_bobot_nilai' => 'boolean',
            'transition_confirmation' => 'required|string',
            'is_active' => 'boolean'
        ]);

        if ($validator->fails()) {
            if ($request->expectsJson()) {
                return response()->json([
                    'success' => false,
                    'message' => $validator->errors()->first(),
                    'errors' => $validator->errors()
                ], 422);
            }

            return redirect()->back()
                            ->withErrors($validator)
                            ->withInput();
        }

        $copyKelas = $request->boolean('copy_kelas');
        $copyMataPelajaran = $request->boolean('copy_mata_pelajaran');
        $copyTemplates = $request->boolean('copy_templates');
        $copyKkm = $request->boolean('copy_kkm');
        $copyEkstrakurikuler = $request->boolean('copy_ekstrakurikuler');
        $copyBobotNilai = $request->boolean('copy_bobot_nilai');

        if ((! $copyKelas && ($copyMataPelajaran || $copyTemplates || $copyKkm)) || ($copyKkm && ! $copyMataPelajaran)) {
            $message = 'Copy mata pelajaran, template, dan KKM memerlukan copy kelas dan mata pelajaran yang sesuai.';

            if ($request->expectsJson()) {
                return response()->json([
                    'success' => false,
                    'message' => $message,
                ], 422);
            }

            return redirect()->back()
                            ->with('error', $message)
                            ->withInput();
        }

        $conflict = $this->findCopyConflictRecord($request->tahun_ajaran, (int) $request->semester);
        if ($conflict) {
            return $this->respondToCopyConflict($request, $conflict, $request->tahun_ajaran);
        }

        $copiedStoragePaths = [];

        DB::beginTransaction();
        
        try {
            $newTahunAjaran = TahunAjaran::create([
                'tahun_ajaran' => $request->tahun_ajaran,
                'is_active' => false,
                'tanggal_mulai' => $request->tanggal_mulai,
                'tanggal_selesai' => $request->tanggal_selesai,
                'semester' => $request->semester,
                'deskripsi' => $request->deskripsi ?? ('Tahun Ajaran ' . $request->tahun_ajaran)
            ]);

            $kelasMapping = [];
            $mapelMapping = [];
            
            // SIMPLIFIED: Copy kelas dengan struktur yang sama (tanpa increment)
            if ($copyKelas) {
                $kelasMapping = $this->copyKelasExact($sourceTahunAjaran, $newTahunAjaran);
            }

            // Copy data lainnya
            if ($copyMataPelajaran) {
                $mapelMapping = $this->copyMataPelajaran($sourceTahunAjaran, $newTahunAjaran, $request->semester, $kelasMapping);
            }

            if ($copyTemplates) {
                $this->copyReportTemplates($sourceTahunAjaran, $newTahunAjaran, $request->semester, $kelasMapping, $copiedStoragePaths);
            }
            
            if ($copyEkstrakurikuler) {
                $this->copyEkstrakurikuler($sourceTahunAjaran, $newTahunAjaran);
            }
            
            if ($copyKkm) {
                $this->copyKkm($sourceTahunAjaran, $newTahunAjaran, $kelasMapping, $mapelMapping);
            }
            
            if ($copyBobotNilai) {
                $this->copyBobotNilai($sourceTahunAjaran, $newTahunAjaran);
            }
            
            if ($request->boolean('is_active')) {
                TahunAjaran::where('is_active', true)
                        ->update(['is_active' => false]);

                $newTahunAjaran->forceFill(['is_active' => true])->save();
                $this->updateProfilSekolah($newTahunAjaran);
            }
            
            DB::commit();
            $this->clearTahunAjaranCaches($newTahunAjaran->id);

            if ($newTahunAjaran->is_active) {
                session([
                    'tahun_ajaran_id' => $newTahunAjaran->id,
                    'selected_semester' => 1,
                    'no_tahun_ajaran' => false,
                ]);
            }

            if ($request->expectsJson()) {
                return response()->json([
                    'success' => true,
                    'message' => 'Tahun ajaran berikutnya berhasil dibuat dengan struktur kelas yang sama!',
                    'redirect' => route('tahun.ajaran.index')
                ]);
            }
            
            return redirect()->route('tahun.ajaran.index')
                            ->with('success', 'Tahun ajaran berikutnya berhasil dibuat dengan struktur kelas yang sama!');
        } catch (\Exception $e) {
            DB::rollback();
            $this->cleanupCopiedNewYearFiles($copiedStoragePaths);
            \Log::error('Error in processCopy: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString()
            ]);

            if ($request->expectsJson()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Gagal membuat tahun ajaran berikutnya. Silakan coba lagi.'
                ], 500);
            }

            return redirect()->back()->with('error', 'Gagal membuat tahun ajaran berikutnya. Silakan coba lagi.');
        }
    }

    
    // Metode helper untuk menyalin kelas dengan opsi peningkatan nomor kelas
    private function copyKelas($sourceTahunAjaran, $newTahunAjaran, $incrementKelasNumbers = false, $preserveTeachers = false)
    {
        $sourceKelas = Kelas::where('tahun_ajaran_id', $sourceTahunAjaran->id)->get();
        $kelasMapping = [];
        
        // Group source classes by their grade number and name
        $sourceKelasGroups = $sourceKelas->groupBy(function($kelas) {
            return $kelas->nomor_kelas . '-' . $kelas->nama_kelas;
        });
        
        // Process each unique class (preventing duplicates)
        foreach ($sourceKelasGroups as $classKey => $classGroup) {
            // Take the first class from each group as our reference
            $kelas = $classGroup->first();
            
            $newKelas = $kelas->replicate();
            $newKelas->tahun_ajaran_id = $newTahunAjaran->id;
            
            // Tingkatkan nomor kelas jika diminta
            if ($incrementKelasNumbers) {
                $newKelas->nomor_kelas = $kelas->nomor_kelas + 1;
                
                // Skip kelas yang nomor kelasnya melebihi 6 (untuk SD)
                if ($newKelas->nomor_kelas > 6) {
                    continue;
                }
            }
            
            $newKelas->save();
            
            // Map ALL classes from this group to the new class
            foreach ($classGroup as $sourceClass) {
                $kelasMapping[$sourceClass->id] = $newKelas->id;
            }
            
            // Only copy teacher relationships if NOT preserving teachers
            // This is the critical change
            if (!$preserveTeachers) {
                // Get all teacher relationships for this class group
                $teacherIds = [];
                foreach ($classGroup as $sourceClass) {
                    $guruRelations = DB::table('guru_kelas')
                        ->where('kelas_id', $sourceClass->id)
                        ->get();
                        
                    foreach ($guruRelations as $relation) {
                        // Create a unique key to prevent duplicate teacher assignments
                        $relationKey = $relation->guru_id . '-' . $relation->is_wali_kelas . '-' . $relation->role;
                        
                        if (!isset($teacherIds[$relationKey])) {
                            $teacherIds[$relationKey] = $relation;
                        }
                    }
                }
                
                // Add all unique teacher relationships to the new class
                foreach ($teacherIds as $relation) {
                    DB::table('guru_kelas')->insert([
                        'guru_id' => $relation->guru_id,
                        'kelas_id' => $newKelas->id,
                        'is_wali_kelas' => $relation->is_wali_kelas,
                        'role' => $relation->role,
                        'created_at' => now(),
                        'updated_at' => now()
                    ]);
                }
            }
        }
        
        return $kelasMapping;
    }
    
        
    // Metode baru untuk menyalin KKM
    private function copyKkm($sourceTahunAjaran, $newTahunAjaran, $kelasMapping = [], $mapelMapping = [])
    {
        if (empty($kelasMapping)) {
            return;
        }
        
        $kkms = \App\Models\Kkm::where('tahun_ajaran_id', $sourceTahunAjaran->id)->get();
        
        foreach ($kkms as $kkm) {
            // Hanya salin jika kelas ada dalam mapping
            if (isset($kelasMapping[$kkm->kelas_id])) {
                $newKkm = $kkm->replicate();
                $newKkm->tahun_ajaran_id = $newTahunAjaran->id;
                $newKkm->kelas_id = $kelasMapping[$kkm->kelas_id];

                if ($kkm->mata_pelajaran_id) {
                    if (! isset($mapelMapping[$kkm->mata_pelajaran_id])) {
                        Log::warning('Skipping KKM copy because copied subject mapping is missing.', [
                            'kkm_id' => $kkm->id,
                            'source_mata_pelajaran_id' => $kkm->mata_pelajaran_id,
                            'source_tahun_ajaran_id' => $sourceTahunAjaran->id,
                            'target_tahun_ajaran_id' => $newTahunAjaran->id,
                        ]);

                        continue;
                    }

                    $newKkm->mata_pelajaran_id = $mapelMapping[$kkm->mata_pelajaran_id];
                }

                $newKkm->save();
            }
        }
    }
    
    // Metode baru untuk menyalin Bobot Nilai
    private function copyBobotNilai($sourceTahunAjaran, $newTahunAjaran)
    {
        $bobotNilai = \App\Models\BobotNilai::where('tahun_ajaran_id', $sourceTahunAjaran->id)->first();
        
        if ($bobotNilai) {
            $newBobotNilai = $bobotNilai->replicate();
            $newBobotNilai->tahun_ajaran_id = $newTahunAjaran->id;
            $newBobotNilai->save();
        }
    }
    
    // Metode baru untuk menyalin Ekstrakurikuler
    private function copyEkstrakurikuler($sourceTahunAjaran, $newTahunAjaran)
    {
        $ekstrakurikulers = \App\Models\Ekstrakurikuler::where('tahun_ajaran_id', $sourceTahunAjaran->id)->get();
        
        foreach ($ekstrakurikulers as $ekskul) {
            $newEkskul = $ekskul->replicate();
            $newEkskul->tahun_ajaran_id = $newTahunAjaran->id;
            $newEkskul->save();
        }
    }

    
    private function copyKelasExact($sourceTahunAjaran, $newTahunAjaran)
    {
        $sourceKelas = Kelas::where('tahun_ajaran_id', $sourceTahunAjaran->id)
            ->orderBy('nomor_kelas')
            ->orderBy('nama_kelas')
            ->get();
        
        $kelasMapping = [];
        
        \Log::info("Starting copyKelasExact", [
            'source_classes_count' => $sourceKelas->count(),
            'source_tahun_ajaran' => $sourceTahunAjaran->tahun_ajaran,
            'target_tahun_ajaran' => $newTahunAjaran->tahun_ajaran
        ]);
        
        foreach ($sourceKelas as $kelas) {
            // Copy kelas dengan struktur yang sama persis
            $newKelas = $kelas->replicate();
            $newKelas->tahun_ajaran_id = $newTahunAjaran->id;
            $newKelas->save();
            
            $kelasMapping[$kelas->id] = $newKelas->id;
            
            \Log::info("Created exact copy of class", [
                'class' => "Kelas {$kelas->nomor_kelas}{$kelas->nama_kelas}",
                'old_id' => $kelas->id,
                'new_id' => $newKelas->id
            ]);
            
            // Copy teacher assignments (guru tetap sama di kelas yang sama)
            $this->copyTeacherAssignments($kelas->id, $newKelas->id);
        }
        
        \Log::info("Completed copyKelasExact", [
            'total_classes_created' => count($kelasMapping),
            'mapping' => $kelasMapping
        ]);
        
        return $kelasMapping;
    }

    /**
     * SIMPLIFIED: Copy teacher assignments dari satu kelas ke kelas lain
     */
    private function copyTeacherAssignments($sourceKelasId, $targetKelasId)
    {
        $guruRelations = DB::table('guru_kelas')
            ->where('kelas_id', $sourceKelasId)
            ->get();
            
        \Log::info("Copying teacher assignments", [
            'source_kelas_id' => $sourceKelasId,
            'target_kelas_id' => $targetKelasId,
            'teacher_count' => $guruRelations->count()
        ]);
        
        foreach ($guruRelations as $relation) {
            // Check if assignment already exists to prevent duplicates
            $exists = DB::table('guru_kelas')
                ->where('guru_id', $relation->guru_id)
                ->where('kelas_id', $targetKelasId)
                ->where('role', $relation->role)
                ->exists();
                
            if (!$exists) {
                DB::table('guru_kelas')->insert([
                    'guru_id' => $relation->guru_id,
                    'kelas_id' => $targetKelasId,
                    'is_wali_kelas' => $relation->is_wali_kelas,
                    'role' => $relation->role,
                    'created_at' => now(),
                    'updated_at' => now()
                ]);
                
                \Log::info("Copied teacher assignment", [
                    'guru_id' => $relation->guru_id,
                    'target_kelas_id' => $targetKelasId,
                    'role' => $relation->role,
                    'is_wali_kelas' => $relation->is_wali_kelas ? 'YES' : 'NO'
                ]);
            } else {
                \Log::info("Teacher assignment already exists, skipping", [
                    'guru_id' => $relation->guru_id,
                    'target_kelas_id' => $targetKelasId,
                    'role' => $relation->role
                ]);
            }
        }
    }

    /**
     * Helper method untuk copy mata pelajaran dari satu tahun ajaran ke tahun ajaran lain.
     */
    private function copyMataPelajaran($sourceTahunAjaran, $newTahunAjaran, $newSemester = null, $kelasMapping = [])
    {
        $sourceMataPelajaran = MataPelajaran::where('tahun_ajaran_id', $sourceTahunAjaran->id)->get();
        $mapelMapping = [];
        
        \Log::info("Starting copyMataPelajaran", [
            'source_mapel_count' => $sourceMataPelajaran->count(),
            'kelas_mapping_count' => count($kelasMapping)
        ]);
        
        foreach ($sourceMataPelajaran as $mapel) {
            if ($mapel->kelas_id && ! isset($kelasMapping[$mapel->kelas_id])) {
                Log::warning('Skipping subject copy because copied class mapping is missing.', [
                    'mata_pelajaran_id' => $mapel->id,
                    'source_kelas_id' => $mapel->kelas_id,
                    'source_tahun_ajaran_id' => $sourceTahunAjaran->id,
                    'target_tahun_ajaran_id' => $newTahunAjaran->id,
                ]);

                continue;
            }

            $newMapel = $mapel->replicate();
            $newMapel->tahun_ajaran_id = $newTahunAjaran->id;
            
            // Set semester baru jika disediakan
            if ($newSemester !== null && Schema::hasColumn('mata_pelajarans', 'semester')) {
                $newMapel->semester = $newSemester;
            }
            
            if ($mapel->kelas_id) {
                $newMapel->kelas_id = $kelasMapping[$mapel->kelas_id];
            }
            
            // Guru tetap sama (tidak perlu update guru_id)
            $newMapel->save();
            $mapelMapping[$mapel->id] = $newMapel->id;
            
            \Log::info("Created new mata pelajaran", [
                'original_id' => $mapel->id,
                'new_id' => $newMapel->id,
                'nama_pelajaran' => $mapel->nama_pelajaran,
                'guru_id' => $newMapel->guru_id,
                'kelas_id' => $newMapel->kelas_id
            ]);
            
            // Copy lingkup materi dan tujuan pembelajaran
            foreach ($mapel->lingkupMateris as $lm) {
                $newLM = $lm->replicate();
                $newLM->mata_pelajaran_id = $newMapel->id;
                $newLM->save();
                
                foreach ($lm->tujuanPembelajarans as $tp) {
                    $newTP = $tp->replicate();
                    $newTP->lingkup_materi_id = $newLM->id;
                    $newTP->save();
                }
            }
        }

        return $mapelMapping;
    }

    /**
     * Helper method untuk copy template rapor dari satu tahun ajaran ke tahun ajaran lain.
     */
    private function copyReportTemplates($sourceTahunAjaran, $newTahunAjaran, $newSemester = null, $kelasMapping = [], array &$copiedStoragePaths = [])
    {
        $sourceTemplates = ReportTemplate::where('tahun_ajaran_id', $sourceTahunAjaran->id)->get();
        
        foreach ($sourceTemplates as $template) {
            $newPath = $this->copyReportTemplateFileForNewYear($template, $newTahunAjaran, $copiedStoragePaths);
            
            $newTemplate = $template->replicate();
            $newTemplate->tahun_ajaran_id = $newTahunAjaran->id;
            $newTemplate->tahun_ajaran_text = $newTahunAjaran->tahun_ajaran;
            $newTemplate->path = $newPath;
            $newTemplate->is_active = false; // Default tidak aktif
            
            // Set semester baru jika disediakan dan kolom semester ada
            if ($newSemester !== null && Schema::hasColumn('report_templates', 'semester')) {
                $newTemplate->semester = $newSemester;
            }
            
            if ($template->kelas_id) {
                if (isset($kelasMapping[$template->kelas_id])) {
                    $newTemplate->kelas_id = $kelasMapping[$template->kelas_id];
                } else {
                    Log::warning('Clearing copied report template class because copied class mapping is missing.', [
                        'report_template_id' => $template->id,
                        'source_kelas_id' => $template->kelas_id,
                        'source_tahun_ajaran_id' => $sourceTahunAjaran->id,
                        'target_tahun_ajaran_id' => $newTahunAjaran->id,
                    ]);

                    $newTemplate->kelas_id = null;
                }
            }
            
            $newTemplate->save();

            if (Schema::hasTable('report_template_kelas')) {
                $templateClassIds = DB::table('report_template_kelas')
                    ->where('report_template_id', $template->id)
                    ->pluck('kelas_id');

                foreach ($templateClassIds as $templateClassId) {
                    if (isset($kelasMapping[$templateClassId])) {
                        DB::table('report_template_kelas')->insert([
                            'report_template_id' => $newTemplate->id,
                            'kelas_id' => $kelasMapping[$templateClassId],
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]);
                    }
                }
            }
            
            // Copy mappings jika ada
            foreach ($template->mappings as $mapping) {
                $newMapping = $mapping->replicate();
                $newMapping->report_template_id = $newTemplate->id;
                $newMapping->save();
            }
        }
    }

    private function copyReportTemplateFileForNewYear(ReportTemplate $template, TahunAjaran $newTahunAjaran, array &$copiedStoragePaths): ?string
    {
        if (! $template->path) {
            return $template->path;
        }

        $sourcePath = 'public/' . $template->path;

        if (! Storage::exists($sourcePath)) {
            Log::warning('Report template file missing during new academic year copy; copied template metadata will reuse existing path.', [
                'report_template_id' => $template->id,
                'path' => $template->path,
            ]);

            return $template->path;
        }

        $safeYearLabel = str_replace(['/', '\\'], '-', $newTahunAjaran->tahun_ajaran);
        $newPath = str_replace(
            basename($template->path),
            'copy_' . $safeYearLabel . '_' . basename($template->path),
            $template->path
        );
        $targetPath = 'public/' . $newPath;

        if (! Storage::copy($sourcePath, $targetPath)) {
            throw new RuntimeException("Failed to copy report template file for template {$template->id}.");
        }

        $copiedStoragePaths[] = $targetPath;

        return $newPath;
    }

    private function cleanupCopiedNewYearFiles(array $copiedStoragePaths): void
    {
        foreach ($copiedStoragePaths as $path) {
            try {
                if (Storage::exists($path)) {
                    Storage::delete($path);
                }
            } catch (Throwable $exception) {
                Log::warning('Failed to clean up copied report template file after new academic year copy rollback.', [
                    'path' => $path,
                    'error' => $exception->getMessage(),
                ]);
            }
        }
    }

    public function setSessionTahunAjaran($id)
    {
        try {
            $tahunAjaran = TahunAjaran::findOrFail($id);
            
            // Set session untuk digunakan di seluruh aplikasi
            session(['tahun_ajaran_id' => $id]);
            
            return redirect()->back()->with('success', 'Tampilan data diubah ke tahun ajaran ' . $tahunAjaran->tahun_ajaran);
        } catch (\Exception $e) {
            Log::error('[TahunAjaranController] Change session tahun ajaran failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'user_id' => auth()->id(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
            return redirect()->back()->with('error', 'Gagal memproses tahun ajaran. Silakan coba lagi.');
        }
    }

    public function restore($id)
    {
        try {
            $tahunAjaran = TahunAjaran::withTrashed()->findOrFail($id);
            
            if (!$tahunAjaran->trashed()) {
                return redirect()->back()
                    ->with('error', 'Tahun ajaran ini tidak dalam status diarsipkan.');
            }
            
            $sameSemesterExists = TahunAjaran::where('tahun_ajaran', $tahunAjaran->tahun_ajaran)
                ->where('semester', $tahunAjaran->semester)
                ->where('id', '!=', $id)
                ->exists();

            if ($sameSemesterExists) {
                return redirect()->back()
                    ->with('error', 'Tidak dapat memulihkan tahun ajaran ini karena semester yang sama sudah ada. Periksa daftar tahun ajaran sebelum memulihkan.');
            }

            if ($tahunAjaran->is_active && TahunAjaran::where('is_active', true)->where('id', '!=', $id)->exists()) {
                return redirect()->back()
                    ->with('error', 'Tidak dapat memulihkan tahun ajaran ini sebagai aktif karena sudah ada tahun ajaran aktif lain. Nonaktifkan salah satunya terlebih dahulu.');
            }
            
            $tahunAjaran->restore();
            $this->clearTahunAjaranCaches($tahunAjaran->id);
            
            return redirect()->route('tahun.ajaran.index', ['showArchived' => true])
                ->with('success', 'Tahun ajaran ' . $tahunAjaran->tahun_ajaran . ' berhasil dipulihkan!');
                    
        } catch (\Exception $e) {
            \Log::error('Error saat memulihkan tahun ajaran: ' . $e->getMessage(), [
                'tahun_ajaran_id' => $id,
                'trace' => $e->getTraceAsString()
            ]);
            
            return redirect()->back()
                ->with('error', 'Terjadi kesalahan saat memulihkan tahun ajaran: ' . $e->getMessage());
        }
    }

    private function clearTahunAjaranCaches(?int $tahunAjaranId = null): void
    {
        Cache::forget('active_tahun_ajaran');
        Cache::forget('latest_tahun_ajaran');
        Cache::forget('all_tahun_ajaran_selector');
        Cache::forget('all_tahun_ajaran_selector_archived');

        if ($tahunAjaranId) {
            Cache::forget("tahun_ajaran_{$tahunAjaranId}");
        }
    }
}

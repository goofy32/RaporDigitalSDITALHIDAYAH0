<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Traits\RequiresTahunAjaran;
use App\Models\ReportTemplate;
use App\Models\ReportPlaceholder;
use App\Models\Siswa;
use App\Models\Kelas;
use App\Models\MataPelajaran;
use App\Models\Notification;
use App\Services\RaporTemplateProcessor;
use Illuminate\Support\Facades\Storage;
use App\Models\ProfilSekolah;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Barryvdh\DomPDF\Facade\PDF;
use App\Models\ReportGeneration;
use App\Models\TahunAjaran;
use App\Jobs\GeneratePdfReportJob;
use App\Services\DocumentConversionService;
use App\Services\PdfCacheService;
use App\Services\ReportPdfAutoPrepareService;
use App\Services\ReportPeriodService;
use App\Services\ReportTemplateDocxValidationService;
use App\Services\SiswaKelasSemesterResolver;
use App\Services\ReportPerformanceTracker;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class ReportController extends Controller
{
    use RequiresTahunAjaran;

    // Modify the index method to pass school profile to the view
    public function index()
    {
        $tahunAjaranId = session('tahun_ajaran_id');
        $openedReportType = app(ReportPeriodService::class)->openedType(null, $tahunAjaranId ? (int) $tahunAjaranId : null);
        
        $templates = ReportTemplate::when($tahunAjaranId, function($query) use ($tahunAjaranId) {
                return $query->where('tahun_ajaran_id', $tahunAjaranId);
            })
            ->with('kelas')
            ->orderBy('created_at', 'desc')
            ->get();
        
        $schoolProfile = \App\Models\ProfilSekolah::first();
        
        return view('admin.report.index', compact('templates', 'schoolProfile', 'openedReportType'));
    }

    public function updateOpenedReportPeriod(Request $request)
    {
        $validated = $request->validate([
            'opened_report_type' => 'required|in:UTS,UAS',
        ]);

        app(ReportPeriodService::class)->setOpenedType($validated['opened_report_type']);

        return redirect()
            ->route('report.template.index')
            ->with('success', 'Jenis rapor yang dibuka untuk Wali Kelas berhasil diperbarui.');
    }
    // Modify the upload method to use school profile data
    /**
     * Upload a new template and validate placeholders.
     *
     * @param Request $request
     * @return \Illuminate\Http\Response
     */
    public function upload(Request $request)
    {
        Log::info('Report template upload request', [
            'has_file' => $request->hasFile('template'),
            'files' => array_keys($request->allFiles()),
            'fields' => array_keys($request->except(['template'])),
            'content_type' => $request->header('Content-Type'),
        ]);

        // Validasi permintaan
        $validator = \Illuminate\Support\Facades\Validator::make($request->all(), [
            'template' => [
                'required',
                'file',
                'mimes:docx',
                'mimetypes:application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                'max:10240',
            ],
            'type' => 'required|in:UTS,UAS',
            'kelas_ids' => 'nullable|array',
            'kelas_ids.*' => 'exists:kelas,id',
            'tahun_ajaran' => 'required',
            'semester' => 'required|in:1,2'
        ]);
    
        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validasi gagal: ' . implode(', ', $validator->errors()->all())
            ], 422);
        }
    
        try {
            // Proses upload file
            $file = $request->file('template');
            if (!$file || !$file->isValid()) {
                return response()->json([
                    'success' => false,
                    'message' => 'File template tidak valid atau gagal diupload.'
                ], 422);
            }

            $originalName = pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME);
            $safeName = Str::slug($originalName ?: 'template-rapor');
            $extension = $file->getClientOriginalExtension() ?: 'docx';
            $fileName = time() . '_' . $safeName . '.' . $extension;
            Storage::disk('public')->makeDirectory('templates');

            $destinationDirectory = Storage::disk('public')->path('templates');
            $file->move($destinationDirectory, $fileName);
            $filePath = 'templates/' . $fileName;

            if (!Storage::disk('public')->exists($filePath)) {
                throw new \RuntimeException('Gagal menyimpan file template ke storage.');
            }
            
            // Validasi placeholder dalam template
            $templatePath = Storage::disk('public')->path($filePath);
            if ($typeValidationMessage = app(ReportTemplateDocxValidationService::class)->validateTypeFromDocxText($templatePath, $request->type)) {
                \Storage::disk('public')->delete($filePath);

                return response()->json([
                    'success' => false,
                    'message' => $typeValidationMessage,
                ], 422);
            }

            $templateProcessor = new \PhpOffice\PhpWord\TemplateProcessor($templatePath);
            $variables = $templateProcessor->getVariables();
            
            // Dapatkan placeholder wajib berdasarkan tipe
            $requiredPlaceholders = \App\Models\ReportPlaceholder::where('is_required', true)
                ->where(function($query) use ($request) {
                    if ($request->type === 'UTS') {
                        $query->where('category', '!=', 'uas_only');
                    } else {
                        $query->where('category', '!=', 'uts_only');
                    }
                })
                ->pluck('placeholder_key')
                ->toArray();
            
            // Periksa apakah semua placeholder wajib ada
            $missingPlaceholders = array_diff($requiredPlaceholders, $variables);
            if (count($missingPlaceholders) > 0) {
                // Hapus file yang sudah diupload
                \Storage::disk('public')->delete($filePath);
                
                return response()->json([
                    'success' => false,
                    'message' => 'Template tidak valid. Placeholder wajib yang tidak ditemukan: ' . implode(', ', $missingPlaceholders)
                ], 422);
            }
            
            // Buat template utama
            $template = ReportTemplate::create([
                'filename' => $fileName,
                'path' => $filePath,
                'type' => $request->type,
                'kelas_id' => null, // Kosongkan kelas_id karena kita akan menggunakan relasi many-to-many
                'is_active' => false, 
                'tahun_ajaran' => $request->tahun_ajaran,
                'semester' => $request->semester,
                'tahun_ajaran_id' => $request->tahun_ajaran_id
            ]);
    
            // Tambahkan relasi ke kelas yang dipilih
            $kelasIds = $request->input('kelas_ids', []);
            if (!empty($kelasIds)) {
                $template->kelasList()->attach($kelasIds);
            }
    
            return response()->json([
                'success' => true,
                'message' => 'Template berhasil diunggah' . (!empty($kelasIds) ? ' untuk ' . count($kelasIds) . ' kelas' : ''),
                'template' => $template
            ]);
        } catch (\Exception $e) {
            // Jika terjadi error saat memproses template, hapus file yang sudah diupload
            if (isset($filePath) && \Storage::disk('public')->exists($filePath)) {
                \Storage::disk('public')->delete($filePath);
            }
            
            \Log::error('Error uploading template: ' . $e->getMessage());
            \Log::error($e->getTraceAsString());
            
            return response()->json([
                'success' => false,
                'message' => 'Gagal mengupload template: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Tampilkan halaman cetak rapor HTML untuk wali kelas
     * 
     * @param Siswa $siswa
     * @param Request $request
     * @return \Illuminate\View\View
     */
    public function printRaporHtml(Siswa $siswa, Request $request)
    {
        $type = $this->normalizeReportType($request->query('type', 'UTS'));
        $performance = ReportPerformanceTracker::startFlowIfEnabled(
            'html_print',
            $type,
            $request->route()?->getName()
        );

        try {
            $tahunAjaranId = ReportPerformanceTracker::measureSegment('context', function () use ($request) {
                return $this->resolveRaporTahunAjaranId($request);
            });
            abort_unless($tahunAjaranId, 403);
            $type = $this->resolveReportTypeForRequest($request, $tahunAjaranId);

            ReportPerformanceTracker::measureSegment('authorization', function () use ($siswa, $tahunAjaranId) {
                $this->authorizeWaliRaporAccess($siswa, $tahunAjaranId);
            });

            $guru = auth()->guard('guru')->user();
            [$tahunAjaran, $kelas] = ReportPerformanceTracker::measureSegment('context', function () use ($siswa, $tahunAjaranId) {
                return [
                    TahunAjaran::find($tahunAjaranId),
                    $this->applyRaporClassContext($siswa, $tahunAjaranId),
                ];
            });
            $semester = $tahunAjaran ? $tahunAjaran->semester : 1;
            abort_unless($kelas, 403);

            if ($response = $this->ensureReportPeriodOpened($request, $type, $tahunAjaranId)) {
                return $response;
            }

            ReportPerformanceTracker::measureSegment('preload', function () use ($siswa, $tahunAjaranId, $semester) {
                $siswa->load([
                    'nilais' => function($query) use ($tahunAjaranId, $semester) {
                        $query->where('tahun_ajaran_id', $tahunAjaranId)
                            ->whereHas('mataPelajaran', function($q) use ($semester) {
                                $q->where('semester', $semester);
                            })
                            ->where('is_submitted', true);
                    },
                    'nilais.mataPelajaran',
                    'nilaiEkstrakurikuler' => function($query) use ($tahunAjaranId, $semester) {
                        $query->where('tahun_ajaran_id', $tahunAjaranId)
                            ->where('semester', $semester);
                    },
                    'nilaiEkstrakurikuler.ekstrakurikuler',
                    'absensi' => function($query) use ($tahunAjaranId, $semester) {
                        $query->where('semester', $semester)
                            ->where('tahun_ajaran_id', $tahunAjaranId);
                    }
                ]);
            });

            $profilSekolah = ReportPerformanceTracker::measureSegment('preload', fn () => ProfilSekolah::first());
            $waliKelas = $guru;

            if ($siswa->nilais->isEmpty()) {
                return redirect()->back()
                    ->with('error', 'Data nilai siswa belum lengkap. Pastikan semua nilai sudah diinput untuk semester ' . $semester);
            }

            if (!$siswa->absensi) {
                return redirect()->back()
                    ->with('error', 'Data absensi siswa belum diinput untuk semester ' . $semester);
            }

            return ReportPerformanceTracker::measureSegment('response', function () use (
                $siswa,
                $tahunAjaran,
                $profilSekolah,
                $waliKelas,
                $semester,
                $kelas,
                $tahunAjaranId
            ) {
                return view('wali_kelas.rapor.print_html', compact(
                    'siswa',
                    'tahunAjaran',
                    'profilSekolah',
                    'waliKelas',
                    'semester',
                    'kelas',
                    'tahunAjaranId'
                ));
            });
        } finally {
            ReportPerformanceTracker::finishIfEnabled($performance);
        }
    }

    /**
     * Tampilkan daftar siswa untuk cetak rapor HTML
     * 
     * @return \Illuminate\View\View
     */
    public function indexPrintRapor()
    {
        $guru = auth()->guard('guru')->user();
        $tahunAjaranId = session('tahun_ajaran_id');
        
        if (!$guru || !$guru->isWaliKelas()) {
            abort(403, 'Hanya wali kelas yang dapat mengakses halaman ini');
        }
        
        $kelas = DB::table('guru_kelas')
            ->join('kelas', 'guru_kelas.kelas_id', '=', 'kelas.id')
            ->where('guru_kelas.guru_id', $guru->id)
            ->where('guru_kelas.is_wali_kelas', true)
            ->where('guru_kelas.role', 'wali_kelas')
            ->where('kelas.tahun_ajaran_id', $tahunAjaranId)
            ->select('kelas.*')
            ->first();
        
        if (!$kelas) {
            return redirect()->back()
                ->with('error', 'Anda tidak menjadi wali kelas untuk tahun ajaran yang dipilih.');
        }
        
        $tahunAjaran = TahunAjaran::find($tahunAjaranId);
        $semester = $tahunAjaran ? $tahunAjaran->semester : 1;

        $kelasModel = Kelas::with([
                'mataPelajarans' => function ($query) use ($tahunAjaranId, $semester) {
                    $query->where('semester', $semester)
                        ->when($tahunAjaranId, function ($subQuery) use ($tahunAjaranId) {
                            return $subQuery->where('tahun_ajaran_id', $tahunAjaranId);
                        });
                },
            ])->find($kelas->id);

        $siswa = app(SiswaKelasSemesterResolver::class)
            ->studentQueryForClass((int) $kelas->id, (int) $tahunAjaranId, (int) $semester, true)
            ->with([
                'nilais' => function ($query) use ($tahunAjaranId, $semester) {
                    $query->where('tahun_ajaran_id', $tahunAjaranId)
                        ->whereHas('mataPelajaran', function ($subQuery) use ($semester) {
                            $subQuery->where('semester', $semester);
                        })
                        ->where('is_submitted', true);
                },
                'nilais.mataPelajaran',
                'absensi' => function ($query) use ($tahunAjaranId, $semester) {
                    $query->where('semester', $semester)
                        ->where('tahun_ajaran_id', $tahunAjaranId);
                },
            ])
            ->orderBy('nama')
            ->get();

        $siswa->each(function (Siswa $student) use ($kelasModel) {
            if ($kelasModel) {
                $student->setRelation('kelas', $kelasModel);
            }
        });
        
        $diagnosisResults = [];
        foreach ($siswa as $s) {
            $diagnosisResults[$s->id] = $s->diagnoseDataCompleteness('UTS');
        }
        
        return view('wali_kelas.rapor.index_print', compact(
            'siswa',
            'kelas', 
            'diagnosisResults',
            'tahunAjaran'
        ));
    }

    public function tutorialView()
    {
        return view('admin.report.tutorial');
    }

    public function checkActiveTemplates(Request $request)
    {
        $guru = auth()->guard('guru')->user();
        $tahunAjaranId = $this->getValidTahunAjaranId(
            $request->query('tahun_ajaran_id') ? (int) $request->query('tahun_ajaran_id') : null
        );

        $kelasId = $guru && $tahunAjaranId
            ? DB::table('guru_kelas')
                ->join('kelas', 'guru_kelas.kelas_id', '=', 'kelas.id')
                ->where('guru_kelas.guru_id', $guru->id)
                ->where('guru_kelas.is_wali_kelas', true)
                ->where('guru_kelas.role', 'wali_kelas')
                ->where('kelas.tahun_ajaran_id', $tahunAjaranId)
                ->value('kelas.id')
            : null;
        
        if (!$kelasId) {
            return response()->json([
                'UTS_active' => false,
                'UAS_active' => false,
                'opened_report_type' => 'UTS',
                'error' => 'Tidak ditemukan kelas yang diwalikan'
            ]);
        }

        $openedReportType = $this->openedReportTypeForYear((int) $tahunAjaranId);
        
        // Cek template aktif untuk UTS
        $utsTemplate = $this->getTemplateStatus('UTS', $kelasId, $tahunAjaranId);
            
        // Cek template aktif untuk UAS
        $uasTemplate = $this->getTemplateStatus('UAS', $kelasId, $tahunAjaranId);
        
        return response()->json([
            'UTS_active' => $openedReportType === 'UTS' && $utsTemplate,
            'UAS_active' => $openedReportType === 'UAS' && $uasTemplate,
            'UTS_template_active' => $utsTemplate,
            'UAS_template_active' => $uasTemplate,
            'opened_report_type' => $openedReportType,
            'UTS_message' => $openedReportType === 'UTS' ? null : app(ReportPeriodService::class)->unopenedMessage('UTS'),
            'UAS_message' => $openedReportType === 'UAS' ? null : app(ReportPeriodService::class)->unopenedMessage('UAS'),
        ]);
    }

    protected function getTemplateStatus($type, $kelasId, $tahunAjaranId = null)
    {
        return (bool) $this->findActiveReportTemplateForContext($type, $kelasId, $tahunAjaranId);
    }
    /**
     * Menampilkan history rapor
     * 
     * @return \Illuminate\View\View
     */
    public function history()
    {
        $tahunAjaranId = session('tahun_ajaran_id');
        
        // Ambil data history rapor dari tabel report_generations
        $reports = ReportGeneration::with(['siswa', 'kelas', 'generator', 'template.kelas'])
            ->when($tahunAjaranId, function($query) use ($tahunAjaranId) {
                return $query->where('tahun_ajaran_id', $tahunAjaranId);
            })
            ->orderBy('created_at', 'desc')
            ->paginate(10);
            
        return view('admin.report.history', compact('reports'));
    }
    /**
     * Download rapor dari history
     * 
     * @param ReportGeneration $report
     * @return \Illuminate\Http\Response
     */
    public function downloadHistory(ReportGeneration $report)
    {
        $path = storage_path('app/public/' . $report->generated_file);
        
        if (!file_exists($path)) {
            return redirect()->back()->with('error', 'File rapor tidak ditemukan.');
        }
        
        $studentIdentifier = $report->siswa?->nis ?: 'siswa-' . $report->siswa_id;
        $fileName = 'rapor_' . $studentIdentifier . '_' . $report->type . '.docx';
        
        return response()->download($path, $fileName);
    }

    public function destroyHistory(ReportGeneration $report)
    {
        try {
            if ($report->generated_file && Storage::disk('public')->exists($report->generated_file)) {
                Storage::disk('public')->delete($report->generated_file);
            }

            $report->delete();

            return response()->json([
                'success' => true,
                'message' => 'Riwayat berhasil dihapus',
            ]);
        } catch (\Exception $e) {
            Log::error('Error deleting report history', [
                'report_id' => $report->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Gagal menghapus riwayat rapor.',
            ], 500);
        }
    }
    /**
     * Buat template sampel dinamis dengan placeholder terbaru
     * 
     * @param string $outputPath Path untuk menyimpan file
     * @param string $type Tipe template (UTS/UAS)
     * @return bool
     */
    protected function createDynamicSampleTemplate($outputPath, $type = 'UTS')
    {
        try {
            // Create a new Word document
            $phpWord = new \PhpOffice\PhpWord\PhpWord();
            
            // Add styles
            $phpWord->addTitleStyle(1, ['bold' => true, 'size' => 16], ['alignment' => 'center']);
            $phpWord->addTitleStyle(2, ['bold' => true, 'size' => 14], ['alignment' => 'center']);
            
            // Add a section
            $section = $phpWord->addSection();
            
            // Add header with school info
            $header = $section->addHeader();
            $header->addText('PEMERINTAH KABUPATEN', ['bold' => true], ['alignment' => 'center']);
            $header->addText('KOORDINATOR WILAYAH DIKPORA KECAMATAN', ['bold' => true], ['alignment' => 'center']);
            $header->addText('SD IT AL-HIDAYAH LOGAM', ['bold' => true, 'size' => 14], ['alignment' => 'center']);
            $header->addText('Telp. ${nomor_telepon}', [], ['alignment' => 'center']);
            
            // Add title
            $section->addTitle($type === 'UTS' ? 'RAPOR TENGAH SEMESTER 1' : 'RAPOR AKHIR SEMESTER', 1);
            
            // Add student info
            $tableStyle = ['borderSize' => 6, 'borderColor' => '000000'];
            $cellStyle = ['valign' => 'center'];
            
            $infoTable = $section->addTable($tableStyle);
            
            $infoTable->addRow();
            $infoTable->addCell(2000, $cellStyle)->addText('Nama Siswa');
            $infoTable->addCell(500, $cellStyle)->addText(':');
            $infoTable->addCell(3000, $cellStyle)->addText('${nama_siswa}');
            $infoTable->addCell(1500, $cellStyle)->addText('Kelas');
            $infoTable->addCell(500, $cellStyle)->addText(':');
            $infoTable->addCell(1500, $cellStyle)->addText('${kelas}');
            
            $infoTable->addRow();
            $infoTable->addCell(2000, $cellStyle)->addText('NISN/NIS');
            $infoTable->addCell(500, $cellStyle)->addText(':');
            $infoTable->addCell(3000, $cellStyle)->addText('${nisn}/${nis}');
            $infoTable->addCell(1500, $cellStyle)->addText('Tahun Pelajaran');
            $infoTable->addCell(500, $cellStyle)->addText(':');
            $infoTable->addCell(1500, $cellStyle)->addText('${tahun_ajaran}');
            
            $section->addTextBreak(1);
            
            // Add mata pelajaran table
            $section->addText('DAFTAR NILAI MATA PELAJARAN', ['bold' => true], ['alignment' => 'center']);
            
            $mapelTable = $section->addTable($tableStyle);
            
            // Table header
            $mapelTable->addRow(null, ['tblHeader' => true, 'cantSplit' => true]);
            $mapelTable->addCell(600, ['bgColor' => 'D3D3D3'])->addText('No.', ['bold' => true], ['alignment' => 'center']);
            $mapelTable->addCell(3000, ['bgColor' => 'D3D3D3'])->addText('Mata Pelajaran', ['bold' => true], ['alignment' => 'center']);
            $mapelTable->addCell(1000, ['bgColor' => 'D3D3D3'])->addText('Nilai', ['bold' => true], ['alignment' => 'center']);
            $mapelTable->addCell(5000, ['bgColor' => 'D3D3D3'])->addText('Capaian Kompetensi', ['bold' => true], ['alignment' => 'center']);
            
            // Add mata pelajaran rows (dynamic placeholders)
            for ($i = 1; $i <= 7; $i++) {
                $mapelTable->addRow();
                $mapelTable->addCell(600)->addText($i, [], ['alignment' => 'center']);
                $mapelTable->addCell(3000)->addText('${nama_matapelajaran' . $i . '}');
                $mapelTable->addCell(1000)->addText('${nilai_matapelajaran' . $i . '}', [], ['alignment' => 'center']);
                $mapelTable->addCell(5000)->addText('${capaian_matapelajaran' . $i . '}');
            }
            
            $section->addTextBreak(1);
            
            // Add muatan lokal table
            $section->addText('MUATAN LOKAL', ['bold' => true], ['alignment' => 'center']);
            
            $mulokTable = $section->addTable($tableStyle);
            
            // Table header
            $mulokTable->addRow(null, ['tblHeader' => true, 'cantSplit' => true]);
            $mulokTable->addCell(600, ['bgColor' => 'D3D3D3'])->addText('No.', ['bold' => true], ['alignment' => 'center']);
            $mulokTable->addCell(3000, ['bgColor' => 'D3D3D3'])->addText('Muatan Lokal', ['bold' => true], ['alignment' => 'center']);
            $mulokTable->addCell(1000, ['bgColor' => 'D3D3D3'])->addText('Nilai', ['bold' => true], ['alignment' => 'center']);
            $mulokTable->addCell(5000, ['bgColor' => 'D3D3D3'])->addText('Capaian Kompetensi', ['bold' => true], ['alignment' => 'center']);
            
            // Add muatan lokal rows
            for ($i = 1; $i <= 5; $i++) {
                $mulokTable->addRow();
                $mulokTable->addCell(600)->addText($i, [], ['alignment' => 'center']);
                $mulokTable->addCell(3000)->addText('${nama_mulok' . $i . '}');
                $mulokTable->addCell(1000)->addText('${nilai_mulok' . $i . '}', [], ['alignment' => 'center']);
                $mulokTable->addCell(5000)->addText('${capaian_mulok' . $i . '}');
            }
            
            $section->addTextBreak(1);
            
            // Add ekstrakurikuler table
            $section->addText('EKSTRAKURIKULER', ['bold' => true], ['alignment' => 'center']);
            
            $ekskulTable = $section->addTable($tableStyle);
            
            // Table header
            $ekskulTable->addRow(null, ['tblHeader' => true, 'cantSplit' => true]);
            $ekskulTable->addCell(600, ['bgColor' => 'D3D3D3'])->addText('No.', ['bold' => true], ['alignment' => 'center']);
            $ekskulTable->addCell(3000, ['bgColor' => 'D3D3D3'])->addText('Kegiatan Ekstrakurikuler', ['bold' => true], ['alignment' => 'center']);
            $ekskulTable->addCell(6000, ['bgColor' => 'D3D3D3'])->addText('Keterangan', ['bold' => true], ['alignment' => 'center']);
            
            // Add ekstrakurikuler rows
            for ($i = 1; $i <= 5; $i++) {
                $ekskulTable->addRow();
                $ekskulTable->addCell(600)->addText($i, [], ['alignment' => 'center']);
                $ekskulTable->addCell(3000)->addText('${ekskul' . $i . '_nama}');
                $ekskulTable->addCell(6000)->addText('${ekskul' . $i . '_keterangan}');
            }
            
            $section->addTextBreak(1);
            
            // Add catatan guru
            $section->addText('CATATAN GURU', ['bold' => true], ['alignment' => 'center']);
            
            $catatanTable = $section->addTable($tableStyle);
            $catatanTable->addRow(800);
            $catatanTable->addCell(9600)->addText('${catatan_guru}');
            
            $section->addTextBreak(1);
            
            // Add ketidakhadiran
            $section->addText('KETIDAKHADIRAN', ['bold' => true], ['alignment' => 'center']);
            
            $kehadiranTable = $section->addTable($tableStyle);
            
            $kehadiranTable->addRow();
            $kehadiranTable->addCell(9600)->addText('Sakit : ${sakit} Hari');
            
            $kehadiranTable->addRow();
            $kehadiranTable->addCell(9600)->addText('Izin : ${izin} Hari');
            
            $kehadiranTable->addRow();
            $kehadiranTable->addCell(9600)->addText('Tanpa Keterangan : ${tanpa_keterangan} Hari');
            
            $section->addTextBreak(2);
            
            // Add signature section
            $signatureTable = $section->addTable();
            $signatureTable->addRow();
            $signatureTable->addCell(3000)->addText('Mengetahui:', ['bold' => true], ['alignment' => 'center']);
            $signatureTable->addCell(3000);
            $signatureTable->addCell(3000);
            
            $signatureTable->addRow();
            $signatureTable->addCell(3000)->addText('Orang Tua/Wali,', ['bold' => true], ['alignment' => 'center']);
            $signatureTable->addCell(3000)->addText('Kepala Sekolah,', ['bold' => true], ['alignment' => 'center']);
            $signatureTable->addCell(3000)->addText('Wali Kelas,', ['bold' => true], ['alignment' => 'center']);
            
            $signatureTable->addRow(1000); // Space for signature
            $signatureTable->addCell(3000);
            $signatureTable->addCell(3000);
            $signatureTable->addCell(3000);
            
            $signatureTable->addRow();
            $signatureTable->addCell(3000)->addText('____________________');
            $signatureTable->addCell(3000)->addText('${kepala_sekolah}');
            $signatureTable->addCell(3000)->addText('${wali_kelas}');
            
            $signatureTable->addRow();
            $signatureTable->addCell(3000);
            $signatureTable->addCell(3000)->addText('NIP. ${nip_kepala_sekolah}');
            $signatureTable->addCell(3000)->addText('NIP. ${nip_wali_kelas}');
            
            // Save to file
            $objWriter = \PhpOffice\PhpWord\IOFactory::createWriter($phpWord, 'Word2007');
            
            // Ensure the directory exists
            $dir = dirname($outputPath);
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
            
            $objWriter->save($outputPath);
            
            return true;
        } catch (\Exception $e) {
            \Log::error('Error creating dynamic sample template:', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return false;
        }
    }


    public function previewData(ReportTemplate $template)
    {
        try {
            $filePath = storage_path('app/public/' . $template->path);
            
            if (!file_exists($filePath)) {
                return response()->json([
                    'success' => false,
                    'message' => 'File template tidak ditemukan'
                ], 404);
            }

            // Return raw file content for docx.js processing
            $content = file_get_contents($filePath);
            
            return response($content, 200, [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                'Content-Disposition' => 'inline; filename="' . $template->filename . '"'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Gagal membuat preview data: ' . $e->getMessage()
            ], 500);
        }
    }
    
    public function getCurrentTemplate(Request $request)
    {
        try {
            $type = $request->type ?? 'UTS';
            
            \Log::info('getCurrentTemplate request:', [
                'type' => $type,
                'all_params' => $request->all()
            ]);
    
            $templates = ReportTemplate::where('type', $type)
                ->orderBy('created_at', 'desc')
                ->get();
    
            $activeTemplate = $templates->where('is_active', true)->first();
    
            \Log::info('getCurrentTemplate response:', [
                'templates_count' => $templates->count(),
                'templates' => $templates->toArray(),
                'active_template' => $activeTemplate ? $activeTemplate->toArray() : null
            ]);
    
            return response()->json([
                'success' => true,
                'templates' => $templates,
                'activeTemplate' => $activeTemplate
            ])->header('Content-Type', 'application/json');
        } catch (\Exception $e) {
            \Log::error('Error in getCurrentTemplate:', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
    
            return response()->json([
                'success' => false,
                'message' => 'Gagal mengambil template: ' . $e->getMessage()
            ], 500)->header('Content-Type', 'application/json');
        }
    }

     /**
     * Download sample template with correct placeholders
     * 
     * @param Request $request
     * @return \Illuminate\Http\Response
     */
    public function downloadSampleTemplate(Request $request)
    {
        $type = $request->input('type', 'UTS');
        
        // Ensure valid type
        if (!in_array($type, ['UTS', 'UAS'])) {
            $type = 'UTS';
        }
        
        // Lokasi file template
        $templatePaths = [
            'UTS' => [
                storage_path('app/public/templates/Template_UTS_New.docx'),
                storage_path('app/public/templates/RAPOR_UTS.docx'),
                storage_path('app/public/templates/RAPOR TENGAH SEMESTER I.docx'),
                storage_path('app/public/RAPOR TENGAH SEMESTER I.docx'),
                base_path('RAPOR TENGAH SEMESTER I.docx'),
            ],
            'UAS' => [
                storage_path('app/public/templates/Template_UAS_New.docx'),
                storage_path('app/public/templates/RAPOR_UAS.docx'),
                storage_path('app/public/templates/RAPOR AKHIR SEMESTER.docx'),
            ]
        ];
        
        $filePath = null;
        
        // Cari file yang ada
        foreach ($templatePaths[$type] as $path) {
            if (file_exists($path) && is_readable($path) && filesize($path) > 0) {
                $filePath = $path;
                break;
            }
        }
        
        if (!$filePath) {
            return response()->json([
                'success' => false,
                'message' => "Template file untuk {$type} tidak ditemukan"
            ], 404);
        }
        
        // **CRITICAL: Clean all output buffers untuk mencegah corruption**
        while (ob_get_level()) {
            ob_end_clean();
        }
        
        // Dapatkan file info
        $fileSize = filesize($filePath);
        $fileName = "Template_Rapor_{$type}_" . date('Y-m-d') . ".docx";
        
        // **CRITICAL: Set headers yang benar untuk DOCX**
        $headers = [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'Content-Disposition' => 'attachment; filename="' . $fileName . '"',
            'Content-Length' => $fileSize,
            'Content-Transfer-Encoding' => 'binary',
            'Cache-Control' => 'private, no-transform, no-store, must-revalidate',
            'Pragma' => 'no-cache',
            'Expires' => '0',
            'Accept-Ranges' => 'bytes',
        ];
        
        // Log untuk debugging
        Log::info('Downloading template file', [
            'file_path' => $filePath,
            'file_size' => $fileSize,
            'download_name' => $fileName,
            'mime_type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'
        ]);
        
        // **METHOD 1: Laravel Response Download (Recommended)**
        try {
            return response()->download($filePath, $fileName, $headers);
        } catch (\Exception $e) {
            Log::error('Laravel download failed, trying manual method', [
                'error' => $e->getMessage()
            ]);
            
            // **METHOD 2: Manual Binary Stream (Fallback)**
            return $this->streamBinaryFile($filePath, $fileName, $headers);
        }
    }

    /**
     * Manual binary file streaming - sebagai fallback
     * Metode ini memastikan file di-stream secara binary tanpa corruption
     */
    private function streamBinaryFile($filePath, $fileName, $headers)
    {
        // **CRITICAL: Bersihkan semua output buffer**
        while (ob_get_level()) {
            ob_end_clean();
        }
        
        // Set all headers
        foreach ($headers as $key => $value) {
            header("{$key}: {$value}");
        }
        
        // **CRITICAL: Set binary mode untuk file reading**
        $handle = fopen($filePath, 'rb'); // 'rb' = read binary
        
        if (!$handle) {
            abort(500, 'Cannot open template file');
        }
        
        // **CRITICAL: Stream file dalam chunks untuk avoid memory issues**
        $chunkSize = 8192; // 8KB chunks
        
        while (!feof($handle)) {
            $chunk = fread($handle, $chunkSize);
            if ($chunk === false) {
                break;
            }
            echo $chunk;
            
            // **CRITICAL: Flush output untuk memastikan chunk dikirim**
            if (ob_get_level()) {
                ob_flush();
            }
            flush();
        }
        
        fclose($handle);
        exit; // **CRITICAL: Exit untuk mencegah output tambahan**
    }

    
    /**
     * Alternative method menggunakan StreamedResponse
     * Untuk kasus yang sangat sulit
     */
    public function downloadTemplateStreamed(Request $request)
    {
        $type = $request->input('type', 'UTS');
        
        // Find file (sama seperti method sebelumnya)
        $templatePaths = [
            'UTS' => [
                storage_path('app/public/templates/Template_UTS_New.docx'),
                storage_path('app/public/RAPOR TENGAH SEMESTER I.docx'),
                base_path('RAPOR TENGAH SEMESTER I.docx'),
            ],
            'UAS' => [
                storage_path('app/public/templates/Template_UAS_New.docx'),
            ]
        ];
        
        $filePath = null;
        foreach ($templatePaths[$type] as $path) {
            if (file_exists($path) && filesize($path) > 0) {
                $filePath = $path;
                break;
            }
        }
        
        if (!$filePath) {
            abort(404, 'Template not found');
        }
        
        $fileName = "Template_Rapor_{$type}_" . date('Y-m-d') . ".docx";
        $fileSize = filesize($filePath);
        
        // **StreamedResponse - paling clean untuk binary files**
        return response()->streamDownload(function () use ($filePath) {
            // **CRITICAL: Binary file reading**
            $handle = fopen($filePath, 'rb');
            
            while (!feof($handle)) {
                echo fread($handle, 8192); // 8KB chunks
            }
            
            fclose($handle);
        }, $fileName, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'Content-Length' => $fileSize,
        ]);
    }

    /**
     * Generate and download a PDF version of the report
     *
     * @param Request $request
     * @param Siswa $siswa
     * @return \Illuminate\Http\Response
     */
    public function downloadPdf(Siswa $siswa, Request $request)
    {
        $request->query->set('disposition', 'attachment');

        return $this->previewPdf($siswa, $request);
    }
    
    /**
     * Download template DOCX file directly in admin Report page
     * 
     * @param ReportTemplate $template
     * @return \Illuminate\Http\Response
     */
    public function downloadTemplate(ReportTemplate $template)
    {
        try {
            $filePath = storage_path('app/public/' . $template->path);
            
            if (!file_exists($filePath)) {
                return redirect()->back()->with('error', 'File template tidak ditemukan');
            }
            
            // **CRITICAL: Clean all output buffers**
            while (ob_get_level()) {
                ob_end_clean();
            }
            
            // Generate clean filename
            $cleanFilename = preg_replace('/^\d+_/', '', $template->filename);
            $downloadFilename = 'Template_' . $template->type . '_' . $cleanFilename;
            
            // **CRITICAL: Set proper headers for DOCX**
            $headers = [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                'Content-Disposition' => 'attachment; filename="' . $downloadFilename . '"',
                'Content-Length' => filesize($filePath),
                'Content-Transfer-Encoding' => 'binary',
                'Cache-Control' => 'private, no-transform, no-store, must-revalidate',
                'Pragma' => 'no-cache',
                'Expires' => '0',
                'Accept-Ranges' => 'bytes',
            ];
            
            // Log for debugging
            Log::info('Downloading template file', [
                'template_id' => $template->id,
                'file_path' => $filePath,
                'file_size' => filesize($filePath),
                'download_name' => $downloadFilename
            ]);
            
            // Use Laravel's download response
            return response()->download($filePath, $downloadFilename, $headers);
            
        } catch (\Exception $e) {
            Log::error('Error downloading template: ' . $e->getMessage(), [
                'template_id' => $template->id,
                'trace' => $e->getTraceAsString()
            ]);
            
            return redirect()->back()->with('error', 'Gagal mendownload template: ' . $e->getMessage());
        }
    }

    
    /**
     * Helper method untuk logging performance metrics
     */
    private function logPerformanceMetrics($requestId, $status, $startTime, $memoryStart, $additionalData = [])
    {
        $currentTime = microtime(true);
        $currentMemory = memory_get_usage(true);
        $peakMemory = memory_get_peak_usage(true);
        
        $metrics = [
            'request_id' => $requestId,
            'status' => $status,
            'duration_ms' => round(($currentTime - $startTime) * 1000, 2),
            'duration_seconds' => round($currentTime - $startTime, 2),
            'memory_used_mb' => round(($currentMemory - $memoryStart) / 1024 / 1024, 2),
            'memory_current_mb' => round($currentMemory / 1024 / 1024, 2),
            'memory_peak_mb' => round($peakMemory / 1024 / 1024, 2),
            'timestamp' => now()->toISOString()
        ];
        
        if (!empty($additionalData)) {
            $metrics = array_merge($metrics, $additionalData);
        }
        
        if (config('logging.diagnostics.log_report_processing')) {
            Log::debug("Performance Metrics - {$status}", $metrics);
        }
    }

    private function resolveRaporTahunAjaranId(Request $request): ?int
    {
        $hasRequestedYear = $request->has('tahun_ajaran_id')
            && $request->input('tahun_ajaran_id') !== null
            && $request->input('tahun_ajaran_id') !== '';

        return $this->getValidTahunAjaranId(
            $hasRequestedYear ? (int) $request->input('tahun_ajaran_id') : null
        );
    }

    private function authorizeWaliRaporAccess(Siswa $siswa, int $tahunAjaranId): void
    {
        $guru = auth()->guard('guru')->user();
        $tahunAjaran = TahunAjaran::find($tahunAjaranId);

        abort_unless(
            $guru &&
            $tahunAjaran &&
            session('selected_role') === 'wali_kelas' &&
            $siswa->isInKelasWali($guru->id, $tahunAjaranId, (int) $tahunAjaran->semester),
            403
        );
    }

    private function getRaporSemester(int $tahunAjaranId): int
    {
        return (int) (TahunAjaran::whereKey($tahunAjaranId)->value('semester') ?: 1);
    }

    private function normalizeReportType(?string $type): string
    {
        return app(ReportPeriodService::class)->normalizeType($type) ?: 'UTS';
    }

    private function resolveReportTypeForRequest(Request $request, int $tahunAjaranId, string $source = 'query'): string
    {
        $value = $source === 'input'
            ? $request->input('type')
            : $request->query('type');

        if ($value !== null && $value !== '') {
            return $this->normalizeReportType($value);
        }

        return $this->openedReportTypeForYear($tahunAjaranId);
    }

    private function openedReportTypeForYear(int $tahunAjaranId): string
    {
        return app(ReportPeriodService::class)->openedType(null, $tahunAjaranId);
    }

    private function reportPeriodUnavailableResponse(Request $request, string $type, int $tahunAjaranId)
    {
        $periodService = app(ReportPeriodService::class);
        $message = $periodService->unopenedMessage($type);

        Log::info('report.period_unopened_access_blocked', [
            'report_type' => $type,
            'opened_report_type' => $periodService->openedType(null, $tahunAjaranId),
            'tahun_ajaran_id' => $tahunAjaranId,
            'route' => $request->route()?->getName(),
            'guru_id' => auth()->guard('guru')->id(),
        ]);

        if ($request->expectsJson() || $request->ajax()) {
            return response()->json([
                'success' => false,
                'message' => $message,
                'error_type' => 'report_period_unopened',
                'opened_report_type' => $periodService->openedType(null, $tahunAjaranId),
            ], 422);
        }

        return redirect()
            ->route('wali_kelas.rapor.index', [
                'type' => $periodService->openedType(null, $tahunAjaranId),
                'tahun_ajaran_id' => $tahunAjaranId,
            ])
            ->with('error', $message);
    }

    private function ensureReportPeriodOpened(Request $request, string $type, int $tahunAjaranId)
    {
        if (app(ReportPeriodService::class)->isOpened($type, null, $tahunAjaranId)) {
            return null;
        }

        return $this->reportPeriodUnavailableResponse($request, $type, $tahunAjaranId);
    }

    private function resolveRaporClass(Siswa $siswa, int $tahunAjaranId): ?Kelas
    {
        try {
            return app(SiswaKelasSemesterResolver::class)
                ->resolveClass($siswa, $tahunAjaranId, $this->getRaporSemester($tahunAjaranId), true);
        } catch (\RuntimeException $exception) {
            Log::warning('Unable to resolve report class context', [
                'siswa_id' => $siswa->id,
                'tahun_ajaran_id' => $tahunAjaranId,
                'error' => $exception->getMessage(),
            ]);

            return null;
        }
    }

    private function applyRaporClassContext(Siswa $siswa, int $tahunAjaranId): ?Kelas
    {
        $kelas = $this->resolveRaporClass($siswa, $tahunAjaranId);

        if ($kelas) {
            $kelas->loadMissing('tahunAjaran');
            $siswa->setRelation('kelas', $kelas);
        }

        return $kelas;
    }

    private function reportTemplateSemester(?int $tahunAjaranId): ?int
    {
        return $tahunAjaranId ? $this->getRaporSemester($tahunAjaranId) : null;
    }

    private function baseActiveReportTemplateQuery(string $type, ?int $tahunAjaranId = null)
    {
        $semester = $this->reportTemplateSemester($tahunAjaranId);

        return ReportTemplate::where('type', $type)
            ->where('is_active', true)
            ->when($tahunAjaranId, function ($query) use ($tahunAjaranId) {
                return $query->where('tahun_ajaran_id', $tahunAjaranId);
            })
            ->when($semester, function ($query) use ($semester) {
                return $query->where(function ($query) use ($semester) {
                    $query->whereNull('semester')
                        ->orWhere('semester', $semester);
                });
            })
            ->orderByDesc('updated_at')
            ->orderByDesc('id');
    }

    private function findActiveReportTemplateForContext(string $type, ?int $kelasId, ?int $tahunAjaranId = null): ?ReportTemplate
    {
        if ($kelasId) {
            $template = $this->baseActiveReportTemplateQuery($type, $tahunAjaranId)
                ->whereHas('kelasList', function ($query) use ($kelasId) {
                    $query->where('kelas_id', $kelasId);
                })
                ->first();

            if ($template) {
                return $template;
            }

            $template = $this->baseActiveReportTemplateQuery($type, $tahunAjaranId)
                ->where('kelas_id', $kelasId)
                ->first();

            if ($template) {
                return $template;
            }
        }

        return $this->baseActiveReportTemplateQuery($type, $tahunAjaranId)
            ->whereNull('kelas_id')
            ->whereDoesntHave('kelasList')
            ->first();
    }

    private function pdfTemplateUnavailableResponse(Request $request, Siswa $siswa, string $type, int $tahunAjaranId, ?int $kelasId = null)
    {
        Log::warning('PDF report template unavailable for requested context', [
            'siswa_id' => $siswa->id,
            'kelas_id' => $kelasId,
            'tahun_ajaran_id' => $tahunAjaranId,
            'semester' => $this->getRaporSemester($tahunAjaranId),
            'type' => $type,
            'route' => $request->route()?->getName(),
        ]);

        return response()->json([
            'success' => false,
            'message' => $this->missingActiveTemplateMessage($type),
            'error_type' => 'template_missing',
        ], 422);
    }

    private function missingActiveTemplateMessage(string $type): string
    {
        $type = $this->normalizeReportType($type);

        return "Belum ada template {$type} aktif untuk kelas ini. Silakan hubungi admin.";
    }

    public function previewRapor(Request $request, $siswa_id) {
        $type = $this->normalizeReportType($request->query('type', 'UTS'));
        $performance = ReportPerformanceTracker::startFlowIfEnabled(
            'html_preview',
            $type,
            $request->route()?->getName()
        );

        try {
            // Ambil tipe rapor dari query param
            $tahunAjaranId = ReportPerformanceTracker::measureSegment('context', function () use ($request) {
                return $this->resolveRaporTahunAjaranId($request);
            });
            abort_unless($tahunAjaranId, 403);
            $type = $this->resolveReportTypeForRequest($request, $tahunAjaranId);

            $siswa = ReportPerformanceTracker::measureSegment('context', fn () => Siswa::find($siswa_id));
            abort_unless($siswa, 403);
            ReportPerformanceTracker::measureSegment('authorization', function () use ($siswa, $tahunAjaranId) {
                $this->authorizeWaliRaporAccess($siswa, $tahunAjaranId);
            });

            if ($response = $this->ensureReportPeriodOpened($request, $type, $tahunAjaranId)) {
                return $response;
            }
            
            // Ambil semester dari tahun ajaran
            [$tahunAjaran, $kelas] = ReportPerformanceTracker::measureSegment('context', function () use ($siswa, $tahunAjaranId) {
                return [
                    \App\Models\TahunAjaran::find($tahunAjaranId),
                    $this->applyRaporClassContext($siswa, $tahunAjaranId),
                ];
            });
            $semester = $tahunAjaran ? $tahunAjaran->semester : 1;
            abort_unless($kelas, 403);
            
            \Log::info('Preview rapor', [
                'siswa_id' => $siswa_id,
                'type' => $type,
                'semester' => $semester,
                'tahun_ajaran_id' => $tahunAjaranId
            ]);
            
            // Cari siswa dengan relasi yang dibutuhkan
            ReportPerformanceTracker::measureSegment('preload', function () use ($siswa, $tahunAjaranId, $semester) {
                $siswa->load([
                    'nilais' => function($query) use ($tahunAjaranId, $semester) {
                        // Filter nilai berdasarkan semester dan tahun ajaran
                        $query->where('tahun_ajaran_id', $tahunAjaranId);
                        $query->whereHas('mataPelajaran', function($q) use ($semester) {
                            $q->where('semester', $semester);
                        });
                        $query->where('is_submitted', true);
                    },
                    'nilais.mataPelajaran',
                    'nilaiEkstrakurikuler' => function($query) use ($tahunAjaranId, $semester) {
                        $query->where('tahun_ajaran_id', $tahunAjaranId)
                            ->where('semester', $semester);
                    },
                    'nilaiEkstrakurikuler.ekstrakurikuler',
                    'absensi' => function($query) use ($tahunAjaranId, $semester) {
                        $query->where('semester', $semester)
                            ->where('tahun_ajaran_id', $tahunAjaranId);
                    }
                ]);
            });
            
            // Logging untuk debug
            \Log::info('Preview data loaded', [
                'siswa_id' => $siswa->id,
                'nilais_count' => $siswa->nilais->count(),
                'ekstrakurikuler_count' => $siswa->nilaiEkstrakurikuler->count(),
                'has_absensi' => $siswa->absensi ? true : false
            ]);
            
            // Render view ke HTML
            $html = ReportPerformanceTracker::measureSegment('response', function () use ($siswa, $type, $semester, $kelas, $tahunAjaran, $tahunAjaranId) {
                return view('wali_kelas.rapor.preview', [
                    'siswa' => $siswa,
                    'type' => $type,
                    'semester' => $semester,
                    'kelas' => $kelas,
                    'tahunAjaran' => $tahunAjaran,
                    'tahunAjaranId' => $tahunAjaranId
                ])->render();
            });
            
            // Kembalikan sebagai JSON response
            return response()->json([
                'success' => true,
                'html' => $html
            ]);
        } catch (\Exception $e) {
            if ($e instanceof HttpExceptionInterface) {
                throw $e;
            }

            // Log error untuk debugging
            \Log::error('Error in previewRapor: ' . $e->getMessage());
            \Log::error($e->getTraceAsString());
            
            // Kirim respon error
            return response()->json([
                'success' => false,
                'message' => 'Terjadi kesalahan saat memuat preview rapor: ' . $e->getMessage()
            ], 500);
        } finally {
            ReportPerformanceTracker::finishIfEnabled($performance);
        }
    }

    public function diagnoseSiswaData(Request $request, Siswa $siswa)
    {
        $type = $request->input('type', 'UTS');
        $tahunAjaranId = session('tahun_ajaran_id');
        
        try {
            // Get diagnostic data
            $diagnosisResult = $siswa->diagnoseDataCompleteness($type);
            
            // Check template availability
            $template = $this->getTemplateForSiswa($siswa, $type, $tahunAjaranId);
            
            return response()->json([
                'success' => true,
                'data' => [
                    'nilai_status' => $diagnosisResult['nilai_status'],
                    'nilai_message' => $diagnosisResult['nilai_message'],
                    'absensi_status' => $diagnosisResult['absensi_status'],
                    'absensi_message' => $diagnosisResult['absensi_message'],
                    'template_status' => !is_null($template),
                    'template_message' => is_null($template) ? 
                        'Template tidak ditemukan untuk kelas ini' : 
                        'Template ditemukan dengan ID: ' . $template->id,
                    'detail' => 'Tipe: ' . $type . ', ' .
                            'Kelas: ' . ($siswa->kelas->nama_kelas ?? 'N/A') . ', ' .
                            'Tahun Ajaran: ' . $tahunAjaranId,
                    'tahun_ajaran_id' => $tahunAjaranId
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error: ' . $e->getMessage()
            ], 500);
        }
    }

    public function checkLibreOfficeStatus()
    {
        try {
            $conversionService = new \App\Services\DocumentConversionService();
            $testResult = $conversionService->testInstallation();
            
            return response()->json([
                'success' => true,
                'libreoffice' => $testResult
            ]);
            
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Test PDF conversion functionality
     */
    public function testPdfConversion()
    {
        try {
            $conversionService = new \App\Services\DocumentConversionService();
            
            // Test LibreOffice installation
            $testResult = $conversionService->testInstallation();
            
            if (!$testResult['success']) {
                return response()->json([
                    'success' => false,
                    'message' => 'LibreOffice test failed: ' . $testResult['message'],
                    'test_result' => $testResult
                ]);
            }
            
            // Create a simple test document
            $phpWord = new \PhpOffice\PhpWord\PhpWord();
            $section = $phpWord->addSection();
            $section->addText('Test document for PDF conversion');
            $section->addText('Generated on ' . date('Y-m-d H:i:s'));
            $section->addText('If you can see this as PDF, the conversion is working!');
            
            // Save test document
            $testDir = storage_path('app/public/test');
            if (!file_exists($testDir)) {
                mkdir($testDir, 0755, true);
            }
            
            $docxPath = $testDir . '/test_conversion.docx';
            $objWriter = \PhpOffice\PhpWord\IOFactory::createWriter($phpWord, 'Word2007');
            $objWriter->save($docxPath);
            
            // Test conversion
            $result = $conversionService->convertDocxToPdf($docxPath, $testDir);
            
            // Cleanup test files
            if (file_exists($docxPath)) {
                unlink($docxPath);
            }
            
            if ($result['success'] && isset($result['path']) && file_exists($result['path'])) {
                $pdfSize = filesize($result['path']);
                unlink($result['path']); // Cleanup PDF
                
                return response()->json([
                    'success' => true,
                    'message' => 'PDF conversion is working correctly!',
                    'details' => [
                        'libreoffice_test' => $testResult,
                        'conversion_result' => $result,
                        'pdf_size' => $pdfSize . ' bytes'
                    ]
                ]);
            } else {
                return response()->json([
                    'success' => false,
                    'message' => 'PDF conversion test failed',
                    'details' => [
                        'libreoffice_test' => $testResult,
                        'conversion_result' => $result
                    ]
                ]);
            }
            
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error testing PDF conversion: ' . $e->getMessage(),
                'trace' => env('APP_DEBUG') ? $e->getTraceAsString() : null
            ], 500);
        }
    }

    /**
     * Check if LibreOffice is installed and get version information
     * 
     * @return \Illuminate\Http\Response
     */
    public function getConversionStatus()
    {
        try {
            $process = new \Symfony\Component\Process\Process(['soffice', '--version']);
            $process->run();
            
            if ($process->isSuccessful()) {
                $versionInfo = trim($process->getOutput());
                
                return response()->json([
                    'success' => true,
                    'libreoffice_installed' => true,
                    'version_info' => $versionInfo,
                    'environment' => [
                        'os' => php_uname(),
                        'php_version' => PHP_VERSION,
                        'server' => $_SERVER['SERVER_SOFTWARE'] ?? 'Unknown'
                    ]
                ]);
            } else {
                return response()->json([
                    'success' => false,
                    'libreoffice_installed' => false,
                    'error' => 'LibreOffice not found or not accessible: ' . $process->getErrorOutput()
                ]);
            }
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error checking LibreOffice: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Preview PDF in browser
     */
    public function previewPdf(Siswa $siswa, Request $request)
    {
        $type = $this->normalizeReportType($request->query('type', 'UTS'));
        $disposition = $request->query('disposition', 'inline') === 'attachment' ? 'attachment' : 'inline';
        $flowPrefix = $disposition === 'attachment' ? 'pdf_download' : 'pdf_preview';
        $performance = ReportPerformanceTracker::startFlowIfEnabled(
            "{$flowPrefix}_pending",
            $type,
            $request->route()?->getName()
        );

        try {
            $tahunAjaranId = ReportPerformanceTracker::measureSegment('context', function () use ($request) {
                return $this->getValidTahunAjaranId(
                    $request->query('tahun_ajaran_id') ? (int) $request->query('tahun_ajaran_id') : null
                );
            });

            if (!$tahunAjaranId) {
                return $this->failTahunAjaranNotSet($request, true);
            }
            $type = $this->resolveReportTypeForRequest($request, $tahunAjaranId);

            ReportPerformanceTracker::measureSegment('authorization', function () use ($siswa, $tahunAjaranId) {
                $this->authorizeWaliRaporAccess($siswa, $tahunAjaranId);
            });

            if ($response = $this->ensureReportPeriodOpened($request, $type, $tahunAjaranId)) {
                return $response;
            }

            $kelas = ReportPerformanceTracker::measureSegment('context', function () use ($siswa, $tahunAjaranId) {
                return $this->resolveRaporClass($siswa, $tahunAjaranId);
            });

            // Get template and generate DOCX
            $template = ReportPerformanceTracker::measureSegment('context', function () use ($siswa, $type, $tahunAjaranId) {
                return $this->getTemplateForSiswa($siswa, $type, $tahunAjaranId);
            });
            if (!$template) {
                return $this->pdfTemplateUnavailableResponse($request, $siswa, $type, $tahunAjaranId, $kelas?->id);
            }

            // Cache lookup happens before LibreOffice checks so valid cached PDFs remain fast and reusable.
            $cachedPdf = PdfCacheService::getCachedPdf(
                $siswa,
                $type,
                $tahunAjaranId
            );

            if ($cachedPdf) {
                ReportPerformanceTracker::setFlowTypeIfEnabled("{$flowPrefix}_cache_hit");

                return $this->pdfReadyResponse($request, $cachedPdf, $disposition, true);
            }

            ReportPerformanceTracker::setFlowTypeIfEnabled("{$flowPrefix}_queued");

            return $this->queuePdfGenerationResponse(
                $request,
                $siswa,
                $type,
                (int) $tahunAjaranId,
                $disposition,
                $flowPrefix
            );
            
        } catch (\Exception $e) {
            if ($e instanceof HttpExceptionInterface) {
                throw $e;
            }

            Log::error('[ReportController] Preview PDF failed', [
                'siswa_id' => $siswa->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'user_id' => auth()->guard('guru')->id(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Terjadi kesalahan. Silakan coba lagi.'
            ], 500);
        } finally {
            ReportPerformanceTracker::finishIfEnabled($performance);
        }
    }

    protected function queuePdfGenerationResponse(
        Request $request,
        Siswa $siswa,
        string $type,
        int $tahunAjaranId,
        string $disposition,
        string $flowPrefix
    ) {
        $existingRequestId = $this->activePdfGenerationRequestId($siswa, $type, $tahunAjaranId);

        if ($existingRequestId) {
            return $this->pdfProcessingResponse($request, $existingRequestId, $disposition, true);
        }

        $requestId = 'pdf_' . (string) Str::uuid();
        $userId = auth()->guard('guru')->id();

        Cache::put(PdfCacheService::getProgressKey($requestId), [
            'status' => 'queued',
            'stage' => 'queued',
            'message' => 'Menunggu antrean',
            'completed' => false,
            'error' => false,
            'request_id' => $requestId,
            'siswa_id' => $siswa->id,
            'type' => $type,
            'tahun_ajaran_id' => $tahunAjaranId,
            'user_id' => $userId,
            'cached' => false,
            'updated_at' => time(),
            'timestamp' => now()->toISOString(),
        ], now()->addMinutes(PdfCacheService::PROGRESS_TTL_MINUTES));

        Cache::put(
            PdfCacheService::getGenerationRequestKey($siswa, $type, $tahunAjaranId),
            $requestId,
            now()->addMinutes(PdfCacheService::PROGRESS_TTL_MINUTES)
        );

        GeneratePdfReportJob::dispatch($siswa, $type, $tahunAjaranId, $requestId, $userId);

        return $this->pdfProcessingResponse($request, $requestId, $disposition, false);
    }

    protected function activePdfGenerationRequestId(Siswa $siswa, string $type, int $tahunAjaranId): ?string
    {
        $requestKey = PdfCacheService::getGenerationRequestKey($siswa, $type, $tahunAjaranId);
        $requestId = Cache::get($requestKey);

        if (! is_string($requestId) || $requestId === '') {
            return null;
        }

        $progress = Cache::get(PdfCacheService::getProgressKey($requestId));

        if (! is_array($progress)) {
            Cache::forget($requestKey);

            return null;
        }

        if ((int) ($progress['siswa_id'] ?? 0) !== (int) $siswa->id ||
            (string) ($progress['type'] ?? '') !== (string) $type ||
            (int) ($progress['tahun_ajaran_id'] ?? 0) !== (int) $tahunAjaranId) {
            Cache::forget($requestKey);

            return null;
        }

        $currentUserId = auth()->guard('guru')->id();
        if ($currentUserId && isset($progress['user_id']) && (string) $progress['user_id'] !== (string) $currentUserId) {
            return null;
        }

        if (($progress['completed'] ?? false) || ($progress['error'] ?? false)) {
            Cache::forget($requestKey);

            return null;
        }

        $updatedAt = (int) ($progress['updated_at'] ?? 0);
        if ($updatedAt > 0 && $updatedAt < now()->subMinutes(PdfCacheService::PROCESSING_STALE_MINUTES)->timestamp) {
            Cache::forget($requestKey);

            return null;
        }

        return $requestId;
    }

    protected function pdfReadyResponse(Request $request, array $cachedPdf, string $disposition, bool $cacheHit, ?string $requestId = null)
    {
        return ReportPerformanceTracker::measureSegment('response', function () use ($request, $cachedPdf, $disposition, $cacheHit, $requestId) {
            $url = $this->createSecureRaporFileUrl(
                $cachedPdf['path'],
                $cachedPdf['filename'],
                $disposition,
                60
            );

            if ($this->expectsPdfJson($request)) {
                return response()->json([
                    'success' => true,
                    'status' => 'ready',
                    'ready' => true,
                    'cache_hit' => $cacheHit,
                    'cached' => $cacheHit,
                    'url' => $url,
                    'download_url' => $url,
                    'filename' => $cachedPdf['filename'],
                    'file_size' => $cachedPdf['file_size'] ?? null,
                    'request_id' => $requestId,
                ]);
            }

            return redirect()->away($url);
        });
    }

    protected function pdfProcessingResponse(Request $request, string $requestId, string $disposition, bool $reused)
    {
        return ReportPerformanceTracker::measureSegment('response', function () use ($request, $requestId, $disposition, $reused) {
            $pollUrl = route('wali_kelas.rapor.pdf-progress', [
                'requestId' => $requestId,
                'disposition' => $disposition,
            ]);

            if ($this->expectsPdfJson($request)) {
                return response()->json([
                    'success' => true,
                    'status' => 'processing',
                    'ready' => false,
                    'cache_hit' => false,
                    'cached' => false,
                    'reused' => $reused,
                    'request_id' => $requestId,
                    'poll_url' => $pollUrl,
                    'progress_url' => $pollUrl,
                    'message' => 'Sedang menyiapkan PDF rapor. Proses ini biasanya membutuhkan beberapa detik.',
                ], 202);
            }

            return redirect()->route('wali_kelas.rapor.index')
                ->with('success', 'PDF sedang disiapkan. Silakan buka kembali beberapa saat lagi.');
        });
    }

    protected function expectsPdfJson(Request $request): bool
    {
        return $request->expectsJson() || $request->ajax();
    }

    public function requestPdf(Siswa $siswa, Request $request)
    {
        $type = $this->normalizeReportType($request->input('type', 'UTS'));
        $disposition = $request->input('disposition') === 'inline' ? 'inline' : 'attachment';
        $performance = ReportPerformanceTracker::startFlowIfEnabled(
            'pdf_request_pending',
            $type,
            $request->route()?->getName()
        );

        try {
            $tahunAjaranId = $this->getValidTahunAjaranId(
                $request->input('tahun_ajaran_id') ? (int) $request->input('tahun_ajaran_id') : null
            );

            if (!$tahunAjaranId) {
                return $this->failTahunAjaranNotSet($request, true);
            }
            $type = $this->resolveReportTypeForRequest($request, $tahunAjaranId, 'input');

            $this->authorizeWaliRaporAccess($siswa, $tahunAjaranId);

            if ($response = $this->ensureReportPeriodOpened($request, $type, $tahunAjaranId)) {
                return $response;
            }

            $kelas = $this->resolveRaporClass($siswa, $tahunAjaranId);
            $template = $this->getTemplateForSiswa($siswa, $type, $tahunAjaranId);

            if (!$template) {
                return $this->pdfTemplateUnavailableResponse($request, $siswa, $type, $tahunAjaranId, $kelas?->id);
            }

            $cachedPdf = PdfCacheService::getCachedPdf($siswa, $type, $tahunAjaranId);
            
            if ($cachedPdf) {
                ReportPerformanceTracker::setFlowTypeIfEnabled('pdf_request_cache_hit');

                return $this->pdfReadyResponse($request, $cachedPdf, $disposition, true);
            }

            ReportPerformanceTracker::setFlowTypeIfEnabled('pdf_request_queued');

            return $this->queuePdfGenerationResponse(
                $request,
                $siswa,
                $type,
                (int) $tahunAjaranId,
                $disposition,
                'pdf_request'
            );

        } catch (\Exception $e) {
            if ($e instanceof HttpExceptionInterface) {
                throw $e;
            }

            Log::error("Error in requestPdf", [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'status' => 'failed',
                'message' => 'PDF gagal disiapkan. Silakan coba lagi atau hubungi administrator.'
            ], 500);
        } finally {
            ReportPerformanceTracker::finishIfEnabled($performance);
        }
    }


    /**
     * Check PDF generation progress
     */
    public function checkPdfProgress(Request $request, string $requestId)
    {
        try {
            $progressKey = PdfCacheService::getProgressKey($requestId);
            $progress = Cache::get($progressKey);

            if (!$progress) {
                Log::warning("Progress not found", [
                    'request_id' => $requestId,
                    'progress_key' => $progressKey,
                ]);

                return response()->json([
                    'success' => false,
                    'status' => 'failed',
                    'message' => 'Progress tidak ditemukan. Request mungkin sudah kadaluarsa.',
                    'request_id' => $requestId,
                ], 404);
            }

            $this->authorizePdfProgress($progress);

            if (($progress['error'] ?? false) || ($progress['status'] ?? null) === 'failed') {
                return response()->json([
                    'success' => true,
                    'status' => 'failed',
                    'message' => $progress['message'] ?? 'PDF gagal disiapkan. Silakan coba lagi atau hubungi administrator.',
                    'request_id' => $requestId,
                    'progress' => $this->sanitizePdfProgress($progress),
                ]);
            }

            $siswaId = (int) ($progress['siswa_id'] ?? 0);
            $type = (string) ($progress['type'] ?? 'UTS');
            $tahunAjaranId = (int) ($progress['tahun_ajaran_id'] ?? 0);
            $siswa = $siswaId ? Siswa::find($siswaId) : null;

            if ($siswa && $tahunAjaranId) {
                $cachedPdf = PdfCacheService::getCachedPdf($siswa, $type, $tahunAjaranId);

                if ($cachedPdf) {
                    $disposition = $request->query('disposition') === 'inline' ? 'inline' : 'attachment';
                    $url = $this->createSecureRaporFileUrl(
                        $cachedPdf['path'],
                        $cachedPdf['filename'],
                        $disposition,
                        60
                    );

                    return response()->json([
                        'success' => true,
                        'status' => 'ready',
                        'ready' => true,
                        'cache_hit' => (bool) ($progress['cached'] ?? false),
                        'cached' => (bool) ($progress['cached'] ?? false),
                        'url' => $url,
                        'download_url' => $url,
                        'filename' => $cachedPdf['filename'],
                        'file_size' => $cachedPdf['file_size'] ?? null,
                        'request_id' => $requestId,
                        'progress' => $this->sanitizePdfProgress(array_merge($progress, [
                            'status' => 'ready',
                            'completed' => true,
                            'filename' => $cachedPdf['filename'],
                            'file_size' => $cachedPdf['file_size'] ?? null,
                        ])),
                    ]);
                }
            }

            if (($progress['completed'] ?? false) && ! ($progress['error'] ?? false)) {
                return response()->json([
                    'success' => true,
                    'status' => 'failed',
                    'message' => 'PDF belum tersedia. Silakan coba lagi atau hubungi administrator.',
                    'request_id' => $requestId,
                    'progress' => $this->sanitizePdfProgress($progress),
                ]);
            }

            return response()->json([
                'success' => true,
                'status' => 'processing',
                'ready' => false,
                'cache_hit' => false,
                'cached' => false,
                'message' => $progress['message'] ?? 'Sedang menyiapkan PDF rapor.',
                'progress' => $this->sanitizePdfProgress($progress),
                'request_id' => $requestId,
                'timestamp' => now()->toISOString()
            ]);

        } catch (\Exception $e) {
            if ($e instanceof HttpExceptionInterface) {
                throw $e;
            }

            Log::error("Error checking progress", [
                'request_id' => $requestId,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'status' => 'failed',
                'message' => 'Gagal memeriksa status PDF. Silakan coba lagi.',
                'request_id' => $requestId
            ], 500);
        }
    }

    protected function authorizePdfProgress(array $progress): void
    {
        $currentGuruId = auth()->guard('guru')->id();

        abort_unless(
            $currentGuruId &&
            isset($progress['user_id']) &&
            (string) $progress['user_id'] === (string) $currentGuruId,
            403
        );

        $siswaId = (int) ($progress['siswa_id'] ?? 0);
        $tahunAjaranId = (int) ($progress['tahun_ajaran_id'] ?? 0);

        if ($siswaId && $tahunAjaranId && ($siswa = Siswa::find($siswaId))) {
            $this->authorizeWaliRaporAccess($siswa, $tahunAjaranId);
        }
    }

    protected function sanitizePdfProgress(array $progress): array
    {
        return collect($progress)
            ->only([
                'status',
                'stage',
                'message',
                'completed',
                'error',
                'request_id',
                'percentage',
                'cached',
                'filename',
                'file_size',
                'updated_at',
                'timestamp',
            ])
            ->all();
    }

    /**
     * Clear PDF cache for student
     */
    public function clearPdfCache(Siswa $siswa, Request $request)
    {
        $tahunAjaranId = $this->resolveRaporTahunAjaranId($request);
        abort_unless($tahunAjaranId, 403);
        $this->authorizeWaliRaporAccess($siswa, $tahunAjaranId);

        try {
            PdfCacheService::clearStudentCache($siswa);
            
            return response()->json([
                'success' => true,
                'message' => 'Cache PDF siswa berhasil dibersihkan'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Gagal membersihkan cache: ' . $e->getMessage()
            ], 500);
        }
    }
    public function preview(ReportTemplate $template)
    {
        try {
            $filePath = storage_path('app/public/' . $template->path);
            
            if (!file_exists($filePath)) {
                return redirect()->back()->with('error', 'File template tidak ditemukan');
            }
            
            // **CRITICAL: Clean all output buffers**
            while (ob_get_level()) {
                ob_end_clean();
            }
            
            // Generate clean filename
            $cleanFilename = preg_replace('/^\d+_/', '', $template->filename);
            $downloadFilename = 'Template_' . $template->type . '_' . $cleanFilename;
            
            // **CRITICAL: Set proper headers for DOCX**
            $headers = [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                'Content-Disposition' => 'attachment; filename="' . $downloadFilename . '"',
                'Content-Length' => filesize($filePath),
                'Content-Transfer-Encoding' => 'binary',
                'Cache-Control' => 'private, no-transform, no-store, must-revalidate',
                'Pragma' => 'no-cache',
                'Expires' => '0',
                'Accept-Ranges' => 'bytes',
            ];
            
            // Log for debugging
            Log::info('Downloading template file', [
                'template_id' => $template->id,
                'file_path' => $filePath,
                'file_size' => filesize($filePath),
                'download_name' => $downloadFilename
            ]);
            
            // Use Laravel's download response
            return response()->download($filePath, $downloadFilename, $headers);
            
        } catch (\Exception $e) {
            Log::error('Error downloading template: ' . $e->getMessage(), [
                'template_id' => $template->id,
                'trace' => $e->getTraceAsString()
            ]);
            
            return redirect()->back()->with('error', 'Gagal mendownload template: ' . $e->getMessage());
        }
    }

    public function generateReport(Request $request, Siswa $siswa)
    {
        $type = $this->normalizeReportType($request->input('type', 'UTS'));
        $action = $request->input('action', 'download');
        $tahunAjaranId = null;
        $performance = ReportPerformanceTracker::startFlowIfEnabled(
            'docx_generate',
            $type,
            $request->route()?->getName()
        );
        
        try {
            $tahunAjaranId = ReportPerformanceTracker::measureSegment('context', function () use ($request) {
                return $this->getValidTahunAjaranId(
                    $request->input('tahun_ajaran_id') ? (int) $request->input('tahun_ajaran_id') : null
                );
            });

            if (!$tahunAjaranId) {
                return $this->failTahunAjaranNotSet($request, true);
            }
            $type = $this->resolveReportTypeForRequest($request, $tahunAjaranId, 'input');

            ReportPerformanceTracker::measureSegment('authorization', function () use ($siswa, $tahunAjaranId) {
                $this->authorizeWaliRaporAccess($siswa, $tahunAjaranId);
            });

            if ($response = $this->ensureReportPeriodOpened($request, $type, $tahunAjaranId)) {
                return $response;
            }

            \Log::info('Generate report request', [
                'siswa_id' => $siswa->id,
                'type' => $type,
                'tahun_ajaran_id' => $tahunAjaranId,
                'action' => $action
            ]);
            
            // Get the template based on the report type requested
            $template = ReportPerformanceTracker::measureSegment('context', function () use ($siswa, $type, $tahunAjaranId) {
                return $this->getTemplateForSiswa($siswa, $type, $tahunAjaranId);
            });
            
            if (!$template) {
                return response()->json([
                    'success' => false,
                    'message' => $this->missingActiveTemplateMessage($type),
                    'error_type' => 'template_missing'
                ], 404);
            }

            $cachedDocx = PdfCacheService::getCachedDocx($siswa, $type, $tahunAjaranId);
            if ($cachedDocx) {
                ReportPerformanceTracker::setFlowTypeIfEnabled('docx_cache_hit');

                $cachedPath = storage_path('app/public/' . $cachedDocx['path']);
                $cachedFilename = $cachedDocx['filename'] ?? basename($cachedPath);

                if ($action == 'preview') {
                    return ReportPerformanceTracker::measureSegment('response', function () use ($cachedDocx, $cachedFilename) {
                        return response()->json([
                            'success' => true,
                            'file_url' => $this->createSecureRaporFileUrl(
                                $cachedDocx['path'],
                                $cachedFilename,
                                'attachment'
                            ),
                            'filename' => $cachedFilename,
                            'cache_hit' => true,
                        ]);
                    });
                }

                return ReportPerformanceTracker::measureSegment('response', function () use ($cachedPath, $cachedFilename) {
                    return $this->downloadDocxFile($cachedPath, $cachedFilename);
                });
            }

            ReportPerformanceTracker::setFlowTypeIfEnabled('docx_cache_miss');
            
            // Better validation - verify data for the CURRENT semester, not based on report type
            $tahunAjaran = ReportPerformanceTracker::measureSegment('context', fn () => \App\Models\TahunAjaran::find($tahunAjaranId));
            $currentSemester = $tahunAjaran ? $tahunAjaran->semester : ($type === 'UTS' ? 1 : 2);
            
            // Check for proper data in the current semester
            [$hasNilai, $hasAbsensi] = ReportPerformanceTracker::measureSegment('preload', function () use ($siswa, $currentSemester, $tahunAjaranId) {
                return [
                    $siswa->nilais()
                        ->whereHas('mataPelajaran', function($q) use ($currentSemester) {
                            $q->where('semester', $currentSemester);
                        })
                        ->where('tahun_ajaran_id', $tahunAjaranId)
                        ->where('is_submitted', true)
                        ->exists(),
                    $siswa->absensi()
                        ->where('semester', $currentSemester)
                        ->where('tahun_ajaran_id', $tahunAjaranId)
                        ->exists(),
                ];
            });
                
            if (!$hasNilai || !$hasAbsensi) {
                \Log::warning('Data incomplete for report generation', [
                    'siswa_id' => $siswa->id,
                    'type' => $type,
                    'semester' => $currentSemester,
                    'hasNilai' => $hasNilai,
                    'hasAbsensi' => $hasAbsensi
                ]);
                
                return response()->json([
                    'success' => false,
                    'message' => "Data siswa belum lengkap untuk menghasilkan rapor. Pastikan nilai akhir dan data absensi untuk semester {$currentSemester} sudah diisi.",
                    'error_type' => 'data_incomplete'
                ], 422);
            }
            
            // Continue with report generation
            $processor = new \App\Services\RaporTemplateProcessor($template, $siswa, $type, $tahunAjaranId);
            $result = $processor->generate();
            
            // Save history
            $this->saveGenerationHistory($siswa, $template, $type, $tahunAjaranId, $result['path']);
            
            // **CRITICAL: Get full path dan verify file**
            $fullPath = storage_path('app/public/' . $result['path']);
            
            if (!file_exists($fullPath)) {
                return response()->json([
                    'success' => false,
                    'message' => 'File rapor tidak ditemukan setelah diproses.',
                    'error_type' => 'file_missing'
                ], 404);
            }
            
            // **CRITICAL: Verify file integrity**
            $fileSize = filesize($fullPath);
            if ($fileSize == 0) {
                return response()->json([
                    'success' => false,
                    'message' => 'File rapor kosong. Terjadi kesalahan dalam pemrosesan.',
                    'error_type' => 'file_empty'
                ], 500);
            }
            
            // **CRITICAL: Verify DOCX format**
            if (!$this->isValidDocxFile($fullPath)) {
                return response()->json([
                    'success' => false,
                    'message' => 'File rapor tidak valid. Format file rusak.',
                    'error_type' => 'file_corrupt'
                ], 500);
            }
            
            \Log::info('Report generated successfully', [
                'file_path' => $fullPath,
                'file_size' => $fileSize,
                'action' => $action
            ]);

            $cachedDocx = PdfCacheService::cacheDocx($siswa, $type, $tahunAjaranId, $fullPath, $result['filename']);
            $responsePath = $cachedDocx
                ? storage_path('app/public/' . $cachedDocx['path'])
                : $fullPath;
            $responseFilename = $cachedDocx['filename'] ?? $result['filename'];
            $responseStoragePath = $cachedDocx['path'] ?? $result['path'];
            
            // Handle preview vs download
            if ($action == 'preview') {
                return ReportPerformanceTracker::measureSegment('response', function () use ($responseStoragePath, $responseFilename) {
                    return response()->json([
                        'success' => true,
                        'file_url' => $this->createSecureRaporFileUrl(
                            $responseStoragePath,
                            $responseFilename,
                            'attachment'
                        ),
                        'filename' => $responseFilename,
                        'cache_hit' => false,
                    ]);
                });
            }
            
            // **SOLUTION: Download dengan headers yang BENAR untuk DOCX**
            return ReportPerformanceTracker::measureSegment('response', function () use ($responsePath, $responseFilename) {
                return $this->downloadDocxFile($responsePath, $responseFilename);
            });
            
        } 
        catch (\App\Exceptions\RaporException $e) {
            \Log::error('RaporException in generateReport: ' . $e->getMessage(), [
                'siswa_id' => $siswa->id,
                'type' => $type,
                'tahun_ajaran_id' => $tahunAjaranId,
                'error_type' => $e->getErrorType()
            ]);
            
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
                'error_type' => $e->getErrorType()
            ], 422);
        } 
        catch (\Exception $e) {
            \Log::error('Error in generateReport: ' . $e->getMessage(), [
                'siswa_id' => $siswa->id,
                'type' => $type,
                'tahun_ajaran_id' => $tahunAjaranId
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Terjadi kesalahan saat generate rapor: ' . $e->getMessage()
            ], 500);
        } finally {
            ReportPerformanceTracker::finishIfEnabled($performance);
        }
    }

    private function isValidDocxFile($filePath)
    {
        if (!file_exists($filePath) || !is_readable($filePath)) {
            return false;
        }
        
        if (filesize($filePath) == 0) {
            return false;
        }
        
        // DOCX files are ZIP files, should start with 'PK'
        $handle = fopen($filePath, 'rb');
        if (!$handle) {
            return false;
        }
        
        $firstBytes = fread($handle, 2);
        fclose($handle);
        
        $isValid = $firstBytes === 'PK';
        
        if (!$isValid) {
            \Log::error('Invalid DOCX file detected', [
                'file_path' => $filePath,
                'first_bytes' => bin2hex($firstBytes),
                'expected' => 'PK (504B in hex)'
            ]);
        }
        
        return $isValid;
    }

    private function downloadDocxFile($filePath, $filename)
    {
        // **CRITICAL: Bersihkan semua output buffer**
        while (ob_get_level()) {
            ob_end_clean();
        }
        
        // **CRITICAL: Headers yang PROPER untuk DOCX**
        $headers = [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
            'Content-Length' => filesize($filePath),
            'Content-Transfer-Encoding' => 'binary',
            'Cache-Control' => 'private, no-transform, no-store, must-revalidate',
            'Pragma' => 'no-cache',
            'Expires' => '0',
            'Accept-Ranges' => 'bytes',
        ];
        
        \Log::info('Downloading DOCX file', [
            'file_path' => $filePath,
            'filename' => $filename,
            'file_size' => filesize($filePath)
        ]);
        
        // **METHOD 1: Try Laravel's download response first**
        try {
            return response()->download($filePath, $filename, $headers);
        } catch (\Exception $e) {
            \Log::warning('Laravel download failed, using streamed response', [
                'error' => $e->getMessage()
            ]);
            
            // **METHOD 2: Fallback ke StreamedResponse**
            return response()->streamDownload(function() use ($filePath) {
                $handle = fopen($filePath, 'rb'); // Binary mode!
                
                if ($handle) {
                    while (!feof($handle)) {
                        $chunk = fread($handle, 8192); // 8KB chunks
                        if ($chunk !== false) {
                            echo $chunk;
                            
                            // Flush output buffer
                            if (ob_get_level()) {
                                ob_flush();
                            }
                            flush();
                        }
                    }
                    fclose($handle);
                }
            }, $filename, $headers);
        }
    }

    protected function saveGenerationHistory(Siswa $siswa, ReportTemplate $template, $type, $tahunAjaranId = null, $filePath = null)
    {
        try {
            $tahunAjaranId = $tahunAjaranId ?: session('tahun_ajaran_id');
            $tahunAjaran = $tahunAjaranId ? TahunAjaran::find($tahunAjaranId) : null;
            $kelas = $tahunAjaranId ? $this->resolveRaporClass($siswa, (int) $tahunAjaranId) : null;

            \App\Models\ReportGeneration::create([
                'siswa_id' => $siswa->id,
                'kelas_id' => $kelas?->id ?: $siswa->kelas_id,
                'report_template_id' => $template->id,
                'generated_file' => $filePath, // Gunakan path file yang diberikan
                'type' => $type,
                'tahun_ajaran' => $tahunAjaran?->tahun_ajaran ?: $template->tahun_ajaran,
                'semester' => $tahunAjaran?->semester ?: $template->semester,
                'tahun_ajaran_id' => $tahunAjaranId,
                'generated_at' => now(),
                'generated_by' => auth()->id() ?? auth()->guard('guru')->id()
            ]);
    
            \Log::info('History generation saved successfully', [
                'siswa_id' => $siswa->id,
                'template_id' => $template->id,
                'file_path' => $filePath
            ]);
        } catch (\Exception $e) {
            \Log::error('Error saving generation history: ' . $e->getMessage(), [
                'siswa_id' => $siswa->id,
                'template_id' => $template->id,
                'tahun_ajaran_id' => $tahunAjaranId,
                'file_path' => $filePath
            ]);
        }
    }
    

    /**
     * Regenerate rapor dari history
     * 
     * @param ReportGeneration $report
     * @return \Illuminate\Http\Response
     */
    public function regenerateHistoryRapor(ReportGeneration $report)
    {
        $performance = ReportPerformanceTracker::startFlowIfEnabled(
            'docx_regenerate',
            $report->type,
            request()->route()?->getName()
        );

        try {
            // Ambil data yang diperlukan
            [$siswa, $template, $type, $tahunAjaranId] = ReportPerformanceTracker::measureSegment('context', function () use ($report) {
                return [
                    $report->siswa,
                    $report->template,
                    $report->type,
                    $report->tahun_ajaran_id,
                ];
            });
            
            if (!$template) {
                return response()->json([
                    'success' => false,
                    'message' => 'Template rapor tidak ditemukan. Harap upload template baru.'
                ], 404);
            }
            
            if (!$siswa) {
                return response()->json([
                    'success' => false,
                    'message' => 'Data siswa tidak ditemukan.'
                ], 404);
            }
            
            // Generate rapor baru
            $processor = new \App\Services\RaporTemplateProcessor($template, $siswa, $type, $tahunAjaranId);
            $result = $processor->generate(true); // Bypass validation
            
            if (!$result['success']) {
                throw new \Exception($result['message'] ?? 'Gagal regenerate rapor');
            }
            
            // Update record dengan file baru
            $report->generated_file = $result['path'];
            $report->generated_at = now();
            $report->save();
            
            return ReportPerformanceTracker::measureSegment('response', fn () => response()->json([
                'success' => true,
                'message' => 'Rapor berhasil digenerate ulang'
            ]));
        } catch (\Exception $e) {
            \Log::error('Error regenerating report: ' . $e->getMessage(), [
                'report_id' => $report->id,
                'stack_trace' => $e->getTraceAsString()
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Terjadi kesalahan saat regenerasi rapor: ' . $e->getMessage()
            ], 500);
        } finally {
            ReportPerformanceTracker::finishIfEnabled($performance);
        }
    }
    
    /**
     * Preview rapor dari history
     * 
     * @param ReportGeneration $report
     * @return \Illuminate\Http\Response
     */
    public function previewHistoryRapor(ReportGeneration $report)
    {
        try {
            $report->loadMissing(['kelas.tahunAjaran']);
            $reportSemester = (int) ($report->semester ?: ($report->type === 'UTS' ? 1 : 2));

            // Ambil data siswa dengan relasi yang diperlukan
            $siswa = $report->siswa()->with([
                'nilais' => function($query) use ($report, $reportSemester) {
                    // Filter nilai sesuai dengan semester dan tahun ajaran rapor
                    $query->whereHas('mataPelajaran', function($q) use ($reportSemester) {
                        $q->where('semester', $reportSemester);
                    })
                    ->when($report->tahun_ajaran_id, function($q) use ($report) {
                        $q->where('tahun_ajaran_id', $report->tahun_ajaran_id);
                    })
                    ->where('is_submitted', true);
                },
                'nilais.mataPelajaran',
                'nilaiEkstrakurikuler' => function($query) use ($report, $reportSemester) {
                    $query->when($report->tahun_ajaran_id, function($q) use ($report) {
                        $q->where('tahun_ajaran_id', $report->tahun_ajaran_id);
                    })->where('semester', $reportSemester);
                },
                'nilaiEkstrakurikuler.ekstrakurikuler',
                'absensi' => function($query) use ($report, $reportSemester) {
                    $query->where('semester', $reportSemester)
                        ->when($report->tahun_ajaran_id, function($q) use ($report) {
                            $q->where('tahun_ajaran_id', $report->tahun_ajaran_id);
                        });
                }
            ])->first();
            
            if (!$siswa) {
                return redirect()->back()->with('error', 'Data siswa tidak ditemukan.');
            }

            if ($report->kelas) {
                $siswa->setRelation('kelas', $report->kelas);
            } elseif ($report->tahun_ajaran_id) {
                $this->applyRaporClassContext($siswa, (int) $report->tahun_ajaran_id);
            } else {
                $siswa->loadMissing('kelas');
            }
            
            // Render view ke HTML
            $html = view('admin.report.preview_history', [
                'siswa' => $siswa,
                'report' => $report,
                'kelas' => $siswa->kelas,
                'semester' => $reportSemester,
                'tahunAjaranId' => $report->tahun_ajaran_id
            ])->render();
            
            return response()->json([
                'success' => true,
                'html' => $html
            ]);
        } catch (\Exception $e) {
            \Log::error('Error in previewHistoryRapor: ' . $e->getMessage());
            \Log::error($e->getTraceAsString());
            
            return response()->json([
                'success' => false,
                'message' => 'Terjadi kesalahan saat memuat preview rapor: ' . $e->getMessage()
            ], 500);
        }
    }


    public function indexWaliKelas()
    {
        $guru = auth()->guard('guru')->user();
        $tahunAjaranId = session('tahun_ajaran_id');
        
        // Ambil data tahun ajaran untuk mendapatkan semester yang benar
        $tahunAjaran = \App\Models\TahunAjaran::find($tahunAjaranId);
        if (!$tahunAjaran) {
            return redirect()->back()->with('error', 'Data tahun ajaran tidak ditemukan.');
        }
        
        // PENTING: Semester dan tipe adalah dua hal berbeda!
        // Semester bisa 1/2 (ganjil/genap)
        // Tipe bisa UTS/UAS (tengah semester/akhir semester)
        $semester = $tahunAjaran->semester; // 1 atau 2
        $openedReportType = $this->openedReportTypeForYear((int) $tahunAjaranId);
        $type = $this->normalizeReportType(request('type', $openedReportType));

        if ($type !== $openedReportType) {
            return redirect()
                ->route('wali_kelas.rapor.index', [
                    'type' => $openedReportType,
                    'tahun_ajaran_id' => $tahunAjaranId,
                ])
                ->with('error', app(ReportPeriodService::class)->unopenedMessage($type));
        }
        
        \Log::info('Rapor WaliKelas - Info penting:', [
            'semester' => $semester,
            'type' => $type,
            'opened_report_type' => $openedReportType,
            'tahun_ajaran_id' => $tahunAjaranId,
            'kombinasi_valid' => "Semester {$semester} - {$type}"
        ]);
        
        // Ambil kelas yang diwalikan
        $kelas = DB::table('guru_kelas')
            ->join('kelas', 'guru_kelas.kelas_id', '=', 'kelas.id')
            ->where('guru_kelas.guru_id', $guru->id)
            ->where('guru_kelas.is_wali_kelas', true)
            ->where('guru_kelas.role', 'wali_kelas')
            ->where('kelas.tahun_ajaran_id', $tahunAjaranId)
            ->select('kelas.*')
            ->first();
        
        if (!$kelas) {
            return redirect()->back()->with('error', 'Anda tidak menjadi wali kelas untuk tahun ajaran yang dipilih.');
        }
        
        $kelasModel = Kelas::with([
                'mataPelajarans' => function ($query) use ($tahunAjaranId, $semester) {
                    $query->where('semester', $semester)
                        ->when($tahunAjaranId, function ($subQuery) use ($tahunAjaranId) {
                            return $subQuery->where('tahun_ajaran_id', $tahunAjaranId);
                        });
                },
            ])->find($kelas->id);

        // Query siswa
        $siswa = app(SiswaKelasSemesterResolver::class)
            ->studentQueryForClass((int) $kelas->id, (int) $tahunAjaranId, (int) $semester, true)
            ->with([
                'nilais' => function ($query) use ($tahunAjaranId, $semester) {
                    $query->where('tahun_ajaran_id', $tahunAjaranId)
                        ->whereHas('mataPelajaran', function ($subQuery) use ($semester) {
                            $subQuery->where('semester', $semester);
                        })
                        ->where('is_submitted', true);
                },
                'nilais.mataPelajaran',
                'absensi' => function ($query) use ($tahunAjaranId, $semester) {
                    $query->where('semester', $semester)
                        ->where('tahun_ajaran_id', $tahunAjaranId);
                },
            ])
            ->withCount([
                'nilais as completed_nilai_count' => function ($query) use ($tahunAjaranId, $semester) {
                    $query->where('tahun_ajaran_id', $tahunAjaranId)
                        ->where('is_submitted', true)
                        ->whereHas('mataPelajaran', function ($subQuery) use ($semester) {
                            $subQuery->where('semester', $semester);
                        });
                }
            ])
            ->orderBy('nama')
            ->get();

        $siswa->each(function (Siswa $student) use ($kelasModel) {
            if ($kelasModel) {
                $student->setRelation('kelas', $kelasModel);
            }
        });

        $totalMapelCount = MataPelajaran::where('kelas_id', $kelas->id)
            ->whereNull('deleted_at')
            ->where('semester', $semester)
            ->when($tahunAjaranId, function ($query) use ($tahunAjaranId) {
                return $query->where('tahun_ajaran_id', $tahunAjaranId);
            })
            ->count();

        $completedMapelCounts = DB::table('nilais')
            ->join('mata_pelajarans', 'nilais.mata_pelajaran_id', '=', 'mata_pelajarans.id')
            ->where('mata_pelajarans.kelas_id', $kelas->id)
            ->where('mata_pelajarans.semester', $semester)
            ->where('nilais.is_submitted', true)
            ->whereNull('nilais.deleted_at')
            ->whereNull('mata_pelajarans.deleted_at')
            ->when($tahunAjaranId, function ($query) use ($tahunAjaranId) {
                return $query->where('nilais.tahun_ajaran_id', $tahunAjaranId)
                    ->where('mata_pelajarans.tahun_ajaran_id', $tahunAjaranId);
            })
            ->groupBy('nilais.siswa_id')
            ->select('nilais.siswa_id', DB::raw('COUNT(DISTINCT nilais.mata_pelajaran_id) as completed'))
            ->pluck('completed', 'nilais.siswa_id');
        
        // Prepare data for each student
        $diagnosisResults = [];
        $nilaiCounts = [];
        $completionData = [];
        
        foreach ($siswa as $s) {
            // Diagnosis tetap berdasarkan tipe, tapi perlu disesuaikan lagi
            $diagnosisResults[$s->id] = $s->diagnoseDataCompleteness($type);
            
            // Hitung jumlah nilai yang sudah memiliki nilai_akhir_rapor
            // PENTING: Untuk UTS/UAS di semester yang sama, perlu dibedakan lagi
            // dengan field tambahan di tabel nilai
            $completedCount = min((int) ($completedMapelCounts[$s->id] ?? 0), $totalMapelCount);
            $nilaiCounts[$s->id] = $completedCount;
            $completionData[$s->id] = [
                'completed' => $completedCount,
                'total' => $totalMapelCount,
                'missing' => max(0, $totalMapelCount - $completedCount),
                'status' => $completedCount === 0
                    ? 'empty'
                    : ($completedCount >= $totalMapelCount && $totalMapelCount > 0 ? 'complete' : 'partial'),
            ];
        }

        $pdfTemplateAvailability = [];
        $pdfStatuses = [];
        foreach ($siswa as $student) {
            $pdfTemplateAvailability[$student->id] = [
                'UTS' => $openedReportType === 'UTS' && (bool) $this->getTemplateForSiswa($student, 'UTS', $tahunAjaranId),
                'UAS' => $openedReportType === 'UAS' && (bool) $this->getTemplateForSiswa($student, 'UAS', $tahunAjaranId),
            ];
            $pdfStatuses[$student->id] = [
                'UTS' => $openedReportType === 'UTS' ? PdfCacheService::getPdfPreparationStatus($student, 'UTS', $tahunAjaranId) : 'missing',
                'UAS' => $openedReportType === 'UAS' ? PdfCacheService::getPdfPreparationStatus($student, 'UAS', $tahunAjaranId) : 'missing',
            ];
        }

        if (config('report.pdf_auto_prepare.enabled', false)) {
            $autoPrepare = app(ReportPdfAutoPrepareService::class);

            foreach ($siswa as $student) {
                if (($pdfTemplateAvailability[$student->id][$openedReportType] ?? false) &&
                    ($pdfStatuses[$student->id][$openedReportType] ?? 'missing') === 'missing') {
                    $autoPrepare->scheduleForStudent($student, (int) $tahunAjaranId, [$openedReportType], 'report_page_warmup');
                    $pdfStatuses[$student->id][$openedReportType] = PdfCacheService::getPdfPreparationStatus($student, $openedReportType, (int) $tahunAjaranId);
                }
            }
        }
        
        return view('wali_kelas.rapor.index', [
            'siswa' => $siswa,
            'diagnosisResults' => $diagnosisResults,
            'nilaiCounts' => $nilaiCounts,
            'completionData' => $completionData,
            'pdfTemplateAvailability' => $pdfTemplateAvailability,
            'pdfStatuses' => $pdfStatuses,
            'type' => $type, // Kirim ke view
            'openedReportType' => $openedReportType,
            'semester' => $semester, // Kirim ke view
            'tahunAjaran' => $tahunAjaran,
            'kelas' => $kelas,
            'pdfAvailable' => app(\App\Services\DocumentConversionService::class)->isLibreOfficeAvailable(),
        ]);
    }

    public function pdfStatuses(Request $request)
    {
        $type = $this->normalizeReportType($request->query('type', 'UTS'));

        if (! in_array($type, ['UTS', 'UAS'], true)) {
            return response()->json([
                'success' => false,
                'message' => 'Tipe rapor tidak valid.',
            ], 422);
        }

        $tahunAjaranId = $this->getValidTahunAjaranId(
            $request->query('tahun_ajaran_id') ? (int) $request->query('tahun_ajaran_id') : null
        );

        if (! $tahunAjaranId) {
            return $this->failTahunAjaranNotSet($request, true);
        }
        $type = $this->resolveReportTypeForRequest($request, $tahunAjaranId);

        $tahunAjaran = TahunAjaran::find($tahunAjaranId);
        if (! $tahunAjaran) {
            return $this->failTahunAjaranNotSet($request, true);
        }

        if ($response = $this->ensureReportPeriodOpened($request, $type, $tahunAjaranId)) {
            return $response;
        }

        $studentIds = collect($request->query('student_ids', []))
            ->flatten()
            ->map(fn ($id) => (int) $id)
            ->filter(fn ($id) => $id > 0)
            ->unique()
            ->values();

        if ($studentIds->count() > 50) {
            return response()->json([
                'success' => false,
                'message' => 'Maksimal 50 status PDF dapat diperiksa sekaligus.',
            ], 422);
        }

        if ($studentIds->isEmpty()) {
            return response()->json([
                'success' => true,
                'type' => $type,
                'statuses' => [],
            ]);
        }

        $guru = auth()->guard('guru')->user();
        abort_unless($guru, 403);

        $kelas = DB::table('guru_kelas')
            ->join('kelas', 'guru_kelas.kelas_id', '=', 'kelas.id')
            ->where('guru_kelas.guru_id', $guru->id)
            ->where('guru_kelas.is_wali_kelas', true)
            ->where('guru_kelas.role', 'wali_kelas')
            ->where('kelas.tahun_ajaran_id', $tahunAjaranId)
            ->whereNull('kelas.deleted_at')
            ->select('kelas.*')
            ->first();

        abort_unless($kelas, 403);

        $students = app(SiswaKelasSemesterResolver::class)
            ->studentQueryForClass((int) $kelas->id, (int) $tahunAjaranId, (int) $tahunAjaran->semester, true)
            ->whereIn('siswas.id', $studentIds->all())
            ->get();

        $authorizedIds = $students->pluck('id')->map(fn ($id) => (int) $id)->sort()->values()->all();
        $requestedIds = $studentIds->sort()->values()->all();

        abort_unless($authorizedIds === $requestedIds, 403);

        $statuses = [];
        foreach ($students as $student) {
            $statuses[$student->id] = PdfCacheService::getPdfPreparationStatus($student, $type, $tahunAjaranId);
        }

        return response()->json([
            'success' => true,
            'type' => $type,
            'tahun_ajaran_id' => $tahunAjaranId,
            'statuses' => $statuses,
        ]);
    }

    public function activate(ReportTemplate $template)
    {
        try {
            if ($template->is_active) {
                $template->update(['is_active' => false]);

                return response()->json([
                    'success' => true,
                    'message' => 'Template berhasil dinonaktifkan. Jika tidak ada template ' . $template->type . ' aktif lain yang sesuai, rapor ' . $template->type . ' belum dapat dibuat.',
                    'status' => 'inactive'
                ]);
            }

            \Log::info('Activating template', [
                'template_id' => $template->id,
                'type' => $template->type,
                'kelas_id' => $template->kelas_id
            ]);

            $template->update(['is_active' => true]);
            
            return response()->json([
                'success' => true,
                'message' => 'Template berhasil diaktifkan',
                'status' => 'active'
            ]);
        } catch (\Exception $e) {
            \Log::error('Error activating template: ' . $e->getMessage(), [
                'template_id' => $template->id,
                'stack_trace' => $e->getTraceAsString()
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Gagal mengubah status template: ' . $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * Generate batch report for multiple students
     * 
     * @param Request $request
     * @return \Illuminate\Http\Response
     */
    public function generateBatchReport(Request $request)
    {
        $type = $this->normalizeReportType($request->input('type', 'UTS'));
        $guru = null;
        $performance = ReportPerformanceTracker::startFlowIfEnabled(
            'batch_docx',
            $type,
            $request->route()?->getName()
        );

        try {
            $siswaIds = $request->input('siswa_ids', []);
            $tahunAjaranId = ReportPerformanceTracker::measureSegment('context', function () use ($request) {
                return $this->getValidTahunAjaranId(
                    $request->input('tahun_ajaran_id') ? (int) $request->input('tahun_ajaran_id') : null
                );
            });

            if (!$tahunAjaranId) {
                return $this->failTahunAjaranNotSet($request, true);
            }
            $type = $this->resolveReportTypeForRequest($request, $tahunAjaranId, 'input');

            if ($response = $this->ensureReportPeriodOpened($request, $type, $tahunAjaranId)) {
                return $response;
            }
            
            // Get current semester from tahun ajaran
            $tahunAjaran = ReportPerformanceTracker::measureSegment('context', fn () => \App\Models\TahunAjaran::find($tahunAjaranId));
            $currentSemester = $tahunAjaran ? $tahunAjaran->semester : 1;
            
            // Log for debugging
            \Log::info('Batch report generation requested', [
                'siswa_count' => count($siswaIds),
                'type' => $type,
                'tahun_ajaran_id' => $tahunAjaranId,
                'current_semester' => $currentSemester
            ]);
            
            // Validasi siswa
            $guru = ReportPerformanceTracker::measureSegment('authorization', fn () => auth()->guard('guru')->user());
            ReportPerformanceTracker::measureSegment('authorization', function () use ($guru) {
                abort_unless($guru && session('selected_role') === 'wali_kelas', 403);
            });

            $kelas = ReportPerformanceTracker::measureSegment('context', function () use ($guru, $tahunAjaranId) {
                return DB::table('guru_kelas')
                    ->join('kelas', 'guru_kelas.kelas_id', '=', 'kelas.id')
                    ->where('guru_kelas.guru_id', $guru->id)
                    ->where('guru_kelas.is_wali_kelas', true)
                    ->where('guru_kelas.role', 'wali_kelas')
                    ->where('kelas.tahun_ajaran_id', $tahunAjaranId)
                    ->select('kelas.*')
                    ->first();
            });
            
            if (!$kelas) {
                throw new \Exception('Anda tidak memiliki kelas yang diwalikan');
            }
            
            // Validasi siswa IDs
            if (empty($siswaIds)) {
                throw new \Exception('Tidak ada siswa yang dipilih');
            }
            
            // Verifikasi siswa berdasarkan enrollment semester/tahun ajaran
            $authorizedStudentIds = ReportPerformanceTracker::measureSegment('authorization', function () use ($kelas, $tahunAjaranId, $currentSemester) {
                return app(SiswaKelasSemesterResolver::class)
                    ->studentsForClass((int) $kelas->id, (int) $tahunAjaranId, (int) $currentSemester, true)
                    ->pluck('id')
                    ->all();
            });

            $siswaList = ReportPerformanceTracker::measureSegment('preload', function () use ($siswaIds, $authorizedStudentIds) {
                return Siswa::whereIn('id', $siswaIds)
                    ->whereIn('id', $authorizedStudentIds)
                    ->get();
            });
                
            if ($siswaList->count() !== count($siswaIds)) {
                throw new \Exception('Cetak Semua Rapor Masih Maintenance di Tahun Ajaran ini, Harap Gunakan Fitur Download Rapor Satu per Satu di Icon Aksi');
            }
            
            // Cek template untuk tipe rapor yang diminta
            $template = ReportPerformanceTracker::measureSegment('context', function () use ($type, $kelas, $tahunAjaranId) {
                return ReportTemplate::where([
                        'type' => $type,
                        'is_active' => true,
                    ])
                    ->where(function($query) use ($kelas) {
                        $query->where('kelas_id', $kelas->id)
                            ->orWhereHas('kelasList', function($q) use ($kelas) {
                                $q->where('kelas_id', $kelas->id);
                            })
                            ->orWhereNull('kelas_id'); // Template global
                    })
                    ->when($tahunAjaranId, function($query) use ($tahunAjaranId) {
                        return $query->where('tahun_ajaran_id', $tahunAjaranId);
                    })
                    ->first();
            });
            
            if (!$template) {
                throw new \Exception("Tidak ada template {$type} aktif untuk kelas ini di tahun ajaran yang dipilih");
            }
            
            // Persiapkan tracking dan files
            $successSiswa = [];
            $errorSiswa = [];
            $files = [];
            
            // Buat direktori di public yang bisa diakses langsung
            $timestamp = date('Ymd_His');
            $publicDir = public_path('downloads/rapor_batch_' . $timestamp);
            if (!file_exists($publicDir)) {
                mkdir($publicDir, 0755, true);
            }
            
            // Nama file ZIP yang akan dibuat di direktori public
            $zipName = "Rapor_Batch_{$type}_{$kelas->nama_kelas}_{$timestamp}.zip";
            $zipPath = $publicDir . '/' . $zipName;
            $webPath = 'downloads/rapor_batch_' . $timestamp . '/' . $zipName;
            
            // Memproses setiap siswa
            foreach ($siswaList as $index => $siswa) {
                try {
                    // Validasi data siswa berdasarkan semester SAAT INI
                    // Cek nilai di semester yang aktif saat ini
                    $hasNilai = $siswa->nilais()
                        ->whereHas('mataPelajaran', function($q) use ($currentSemester) {
                            $q->where('semester', $currentSemester);
                        })
                        ->when($tahunAjaranId, function($query) use ($tahunAjaranId) {
                            return $query->where('tahun_ajaran_id', $tahunAjaranId);
                        })
                        ->where('is_submitted', true)
                        ->exists();
                        
                    // Cek kehadiran di semester yang aktif saat ini
                    $hasAbsensi = $siswa->absensi()
                        ->where('semester', $currentSemester)
                        ->when($tahunAjaranId, function($query) use ($tahunAjaranId) {
                            return $query->where('tahun_ajaran_id', $tahunAjaranId);
                        })
                        ->exists();
                        
                    if (!$hasNilai || !$hasAbsensi) {
                        throw new \Exception("Data nilai atau kehadiran belum lengkap untuk semester {$currentSemester}");
                    }
                    
                    // Generate rapor
                    $processor = new \App\Services\RaporTemplateProcessor($template, $siswa, $type, $tahunAjaranId);
                    $result = $processor->generate();
                    
                    // Salin file ke direktori public
                    $sourcePath = storage_path('app/public/' . $result['path']);
                    $sanitizedName = preg_replace('/[^\w\.-]/', '_', $siswa->nama); 
                    $destFileName = "Rapor_{$type}_{$sanitizedName}.docx";
                    $destPath = $publicDir . '/' . $destFileName;
                    
                    // Gunakan file_get_contents/file_put_contents yang lebih handal untuk menyalin
                    $fileContent = file_get_contents($sourcePath);
                    if ($fileContent !== false && file_put_contents($destPath, $fileContent) !== false) {
                        $files[] = [
                            'path' => $destPath,
                            'name' => $destFileName
                        ];
                        
                        // Simpan history generate
                        \App\Models\ReportGeneration::create([
                            'siswa_id' => $siswa->id,
                            'kelas_id' => $kelas->id,
                            'report_template_id' => $template->id,
                            'generated_file' => $result['path'],
                            'type' => $type,
                            'tahun_ajaran' => $tahunAjaran?->tahun_ajaran ?: $template->tahun_ajaran,
                            'semester' => $currentSemester, // Use current semester
                            'tahun_ajaran_id' => $tahunAjaranId,
                            'generated_at' => now(),
                            'generated_by' => $guru->id
                        ]);
                        
                        // Tracking siswa berhasil
                        $successSiswa[] = [
                            'id' => $siswa->id,
                            'name' => $siswa->nama,
                            'filename' => $destFileName
                        ];
                    } else {
                        throw new \Exception("Gagal menyalin file rapor");
                    }
                    
                } catch (\Exception $e) {
                    // Log error
                    \Log::error("Error generating report for siswa {$siswa->id} ({$siswa->nama}): " . $e->getMessage());
                    
                    // Tracking siswa gagal
                    $errorSiswa[] = [
                        'id' => $siswa->id,
                        'name' => $siswa->nama,
                        'error' => $e->getMessage()
                    ];
                }
            }
            
            // Cek jika tidak ada rapor berhasil
            if (empty($files)) {
                throw new \Exception('Tidak ada rapor yang dapat digenerate. ' . implode("\n", array_column($errorSiswa, 'error')));
            }
            
            // Buat file summary
            $summaryContent = "# Laporan Generate Batch Rapor\n\n";
            $summaryContent .= "Tanggal: " . date('Y-m-d H:i:s') . "\n";
            $summaryContent .= "Kelas: {$kelas->nama_kelas}\n";
            $summaryContent .= "Tipe Rapor: $type\n";
            $summaryContent .= "Tahun Ajaran: " . ($template->tahunAjaran ? $template->tahunAjaran->tahun_ajaran : $template->tahun_ajaran) . "\n";
            $summaryContent .= "Semester: {$currentSemester}\n\n";
            
            $summaryContent .= "## Ringkasan\n";
            $summaryContent .= "Total Siswa: " . count($siswaIds) . "\n";
            $summaryContent .= "Berhasil: " . count($successSiswa) . "\n";
            $summaryContent .= "Gagal: " . count($errorSiswa) . "\n\n";
            
            if (!empty($successSiswa)) {
                $summaryContent .= "## Siswa Berhasil\n";
                foreach ($successSiswa as $index => $siswa) {
                    $summaryContent .= ($index + 1) . ". {$siswa['name']} - {$siswa['filename']}\n";
                }
                $summaryContent .= "\n";
            }
            
            if (!empty($errorSiswa)) {
                $summaryContent .= "## Siswa Gagal\n";
                foreach ($errorSiswa as $index => $siswa) {
                    $summaryContent .= ($index + 1) . ". {$siswa['name']} - {$siswa['error']}\n";
                }
            }
            
            // Tulis file summary
            $summaryPath = $publicDir . "/RINGKASAN_RAPOR.md";
            file_put_contents($summaryPath, $summaryContent);
            
            // Buat ZIP file
            $zip = new \ZipArchive();
            if ($zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
                throw new \Exception("Gagal membuat file ZIP");
            }
            
            // Tambahkan file summary
            $zip->addFile($summaryPath, "RINGKASAN_RAPOR.md");
            
            // Tambahkan semua file rapor
            foreach ($files as $file) {
                if (file_exists($file['path'])) {
                    $zip->addFile($file['path'], $file['name']);
                } else {
                    \Log::warning("File not found: {$file['path']}");
                }
            }
            
            // Tutup ZIP
            if (!$zip->close()) {
                throw new \Exception("Gagal menutup file ZIP");
            }
            
            // Buat notifikasi sukses
            $notification = new \App\Models\Notification();
            $notification->title = "Batch Rapor {$type} Kelas {$kelas->nama_kelas} Siap Diunduh";
            $notification->content = "Generate batch rapor {$type} untuk kelas {$kelas->nama_kelas} telah selesai. " . 
                                "Berhasil: " . count($successSiswa) . " siswa, " . 
                                "Gagal: " . count($errorSiswa) . " siswa. " .
                                "Silahkan unduh file ZIP dari link yang disediakan.";
            $notification->target = 'specific';
            $notification->specific_users = [$guru->id];
            $notification->save();
            
            // Return signed URL download agar file batch tidak bisa diakses publik langsung
            $downloadUrl = $this->createSecureBatchDownloadUrl($webPath, $zipName);
            
            return ReportPerformanceTracker::measureSegment('response', fn () => response()->json([
                'success' => true,
                'message' => 'Batch rapor berhasil digenerate',
                'download_url' => $downloadUrl,
                'stats' => [
                    'total' => count($siswaIds),
                    'success' => count($successSiswa),
                    'error' => count($errorSiswa)
                ]
            ]));
            
        } catch (\Exception $e) {
            if ($e instanceof HttpExceptionInterface) {
                throw $e;
            }

            \Log::error("Batch generate report error: " . $e->getMessage(), [
                'trace' => $e->getTraceAsString()
            ]);
            
            // Buat notifikasi error
            if (isset($guru) && $guru) {
                $notification = new \App\Models\Notification();
                $notification->title = "Gagal Generate Batch Rapor {$type}";
                $notification->content = "Terjadi kesalahan saat membuat batch rapor {$type}: " . $e->getMessage() . 
                                    ". Silahkan coba lagi atau hubungi admin jika masalah berlanjut.";
                $notification->target = 'specific';
                $notification->specific_users = [$guru->id];
                $notification->save();
            }
            
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
                'error_detail' => env('APP_DEBUG') ? [
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                    'trace' => array_slice($e->getTrace(), 0, 3)
                ] : null
            ], 500);
        } finally {
            ReportPerformanceTracker::finishIfEnabled($performance);
        }
    }

    protected function getTemplateForSiswa(Siswa $siswa, $type, $tahunAjaranId = null)
    {
        $tahunAjaranId = $tahunAjaranId ?: session('tahun_ajaran_id');
        $kelas = $tahunAjaranId ? $this->resolveRaporClass($siswa, (int) $tahunAjaranId) : null;
        $kelasId = $kelas?->id ?: ($tahunAjaranId ? null : $siswa->kelas_id);
        
        \Log::info('Looking for template', [
            'siswa_id' => $siswa->id,
            'siswa_kelas_id' => $siswa->kelas_id,
            'report_kelas_id' => $kelasId,
            'type' => $type, // UTS atau UAS
            'tahun_ajaran_id' => $tahunAjaranId
        ]);

        $template = $this->findActiveReportTemplateForContext($type, $kelasId, $tahunAjaranId ? (int) $tahunAjaranId : null);

        if ($template) {
            \Log::info('Found template for report context', [
                'template_id' => $template->id,
                'template_type' => $template->type,
                'kelas_id' => $kelasId,
                'tahun_ajaran_id' => $tahunAjaranId,
            ]);

            return $template;
        }

        \Log::warning('No template found for', [
            'type' => $type,
            'kelas_id' => $kelasId,
            'tahun_ajaran_id' => $tahunAjaranId,
            'semester' => $tahunAjaranId ? $this->getRaporSemester((int) $tahunAjaranId) : null,
        ]);
        
        return null;
    }

    public function downloadSecureFile(Request $request)
    {
        abort_unless($request->hasValidSignature(), 403);

        $currentGuruId = auth()->guard('guru')->id();
        abort_unless(
            $currentGuruId && (string) $request->query('user') === (string) $currentGuruId,
            403
        );

        $relativePath = $this->normalizeProtectedPath((string) $request->query('path'));
        abort_unless($this->isAllowedProtectedStoragePath($relativePath), 404);
        abort_unless(Storage::disk('public')->exists($relativePath), 404);

        $filePath = Storage::disk('public')->path($relativePath);
        $filename = basename((string) $request->query('filename', basename($relativePath)));
        $disposition = $request->query('disposition') === 'inline' ? 'inline' : 'attachment';

        if ($disposition === 'inline') {
            return response()->file($filePath, [
                'Content-Disposition' => sprintf('inline; filename="%s"', $filename),
            ]);
        }

        return response()->download($filePath, $filename);
    }

    public function downloadSecureBatchDownload(Request $request)
    {
        abort_unless($request->hasValidSignature(), 403);

        $currentGuruId = auth()->guard('guru')->id();
        abort_unless(
            $currentGuruId && (string) $request->query('user') === (string) $currentGuruId,
            403
        );

        $relativePath = $this->normalizeProtectedPath((string) $request->query('path'));
        abort_unless(Str::startsWith($relativePath, 'downloads/rapor_batch_'), 404);

        $filePath = public_path($relativePath);
        abort_unless(file_exists($filePath), 404);

        $filename = basename((string) $request->query('filename', basename($relativePath)));

        return response()->download($filePath, $filename);
    }

    protected function createSecureRaporFileUrl(
        string $relativePath,
        string $filename,
        string $disposition = 'attachment',
        int $expiresInMinutes = 30
    ): string {
        return URL::temporarySignedRoute(
            'wali_kelas.rapor.secure-file',
            now()->addMinutes($expiresInMinutes),
            [
                'path' => $this->normalizeProtectedPath($relativePath),
                'filename' => $filename,
                'disposition' => $disposition,
                'user' => auth()->guard('guru')->id(),
            ]
        );
    }

    protected function createSecureBatchDownloadUrl(
        string $relativePath,
        string $filename,
        int $expiresInMinutes = 30
    ): string {
        return URL::temporarySignedRoute(
            'wali_kelas.rapor.secure-batch-download',
            now()->addMinutes($expiresInMinutes),
            [
                'path' => $this->normalizeProtectedPath($relativePath),
                'filename' => $filename,
                'user' => auth()->guard('guru')->id(),
            ]
        );
    }

    protected function normalizeProtectedPath(string $path): string
    {
        return ltrim(str_replace('\\', '/', $path), '/');
    }

    protected function isAllowedProtectedStoragePath(string $relativePath): bool
    {
        $allowedPrefixes = [
            'generated/',
            'pdf_reports/',
            'pdf_previews/',
            'templates/',
        ];

        foreach ($allowedPrefixes as $prefix) {
            if (Str::startsWith($relativePath, $prefix)) {
                return true;
            }
        }

        return false;
    }

    public function destroy(ReportTemplate $template)
    {
        try {
            DB::beginTransaction();

            // Hapus file
            if (Storage::disk('public')->exists($template->path)) {
                Storage::disk('public')->delete($template->path);
            }

            // Hapus record
            $template->delete();

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Template berhasil dihapus. Jika tidak ada template ' . $template->type . ' aktif lain yang sesuai, rapor ' . $template->type . ' belum dapat dibuat.'
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Gagal menghapus template: ' . $e->getMessage()
            ], 500);
        }
    }

    protected function validateTemplate($filePath, $type = 'UTS')
    {
        try {
            $phpWord = new \PhpOffice\PhpWord\TemplateProcessor($filePath);
            $existingVariables = $phpWord->getVariables();
            
            // Get all valid placeholders from database
            $validPlaceholders = ReportPlaceholder::pluck('placeholder_key')->toArray();
            
            // Add dynamic placeholder patterns for regex validation
            $dynamicPatterns = [
                'nama_matapelajaran\d+',
                'nilai_matapelajaran\d+',
                'catatan_matapelajaran\d+',
                'nama_mulok\d+',
                'nilai_mulok\d+',
                'catatan_mulok\d+',
                'ekskul\d+_nama',
                'ekskul\d+_keterangan'
            ];
            
            // Placeholder yang harus ada untuk UTS
            $utsRequiredPlaceholders = [
                'nama_siswa', 
                'nisn', 
                'nis', 
                'kelas',
                'tahun_ajaran',
                'sakit'
            ];
            
            // Placeholder yang harus ada untuk UAS
            $uasRequiredPlaceholders = [
                'nama_siswa', 
                'nisn', 
                'nis', 
                'kelas',
                'tahun_ajaran',
                'sakit',
                'nama_sekolah',
                'alamat_sekolah'
                // Tambahkan placeholder lain yang harus ada di UAS
            ];
            
            // Pilih daftar placeholder yang diperlukan berdasarkan jenis template
            $requiredPlaceholders = ($type === 'UTS') ? $utsRequiredPlaceholders : $uasRequiredPlaceholders;
            
            // Tambahkan validator untuk memastikan template tidak memiliki placeholder dari jenis lain
            // Misalnya, placeholder UAS tidak boleh ada di template UTS
            $uasSpecificPlaceholders = ['tempat_lahir', 'jenis_kelamin', 'agama', 'alamat_wali'];
            if ($type === 'UTS' && count(array_intersect($existingVariables, $uasSpecificPlaceholders)) > 0) {
                return [
                    'is_valid' => false,
                    'message' => 'Template UTS tidak boleh mengandung placeholder UAS'
                ];
            }
            
            // Check if required placeholders exist
            $missingPlaceholders = [];
            foreach ($requiredPlaceholders as $required) {
                if (!in_array($required, $existingVariables)) {
                    $missingPlaceholders[] = $required;
                }
            }
            
            // Check if each template variable is valid
            $invalidPlaceholders = [];
            foreach ($existingVariables as $var) {
                $isValid = in_array($var, $validPlaceholders);
                
                if (!$isValid) {
                    // Check if it matches a dynamic pattern
                    $isDynamic = false;
                    foreach ($dynamicPatterns as $pattern) {
                        if (preg_match('/^' . $pattern . '$/', $var)) {
                            $isDynamic = true;
                            break;
                        }
                    }
                    
                    if (!$isDynamic) {
                        $invalidPlaceholders[] = $var;
                    }
                }
            }
            
            return [
                'is_valid' => empty($missingPlaceholders) && empty($invalidPlaceholders),
                'missing_placeholders' => $missingPlaceholders,
                'invalid_placeholders' => $invalidPlaceholders,
            ];
        } catch (\Exception $e) {
            \Log::error('Template validation error:', [
                'error' => $e->getMessage(),
                'file_path' => $filePath
            ]);
            
            return [
                'is_valid' => false,
                'error' => $e->getMessage()
            ];
        }
    }
    /**
     * Create a basic sample template if none exists
     *
     * @param string $outputPath
     * @param string $type
     * @return void
     */
    protected function createSampleTemplate($outputPath, $type = 'UTS')
    {
        try {
            // Create a new Word document
            $phpWord = new \PhpOffice\PhpWord\PhpWord();
            
            // Add a section
            $section = $phpWord->addSection();
            
            // Add header with school name
            $header = $section->addHeader();
            $header->addText('TEMPLATE RAPOR ' . $type, ['bold' => true, 'size' => 16], ['alignment' => 'center']);
            
            // Add student information
            $section->addText('IDENTITAS SISWA:', ['bold' => true, 'size' => 14]);
            $section->addText('Nama: ${nama_siswa}', ['size' => 12]);
            $section->addText('NISN: ${nisn}', ['size' => 12]);
            $section->addText('NIS: ${nis}', ['size' => 12]);
            $section->addText('Kelas: ${kelas}', ['size' => 12]);
            $section->addText('Tahun Ajaran: ${tahun_ajaran}', ['size' => 12]);
            
            $section->addTextBreak(1);
            
            // Add subject information
            $section->addText('NILAI MATA PELAJARAN:', ['bold' => true, 'size' => 14]);
            
            // Create table for subjects
            $table = $section->addTable();
            
            // Add header row
            $table->addRow();
            $table->addCell(2000)->addText('Mata Pelajaran', ['bold' => true]);
            $table->addCell(1000)->addText('Nilai', ['bold' => true]);
            $table->addCell(5000)->addText('Capaian Kompetensi', ['bold' => true]);
            
            // PAI
            $table->addRow();
            $table->addCell(2000)->addText('Pendidikan Agama Islam');
            $table->addCell(1000)->addText('${nilai_pai}');
            $table->addCell(5000)->addText('${capaian_pai}');
            
            // Matematika
            $table->addRow();
            $table->addCell(2000)->addText('Matematika');
            $table->addCell(1000)->addText('${nilai_matematika}');
            $table->addCell(5000)->addText('${capaian_matematika}');
            
            // Bahasa Indonesia
            $table->addRow();
            $table->addCell(2000)->addText('Bahasa Indonesia');
            $table->addCell(1000)->addText('${nilai_bahasa_indonesia}');
            $table->addCell(5000)->addText('${capaian_bahasa_indonesia}');
            
            $section->addTextBreak(1);
            
            // Add attendance information
            $section->addText('KEHADIRAN:', ['bold' => true, 'size' => 14]);
            $section->addText('Sakit: ${sakit} hari', ['size' => 12]);
            $section->addText('Izin: ${izin} hari', ['size' => 12]);
            $section->addText('Tanpa Keterangan: ${tanpa_keterangan} hari', ['size' => 12]);
            
            // Save to file
            $objWriter = \PhpOffice\PhpWord\IOFactory::createWriter($phpWord, 'Word2007');
            $objWriter->save($outputPath);
            
            return true;
        } catch (\Exception $e) {
            \Log::error('Error creating sample template:', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return false;
        }
    }
   
    /**
     * Get required placeholders based on template type
     * 
     * @param string $type
     * @return array
     */
    protected function getRequiredPlaceholders($type = 'UTS')
    {
        // Basic required placeholders for all template types
        $required = [
            'nama_siswa',
            'nisn',
            'nis',
            'kelas',
            'tahun_ajaran',
            'nilai_matematika',
            'sakit'
        ];
        
        // Add UTS specific placeholders
        if ($type === 'UTS') {
            // Currently no additional UTS-specific required placeholders
        }
        
        // Add UAS specific placeholders 
        if ($type === 'UAS') {
            // Additional required placeholders for UAS
            // $required[] = 'additional_uas_placeholder';
        }
        
        return $required;
    }

    protected function fillTemplateSampleData($template)
    {
        $sampleData = [
            // Data Siswa
            'nama_siswa' => 'Muhammad Azzam',
            'nisn' => '0123456789',
            'nis' => '210001',
            'kelas' => '6A',
            'tahun_ajaran' => '2024/2025',
            
            // === MATA PELAJARAN ===
            // PAI
            'nilai_pai' => '90',
            'capaian_pai' => 'Sangat baik dalam memahami dan menerapkan nilai-nilai agama Islam dalam kehidupan sehari-hari',
            
            // PPKN
            'nilai_ppkn' => '88',
            'capaian_ppkn' => 'Menunjukkan pemahaman yang baik tentang nilai-nilai Pancasila dan kewarganegaraan',
            
            // Bahasa Indonesia
            'nilai_bahasa_indonesia' => '85',
            'capaian_bahasa_indonesia' => 'Mampu berkomunikasi dengan baik secara lisan dan tulisan dalam Bahasa Indonesia',
            
            // Matematika
            'nilai_matematika' => '92',
            'capaian_matematika' => 'Sangat baik dalam pemecahan masalah matematika dan penerapan konsep perhitungan',
            
            // PJOK
            'nilai_pjok' => '87',
            'capaian_pjok' => 'Aktif dalam kegiatan olahraga dan menunjukkan sportivitas yang baik',
            
            // Seni Musik
            'nilai_seni_musik' => '88',
            
            // Bahasa Inggris
            'nilai_bahasa_inggris' => '86',
            'capaian_bahasa_inggris' => 'Baik dalam memahami dan menggunakan Bahasa Inggris dasar',
            
            
            // === MUATAN LOKAL ===
            'nama_mulok1' => 'Tahfidz',
            'nama_mulok2' => 'Bahasa Arab',
            'nama_mulok3' => 'BTQ',
            'nama_mulok4' => 'Komputer', 
            'nama_mulok5' => 'Conversation',

            // Tahfidz
            'nilai_mulok1' => '89',
            'capaian_mulok1' => 'Hafalan sangat baik dan tajwid yang tepat',
            
            // Bahasa Arab
            'nilai_mulok2' => '85',
            'capaian_mulok2' => 'Mampu memahami kosakata dasar dan percakapan sederhana',
            
            // BTQ
            'nilai_mulok3' => '88',
            'capaian_mulok3' => 'Bacaan Al-Quran lancar dan sesuai tajwid',
            
            // Komputer
            'nilai_mulok4' => '90',
            'capaian_mulok4' => 'Sangat baik dalam mengoperasikan komputer dan aplikasi dasar',
            
            // Conversation
            'nilai_mulok5' => '87',
            'capaian_mulok5' => 'Aktif dalam percakapan Bahasa Inggris sederhana',
            
            // === EKSTRAKURIKULER ===
            'ekskul1_nama' => 'Pramuka',
            'ekskul1_keterangan' => 'Sangat aktif dan menunjukkan jiwa kepemimpinan',
            
            'ekskul2_nama' => 'Tahfidz',
            'ekskul2_keterangan' => 'Berhasil menghafal juz 30 dengan baik',
            
            'ekskul3_nama' => 'Futsal',
            'ekskul3_keterangan' => 'Menunjukkan kerja sama tim yang baik',
            
            'ekskul4_nama' => 'English Club',
            'ekskul4_keterangan' => 'Aktif dalam kegiatan percakapan Bahasa Inggris',
            
            'ekskul5_nama' => 'Seni Kaligrafi',
            'ekskul5_keterangan' => 'Mampu membuat kaligrafi dengan indah',
            
            'ekskul6_nama' => 'Robotika',
            'ekskul6_keterangan' => 'Menunjukkan minat dan kreativitas dalam pemrograman dasar',
            
            // === KEHADIRAN ===
            'sakit' => '2',
            'izin' => '1',
            'tanpa_keterangan' => '0',
            
            // === LAINNYA ===
            'catatan_guru' => 'Siswa menunjukkan perkembangan yang sangat baik dalam akademik maupun perilaku. Perlu ditingkatkan lagi dalam kegiatan diskusi kelompok.',
            'nomor_telepon' => '(021) 7123456'
        ];
    
        // Get available variables in template
        $variables = $template->getVariables();
        
        // Log untuk debugging
        \Log::info('Variables in template:', [
            'found_variables' => $variables
        ]);
    
        // Only set values for variables that exist in template
        foreach ($sampleData as $key => $value) {
            if (in_array($key, $variables)) {
                $template->setValue($key, $value);
            }
        }
        
        // Log placeholders yang tidak ada di sample data
        $missingPlaceholders = array_diff($variables, array_keys($sampleData));
        if (!empty($missingPlaceholders)) {
            \Log::warning('Placeholders without sample data:', [
                'missing' => $missingPlaceholders
            ]);
        }
    }

    public function placeholderGuide()
    {
        try {
            // Get placeholders grouped by category
            $placeholders = ReportPlaceholder::get()
                ->groupBy('category')
                ->map(function($group) {
                    return $group->map(function($item) {
                        return [
                            'key' => $item->placeholder_key,
                            'description' => $item->description,
                            'is_required' => $item->is_required
                        ];
                    });
                });
    
            // Log for debugging
            \Log::info('Placeholders data:', ['data' => $placeholders]);
    
            // Return view with collection
            return view('admin.report.placeholder_guide', [
                'placeholders' => $placeholders
            ]);
        } catch (\Exception $e) {
            \Log::error('Error in placeholderGuide:', ['error' => $e->getMessage()]);
            return view('admin.report.placeholder_guide', [
                'placeholders' => collect([])
            ])->with('error', 'Terjadi kesalahan saat memuat panduan placeholder');
        }
    }
}

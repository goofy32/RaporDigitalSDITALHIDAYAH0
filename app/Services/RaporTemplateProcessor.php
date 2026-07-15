<?php

namespace App\Services;

use App\Models\ReportTemplate;
use App\Models\Siswa;
use App\Models\ReportPlaceholder;
use App\Models\ProfilSekolah;
use App\Models\Kelas;
use App\Models\Guru;
use App\Models\TahunAjaran;
use PhpOffice\PhpWord\TemplateProcessor;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use App\Exceptions\RaporException;
use Exception;
use App\Helpers\FileNameHelper;
use App\Services\SiswaKelasSemesterResolver;

class RaporTemplateProcessor 
{
    // Constants for error types
    const ERROR_TEMPLATE_MISSING = 1001;
    const ERROR_TEMPLATE_INVALID = 1002;
    const ERROR_DATA_INCOMPLETE = 1003;
    const ERROR_PLACEHOLDER_MISSING = 1004;
    const ERROR_FILE_PROCESSING = 1005;

    protected $processor;
    protected $template;
    protected $siswa;
    protected $type;
    protected $placeholders;
    protected $schoolProfile;
    protected $tahunAjaranId; // Tambahkan property untuk menyimpan tahun ajaran ID
    protected ?Kelas $reportKelas = null;
    protected ?TahunAjaran $reportTahunAjaran = null;
    protected ?string $fotoSiswaReplacementPath = null;
    private const FOTO_SISWA_WIDTH = '3cm';
    private const FOTO_SISWA_HEIGHT = '4cm';

    public function __construct(ReportTemplate $template, Siswa $siswa, $type = 'UTS', $tahunAjaranId = null)
    {
        $this->template = $template;
        $this->siswa = $siswa;
        $this->type = $type;
        $this->schoolProfile = ProfilSekolah::first();
        // Ambil tahun ajaran dari parameter, session, atau dari kelas siswa
        $this->tahunAjaranId = $tahunAjaranId ?: session('tahun_ajaran_id') ?: ($siswa->kelas->tahun_ajaran_id ?? null);
        $this->reportTahunAjaran = $this->tahunAjaranId ? TahunAjaran::find($this->tahunAjaranId) : null;
        $this->reportKelas = $this->resolveReportClass();

        if ($this->reportKelas) {
            $this->reportKelas->loadMissing('tahunAjaran');
            $this->siswa->setRelation('kelas', $this->reportKelas);
        }
        
        $this->logReportProcessing('RaporTemplateProcessor initialized', [
            'siswa_id' => $siswa->id, 
            'kelas_id' => $this->reportKelas->id ?? $siswa->kelas_id ?? null,
            'template_id' => $template->id,
            'type' => $type,
            'tahun_ajaran_id' => $this->tahunAjaranId
        ]);

        // Validasi template path tidak kosong
        if (empty($template->path)) {
            throw new RaporException(
                "Path template kosong. Hubungi admin untuk upload template baru.",
                'template_missing',
                self::ERROR_TEMPLATE_MISSING
            );
        }
    
        $this->logReportProcessing('Template info resolved', [
            'template_id' => $template->id,
            'is_active' => $template->is_active,
            'tahun_ajaran_id' => $this->tahunAjaranId,
        ]);
    
        // Pastikan path template adalah file yang valid
        $templatePath = storage_path('app/public/' . $template->path);
        
        $this->logReportProcessing('Template path checked', [
            'exists' => file_exists($templatePath),
            'is_file' => is_file($templatePath)
        ]);
    
        if (!file_exists($templatePath)) {
            throw new RaporException(
                "Template file tidak ditemukan: {$templatePath}. Hubungi admin untuk upload template baru.",
                'template_missing',
                self::ERROR_TEMPLATE_MISSING
            );
        }
    
        if (!is_file($templatePath)) {
            throw new RaporException(
                "Path bukan merupakan file yang valid: {$templatePath}. Hubungi admin untuk upload template baru.",
                'template_invalid',
                self::ERROR_TEMPLATE_INVALID
            );
        }
    
        ReportPerformanceTracker::measureSegment('template_open', function () use ($templatePath) {
            try {
                $this->processor = new TemplateProcessor($templatePath);
            } catch (\Exception $e) {
                throw new RaporException(
                    "Gagal memproses template: " . $e->getMessage() . ". Hubungi admin untuk perbaiki template.",
                    'template_invalid',
                    self::ERROR_TEMPLATE_INVALID
                );
            }

            $this->placeholders = ReportPlaceholder::all()->groupBy('category');
        });
    }

    private function logReportProcessing(string $message, array $context = []): void
    {
        if (! config('logging.diagnostics.log_report_processing')) {
            return;
        }

        Log::debug($message, $context);
    }

    protected function resolveReportClass(): ?Kelas
    {
        if (! $this->tahunAjaranId || ! $this->reportTahunAjaran) {
            return $this->siswa->kelas;
        }

        try {
            return app(SiswaKelasSemesterResolver::class)
                ->resolveClass($this->siswa, (int) $this->tahunAjaranId, (int) $this->reportTahunAjaran->semester, true);
        } catch (\RuntimeException $exception) {
            Log::warning('Unable to resolve template report class context', [
                'siswa_id' => $this->siswa->id,
                'tahun_ajaran_id' => $this->tahunAjaranId,
                'semester' => $this->reportTahunAjaran->semester,
                'error' => $exception->getMessage(),
            ]);

            return null;
        }
    }

    protected function getTemplateForSiswa(Siswa $siswa, $type, $tahunAjaranId = null)
    {
        $tahunAjaranId = $tahunAjaranId ?: session('tahun_ajaran_id');
        $kelasId = $this->reportKelas?->id ?: $siswa->kelas_id;
        
        // First look for class-specific template
        $template = ReportTemplate::where('type', $type)
            ->where('kelas_id', $kelasId)
            ->where('is_active', true)
            ->when($tahunAjaranId, function($query) use ($tahunAjaranId) {
                return $query->where('tahun_ajaran_id', $tahunAjaranId);
            })
            ->first();
        
        // If not found, look for global template
        if (!$template) {
            $template = ReportTemplate::where('type', $type)
                ->whereNull('kelas_id')
                ->where('is_active', true)
                ->when($tahunAjaranId, function($query) use ($tahunAjaranId) {
                    return $query->where('tahun_ajaran_id', $tahunAjaranId);
                })
                ->first();
        }
        
        $this->logReportProcessing('Template selection checked', [
            'siswa_id' => $siswa->id,
            'kelas_id' => $kelasId,
            'type' => $type,
            'tahun_ajaran_id' => $tahunAjaranId,
            'template_found' => $template ? 'Yes' : 'No',
            'template_id' => $template ? $template->id : null
        ]);
        
        return $template;
    }
    
    
    protected function getSemesterForType($type, $tahunAjaranId)
    {
        // Get the tahun ajaran to determine the actual semester
        $tahunAjaran = \App\Models\TahunAjaran::find($tahunAjaranId);
        
        if ($tahunAjaran) {
            // Instead of hardcoding UTS=1 and UAS=2, use the actual semester from tahun ajaran
            return $tahunAjaran->semester;
        }
        
        // Fallback to the old logic if tahun ajaran not found
        return $type === 'UTS' ? 1 : 2;
    }
    
    protected function debugCatatanData($tahunAjaranId, $semester, $catatanType)
    {
        $this->logReportProcessing('Debug catatan data requested', [
            'siswa_id' => $this->siswa->id,
            'tahun_ajaran_id' => $tahunAjaranId,
            'semester' => $semester,
            'catatan_type' => $catatanType
        ]);

        // Cek semua catatan mata pelajaran untuk siswa ini
        $allCatatan = \App\Models\CatatanMataPelajaran::where('siswa_id', $this->siswa->id)
            ->where('tahun_ajaran_id', $tahunAjaranId)
            ->where('semester', $semester)
            ->where('type', $catatanType)
            ->with('mataPelajaran')
            ->get();

        $this->logReportProcessing('Catatan mata pelajaran loaded', [
            'count' => $allCatatan->count(),
            'mata_pelajaran_ids' => $allCatatan->pluck('mata_pelajaran_id')->filter()->values()->all(),
        ]);

        // Cek juga semua mata pelajaran untuk siswa ini
        $allMapel = $this->siswa->nilais()
            ->with(['mataPelajaran'])
            ->whereHas('mataPelajaran', function($q) use ($semester) {
                $q->where('semester', $semester);
            })
            ->where('tahun_ajaran_id', $tahunAjaranId)
            ->where('is_submitted', true)
            ->get()
            ->groupBy('mata_pelajaran_id');

        $this->logReportProcessing('Mata pelajaran dengan nilai loaded', [
            'count' => $allMapel->count(),
            'mapel_ids' => $allMapel->keys()->toArray(),
        ]);

        return $allCatatan;
    }

    /**
     * Mengumpulkan semua data yang diperlukan untuk template rapor
     * Updated untuk sistem capaian kompetensi baru
     * 
     * @return array
     */
    protected function collectAllData()
    {
        $semester = $this->getReportDataSemester();
        $tahunAjaranId = $this->tahunAjaranId;
        $kelas = $this->reportKelas ?: $this->siswa->kelas;
        $tahunAjaran = $this->reportTahunAjaran ?: $kelas?->tahunAjaran;
        
        // Data Siswa
        $data = [
            'nama_siswa' => $this->siswa->nama,
            'nisn' => $this->siswa->nisn ?: '-',
            'nis' => $this->siswa->nis ?: '-',
            'kelas' => $kelas ? $kelas->nomor_kelas . ' ' . $kelas->nama_kelas : '-',
            'tahun_ajaran' => $tahunAjaran ? $tahunAjaran->tahun_ajaran : ($this->schoolProfile->tahun_pelajaran ?? '-'),
            'tempat_lahir' => $this->siswa->tempat_lahir ?? '-',
            'jenis_kelamin' => $this->siswa->jenis_kelamin ?? '-',
            'agama' => $this->siswa->agama ?? '-',
            'alamat_siswa' => $this->siswa->alamat ?? '-',
            'nama_ayah' => $this->siswa->nama_ayah ?? '-',
            'nama_ibu' => $this->siswa->nama_ibu ?? '-',
            'pekerjaan_ayah' => $this->siswa->pekerjaan_ayah ?? '-',
            'pekerjaan_ibu' => $this->siswa->pekerjaan_ibu ?? '-',
            'alamat_orangtua' => $this->siswa->alamat_orangtua ?? '-',
            'wali_siswa' => $this->siswa->wali_siswa ?? '-',
            'pekerjaan_wali' => $this->siswa->pekerjaan_wali ?? '-',
            'alamat_wali' => $this->siswa->alamat_wali ?? '-',
            'fase' => $kelas ? $this->determineFase($kelas->nomor_kelas) : '-',
            'semester' => (int) $semester === 1 ? 'Ganjil' : 'Genap',
        ];

        $data['foto_siswa'] = $this->prepareFotoSiswa();
        $waliKelas = $kelas ? $kelas->getWaliKelas() : null;
        $data['ttd_wali_kelas'] = $this->prepareTtdWaliKelas($waliKelas);

        // ========== CATATAN SISWA (CATATAN GURU) ==========
        $catatanSiswa = \App\Models\CatatanSiswa::where('siswa_id', $this->siswa->id)
            ->where('tahun_ajaran_id', $tahunAjaranId)
            ->where('semester', $semester)
            ->where('type', 'umum') // Gunakan type umum untuk catatan guru
            ->first();
            
        $data['catatan_guru'] = $catatanSiswa ? $catatanSiswa->catatan : '-';

        // ========== AMBIL DATA KKM TERLEBIH DAHULU ==========
        $kkmData = $this->getKkmData($tahunAjaranId);

        // Data Nilai - Filter berdasarkan tahun ajaran yang dipilih
        $nilaiQuery = $this->siswa->nilais()
            ->with(['mataPelajaran'])
            ->whereHas('mataPelajaran', function($q) use ($semester) {
                $q->where('semester', $semester);
            })
            ->where('tahun_ajaran_id', $tahunAjaranId)
            ->where('is_submitted', true);
            
        $nilaiCollection = $nilaiQuery->get();
        
        // Grouping by mata pelajaran
        $nilai = $nilaiCollection->groupBy('mataPelajaran.nama_pelajaran');
        
        $this->logReportProcessing('Report score data loaded', [
            'siswa_id' => $this->siswa->id,
            'mapel_count' => $nilai->count(),
            'tahun_ajaran_id' => $tahunAjaranId,
            'kkm_count' => count($kkmData)
        ]);

        // Pisahkan mata pelajaran reguler dan muatan lokal
        $mapelReguler = $nilaiCollection->groupBy('mataPelajaran.nama_pelajaran')
            ->filter(function($value, $key) {
                return $value->first() && 
                    $value->first()->mataPelajaran && 
                    $value->first()->mataPelajaran->is_muatan_lokal == 0;
            });
            
        $mulok = $nilaiCollection->groupBy('mataPelajaran.nama_pelajaran')
            ->filter(function($value, $key) {
                return $value->first() && 
                    $value->first()->mataPelajaran && 
                    $value->first()->mataPelajaran->is_muatan_lokal == 1;
            });
            
        $this->logReportProcessing('Report subject groups prepared', [
            'reguler_count' => $mapelReguler->count(),
            'mulok_count' => $mulok->count(),
        ]);

        $allMapelIds = $nilaiCollection->pluck('mata_pelajaran_id')
            ->filter()
            ->unique()
            ->values()
            ->toArray();

        $preloadedCapaian = !empty($allMapelIds)
            ? \App\Http\Controllers\CapaianKompetensiController::preloadCapaianData(
                $this->siswa->id,
                $allMapelIds,
                $tahunAjaranId
            )
            : [];

        // Definisi mata pelajaran wajib dengan urutan tertentu dan sinonim yang lebih tepat
        $priorityMapel = [
            'pai' => ['Pendidikan Agama Islam', 'PAI', 'Agama Islam', 'Pendidikan Agama dan Budi Pekerti'],
            'ppkn' => ['PPKN', 'PKN', 'Pendidikan Pancasila', 'Pendidikan Kewarganegaraan', 'Pendidikan Pancasila dan Kewarganegaraan'],
            'bahasa_indonesia' => ['Bahasa Indonesia', 'B. Indonesia', 'BI'],
            'matematika' => ['Matematika', 'MTK', 'Math'],
            'pjok' => ['PJOK', 'Pendidikan Jasmani', 'Olahraga', 'Pendidikan Jasmani Olahraga dan Kesehatan'],
            'seni_musik' => ['Seni Musik', 'Musik', 'Kesenian', 'Seni', 'Seni Budaya', 'SBK'],
            'bahasa_inggris' => ['Bahasa Inggris', 'B. Inggris', 'English'],
            'ips' => ['IPS', 'Ilmu Pengetahuan Sosial', 'Ilmu Sosial']
        ];

        // Urutan standar mata pelajaran
        $mapelOrder = ['pai', 'ppkn', 'bahasa_indonesia', 'matematika', 'ips', 'seni_musik', 'pjok', 'bahasa_inggris'];

        // Identifikasi semua mata pelajaran terlebih dahulu
        $mapelIdentified = [];
        $processedMapelNames = []; // Track nama mata pelajaran yang sudah diproses

        foreach ($mapelReguler as $mapelName => $nilaiMapel) {
            $matchedKey = $this->findMatchingMapel($mapelName, $priorityMapel);
            
            if ($matchedKey) {
                // Mencegah duplikasi: jangan tambahkan mata pelajaran dengan key yang sama
                if (!isset($mapelIdentified[$matchedKey])) {
                    $mapelIdentified[$matchedKey] = [
                        'name' => $mapelName,
                        'nilai' => $nilaiMapel,
                        'mata_pelajaran_id' => $nilaiMapel->first()->mata_pelajaran_id
                    ];
                    $processedMapelNames[] = $mapelName;
                    
                    $this->logReportProcessing("Mata pelajaran diidentifikasi", [
                        'nama' => $mapelName,
                        'key' => $matchedKey,
                        'mata_pelajaran_id' => $nilaiMapel->first()->mata_pelajaran_id
                    ]);
                } else {
                    Log::warning("Duplikasi mata pelajaran terdeteksi", [
                        'existing' => $mapelIdentified[$matchedKey]['name'],
                        'duplicate' => $mapelName,
                        'key' => $matchedKey
                    ]);
                }
            }
        }

        // INISIALISASI VARIABEL YANG DIPERLUKAN
        $dynamicPlaceholders = [];
        $mapelCount = 1;
        $processedKeys = []; // Track key yang sudah diproses
        
        // Proses mata pelajaran berdasarkan urutan prioritas
        foreach ($mapelOrder as $key) {
            if (isset($mapelIdentified[$key])) {
                $mapelInfo = $mapelIdentified[$key];
                $mapelName = $mapelInfo['name'];
                $nilaiMapel = $mapelInfo['nilai'];
                $mataPelajaranId = $mapelInfo['mata_pelajaran_id'];
                
                if (in_array($key, $processedKeys)) {
                    Log::warning("Mata pelajaran dengan key '$key' sudah diproses. Mengabaikan untuk mencegah duplikasi.", [
                        'mapel_name' => $mapelName
                    ]);
                    continue;
                }
                
                // Tandai key ini sudah diproses
                $processedKeys[] = $key;
                
                // Cari nilai akhir rapor yang sesuai dengan tahun ajaran
                $nilaiAkhir = $nilaiMapel
                    ->when($tahunAjaranId, function($collection) use ($tahunAjaranId) {
                        return $collection->where('tahun_ajaran_id', $tahunAjaranId);
                    })
                    ->where('nilai_akhir_rapor', '!=', null)
                    ->first();
                
                if ($nilaiAkhir) {
                    $nilaiValue = $nilaiAkhir->nilai_akhir_rapor;
                    $data["nilai_$key"] = number_format($nilaiValue, 1);

                    $capaianData = $preloadedCapaian[$mataPelajaranId]
                        ?? ['tertinggi' => '-', 'terendah' => '-'];
                    $data["capaian_tertinggi_$key"] = $capaianData['tertinggi'];
                    $data["capaian_terendah_$key"] = $capaianData['terendah'];
                    $data["capaian_kompetensi_$key"] = '';
                        
                    // KKM MATA PELAJARAN
                    $data["kkm_$key"] = isset($kkmData[$mataPelajaranId]) ? $kkmData[$mataPelajaranId] : '70';
                        
                    // Tambahkan ke placeholder dinamis
                    $dynamicPlaceholders[$mapelCount] = [
                        'nama' => $mapelName,
                        'nilai' => $data["nilai_$key"],
                        'capaian_tertinggi' => $data["capaian_tertinggi_$key"],
                        'capaian_terendah' => $data["capaian_terendah_$key"],
                        'kkm' => $data["kkm_$key"],
                        'mata_pelajaran_id' => $mataPelajaranId
                    ];
                    
                    $mapelCount++;
                    
                    $this->logReportProcessing("Mata pelajaran $key diproses", [
                        'nama' => $mapelName,
                        'nilai' => $nilaiValue,
                        'kkm' => $data["kkm_$key"],
                        'capaian_tertinggi' => substr($data["capaian_tertinggi_$key"], 0, 100) . '...',
                        'capaian_terendah' => substr($data["capaian_terendah_$key"], 0, 100) . '...',
                        'placeholder_position' => $mapelCount - 1,
                        'tahun_ajaran_id' => $tahunAjaranId,
                        'mata_pelajaran_id' => $mataPelajaranId
                    ]);
                } else {
                    // Jika nilai akhir rapor belum tersimpan, gunakan placeholder kosong
                    // agar output rapor konsisten dengan source of truth di database.
                    $data["nilai_$key"] = '-';
                    $data["capaian_tertinggi_$key"] = '-';
                    $data["capaian_terendah_$key"] = '-';
                    $data["capaian_kompetensi_$key"] = '';
                    $data["kkm_$key"] = isset($kkmData[$mataPelajaranId]) ? $kkmData[$mataPelajaranId] : '70';

                    $dynamicPlaceholders[$mapelCount] = [
                        'nama' => $mapelName,
                        'nilai' => $data["nilai_$key"],
                        'capaian_tertinggi' => $data["capaian_tertinggi_$key"],
                        'capaian_terendah' => $data["capaian_terendah_$key"],
                        'kkm' => $data["kkm_$key"],
                        'mata_pelajaran_id' => $mataPelajaranId
                    ];

                    $mapelCount++;
                }
            } else {
                // Jika key tidak ada di data, set nilai default
                $data["nilai_$key"] = '-';
                $data["capaian_tertinggi_$key"] = '-';
                $data["capaian_terendah_$key"] = '-';
                $data["capaian_kompetensi_$key"] = '';
                $data["kkm_$key"] = '70'; // Default KKM
                
                $this->logReportProcessing("Mata pelajaran $key tidak ditemukan dalam data siswa");
            }
        }

        // Proses mata pelajaran reguler lainnya yang belum diidentifikasi
        foreach ($mapelReguler as $mapelName => $nilaiMapel) {
            if (!in_array($mapelName, $processedMapelNames) && $mapelCount <= 10) {
                $mataPelajaranId = $nilaiMapel->first()->mata_pelajaran_id;
                
                // Cari nilai akhir rapor dengan filter tahun ajaran
                $nilaiAkhir = $nilaiMapel
                    ->when($tahunAjaranId, function($collection) use ($tahunAjaranId) {
                        return $collection->where('tahun_ajaran_id', $tahunAjaranId);
                    })
                    ->where('nilai_akhir_rapor', '!=', null)
                    ->first();
                
                if ($nilaiAkhir) {
                    $nilaiValue = $nilaiAkhir->nilai_akhir_rapor;
                    $capaianData = $preloadedCapaian[$mataPelajaranId]
                        ?? ['tertinggi' => '-', 'terendah' => '-'];
                    
                    // Tambahkan ke placeholder dinamis
                    $dynamicPlaceholders[$mapelCount] = [
                        'nama' => $mapelName,
                        'nilai' => number_format($nilaiValue, 1),
                        'capaian_tertinggi' => $capaianData['tertinggi'],
                        'capaian_terendah' => $capaianData['terendah'],
                        'kkm' => isset($kkmData[$mataPelajaranId]) ? $kkmData[$mataPelajaranId] : '70',
                        'mata_pelajaran_id' => $mataPelajaranId
                    ];
                    
                    $processedMapelNames[] = $mapelName;
                    $mapelCount++;
                    
                    $this->logReportProcessing("Mata pelajaran lainnya diproses", [
                        'nama' => $mapelName,
                        'nilai' => $nilaiValue,
                        'capaian_tertinggi' => substr($dynamicPlaceholders[$mapelCount-1]['capaian_tertinggi'], 0, 100) . '...',
                        'capaian_terendah' => substr($dynamicPlaceholders[$mapelCount-1]['capaian_terendah'], 0, 100) . '...',
                        'kkm' => $dynamicPlaceholders[$mapelCount-1]['kkm'],
                        'placeholder_position' => $mapelCount - 1,
                        'tahun_ajaran_id' => $tahunAjaranId,
                        'mata_pelajaran_id' => $mataPelajaranId
                    ]);
                } else {
                    $dynamicPlaceholders[$mapelCount] = [
                        'nama' => $mapelName,
                        'nilai' => '-',
                        'capaian_tertinggi' => '-',
                        'capaian_terendah' => '-',
                        'kkm' => isset($kkmData[$mataPelajaranId]) ? $kkmData[$mataPelajaranId] : '70',
                        'mata_pelajaran_id' => $mataPelajaranId
                    ];

                    $processedMapelNames[] = $mapelName;
                    $mapelCount++;
                }
            }
        }

        // Isi placeholder dinamis di template
        for ($i = 1; $i <= 10; $i++) {
            if (isset($dynamicPlaceholders[$i])) {
                $data["nama_matapelajaran$i"] = $dynamicPlaceholders[$i]['nama'];
                $data["nilai_matapelajaran$i"] = $dynamicPlaceholders[$i]['nilai'];
                $data["capaian_tertinggi$i"] = $dynamicPlaceholders[$i]['capaian_tertinggi'] ?? '-';
                $data["capaian_terendah$i"] = $dynamicPlaceholders[$i]['capaian_terendah'] ?? '-';
                $data["capaian_kompetensi$i"] = '';
                $data["kkm_matapelajaran$i"] = $dynamicPlaceholders[$i]['kkm'];
            } else {
                $data["nama_matapelajaran$i"] = '-';
                $data["nilai_matapelajaran$i"] = '-';
                $data["capaian_tertinggi$i"] = '-';
                $data["capaian_terendah$i"] = '-';
                $data["capaian_kompetensi$i"] = '';
                $data["kkm_matapelajaran$i"] = '70';
            }
        }

        // Proses Muatan Lokal dengan filter tahun ajaran dan capaian kompetensi
        $mulokCount = 1;
        foreach ($mulok as $nama => $nilaiMulok) {
            if ($mulokCount <= 5) {
                $mataPelajaranId = $nilaiMulok->first()->mata_pelajaran_id;
                
                // Filter nilai berdasarkan tahun ajaran
                $nilaiAkhir = $nilaiMulok
                    ->when($tahunAjaranId, function($collection) use ($tahunAjaranId) {
                        return $collection->where('tahun_ajaran_id', $tahunAjaranId);
                    })
                    ->where('nilai_akhir_rapor', '!=', null)
                    ->first();
                
                // Nama muatan lokal
                $data["nama_mulok$mulokCount"] = $nama;
                
                if ($nilaiAkhir) {
                    $nilaiValue = $nilaiAkhir->nilai_akhir_rapor;
                    $data["nilai_mulok$mulokCount"] = number_format($nilaiValue, 1);

                    $capaianMulok = $preloadedCapaian[$mataPelajaranId]
                        ?? ['tertinggi' => '-', 'terendah' => '-'];
                    $data["capaian_tertinggi_mulok$mulokCount"] = $capaianMulok['tertinggi'];
                    $data["capaian_terendah_mulok$mulokCount"] = $capaianMulok['terendah'];
                    $data["capaian_mulok$mulokCount"] = '';
                } else {
                    $data["nilai_mulok$mulokCount"] = '-';
                    $data["capaian_tertinggi_mulok$mulokCount"] = '-';
                    $data["capaian_terendah_mulok$mulokCount"] = '-';
                    $data["capaian_mulok$mulokCount"] = '';
                }
                
                // KKM untuk muatan lokal
                $data["kkm_mulok$mulokCount"] = isset($kkmData[$mataPelajaranId]) ? $kkmData[$mataPelajaranId] : '70';
                
                $mulokCount++;
            }
        }

        // Tambahkan default untuk muatan lokal yang tidak ada
        for ($i = $mulokCount; $i <= 5; $i++) {
            $data["nama_mulok$i"] = '-';
            $data["nilai_mulok$i"] = '-';
            $data["capaian_tertinggi_mulok$i"] = '-';
            $data["capaian_terendah_mulok$i"] = '-';
            $data["capaian_mulok$i"] = '';
            $data["kkm_mulok$i"] = '70';
        }

        // Data Ekstrakurikuler dengan filter tahun ajaran
        $ekstrakurikuler = $this->siswa->nilaiEkstrakurikuler()
            ->with('ekstrakurikuler')
            ->when($tahunAjaranId, function($query) use ($tahunAjaranId) {
                return $query->where('tahun_ajaran_id', $tahunAjaranId);
            })
            ->where('semester', $semester)
            ->get();
            
        for ($i = 1; $i <= 6; $i++) {
            if (isset($ekstrakurikuler[$i-1])) {
                $ekskul = $ekstrakurikuler[$i-1];
                $data["ekskul{$i}_nama"] = $ekskul->ekstrakurikuler->nama_ekstrakurikuler ?? '-';
                $data["ekskul{$i}_keterangan"] = $ekskul->deskripsi ?: '-';
            } else {
                $data["ekskul{$i}_nama"] = '-';
                $data["ekskul{$i}_keterangan"] = '-';
            }
        }

        // Data Kehadiran dengan filter tahun ajaran
        $absensi = $this->siswa->absensi()
            ->where('semester', $semester)
            ->when($tahunAjaranId, function($query) use ($tahunAjaranId) {
                return $query->where('tahun_ajaran_id', $tahunAjaranId);
            })
            ->first();
            
        if ($absensi) {
            $data['sakit'] = $absensi->sakit ?: '0';
            $data['izin'] = $absensi->izin ?: '0';
            $data['tanpa_keterangan'] = $absensi->tanpa_keterangan ?: '0';
        } else {
            $data['sakit'] = '0';
            $data['izin'] = '0';
            $data['tanpa_keterangan'] = '0';
        }
        
        // Data sekolah dan lainnya
        if ($this->schoolProfile) {
            $data['nomor_telepon'] = $this->schoolProfile->telepon ?: '-';
            $data['kepala_sekolah'] = $this->schoolProfile->kepala_sekolah ?: '-';
            $data['wali_kelas'] = $kelas ? ($kelas->waliKelasName ?: '-') : '-';
            $data['nip_kepala_sekolah'] = $this->schoolProfile->nip_kepala_sekolah ?? '-';
            
            // PERBAIKAN: Ambil NUPTK wali kelas dari database
            $data['nip_wali_kelas'] = $waliKelas ? $waliKelas->nuptk : '-';
            $data['nuptk_wali_kelas'] = $waliKelas ? $waliKelas->nuptk : '-'; // Alias untuk NUPTK
            
            // TAMBAHAN: Tanggal otomatis
            $data['tanggal_terbit'] = date('d-m-Y');
            $data['tanggal_lengkap'] = $this->getFormattedDate(); // Format: "15 Januari 2025"
            $data['tanggal_rapor'] = $this->getFormattedDate(); // Alias untuk tanggal
            
            $data['tempat_terbit'] = $this->schoolProfile->tempat_terbit ?: 'Bandung';
            $data['tempat_tanggal'] = ($this->schoolProfile->tempat_terbit ?: 'Bandung') . ', ' . $this->getFormattedDate();
            
            // Data profil sekolah untuk template UAS
            $data['nama_sekolah'] = $this->schoolProfile->nama_sekolah ?: '-';
            $data['alamat_sekolah'] = $this->schoolProfile->alamat ?: '-';
            $data['kelurahan'] = $this->schoolProfile->kelurahan ?? '-';
            $data['kecamatan'] = $this->schoolProfile->kecamatan ?? '-';
            $data['kabupaten'] = $this->schoolProfile->kabupaten ?? '-';
            $data['provinsi'] = $this->schoolProfile->provinsi ?? '-';
            $data['kode_pos'] = $this->schoolProfile->kode_pos ?: '-';
            $data['website'] = $this->schoolProfile->website ?: '-';
            $data['email_sekolah'] = $this->schoolProfile->email_sekolah ?: '-';
            $data['npsn'] = $this->schoolProfile->npsn ?: '-';
        } else {
            $data['nomor_telepon'] = '-';
            $data['kepala_sekolah'] = '-';
            $data['wali_kelas'] = $kelas ? ($kelas->waliKelasName ?: '-') : '-';
            $data['nip_wali_kelas'] = $waliKelas ? $waliKelas->nuptk : '-';
            $data['nuptk_wali_kelas'] = $waliKelas ? $waliKelas->nuptk : '-';
            
            // Default tanggal
            $data['tanggal_terbit'] = date('d-m-Y');
            $data['tanggal_lengkap'] = $this->getFormattedDate();
            $data['tanggal_rapor'] = $this->getFormattedDate();
            $data['tempat_terbit'] = 'Bandung';
            $data['tempat_tanggal'] = 'Bandung, ' . $this->getFormattedDate();
            
            // Default untuk data profil sekolah jika tidak ada
            $data['nama_sekolah'] = '-';
            $data['alamat_sekolah'] = '-';
            $data['kelurahan'] = '-';
            $data['kecamatan'] = '-';
            $data['kabupaten'] = '-';
            $data['provinsi'] = '-';
            $data['kode_pos'] = '-';
            $data['website'] = '-';
            $data['email_sekolah'] = '-';
            $data['npsn'] = '-';
        }


        // Log data akhir yang akan diisi ke template
        $this->logReportProcessing('Data placeholder yang telah disiapkan:', [
            'mata_pelajaran_count' => count(array_filter(array_keys($data), function($key) {
                return strpos($key, 'nama_matapelajaran') === 0;
            })),
            'mulok_count' => count(array_filter(array_keys($data), function($key) {
                return strpos($key, 'nama_mulok') === 0;
            })),
            'kkm_count' => count(array_filter(array_keys($data), function($key) {
                return strpos($key, 'kkm_') === 0;
            })),
            'capaian_kompetensi_count' => count(array_filter(array_keys($data), function($key) {
                return strpos($key, 'capaian_kompetensi') === 0;
            })),
            'tahun_ajaran_id' => $tahunAjaranId,
            'catatan_guru' => $data['catatan_guru']
        ]);

        return $data;
    }

    /**
     * Resize dan crop foto ke rasio 3:4 sebelum dimasukkan ke template
     */
    protected function prepareAndResizeFoto($originalPath)
    {
        try {
            // Cek GD extension
            if (!extension_loaded('gd')) {
                Log::error('GD extension not loaded, using original photo');
                return $originalPath;
            }
            
            // Target size dalam pixel (untuk kualitas tinggi)
            $targetWidth = 450;   // 3 cm dalam pixel high-res
            $targetHeight = 600;  // 4 cm dalam pixel high-res
            
            // Create processed image
            $processedPath = $this->createProcessedFoto($originalPath, $targetWidth, $targetHeight);
            
            return $processedPath;
        } catch (\Exception $e) {
            Log::error('Error processing foto, using original', [
                'error' => $e->getMessage(),
                'original_path' => $originalPath
            ]);
            return $originalPath; // fallback ke foto asli
        }
    }

    protected function createProcessedFoto($sourcePath, $targetWidth, $targetHeight)
    {
        // FIX: Normalize semua paths
        $sourcePath = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $sourcePath);
        
        // Buat folder temp dengan normalized path
        $tempDir = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, storage_path('app/temp/processed_photos'));
        
        if (!file_exists($tempDir)) {
            if (!mkdir($tempDir, 0755, true)) {
                throw new \Exception("Cannot create temp directory: {$tempDir}");
            }
        }
        
        // Generate clean filename
        $sourceBasename = pathinfo($sourcePath, PATHINFO_FILENAME);
        $sourceExt = pathinfo($sourcePath, PATHINFO_EXTENSION);
        $sourceHash = substr(md5($sourcePath . filemtime($sourcePath)), 0, 8);
        
        // Clean basename untuk avoid invalid characters
        $sourceBasename = preg_replace('/[^a-zA-Z0-9_-]/', '_', $sourceBasename);
        $fileName = "processed_{$targetWidth}x{$targetHeight}_{$sourceBasename}_{$sourceHash}.jpg";
        
        $outputPath = $tempDir . DIRECTORY_SEPARATOR . $fileName;
        
        $this->logReportProcessing('Creating processed photo', [
            'source' => $sourcePath,
            'output' => $outputPath,
            'temp_dir' => $tempDir,
            'filename' => $fileName
        ]);
        
        // Skip jika sudah diproses (cache)
        if (file_exists($outputPath)) {
            $this->logReportProcessing('Using cached processed photo', [
                'cached_path' => $outputPath
            ]);
            return $outputPath;
        }
        
        // Validate source image
        if (!file_exists($sourcePath)) {
            throw new \Exception("Source image not found: {$sourcePath}");
        }
        
        $imageInfo = getimagesize($sourcePath);
        if (!$imageInfo) {
            throw new \Exception("Invalid image file: {$sourcePath}");
        }
        
        $sourceWidth = $imageInfo[0];
        $sourceHeight = $imageInfo[1];
        $sourceType = $imageInfo[2];
        
        $this->logReportProcessing('Processing photo details', [
            'source_size' => "{$sourceWidth}x{$sourceHeight}",
            'target_size' => "{$targetWidth}x{$targetHeight}",
            'source_type' => $sourceType,
            'source_path' => $sourcePath
        ]);
        
        // Create source image based on type
        $sourceImage = null;
        switch ($sourceType) {
            case IMAGETYPE_JPEG:
                $sourceImage = imagecreatefromjpeg($sourcePath);
                break;
            case IMAGETYPE_PNG:
                $sourceImage = imagecreatefrompng($sourcePath);
                break;
            case IMAGETYPE_GIF:
                $sourceImage = imagecreatefromgif($sourcePath);
                break;
            default:
                throw new \Exception("Unsupported image type: {$sourceType}");
        }
        
        if (!$sourceImage) {
            throw new \Exception("Failed to create image from source: {$sourcePath}");
        }
        
        // Calculate crop dimensions for perfect 3:4 ratio
        $sourceRatio = $sourceWidth / $sourceHeight;
        $targetRatio = $targetWidth / $targetHeight; // 3:4 = 0.75
        
        if ($sourceRatio > $targetRatio) {
            // Source is wider, crop width
            $cropHeight = $sourceHeight;
            $cropWidth = $sourceHeight * $targetRatio;
            $cropX = ($sourceWidth - $cropWidth) / 2;
            $cropY = 0;
        } else {
            // Source is taller, crop height
            $cropWidth = $sourceWidth;
            $cropHeight = $sourceWidth / $targetRatio;
            $cropX = 0;
            $cropY = ($sourceHeight - $cropHeight) / 2;
        }
        
        // Create target image dengan background putih
        $targetImage = imagecreatetruecolor($targetWidth, $targetHeight);
        $white = imagecolorallocate($targetImage, 255, 255, 255);
        imagefill($targetImage, 0, 0, $white);
        
        // Handle transparency untuk PNG
        if ($sourceType == IMAGETYPE_PNG) {
            $tempImage = imagecreatetruecolor($sourceWidth, $sourceHeight);
            $tempWhite = imagecolorallocate($tempImage, 255, 255, 255);
            imagefill($tempImage, 0, 0, $tempWhite);
            imagecopy($tempImage, $sourceImage, 0, 0, 0, 0, $sourceWidth, $sourceHeight);
            imagedestroy($sourceImage);
            $sourceImage = $tempImage;
        }
        
        // High-quality resize
        $resizeSuccess = imagecopyresampled(
            $targetImage, $sourceImage,
            0, 0, $cropX, $cropY,
            $targetWidth, $targetHeight, $cropWidth, $cropHeight
        );
        
        if (!$resizeSuccess) {
            imagedestroy($sourceImage);
            imagedestroy($targetImage);
            throw new \Exception("Failed to resize image");
        }
        
        // Save processed image
        $saveSuccess = imagejpeg($targetImage, $outputPath, 95);
        
        // Cleanup memory
        imagedestroy($sourceImage);
        imagedestroy($targetImage);
        
        if (!$saveSuccess) {
            throw new \Exception("Failed to save processed image to: {$outputPath}");
        }
        
        // Verify output file
        if (!file_exists($outputPath) || filesize($outputPath) == 0) {
            if (file_exists($outputPath)) {
                unlink($outputPath);
            }
            throw new \Exception("Processed image file is invalid");
        }
        
        $this->logReportProcessing('Photo processed successfully', [
            'source' => basename($sourcePath),
            'output' => $outputPath,
            'output_size' => filesize($outputPath) . ' bytes',
            'target_dimensions' => "{$targetWidth}x{$targetHeight}"
        ]);
        
        return $outputPath;
    }

    /**
     * Prepare foto siswa untuk template
     * 
     * @return string|null
     */
    protected function prepareFotoSiswa()
    {
        if ($this->siswa->photo) {
            $originalPath = storage_path('app/public/' . $this->siswa->photo);
            $originalPath = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $originalPath);

            if (file_exists($originalPath)) {
                $processedPath = $this->prepareAndResizeFoto($originalPath);

                $this->logReportProcessing('Using processed student photo', [
                    'siswa_id' => $this->siswa->id,
                    'path' => $processedPath,
                ]);

                return $processedPath;
            }
        }

        $defaultPath = public_path('images/default-student.png');
        $defaultPath = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $defaultPath);

        if (file_exists($defaultPath)) {
            $processedPath = $this->prepareAndResizeFoto($defaultPath);

            $this->logReportProcessing('Using processed default student photo', [
                'siswa_id' => $this->siswa->id,
                'path' => $processedPath,
            ]);

            return $processedPath;
        }

        return null;
    }

    protected function prepareTtdWaliKelas(?Guru $waliKelas): ?string
    {
        if (! $waliKelas || blank($waliKelas->signature_path)) {
            return null;
        }

        if (! Storage::disk('local')->exists($waliKelas->signature_path)) {
            Log::warning('File tanda tangan wali kelas tidak ditemukan; placeholder dikosongkan.', [
                'kelas_id' => $this->reportKelas?->id,
                'tahun_ajaran_id' => $this->tahunAjaranId,
            ]);

            return null;
        }

        $path = Storage::disk('local')->path($waliKelas->signature_path);

        if (! is_file($path) || ! is_readable($path) || @getimagesize($path) === false) {
            Log::warning('File tanda tangan wali kelas tidak dapat diproses; placeholder dikosongkan.', [
                'kelas_id' => $this->reportKelas?->id,
                'tahun_ajaran_id' => $this->tahunAjaranId,
            ]);

            return null;
        }

        return $path;
    }


    /**
     * Ambil data KKM untuk semua mata pelajaran
     * 
     * @param int|null $tahunAjaranId
     * @return array
     */
    protected function getKkmData($tahunAjaranId = null)
    {
        $tahunAjaranId = $tahunAjaranId ?: session('tahun_ajaran_id');
        
        // Ambil semua KKM berdasarkan tahun ajaran
        $kkmCollection = \App\Models\Kkm::where('tahun_ajaran_id', $tahunAjaranId)
            ->get();
        
        // Convert ke array dengan mata_pelajaran_id sebagai key
        $kkmData = [];
        foreach ($kkmCollection as $kkm) {
            $kkmData[$kkm->mata_pelajaran_id] = $kkm->nilai;
        }
        
        $this->logReportProcessing('Data KKM yang diambil:', [
            'tahun_ajaran_id' => $tahunAjaranId,
            'kkm_count' => count($kkmData),
            'kkm_data' => $kkmData
        ]);
        
        return $kkmData;
    }

    
    /**
     * Cari mata pelajaran yang cocok berdasarkan nama
     * 
     * @param string $mapelName Nama mata pelajaran
     * @param array $priorities Daftar prioritas mapel
     * @return string|null Kunci mata pelajaran yang cocok atau null jika tidak ditemukan
     */
    protected function findMatchingMapel($mapelName, $priorities)
    {
        // Normalisasi nama mapel (lowercase, hapus spasi berlebih)
        $normalizedName = strtolower(trim($mapelName));
        
        // Log untuk debugging
        $this->logReportProcessing('Mencoba mencocokkan mata pelajaran:', [
            'mapel_name' => $mapelName,
            'normalized' => $normalizedName
        ]);
        
        foreach ($priorities as $key => $keywords) {
            foreach ($keywords as $keyword) {
                $normalizedKeyword = strtolower(trim($keyword));
                
                // Exact match paling diutamakan
                if ($normalizedName === $normalizedKeyword) {
                    $this->logReportProcessing('Exact match ditemukan', [
                        'mapel' => $mapelName,
                        'matched_with' => $keyword,
                        'key' => $key
                    ]);
                    return $key;
                }
                
                // Partial match berikutnya 
                if (strpos($normalizedName, $normalizedKeyword) !== false) {
                    // Pastikan ini bukan partial match yang ambigu
                    // Misalnya, "Pendidikan" bisa merujuk ke banyak mata pelajaran
                    if (strlen($normalizedKeyword) > 5) {
                        $this->logReportProcessing('Partial match ditemukan', [
                            'mapel' => $mapelName,
                            'matched_with' => $keyword,
                            'key' => $key
                        ]);
                        return $key;
                    }
                }
                
                // Cek similaritas teks (terakhir dan dengan threshold yang lebih tinggi)
                $similarity = similar_text($normalizedName, $normalizedKeyword) / max(strlen($normalizedName), strlen($normalizedKeyword));
                if ($similarity > 0.8) { // Threshold 80% (lebih tinggi)
                    $this->logReportProcessing('Similarity match ditemukan', [
                        'mapel' => $mapelName,
                        'matched_with' => $keyword,
                        'similarity' => $similarity,
                        'key' => $key
                    ]);
                    return $key;
                }
            }
        }

        $this->logReportProcessing('Tidak ada kecocokan untuk mata pelajaran', ['mapel' => $mapelName]);
        return null;
    }

    protected function getFormattedDate()
    {
        $bulan = [
            1 => 'Januari', 2 => 'Februari', 3 => 'Maret', 4 => 'April',
            5 => 'Mei', 6 => 'Juni', 7 => 'Juli', 8 => 'Agustus',
            9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Desember'
        ];
        
        $tanggal = date('j');
        $bulanNama = $bulan[date('n')];
        $tahun = date('Y');
        
        return "{$tanggal} {$bulanNama} {$tahun}";
    }
    
    /**
     * Tentukan fase berdasarkan kelas
     * 
     * @param int $kelas Nomor kelas
     * @return string Fase pembelajaran
     */
    protected function determineFase($kelas)
    {
        if ($kelas <= 2) {
            return 'A';
        } elseif ($kelas <= 4) {
            return 'B';
        } else {
            return 'C';
        }
    }
    /**
     * Generate deskripsi capaian otomatis berdasarkan nilai
     *
     * @param float $nilai Nilai siswa
     * @param string $namaMapel Nama mata pelajaran
     * @param string|null $namaSiswa Nama siswa (opsional)
     * @return string Deskripsi capaian
     */
    protected function generateCapaianDeskripsi($nilai, $namaMapel, $namaSiswa = null)
    {
        // Default nama siswa jika tidak disediakan
        $siswa = $namaSiswa ?? $this->siswa->nama ?? 'Siswa';
        
        // Kategori berdasarkan nilai
        if ($nilai >= 90) {
            return "{$siswa} menunjukkan penguasaan yang sangat baik dalam mata pelajaran {$namaMapel}. Mampu memahami konsep, menerapkan, dan menganalisis dengan sangat baik.";
        } elseif ($nilai >= 80) {
            return "{$siswa} menunjukkan penguasaan yang baik dalam mata pelajaran {$namaMapel}. Mampu memahami konsep dan menerapkannya dengan baik.";
        } elseif ($nilai >= 70) {
            return "{$siswa} menunjukkan penguasaan yang cukup dalam mata pelajaran {$namaMapel}. Sudah mampu memahami konsep dasar dengan baik.";
        } elseif ($nilai >= 60) {
            return "{$siswa} menunjukkan penguasaan yang sedang dalam mata pelajaran {$namaMapel}. Perlu meningkatkan pemahaman konsep dasar.";
        } else {
            return "{$siswa} perlu bimbingan lebih lanjut dalam mata pelajaran {$namaMapel}. Disarankan untuk mengulang pembelajaran materi dasar.";
        }
    }

    /**
     * Generate deskripsi capaian spesifik berdasarkan mata pelajaran
     *
     * @param float $nilai Nilai siswa
     * @param string $key Kode mata pelajaran (pai, bahasa_indonesia, dll)
     * @return string Deskripsi capaian khusus mata pelajaran
     */
    protected function generateSpecificCapaian($nilai, $key)
    {
        $siswa = $this->siswa->nama ?? 'Siswa';
        
        // Deskripsi khusus berdasarkan mata pelajaran
        $specificDescriptions = [
            'pai' => [
                90 => "{$siswa} menunjukkan pemahaman yang sangat baik tentang nilai-nilai agama Islam dan dapat menerapkannya dalam kehidupan sehari-hari.",
                80 => "{$siswa} memahami nilai-nilai agama Islam dengan baik dan berusaha menerapkannya.",
                70 => "{$siswa} cukup memahami nilai-nilai dasar agama Islam.",
                60 => "{$siswa} perlu meningkatkan pemahaman tentang nilai-nilai dasar agama Islam.",
                0 => "{$siswa} membutuhkan bimbingan khusus dalam memahami nilai-nilai dasar agama Islam."
            ],
            'matematika' => [
                90 => "{$siswa} sangat baik dalam memahami konsep matematika dan dapat menyelesaikan soal-soal dengan sangat baik.",
                80 => "{$siswa} memahami konsep matematika dengan baik dan dapat menyelesaikan berbagai jenis soal.",
                70 => "{$siswa} cukup memahami konsep dasar matematika dan mampu menyelesaikan soal-soal sederhana.",
                60 => "{$siswa} perlu meningkatkan pemahaman konsep dasar matematika.",
                0 => "{$siswa} membutuhkan bimbingan khusus dalam memahami konsep dasar matematika."
            ],
            'bahasa_indonesia' => [
                90 => "{$siswa} sangat baik dalam berkomunikasi dan memahami teks bahasa Indonesia.",
                80 => "{$siswa} memiliki kemampuan yang baik dalam berkomunikasi dan memahami teks bahasa Indonesia.",
                70 => "{$siswa} cukup baik dalam berkomunikasi dan memahami teks bahasa Indonesia sederhana.",
                60 => "{$siswa} perlu meningkatkan kemampuan berkomunikasi dan pemahaman teks bahasa Indonesia.",
                0 => "{$siswa} membutuhkan bimbingan khusus dalam berkomunikasi dan memahami teks bahasa Indonesia dasar."
            ],
            'ppkn' => [
                90 => "{$siswa} menunjukkan pemahaman sangat baik tentang nilai-nilai Pancasila dan kewarganegaraan.",
                80 => "{$siswa} memiliki pemahaman yang baik tentang nilai-nilai Pancasila dan kewarganegaraan.",
                70 => "{$siswa} cukup memahami nilai-nilai dasar Pancasila dan kewarganegaraan.",
                60 => "{$siswa} perlu meningkatkan pemahaman tentang nilai-nilai Pancasila dan kewarganegaraan.",
                0 => "{$siswa} membutuhkan bimbingan khusus dalam memahami nilai dasar Pancasila dan kewarganegaraan."
            ],
            'pjok' => [
                90 => "{$siswa} sangat aktif dalam kegiatan olahraga dan menunjukkan keterampilan motorik yang sangat baik.",
                80 => "{$siswa} aktif dalam kegiatan olahraga dan memiliki keterampilan motorik yang baik.",
                70 => "{$siswa} cukup aktif dalam kegiatan olahraga dan menunjukkan perkembangan keterampilan motorik.",
                60 => "{$siswa} perlu lebih aktif dalam kegiatan olahraga dan meningkatkan keterampilan motorik.",
                0 => "{$siswa} membutuhkan bimbingan khusus dalam aktivitas olahraga dan pengembangan keterampilan motorik."
            ],
            'seni_musik' => [
                90 => "{$siswa} menunjukkan apresiasi dan keterampilan musik yang sangat baik.",
                80 => "{$siswa} memiliki apresiasi dan keterampilan musik yang baik.",
                70 => "{$siswa} cukup mampu mengapresiasi dan menunjukkan keterampilan musik dasar.",
                60 => "{$siswa} perlu meningkatkan apresiasi dan keterampilan musik.",
                0 => "{$siswa} membutuhkan bimbingan khusus dalam mengembangkan apresiasi dan keterampilan musik."
            ],
            'bahasa_inggris' => [
                90 => "{$siswa} sangat baik dalam berkomunikasi dan memahami teks bahasa Inggris sederhana.",
                80 => "{$siswa} memiliki kemampuan yang baik dalam bahasa Inggris dasar.",
                70 => "{$siswa} cukup memahami kosakata dan kalimat bahasa Inggris sederhana.",
                60 => "{$siswa} perlu meningkatkan pemahaman kosakata dan struktur bahasa Inggris dasar.",
                0 => "{$siswa} membutuhkan bimbingan khusus dalam mempelajari bahasa Inggris dasar."
            ],
        ];
        
        // Jika ada deskripsi khusus untuk mata pelajaran
        if (isset($specificDescriptions[$key])) {
            // Temukan deskripsi berdasarkan rentang nilai
            foreach ($specificDescriptions[$key] as $minNilai => $deskripsi) {
                if ($nilai >= $minNilai) {
                    return $deskripsi;
                }
            }
        }
        
        // Jika tidak ada deskripsi khusus, gunakan deskripsi umum
        $mapelNames = [
            'pai' => 'Pendidikan Agama dan Budi Pekerti',
            'ppkn' => 'Pendidikan Pancasila',
            'bahasa_indonesia' => 'Bahasa Indonesia',
            'matematika' => 'Matematika',
            'pjok' => 'Pendidikan Jasmani, Olahraga, dan Kesehatan',
            'seni_musik' => 'Seni Musik',
            'bahasa_inggris' => 'Bahasa Inggris'
        ];
        
        $namaMapel = $mapelNames[$key] ?? $key;
        return $this->generateCapaianDeskripsi($nilai, $namaMapel);
    }

     /**
     * Generate rapor dari template
     * 
     * @param bool $bypassValidation Lewati validasi data jika perlu
     * @return array
     */
    public function generate($bypassValidation = false)
    {
        try {
            $this->logReportProcessing('Starting generate() with professional photo processing', [
                'tahun_ajaran_id' => $this->tahunAjaranId
            ]);
            
            // Cleanup old temp photos di awal
            $this->cleanupTempPhotos();
            
            // 1. Validasi data
            if (!$bypassValidation) {
                $this->validateData();
            }

            // 2. Kumpulkan dan isi data
            $data = ReportPerformanceTracker::measureSegment('preload', function () {
                return $this->collectAllData();
            });
            
            // 3. Dapatkan semua variabel di template
            $variables = $this->processor->getVariables();
            
            $this->logReportProcessing('Variables in template:', [
                'found_variables' => $variables,
                'template_type' => $this->type,
                'tahun_ajaran_id' => $this->tahunAjaranId,
                'foto_siswa_found' => in_array('foto_siswa', $variables),
                'ttd_wali_kelas_found' => in_array('ttd_wali_kelas', $variables),
            ]);
            
            ReportPerformanceTracker::measureSegment('images', function () use (&$variables, $data) {
                // 4. HANDLE FOTO SISWA TERLEBIH DAHULU (PENTING!)
                if (in_array('foto_siswa', $variables)) {
                    $this->setFotoSiswa($data['foto_siswa']);

                    // Update variables list setelah foto di-set
                    $variables = $this->processor->getVariables();

                    $this->logReportProcessing('After setting foto siswa', [
                        'foto_siswa_still_exists' => in_array('foto_siswa', $variables),
                        'remaining_variables_count' => count($variables)
                    ]);
                }

                if (in_array('ttd_wali_kelas', $variables, true)) {
                    $this->setTtdWaliKelas($data['ttd_wali_kelas'] ?? null);

                    $variables = $this->processor->getVariables();

                    $this->logReportProcessing('After setting ttd wali kelas', [
                        'ttd_wali_kelas_still_exists' => in_array('ttd_wali_kelas', $variables, true),
                        'remaining_variables_count' => count($variables),
                    ]);
                }
            });

            ReportPerformanceTracker::measureSegment('template_replace', function () use (&$variables, $data) {
                // 5. Isi placeholder text (EXCLUDE image placeholders yang sudah di-handle)
                $imagePlaceholders = ['foto_siswa', 'ttd_wali_kelas'];
                foreach ($data as $key => $value) {
                    if (in_array($key, $variables) && ! in_array($key, $imagePlaceholders, true)) {
                        $processedValue = $this->processPlaceholderValue($value);
                        $this->processor->setValue($key, $processedValue);
                    }
                }

                // 6. Fill missing placeholders (EXCLUDE foto_siswa)
                $remainingVariables = $this->processor->getVariables();
                $missingPlaceholders = array_diff($remainingVariables, array_keys($data));

                $this->logReportProcessing('Filling missing placeholders', [
                    'missing_count' => count($missingPlaceholders),
                    'missing_placeholders' => $missingPlaceholders
                ]);

                foreach ($missingPlaceholders as $placeholder) {
                    // CRITICAL: SKIP foto_siswa completely!
                    if ($placeholder === 'foto_siswa') {
                        continue;
                    }

                    if ($placeholder === 'ttd_wali_kelas') {
                        $this->processor->setValue('ttd_wali_kelas', '');
                        continue;
                    }

                    if (! in_array($placeholder, $imagePlaceholders, true)) {
                        try {
                            $defaultValue = $this->getDefaultPlaceholderValue($placeholder);
                            $this->processor->setValue($placeholder, $defaultValue);
                        } catch (\Exception $e) {
                            Log::warning("Could not set default value for placeholder '{$placeholder}':", [
                                'error' => $e->getMessage()
                            ]);
                        }
                    }
                }

                // 7. Clean remaining placeholders (EXCLUDE foto_siswa)
                $finalRemainingPlaceholders = $this->processor->getVariables();

                $this->logReportProcessing('Final cleanup placeholders', [
                    'final_remaining_count' => count($finalRemainingPlaceholders),
                    'final_remaining' => $finalRemainingPlaceholders
                ]);

                foreach ($finalRemainingPlaceholders as $placeholder) {
                    // CRITICAL: NEVER touch foto_siswa again!
                    if ($placeholder === 'foto_siswa') {
                        Log::warning('foto_siswa placeholder still exists after setImageValue - this should not happen!');
                        continue;
                    }

                    if ($placeholder === 'ttd_wali_kelas') {
                        $this->processor->setValue('ttd_wali_kelas', '');
                        continue;
                    }

                    if (! in_array($placeholder, $imagePlaceholders, true)) {
                        try {
                            $this->processor->setValue($placeholder, '');
                        } catch (\Exception $e) {
                            Log::warning("Could not clean placeholder '{$placeholder}'");
                        }
                    }
                }
            });
            
            // 8. Generate file
            $filename = $this->generateFilename();
            $outputPath = $this->saveFile($filename);

            $this->logReportProcessing('Rapor generated successfully with professional photo processing', [
                'filename' => $filename,
                'siswa_id' => $this->siswa->id
            ]);

            return [
                'success' => true,
                'filename' => $filename,
                'path' => "generated/{$filename}"
            ];

        } catch (RaporException $e) {
            Log::error('Gagal generate rapor (RaporException):', [
                'error' => $e->getMessage(),
                'error_type' => $e->getErrorType(),
                'siswa_id' => $this->siswa->id,
                'template_id' => $this->template->id,
                'tahun_ajaran_id' => $this->tahunAjaranId
            ]);
            
            throw $e;
        } catch (\Exception $e) {
            Log::error('Gagal generate rapor (Exception):', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'siswa_id' => $this->siswa->id,
                'template_id' => $this->template->id,
                'tahun_ajaran_id' => $this->tahunAjaranId
            ]);
            
            throw new RaporException('Gagal generate rapor: ' . $e->getMessage(), 'general_error', 500, $e);
        }
    }

    /**
     * Set foto siswa ke template
     * 
     * @param string|null $fotoPath
     * @return void
     */
    protected function setFotoSiswa($fotoPath)
    {
        try {
            if ($fotoPath && file_exists($fotoPath)) {
                // Keep the table-cell placeholder as an inline 3x4 cm student photo.
                $this->fotoSiswaReplacementPath = $fotoPath;
                
                $this->processor->setImageValue('foto_siswa', [
                    'path' => $fotoPath,
                    'width' => self::FOTO_SISWA_WIDTH,
                    'height' => self::FOTO_SISWA_HEIGHT,
                    'ratio' => ! $this->isThreeByFourImage($fotoPath),
                ]);
                
                $this->logReportProcessing('Foto successfully set to template', [
                    'siswa_id' => $this->siswa->id,
                    'foto_path' => $fotoPath,
                    'size_cm' => self::FOTO_SISWA_WIDTH.'x'.self::FOTO_SISWA_HEIGHT,
                    'is_processed' => strpos($fotoPath, 'processed_') !== false
                ]);
            } else {
                // Placeholder jika tidak ada foto
                $this->fotoSiswaReplacementPath = null;
                $this->processor->setValue('foto_siswa', '');
                
                Log::warning('No photo available for template', [
                    'siswa_id' => $this->siswa->id
                ]);
            }
        } catch (\Exception $e) {
            Log::error('Error setting foto to template', [
                'siswa_id' => $this->siswa->id,
                'error' => $e->getMessage(),
                'foto_path' => $fotoPath
            ]);
            
            // Fallback graceful
            try {
                $this->fotoSiswaReplacementPath = null;
                $this->processor->setValue('foto_siswa', '');
            } catch (\Exception $fallbackError) {
                Log::error('Critical error in foto fallback', [
                    'error' => $fallbackError->getMessage()
                ]);
            }
        }
    }

    private function isThreeByFourImage(string $path): bool
    {
        $size = @getimagesize($path);

        if (! is_array($size) || empty($size[0]) || empty($size[1])) {
            return false;
        }

        return abs(($size[0] / $size[1]) - 0.75) < 0.01;
    }

    private function centerFotoSiswaParagraph(string $outputPath): void
    {
        if (! $this->fotoSiswaReplacementPath || ! is_file($outputPath)) {
            return;
        }

        $zip = new \ZipArchive;

        if ($zip->open($outputPath) !== true) {
            return;
        }

        $xml = $zip->getFromName('word/document.xml');

        if (! is_string($xml) || $xml === '') {
            $zip->close();

            return;
        }

        $pattern = '/<w:p\b[^>]*>(?:(?!<\/w:p>).)*<v:shape\b[^>]*style="[^"]*width:'
            .preg_quote(self::FOTO_SISWA_WIDTH, '/')
            .';height:'
            .preg_quote(self::FOTO_SISWA_HEIGHT, '/')
            .'[^"]*"(?:(?!<\/w:p>).)*<\/w:p>/s';

        $updatedXml = preg_replace_callback($pattern, function (array $matches) {
            return $this->centerParagraphXml($matches[0]);
        }, $xml);

        if (is_string($updatedXml) && $updatedXml !== $xml) {
            $zip->addFromString('word/document.xml', $updatedXml);

            $this->logReportProcessing('Centered student photo paragraph in generated DOCX', [
                'siswa_id' => $this->siswa->id,
            ]);
        }

        $zip->close();
    }

    private function centerParagraphXml(string $paragraphXml): string
    {
        if (preg_match('/<w:pPr\b[^>]*>.*?<\/w:pPr>/s', $paragraphXml)) {
            if (str_contains($paragraphXml, '<w:jc ')) {
                return preg_replace('/<w:jc\b[^\/>]*\/>/', '<w:jc w:val="center"/>', $paragraphXml, 1) ?? $paragraphXml;
            }

            return preg_replace('/<\/w:pPr>/', '<w:jc w:val="center"/></w:pPr>', $paragraphXml, 1) ?? $paragraphXml;
        }

        return preg_replace('/(<w:p\b[^>]*>)/', '$1<w:pPr><w:jc w:val="center"/></w:pPr>', $paragraphXml, 1) ?? $paragraphXml;
    }

    protected function setTtdWaliKelas(?string $signaturePath): void
    {
        try {
            if ($signaturePath && is_readable($signaturePath) && @getimagesize($signaturePath) !== false) {
                $this->processor->setImageValue('ttd_wali_kelas', [
                    'path' => $signaturePath,
                    'width' => 120,
                    'height' => 60,
                    'ratio' => true,
                ]);

                $this->logReportProcessing('Tanda tangan wali kelas set to template', [
                    'kelas_id' => $this->reportKelas?->id,
                    'tahun_ajaran_id' => $this->tahunAjaranId,
                ]);

                return;
            }

            $this->processor->setValue('ttd_wali_kelas', '');
        } catch (\Exception $e) {
            Log::warning('Gagal memproses tanda tangan wali kelas; placeholder dikosongkan.', [
                'kelas_id' => $this->reportKelas?->id,
                'tahun_ajaran_id' => $this->tahunAjaranId,
                'error' => $e->getMessage(),
            ]);

            try {
                $this->processor->setValue('ttd_wali_kelas', '');
            } catch (\Exception $fallbackError) {
                Log::warning('Gagal membersihkan placeholder tanda tangan wali kelas.', [
                    'kelas_id' => $this->reportKelas?->id,
                    'tahun_ajaran_id' => $this->tahunAjaranId,
                    'error' => $fallbackError->getMessage(),
                ]);
            }
        }
    }

        /**
     * Cleanup temporary processed photos (call ini di destructor atau setelah generate)
     */
    protected function cleanupTempPhotos()
    {
        try {
            $tempDir = storage_path('app/temp/processed_photos');
            
            if (!is_dir($tempDir)) {
                return;
            }
            
            // Hapus file yang lebih dari 1 jam (3600 detik)
            $files = glob($tempDir . '/processed_*.jpg');
            $now = time();
            $maxAge = 3600; // 1 hour
            
            foreach ($files as $file) {
                if (is_file($file)) {
                    $fileAge = $now - filemtime($file);
                    if ($fileAge > $maxAge) {
                        unlink($file);
                        $this->logReportProcessing('Cleaned up old processed photo', [
                            'file' => basename($file),
                            'age_minutes' => round($fileAge / 60)
                        ]);
                    }
                }
            }
        } catch (\Exception $e) {
            Log::error('Error cleaning up temp photos', [
                'error' => $e->getMessage()
            ]);
        }
    }


    /**
     * Process placeholder value to ensure it's properly formatted
     */
    protected function processPlaceholderValue($value)
    {
        // Handle null or empty values
        if ($value === null || $value === '') {
            return '-';
        }
        
        // Handle array values (shouldn't happen, but just in case)
        if (is_array($value)) {
            return implode(', ', $value);
        }
        
        // Handle boolean values
        if (is_bool($value)) {
            return $value ? 'Ya' : 'Tidak';
        }
        
        // Convert to string and trim
        $processedValue = trim((string) $value);
        
        // Return default if empty after processing
        return $processedValue ?: '-';
    }

    /**
     * Get default value for missing placeholders
     */
    protected function getDefaultPlaceholderValue($placeholder)
    {
        // CRITICAL: Never set default for foto_siswa
        if ($placeholder === 'foto_siswa') {
            return null; // This should never be called
        }

        if ($placeholder === 'ttd_wali_kelas') {
            return '';
        }
        
        // Special handling for specific placeholder types
        if (strpos($placeholder, 'nilai_') === 0) {
            return '-';
        }
        
        if (strpos($placeholder, 'kkm_') === 0) {
            return '70';
        }
        
        if (strpos($placeholder, 'catatan_') === 0) {
            return '-';
        }
        
        if (strpos($placeholder, 'sakit') !== false || 
            strpos($placeholder, 'izin') !== false || 
            strpos($placeholder, 'tanpa_keterangan') !== false) {
            return '0';
        }
        
        // Default for all other placeholders
        return '-';
    }
    /**
     * Get the semester data to use for the report
     */
    protected function getReportDataSemester()
    {
        // Currently, the method is tightly linking UTS->Semester 1 and UAS->Semester 2
        // Instead, use the current semester from the tahun ajaran

        if ($this->tahunAjaranId) {
            $tahunAjaran = $this->reportTahunAjaran ?: TahunAjaran::find($this->tahunAjaranId);
            if ($tahunAjaran) {
                return $tahunAjaran->semester;
            }
        }
        
        // Fallback to the traditional mapping only if tahun ajaran not found
        return $this->type === 'UTS' ? 1 : 2;
    }

   /**
     * Validasi data sebelum generate rapor
     * 
     * @throws RaporException
     * @return void
     */
    protected function validateData()
    {
        $tahunAjaranId = $this->tahunAjaranId;
        
        // Use the new method to determine semester based on current tahun ajaran
        $semester = $this->getSemesterForType($this->type, $tahunAjaranId);

        // Validasi template aktif
        if (!$this->template->is_active) {
            throw new RaporException(
                'Template rapor belum diaktifkan. Hubungi admin untuk mengaktifkan template.',
                'template_invalid',
                self::ERROR_TEMPLATE_INVALID
            );
        }

        // Validasi apakah siswa memiliki nilai untuk tahun ajaran yang aktif
        // Use the determined semester instead of type-based semester
        $hasAnyNilai = $this->siswa->nilais()
            ->whereHas('mataPelajaran', function($q) use ($semester) {
                $q->where('semester', $semester);
            })
            ->when($tahunAjaranId, function($query) use ($tahunAjaranId) {
                return $query->where('tahun_ajaran_id', $tahunAjaranId);
            })
            ->where('is_submitted', true)
            ->exists();
            
        if (!$hasAnyNilai) {
            throw new RaporException(
                'Siswa belum memiliki nilai pada tahun ajaran ini untuk semester ' . $semester . '. Mohon input nilai terlebih dahulu.',
                'data_incomplete',
                self::ERROR_DATA_INCOMPLETE
            );
        }

        // Cek kehadiran untuk tahun ajaran yang aktif
        // Use the determined semester instead of type-based semester
        $hasAbsensi = $this->siswa->absensi()
            ->where('semester', $semester) // Use the determined semester
            ->when($tahunAjaranId, function($query) use ($tahunAjaranId) {
                return $query->where('tahun_ajaran_id', $tahunAjaranId);
            })
            ->exists();
            
        if (!$hasAbsensi) {
            throw new RaporException(
                'Data kehadiran siswa pada tahun ajaran ini untuk semester ' . $semester . ' belum diisi.',
                'data_incomplete',
                self::ERROR_DATA_INCOMPLETE
            );
        }
    }

    /**
     * Generate nama file rapor using the helper
     * 
     * @return string
     */
    protected function generateFilename()
    {
        // Get tahun ajaran info
        $tahunAjaranText = null;
        if ($this->tahunAjaranId) {
            $tahunAjaran = $this->reportTahunAjaran ?: TahunAjaran::find($this->tahunAjaranId);
            if ($tahunAjaran) {
                $tahunAjaranText = $tahunAjaran->tahun_ajaran;
            }
        }
        $kelas = $this->reportKelas ?: $this->siswa->kelas;
        
        // Call the helper to generate a consistent filename
        return FileNameHelper::generateReportFilename(
            $this->type,
            $this->siswa->nama,
            $kelas ? $kelas->nomor_kelas . $kelas->nama_kelas : 'Kelas',
            $tahunAjaranText
        );
    }

    protected function calculateAverageTreatingNullAsZero($collection, $field)
    {
        $items = collect($collection)->values();

        if ($field === 'nilai_tp') {
            $items = $items->filter(function ($item) {
                return !is_null(data_get($item, 'tujuan_pembelajaran_id'));
            })->values();
        }

        if ($items->isEmpty()) {
            return null;
        }

        $sum = $items->sum(function ($item) use ($field) {
            return (float) data_get($item, $field, 0);
        });

        return $sum / $items->count();
    }

    /**
     * Simpan file rapor ke storage
     * 
     * @param string $filename
     * @return string
     * @throws RaporException
     */
    protected function saveFile($filename)
    {
        $outputPath = storage_path("app/public/generated/{$filename}");
        
        if (!file_exists(dirname($outputPath))) {
            mkdir(dirname($outputPath), 0755, true);
        }
        
        try {
            ReportPerformanceTracker::measureSegment('docx_save', function () use ($outputPath) {
                $this->processor->saveAs($outputPath);

                if (!file_exists($outputPath)) {
                    throw new \Exception("File tidak berhasil disimpan");
                }

                $this->centerFotoSiswaParagraph($outputPath);
                app(ReportIdentityLayoutStabilizer::class)->stabilize($outputPath);
            });
            
            $this->logReportProcessing('Rapor berhasil disimpan:', [
                'path' => $outputPath,
                'size' => filesize($outputPath),
                'tahun_ajaran_id' => $this->tahunAjaranId
            ]);
            
            return "generated/{$filename}";
        } catch (\Exception $e) {
            Log::error('Error saat menyimpan file rapor:', [
                'error' => $e->getMessage(),
                'path' => $outputPath,
                'tahun_ajaran_id' => $this->tahunAjaranId
            ]);
            
            throw new RaporException(
                "Gagal menyimpan file rapor: " . $e->getMessage(),
                'file_processing',
                self::ERROR_FILE_PROCESSING
            );
        }
    }
}

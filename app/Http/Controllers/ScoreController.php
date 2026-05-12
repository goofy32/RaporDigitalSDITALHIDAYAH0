<?php

namespace App\Http\Controllers;

use App\Traits\RequiresTahunAjaran;
use App\Models\Kelas;
use App\Models\MataPelajaran;
use App\Models\Siswa;
use App\Models\Nilai;
use App\Models\TujuanPembelajaran;
use App\Models\LingkupMateri;
use App\Models\Kkm;
use App\Models\BobotNilai;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;

class ScoreController extends Controller
{
    use RequiresTahunAjaran;

    public function index()
    {
        $guru = Auth::guard('guru')->user();
        $tahunAjaranId = session('tahun_ajaran_id');
        Log::info('Guru ID: ' . $guru->id);
        
        $kelasData = Kelas::with(['mataPelajarans' => function($query) use ($guru, $tahunAjaranId) {
            $query->where('guru_id', $guru->id);
            if ($tahunAjaranId) {
                $query->where('tahun_ajaran_id', $tahunAjaranId);
            }
        }])
        ->whereHas('mataPelajarans', function($query) use ($guru, $tahunAjaranId) {
            $query->where('guru_id', $guru->id);
            if ($tahunAjaranId) {
                $query->where('tahun_ajaran_id', $tahunAjaranId);
            }
        })
        ->when($tahunAjaranId, function($query) use ($tahunAjaranId) {
            return $query->where('tahun_ajaran_id', $tahunAjaranId);
        })
        ->get();
        
        Log::info('Kelas Data:', $kelasData->toArray());
        
        return view('pengajar.score', ['kelasData' => $kelasData]);
    }


    public function saveScore(Request $request, $id)
    {
        $tahunAjaranId = $this->getValidTahunAjaranId();

        if (!$tahunAjaranId) {
            return $this->failTahunAjaranNotSet($request, true);
        }

        try {
            DB::beginTransaction();
            $bobotNilai = BobotNilai::getDefault();
            $savedData = [];
            $notSavedData = []; // Tracking data yang tidak tersimpan

            foreach($request->scores as $siswaId => $scoreData) {
                $studentData = [
                    'nama' => Siswa::find($siswaId)->nama,
                    'nilai' => []
                ];
                $studentNotSaved = []; // Tracking nilai yang tidak tersimpan per siswa

                // Simpan nilai TP dan LM
                if (isset($scoreData['tp']) && is_array($scoreData['tp'])) {
                    foreach($scoreData['tp'] as $lmId => $tpScores) {
                        foreach($tpScores as $tpId => $nilai) {
                            try {
                                $tp = TujuanPembelajaran::find($tpId);
                                $lm = LingkupMateri::find($lmId);
                                
                                $nilaiData = [
                                    'nilai_tp' => $nilai !== '' ? $nilai : 0
                                ];
                                
                                if ($tahunAjaranId) {
                                    $nilaiData['tahun_ajaran_id'] = $tahunAjaranId;
                                }
                                
                                Nilai::updateOrCreate(
                                    [
                                        'siswa_id' => $siswaId,
                                        'mata_pelajaran_id' => $id,
                                        'lingkup_materi_id' => $lmId,
                                        'tujuan_pembelajaran_id' => $tpId,
                                        'tahun_ajaran_id' => $tahunAjaranId,
                                    ],
                                    $nilaiData
                                );

                                if ($nilai !== '' && $nilai !== null) {
                                    $studentData['nilai'][] = [
                                        'tipe' => 'TP',
                                        'kode' => $tp->kode_tp,
                                        'nilai' => $nilai
                                    ];
                                }
                            } catch (\Exception $e) {
                                $studentNotSaved[] = "TP {$tp->kode_tp}: {$e->getMessage()}";
                            }
                        }
                    }
                }
                
                // Tambahkan kode untuk simpan nilai Lingkup Materi (LM)
                if (isset($scoreData['lm']) && is_array($scoreData['lm'])) {
                    foreach($scoreData['lm'] as $lmId => $nilai) {
                        try {
                            $lm = LingkupMateri::find($lmId);
                            
                            $nilaiData = [
                                'nilai_lm' => $nilai !== '' ? $nilai : 0
                            ];
                            
                            if ($tahunAjaranId) {
                                $nilaiData['tahun_ajaran_id'] = $tahunAjaranId;
                            }
                            
                            Nilai::updateOrCreate(
                                [
                                    'siswa_id' => $siswaId,
                                    'mata_pelajaran_id' => $id,
                                    'lingkup_materi_id' => $lmId,
                                    'tahun_ajaran_id' => $tahunAjaranId,
                                ],
                                $nilaiData
                            );

                            if ($nilai !== '' && $nilai !== null) {
                                $studentData['nilai'][] = [
                                    'tipe' => 'LM',
                                    'kode' => $lm->judul_lingkup_materi,
                                    'nilai' => $nilai
                                ];
                            }
                        } catch (\Exception $e) {
                            $studentNotSaved[] = "LM {$lm->judul_lingkup_materi}: {$e->getMessage()}";
                        }
                    }
                }

                // Simpan nilai agregat
                $finalScores = [];
                $naTp = $this->calculateAverageScore($scoreData['tp'] ?? []);
                $naLm = $this->calculateAverageScore($scoreData['lm'] ?? []);
                $nilaiTes = $this->normalizeScoreValue($scoreData['nilai_tes'] ?? null);
                $nilaiNonTes = $this->normalizeScoreValue($scoreData['nilai_non_tes'] ?? null);
                $nilaiAkhirSemester = $this->calculateNilaiAkhirSemester($nilaiTes, $nilaiNonTes);
                $nilaiAkhirRapor = $this->calculateNilaiAkhirRapor($naTp, $naLm, $nilaiAkhirSemester, $bobotNilai);

                $finalScores = [
                    'na_tp' => $naTp,
                    'na_lm' => $naLm,
                    'nilai_tes' => $nilaiTes,
                    'nilai_non_tes' => $nilaiNonTes,
                    'nilai_akhir_semester' => $nilaiAkhirSemester,
                    'nilai_akhir_rapor' => $nilaiAkhirRapor,
                ];

                if ($tahunAjaranId) {
                    $finalScores['tahun_ajaran_id'] = $tahunAjaranId;
                }
                
                try {
                    if (!empty($finalScores)) {
                        Nilai::updateOrCreate(
                            [
                                'siswa_id' => $siswaId,
                                'mata_pelajaran_id' => $id,
                                'tahun_ajaran_id' => $tahunAjaranId,
                            ],
                            $finalScores
                        );

                        foreach($finalScores as $key => $value) {
                            if ($key !== 'tahun_ajaran_id' && $value !== null) {
                                $studentData['nilai'][] = [
                                    'tipe' => str_replace('_', ' ', ucwords($key)),
                                    'nilai' => $value
                                ];
                            }
                        }
                    }
                } catch (\Exception $e) {
                    $studentNotSaved[] = "Nilai Akhir: {$e->getMessage()}";
                }

                if (!empty($studentData['nilai'])) {
                    $savedData[] = $studentData;
                }
                if (!empty($studentNotSaved)) {
                    $notSavedData[$studentData['nama']] = $studentNotSaved;
                }
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Nilai berhasil disimpan!',
                'details' => $savedData,
                'warnings' => $notSavedData
            ]);

        } catch (\Exception $e) {
            DB::rollback();
            Log::error('[ScoreController] Save score failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'user_id' => Auth::guard('guru')->id(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'mata_pelajaran_id' => $id,
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Terjadi kesalahan. Silakan coba lagi.'
            ], 500);
        }
    }

    public function inputScore($id)
    {
        try {
            $mataPelajaran = MataPelajaran::with([
                'kelas.siswas' => function($query) {
                    $query->orderBy('nama', 'asc');
                },
                'lingkupMateris.tujuanPembelajarans',
            ])->findOrFail($id);

            // Validasi akses guru - with improved type checking
            $guru = Auth::guard('guru')->user();
            
            // Add debug logging
            Log::info('Checking guru access for mata pelajaran:', [
                'mata_pelajaran_id' => $id,
                'mata_pelajaran_guru_id' => $mataPelajaran->guru_id,
                'mata_pelajaran_guru_id_type' => gettype($mataPelajaran->guru_id),
                'current_guru_id' => $guru->id,
                'current_guru_id_type' => gettype($guru->id),
                'tahun_ajaran_mapel' => $mataPelajaran->tahun_ajaran_id,
                'tahun_ajaran_session' => session('tahun_ajaran_id')
            ]);
            
            // Fix the comparison with type casting
            if ((int)$mataPelajaran->guru_id !== (int)$guru->id) {
                return redirect()->route('pengajar.score')
                    ->with('error', 'Anda tidak memiliki akses ke mata pelajaran ini');
            }
            // Periksa apakah ada TP untuk setiap Lingkup Materi
            $hasTp = $mataPelajaran->lingkupMateris->every(function($lm) {
                return $lm->tujuanPembelajarans->isNotEmpty();
            });

            if (!$hasTp) {
                return redirect()->route('pengajar.score')
                    ->with('warning', 'Harap isi Tujuan Pembelajaran untuk mata pelajaran ini terlebih dahulu.');
            }

            // Siapkan data
            $subject = [
                'id' => $mataPelajaran->id,
                'name' => $mataPelajaran->nama_pelajaran,
                'class' => $mataPelajaran->kelas->nomor_kelas . ' ' . $mataPelajaran->kelas->nama_kelas
            ];

            // Filter siswa berdasarkan tahun ajaran yang aktif
            $tahunAjaranId = session('tahun_ajaran_id');
            $siswas = $mataPelajaran->kelas->siswas;
            
            if ($tahunAjaranId) {
                $siswas = $siswas->filter(function($siswa) use ($tahunAjaranId) {
                    return $siswa->kelas && $siswa->kelas->tahun_ajaran_id == $tahunAjaranId;
                });
            }
            
            $students = $siswas->sortBy('nama')->map(function($siswa) {
                return [
                    'id' => $siswa->id,
                    'name' => $siswa->nama
                ];
            });

            // Inisialisasi struktur data nilai
            $existingScores = [];
            foreach ($siswas as $siswa) {
                $existingScores[$siswa->id] = [
                    'tp' => [],
                    'lm' => [],
                    'na_tp' => null,
                    'na_lm' => null,
                    'nilai_tes' => null,
                    'nilai_non_tes' => null,
                    'nilai_akhir_semester' => null,
                    'nilai_akhir_rapor' => null
                ];
                foreach ($mataPelajaran->lingkupMateris as $lm) {
                    $existingScores[$siswa->id]['lm'][$lm->id] = null;
                    foreach ($lm->tujuanPembelajarans as $tp) {
                        $existingScores[$siswa->id]['tp'][$lm->id][$tp->id] = null;
                    }
                }
            }

            // Ambil semua nilai yang sudah ada dengan filter tahun ajaran jika ada
            $existingNilaisQuery = Nilai::where('mata_pelajaran_id', $id);
            if ($tahunAjaranId) {
                $existingNilaisQuery->where('tahun_ajaran_id', $tahunAjaranId);
            }
            $existingNilais = $existingNilaisQuery->get();
            
            // Isi struktur data dengan nilai yang ada
            foreach ($existingNilais as $nilai) {
                if ($nilai->nilai_tp !== null) {
                    $existingScores[$nilai->siswa_id]['tp'][$nilai->lingkup_materi_id][$nilai->tujuan_pembelajaran_id] = $nilai->nilai_tp;
                }
                if ($nilai->nilai_lm !== null) {
                    $existingScores[$nilai->siswa_id]['lm'][$nilai->lingkup_materi_id] = $nilai->nilai_lm;
                }
                if ($nilai->na_tp !== null) {
                    $existingScores[$nilai->siswa_id]['na_tp'] = $nilai->na_tp;
                }
                if ($nilai->na_lm !== null) {
                    $existingScores[$nilai->siswa_id]['na_lm'] = $nilai->na_lm;
                }
                if ($nilai->nilai_akhir_semester !== null) {
                    $existingScores[$nilai->siswa_id]['nilai_akhir_semester'] = $nilai->nilai_akhir_semester;
                }
                if ($nilai->nilai_tes !== null) {
                    $existingScores[$nilai->siswa_id]['nilai_tes'] = $nilai->nilai_tes;
                }
                if ($nilai->nilai_non_tes !== null) {
                    $existingScores[$nilai->siswa_id]['nilai_non_tes'] = $nilai->nilai_non_tes;
                }
                if ($nilai->nilai_akhir_rapor !== null) {
                    $existingScores[$nilai->siswa_id]['nilai_akhir_rapor'] = $nilai->nilai_akhir_rapor;
                }
            }

            $mataPelajaranList = MataPelajaran::where('kelas_id', $mataPelajaran->kelas_id)
                ->where('guru_id', $guru->id)
                ->when($tahunAjaranId, function($query) use ($tahunAjaranId) {
                    return $query->where('tahun_ajaran_id', $tahunAjaranId);
                })
                ->get();

            $kkm = Kkm::where('mata_pelajaran_id', $id)
            ->where('tahun_ajaran_id', session('tahun_ajaran_id'))
            ->first();
            
            $kkmValue = $kkm ? $kkm->nilai : 70;
            
            // Ambil bobot nilai
            $bobotNilai = BobotNilai::getDefault();
            
            return view('pengajar.input_score', compact(
                'subject',
                'students',
                'mataPelajaran',
                'existingScores',
                'mataPelajaranList',
                'kkmValue',
                'bobotNilai'
            ));

        } catch (\Exception $e) {
            Log::error('Error in ScoreController@inputScore: ' . $e->getMessage());
            return redirect()->route('pengajar.score')
                ->with('error', 'Terjadi kesalahan saat memuat data');
        }
    }

    private function compareScores($existingNilai, $newScoreData) 
    {
        if (!$existingNilai) return false;
    
        // Bandingkan semua jenis nilai
        return (float)$existingNilai->nilai_tp === (float)($newScoreData['tp'] ?? null) &&
               (float)$existingNilai->nilai_lm === (float)($newScoreData['lm'] ?? null) &&
               (float)$existingNilai->na_tp === (float)($newScoreData['na_tp'] ?? null) &&
               (float)$existingNilai->na_lm === (float)($newScoreData['na_lm'] ?? null) &&
               (float)$existingNilai->nilai_tes === (float)($newScoreData['nilai_tes'] ?? null) &&
               (float)$existingNilai->nilai_non_tes === (float)($newScoreData['nilai_non_tes'] ?? null) &&
               (float)$existingNilai->nilai_akhir_semester === (float)($newScoreData['nilai_akhir'] ?? null) &&
               (float)$existingNilai->nilai_akhir_rapor === (float)($newScoreData['nilai_akhir_rapor'] ?? null);
    }

    
    private function hasChanges($existing, $new)
    {
        foreach ($new as $key => $value) {
            if ($existing->$key != $value) {
                return true;
            }
        }
        return false;
    }

    private function normalizeScoreValue($value): ?float
    {
        if ($value === '' || $value === null) {
            return null;
        }

        return is_numeric($value) ? (float) $value : null;
    }

    private function calculateAverageScore(array $scores): float
    {
        $sum = 0;
        $count = 0;

        array_walk_recursive($scores, function ($value) use (&$sum, &$count) {
            $count++;
            $sum += ($value === '' || $value === null || !is_numeric($value))
                ? 0
                : (float) $value;
        });

        if ($count === 0) {
            return 0.0;
        }

        return round($sum / $count, 2);
    }

    private function calculateNilaiAkhirSemester(?float $nilaiTes, ?float $nilaiNonTes): float
    {
        if ($nilaiTes === null || $nilaiNonTes === null) {
            return 0.0;
        }

        return round(($nilaiTes + $nilaiNonTes) / 2, 2);
    }

    private function calculateNilaiAkhirRapor(
        float $naTp,
        float $naLm,
        float $nilaiAkhirSemester,
        BobotNilai $bobotNilai
    ): float {
        $totalBobot = $bobotNilai->getTotal();

        if ($totalBobot === 0) {
            return 0.0;
        }

        return round(
            (
                ($naTp * (int) $bobotNilai->bobot_tp) +
                ($naLm * (int) $bobotNilai->bobot_lm) +
                ($nilaiAkhirSemester * (int) $bobotNilai->bobot_as)
            ) / $totalBobot
        );
    }
      
    public function previewScore($id)
    {
        try {
            $tahunAjaranId = session('tahun_ajaran_id');
            
            // Load mata pelajaran dengan relasi yang diperlukan
            $mataPelajaran = MataPelajaran::with([
                'kelas.siswas' => function($query) {
                    $query->orderBy('nama', 'asc');
                },
                'lingkupMateris.tujuanPembelajarans',
                'lingkupMateris.nilais' => function($query) use ($tahunAjaranId) {
                    $query->select(
                        'nilais.*',
                        'siswa_id',
                        'lingkup_materi_id',
                        'tujuan_pembelajaran_id',
                        'nilai_tp',
                        'nilai_lm',
                        'na_tp',
                        'na_lm',
                        'nilai_tes',
                        'nilai_non_tes',
                        'nilai_akhir_semester',
                        'nilai_akhir_rapor'
                    );
                    
                    // Filter nilai berdasarkan tahun ajaran yang aktif
                    if ($tahunAjaranId) {
                        $query->where('tahun_ajaran_id', $tahunAjaranId);
                    }
                }
            ])->findOrFail($id);
    
            // Validasi akses guru
            $guru = Auth::guard('guru')->user();
                        Log::info('Checking guru access for mata pelajaran preview:', [
                'mata_pelajaran_id' => $id,
                'mata_pelajaran_guru_id' => $mataPelajaran->guru_id, 
                'mata_pelajaran_guru_id_type' => gettype($mataPelajaran->guru_id),
                'current_guru_id' => $guru->id,
                'current_guru_id_type' => gettype($guru->id),
                'tahun_ajaran_mapel' => $mataPelajaran->tahun_ajaran_id,
                'tahun_ajaran_session' => $tahunAjaranId
            ]);
            if ($mataPelajaran->guru_id !== $guru->id) {
                return redirect()->route('pengajar.score.index')
                    ->with('error', 'Anda tidak memiliki akses ke mata pelajaran ini');
            }
    
            // Filter siswa berdasarkan tahun ajaran aktif
            $students = $mataPelajaran->kelas->siswas
                ->when($tahunAjaranId, function($collection) use ($tahunAjaranId) {
                    return $collection->filter(function($siswa) use ($tahunAjaranId) {
                        return $siswa->kelas && $siswa->kelas->tahun_ajaran_id == $tahunAjaranId;
                    });
                })
                ->sortBy('nama')
                ->map(function($siswa) {
                    return [
                        'id' => $siswa->id,
                        'name' => $siswa->nama
                    ];
                });
            
            // Inisialisasi struktur data nilai
            $existingScores = [];
            foreach ($students as $student) {
                $existingScores[$student['id']] = [
                    'tp' => [],
                    'lm' => [],
                    'na_tp' => null,
                    'na_lm' => null,
                    'nilai_tes' => null,
                    'nilai_non_tes' => null,
                    'nilai_akhir_semester' => null,
                    'nilai_akhir_rapor' => null
                ];
                
                foreach ($mataPelajaran->lingkupMateris as $lm) {
                    $existingScores[$student['id']]['lm'][$lm->id] = null;
                    foreach ($lm->tujuanPembelajarans as $tp) {
                        $existingScores[$student['id']]['tp'][$lm->id][$tp->id] = null;
                    }
                }
            }
    
            // Ambil semua nilai dengan single query dan filter berdasarkan tahun ajaran
            $nilaiQuery = Nilai::where('mata_pelajaran_id', $id);
            
            if ($tahunAjaranId) {
                $nilaiQuery->where('tahun_ajaran_id', $tahunAjaranId);
            }
            
            $nilais = $nilaiQuery->get()->groupBy('siswa_id');
    
            // Isi struktur data dengan nilai yang ada
            foreach ($nilais as $siswaId => $nilaiSiswa) {
                if (!isset($existingScores[$siswaId])) continue;
                
                foreach ($nilaiSiswa as $nilai) {
                    // Isi nilai TP
                    if ($nilai->nilai_tp !== null && $nilai->tujuan_pembelajaran_id && $nilai->lingkup_materi_id) {
                        $existingScores[$siswaId]['tp'][$nilai->lingkup_materi_id][$nilai->tujuan_pembelajaran_id] = $nilai->nilai_tp;
                    }
                    
                    // Isi nilai LM
                    if ($nilai->nilai_lm !== null && $nilai->lingkup_materi_id) {
                        $existingScores[$siswaId]['lm'][$nilai->lingkup_materi_id] = $nilai->nilai_lm;
                    }
                    
                    // Isi nilai agregat
                    if ($nilai->na_tp !== null) {
                        $existingScores[$siswaId]['na_tp'] = $nilai->na_tp;
                    }
                    if ($nilai->na_lm !== null) {
                        $existingScores[$siswaId]['na_lm'] = $nilai->na_lm;
                    }
                    if ($nilai->nilai_tes !== null) {
                        $existingScores[$siswaId]['nilai_tes'] = $nilai->nilai_tes;
                    }
                    if ($nilai->nilai_non_tes !== null) {
                        $existingScores[$siswaId]['nilai_non_tes'] = $nilai->nilai_non_tes;
                    }
                    if ($nilai->nilai_akhir_semester !== null) {
                        $existingScores[$siswaId]['nilai_akhir_semester'] = $nilai->nilai_akhir_semester;
                    }
                    if ($nilai->nilai_akhir_rapor !== null) {
                        $existingScores[$siswaId]['nilai_akhir_rapor'] = $nilai->nilai_akhir_rapor;
                    }
                }
            }
    
            $kkm = Kkm::where('mata_pelajaran_id', $id)
            ->where('tahun_ajaran_id', session('tahun_ajaran_id'))
            ->first();
            
            $kkmValue = $kkm ? $kkm->nilai : 70; // Default ke 70 jika tidak ada KKM
            
            // Tambahkan ini: Ambil bobot nilai
            $bobotNilai = BobotNilai::getDefault();
            
            // Kirim variabel tambahan ke view
            return view('pengajar.preview_score', compact(
                'mataPelajaran', 
                'existingScores', 
                'students',
                'kkmValue',    // Tambahkan ini
                'bobotNilai'   // Tambahkan ini
            ));
        } catch (\Exception $e) {
            Log::error('[ScoreController] Preview score failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'user_id' => Auth::guard('guru')->id(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'mata_pelajaran_id' => $id,
            ]);
            return redirect()->route('pengajar.score.index')
                ->with('error', 'Terjadi kesalahan. Silakan coba lagi.');
        }
    }

    public function deleteNilai(Request $request)
    {
        try {
            DB::beginTransaction();
            $tahunAjaranId = session('tahun_ajaran_id');
            
            // Query dasar untuk menghapus semua nilai siswa untuk mapel tertentu
            Nilai::where([
                'siswa_id' => $request->siswa_id,
                'mata_pelajaran_id' => $request->mata_pelajaran_id,
            ])
            ->when($tahunAjaranId, function($query) use ($tahunAjaranId) {
                return $query->where('tahun_ajaran_id', $tahunAjaranId);
            })
            ->delete();

            DB::commit();
            return response()->json([
                'success' => true,
                'message' => 'Nilai berhasil dihapus'
            ]);
        } catch (\Exception $e) {
            DB::rollback();
            return response()->json([
                'success' => false, 
                'message' => 'Gagal menghapus nilai: ' . $e->getMessage()
            ]);
        }
    }
}

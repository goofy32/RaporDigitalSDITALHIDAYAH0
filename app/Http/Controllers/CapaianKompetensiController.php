<?php
// app/Http/Controllers/CapaianKompetensiController.php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\CapaianKompetensiTemplate;
use App\Models\CapaianKompetensiCustom;
use App\Models\CapaianPhraseDefault;
use App\Models\Kelas;
use App\Models\MataPelajaran;
use App\Models\Siswa;
use App\Models\TahunAjaran;
use App\Services\CapaianKompetensiTextService;
use App\Services\PdfCacheService;
use App\Services\SiswaKelasSemesterResolver;
use App\Traits\RequiresTahunAjaran;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class CapaianKompetensiController extends Controller
{
    use RequiresTahunAjaran;

    // =============== ADMIN METHODS ===============
    
    /**
     * Tampilkan halaman pengaturan template capaian kompetensi (Admin)
     */
    public function adminIndex()
    {
        $tahunAjaranId = session('tahun_ajaran_id');
        
        // Ambil semua mata pelajaran unik di tahun ajaran ini
        $mataPelajarans = MataPelajaran::where('tahun_ajaran_id', $tahunAjaranId)
            ->select('nama_pelajaran')
            ->distinct()
            ->orderBy('nama_pelajaran')
            ->pluck('nama_pelajaran');

        // Ambil templates yang sudah ada
        $templates = CapaianKompetensiTemplate::where('tahun_ajaran_id', $tahunAjaranId)
            ->orderBy('mata_pelajaran')
            ->orderBy('nilai_min', 'desc')
            ->get()
            ->groupBy('mata_pelajaran');

        return view('admin.capaian_kompetensi.index', compact('mataPelajarans', 'templates'));
    }

    /**
     * Simpan template capaian kompetensi (Admin)
     */
    public function adminStore(Request $request)
    {
        $tahunAjaranId = $this->getValidTahunAjaranId();

        if (!$tahunAjaranId) {
            return $this->failTahunAjaranNotSet($request);
        }

        $request->validate([
            'mata_pelajaran' => 'required|string|max:255',
            'nilai_min' => 'required|numeric|min:0|max:100',
            'nilai_max' => 'required|numeric|min:0|max:100|gte:nilai_min',
            'template_text' => 'required|string|max:1000',
        ]);
        // Cek overlap range nilai untuk mata pelajaran yang sama
        $overlap = CapaianKompetensiTemplate::where('mata_pelajaran', $request->mata_pelajaran)
            ->where('tahun_ajaran_id', $tahunAjaranId)
            ->where(function($query) use ($request) {
                $query->whereBetween('nilai_min', [$request->nilai_min, $request->nilai_max])
                      ->orWhereBetween('nilai_max', [$request->nilai_min, $request->nilai_max])
                      ->orWhere(function($q) use ($request) {
                          $q->where('nilai_min', '<=', $request->nilai_min)
                            ->where('nilai_max', '>=', $request->nilai_max);
                      });
            })
            ->exists();

        if ($overlap) {
            return redirect()->back()
                ->withInput()
                ->withErrors(['nilai_min' => 'Range nilai bertabrakan dengan template yang sudah ada.']);
        }

        CapaianKompetensiTemplate::create([
            'mata_pelajaran' => $request->mata_pelajaran,
            'nilai_min' => $request->nilai_min,
            'nilai_max' => $request->nilai_max,
            'template_text' => $request->template_text,
            'tahun_ajaran_id' => $tahunAjaranId,
        ]);

        return redirect()->back()->with('success', 'Template capaian kompetensi berhasil ditambahkan.');
    }

    /**
     * Hapus template capaian kompetensi (Admin)
     */
    public function adminDestroy($id)
    {
        try {
            $template = CapaianKompetensiTemplate::findOrFail($id);
            $template->delete();

            return response()->json([
                'success' => true,
                'message' => 'Template berhasil dihapus.'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Gagal menghapus template: ' . $e->getMessage()
            ], 500);
        }
    }

    // =============== WALI KELAS METHODS ===============
    
    /**
     * Tampilkan daftar mata pelajaran untuk capaian kompetensi (Wali Kelas)
     */
    public function waliKelasIndex()
    {
        $guru = auth()->guard('guru')->user();
        abort_unless($guru && session('selected_role') === 'wali_kelas', 403);

        $tahunAjaranId = $this->getValidTahunAjaranId();

        if (!$tahunAjaranId) {
            return $this->failTahunAjaranNotSet(request());
        }

        $semester = $this->getCurrentSemester($tahunAjaranId);

        // Ambil kelas yang diwalikan
        $kelas = $this->getWaliKelasKelas($guru, $tahunAjaranId);
        abort_unless($kelas, 403);

        // Ambil mata pelajaran di kelas ini
        $mataPelajarans = MataPelajaran::where('kelas_id', $kelas->id)
            ->where('tahun_ajaran_id', $tahunAjaranId)
            ->where('semester', $semester)
            ->with(['guru'])
            ->orderBy('nama_pelajaran')
            ->get();

        $siswaList = $this->studentsForWaliClass((int) $kelas->id, $tahunAjaranId, $semester);
        $totalSiswa = $siswaList->count();
        $studentIds = $siswaList->pluck('id');
        $customCounts = CapaianKompetensiCustom::whereIn('mata_pelajaran_id', $mataPelajarans->pluck('id'))
            ->whereIn('siswa_id', $studentIds)
            ->where('tahun_ajaran_id', $tahunAjaranId)
            ->where('semester', $semester)
            ->where(function ($query) {
                $query->whereNotNull('custom_capaian_tertinggi')
                    ->whereRaw("TRIM(custom_capaian_tertinggi) <> ''")
                    ->orWhere(function ($query) {
                        $query->whereNotNull('custom_capaian_terendah')
                            ->whereRaw("TRIM(custom_capaian_terendah) <> ''");
                    })
                    ->orWhere(function ($query) {
                        $query->whereIn('tertinggi_prefix_mode', ['preset', 'custom'])
                            ->whereNotNull('tertinggi_prefix_text')
                            ->whereRaw("TRIM(tertinggi_prefix_text) <> ''");
                    })
                    ->orWhere(function ($query) {
                        $query->whereIn('terendah_prefix_mode', ['preset', 'custom'])
                            ->whereNotNull('terendah_prefix_text')
                            ->whereRaw("TRIM(terendah_prefix_text) <> ''");
                    });
            })
            ->select('mata_pelajaran_id', DB::raw('count(distinct siswa_id) as aggregate'))
            ->groupBy('mata_pelajaran_id')
            ->pluck('aggregate', 'mata_pelajaran_id');

        return view('wali_kelas.capaian_kompetensi.index', compact(
            'mataPelajarans',
            'kelas',
            'totalSiswa',
            'customCounts',
            'tahunAjaranId',
            'semester'
        ));
    }

    private function getWaliKelasKelas($guru, $tahunAjaranId)
    {
        $kelasId = DB::table('guru_kelas')
            ->join('kelas', function ($join) {
                $join->on('guru_kelas.kelas_id', '=', 'kelas.id')
                    ->whereNull('kelas.deleted_at');
            })
            ->where('guru_kelas.guru_id', $guru->id)
            ->where('guru_kelas.is_wali_kelas', true)
            ->where('guru_kelas.role', 'wali_kelas')
            ->where('kelas.tahun_ajaran_id', $tahunAjaranId)
            ->value('kelas.id');

        return $kelasId ? Kelas::find($kelasId) : null;
    }

    /**
     * Tampilkan form edit capaian kompetensi untuk mata pelajaran tertentu (Wali Kelas)
     */
    public function waliKelasEdit($mataPelajaranId)
    {
        $tahunAjaranId = $this->getValidTahunAjaranId();

        if (!$tahunAjaranId) {
            return $this->failTahunAjaranNotSet(request());
        }

        $semester = $this->getCurrentSemester($tahunAjaranId);

        $mataPelajaran = MataPelajaran::findOrFail($mataPelajaranId);

        // Cek akses wali kelas
        $kelas = $this->authorizeWaliSubject($mataPelajaran, $tahunAjaranId, $semester);
        $tahunAjaran = TahunAjaran::find($tahunAjaranId);

        // Ambil semua siswa di kelas
        $siswaList = $this->studentsForWaliClass((int) $kelas->id, $tahunAjaranId, $semester);
        $studentIds = $siswaList->pluck('id');

        // Ambil capaian kompetensi custom yang sudah ada
        $existingCapaian = CapaianKompetensiCustom::where('mata_pelajaran_id', $mataPelajaranId)
            ->whereIn('siswa_id', $studentIds)
            ->where('tahun_ajaran_id', $tahunAjaranId)
            ->where('semester', $semester)
            ->get()
            ->keyBy('siswa_id');

        $phraseDefaults = Schema::hasTable('capaian_phrase_defaults')
            ? CapaianPhraseDefault::where('tahun_ajaran_id', $tahunAjaranId)
                ->where('semester', $semester)
                ->where('kelas_id', $kelas->id)
                ->where('mata_pelajaran_id', $mataPelajaranId)
                ->get()
                ->keyBy('type')
            : collect();
        $prefixPresets = $this->capaianPrefixPresets();
        $studentCapaianRows = $this->buildStudentCapaianRows(
            $siswaList,
            $mataPelajaran,
            $existingCapaian,
            $tahunAjaranId,
            $semester
        );

        return view('wali_kelas.capaian_kompetensi.edit', compact(
            'mataPelajaran',
            'siswaList', 
            'existingCapaian',
            'tahunAjaranId',
            'semester',
            'kelas',
            'tahunAjaran',
            'phraseDefaults',
            'prefixPresets',
            'studentCapaianRows'
        ));
    }

    /**
     * Update capaian kompetensi custom (Wali Kelas)
     */
    public function waliKelasUpdate(Request $request, $mataPelajaranId)
    {
        $tahunAjaranId = $this->getValidTahunAjaranId();

        if (!$tahunAjaranId) {
            return $this->failTahunAjaranNotSet($request);
        }

        $semester = $this->getCurrentSemester($tahunAjaranId);

        $mataPelajaran = MataPelajaran::findOrFail($mataPelajaranId);

        // Cek akses wali kelas
        $kelas = $this->authorizeWaliSubject($mataPelajaran, $tahunAjaranId, $semester);

        $request->validate([
            'capaian_tertinggi' => 'array',
            'capaian_tertinggi.*' => 'nullable|string|max:1000',
            'capaian_terendah' => 'array',
            'capaian_terendah.*' => 'nullable|string|max:1000',
        ]);

        $capaianTertinggi = $request->input('capaian_tertinggi', []);
        $capaianTerendah = $request->input('capaian_terendah', []);
        $siswaIds = collect(array_keys($capaianTertinggi))
            ->merge(array_keys($capaianTerendah))
            ->unique()
            ->values();

        $this->assertAllStudentsBelongToWaliClass(
            $siswaIds->all(),
            (int) $kelas->id,
            $tahunAjaranId,
            $semester
        );

        try {
            DB::transaction(function () use ($siswaIds, $capaianTertinggi, $capaianTerendah, $mataPelajaranId, $tahunAjaranId, $semester) {
                foreach ($siswaIds as $siswaId) {
                    $siswaId = (int) $siswaId;
                    $customTertinggi = trim((string) ($capaianTertinggi[$siswaId] ?? ''));
                    $customTerendah = trim((string) ($capaianTerendah[$siswaId] ?? ''));

                    if ($customTertinggi !== '' || $customTerendah !== '') {
                        CapaianKompetensiCustom::updateOrCreate(
                            [
                                'siswa_id' => $siswaId,
                                'mata_pelajaran_id' => $mataPelajaranId,
                                'tahun_ajaran_id' => $tahunAjaranId,
                                'semester' => $semester,
                            ],
                            [
                                'custom_capaian_tertinggi' => $customTertinggi !== '' ? $customTertinggi : null,
                                'custom_capaian_terendah' => $customTerendah !== '' ? $customTerendah : null,
                            ]
                        );
                    } else {
                        // Hapus jika kosong
                        CapaianKompetensiCustom::where([
                            'siswa_id' => $siswaId,
                            'mata_pelajaran_id' => $mataPelajaranId,
                            'tahun_ajaran_id' => $tahunAjaranId,
                            'semester' => $semester,
                        ])->delete();
                    }
                }
            });

            return redirect()->route('wali_kelas.capaian_kompetensi.index')
                ->with('success', 'Capaian kompetensi berhasil disimpan.');

        } catch (\Exception $e) {
            Log::error('Error updating capaian kompetensi: ' . $e->getMessage());
            return redirect()->back()->with('error', 'Gagal menyimpan capaian kompetensi: ' . $e->getMessage());
        }
    }

    public function waliKelasUpdatePhraseDefaults(Request $request, $mataPelajaranId)
    {
        $validated = $request->validate([
            'tahun_ajaran_id' => ['required', 'integer', 'exists:tahun_ajarans,id'],
            'semester' => ['required', 'integer', Rule::in([1, 2])],
            'tertinggi_choice' => ['required', 'string', 'max:150'],
            'tertinggi_custom_phrase' => ['nullable', 'string', 'max:150'],
            'terendah_choice' => ['required', 'string', 'max:150'],
            'terendah_custom_phrase' => ['nullable', 'string', 'max:150'],
        ]);

        $tahunAjaranId = (int) $validated['tahun_ajaran_id'];
        $semester = (int) $validated['semester'];
        $tahunAjaran = TahunAjaran::findOrFail($tahunAjaranId);
        abort_unless((int) $tahunAjaran->semester === $semester, 403);

        $mataPelajaran = MataPelajaran::findOrFail($mataPelajaranId);
        $kelas = $this->authorizeWaliSubject($mataPelajaran, $tahunAjaranId, $semester);
        $presets = $this->capaianPrefixPresets();

        $tertinggi = $this->resolveDefaultPhrasePayload($request, 'tertinggi', $presets['tertinggi']);
        $terendah = $this->resolveDefaultPhrasePayload($request, 'terendah', $presets['terendah']);

        DB::transaction(function () use ($tahunAjaranId, $semester, $kelas, $mataPelajaranId, $tertinggi, $terendah) {
            foreach ([
                CapaianKompetensiTextService::TYPE_TERTINGGI => $tertinggi,
                CapaianKompetensiTextService::TYPE_TERENDAH => $terendah,
            ] as $type => $payload) {
                CapaianPhraseDefault::updateOrCreate(
                    [
                        'tahun_ajaran_id' => $tahunAjaranId,
                        'semester' => $semester,
                        'kelas_id' => $kelas->id,
                        'mata_pelajaran_id' => $mataPelajaranId,
                        'type' => $type,
                    ],
                    [
                        'mode' => $payload['mode'],
                        'phrase' => $payload['phrase'],
                    ]
                );
            }
        });

        $this->clearCapaianPdfCacheForStudents(
            $this->studentsForWaliClass((int) $kelas->id, $tahunAjaranId, $semester)->pluck('id')->all(),
            $tahunAjaranId
        );

        return redirect()
            ->route('wali_kelas.capaian_kompetensi.edit', $mataPelajaranId)
            ->with('success', 'Pengaturan kalimat awal capaian berhasil disimpan.');
    }

    public function waliKelasUpdateStudentPhrase(Request $request, $mataPelajaranId, $siswaId)
    {
        $validator = validator($request->all(), [
            'tahun_ajaran_id' => ['required', 'integer', 'exists:tahun_ajarans,id'],
            'semester' => ['required', 'integer', Rule::in([1, 2])],
            'tertinggi_mode' => ['nullable', 'string', Rule::in(['default', 'preset', 'custom', 'full'])],
            'tertinggi_prefix_choice' => ['nullable', 'string', 'max:150'],
            'tertinggi_prefix_custom' => ['nullable', 'string', 'max:150'],
            'tertinggi_full_text' => ['nullable', 'string', 'max:1000'],
            'tertinggi_clear_full' => ['nullable', 'boolean'],
            'terendah_mode' => ['nullable', 'string', Rule::in(['default', 'preset', 'custom', 'full'])],
            'terendah_prefix_choice' => ['nullable', 'string', 'max:150'],
            'terendah_prefix_custom' => ['nullable', 'string', 'max:150'],
            'terendah_full_text' => ['nullable', 'string', 'max:1000'],
            'terendah_clear_full' => ['nullable', 'boolean'],
        ]);

        if ($validator->fails()) {
            return redirect()->back()
                ->withErrors($validator)
                ->withInput()
                ->with('open_capaian_modal', (int) $siswaId);
        }

        $validated = $validator->validated();
        $tahunAjaranId = (int) $validated['tahun_ajaran_id'];
        $semester = (int) $validated['semester'];
        $tahunAjaran = TahunAjaran::findOrFail($tahunAjaranId);
        abort_unless((int) $tahunAjaran->semester === $semester, 403);

        $mataPelajaran = MataPelajaran::findOrFail($mataPelajaranId);
        $kelas = $this->authorizeWaliSubject($mataPelajaran, $tahunAjaranId, $semester);
        $siswa = Siswa::findOrFail($siswaId);
        $this->assertAllStudentsBelongToWaliClass([(int) $siswa->id], (int) $kelas->id, $tahunAjaranId, $semester);

        $presets = $this->capaianPrefixPresets();
        $updates = [];
        $hasRequestedUpdate = false;

        foreach ([CapaianKompetensiTextService::TYPE_TERTINGGI, CapaianKompetensiTextService::TYPE_TERENDAH] as $type) {
            $mode = $validated[$type.'_mode'] ?? null;

            if (! $mode) {
                continue;
            }

            $hasRequestedUpdate = true;
            $sideUpdates = $this->resolveStudentPhraseUpdates($request, $type, $mode, $presets[$type]);

            if ($sideUpdates === null) {
                return redirect()->back()
                    ->withErrors([$type.'_mode' => 'Pilihan capaian tidak valid.'])
                    ->withInput()
                    ->with('open_capaian_modal', (int) $siswaId);
            }

            $updates = array_merge($updates, $sideUpdates);
        }

        if (! $hasRequestedUpdate) {
            return redirect()->back()
                ->withErrors(['capaian' => 'Pilih minimal satu capaian yang ingin diperbarui.'])
                ->withInput()
                ->with('open_capaian_modal', (int) $siswaId);
        }

        if (! empty($updates)) {
            DB::transaction(function () use ($siswa, $mataPelajaranId, $tahunAjaranId, $semester, $updates) {
                CapaianKompetensiCustom::updateOrCreate(
                    [
                        'siswa_id' => $siswa->id,
                        'mata_pelajaran_id' => $mataPelajaranId,
                        'tahun_ajaran_id' => $tahunAjaranId,
                        'semester' => $semester,
                    ],
                    $updates
                );
            });
        }

        PdfCacheService::clearStudentCache($siswa, $tahunAjaranId, true);

        return redirect()
            ->route('wali_kelas.capaian_kompetensi.edit', $mataPelajaranId)
            ->with('success', 'Pengaturan capaian siswa berhasil disimpan.');
    }

    public function waliKelasSaveAllCapaian(Request $request, $mataPelajaranId)
    {
        $validator = validator($request->all(), [
            'context.tahun_ajaran_id' => ['required', 'integer', 'exists:tahun_ajarans,id'],
            'context.semester' => ['required', 'integer', Rule::in([1, 2])],
            'context.kelas_id' => ['required', 'integer', 'exists:kelas,id'],
            'defaults' => ['nullable', 'array'],
            'student_changes' => ['nullable', 'array'],
            'student_changes.*.siswa_id' => ['required_with:student_changes', 'integer', 'exists:siswas,id'],
            'student_changes.*.tertinggi' => ['nullable', 'array'],
            'student_changes.*.terendah' => ['nullable', 'array'],
        ]);

        $validator->after(function ($validator) use ($request) {
            $hasChange = false;
            $presets = $this->capaianPrefixPresets();

            foreach ([CapaianKompetensiTextService::TYPE_TERTINGGI, CapaianKompetensiTextService::TYPE_TERENDAH] as $type) {
                $default = $request->input("defaults.$type");

                if (! is_array($default) || ! filter_var($default['changed'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
                    continue;
                }

                $hasChange = true;
                $mode = $default['mode'] ?? null;
                $phrase = trim((string) ($default['phrase'] ?? ''));

                if (! in_array($mode, [CapaianPhraseDefault::MODE_PRESET, CapaianPhraseDefault::MODE_CUSTOM], true)) {
                    $validator->errors()->add("defaults.$type.mode", 'Mode kalimat default tidak valid.');
                }

                if ($phrase === '') {
                    $validator->errors()->add("defaults.$type.phrase", 'Kalimat default wajib diisi.');
                }

                if (mb_strlen($phrase) > 150) {
                    $validator->errors()->add("defaults.$type.phrase", 'Kalimat default maksimal 150 karakter.');
                }

                if ($mode === CapaianPhraseDefault::MODE_PRESET && ! in_array($phrase, $presets[$type], true)) {
                    $validator->errors()->add("defaults.$type.phrase", 'Preset kalimat default tidak valid.');
                }
            }

            $seen = [];

            foreach ((array) $request->input('student_changes', []) as $index => $change) {
                if (! is_array($change)) {
                    $validator->errors()->add("student_changes.$index", 'Format perubahan siswa tidak valid.');
                    continue;
                }

                $studentId = (int) ($change['siswa_id'] ?? 0);
                $sideCount = 0;

                foreach ([CapaianKompetensiTextService::TYPE_TERTINGGI, CapaianKompetensiTextService::TYPE_TERENDAH] as $type) {
                    $side = $change[$type] ?? null;

                    if (! is_array($side)) {
                        continue;
                    }

                    $hasChange = true;
                    $sideCount++;

                    $sideKey = $studentId.':'.$type;
                    if (isset($seen[$sideKey])) {
                        $validator->errors()->add("student_changes.$index.$type", 'Perubahan capaian siswa tidak boleh dikirim ganda.');
                    }
                    $seen[$sideKey] = true;

                    $action = $side['action'] ?? null;
                    if (! in_array($action, ['custom_full', 'reset_default'], true)) {
                        $validator->errors()->add("student_changes.$index.$type.action", 'Aksi perubahan capaian tidak valid.');
                        continue;
                    }

                    if ($action === 'custom_full') {
                        $text = trim((string) ($side['text'] ?? ''));

                        if ($text === '') {
                            $validator->errors()->add("student_changes.$index.$type.text", 'Deskripsi capaian tidak boleh kosong. Gunakan tombol default bila ingin menghapus custom.');
                        }

                        if (mb_strlen($text) > 1000) {
                            $validator->errors()->add("student_changes.$index.$type.text", 'Deskripsi capaian maksimal 1000 karakter.');
                        }
                    }
                }

                if ($sideCount === 0) {
                    $validator->errors()->add("student_changes.$index", 'Pilih minimal satu perubahan capaian siswa.');
                }
            }

            if (! $hasChange) {
                $validator->errors()->add('changes', 'Tidak ada perubahan capaian untuk disimpan.');
            }
        });

        if ($validator->fails()) {
            return redirect()->back()
                ->withErrors($validator)
                ->withInput()
                ->with('error', 'Perubahan capaian belum dapat disimpan. Periksa kembali isian yang ditandai.');
        }

        $context = $validator->validated()['context'];
        $tahunAjaranId = (int) $context['tahun_ajaran_id'];
        $semester = (int) $context['semester'];
        $kelasId = (int) $context['kelas_id'];
        $tahunAjaran = TahunAjaran::findOrFail($tahunAjaranId);
        abort_unless((int) $tahunAjaran->semester === $semester, 403);

        $mataPelajaran = MataPelajaran::findOrFail($mataPelajaranId);
        $kelas = $this->authorizeWaliSubject($mataPelajaran, $tahunAjaranId, $semester);
        abort_unless((int) $kelas->id === $kelasId, 403);

        $defaultUpdates = $this->resolveSaveAllDefaultUpdates($request);
        $studentUpdates = $this->resolveSaveAllStudentUpdates($request);
        $studentIds = array_keys($studentUpdates);

        if (! empty($studentIds)) {
            $this->assertAllStudentsBelongToWaliClass($studentIds, $kelasId, $tahunAjaranId, $semester);
        }

        DB::transaction(function () use ($defaultUpdates, $studentUpdates, $tahunAjaranId, $semester, $kelasId, $mataPelajaranId) {
            foreach ($defaultUpdates as $type => $payload) {
                CapaianPhraseDefault::updateOrCreate(
                    [
                        'tahun_ajaran_id' => $tahunAjaranId,
                        'semester' => $semester,
                        'kelas_id' => $kelasId,
                        'mata_pelajaran_id' => $mataPelajaranId,
                        'type' => $type,
                    ],
                    [
                        'mode' => $payload['mode'],
                        'phrase' => $payload['phrase'],
                    ]
                );
            }

            foreach ($studentUpdates as $studentId => $updates) {
                CapaianKompetensiCustom::updateOrCreate(
                    [
                        'siswa_id' => $studentId,
                        'mata_pelajaran_id' => $mataPelajaranId,
                        'tahun_ajaran_id' => $tahunAjaranId,
                        'semester' => $semester,
                    ],
                    $updates
                );
            }
        });

        $cacheStudentIds = empty($defaultUpdates)
            ? $studentIds
            : $this->studentsForWaliClass($kelasId, $tahunAjaranId, $semester)->pluck('id')->all();

        $this->clearCapaianPdfCacheForStudents(array_unique(array_map('intval', $cacheStudentIds)), $tahunAjaranId);

        return redirect()
            ->route('wali_kelas.capaian_kompetensi.edit', $mataPelajaranId)
            ->with('success', 'Semua perubahan capaian berhasil disimpan.');
    }

    public function waliKelasBatchUpdateStudentPhrases(Request $request, $mataPelajaranId)
    {
        $validator = validator($request->all(), [
            'tahun_ajaran_id' => ['required', 'integer', 'exists:tahun_ajarans,id'],
            'semester' => ['required', 'integer', Rule::in([1, 2])],
            'changes' => ['required', 'array', 'min:1'],
            'changes.*.siswa_id' => ['required', 'integer', 'exists:siswas,id'],
            'changes.*.tertinggi' => ['nullable', 'string', 'max:1000'],
            'changes.*.terendah' => ['nullable', 'string', 'max:1000'],
            'changes.*.tertinggi_reset' => ['nullable', 'boolean'],
            'changes.*.terendah_reset' => ['nullable', 'boolean'],
        ]);

        $validator->after(function ($validator) use ($request) {
            $seen = [];

            foreach ((array) $request->input('changes', []) as $index => $change) {
                if (! is_array($change)) {
                    $validator->errors()->add("changes.$index", 'Format perubahan capaian tidak valid.');
                    continue;
                }

                $studentId = (int) ($change['siswa_id'] ?? 0);
                $sideCount = 0;

                foreach ([CapaianKompetensiTextService::TYPE_TERTINGGI, CapaianKompetensiTextService::TYPE_TERENDAH] as $type) {
                    $hasText = array_key_exists($type, $change);
                    $hasReset = filter_var($change[$type.'_reset'] ?? false, FILTER_VALIDATE_BOOLEAN);

                    if ($hasText && $hasReset) {
                        $validator->errors()->add("changes.$index.$type", 'Pilih edit teks atau gunakan default, bukan keduanya.');
                        continue;
                    }

                    if (! $hasText && ! $hasReset) {
                        continue;
                    }

                    $sideKey = $studentId.':'.$type;
                    if (isset($seen[$sideKey])) {
                        $validator->errors()->add("changes.$index.$type", 'Perubahan capaian siswa tidak boleh dikirim ganda.');
                    }

                    $seen[$sideKey] = true;
                    $sideCount++;

                    if ($hasText && trim((string) $change[$type]) === '') {
                        $validator->errors()->add("changes.$index.$type", 'Deskripsi capaian tidak boleh kosong. Gunakan tombol default bila ingin menghapus custom.');
                    }
                }

                if ($sideCount === 0) {
                    $validator->errors()->add("changes.$index", 'Pilih minimal satu perubahan capaian siswa.');
                }
            }
        });

        if ($validator->fails()) {
            return redirect()->back()
                ->withErrors($validator)
                ->withInput()
                ->with('error', 'Perubahan capaian siswa belum dapat disimpan. Periksa kembali isian yang ditandai.');
        }

        $validated = $validator->validated();
        $tahunAjaranId = (int) $validated['tahun_ajaran_id'];
        $semester = (int) $validated['semester'];
        $tahunAjaran = TahunAjaran::findOrFail($tahunAjaranId);
        abort_unless((int) $tahunAjaran->semester === $semester, 403);

        $mataPelajaran = MataPelajaran::findOrFail($mataPelajaranId);
        $kelas = $this->authorizeWaliSubject($mataPelajaran, $tahunAjaranId, $semester);
        $changes = collect($validated['changes']);
        $studentIds = $changes
            ->pluck('siswa_id')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();

        $this->assertAllStudentsBelongToWaliClass($studentIds, (int) $kelas->id, $tahunAjaranId, $semester);

        $updatesByStudent = $this->resolveBatchCapaianUpdates($changes->all());
        abort_unless(! empty($updatesByStudent), 422);

        DB::transaction(function () use ($updatesByStudent, $mataPelajaranId, $tahunAjaranId, $semester) {
            foreach ($updatesByStudent as $studentId => $updates) {
                CapaianKompetensiCustom::updateOrCreate(
                    [
                        'siswa_id' => $studentId,
                        'mata_pelajaran_id' => $mataPelajaranId,
                        'tahun_ajaran_id' => $tahunAjaranId,
                        'semester' => $semester,
                    ],
                    $updates
                );
            }
        });

        $this->clearCapaianPdfCacheForStudents(array_keys($updatesByStudent), $tahunAjaranId);

        return redirect()
            ->route('wali_kelas.capaian_kompetensi.edit', $mataPelajaranId)
            ->with('success', 'Perubahan capaian siswa berhasil disimpan.');
    }

    private function capaianPrefixPresets(): array
    {
        return [
            CapaianKompetensiTextService::TYPE_TERTINGGI => [
                'menunjukkan penguasaan dalam',
                'menunjukkan penguasaan yang sangat baik dalam',
                'menunjukkan pemahaman dalam',
                'menunjukkan pemahaman yang sangat baik dalam',
            ],
            CapaianKompetensiTextService::TYPE_TERENDAH => [
                'mulai berkembang dalam',
                'cukup berkembang dalam',
                'berkembang dalam',
                'berkembang sangat baik dalam',
            ],
        ];
    }

    private function buildStudentCapaianRows($siswaList, MataPelajaran $mataPelajaran, $existingCapaian, int $tahunAjaranId, int $semester): array
    {
        return $siswaList->mapWithKeys(function (Siswa $siswa) use ($mataPelajaran, $existingCapaian, $tahunAjaranId, $semester) {
            $existingRow = $existingCapaian->get($siswa->id);
            $resolved = self::generateCapaianTertinggiTerendah($siswa->id, $mataPelajaran->id, $tahunAjaranId);
            $lmTexts = $this->resolveCapaianLingkupMateriTitles($siswa->id, $mataPelajaran->id, $tahunAjaranId, $semester);
            $nilai = $siswa->nilais()
                ->where('mata_pelajaran_id', $mataPelajaran->id)
                ->where('tahun_ajaran_id', $tahunAjaranId)
                ->whereHas('mataPelajaran', function ($query) use ($tahunAjaranId, $semester) {
                    $query->where('tahun_ajaran_id', $tahunAjaranId)
                        ->where('semester', $semester);
                })
                ->whereNotNull('nilai_akhir_rapor')
                ->first();

            return [
                $siswa->id => [
                    'resolved' => $resolved,
                    'nilai_akhir' => $nilai?->nilai_akhir_rapor,
                    'status' => [
                        CapaianKompetensiTextService::TYPE_TERTINGGI => $this->capaianSideStatus($existingRow, CapaianKompetensiTextService::TYPE_TERTINGGI),
                        CapaianKompetensiTextService::TYPE_TERENDAH => $this->capaianSideStatus($existingRow, CapaianKompetensiTextService::TYPE_TERENDAH),
                    ],
                    'lm' => $lmTexts,
                    'uses_default' => [
                        CapaianKompetensiTextService::TYPE_TERTINGGI => $this->capaianSideUsesDefault($existingRow, CapaianKompetensiTextService::TYPE_TERTINGGI),
                        CapaianKompetensiTextService::TYPE_TERENDAH => $this->capaianSideUsesDefault($existingRow, CapaianKompetensiTextService::TYPE_TERENDAH),
                    ],
                ],
            ];
        })->all();
    }

    private function resolveCapaianLingkupMateriTitles(int $studentId, int $subjectId, int $yearId, int $semester): array
    {
        $lmData = DB::table('nilais')
            ->join('lingkup_materis', 'nilais.lingkup_materi_id', '=', 'lingkup_materis.id')
            ->join('mata_pelajarans', 'nilais.mata_pelajaran_id', '=', 'mata_pelajarans.id')
            ->where('nilais.siswa_id', $studentId)
            ->where('nilais.mata_pelajaran_id', $subjectId)
            ->where('nilais.tahun_ajaran_id', $yearId)
            ->where('mata_pelajarans.tahun_ajaran_id', $yearId)
            ->where('mata_pelajarans.semester', $semester)
            ->whereNull('nilais.deleted_at')
            ->whereNull('lingkup_materis.deleted_at')
            ->whereNull('mata_pelajarans.deleted_at')
            ->whereNotNull('nilais.nilai_lm')
            ->groupBy('lingkup_materis.id', 'lingkup_materis.judul_lingkup_materi')
            ->select(
                'lingkup_materis.id',
                'lingkup_materis.judul_lingkup_materi',
                DB::raw('MAX(nilais.nilai_lm) as nilai_lm')
            )
            ->get();

        return [
            CapaianKompetensiTextService::TYPE_TERTINGGI => (string) ($lmData->sortByDesc('nilai_lm')->first()?->judul_lingkup_materi ?? ''),
            CapaianKompetensiTextService::TYPE_TERENDAH => (string) ($lmData->sortBy('nilai_lm')->first()?->judul_lingkup_materi ?? ''),
        ];
    }

    private function capaianSideStatus(?CapaianKompetensiCustom $custom, string $type): array
    {
        $fullField = $this->fullCustomField($type);
        $modeField = $this->prefixModeField($type);
        $textField = $this->prefixTextField($type);

        if ($custom && filled($custom->{$fullField})) {
            return [
                'label' => 'Custom',
                'class' => 'bg-amber-50 text-amber-700 ring-amber-200',
            ];
        }

        if ($custom && $custom->{$modeField} === 'preset' && filled($custom->{$textField})) {
            return [
                'label' => 'Preset khusus',
                'class' => 'bg-green-50 text-green-700 ring-green-200',
            ];
        }

        if ($custom && $custom->{$modeField} === 'custom' && filled($custom->{$textField})) {
            return [
                'label' => 'Kalimat awal khusus',
                'class' => 'bg-green-50 text-green-700 ring-green-200',
            ];
        }

        return [
            'label' => 'Default',
            'class' => 'bg-gray-100 text-gray-700 ring-gray-200',
        ];
    }

    private function capaianSideUsesDefault(?CapaianKompetensiCustom $custom, string $type): bool
    {
        $fullField = $this->fullCustomField($type);
        $modeField = $this->prefixModeField($type);
        $textField = $this->prefixTextField($type);

        if ($custom && filled($custom->{$fullField})) {
            return false;
        }

        if ($custom && in_array($custom->{$modeField}, ['preset', 'custom'], true) && filled($custom->{$textField})) {
            return false;
        }

        return true;
    }

    private function resolveDefaultPhrasePayload(Request $request, string $type, array $presets): array
    {
        $choice = trim((string) $request->input($type.'_choice'));

        if ($choice === '__custom__') {
            $phrase = trim((string) $request->input($type.'_custom_phrase'));

            if ($phrase === '') {
                throw ValidationException::withMessages([
                    $type.'_custom_phrase' => 'Kalimat custom wajib diisi.',
                ]);
            }

            return [
                'mode' => 'custom',
                'phrase' => $phrase,
            ];
        }

        if (! in_array($choice, $presets, true)) {
            throw ValidationException::withMessages([
                $type.'_choice' => 'Pilihan kalimat awal tidak valid.',
            ]);
        }

        return [
            'mode' => 'preset',
            'phrase' => $choice,
        ];
    }

    private function resolveStudentPhraseUpdates(Request $request, string $type, string $mode, array $presets): ?array
    {
        $updates = [];
        $fullField = $this->fullCustomField($type);
        $modeField = $this->prefixModeField($type);
        $textField = $this->prefixTextField($type);
        $clearFull = $request->boolean($type.'_clear_full');

        if ($clearFull) {
            $updates[$fullField] = null;
        }

        if ($mode === 'default') {
            $updates[$modeField] = 'default';
            $updates[$textField] = null;

            return $updates;
        }

        if ($mode === 'preset') {
            $choice = trim((string) $request->input($type.'_prefix_choice'));

            if (! in_array($choice, $presets, true)) {
                return null;
            }

            $updates[$modeField] = 'preset';
            $updates[$textField] = $choice;

            return $updates;
        }

        if ($mode === 'custom') {
            $phrase = trim((string) $request->input($type.'_prefix_custom'));

            if ($phrase === '') {
                return null;
            }

            $updates[$modeField] = 'custom';
            $updates[$textField] = $phrase;

            return $updates;
        }

        if ($mode === 'full') {
            $fullText = trim((string) $request->input($type.'_full_text'));

            if ($fullText === '') {
                return null;
            }

            $updates[$fullField] = $fullText;

            return $updates;
        }

        return null;
    }

    private function resolveSaveAllDefaultUpdates(Request $request): array
    {
        $updates = [];

        foreach ([CapaianKompetensiTextService::TYPE_TERTINGGI, CapaianKompetensiTextService::TYPE_TERENDAH] as $type) {
            $default = $request->input("defaults.$type");

            if (! is_array($default) || ! filter_var($default['changed'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
                continue;
            }

            $updates[$type] = [
                'mode' => (string) $default['mode'],
                'phrase' => trim((string) $default['phrase']),
            ];
        }

        return $updates;
    }

    private function resolveSaveAllStudentUpdates(Request $request): array
    {
        $updatesByStudent = [];

        foreach ((array) $request->input('student_changes', []) as $change) {
            if (! is_array($change)) {
                continue;
            }

            $studentId = (int) ($change['siswa_id'] ?? 0);

            foreach ([CapaianKompetensiTextService::TYPE_TERTINGGI, CapaianKompetensiTextService::TYPE_TERENDAH] as $type) {
                $side = $change[$type] ?? null;

                if (! is_array($side)) {
                    continue;
                }

                $fullField = $this->fullCustomField($type);
                $modeField = $this->prefixModeField($type);
                $textField = $this->prefixTextField($type);
                $action = $side['action'] ?? null;

                if ($action === 'custom_full') {
                    $updatesByStudent[$studentId][$fullField] = trim((string) ($side['text'] ?? ''));
                }

                if ($action === 'reset_default') {
                    $updatesByStudent[$studentId][$fullField] = null;
                    $updatesByStudent[$studentId][$modeField] = 'default';
                    $updatesByStudent[$studentId][$textField] = null;
                }
            }
        }

        return $updatesByStudent;
    }

    private function resolveBatchCapaianUpdates(array $changes): array
    {
        $updatesByStudent = [];

        foreach ($changes as $change) {
            $studentId = (int) $change['siswa_id'];

            foreach ([CapaianKompetensiTextService::TYPE_TERTINGGI, CapaianKompetensiTextService::TYPE_TERENDAH] as $type) {
                $fullField = $this->fullCustomField($type);
                $modeField = $this->prefixModeField($type);
                $textField = $this->prefixTextField($type);

                if (array_key_exists($type, $change)) {
                    $updatesByStudent[$studentId][$fullField] = trim((string) $change[$type]);
                }

                if (filter_var($change[$type.'_reset'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
                    $updatesByStudent[$studentId][$fullField] = null;
                    $updatesByStudent[$studentId][$modeField] = 'default';
                    $updatesByStudent[$studentId][$textField] = null;
                }
            }
        }

        return $updatesByStudent;
    }

    private function fullCustomField(string $type): string
    {
        return $type === CapaianKompetensiTextService::TYPE_TERTINGGI
            ? 'custom_capaian_tertinggi'
            : 'custom_capaian_terendah';
    }

    private function prefixModeField(string $type): string
    {
        return $type === CapaianKompetensiTextService::TYPE_TERTINGGI
            ? 'tertinggi_prefix_mode'
            : 'terendah_prefix_mode';
    }

    private function prefixTextField(string $type): string
    {
        return $type === CapaianKompetensiTextService::TYPE_TERTINGGI
            ? 'tertinggi_prefix_text'
            : 'terendah_prefix_text';
    }

    private function clearCapaianPdfCacheForStudents(array $studentIds, int $tahunAjaranId): void
    {
        Siswa::whereIn('id', $studentIds)
            ->get()
            ->each(fn (Siswa $siswa) => PdfCacheService::clearStudentCache($siswa, $tahunAjaranId, true));
    }

    private function getCurrentSemester(int $tahunAjaranId): int
    {
        return (int) (TahunAjaran::find($tahunAjaranId)?->semester ?? 1);
    }

    private function authorizeWaliSubject(MataPelajaran $mataPelajaran, int $tahunAjaranId, int $semester): Kelas
    {
        $guru = auth()->guard('guru')->user();
        abort_unless($guru && session('selected_role') === 'wali_kelas', 403);

        $kelas = $this->getWaliKelasKelas($guru, $tahunAjaranId);

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

    public static function generateCapaianTertinggiTerendah(
        $siswaId,
        $mataPelajaranId,
        $tahunAjaranId = null
    ): array {
        return app(CapaianKompetensiTextService::class)->resolvePair(
            (int) $siswaId,
            (int) $mataPelajaranId,
            $tahunAjaranId ? (int) $tahunAjaranId : null
        );
    }

    public static function preloadCapaianData(
        int $siswaId,
        array $mataPelajaranIds,
        int $tahunAjaranId
    ): array {
        $mataPelajaranIds = array_values(array_unique(array_filter($mataPelajaranIds)));

        if (empty($mataPelajaranIds)) {
            return [];
        }

        return app(CapaianKompetensiTextService::class)->preload($siswaId, $mataPelajaranIds, $tahunAjaranId);
    }

    public static function generateAutoCapaianTertinggiTerendah(
        $siswaId,
        $mataPelajaranId,
        $tahunAjaranId = null
    ): array {
        return app(CapaianKompetensiTextService::class)->resolvePair(
            (int) $siswaId,
            (int) $mataPelajaranId,
            $tahunAjaranId ? (int) $tahunAjaranId : null,
            includeFullCustom: false
        );
    }

    /**
     * Generate capaian kompetensi untuk rapor
     */
    public static function generateCapaianForRapor($siswaId, $mataPelajaranId, $tahunAjaranId = null)
    {
        $tahunAjaranId = $tahunAjaranId ?: session('tahun_ajaran_id');
        $tahunAjaran = TahunAjaran::find($tahunAjaranId);
        $semester = $tahunAjaran ? $tahunAjaran->semester : 1;

        // Cek apakah ada custom capaian
        $customCapaian = CapaianKompetensiCustom::where([
            'siswa_id' => $siswaId,
            'mata_pelajaran_id' => $mataPelajaranId,
            'tahun_ajaran_id' => $tahunAjaranId,
            'semester' => $semester,
        ])->first();

        if ($customCapaian) {
            return $customCapaian->generateFinalCapaian();
        }

        // Jika tidak ada custom, generate otomatis
        $siswa = Siswa::find($siswaId);
        $mataPelajaran = MataPelajaran::find($mataPelajaranId);

        if (
            !$siswa
            || !$mataPelajaran
            || (int) $mataPelajaran->tahun_ajaran_id !== (int) $tahunAjaranId
            || (int) $mataPelajaran->semester !== (int) $semester
        ) {
            return 'Data tidak lengkap.';
        }

        // Ambil nilai siswa
        $nilai = $siswa->nilais()
            ->where('mata_pelajaran_id', $mataPelajaranId)
            ->where('tahun_ajaran_id', $tahunAjaranId)
            ->whereNotNull('nilai_akhir_rapor')
            ->first();

        if (!$nilai || is_null($nilai->nilai_akhir_rapor)) {
            return 'Nilai belum tersedia.';
        }

        // Cari template
        $template = CapaianKompetensiTemplate::getTemplateByNilai(
            $mataPelajaran->nama_pelajaran,
            $nilai->nilai_akhir_rapor,
            $tahunAjaranId
        );

        if ($template) {
            return $template->generateCapaianText($siswa->nama);
        }

        // Fallback ke template default
        return self::generateDefaultCapaian($siswa->nama, $mataPelajaran->nama_pelajaran, $nilai->nilai_akhir_rapor);
    }

    /**
     * Generate default capaian
     */
    private static function generateDefaultCapaian($namaSiswa, $namaMapel, $nilai)
    {
        if ($nilai >= 90) {
            return "{$namaSiswa} menunjukkan penguasaan yang sangat baik dalam mata pelajaran {$namaMapel}.";
        } elseif ($nilai >= 80) {
            return "{$namaSiswa} menunjukkan penguasaan yang baik dalam mata pelajaran {$namaMapel}.";
        } elseif ($nilai >= 70) {
            return "{$namaSiswa} menunjukkan penguasaan yang cukup dalam mata pelajaran {$namaMapel}.";
        } else {
            return "{$namaSiswa} perlu meningkatkan penguasaan dalam mata pelajaran {$namaMapel}.";
        }
    }
}

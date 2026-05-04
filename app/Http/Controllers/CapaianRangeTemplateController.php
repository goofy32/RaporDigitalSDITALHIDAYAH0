<?php

namespace App\Http\Controllers;

use App\Models\CapaianKompetensiRangeTemplate;
use App\Models\TahunAjaran;
use App\Traits\RequiresTahunAjaran;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CapaianRangeTemplateController extends Controller
{
    use RequiresTahunAjaran;

    /**
     * Display listing of range templates
     */
    public function index()
    {
        $tahunAjaranId = session('tahun_ajaran_id');
        
        $templates = CapaianKompetensiRangeTemplate::where('tahun_ajaran_id', $tahunAjaranId)
            ->ordered()
            ->get();
        
        $tahunAjaran = TahunAjaran::find($tahunAjaranId);
        
        return view('wali_kelas.capaian_kompetensi.range_templates', compact('templates', 'tahunAjaran'));
    }

    /**
     * Update range templates
     */
    public function update(Request $request)
    {
        $request->validate([
            'templates' => 'required|array',
            'templates.*.id' => 'required|exists:capaian_kompetensi_range_templates,id',
            'templates.*.nama_range' => 'required|string|max:255',
            'templates.*.nilai_min' => 'required|integer|min:0|max:100',
            'templates.*.nilai_max' => 'required|integer|min:0|max:100',
            'templates.*.template_text' => 'required|string',
            'templates.*.color_class' => 'nullable|string|max:255',
            'templates.*.is_active' => 'boolean',
        ]);

        DB::beginTransaction();
        
        try {
            foreach ($request->templates as $templateData) {
                // Validasi range tidak overlap dengan template lain
                $existingTemplate = CapaianKompetensiRangeTemplate::where('id', '!=', $templateData['id'])
                    ->where('tahun_ajaran_id', session('tahun_ajaran_id'))
                    ->where('is_active', true)
                    ->where(function($query) use ($templateData) {
                        $query->whereBetween('nilai_min', [$templateData['nilai_min'], $templateData['nilai_max']])
                              ->orWhereBetween('nilai_max', [$templateData['nilai_min'], $templateData['nilai_max']])
                              ->orWhere(function($q) use ($templateData) {
                                  $q->where('nilai_min', '<=', $templateData['nilai_min'])
                                    ->where('nilai_max', '>=', $templateData['nilai_max']);
                              });
                    })
                    ->exists();

                if ($existingTemplate) {
                    throw new \Exception("Range nilai {$templateData['nilai_min']}-{$templateData['nilai_max']} overlap dengan template lain untuk '{$templateData['nama_range']}'");
                }

                // Validasi min tidak lebih besar dari max
                if ($templateData['nilai_min'] > $templateData['nilai_max']) {
                    throw new \Exception("Nilai minimum tidak boleh lebih besar dari nilai maksimum untuk '{$templateData['nama_range']}'");
                }

                // Update template
                $template = CapaianKompetensiRangeTemplate::findOrFail($templateData['id']);
                $template->update([
                    'nama_range' => $templateData['nama_range'],
                    'nilai_min' => $templateData['nilai_min'],
                    'nilai_max' => $templateData['nilai_max'],
                    'template_text' => $templateData['template_text'],
                    'color_class' => $templateData['color_class'] ?? $template->color_class,
                    'is_active' => $templateData['is_active'] ?? true,
                ]);
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Template range capaian kompetensi berhasil diperbarui'
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            
            Log::error('Error updating range templates: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 422);
        }
    }

    /**
     * Create new range template
     */
    public function store(Request $request)
    {
        $tahunAjaranId = $this->getValidTahunAjaranId();

        if (!$tahunAjaranId) {
            return $this->failTahunAjaranNotSet($request, true);
        }

        $request->validate([
            'nama_range' => 'required|string|max:255',
            'nilai_min' => 'required|integer|min:0|max:100',
            'nilai_max' => 'required|integer|min:0|max:100',
            'template_text' => 'required|string',
            'color_class' => 'nullable|string|max:255',
        ]);
        // Validasi range tidak overlap
        $existingTemplate = CapaianKompetensiRangeTemplate::where('tahun_ajaran_id', $tahunAjaranId)
            ->where('is_active', true)
            ->where(function($query) use ($request) {
                $query->whereBetween('nilai_min', [$request->nilai_min, $request->nilai_max])
                      ->orWhereBetween('nilai_max', [$request->nilai_min, $request->nilai_max])
                      ->orWhere(function($q) use ($request) {
                          $q->where('nilai_min', '<=', $request->nilai_min)
                            ->where('nilai_max', '>=', $request->nilai_max);
                      });
            })
            ->exists();

        if ($existingTemplate) {
            return response()->json([
                'success' => false,
                'message' => "Range nilai {$request->nilai_min}-{$request->nilai_max} overlap dengan template yang sudah ada"
            ], 422);
        }

        // Validasi min tidak lebih besar dari max
        if ($request->nilai_min > $request->nilai_max) {
            return response()->json([
                'success' => false,
                'message' => 'Nilai minimum tidak boleh lebih besar dari nilai maksimum'
            ], 422);
        }

        // Get next urutan
        $nextUrutan = CapaianKompetensiRangeTemplate::where('tahun_ajaran_id', $tahunAjaranId)->max('urutan') + 1;

        try {
            $template = CapaianKompetensiRangeTemplate::create([
                'nama_range' => $request->nama_range,
                'nilai_min' => $request->nilai_min,
                'nilai_max' => $request->nilai_max,
                'template_text' => $request->template_text,
                'color_class' => $request->color_class,
                'urutan' => $nextUrutan,
                'tahun_ajaran_id' => $tahunAjaranId,
                'is_active' => true,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Template range baru berhasil ditambahkan',
                'template' => $template
            ]);

        } catch (\Exception $e) {
            Log::error('Error creating range template: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Terjadi kesalahan saat menambah template range'
            ], 500);
        }
    }

    /**
     * Delete range template
     */
    public function destroy($id)
    {
        try {
            $template = CapaianKompetensiRangeTemplate::findOrFail($id);
            
            // Check if template belongs to current tahun ajaran
            if ($template->tahun_ajaran_id != session('tahun_ajaran_id')) {
                return response()->json([
                    'success' => false,
                    'message' => 'Tidak dapat menghapus template dari tahun ajaran lain'
                ], 403);
            }

            $template->delete();

            return response()->json([
                'success' => true,
                'message' => 'Template range berhasil dihapus'
            ]);

        } catch (\Exception $e) {
            Log::error('Error deleting range template: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Terjadi kesalahan saat menghapus template range'
            ], 500);
        }
    }

    /**
     * Reset to default templates
     */
    public function resetToDefault()
    {
        $tahunAjaranId = session('tahun_ajaran_id');
        
        try {
            DB::beginTransaction();
            
            // Delete existing templates
            CapaianKompetensiRangeTemplate::where('tahun_ajaran_id', $tahunAjaranId)->delete();
            
            // Create default templates
            CapaianKompetensiRangeTemplate::createDefaultTemplates($tahunAjaranId);
            
            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Template range berhasil direset ke pengaturan default'
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error resetting range templates: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Terjadi kesalahan saat mereset template range'
            ], 500);
        }
    }

    /**
     * Preview generated text for a template
     */
    public function preview(Request $request)
    {
        $request->validate([
            'template_text' => 'required|string',
            'sample_name' => 'string',
            'sample_subject' => 'string',
        ]);

        $sampleName = $request->sample_name ?: 'Contoh Siswa';
        $sampleSubject = $request->sample_subject ?: 'Matematika';

        $previewText = str_replace(
            ['{nama_siswa}', '{mata_pelajaran}'],
            [$sampleName, $sampleSubject],
            $request->template_text
        );

        return response()->json([
            'success' => true,
            'preview_text' => $previewText
        ]);
    }
}

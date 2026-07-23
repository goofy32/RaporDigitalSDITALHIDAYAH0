<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\Log;
use App\Models\BobotNilai;
use Illuminate\Http\Request;

class BobotNilaiController extends Controller
{
    public function index()
    {
        // Alihkan ke dashboard dengan pesan
        return redirect()->route('admin.dashboard')
            ->with('info', 'Pengaturan Bobot Nilai tersedia melalui menu pengaturan di navbar');
    }
    
    public function subjectView()
    {
        return view('admin.subject.bobot-nilai');
    }
    
    public function update(Request $request)
    {
        $validated = $request->validate([
            'bobot_tp' => 'required|integer|min:1|max:100',
            'bobot_lm' => 'required|integer|min:1|max:100',
            'bobot_as' => 'required|integer|min:1|max:100',
        ]);

        try {
            $tahunAjaranId = session('tahun_ajaran_id');
            $bobotNilai = BobotNilai::query()
                ->where('tahun_ajaran_id', $tahunAjaranId)
                ->orderBy('id')
                ->first();

            if (! $bobotNilai) {
                $bobotNilai = new BobotNilai([
                    'tahun_ajaran_id' => $tahunAjaranId,
                    'bobot_tp' => BobotNilai::DEFAULT_BOBOT_TP,
                    'bobot_lm' => BobotNilai::DEFAULT_BOBOT_LM,
                    'bobot_as' => BobotNilai::DEFAULT_BOBOT_AS,
                ]);
            }

            // Ambil nilai bobot lama untuk logging
            $oldValues = [
                'bobot_tp' => $bobotNilai->bobot_tp,
                'bobot_lm' => $bobotNilai->bobot_lm,
                'bobot_as' => $bobotNilai->bobot_as
            ];

            // Update bobot nilai
            $bobotNilai->fill($validated);
            $bobotNilai->save();

            // Log perubahan untuk audit
            $user = auth()->user();
            Log::info('Bobot nilai diperbarui', [
                'user_id' => $user->id,
                'user_name' => $user->name,
                'old_values' => $oldValues,
                'new_values' => $validated,
                'timestamp' => now()->toDateTimeString()
            ]);

            // Tambahkan ke AuditLog jika model tersedia
            if (class_exists('App\Models\AuditLog')) {
                \App\Models\AuditLog::create([
                    'user_type' => get_class($user),
                    'user_id' => $user->id,
                    'action' => 'update',
                    'model_type' => 'App\Models\BobotNilai',
                    'model_id' => $bobotNilai->id,
                    'description' => 'Perubahan bobot nilai',
                    'old_values' => $oldValues,
                    'new_values' => $validated,
                    'ip_address' => request()->ip(),
                    'user_agent' => request()->userAgent()
                ]);
            }

            return response()->json([
                'success' => true,
                'message' => 'Bobot nilai berhasil diperbarui!'
            ]);
        } catch (\Throwable $e) {
            Log::error('Gagal memperbarui bobot nilai', [
                'tahun_ajaran_id' => session('tahun_ajaran_id'),
                'error' => $e->getMessage(),
                'exception' => get_class($e),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Bobot nilai gagal diperbarui. Silakan coba lagi.',
            ], 500);
        }
    }
    
    public function getBobot()
    {
        try {
            $bobot = BobotNilai::resolveForRead();

            return response()->json([
                'bobot_tp' => $bobot->bobot_tp,
                'bobot_lm' => $bobot->bobot_lm,
                'bobot_as' => $bobot->bobot_as
            ]);
        } catch (\Throwable $e) {
            Log::error('Gagal memuat bobot nilai', [
                'tahun_ajaran_id' => session('tahun_ajaran_id'),
                'error' => $e->getMessage(),
                'exception' => get_class($e),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Bobot nilai tidak dapat dimuat. Silakan coba lagi.',
            ], 500);
        }
    }
}

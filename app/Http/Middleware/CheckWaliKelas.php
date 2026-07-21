<?php

namespace App\Http\Middleware;

use Closure;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class CheckWaliKelas
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next)
    {
        $guru = auth()->guard('guru')->user();
        $tahunAjaranId = session('tahun_ajaran_id');
        
        if (!$guru) {
            return redirect()->route('login')
                ->with('error', 'Silakan login terlebih dahulu');
        }
        
        Log::debug('Check Wali Kelas middleware', [
            'guru_id' => $guru->id,
            'tahun_ajaran_id' => $tahunAjaranId
        ]);
        
        // Periksa apakah guru ini adalah wali kelas untuk tahun ajaran terpilih
        $isWaliKelas = DB::table('guru_kelas')
            ->join('kelas', 'guru_kelas.kelas_id', '=', 'kelas.id')
            ->where('guru_kelas.guru_id', $guru->id)
            ->where('guru_kelas.is_wali_kelas', true)
            ->where('guru_kelas.role', 'wali_kelas')
            ->where('kelas.tahun_ajaran_id', $tahunAjaranId)
            ->exists();
            
        Log::debug('Wali kelas check result', ['is_wali_kelas' => $isWaliKelas]);
        
        if (!$isWaliKelas) {
            $relationCount = DB::table('guru_kelas')
                ->join('kelas', 'guru_kelas.kelas_id', '=', 'kelas.id')
                ->where('guru_kelas.guru_id', $guru->id)
                ->count();
                
            Log::warning('Guru is not assigned as wali kelas for selected academic year.', [
                'guru_id' => $guru->id,
                'tahun_ajaran_id' => $tahunAjaranId,
                'relation_count' => $relationCount,
            ]);
            
            return redirect()->route('pengajar.dashboard')
                ->with('error', 'Anda tidak terdaftar sebagai wali kelas untuk tahun ajaran yang aktif.');
        }

        return $next($request);
    }
}

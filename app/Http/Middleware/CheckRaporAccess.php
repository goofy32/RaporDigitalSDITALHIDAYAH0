<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use App\Models\Siswa;
use App\Models\TahunAjaran;

class CheckRaporAccess
{
    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure  $next
     * @return mixed
     */
    public function handle(Request $request, Closure $next)
    {
        $siswaParam = $request->route('siswa');
        $guru = auth()->guard('guru')->user();
        
        // Ambil siswa dari parameter route
        if (is_numeric($siswaParam)) {
            $siswa = Siswa::find($siswaParam);
        } else {
            $siswa = $siswaParam;
        }
        
        $tahunAjaranId = $this->resolveTahunAjaranId($request);

        if (
            !$guru ||
            session('selected_role') !== 'wali_kelas' ||
            !$siswa instanceof Siswa ||
            !$tahunAjaranId ||
            !$siswa->isInKelasWali($guru->id, $tahunAjaranId)
        ) {
            abort(403);
        }
        
        return $next($request);
    }

    private function resolveTahunAjaranId(Request $request): ?int
    {
        $tahunAjaranId = $request->input('tahun_ajaran_id', session('tahun_ajaran_id'));

        if (!$tahunAjaranId || !TahunAjaran::whereKey($tahunAjaranId)->exists()) {
            return null;
        }

        return (int) $tahunAjaranId;
    }
}

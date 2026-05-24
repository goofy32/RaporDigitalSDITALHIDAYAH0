<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use App\Models\TahunAjaran;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

class TahunAjaranMiddleware
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
        // Ambil parameter untuk menampilkan tahun ajaran terarsipkan
        $tampilkanArsip = $request->has('showArchived');
        $allTahunAjarans = $this->getCachedTahunAjarans(true);
        
        // Cek jika ada tahun ajaran yang dipilih di session
        $tahunAjaranId = session('tahun_ajaran_id');
        
        // Jika tidak ada di session, gunakan tahun ajaran aktif
        if (!$tahunAjaranId || !$this->isValidTahunAjaranId($tahunAjaranId, $allTahunAjarans)) {
            $activeTahunAjaran = $this->getCachedActiveTahunAjaran();
            
            if ($activeTahunAjaran) {
                session(['tahun_ajaran_id' => $activeTahunAjaran->id]);
                session(['no_tahun_ajaran' => false]);
                // FIX: Sync semester session dengan tahun ajaran aktif
                session(['selected_semester' => $activeTahunAjaran->semester]);
                $tahunAjaranId = $activeTahunAjaran->id;
                if (config('app.debug')) {
                    \Log::info("Auto-sync tahun ajaran dan semester: Set ke tahun ajaran aktif (ID: {$tahunAjaranId}, Semester: {$activeTahunAjaran->semester})");
                }
            } else {
                // Gunakan tahun ajaran terbaru jika tidak ada yang aktif
                $latestTahunAjaran = $this->getCachedLatestTahunAjaran();
                if ($latestTahunAjaran) {
                    session(['tahun_ajaran_id' => $latestTahunAjaran->id]);
                    session(['no_tahun_ajaran' => false]);
                    session(['selected_semester' => $latestTahunAjaran->semester]);
                    $tahunAjaranId = $latestTahunAjaran->id;
                    if (config('app.debug')) {
                        \Log::info("Auto-sync tahun ajaran dan semester: Set ke tahun ajaran terbaru (ID: {$tahunAjaranId}, Semester: {$latestTahunAjaran->semester})");
                    }
                } else {
                    session(['no_tahun_ajaran' => true]);
                }
            }
        } else {
            session(['no_tahun_ajaran' => false]);
            // FIX: Jika tahun ajaran ID valid, pastikan semester juga sync
            $tahunAjaran = $allTahunAjarans->firstWhere('id', (int) $tahunAjaranId);
            if ($tahunAjaran && session('selected_semester') != $tahunAjaran->semester) {
                session(['selected_semester' => $tahunAjaran->semester]);
                if (config('app.debug')) {
                    \Log::info("Sync semester session dengan tahun ajaran", [
                        'tahun_ajaran_id' => $tahunAjaranId,
                        'old_semester' => session('selected_semester'),
                        'new_semester' => $tahunAjaran->semester
                    ]);
                }
            }
        }
        
        // Share tahun ajaran ke semua view
        $tahunAjaran = null;
        if ($tahunAjaranId) {
            $tahunAjaran = $allTahunAjarans->firstWhere('id', (int) $tahunAjaranId);
            
            if ($tahunAjaran) {
                view()->share('activeTahunAjaran', $tahunAjaran);
                
                // Tambahkan tahun ajaran ke request untuk digunakan di controller
                $request->merge(['tahun_ajaran_id' => $tahunAjaranId]);
                
                // Tambahkan ke request attributes agar bisa diakses dengan $request->attributes->get('tahun_ajaran_id')
                $request->attributes->add(['tahun_ajaran_id' => $tahunAjaranId]);
                
                // Tambahkan flag untuk mengetahui apakah tahun ajaran yang dipilih telah diarsipkan
                $request->attributes->add(['tahun_ajaran_is_archived' => $tahunAjaran->trashed()]);
                view()->share('tahunAjaranIsArchived', $tahunAjaran->trashed());
            } else {
                // Tahun ajaran tidak ditemukan, mungkin sudah dihapus
                // Reset session dan cari tahun ajaran lain
                session()->forget('tahun_ajaran_id');
                $newActiveTahunAjaran = $this->getCachedActiveTahunAjaran();
                
                if ($newActiveTahunAjaran) {
                    session(['tahun_ajaran_id' => $newActiveTahunAjaran->id]);
                    session(['no_tahun_ajaran' => false]);
                    session(['selected_semester' => $newActiveTahunAjaran->semester]); // Add semester sync here too
                    view()->share('activeTahunAjaran', $newActiveTahunAjaran);
                    $request->merge(['tahun_ajaran_id' => $newActiveTahunAjaran->id]);
                    $request->attributes->add(['tahun_ajaran_id' => $newActiveTahunAjaran->id]);
                    $request->attributes->add(['tahun_ajaran_is_archived' => $newActiveTahunAjaran->trashed()]);
                    view()->share('tahunAjaranIsArchived', $newActiveTahunAjaran->trashed());
                } else {
                    session(['no_tahun_ajaran' => true]);
                }
            }
        }
        
        // Ambil daftar semua tahun ajaran (untuk dropdown selector)
        $tahunAjarans = $this->getCachedTahunAjarans($tampilkanArsip);
        
        view()->share('tahunAjarans', $tahunAjarans);
        view()->share('tampilkanArsip', $tampilkanArsip);
        
        // Pastikan field tahun_ajaran_id otomatis terisi saat form submission
        if ($request->isMethod('post') || $request->isMethod('put')) {
            if (!$request->has('tahun_ajaran_id') && $tahunAjaranId) {
                $request->merge(['tahun_ajaran_id' => $tahunAjaranId]);
            }
        }
        
        // Tampilkan peringatan jika menggunakan tahun ajaran yang diarsipkan
        if ($tahunAjaran && $tahunAjaran->trashed()) {
            // Gunakan session flash untuk menampilkan peringatan
            session()->flash('warning', 'Anda sedang melihat data untuk tahun ajaran yang diarsipkan. Beberapa fitur mungkin terbatas.');
        }
        
        return $next($request);
    }
    
    /**
     * Cek apakah ID tahun ajaran valid (ada di database)
     * 
     * @param int $id
     * @return bool
     */
    private function isValidTahunAjaranId($id, ?Collection $tahunAjarans = null)
    {
        if (!$id) {
            return false;
        }
        
        $tahunAjarans = $tahunAjarans ?: $this->getCachedTahunAjarans(true);

        return $tahunAjarans->contains('id', (int) $id);
    }

    private function getCachedActiveTahunAjaran(): ?TahunAjaran
    {
        return Cache::remember(
            'active_tahun_ajaran',
            now()->addMinutes(10),
            fn () => TahunAjaran::where('is_active', true)->first()
        );
    }

    private function getCachedLatestTahunAjaran(): ?TahunAjaran
    {
        return Cache::remember(
            'latest_tahun_ajaran',
            now()->addMinutes(10),
            fn () => TahunAjaran::orderByDesc('id')->first()
        );
    }

    private function getCachedTahunAjarans(bool $includeArchived = false): Collection
    {
        $cacheKey = $includeArchived
            ? 'all_tahun_ajaran_selector_archived'
            : 'all_tahun_ajaran_selector';

        return Cache::remember($cacheKey, now()->addMinutes(10), function () use ($includeArchived) {
            $query = TahunAjaran::select([
                'id',
                'tahun_ajaran',
                'semester',
                'is_active',
                'tanggal_mulai',
                'tanggal_selesai',
                'deskripsi',
                'deleted_at',
            ])
                ->orderBy('is_active', 'desc')
                ->orderBy('tanggal_mulai', 'desc');

            if ($includeArchived) {
                $query->withTrashed();
            }

            return $query->get();
        });
    }
}

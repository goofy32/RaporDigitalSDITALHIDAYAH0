@php
    $menuLinkClasses = 'flex items-center p-2 text-gray-700 rounded-lg hover:bg-gray-100 hover:text-gray-900 transition-colors duration-150';
@endphp

<aside id="logo-sidebar" 
       class="fixed top-0 left-0 z-40 w-64 h-screen pt-20 bg-white border-r border-gray-200 dark:bg-gray-800 dark:border-gray-700" 
       aria-label="Sidebar"
       data-turbo-permanent
       x-data="{ 
           initImages() {
               this.$el.querySelectorAll('img').forEach(img => {
                   if (!Alpine.store('navigation').isImageLoaded(img.id)) {
                       img.style.opacity = '0';
                       if (img.complete) {
                           img.style.opacity = '1';
                           Alpine.store('navigation').markImageLoaded(img.id);
                       } else {
                           img.addEventListener('load', () => {
                               img.style.opacity = '1';
                               Alpine.store('navigation').markImageLoaded(img.id);
                           });
                       }
                   } else {
                       img.style.opacity = '1';
                   }
               });
           }
       }"
       x-init="initImages">
    <div class="h-full px-3 pb-4 overflow-y-auto">
        <ul class="space-y-2 font-medium">
            <!-- Dashboard -->
            <li>
                <a href="{{ route('pengajar.dashboard') }}" 
                   data-turbo-action="replace"
                   data-path="/pengajar/dashboard"
                   onclick="return !window.formChanged || confirm('Ada perubahan yang belum disimpan. Yakin ingin meninggalkan halaman?')"
                   class="{{ $menuLinkClasses }}">
                    <div x-data class="w-5 h-5">
                        <img src="{{ asset('images/icons/dashboard-icon.png') }}" 
                             alt="Dashboard Icon"
                             class="w-full h-full transition-opacity duration-300"
                             :class="Alpine.store('navigation').isImageLoaded('dashboard-icon') ? 'opacity-100' : 'opacity-0'"
                             id="dashboard-icon">
                    </div>
                    <span class="ml-3">Dashboard</span>
                </a>
            </li>

            <!-- Data Pembelajaran -->
            <li>
                <a href="{{ route('pengajar.score.index') }}" 
                data-turbo-action="replace"
                data-path="/pengajar/score"
                onclick="return !window.formChanged || confirm('Ada perubahan yang belum disimpan. Yakin ingin meninggalkan halaman?')"
                class="{{ $menuLinkClasses }} relative">
                    <div x-data class="w-5 h-5">
                        <img src="{{ asset('images/icons/score.png') }}" 
                            alt="Score Icon"
                            class="w-full h-full transition-opacity duration-300"
                            :class="Alpine.store('navigation').isImageLoaded('score-icon') ? 'opacity-100' : 'opacity-0'"
                            id="score-icon">
                    </div>
                    <span class="ml-3">Data Pembelajaran</span>
                    
                    <!-- Warning Indicator with Modified Logic -->
                    @php
                    $hasLowScores = false;
                    $countLowScores = 0;
                    $kkmValue = 70; // Default KKM value
                    
                    // Cek apakah ada guru yang login
                    if (Auth::guard('guru')->check()) {
                        $guru = Auth::guard('guru')->user();
                        
                        // Base query
                        $query = DB::table('nilais')
                            ->join('mata_pelajarans', 'nilais.mata_pelajaran_id', '=', 'mata_pelajarans.id')
                            ->join('kkms', 'mata_pelajarans.id', '=', 'kkms.mata_pelajaran_id')
                            ->where('mata_pelajarans.guru_id', $guru->id)
                            ->whereNull('nilais.deleted_at')
                            ->whereNull('mata_pelajarans.deleted_at')
                            ->where('nilais.nilai_akhir_rapor', '<', DB::raw('kkms.nilai'));
                            
                        // Add tahun ajaran filter
                        $tahunAjaranId = session('tahun_ajaran_id');
                        if ($tahunAjaranId) {
                            $query->where(function($q) use ($tahunAjaranId) {
                                $q->where('nilais.tahun_ajaran_id', $tahunAjaranId)
                                    ->where('mata_pelajarans.tahun_ajaran_id', $tahunAjaranId)
                                    ->where('kkms.tahun_ajaran_id', $tahunAjaranId);
                            });
                        }
                        
                        $nilaiDibawahKKM = $query->count();
                            
                        if ($nilaiDibawahKKM > 0) {
                            $hasLowScores = true;
                            $countLowScores = $nilaiDibawahKKM;
                        }
                    }
                    @endphp
                    
                    @if($hasLowScores)
                    <div class="absolute right-0 top-0 -mr-1 -mt-1">
                        <span class="flex h-5 w-5">
                            <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-red-400 opacity-75"></span>
                            <span class="relative inline-flex rounded-full h-5 w-5 bg-red-500 text-white text-xs items-center justify-center">
                                {{ $countLowScores > 99 ? '99+' : $countLowScores }}
                            </span>
                        </span>
                    </div>
                    @endif
                </a>
                </li>

            <!-- Data Mata Pelajaran -->
            <li>
                <a href="{{ route('pengajar.subject.index') }}" 
                   data-turbo-action="replace"
                   data-path="/pengajar/subject"
                   onclick="return !window.formChanged || confirm('Ada perubahan yang belum disimpan. Yakin ingin meninggalkan halaman?')"
                   class="{{ $menuLinkClasses }}">
                    <div x-data class="w-5 h-5">
                        <img src="{{ asset('images/icons/subject-icon.png') }}" 
                             alt="Subject Icon"
                             class="w-full h-full transition-opacity duration-300"
                             :class="Alpine.store('navigation').isImageLoaded('subject-icon') ? 'opacity-100' : 'opacity-0'"
                             id="subject-icon">
                    </div>
                    <span class="ml-3">Data Mata Pelajaran</span>
                </a>
            </li>
        </ul>
    </div>
</aside>

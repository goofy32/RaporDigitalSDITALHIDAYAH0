@php
    $menuLinkClasses = 'flex items-center p-2 text-gray-700 rounded-lg hover:bg-gray-100 hover:text-gray-900 transition-colors duration-150';
@endphp

<aside id="logo-sidebar" 
       class="fixed top-0 left-0 z-40 w-64 h-screen pt-20 transition-transform -translate-x-full bg-white border-r border-gray-200 xl:translate-x-0 dark:bg-gray-800 dark:border-gray-700"
       aria-label="Sidebar"
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
                    <span class="ml-3">Data Nilai Pelajaran</span>
                    
                    <!-- Warning Indicator with Modified Logic -->
                    @php
                    $hasLowScores = $pengajarHasLowScores ?? false;
                    $countLowScores = $pengajarLowScoreCount ?? 0;
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

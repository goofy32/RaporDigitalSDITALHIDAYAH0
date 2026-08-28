<aside id="logo-sidebar"
    class="fixed top-0 left-0 z-40 w-64 h-screen pt-20 transition-transform -translate-x-full bg-white border-r border-gray-200 xl:translate-x-0 dark:bg-gray-800 dark:border-gray-700"
    aria-label="Sidebar"
    data-turbo-permanent>
    @php
        $hasTahunAjaran = $hasAnyTahunAjaran ?? (isset($tahunAjarans) && $tahunAjarans->isNotEmpty());
        $hasProfilSekolah = isset($profilSekolah) && $profilSekolah !== null;
        $menuLinkClasses = 'flex items-center p-2 text-gray-700 rounded-lg hover:bg-gray-100 hover:text-gray-900 transition-colors duration-150 group';
    @endphp
    <div x-data="{ 
        openDropdown: $store.sidebar.dropdownState.formatRapor,
        hasTahunAjaran: {{ $hasTahunAjaran ? 'true' : 'false' }},
        hasProfilSekolah: {{ $hasProfilSekolah ? 'true' : 'false' }},
        showSetupWarning() {
            Swal.fire({
                title: 'Persiapan Sistem Belum Lengkap',
                html: `
                    <div class='text-left'>
                        Anda perlu menyelesaikan persiapan sistem terlebih dahulu:
                        <ul class='list-disc pl-5 mt-2'>
                            ${!this.hasProfilSekolah ? '<li>Lengkapi <a href=\'{{ route('profile.edit') }}\' class=\'font-medium text-blue-600\'>Profil Sekolah</a></li>' : ''}
                            ${!this.hasTahunAjaran ? '<li>Buat <a href=\'{{ route('tahun.ajaran.create') }}\' class=\'font-medium text-blue-600\'>Tahun Ajaran</a></li>' : ''}
                        </ul>
                    </div>
                `,
                icon: 'warning',
                confirmButtonText: 'Mengerti'
            });
        }
    }" 
    x-init="$store.sidebar.initDropdown('formatRapor')"
    class="h-full px-3 pb-4 overflow-y-auto bg-white dark:bg-gray-800">
        <ul class="space-y-2 font-medium">
            <li>
                <a href="{{ route('admin.dashboard') }}"
                    data-turbo-frame="main"
                    data-path="/admin/dashboard"
                    class="{{ $menuLinkClasses }}">
                    <img src="{{ asset('images/icons/dashboard-icon.png') }}" alt="Dashboard Icon" class="w-5 h-5"
                    data-persist-visibility="true"
                    data-preloaded="true" onerror="this.onerror=null; this.src='{{ asset('images/icons/dashboard-icon.png') }}?v=' + Date.now();">
                    <span class="ms-3">Dashboard</span>
                </a>
            </li>

            @if(config('staging_test_tools.enabled'))
                <li>
                    <a href="{{ route('admin.testing.multi-user.index') }}"
                        data-path="/admin/testing/multi-user-simulation"
                        class="{{ $menuLinkClasses }}">
                        <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5 text-current" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"></path>
                            <circle cx="9" cy="7" r="4"></circle>
                            <path d="M22 21v-2a4 4 0 0 0-3-3.87"></path>
                            <path d="M16 3.13a4 4 0 0 1 0 7.75"></path>
                        </svg>
                        <span class="ms-3">Simulasi Staging</span>
                    </a>
                </li>
            @endif

            <li>
                <a href="{{ route('tahun.ajaran.index') }}" 
                data-path="/admin/tahun-ajaran"
                class="{{ $menuLinkClasses }}">
                    <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5 text-current" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect>
                        <line x1="16" y1="2" x2="16" y2="6"></line>
                        <line x1="8" y1="2" x2="8" y2="6"></line>
                        <line x1="3" y1="10" x2="21" y2="10"></line>
                    </svg>
                    <span class="ms-3">Tahun Ajaran</span>
                </a>
            </li>

            <li>
                <a href="{{ route('profile') }}"
                    data-turbo-frame="main"
                    data-path="/admin/profile"
                    class="{{ $menuLinkClasses }}">
                    <img src="{{ asset('images/icons/profile-icon.png') }}" alt="Profile Icon" class="w-5 h-5" data-persist-visibility="true" data-preloaded="true" onerror="this.onerror=null; this.src='{{ asset('images/icons/profile-icon.png') }}?v=' + Date.now();">
                    <span class="ms-3">Profile Sekolah</span>
                </a>
            </li>

            <!-- Menu-menu yang membutuhkan Tahun Ajaran dan Profil Sekolah -->
            @if($hasTahunAjaran && $hasProfilSekolah)
                <div class="space-y-2">
                    <li>
                        <a href="{{ route('kelas.index') }}"
                            data-turbo-frame="main"
                            data-path="/admin/kelas"
                            class="{{ $menuLinkClasses }}">
                            <img src="{{ asset('images/icons/class-icon.png') }}" alt="Class Icon" class="w-5 h-5" data-persist-visibility="true" data-preloaded="true" onerror="this.onerror=null; this.src='{{ asset('images/icons/class-icon.png') }}?v=' + Date.now();">
                            <span class="ms-3">Kelas</span>
                        </a>
                    </li>
                    <li>
                        <a href="{{ route('teacher') }}"
                            data-turbo-frame="main"
                            data-path="/admin/pengajar"
                            class="{{ $menuLinkClasses }}">
                            <img src="{{ asset('images/icons/teacher-icon.png') }}" alt="Teacher Icon" class="w-5 h-5" data-persist-visibility="true" data-preloaded="true" onerror="this.onerror=null; this.src='{{ asset('images/icons/teacher-icon.png') }}?v=' + Date.now();">
                            <span class="ms-3">Pengajar</span>
                        </a>
                    </li>
                    <li>
                        <a href="{{ route('student') }}"
                            data-turbo-frame="main"
                            data-path="/admin/students"
                            class="{{ $menuLinkClasses }}">
                            <img src="{{ asset('images/icons/student-icon.png') }}" alt="Student Icon" class="w-5 h-5" data-persist-visibility="true" data-preloaded="true" onerror="this.onerror=null; this.src='{{ asset('images/icons/student-icon.png') }}?v=' + Date.now();">
                            <span class="ms-3">Siswa</span>
                        </a>
                    </li>
                    <li>
                        <a href="{{ route('subject.index') }}"
                            data-turbo-frame="main"
                            data-path="/admin/subject"
                            class="{{ $menuLinkClasses }}">
                            <img src="{{ asset('images/icons/subject-icon.png') }}" alt="Subject Icon" class="w-5 h-5" data-persist-visibility="true" data-preloaded="true" onerror="this.onerror=null; this.src='{{ asset('images/icons/dashboard-icon.png') }}?v=' + Date.now();">
                            <span class="ms-3">Pelajaran</span>
                        </a>
                    </li>
                    <li>
                        <a href="{{ route('ekstra.index') }}"
                            data-turbo-frame="main"
                            data-path="/admin/ekstrakulikuler"
                            class="{{ $menuLinkClasses }}">
                            <img src="{{ asset('images/icons/extracurricular-icon.png') }}" alt="Extracurricular Icon" class="w-5 h-5" data-persist-visibility="true" data-preloaded="true" onerror="this.onerror=null; this.src='{{ asset('images/icons/extracurricular-icon.png') }}?v=' + Date.now();">
                            <span class="ms-3">Ekstrakurikuler</span>
                        </a>
                    </li>
                    <li>
                        <a href="{{ route('achievement.index') }}"
                            data-turbo-frame="main"
                            data-path="/admin/achievement"
                            class="{{ $menuLinkClasses }}">
                            <img src="{{ asset('images/icons/achievement-icon.png') }}" alt="Achievement Icon" class="w-5 h-5" data-persist-visibility="true" data-preloaded="true" onerror="this.onerror=null; this.src='{{ asset('images/icons/achievement-icon.png') }}?v=' + Date.now();">
                            <span class="ms-3">Prestasi</span>
                        </a>
                    </li>
                    <li>
                        <a href="{{ route('report.template.index') }}"
                        data-turbo-frame="main" 
                        data-path="/admin/report-template"
                        class="{{ $menuLinkClasses }}">
                            <img src="{{ asset('images/icons/report-icon.png') }}" class="w-5 h-5" alt="Format Rapor" data-persist-visibility="true" data-preloaded="true" onerror="this.onerror=null; this.src='{{ asset('images/icons/report-icon.png') }}?v=' + Date.now();" />
                            <span class="flex-1 ml-3 whitespace-nowrap">Format Rapor</span>
                        </a>
                    </li>
                    <li>
                        <a href="{{ route('admin.report.history') }}"
                        data-turbo-frame="main" 
                        data-path="/admin/report-history"
                        class="{{ $menuLinkClasses }}">
                            <img src="{{ asset('images/icons/history-icon.png') }}" alt="History Icon" class="w-5 h-5" data-persist-visibility="true" data-preloaded="true" onerror="this.onerror=null; this.src='{{ asset('images/icons/history-icon.png') }}?v=' + Date.now();">
                            <span class="flex-1 ml-3 whitespace-nowrap">Riwayat Cetak Rapor</span>
                        </a>
                    </li>
                    <li>
                        <a href="{{ route('admin.kenaikan-kelas.index') }}"
                            data-turbo-frame="main"
                            data-path="/admin/kenaikan-kelas"
                            class="{{ $menuLinkClasses }}">
                            <img src="{{ asset('images/icons/kenaikan-kelas-icon.png') }}" alt="Promotion Icon" class="w-5 h-5" data-persist-visibility="true" data-preloaded="true" onerror="this.onerror=null; this.src='{{ asset('images/icons/kenaikan-kelas-icon.png') }}?v=' + Date.now();">
                            <span class="ms-3">Kenaikan Kelas</span>
                        </a>
                    </li>
                </div>
            @endif

            <!-- Menu-menu yang di-disable jika belum ada Tahun Ajaran atau Profil Sekolah -->
            @if(!$hasTahunAjaran || !$hasProfilSekolah)
                <div class="space-y-2">
                    <li>
                        <a href="#" @click.prevent="showSetupWarning()" 
                            class="flex items-center p-2 text-gray-400 rounded-lg cursor-not-allowed group">
                            <img src="{{ asset('images/icons/class-icon.png') }}" alt="Class Icon" class="w-5 h-5 opacity-50" data-persist-visibility="true">
                            <span class="ms-3">Kelas</span>
                        </a>
                    </li>
                    <li>
                        <a href="#" @click.prevent="showSetupWarning()" 
                            class="flex items-center p-2 text-gray-400 rounded-lg cursor-not-allowed group">
                            <img src="{{ asset('images/icons/teacher-icon.png') }}" alt="Teacher Icon" class="w-5 h-5 opacity-50" data-persist-visibility="true">
                            <span class="ms-3">Pengajar</span>
                        </a>
                    </li>
                    <li>
                        <a href="#" @click.prevent="showSetupWarning()" 
                            class="flex items-center p-2 text-gray-400 rounded-lg cursor-not-allowed group">
                            <img src="{{ asset('images/icons/student-icon.png') }}" alt="Student Icon" class="w-5 h-5 opacity-50" data-persist-visibility="true">
                            <span class="ms-3">Siswa</span>
                        </a>
                    </li>
                    <li>
                        <a href="#" @click.prevent="showSetupWarning()" 
                            class="flex items-center p-2 text-gray-400 rounded-lg cursor-not-allowed group">
                            <img src="{{ asset('images/icons/subject-icon.png') }}" alt="Subject Icon" class="w-5 h-5 opacity-50" data-persist-visibility="true">
                            <span class="ms-3">Pelajaran</span>
                        </a>
                    </li>
                    <li>
                        <a href="#" @click.prevent="showSetupWarning()" 
                            class="flex items-center p-2 text-gray-400 rounded-lg cursor-not-allowed group">
                            <img src="{{ asset('images/icons/extracurricular-icon.png') }}" alt="Extracurricular Icon" class="w-5 h-5 opacity-50" data-persist-visibility="true">
                            <span class="ms-3">Ekstrakurikuler</span>
                        </a>
                    </li>
                    <li>
                        <a href="#" @click.prevent="showSetupWarning()" 
                            class="flex items-center p-2 text-gray-400 rounded-lg cursor-not-allowed group">
                            <img src="{{ asset('images/icons/achievement-icon.png') }}" alt="Achievement Icon" class="w-5 h-5 opacity-50" data-persist-visibility="true">
                            <span class="ms-3">Prestasi</span>
                        </a>
                    </li>
                    <li>
                        <a href="#" @click.prevent="showSetupWarning()" 
                            class="flex items-center p-2 text-gray-400 rounded-lg cursor-not-allowed group">
                            <img src="{{ asset('images/icons/report-icon.png') }}" class="w-5 h-5 opacity-50" alt="Format Rapor" data-persist-visibility="true" />
                            <span class="flex-1 ml-3 whitespace-nowrap">Format Rapor</span>
                        </a>
                    </li>
                    <li>
                        <a href="#" @click.prevent="showSetupWarning()" 
                            class="flex items-center p-2 text-gray-400 rounded-lg cursor-not-allowed group">
                            <img src="{{ asset('images/icons/history-icon.png') }}" class="w-5 h-5 opacity-50" alt="History Icon" data-persist-visibility="true">
                            <span class="flex-1 ml-3 whitespace-nowrap">History Rapor</span>
                        </a>
                    </li>
                    <li>
                        <a href="#" @click.prevent="showSetupWarning()" 
                            class="flex items-center p-2 text-gray-400 rounded-lg cursor-not-allowed group">
                            <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5 opacity-50" viewBox="0 0 20 20" fill="currentColor">
                                <path fill-rule="evenodd" d="M5.293 9.707a1 1 0 010-1.414l4-4a1 1 0 011.414 0l4 4a1 1 0 01-1.414 1.414L11 7.414V15a1 1 0 11-2 0V7.414L6.707 9.707a1 1 0 01-1.414 0z" clip-rule="evenodd" />
                            </svg>
                            <span class="ms-3">Kenaikan Kelas</span>
                        </a>
                    </li>
                </div>
            @endif
        </ul>
    </div>
</aside>

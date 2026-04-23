@extends('layouts.base')

@section('role-meta')
    <meta name="turbo-root" content="true">
@endsection

@section('sidebar')
    <x-admin.sidebar data-turbo-permanent id="sidebar"></x-admin.sidebar>
@endsection

@push('styles')
<style>
    #content-loading-overlay {
        position: absolute;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        background-color: rgba(255, 255, 255, 0.8);
        display: flex;
        flex-direction: column;
        justify-content: center;
        align-items: center;
        z-index: 50;
        opacity: 0;
        pointer-events: none;
        transition: opacity 0.2s ease;
    }

    #content-loading-overlay.active {
        opacity: 1;
        pointer-events: auto;
    }

    .content-spinner {
        width: 40px;
        height: 40px;
        border: 3px solid rgba(34, 197, 94, 0.2);
        border-top: 3px solid #22c55e;
        border-radius: 50%;
        animation: spin 1s linear infinite;
        box-shadow: 0 0 10px rgba(34, 197, 94, 0.3);
    }

    .content-spinner-text {
        margin-top: 0.75rem;
        font-size: 0.875rem;
        color: #16a34a;
        font-weight: 500;
    }

    @keyframes spin {
        0% { transform: rotate(0deg); }
        100% { transform: rotate(360deg); }
    }

    #logo-sidebar a.active,
    #logo-sidebar a:focus,
    #logo-sidebar a[aria-current="page"],
    #logo-sidebar a.bg-gray-100,
    #logo-sidebar a.bg-green-100 {
        background-color: transparent !important;
    }

    input:required:invalid,
    select:required:invalid {
        border-color: #EF4444;
    }

    .invalid-feedback {
        color: #EF4444;
        font-size: 0.875rem;
        margin-top: 0.25rem;
    }

    #logo-sidebar img {
        opacity: 1 !important;
        visibility: visible !important;
        transition: none !important;
        min-height: 1.25rem;
        min-width: 1.25rem;
        height: 1.25rem;
        width: 1.25rem;
        filter: brightness(0.2) contrast(1.2);
        backface-visibility: hidden;
        -webkit-backface-visibility: hidden;
        will-change: auto;
        transform: translateZ(0);
    }

    #logo-sidebar a.cursor-not-allowed img {
        opacity: 0.6 !important;
        filter: grayscale(1) brightness(0.4);
    }

    #logo-sidebar:not(:first-of-type) {
        display: none !important;
    }

    #logo-sidebar {
        will-change: transform;
        transition: transform 0.3s ease;
        transform: none !important;
    }

    @media (min-width: 640px) {
        #logo-sidebar {
            transform: translateX(0) !important;
        }
    }

    @media (min-width: 640px) {
        .sm\:ml-64 {
            margin-left: 16rem !important;
        }
    }

    #logo-sidebar svg {
        opacity: 1 !important;
        visibility: visible !important;
        width: 1.25rem !important;
        height: 1.25rem !important;
        min-width: 1.25rem !important;
        min-height: 1.25rem !important;
    }

    [x-cloak] {
        display: none !important;
    }

    #dropdown-rapor {
        display: none;
    }

    #dropdown-rapor.show {
        display: block;
    }

    .dropdown-transition {
        transition: opacity 150ms ease-in-out, transform 150ms ease-in-out;
    }

    .sidebar-icon {
        width: 1.25rem;
        height: 1.25rem;
    }

    .turbo-progress-bar {
        background-color: #22c55e !important;
    }

    body.edit-subject-page #logo-sidebar {
        transform: translateX(0) !important;
    }

    body.edit-subject-page .sm\:ml-64 {
        margin-left: 16rem !important;
    }
</style>
@endpush

@section('layout-content')
    <x-content-loading-overlay />

    <div class="p-4 sm:ml-64 min-h-screen bg-white relative">
        <div id="content-loading-overlay"
             x-data="{
                 active: false,
                 init() {
                     document.addEventListener('turbo:before-visit', () => {
                         this.active = true;
                     });

                     document.addEventListener('turbo:submit-start', () => {
                         this.active = true;
                     });

                     document.addEventListener('turbo:render', () => {
                         setTimeout(() => {
                             this.active = false;
                         }, 100);
                     });

                     document.addEventListener('turbo:load', () => {
                         setTimeout(() => {
                             this.active = false;
                         }, 100);
                     });

                     document.addEventListener('turbo:before-fetch-response', () => {
                         setTimeout(() => {
                             this.active = false;
                         }, 100);
                     });
                 }
             }"
             :class="{ 'active': active }">
            <div class="content-spinner"></div>
            <p class="content-spinner-text">Loading...</p>
        </div>

        <div class="mt-14">
            @if(session('tahun_ajaran_id') && isset($activeTahunAjaran) && $activeTahunAjaran && session('tahun_ajaran_id') != $activeTahunAjaran->id)
                <div class="bg-blue-50 border-l-4 border-blue-400 p-4 mb-4">
                    <div class="flex">
                        <div class="flex-shrink-0">
                            <svg class="h-5 w-5 text-blue-400" viewBox="0 0 20 20" fill="currentColor">
                                <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2v-3a1 1 0 00-1-1H9z" clip-rule="evenodd" />
                            </svg>
                        </div>
                        <div class="ml-3">
                            <p class="text-sm text-blue-700">
                                <strong>Perhatian:</strong> Anda sedang melihat data untuk tahun ajaran <strong>{{ (App\Models\TahunAjaran::find(session('tahun_ajaran_id')))->tahun_ajaran ?? 'Tidak diketahui' }}</strong>, sedangkan tahun ajaran aktif adalah <strong>{{ $activeTahunAjaran->tahun_ajaran }}</strong>.
                            </p>
                        </div>
                    </div>
                </div>
            @endif

            @if(session('success'))
                <x-alert type="success" :message="session('success')" />
            @endif

            @if(session('error'))
                <x-alert type="error" :message="session('error')" />
            @endif

            <div id="main" data-turbo-frame="main">
                @yield('content')
            </div>
        </div>
    </div>

    @if(Auth::guard('web')->check())
        <x-admin.settings-modal id="settings-modal"></x-admin.settings-modal>
    @endif

    <x-ai-chatbot />
@endsection

@section('role-scripts')
    @if(Session::has('warning'))
        <script>
            Swal.fire({
                icon: 'warning',
                title: 'Perhatian!',
                html: "{{ Session::get('warning') }}",
                confirmButtonText: 'Mengerti'
            });
        </script>
    @endif

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            document.addEventListener('open-settings', function() {
                window.dispatchEvent(new CustomEvent('open-settings'));
            });

            if (window.Alpine) {
                window.Alpine.store('contentLoading', {
                    isLoading: false,

                    startLoading() {
                        this.isLoading = true;
                        document.getElementById('content-loading-overlay').classList.add('active');
                    },

                    stopLoading() {
                        this.isLoading = false;
                        document.getElementById('content-loading-overlay').classList.remove('active');
                    }
                });
            }

            const sidebar = document.getElementById('logo-sidebar');
            if (sidebar) {
                sidebar.classList.remove('-translate-x-full');
                sidebar.classList.add('sm:translate-x-0');
            }

            if (typeof window.preloadAndCacheSidebarIcons === 'function') {
                window.preloadAndCacheSidebarIcons();
            }

            if (typeof window.updateSidebarActiveState === 'function') {
                window.updateSidebarActiveState();
            }
        });

        (function() {
            const sidebarImages = document.querySelectorAll('#logo-sidebar img');
            sidebarImages.forEach(img => {
                const image = new Image();
                image.onload = function() {
                    img.style.opacity = '1';
                    img.style.visibility = 'visible';
                    img.setAttribute('data-loaded', 'true');
                };
                image.src = img.src;
                img.style.opacity = '1';
                img.style.visibility = 'visible';
            });
        })();
    </script>
@endsection

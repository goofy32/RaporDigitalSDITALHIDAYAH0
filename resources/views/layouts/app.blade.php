<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="ie=edge">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="turbo-cache-control" content="no-preview">
    <meta name="turbo-visit-control" content="reload">
    <!-- Add this new meta tag -->
    <meta name="turbo-root" content="true">
    <link rel="icon" type="image/x-icon" href="{{ asset('favicon.ico') }}">
    <link rel="icon" type="image/png" sizes="16x16" href="{{ asset('favicon-16x16.png') }}">
    <link rel="icon" type="image/png" sizes="32x32" href="{{ asset('favicon-32x32.png') }}">
    <link rel="apple-touch-icon" sizes="180x180" href="{{ asset('apple-touch-icon.png') }}">

    <!-- Preload critical images -->
    <link rel="preload" href="{{ asset('images/icons/dashboard-icon.png') }}" as="image" fetchpriority="high">
    <link rel="preload" href="{{ asset('images/icons/subject-icon.png') }}" as="image" fetchpriority="high">
    <link rel="preload" href="{{ asset('images/icons/class-icon.png') }}" as="image" fetchpriority="high">
    <link rel="preload" href="{{ asset('images/icons/profile-icon.png') }}" as="image" fetchpriority="high">
    <link rel="preload" href="{{ asset('images/icons/teacher-icon.png') }}" as="image" fetchpriority="high">
    <link rel="preload" href="{{ asset('images/icons/student-icon.png') }}" as="image" fetchpriority="high">
    <link rel="preload" href="{{ asset('images/icons/report-icon.png') }}" as="image" fetchpriority="high">
    <link rel="preload" href="{{ asset('images/icons/history-icon.png') }}" as="image" fetchpriority="high">
    <link rel="preload" href="{{ asset('images/icons/kenaikan-kelas-icon.png') }}" as="image" fetchpriority="high">
    
    <title>@yield('title')</title>
    
    <!-- Styles -->
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <link rel="stylesheet" href="https://rsms.me/inter/inter.css">
    
    <!-- React and ReactDOM -->
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

    <!-- Content-area loading overlay styles with green theme -->
    <style>
        /* Content-area loading overlay */
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
        
        /* Green loading spinner */
        .content-spinner {
            width: 40px;
            height: 40px;
            border: 3px solid rgba(34, 197, 94, 0.2); /* Green-500 with low opacity */
            border-top: 3px solid #22c55e; /* Green-500 */
            border-radius: 50%;
            animation: spin 1s linear infinite;
            box-shadow: 0 0 10px rgba(34, 197, 94, 0.3);
        }
        
        .content-spinner-text {
            margin-top: 0.75rem;
            font-size: 0.875rem;
            color: #16a34a; /* Green-600 for text */
            font-weight: 500;
        }
        
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
        
        /* Fix sidebar active state styles - remove background colors */
        #logo-sidebar a.active,
        #logo-sidebar a:focus,
        #logo-sidebar a[aria-current="page"],
        #logo-sidebar a.bg-gray-100,
        #logo-sidebar a.bg-green-100 {
            background-color: transparent !important;
        }

        /* Enhanced input styles */
        input:required:invalid,
        select:required:invalid {
            border-color: #EF4444;
        }

        .invalid-feedback {
            color: #EF4444;
            font-size: 0.875rem;
            margin-top: 0.25rem;
        }

        /* Fix sidebar icons disappearing during page transitions */
        #logo-sidebar img {
            opacity: 1 !important; 
            visibility: visible !important;
            transition: none !important;
            min-height: 1.25rem;
            min-width: 1.25rem;
            height: 1.25rem;
            width: 1.25rem;
            filter: brightness(0.2) contrast(1.2); /* Make icons more visible but not fully black */
            /* Prevent flash during loading */
            backface-visibility: hidden;
            -webkit-backface-visibility: hidden;
            will-change: auto;
            transform: translateZ(0);
        }

        /* Adjust disabled icon opacity */
        #logo-sidebar a.cursor-not-allowed img {
            opacity: 0.6 !important;
            filter: grayscale(1) brightness(0.4);
        }

        /* Prevent sidebar duplication */
        #logo-sidebar:not(:first-of-type) {
            display: none !important;
        }

        /* Improve sidebar transition */
        #logo-sidebar {
            will-change: transform;
            transition: transform 0.3s ease;
            transform: none !important;
        }

        /* Ensure sidebar is always visible on large screens */
        @media (min-width: 640px) {
            #logo-sidebar {
                transform: translateX(0) !important;
            }
        }

        /* Adjust content to accommodate sidebar */
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
            transition: opacity 150ms ease-in-out,
                        transform 150ms ease-in-out;
        }

        .sidebar-icon {
            width: 1.25rem;
            height: 1.25rem;
        }

        /* Prevent flash during navigation */
        .turbo-progress-bar {
            background-color: #22c55e !important; /* Green progress bar */
        }

        body.edit-subject-page #logo-sidebar {
            transform: translateX(0) !important;
        }

        body.edit-subject-page .sm\:ml-64 {
            margin-left: 16rem !important;
        }
    </style>
</head>
<body>
    <!-- Keep your existing content loading overlay component -->
    <x-content-loading-overlay />

    <x-admin.topbar data-turbo-permanent id="topbar"></x-admin.topbar>
    <x-admin.sidebar data-turbo-permanent id="sidebar"></x-admin.sidebar>
    <x-session-timeout-alert data-turbo-permanent id="session-alert" />

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

    <!-- Main content area with the loading overlay -->
    <div class="p-4 sm:ml-64 min-h-screen bg-white relative">
        <!-- Content-specific loading overlay - green theme -->
        <div id="content-loading-overlay" 
             x-data="{ 
                 active: false,
                 init() {
                     // Show overlay when navigation starts
                     document.addEventListener('turbo:before-visit', () => {
                         this.active = true;
                     });
                     
                     // Show on form submissions
                     document.addEventListener('turbo:submit-start', () => {
                         this.active = true;
                     });
                     
                     // Hide when page is rendered
                     document.addEventListener('turbo:render', () => {
                         setTimeout(() => {
                             this.active = false;
                         }, 100);
                     });
                     
                     // Additional events to hide the overlay
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

        <div class="mt-14"> <!-- Padding top untuk navbar -->
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

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Setup event listeners for settings modal
            document.addEventListener('open-settings', function() {
                window.dispatchEvent(new CustomEvent('open-settings'));
            });
            
            // Setup Alpine store for controlling loading state
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
            
            // Force sidebar to be visible after navigation
            const sidebar = document.getElementById('logo-sidebar');
            if (sidebar) {
                sidebar.classList.remove('-translate-x-full');
                sidebar.classList.add('sm:translate-x-0');
            }
            
            // Fix sidebar images and states
            preloadAndCacheSidebarIcons();
            if (typeof updateSidebarActiveState === 'function') {
                updateSidebarActiveState();
            }
        });
        
        // Force immediate loading of sidebar images
        (function() {
            const sidebarImages = document.querySelectorAll('#logo-sidebar img');
            sidebarImages.forEach(img => {
                // Force immediate loading by creating a new Image
                const image = new Image();
                image.onload = function() {
                    img.style.opacity = '1';
                    img.style.visibility = 'visible';
                    img.setAttribute('data-loaded', 'true');
                };
                image.src = img.src;
                
                // Set default state
                img.style.opacity = '1';
                img.style.visibility = 'visible';
            });
        })();
    </script>

    <x-ai-chatbot />

    @stack('scripts')

    <script>
    // Force immediate loading of sidebar images
    (function() {
        const sidebarImages = document.querySelectorAll('#logo-sidebar img');
        sidebarImages.forEach(img => {
            // Force immediate loading by creating a new Image
            const image = new Image();
            image.onload = function() {
                img.style.opacity = '1';
                img.style.visibility = 'visible';
                img.setAttribute('data-loaded', 'true');
            };
            image.src = img.src;
            
            // Set default state
            img.style.opacity = '1';
            img.style.visibility = 'visible';
        });
    })();
</script>
</body>
</html>
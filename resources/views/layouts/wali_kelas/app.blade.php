@extends('layouts.base')

@section('role-meta')
    <meta name="turbo-root" content="true">
@endsection

@section('sidebar')
    <x-wali-kelas.sidebar></x-wali-kelas.sidebar>
@endsection

@push('styles')
<style>
    #global-loader {
        position: absolute;
        top: 3.5rem;
        left: 0;
        right: 0;
        bottom: 0;
        display: flex;
        justify-content: center;
        align-items: center;
        background-color: white;
        z-index: 30;
        transition: opacity 0.3s ease;
    }

    #global-loader.fade-out {
        opacity: 0;
        pointer-events: none;
    }

    [x-cloak] {
        display: none !important;
    }
</style>
@endpush

@section('layout-content')
    <div class="p-4 xl:ml-64 min-h-screen bg-white relative">
        <div id="global-loader">
            <div class="flex flex-col items-center">
                <svg class="animate-spin h-12 w-12 text-green-600 mb-3" viewBox="0 0 24 24" fill="none">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                </svg>
                <p class="text-gray-600">Memuat aplikasi...</p>
            </div>
        </div>

        <div class="mt-16">
            <div id="main">
                @php
                    $layoutSystemActiveTahunAjaran = $systemActiveTahunAjaran ?? null;
                    $layoutHasActiveTahunAjaran = $hasActiveTahunAjaran ?? !is_null($layoutSystemActiveTahunAjaran);
                    $layoutFallbackTahunAjaran = !$layoutHasActiveTahunAjaran
                        ? ($latestTahunAjaran ?? null)
                        : null;
                @endphp

                @if(!$layoutHasActiveTahunAjaran)
                    <div x-data="{ show: true }"
                         x-show="show"
                         x-transition.opacity.duration.150ms
                         class="bg-yellow-50 border-l-4 border-yellow-400 p-4 mb-4 rounded-r">
                        <div class="flex items-start justify-between gap-4">
                            <div class="flex items-start gap-3">
                                <svg class="w-5 h-5 text-yellow-400 mt-0.5 flex-shrink-0" viewBox="0 0 20 20" fill="currentColor">
                                    <path fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.72-1.36 3.485 0l5.58 9.92c.75 1.334-.213 2.981-1.742 2.981H4.42c-1.53 0-2.492-1.647-1.743-2.98l5.58-9.921zM11 13a1 1 0 10-2 0 1 1 0 002 0zm-1-6a1 1 0 00-1 1v3a1 1 0 102 0V8a1 1 0 00-1-1z" clip-rule="evenodd" />
                                </svg>
                                <div>
                                <p class="text-yellow-800 font-medium">Tahun Ajaran Belum Diaktifkan</p>
                                <p class="text-yellow-700 text-sm">
                                    @if($layoutFallbackTahunAjaran)
                                        Data Anda akan masuk ke
                                        <strong>{{ $layoutFallbackTahunAjaran->tahun_ajaran }} - {{ $layoutFallbackTahunAjaran->semester }}</strong>.
                                        Hubungi administrator untuk mengaktifkan tahun ajaran yang benar.
                                    @else
                                            Belum ada tahun ajaran. Hubungi administrator segera.
                                        @endif
                                    </p>
                                </div>
                            </div>
                            <button type="button" @click="show = false" class="text-yellow-400 hover:text-yellow-600">
                                <svg class="w-4 h-4" viewBox="0 0 20 20" fill="currentColor">
                                    <path fill-rule="evenodd" d="M4.293 4.293a1 1 0 011.414 0L10 8.586l4.293-4.293a1 1 0 111.414 1.414L11.414 10l4.293 4.293a1 1 0 01-1.414 1.414L10 11.414l-4.293 4.293a1 1 0 01-1.414-1.414L8.586 10 4.293 5.707a1 1 0 010-1.414z" clip-rule="evenodd" />
                                </svg>
                            </button>
                        </div>
                    </div>
                @endif

                @if(session('success'))
                    <x-alert type="success" :message="session('success')" />
                @endif

                @if(session('error'))
                    <x-alert type="error" :message="session('error')" />
                @endif

                <x-guru-email-verification-banner />

                @yield('content')
            </div>
        </div>
    </div>

    <x-ai-chatbot />
@endsection

@section('role-scripts')
    <script>
        document.addEventListener('turbo:before-render', function () {
            const oldErrors = JSON.parse(localStorage.getItem('validationErrors'));
            const oldInput = JSON.parse(localStorage.getItem('oldInput'));

            if (oldErrors) {
                window.validationErrors = oldErrors;
            }

            if (oldInput) {
                window.oldInput = oldInput;
            }
        });

        document.addEventListener('turbo:render', function () {
            if (window.validationErrors) {
                const errorWrapper = document.createElement('div');
                errorWrapper.className = 'bg-red-100 border-l-4 border-red-500 text-red-700 p-4 mb-6';
                errorWrapper.setAttribute('role', 'alert');

                let errorContent = '<p class="font-bold">Validasi Error:</p><ul>';

                Object.values(window.validationErrors).flat().forEach(error => {
                    errorContent += `<li>${error}</li>`;
                });

                errorContent += '</ul>';
                errorWrapper.innerHTML = errorContent;

                const content = document.querySelector('#main');
                if (content) {
                    content.insertBefore(errorWrapper, content.firstChild);
                }

                delete window.validationErrors;
                localStorage.removeItem('validationErrors');
            }

            if (window.oldInput) {
                Object.entries(window.oldInput).forEach(([name, value]) => {
                    const input = document.querySelector(`[name="${name}"]`);
                    if (input) {
                        if (input.type === 'checkbox' || input.type === 'radio') {
                            input.checked = value === input.value;
                        } else if (input.tagName === 'SELECT') {
                            Array.from(input.options).forEach(option => {
                                option.selected = option.value === value;
                            });
                        } else {
                            input.value = value;
                        }
                    }
                });

                delete window.oldInput;
                localStorage.removeItem('oldInput');
            }
        });

        document.addEventListener('DOMContentLoaded', function() {
            const images = document.querySelectorAll('#topbar img, #logo-sidebar img');
            let loadedImages = 0;

            if (images.length === 0) {
                return;
            }

            const checkAllImagesLoaded = () => {
                loadedImages++;
                if (loadedImages >= images.length) {
                    document.querySelector('#topbar')?.setAttribute('data-initialized', 'true');
                    document.querySelector('#logo-sidebar')?.setAttribute('data-initialized', 'true');
                }
            };

            images.forEach(img => {
                if (img.complete) {
                    checkAllImagesLoaded();
                } else {
                    img.addEventListener('load', checkAllImagesLoaded);
                    img.addEventListener('error', checkAllImagesLoaded);
                }
            });
        });

        function hideGlobalLoader() {
            const loader = document.getElementById('global-loader');
            if (loader) {
                loader.classList.add('fade-out');
                setTimeout(function() {
                    loader.style.display = 'none';
                }, 300);
            }
        }

        window.addEventListener('load', function() {
            setTimeout(hideGlobalLoader, 300);
        });

        document.addEventListener('alpine:initialized', hideGlobalLoader);
        document.addEventListener('turbo:load', function() {
            hideGlobalLoader();

            if (typeof window.safeInitFlowbite === 'function') {
                window.safeInitFlowbite();
            }

            document.dispatchEvent(new CustomEvent('app:page-loaded'));
        });
    </script>
@endsection

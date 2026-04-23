@extends('layouts.base')

@section('sidebar')
    <x-wali-kelas.sidebar data-turbo-permanent id="sidebar"></x-wali-kelas.sidebar>
@endsection

@push('styles')
<style>
    #global-loader {
        position: fixed;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        display: flex;
        justify-content: center;
        align-items: center;
        background-color: white;
        z-index: 9999;
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
    <div id="global-loader">
        <div class="flex flex-col items-center">
            <svg class="animate-spin h-12 w-12 text-green-600 mb-3" viewBox="0 0 24 24" fill="none">
                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
            </svg>
            <p class="text-gray-600">Memuat aplikasi...</p>
        </div>
    </div>

    <div class="p-4 sm:ml-64">
        <div id="main">
            @if(session('success'))
                <x-alert type="success" :message="session('success')" />
            @endif

            @if(session('error'))
                <x-alert type="error" :message="session('error')" />
            @endif

            @yield('content')
        </div>
    </div>
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
            const images = document.querySelectorAll('#topbar img, #sidebar img');
            let loadedImages = 0;

            if (images.length === 0) {
                return;
            }

            const checkAllImagesLoaded = () => {
                loadedImages++;
                if (loadedImages >= images.length) {
                    document.querySelector('#topbar')?.setAttribute('data-initialized', 'true');
                    document.querySelector('#sidebar')?.setAttribute('data-initialized', 'true');
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

            if (typeof initFlowbite === 'function') {
                initFlowbite();
            }

            document.dispatchEvent(new CustomEvent('app:page-loaded'));
        });
    </script>
@endsection

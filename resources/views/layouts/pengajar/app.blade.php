@extends('layouts.base')

@section('role-meta')
    <meta name="turbo-root" content="true">
    <meta name="tahun-ajaran-id" content="{{ session('tahun_ajaran_id') }}">
@endsection

@section('sidebar')
    <x-pengajar.sidebar></x-pengajar.sidebar>
@endsection

@section('layout-content')
    <div class="p-4 sm:ml-64 min-h-screen bg-white relative">
        <div class="mt-16">
            <div id="main" data-turbo-frame="main" class="w-full">
                @php
                    $layoutSystemActiveTahunAjaran = $systemActiveTahunAjaran ?? null;
                    $layoutSelectedTahunAjaran = $selectedTahunAjaran ?? ($activeTahunAjaran ?? null);
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

                @if(session('tahun_ajaran_id') && $layoutSystemActiveTahunAjaran && session('tahun_ajaran_id') != $layoutSystemActiveTahunAjaran->id)
                    <div class="bg-blue-50 border-l-4 border-blue-400 p-4 mb-4">
                        <div class="flex">
                            <div class="flex-shrink-0">
                                <svg class="h-5 w-5 text-blue-400" viewBox="0 0 20 20" fill="currentColor">
                                    <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2v-3a1 1 0 00-1-1H9z" clip-rule="evenodd" />
                                </svg>
                            </div>
                            <div class="ml-3">
                                <p class="text-sm text-blue-700">
                                    <strong>Perhatian:</strong> Anda sedang melihat data untuk tahun ajaran <strong>{{ $layoutSelectedTahunAjaran?->tahun_ajaran ?? 'Tidak diketahui' }}</strong>, sedangkan tahun ajaran aktif adalah <strong>{{ $layoutSystemActiveTahunAjaran->tahun_ajaran }}</strong>.
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

                @yield('content')
            </div>
        </div>
    </div>

    <x-ai-chatbot />
@endsection

@section('role-scripts')
    <script>
        window.formChanged = false;

        window.addEventListener('beforeunload', (e) => {
            if (window.formChanged) {
                e.preventDefault();
                e.returnValue = 'Ada perubahan yang belum disimpan. Yakin ingin meninggalkan halaman?';
                return e.returnValue;
            }
        });

        document.addEventListener('turbo:before-visit', (event) => {
            if (window.formChanged) {
                if (!confirm('Ada perubahan yang belum disimpan. Yakin ingin meninggalkan halaman?')) {
                    event.preventDefault();
                } else {
                    window.formChanged = false;
                }
            }
        });

        document.addEventListener('turbo:submit-end', (event) => {
            if (event.detail.success) {
                window.formChanged = false;
            }
        });

        document.addEventListener('turbo:before-cache', () => {
            sessionStorage.setItem('formChanged', window.formChanged);
        });

        document.addEventListener('turbo:load', () => {
            window.formChanged = sessionStorage.getItem('formChanged') === 'true';
            sessionStorage.removeItem('formChanged');
        });
    </script>

    @if(Session::has('success'))
        <script>
            Swal.fire({
                icon: 'success',
                title: 'Berhasil!',
                text: "{{ Session::get('success') }}",
                showConfirmButton: false,
                timer: 1500
            });
        </script>
    @endif

    @if(Session::has('error'))
        <script>
            Swal.fire({
                icon: 'error',
                title: 'Gagal!',
                text: "{{ Session::get('error') }}",
                confirmButtonText: 'Ok'
            });
        </script>
    @endif
@endsection

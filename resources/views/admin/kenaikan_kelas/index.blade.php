@extends('layouts.app')

@section('title', 'Kenaikan Kelas dan Kelulusan')

@section('content')
<div class="p-4 bg-white rounded-lg shadow-md">
    <div class="flex justify-between items-center mb-6">
        <h2 class="text-2xl font-bold text-green-700">Kenaikan Kelas dan Kelulusan</h2>
    </div>

    @if(session('success'))
    <div class="bg-green-100 border-l-4 border-green-500 text-green-700 p-4 mb-6" role="alert">
        <p>{{ session('success') }}</p>
    </div>
    @endif

    @if(session('error') || isset($error))
    <div class="bg-green-100 border-l-4 border-green-500 text-green-700 p-4 mb-6" role="alert">
        <p>{{ session('error') ?? $error }}</p>
        
        @if(isset($error) && str_contains($error, 'Tidak ada tahun ajaran yang aktif'))
        <div class="mt-4">
            <a href="{{ route('tahun.ajaran.index') }}" class="inline-flex items-center px-4 py-2 border border-transparent text-sm leading-5 font-medium rounded-md text-white bg-green-600 hover:bg-green-500 focus:outline-none focus:border-green-700 focus:shadow-outline-green active:bg-green-700 transition duration-150 ease-in-out">
                <svg class="mr-2 h-5 w-5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6" />
                </svg>
                Kelola Tahun Ajaran
            </a>
        </div>
        @endif
    </div>
    @endif

    @if(isset($tahunAjaranAktif))
    <div class="mb-6">
        <h3 class="text-lg font-semibold text-gray-800 mb-3">Informasi Tahun Ajaran</h3>
        <div class="flex flex-col md:flex-row gap-4">
            <div class="bg-green-50 border border-green-200 rounded-lg p-4 flex-1">
                <h4 class="font-medium text-green-800">Tahun Ajaran Aktif</h4>
                <p class="text-lg font-semibold">{{ $tahunAjaranAktif->tahun_ajaran }}</p>
                <p>Semester {{ $tahunAjaranAktif->semester }} ({{ $tahunAjaranAktif->semester == 1 ? 'Ganjil' : 'Genap' }})</p>
            </div>
            
            @if(isset($tahunAjaranBaru))
            <div class="bg-green-50 border border-green-200 rounded-lg p-4 flex-1">
                <h4 class="font-medium text-green-800">Tahun Ajaran Tujuan</h4>
                <p class="text-lg font-semibold">{{ $tahunAjaranBaru->tahun_ajaran }}</p>
                <p>Semester {{ $tahunAjaranBaru->semester }} ({{ $tahunAjaranBaru->semester == 1 ? 'Ganjil' : 'Genap' }})</p>
            </div>
            @else
            {{-- Box Tahun Ajaran Tujuan - Logika berdasarkan semester --}}
            @if($tahunAjaranAktif->semester == 1)
                {{-- Semester Ganjil: Warna kuning dengan pesan --}}
                <div class="bg-yellow-50 border border-yellow-200 rounded-lg p-4 flex-1">
                    <h4 class="font-medium text-yellow-800">Tahun Ajaran Tujuan</h4>
                    <p class="text-yellow-700 mb-2">Anda berada di semester ganjil</p>
                    <p class="text-yellow-600 text-sm">Untuk membuat tahun ajaran berikutnya, lanjutkan ke semester genap terlebih dahulu.</p>
                </div>
            @else
                {{-- Semester Genap: Hijau dengan tombol ke copy --}}
                <div class="bg-green-50 border border-green-200 rounded-lg p-4 flex-1">
                    <h4 class="font-medium text-green-800">Tahun Ajaran Tujuan</h4>
                    <p class="text-gray-600 mb-2">Silakan buat tahun ajaran baru terlebih dahulu</p>
                    <a href="{{ route('tahun.ajaran.copy', $tahunAjaranAktif->id) }}" class="inline-flex items-center px-4 py-2 border border-transparent text-sm leading-5 font-medium rounded-md text-white bg-green-600 hover:bg-green-500 focus:outline-none focus:border-green-700 focus:shadow-outline-green active:bg-green-700 transition duration-150 ease-in-out">
                        <svg class="mr-2 h-5 w-5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z" />
                        </svg>
                        Buat Tahun Ajaran Baru dari {{ $tahunAjaranAktif->tahun_ajaran }}
                    </a>
                </div>
            @endif
            @endif
        </div>
    </div>
    @endif

    @if(isset($tahunAjaranBaru) && isset($kelasBaru) && !$kelasBaru->isEmpty())
    <div class="mt-6 bg-green-50 p-4 rounded-lg border border-green-200">
        <h3 class="text-lg font-semibold text-green-800 mb-2">Proses Kenaikan Kelas Massal</h3>
        <p class="text-green-700 mb-4">Proses ini akan memindahkan semua siswa dari tahun ajaran {{ $tahunAjaranAktif->tahun_ajaran }} ke kelas dengan tingkat yang lebih tinggi di tahun ajaran {{ $tahunAjaranBaru->tahun_ajaran }}.</p>
        
        @php
            // Check if there are any active students in the current academic year
            $hasActiveStudents = false;
            foreach($kelasAktif as $kelas) {
                if ($kelas->siswas->where('status', 'aktif')->count() > 0) {
                    $hasActiveStudents = true;
                    break;
                }
            }
        @endphp

        <div class="flex items-center">
            <button type="button" 
                    @click="$dispatch('open-confirm-modal')"
                    class="px-4 py-2 bg-green-600 text-white rounded-md hover:bg-green-700 focus:outline-none focus:ring-2 focus:ring-green-500 {{ !$hasActiveStudents ? 'opacity-50 cursor-not-allowed' : '' }}"
                    {{ !$hasActiveStudents ? 'disabled' : '' }}>
                Proses Kenaikan Kelas Otomatis
            </button>
            @if(!$hasActiveStudents)
                <span class="ml-2 text-sm text-red-600">* Tidak ada siswa aktif untuk diproses</span>
            @else
                <span class="ml-2 text-sm text-green-600">* Siswa kelas akhir (Kelas 6) akan ditandai lulus</span>
            @endif
        </div>
        
        <!-- Modal Konfirmasi -->
        <div x-data="{ open: false }" 
            @open-confirm-modal.window="open = true"
            x-show="open" 
            x-cloak
            class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50">
            <div class="bg-white rounded-lg p-6 max-w-md w-full">
                <h3 class="text-lg font-bold mb-4">Konfirmasi Kenaikan Kelas Massal</h3>
                <p class="mb-4">Anda akan memproses kenaikan kelas untuk seluruh siswa. Proses ini akan:</p>
                <ul class="list-disc pl-5 mb-4 text-sm">
                    <li>Memindahkan siswa kelas 1-5 ke kelas yang lebih tinggi</li>
                    <li>Menandai siswa kelas 6 sebagai lulus</li>
                    <li>Menyesuaikan penempatan kelas berdasarkan kapasitas</li>
                </ul>
                <p class="mb-4 text-black-600 font-medium">Apakah Anda yakin ingin melanjutkan?</p>
                <div class="flex justify-end">
                    <button @click="open = false" class="px-3 py-1 bg-gray-200 text-gray-800 rounded-md mr-2">Batal</button>
                    <form action="{{ route('admin.kenaikan-kelas.process-mass') }}" method="POST">
                        @csrf
                        <button type="submit" class="px-3 py-1 bg-green-600 text-white rounded-md">Proses Sekarang</button>
                    </form>
                </div>
            </div>
        </div>
    </div>
    @elseif(isset($tahunAjaranBaru) && isset($kelasBaru) && $kelasBaru->isEmpty())
    <div class="mt-6 bg-yellow-50 p-4 rounded-lg border border-yellow-200">
        <h3 class="text-lg font-semibold text-yellow-800 mb-2">Proses Kenaikan Kelas</h3>
        <p class="text-yellow-700 mb-2">Belum ada kelas yang dibuat di tahun ajaran {{ $tahunAjaranBaru->tahun_ajaran }}.</p>
        <p class="text-yellow-700 mb-4">Untuk melakukan kenaikan kelas, Anda perlu membuat kelas-kelas terlebih dahulu di tahun ajaran baru.</p>
        
        {{-- Tambahan rekomendasi untuk semester genap --}}
        @if($tahunAjaranAktif->semester == 2)
        <div class="bg-green-50 border border-green-200 rounded-lg p-3 mb-4">
            <p class="text-green-800 text-sm font-medium mb-1">💡 Rekomendasi:</p>
            <p class="text-green-700 text-sm mb-2">
                Gunakan fitur <strong>"Buat Tahun Ajaran Berikutnya"</strong> untuk setup otomatis yang lengkap.
            </p>
            <a href="{{ route('tahun.ajaran.copy', $tahunAjaranAktif->id) }}" class="inline-flex items-center px-3 py-1 border border-transparent text-xs leading-4 font-medium rounded text-white bg-green-600 hover:bg-green-500">
                Gunakan Fitur Otomatis
            </a>
        </div>
        @endif
        
        <div class="flex flex-col space-y-2">
            <div class="bg-white p-3 rounded-lg shadow-sm">
                <h4 class="font-medium text-gray-800 mb-2">Langkah-langkah membuat kelas di tahun ajaran baru:</h4>
                <ol class="list-decimal pl-5 text-sm space-y-1">
                    <li>Klik pada <a href="{{ route('tahun.ajaran.set-session', $tahunAjaranBaru->id) }}" class="text-green-600 hover:underline">tahun ajaran {{ $tahunAjaranBaru->tahun_ajaran }}</a> di menu dropdown tahun ajaran</li>
                    <li>Setelah itu, buat kelas baru di menu <a href="{{ route('kelas.index') }}" class="text-green-600 hover:underline">Manajemen Kelas</a></li>
                    <li>Kemudian kembali ke halaman ini untuk melanjutkan proses kenaikan kelas</li>
                </ol>
            </div>
        </div>
    </div>
    @endif

    @if(isset($tahunAjaranBaru) && isset($kelasBaru) && !$kelasBaru->isEmpty())
    <div class="mb-6">
        <h3 class="text-lg font-semibold text-gray-800 mb-3">Pilih Kelas</h3>
        <p class="text-gray-600 mb-4">Pilih kelas yang akan diproses untuk kenaikan kelas atau kelulusan.</p>
        
        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
            @foreach($kelasAktif as $kelas)
            <a href="{{ route('admin.kenaikan-kelas.show-siswa', $kelas->id) }}" 
               class="block p-4 bg-white border rounded-lg hover:bg-gray-50 transition duration-150 ease-in-out">
                <h4 class="font-medium text-lg">Kelas {{ $kelas->nomor_kelas }} {{ $kelas->nama_kelas }}</h4>
                <p class="text-gray-600">{{ $kelas->siswas->where('status', 'aktif')->count() }} Siswa</p>
                <p class="text-gray-500 text-sm">Wali Kelas: {{ $kelas->waliKelasName }}</p>
                <div class="mt-2">
                    <span class="inline-block px-2 py-1 text-xs {{ $kelas->nomor_kelas == 6 ? 'bg-green-100 text-green-800' : 'bg-green-100 text-green-800' }} rounded-full">
                        {{ $kelas->nomor_kelas == 6 ? 'Proses Kelulusan' : 'Proses Kenaikan Kelas' }}
                    </span>
                </div>
            </a>
            @endforeach
        </div>
    </div>
    @endif

    <div class="bg-gray-50 p-4 rounded-lg border border-gray-200 mb-6">
        <h3 class="text-lg font-semibold text-gray-800 mb-2">Petunjuk Proses Kenaikan Kelas dan Kelulusan</h3>
        <ol class="list-decimal pl-5 space-y-2">
            <li>Pastikan tahun ajaran baru sudah dibuat dan ada kelas tujuan di tahun ajaran baru.</li>
            <li>Pilih kelas yang akan diproses dari daftar kelas di atas.</li>
            <li>Pada halaman detail kelas, Anda dapat memilih siswa dan menentukan kenaikan kelas atau kelulusan.</li>
            <li>Siswa yang sudah dipindahkan ke kelas di tahun ajaran baru tidak akan muncul lagi dalam daftar.</li>
        </ol>
    </div>
</div>

<style>
    .tablinks.active {
        font-weight: bold;
    }
    .swal2-html-container {
        overflow-x: hidden;
    }
</style>

@if(session('mass_promotion'))
<script>
document.addEventListener('DOMContentLoaded', function() {
    // Siapkan statistik
    const stats = {
        promoted: {{ session('stats.promoted') }},
        graduated: {{ session('stats.graduated') }},
        notProcessed: {{ session('stats.notProcessed') }}
    };
    
    // Siapkan detail HTML
    let detailHtml = '<div class="text-center mb-4">';
    detailHtml += '<div class="grid grid-cols-3 gap-4 mb-4">';
    detailHtml += '<div class="bg-green-100 p-3 rounded-lg"><div class="text-green-700 text-lg font-bold">' + stats.promoted + '</div><div class="text-green-600 text-sm">Naik Kelas</div></div>';
    detailHtml += '<div class="bg-green-100 p-3 rounded-lg"><div class="text-green-700 text-lg font-bold">' + stats.graduated + '</div><div class="text-green-600 text-sm">Lulus</div></div>';
    detailHtml += '<div class="bg-red-100 p-3 rounded-lg"><div class="text-red-700 text-lg font-bold">' + stats.notProcessed + '</div><div class="text-red-600 text-sm">Tidak Diproses</div></div>';
    detailHtml += '</div>';
    
    // Tab style navigation
    detailHtml += '<div class="mb-4 border-b border-gray-200">';
    detailHtml += '<ul class="flex flex-wrap -mb-px text-sm font-medium text-center" id="kenaikanTabs" role="tablist">';
    
    if (stats.promoted > 0) {
        detailHtml += '<li class="mr-2" role="presentation">';
        detailHtml += '<button class="inline-block p-2 border-b-2 border-green-500 rounded-t-lg hover:bg-green-50 tablinks active" id="promoted-tab" data-target="promoted" type="button">Naik Kelas (' + stats.promoted + ')</button>';
        detailHtml += '</li>';
    }
    
    if (stats.graduated > 0) {
        detailHtml += '<li class="mr-2" role="presentation">';
        detailHtml += '<button class="inline-block p-2 border-b-2 border-transparent rounded-t-lg hover:bg-green-50 tablinks" id="graduated-tab" data-target="graduated" type="button">Lulus (' + stats.graduated + ')</button>';
        detailHtml += '</li>';
    }
    
    if (stats.notProcessed > 0) {
        detailHtml += '<li class="mr-2" role="presentation">';
        detailHtml += '<button class="inline-block p-2 border-b-2 border-transparent rounded-t-lg hover:bg-red-50 tablinks" id="notProcessed-tab" data-target="notProcessed" type="button">Tidak Diproses (' + stats.notProcessed + ')</button>';
        detailHtml += '</li>';
    }
    
    detailHtml += '</ul>';
    detailHtml += '</div>';
    
    // Tab content
    detailHtml += '<div class="tabcontent-container">';
    
    // Promoted tab content
    if (stats.promoted > 0) {
        detailHtml += '<div id="promoted" class="tabcontent block">';
        detailHtml += '<div class="max-h-60 overflow-y-auto py-2">';
        detailHtml += '<ul class="text-left">';
        
        @foreach(session('details.promoted') as $detail)
            detailHtml += '<li class="mb-2 flex items-start">' + 
                        '<span class="text-green-600 mr-1">↗</span> ' +
                        '<div><strong>{{ $detail['nama'] }}</strong><br>' + 
                        '{{ $detail['kelas_asal'] }} → {{ $detail['kelas_tujuan'] }}</div></li>';
        @endforeach
        
        detailHtml += '</ul>';
        detailHtml += '</div>';
        detailHtml += '</div>';
    }
    
    // Graduated tab content
    if (stats.graduated > 0) {
        detailHtml += '<div id="graduated" class="tabcontent hidden">';
        detailHtml += '<div class="max-h-60 overflow-y-auto py-2">';
        detailHtml += '<ul class="text-left">';
        
        @foreach(session('details.graduated') as $detail)
            detailHtml += '<li class="mb-2 flex items-start">' + 
                        '<span class="text-green-600 mr-1">🎓</span> ' +
                        '<div><strong>{{ $detail['nama'] }}</strong><br>' + 
                        'Dari {{ $detail['kelas_asal'] }} → Lulus</div></li>';
        @endforeach
        
        detailHtml += '</ul>';
        detailHtml += '</div>';
        detailHtml += '</div>';
    }
    
    // Not Processed tab content
    if (stats.notProcessed > 0) {
        detailHtml += '<div id="notProcessed" class="tabcontent hidden">';
        detailHtml += '<div class="max-h-60 overflow-y-auto py-2">';
        detailHtml += '<ul class="text-left">';
        
        @foreach(session('details.notProcessed') as $detail)
            detailHtml += '<li class="mb-2 flex items-start">' + 
                        '<span class="text-red-600 mr-1">⚠</span> ' +
                        '<div><strong>{{ $detail['nama'] }}</strong><br>' + 
                        '{{ $detail['kelas_asal'] }} → <span class="text-red-500">{{ $detail['alasan'] }}</span></div></li>';
        @endforeach
        
        detailHtml += '</ul>';
        detailHtml += '</div>';
        detailHtml += '</div>';
    }
    
    detailHtml += '</div>';
    detailHtml += '</div>'; // End of main container
    
    // Tampilkan SweetAlert dengan detail
    Swal.fire({
        title: 'Kenaikan Kelas Massal Berhasil',
        html: detailHtml,
        icon: 'success',
        width: 600,
        confirmButtonColor: '#10b981',
        confirmButtonText: 'OK'
    }).then(() => {
        // Event handler untuk tab
        document.querySelectorAll('.tablinks').forEach(tabLink => {
            tabLink.addEventListener('click', function(e) {
                const target = this.getAttribute('data-target');
                
                // Hide all tabcontent
                document.querySelectorAll('.tabcontent').forEach(tabContent => {
                    tabContent.classList.add('hidden');
                    tabContent.classList.remove('block');
                });
                
                // Remove active class from tabs
                document.querySelectorAll('.tablinks').forEach(tab => {
                    tab.classList.remove('active', 'border-green-500', 'border-green-500', 'border-red-500');
                    tab.classList.add('border-transparent');
                });
                
                // Show current tab
                document.getElementById(target).classList.remove('hidden');
                document.getElementById(target).classList.add('block');
                
                // Add active class to current tab
                this.classList.add('active');
                
                // Add proper border color based on tab
                if (target === 'promoted') {
                    this.classList.add('border-green-500');
                } else if (target === 'graduated') {
                    this.classList.add('border-green-500');
                } else if (target === 'notProcessed') {
                    this.classList.add('border-red-500');
                }
            });
        });
    });
});
</script>
@endif
@endsection
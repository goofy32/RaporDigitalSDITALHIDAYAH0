@extends('layouts.app')

@section('title', 'Dashboard Admin')

@section('content')

@php
    $profilSekolah = \App\Models\ProfilSekolah::first();
    $tahunAjaran = \App\Models\TahunAjaran::first();
@endphp

@if(!$profilSekolah || !$tahunAjaran)
<div class="hidden debug-info">PHP overallProgress: {{ $overallProgress ?? 'undefined' }}</div>

    <div class="bg-yellow-50 border-l-4 border-yellow-400 p-10 mb-6">
        <div class="flex">
            <div class="flex-shrink-0">
                <svg class="h-5 w-5 text-yellow-400" viewBox="0 0 20 20" fill="currentColor">
                    <path fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z" clip-rule="evenodd" />
                </svg>
            </div>
            <div class="ml-3">
                <h3 class="text-sm font-medium text-yellow-800">Persiapan Sistem</h3>
                <div class="mt-2 text-sm text-yellow-700">
                    <ul class="list-disc pl-5 space-y-1">
                        @if(!$profilSekolah)
                            <li>Anda belum mengisi data <a href="{{ route('profile.edit') }}" class="font-medium underline">Profil Sekolah</a>.</li>
                        @endif
                        @if(!$tahunAjaran)
                            <li>Anda belum membuat <a href="{{ route('tahun.ajaran.create') }}" class="font-medium underline">Tahun Ajaran</a>.</li>
                        @endif
                    </ul>
                </div>
            </div>
        </div>
    </div>
@endif
<div x-data="dashboard" data-dashboard-role="admin">
    <div x-data="notificationHandler">  
        <!-- Main Content Container -->
        <div class="flex flex-col lg:flex-row gap-4 mt-14">
            <!-- Statistics Grid - Takes 2/3 of the space -->
            <div class="lg:w-2/3">
                <div class="grid grid-cols-2 md:grid-cols-3 gap-3">
                    <!-- Siswa Card -->
                    <div class="rounded-lg bg-white border border-gray-200 shadow-sm overflow-hidden cursor-pointer hover:bg-gray-50 transition-colors" onclick="navigateTo('{{ route('student') }}')">
                        <div class="p-4">
                            <p class="text-2xl font-bold text-green-600">{{ $totalStudents }}</p>
                            <p class="text-sm text-green-600">Siswa</p>
                        </div>
                    </div>
                    
                    <!-- Guru Card -->
                    <div class="rounded-lg bg-white border border-gray-200 shadow-sm overflow-hidden cursor-pointer hover:bg-gray-50 transition-colors" onclick="navigateTo('{{ route('teacher') }}')">
                        <div class="p-4">
                            <p class="text-2xl font-bold text-green-600">{{ $totalTeachers }}</p>
                            <p class="text-sm text-green-600">Guru</p>
                        </div>
                    </div>
                    
                    <!-- Mata Pelajaran Card -->
                    <div class="rounded-lg bg-white border border-gray-200 shadow-sm overflow-hidden cursor-pointer hover:bg-gray-50 transition-colors" onclick="navigateTo('{{ route('subject.index') }}')">
                        <div class="p-4">
                            <p class="text-2xl font-bold text-green-600">{{ $totalSubjects }}</p>
                            <p class="text-sm text-green-600">Mata Pelajaran</p>
                        </div>
                    </div>
                    
                    <!-- Kelas Card -->
                    <div class="rounded-lg bg-white border border-gray-200 shadow-sm overflow-hidden cursor-pointer hover:bg-gray-50 transition-colors" onclick="navigateTo('{{ route('kelas.index') }}')">
                        <div class="p-4">
                            <p class="text-2xl font-bold text-green-600">{{ $totalClasses }}</p>
                            <p class="text-sm text-green-600">Kelas</p>
                        </div>
                    </div>
                    
                    <!-- Ekstrakurikuler Card -->
                    <div class="rounded-lg bg-white border border-gray-200 shadow-sm overflow-hidden cursor-pointer hover:bg-gray-50 transition-colors" onclick="navigateTo('{{ route('ekstra.index') }}')">
                        <div class="p-4">
                            <p class="text-2xl font-bold text-green-600">{{ $totalExtracurriculars }}</p>
                            <p class="text-sm text-green-600">Ekstrakurikuler</p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Information Section - Takes 1/3 of the space -->
            <div class="lg:w-1/3">
                <div class="flex items-center justify-between mb-3">
                    <div class="bg-green-600 text-white px-3 py-1.5 rounded-lg inline-block">
                        <span class="flex items-center text-sm">
                            <svg class="w-4 h-4 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"></path>
                            </svg>
                            Informasi
                        </span>
                    </div>
                    <button type="button" 
                            @click="showModal = true"
                            class="text-gray-400 bg-transparent hover:bg-gray-200 hover:text-gray-900 rounded-lg text-sm w-8 h-8 flex items-center justify-center">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"></path>
                        </svg>
                    </button>
                </div>

                <!-- Information Items -->
                <div class="h-[150px] overflow-y-auto">
                    <div class="relative pl-14">
                        <!-- Garis vertikal di tengah icon -->
                        <div class="absolute left-5 top-0 bottom-0 w-[2px] bg-gray-200"></div>
                        
                        <!-- Tampilan saat tidak ada notifikasi -->
                        <template x-for="item in $store.notification.items" :key="item.id">
                            <div class="mb-4 relative min-h-[80px] notification-item">
                                <!-- Icon on the timeline -->
                                <div class="absolute -left-12 top-3 w-10 h-10 bg-gray-200 rounded-full flex items-center justify-center z-10">
                                    <svg class="w-5 h-5 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"></path>
                                    </svg>
                                </div>
                                
                                <!-- Notification content with improved styling and visible timestamp -->
                                <div class="bg-white rounded-lg border shadow-sm p-3 notification-content">
                                    <div class="flex justify-between items-start">
                                        <div class="flex-1 min-w-0 pr-2">
                                            <!-- Target info -->
                                            <p class="text-xs text-gray-500 mb-1 truncate">
                                                <span class="font-medium">Untuk: </span>
                                                <span x-text="getTargetText(item)"></span>
                                            </p>
                                            
                                            <!-- Title with timestamp -->
                                            <div class="flex justify-between items-center mb-1">
                                                <h3 class="text-sm font-medium text-gray-900 truncate" x-text="item.title"></h3>
                                            </div>
                                            
                                            <!-- Content with no truncation - full text display -->
                                            <p class="text-xs text-gray-600 break-words whitespace-normal" x-text="item.content"></p>
                                        </div>
                                        
                                        <!-- Delete button -->
                                        <button type="button" 
                                                @click="$store.notification.deleteNotification(item.id)"
                                                class="text-red-500 hover:text-red-700 flex-shrink-0 ml-2">
                                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path>
                                            </svg>
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </template>
                    </div>
                </div>
            </div>
        </div>

        <!-- Dropdown Pilih Kelas -->
        <div class="mt-8">
            <label for="kelas" class="block text-sm font-medium text-gray-700">Pilih Kelas</label>
            <select id="kelas" 
                x-model="selectedKelas" 
                @change="fetchKelasProgress()"
                class="block w-full p-2 mt-1 rounded-lg border border-gray-300 shadow-sm focus:ring-green-500 focus:border-green-500">
                <option value="">Pilih kelas...</option>
                @foreach($kelas as $k)
                    <option value="{{ $k->id }}">Kelas {{ $k->nomor_kelas }} {{ $k->nama_kelas }}</option>
                @endforeach
            </select>
        </div>

        <!-- Charts Section -->
        <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mt-8">
            <!-- Chart Keseluruhan -->
                <div class="bg-white p-4 rounded-lg shadow">
                    <h3 class="text-lg font-semibold text-gray-800 mb-4">Progress Input Nilai Keseluruhan</h3>
                    <div class="flex flex-col items-center">
                        <div class="w-64 h-64 relative">
                            <canvas id="overallPieChart" data-progress="{{ number_format($overallProgress ?? 0, 2) }}"></canvas>
                        </div>
                    </div>
                </div>

            <!-- Chart Per Kelas -->
            <div class="bg-white p-4 rounded-lg shadow">
                <h3 class="text-lg font-semibold text-gray-800 mb-4">
                    Progress Input Nilai 
                    <span x-text="selectedKelasName"></span>
                </h3>
                <div class="flex flex-col items-center">
                    <div class="w-64 h-64 relative">
                        <canvas id="classProgressChart"></canvas>
                    </div>
                </div>
            </div>
        </div>

        <!-- Modal -->
        <div x-show="showModal" 
            class="fixed inset-0 z-50 overflow-y-auto"
            style="display: none;">
            <!-- Modal backdrop -->
            <div class="fixed inset-0 bg-black bg-opacity-50 transition-opacity" 
                x-show="showModal"
                x-transition:enter="ease-out duration-300"
                x-transition:enter-start="opacity-0"
                x-transition:enter-end="opacity-100"
                x-transition:leave="ease-in duration-200"
                x-transition:leave-start="opacity-100"
                x-transition:leave-end="opacity-0"
                @click="showModal = false"></div>

            <!-- Modal content -->
            <div class="relative flex min-h-screen items-center justify-center p-4">
                <div class="relative w-full max-w-md rounded-lg bg-white shadow-xl"
                    x-show="showModal"
                    x-transition:enter="ease-out duration-300"
                    x-transition:enter-start="opacity-0 translate-y-4 sm:translate-y-0 sm:scale-95"
                    x-transition:enter-end="opacity-100 translate-y-0 sm:scale-100"
                    x-transition:leave="ease-in duration-200"
                    x-transition:leave-start="opacity-100 translate-y-0 sm:scale-100"
                    x-transition:leave-end="opacity-0 translate-y-4 sm:translate-y-0 sm:scale-95">

                    <!-- Modal Header -->
                    <div class="flex items-center justify-between p-4 border-b">
                        <h3 class="text-xl font-semibold">Tambah Informasi</h3>
                        <button type="button" 
                                @click="showModal = false"
                                class="text-gray-400 bg-transparent hover:bg-gray-200 hover:text-gray-900 rounded-lg text-sm w-8 h-8 flex items-center justify-center">
                            <svg class="w-3 h-3" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 14 14">
                                <path stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="m1 1 6 6m0 0 6 6M7 7l6-6M7 7l-6 6"/>
                            </svg>
                        </button>
                    </div>

                    <!-- Modal Body -->
                    <div class="p-4">
                        <form @submit.prevent="submitNotification">
                            <div class="mb-4">
                                <label class="block mb-2 text-sm font-medium text-gray-900">Judul</label>
                                <input type="text" 
                                    x-model="notificationForm.title"
                                    class="bg-gray-50 border border-gray-300 text-gray-900 text-sm rounded-lg focus:ring-green-500 focus:border-green-500 block w-full p-2.5" 
                                    placeholder="Masukkan judul informasi" 
                                    required>
                            </div>

                            <div class="mb-4">
                                <label class="block mb-2 text-sm font-medium text-gray-900">Informasi untuk</label>
                                <select x-model="notificationForm.target"
                                    class="bg-gray-50 border border-gray-300 text-gray-900 text-sm rounded-lg focus:ring-green-500 focus:border-green-500 block w-full p-2.5" 
                                    required>
                                    <option value="">-- Pilih Target --</option>
                                    <option value="all">Semua</option>
                                    <option value="guru">Semua Guru</option>
                                    <option value="wali_kelas">Semua Wali Kelas</option>
                                    <option value="specific">Guru Tertentu</option>
                                </select>
                            </div>

                            <div class="mb-4">
                                <label class="block mb-2 text-sm font-medium text-gray-900">Isi</label>
                                <textarea x-model="notificationForm.content"
                                        x-on:input="if(notificationForm.content.length > 100) notificationForm.content = notificationForm.content.substring(0, 100)"
                                        maxlength="100"
                                        class="bg-gray-50 border border-gray-300 text-gray-900 text-sm rounded-lg focus:ring-green-500 focus:border-green-500 block w-full p-2.5" 
                                        rows="4" 
                                        placeholder="Masukkan isi informasi (maksimal 100 karakter)" 
                                        required></textarea>
                                <div class="flex justify-end mt-1">
                                    <span class="text-xs text-gray-500" x-text="`${notificationForm.content ? notificationForm.content.length : 0}/100 karakter`"></span>
                                </div>
                            </div>

                            <!-- Specific teachers container -->
                            <div x-show="notificationForm.target === 'specific'" class="mb-4">
                                <label class="block mb-2 text-sm font-medium text-gray-900">Pilih Guru</label>
                                <div class="max-h-40 overflow-y-auto border border-gray-200 rounded-lg p-2">
                                    @foreach($guru as $g)
                                    <div class="flex items-center mb-2" x-show="guruSearchTerm === '' || '{{ strtolower($g->nama) }}'.includes(guruSearchTerm.toLowerCase())">
                                        <input type="checkbox" 
                                            id="guru-{{ $g->id }}"
                                            value="{{ $g->id }}" 
                                            x-model="notificationForm.specific_users"
                                            class="w-4 h-4 text-green-600 border-gray-300 rounded focus:ring-green-500">
                                            <label for="guru-{{ $g->id }}" class="ml-2 text-sm text-gray-900 cursor-pointer flex-grow">
                                                {{ $g->nama }} 
                                                @if($g->jabatan == 'guru_wali')
                                                    <span class="text-xs text-gray-500">
                                                        (Wali Kelas 
                                                        @if($g->kelasWali)
                                                            {{ $g->kelasWali->nomor_kelas }} {{ $g->kelasWali->nama_kelas }}
                                                        @else
                                                            -
                                                        @endif
                                                        )
                                                    </span>
                                                @else
                                                    <span class="text-xs text-gray-500">
                                                        (<a href="{{ route('teacher.show', $g->id) }}" class="text-green-500 hover:underline" title="Lihat detail guru">
                                                            Lihat detail kelas mengajar
                                                        </a>)
                                                    </span>
                                                @endif
                                            </label>
                                    </div>
                                    @endforeach
                                </div>
                                <div class="mt-2 flex justify-between text-sm text-gray-500">
                                    <span x-text="notificationForm.specific_users.length + ' guru dipilih'"></span>
                                    <button type="button" 
                                            class="text-green-600 hover:underline" 
                                            @click="notificationForm.specific_users = []">
                                        Reset
                                    </button>
                                </div>
                            </div>

                            <!-- Success/Error Messages -->
                            <div x-show="successMessage" 
                                x-text="successMessage" 
                                class="mb-4 p-2 bg-green-100 text-green-700 rounded-lg"></div>
                            <div x-show="errorMessage" 
                                x-text="errorMessage" 
                                class="mb-4 p-2 bg-red-100 text-red-700 rounded-lg"></div>

                            <button type="submit" 
                                    class="w-full text-white bg-green-600 hover:bg-green-700 focus:ring-4 focus:ring-green-300 font-medium rounded-lg text-sm px-5 py-2.5 text-center">
                                <span x-show="!isSubmitting">Simpan</span>
                                <span x-show="isSubmitting" class="flex items-center justify-center">
                                    <svg class="animate-spin -ml-1 mr-2 h-4 w-4 text-white" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                                    </svg>
                                    Menyimpan...
                                </span>
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
/* Base notification item styles */
.notification-item {
  position: relative;
  margin-bottom: 1rem;
  min-height: 80px;
}

/* Container for notifications with dynamic height */
.notifications-container {
  max-height: 400px; /* Increased max height to show more content */
  overflow-y: auto;
  scrollbar-width: thin;
  padding-right: 4px;
}

/* Word breaking for long text */
.break-words {
  word-wrap: break-word;
  overflow-wrap: break-word;
  word-break: break-word; /* Less aggressive than break-all */
  hyphens: auto;
}

/* Keep truncation for titles and headers */
.truncate {
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

/* Notification content should expand as needed */
.notification-content {
  width: 100%;
}

/* Force min-width zero on content element */
.flex-1 {
  flex: 1 1 0%;
  min-width: 0;
}

/* Add some minimal bottom spacing to the text */
.notification-content p.text-gray-600 {
  margin-bottom: 2px;
  line-height: 1.3;
}
/* Make timestamp more visible */
.timestamp {
  font-size: 0.7rem;
  color: #6B7280;
  white-space: nowrap;
  margin-left: 0.5rem;
  padding: 0.125rem 0.375rem;
  background-color: #F3F4F6;
  border-radius: 0.25rem;
}

/* Timestamp container - ensure proper alignment */
.title-timestamp-container {
  display: flex;
  justify-content: space-between;
  align-items: center;
  width: 100%;
  margin-bottom: 0.25rem;
}

/* Title with truncation when needed */
.title-timestamp-container h3 {
  flex: 1;
  min-width: 0;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

/* Additional container styles */
.notifications-container {
  max-height: 400px;
  overflow-y: auto;
  scrollbar-width: thin;
  padding-right: 4px;
}

/* Notification item styling */
.notification-item {
  position: relative;
  margin-bottom: 1rem;
  min-height: 80px;
}

/* Content text */
.notification-content p.text-gray-600 {
  margin-top: 0.25rem;
  line-height: 1.3;
  word-wrap: break-word;
  overflow-wrap: break-word;
  word-break: break-word;
}

</style>
        

@push('scripts')
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
// Explicitly output the PHP value
// console.log("PHP overallProgress direct output: {{ $overallProgress ?? 0 }}");

// Set the global variable
window.overallProgress = {{ number_format($overallProgress ?? 0, 2) }};
// console.log("Set window.overallProgress to:", window.overallProgress);
</script>
<script>
// Global variables for charts
let overallChart, classChart;
let kelasProgress = 0;

function navigateTo(url) {
    window.location.href = url;
}

function destroyCharts() {
    try {
        if (overallChart instanceof Chart) {
            overallChart.destroy();
        }
        if (classChart instanceof Chart) {
            classChart.destroy();
        }
    } catch (error) {
        console.error('Error destroying charts:', error);
    }
    
    // Reset variables to prevent reference errors
    overallChart = null;
    classChart = null;
}


function initCharts() {
    destroyCharts();
    
    // Get the progress value, using the DOM attribute as backup
    const chartContainer = document.getElementById('overallPieChart');
    const domProgress = chartContainer ? chartContainer.dataset.progress : null;
    
    // Try window value first, then DOM attribute, then default to 0
    const safeOverallProgress = typeof window.overallProgress !== 'undefined' ? 
        parseFloat(window.overallProgress) : (domProgress ? parseFloat(domProgress) : 0);
    
    // console.log('Using overall progress value in initCharts:', safeOverallProgress);
    
    
    // Chart configuration options
    const defaultOptions = {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
            legend: {
                position: 'bottom'
            },
            tooltip: {
                callbacks: {
                    label: function(context) {
                        return context.label + ': ' + Math.round(context.raw) + '%';
                    }
                }
            }
        }
    };

    // Initialize Overall Progress Chart
    const overallCtx = document.getElementById('overallPieChart');
    if (overallCtx && overallCtx.getContext) {
        try {
            // console.log('Creating overall chart with progress:', safeOverallProgress);
            overallChart = new Chart(overallCtx.getContext('2d'), {
                type: 'doughnut',
                data: {
                    labels: ['Selesai', 'Belum'],
                    datasets: [{
                        data: [
                            Math.min(100, Math.max(0, safeOverallProgress)), 
                            Math.min(100, Math.max(0, 100 - safeOverallProgress))
                        ],
                        backgroundColor: ['rgb(34, 197, 94)', 'rgb(229, 231, 235)'],
                        borderWidth: 0
                    }]
                },
                options: {
                    ...defaultOptions,
                    cutout: '60%',
                },
                plugins: [{
                    id: 'centerText',
                    afterDraw: function(chart) {
                        const width = chart.width;
                        const height = chart.height;
                        const ctx = chart.ctx;
                        
                        ctx.restore();
                        const fontSize = (height / 114).toFixed(2);
                        ctx.font = fontSize + 'em sans-serif';
                        ctx.textBaseline = 'middle';
                        
                        const text = Math.round(safeOverallProgress) + '%';
                        const textX = Math.round((width - ctx.measureText(text).width) / 2);
                        const textY = height / 2;

                        ctx.fillStyle = '#1F2937';
                        ctx.fillText(text, textX, textY);
                        ctx.save();
                    }
                }]
            });
            // console.log('Overall chart created successfully');
        } catch (e) {
            // console.error('Error creating overall chart:', e);
        }
    } else {
        // console.error('Could not get chart context from overallPieChart element');
    }

    // Initialize Class Progress Chart
    const classCtx = document.getElementById('classProgressChart');
    if (classCtx && classCtx.getContext) {
        try {
            classChart = new Chart(classCtx.getContext('2d'), {
                type: 'doughnut',
                data: {
                    labels: ['Selesai', 'Belum'],
                    datasets: [{
                        data: [0, 100], // Start with 0% progress
                        backgroundColor: ['rgb(34, 197, 94)', 'rgb(229, 231, 235)'],
                        borderWidth: 0
                    }]
                },
                options: {
                    ...defaultOptions,
                    cutout: '60%',
                },
                plugins: [{
                    id: 'centerText',
                    afterDraw: function(chart) {
                        const width = chart.width;
                        const height = chart.height;
                        const ctx = chart.ctx;
                        
                        ctx.restore();
                        const fontSize = (height / 114).toFixed(2);
                        ctx.font = fontSize + 'em sans-serif';
                        ctx.textBaseline = 'middle';
                        
                        const text = Math.round(kelasProgress) + '%';
                        const textX = Math.round((width - ctx.measureText(text).width) / 2);
                        const textY = height / 2;

                        ctx.fillStyle = '#1F2937';
                        ctx.fillText(text, textX, textY);
                        ctx.save();
                    }
                }]
            });
        } catch (e) {
            console.error('Error creating class chart:', e);
        }
    }
}

function updateClassChart(progress) {
    // Ensure progress is a valid number
    kelasProgress = !isNaN(parseFloat(progress)) ? 
        Math.min(100, Math.max(0, parseFloat(progress))) : 0;
    
    // console.log('Updating class chart with progress:', kelasProgress);
    
    if (classChart) {
        classChart.data.datasets[0].data = [kelasProgress, 100 - kelasProgress];
        classChart.update();
    }
}

// Alpine.js Initialization
// Event Listeners
document.addEventListener('DOMContentLoaded', () => {
    // console.log('DOM loaded, overall progress from window:', window.overallProgress);
    
    // Backup the value to a data attribute for Turbo navigation
    const chartContainer = document.getElementById('overallPieChart');
    if (chartContainer && window.overallProgress !== undefined) {
        chartContainer.dataset.progress = window.overallProgress;
        console.log('Stored progress in data attribute:', chartContainer.dataset.progress);
    }
    
    setTimeout(() => {
        initCharts();
    }, 300);
});


// Cleanup
document.addEventListener('turbo:before-cache', () => {
    destroyCharts();
});

// Handle Turbo navigation
document.addEventListener('turbo:load', () => {
    if (window.location.pathname.includes('/admin/dashboard')) {
        // console.log('Dashboard loaded via Turbo');
        
        // Try to recover the progress value from the DOM
        const chartContainer = document.getElementById('overallPieChart');
        if (chartContainer && chartContainer.dataset.progress) {
            window.overallProgress = parseFloat(chartContainer.dataset.progress);
            // console.log('Recovered progress from DOM:', window.overallProgress);
        }
        
        setTimeout(() => {
            destroyCharts();
            initCharts();
        }, 300);
    }
});

// Global function for event binding
function fetchKelasProgress() {
    const selectedKelas = document.getElementById('kelas')?.value;
    if (!selectedKelas) {
        // Update the selected kelas name display
        const dashboardEl = document.querySelector('[x-data="dashboard"]');
        if (dashboardEl && dashboardEl.__x) {
            dashboardEl.__x.$data.selectedKelasName = 'Per Kelas';
        }
        updateClassChart(0);
        return;
    }
    
    // Update selected kelas name
    const kelasSelect = document.getElementById('kelas');
    if (kelasSelect) {
        const dashboardEl = document.querySelector('[x-data="dashboard"]');
        if (dashboardEl && dashboardEl.__x) {
            const selectedOption = kelasSelect.options[kelasSelect.selectedIndex];
            dashboardEl.__x.$data.selectedKelasName = selectedOption ? selectedOption.text : 'Per Kelas';
        }
    }
    
    // Add timestamp to prevent caching
    const timestamp = new Date().getTime();
    fetch(`/admin/kelas-progress/${selectedKelas}?_=${timestamp}`, {
        headers: {
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
            'Accept': 'application/json',
            'X-Requested-With': 'XMLHttpRequest'
        }
    })
    .then(response => {
        if (!response.ok) {
            throw new Error(`HTTP error! status: ${response.status}`);
        }
        return response.json();
    })
    .then(data => {
        // console.log('Fetched kelas progress data:', data);
        if (data.success && !isNaN(data.progress)) {
            updateClassChart(data.progress);
        } else {
            console.error('Invalid progress data:', data);
            updateClassChart(0);
        }
    })
    .catch(error => {
        console.error('Error fetching progress:', error);
        updateClassChart(0);
    });
}

function initModal() {
    const modal = document.getElementById('addInfoModal');
    const openButtons = document.querySelectorAll('[data-modal-target="addInfoModal"]');
    const closeButtons = document.querySelectorAll('[data-modal-hide="addInfoModal"]');
    const modalForm = modal?.querySelector('form');

    // Open modal
    openButtons.forEach(button => {
        button.addEventListener('click', () => {
            modal.classList.remove('hidden');
            modal.classList.add('flex');
        });
    });

    // Close modal
    closeButtons.forEach(button => {
        button.addEventListener('click', () => {
            modal.classList.add('hidden');
            modal.classList.remove('flex');
        });
    });

    // Close on outside click
    modal?.addEventListener('click', (e) => {
        if (e.target === modal) {
            modal.classList.add('hidden');
            modal.classList.remove('flex');
        }
    });

    // Handle form submission
    modalForm?.addEventListener('submit', async (e) => {
        e.preventDefault();
        
        const submitButton = modalForm.querySelector('button[type="submit"]');
        submitButton.disabled = true;
        submitButton.innerHTML = `
            <svg class="animate-spin h-5 w-5 mr-3" viewBox="0 0 24 24">
                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
            </svg>
            Menyimpan...
        `;

        const formData = new FormData(modalForm);
        const data = {
            title: formData.get('title'),
            target: formData.get('target'),
            content: formData.get('content')
        };

        if (formData.get('target') === 'specific') {
            data.specific_users = Array.from(formData.getAll('specific_users[]'))
                .map(id => parseInt(id, 10));
        }

        try {
            const response = await fetch('/admin/information', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                    'Accept': 'application/json'
                },
                body: JSON.stringify(data)
            });

            const result = await response.json();

            if (result.success) {
                modalForm.reset();
                modal.classList.add('hidden');
                modal.classList.remove('flex');
                updateInformationSection();
                showNotification('Informasi berhasil ditambahkan', 'success');
            } else {
                showNotification('Gagal menambahkan informasi', 'error');
            }
        } catch (error) {
            console.error('Error:', error);
            showNotification('Terjadi kesalahan', 'error');
        } finally {
            submitButton.disabled = false;
            submitButton.innerHTML = 'Simpan';
        }
    });
}

function deleteInformation(id) {
    if (confirm('Apakah Anda yakin ingin menghapus informasi ini?')) {
        fetch(`/admin/information/${id}`, {
            method: 'DELETE',
            headers: {
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                'Accept': 'application/json'
            }
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                updateInformationSection();
                showNotification('Informasi berhasil dihapus', 'success');
            } else {
                showNotification('Gagal menghapus informasi', 'error');
            }
        })
        .catch(error => {
            console.error('Error:', error);
            showNotification('Terjadi kesalahan', 'error');
        });
    }
}

// Update information section
function updateInformationSection() {
    fetch('/admin/information/list', {
        headers: {
            'Accept': 'application/json'
        }
    })
    .then(response => response.json())
    .then(data => {
        const container = document.querySelector('.information-items');
        if (container && data.items) {
            container.innerHTML = data.items.map(item => `
                <div class="mb-4 relative">
                    <div class="absolute -left-8 w-6 h-6 bg-gray-200 rounded-full flex items-center justify-center">
                        <svg class="w-3 h-3 text-gray-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"></path>
                        </svg>
                    </div>
                    <div class="bg-white rounded-lg border shadow-sm p-3">
                        <div class="flex justify-between items-start">
                            <div>
                                <h3 class="text-sm font-medium">${item.title}</h3>
                                <p class="text-xs text-gray-600">${item.content}</p>
                            </div>
                            <button class="text-red-500 hover:text-red-700" onclick="deleteInformation(${item.id})">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path>
                                </svg>
                            </button>
                        </div>
                    </div>
                </div>
            `).join('');
        }
    });
}

// Notification handler
function showNotification(message, type = 'success') {
    const notification = document.createElement('div');
    notification.className = `fixed bottom-4 right-4 px-6 py-3 rounded-lg text-white ${
        type === 'success' ? 'bg-green-600' : 'bg-red-600'
    }`;
    notification.textContent = message;
    
    document.body.appendChild(notification);
    
    setTimeout(() => {
        notification.remove();
    }, 3000);
}

// Initialize modal when page loads
document.addEventListener('DOMContentLoaded', initModal);
document.addEventListener('turbo:load', initModal);
</script>
@endpush
@endsection

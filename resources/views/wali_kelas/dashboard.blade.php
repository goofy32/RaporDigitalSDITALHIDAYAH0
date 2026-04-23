@extends('layouts.wali_kelas.app')

@section('title', 'Dashboard Wali Kelas')

@section('content') 
<div x-data="dashboard" data-dashboard-role="wali-kelas" x-init="$store.notification.fetchNotifications(); $store.notification.startAutoRefresh()">

    <!-- Statistics Grid -->
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-4 mt-14">
        <!-- Left Section - Stats (col-span-2) -->
        <div class="lg:col-span-2">
            <!-- Top Row - 2 Cards -->
            <div class="grid grid-cols-2 gap-4 mb-4">
                <!-- Siswa Card -->
                <div class="rounded-lg bg-white border border-gray-200 shadow-sm overflow-hidden cursor-pointer hover:bg-gray-50" onclick="navigateTo('{{ route('wali_kelas.student.index') }}')">
                    <div class="p-4">
                        <p class="text-2xl font-bold text-green-600">{{ $totalSiswa }}</p>
                        <p class="text-sm text-green-600">Siswa</p>
                    </div>
                </div>

                <!-- Absensi Card -->
                <div class="rounded-lg bg-white border border-gray-200 shadow-sm overflow-hidden cursor-pointer hover:bg-gray-50" onclick="navigateTo('{{ route('wali_kelas.absence.index') }}')">
                    <div class="p-4">
                        <p class="text-2xl font-bold text-green-600">{{ $totalAbsensi ?? 0 }}</p>
                        <p class="text-sm text-green-600">Absensi</p>
                    </div>
                </div>
            </div>

            <!-- Bottom Row - 1 Card -->
            <div class="grid grid-cols-2 gap-4">
                <!-- Mata Pelajaran Card -->
                <div class="rounded-lg bg-white border border-gray-200 shadow-sm overflow-hidden cursor-pointer hover:bg-gray-50">
                    <div class="p-4">
                        <p class="text-2xl font-bold text-green-600">{{ $totalMapel }}</p>
                        <p class="text-sm text-green-600">Mata Pelajaran</p>
                    </div>
                </div>

                <!-- Ekstrakurikuler Card -->
                <div class="rounded-lg bg-white border border-gray-200 shadow-sm overflow-hidden cursor-pointer hover:bg-gray-50" onclick="navigateTo('{{ route('wali_kelas.ekstrakurikuler.index') }}')">
                    <div class="p-4">
                        <p class="text-2xl font-bold text-green-600">{{ $totalEkskul }}</p>
                        <p class="text-sm text-green-600">Ekstrakurikuler</p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Right Section - Information -->
        <div class="lg:col-span-1">
            <div class="flex items-center justify-between mb-3">
                <div class="bg-green-600 text-white px-3 py-1.5 rounded-lg inline-block">
                    <span class="flex items-center text-sm">
                        <svg class="w-4 h-4 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"></path>
                        </svg>
                        Informasi
                    </span>
                </div>
            </div>

            <!-- Information Items -->
            <div class="h-[150px] overflow-y-auto">
                <div class="relative pl-14">
                    <!-- Vertical line behind icons -->
                    <div class="absolute left-5 top-0 bottom-0 w-[2px] bg-gray-200"></div>
                    
                    <!-- Notification list -->
                    <template x-for="item in $store.notification.items" :key="item.id">
                        <div class="mb-4 relative min-h-[80px] notification-item">
                            <!-- Envelope icon on the vertical line -->
                            <div class="absolute -left-12 top-3 w-10 h-10 bg-gray-200 rounded-full flex items-center justify-center z-10">
                                <svg class="w-5 h-5 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"></path>
                                </svg>
                            </div>
                            
                            <!-- Notification content with visible timestamp -->
                            <div class="bg-white rounded-lg border shadow-sm p-3 notification-content">
                                <div class="flex justify-between items-start">
                                    <div class="flex-1 min-w-0 pr-2">
                                        <!-- Title row with timestamp -->
                                        <div class="flex justify-between items-center mb-1">
                                            <h3 class="text-sm font-medium text-gray-900 truncate" x-text="item.title"></h3>
                                        </div>
                                        
                                        <!-- Content with no truncation -->
                                        <p class="text-xs text-gray-600 break-words whitespace-normal" x-text="item.content"></p>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </template>
                </div>
            </div>
        </div>
    </div>

    <!-- Dropdown and Charts Section -->
    <div class="mt-8">
        <label for="subject" class="block text-sm font-medium text-gray-700">Pilih Mata Pelajaran</label>
        <select id="subject" 
            x-model="selectedSubject" 
            @change="fetchSubjectProgress"
            class="block w-full p-2 mt-1 rounded-lg border border-gray-300 shadow-sm focus:ring-green-500 focus:border-green-500">
            <option value="">Pilih mata pelajaran...</option>
            @if(isset($kelas) && $kelas)
                @foreach($mataPelajarans as $mapel)
                    <option value="{{ $mapel->id }}">{{ $mapel->nama_pelajaran }} ({{ $mapel->guru ? $mapel->guru->nama : 'Tidak ada guru' }})</option>
                @endforeach
            @else
                <option disabled>Tidak ada mata pelajaran tersedia</option>
            @endif
        </select>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mt-8">
            <!-- Chart Keseluruhan -->
            <div class="bg-white p-4 rounded-lg shadow">
                <h3 class="text-lg font-semibold text-gray-800 mb-4">Progress Input Nilai Keseluruhan</h3>
                <div class="flex flex-col items-center">
                    <div class="w-64 h-64 relative">
                        <canvas id="overallPieChart"></canvas>
                    </div>
                </div>
            </div>
            
            <!-- Chart Per Mata Pelajaran -->
            <div class="bg-white p-4 rounded-lg shadow">
                <h3 class="text-lg font-semibold text-gray-800 mb-4">Progress Input Nilai Per Mata Pelajaran</h3>
                <div class="flex flex-col items-center">
                    <div class="w-64 h-64 relative">
                        <canvas id="classProgressChart"></canvas>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
/* Container for notifications with dynamic height */
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

/* Word breaking for long text */
.break-words {
  word-wrap: break-word;
  overflow-wrap: break-word;
  word-break: break-word;
  hyphens: auto;
}

/* Keep truncation for titles */
.truncate {
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

/* Notification content */
.notification-content {
  width: 100%;
}

/* Flex child */
.flex-1 {
  flex: 1 1 0%;
  min-width: 0;
}

/* Content paragraph */
.notification-content p.text-gray-600 {
  margin-bottom: 2px;
  line-height: 1.3;
}

/* Custom scrollbar styling */
.notifications-container::-webkit-scrollbar {
  width: 4px;
}

.notifications-container::-webkit-scrollbar-thumb {
  background-color: rgba(156, 163, 175, 0.5);
  border-radius: 2px;
}

/* Make sure timestamps are properly displayed */
.text-gray-500.ml-2 {
  white-space: nowrap;
  font-size: 0.7rem;
}
</style>

@push('scripts')
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
let overallChart, classChart;
let kelasProgress = 0;
const WALIKELAS_DASHBOARD_KEY = 'walikelasDashboardLoaded';

function destroyCharts() {
    if (overallChart) {
        overallChart.destroy();
        overallChart = null;
    }
    if (classChart) {
        classChart.destroy();
        classChart = null;
    }
}

function initCharts() {
    const defaultOptions = {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
            legend: {
                position: 'bottom'
            },
            tooltip: {
                enabled: false
            }
        }
    };

    // Overall Progress Chart
    const overallCtx = document.getElementById('overallPieChart')?.getContext('2d');
    if (overallCtx) {
        const existingOverallChart = Chart.getChart('overallPieChart');
        if (existingOverallChart) {
            existingOverallChart.destroy();
        }

        // Get a safe progress value (0-100)
        const safeProgress = Math.min(100, Math.max(0, {{ $overallProgress ?? 0 }}));
        
        overallChart = new Chart(overallCtx, {
            type: 'doughnut',
            data: {
                labels: ['Selesai', 'Belum'],
                datasets: [{
                    data: [safeProgress, 100 - safeProgress],
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
                    
                    const text = Math.round(safeProgress) + '%';
                    const textX = Math.round((width - ctx.measureText(text).width) / 2);
                    const textY = height / 2;

                    ctx.fillStyle = '#1F2937';
                    ctx.fillText(text, textX, textY);
                    ctx.save();
                }
            }]
        });
    }

    // Class Progress Chart (Initially empty)
    const classCtx = document.getElementById('classProgressChart')?.getContext('2d');
    if (classCtx) {
        const existingClassChart = Chart.getChart('classProgressChart');
        if (existingClassChart) {
            existingClassChart.destroy();
        }

        classChart = new Chart(classCtx, {
            type: 'doughnut',
            data: {
                labels: ['Selesai', 'Belum'],
                datasets: [{
                    data: [0, 100],
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
    }
}

function updateClassChart(progress) {
    kelasProgress = Math.min(100, Math.max(0, progress));
    if (classChart) {
        classChart.data.datasets[0].data = [kelasProgress, 100 - kelasProgress];
        classChart.update();
    }
}

function fetchSubjectProgress(subjectId) {
    const selectedSubject = subjectId || document.getElementById('subject')?.value;
    if (selectedSubject) {
        const url = `/wali-kelas/mata-pelajaran-progress/${selectedSubject}`;
        console.log('Fetching progress from:', url);
        
        fetch(url)
            .then(response => {
                console.log('Response status:', response.status);
                if (!response.ok) {
                    throw new Error(`HTTP error! status: ${response.status}`);
                }
                return response.json();
            })
            .then(data => {
                console.log('Progress data received:', data);
                updateClassChart(data.progress);
            })
            .catch(error => {
                console.error('Error fetching subject progress:', error);
                updateClassChart(0);
            });
    } else {
        updateClassChart(0);
    }
}

// Event handlers untuk initialization
// Function untuk mengecek apakah di halaman dashboard
function isDashboardPage() {
    return window.location.pathname.includes('/wali-kelas/dashboard');
}

// Function untuk menangani inisialisasi dashboard
function handleDashboardInit() {
    if (isDashboardPage()) {
        const isLoaded = sessionStorage.getItem(WALIKELAS_DASHBOARD_KEY);
        if (!isLoaded) {
            sessionStorage.setItem(WALIKELAS_DASHBOARD_KEY, 'true');
            window.location.reload();
        } else {
            destroyCharts();
            initCharts();
        }
    }
}

// Event Listeners untuk navigasi dan reload
document.addEventListener('DOMContentLoaded', handleDashboardInit);
document.addEventListener('turbo:load', handleDashboardInit);
document.addEventListener('turbo:render', handleDashboardInit);

document.addEventListener('turbo:load', () => {
    handleDashboardInit();
});

document.addEventListener('turbo:render', () => {
    handleDashboardInit();
});

document.addEventListener('turbo:visit', () => {
    if (!isDashboardPage()) {
        sessionStorage.removeItem(WALIKELAS_DASHBOARD_KEY);
    }
});

// Cleanup saat navigasi
document.addEventListener('turbo:before-cache', () => {
    destroyCharts();
});

document.addEventListener('turbo:before-cache', function() {
    if (Chart.instances) {
        Object.values(Chart.instances).forEach(chart => {
            chart.destroy();
        });
    }
});

// Handle unload
window.addEventListener('unload', () => {
    destroyCharts();
    if (!isDashboardPage()) {
        sessionStorage.removeItem(WALIKELAS_DASHBOARD_KEY);
    }
});

// Inisialisasi awal
if (document.readyState === 'complete') {
    handleDashboardInit();
} else {
    window.addEventListener('load', handleDashboardInit);
}

// Function untuk navigasi
function navigateTo(url) {
    window.location.href = url;
}
</script>
<script>
    // Make sure this variable is declared globally
    window.overallProgress = {{ $overallProgress ?? 0 }};
    console.log("Overall progress from server:", window.overallProgress);
</script>
@endpush
@endsection

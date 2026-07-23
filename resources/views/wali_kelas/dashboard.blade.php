@extends('layouts.wali_kelas.app')

@section('title', 'Dashboard Wali Kelas')

@section('content') 
<div x-data="dashboard" data-dashboard-role="wali-kelas" data-page="wali-dashboard" data-overall-progress="{{ $overallProgress ?? 0 }}" data-progress-endpoint="{{ url('/wali-kelas/mata-pelajaran-progress') }}" x-init="$store.notification.bootstrap()">

    <!-- Statistics Grid -->
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-4">
        <!-- Left Section - Stats (col-span-2) -->
        <div class="lg:col-span-2">
            <!-- Top Row - 2 Cards -->
            <div class="grid grid-cols-1 gap-4 mb-4 sm:grid-cols-2">
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
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
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
            <x-notification-panel />
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
@endpush
@endsection

@extends('layouts.wali_kelas.app')

@section('title', 'Dashboard Wali Kelas')

@section('content') 
<div x-data="dashboard" data-dashboard-role="wali-kelas" data-page="wali-dashboard" data-overall-progress="{{ $overallProgress ?? 0 }}" data-progress-endpoint="{{ url('/wali-kelas/mata-pelajaran-progress') }}" x-init="$store.notification.fetchNotifications(); $store.notification.fetchUnreadCount(); $store.notification.startAutoRefresh()">

    <!-- Statistics Grid -->
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-4">
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
                <div class="relative bg-green-600 text-white px-3 py-1.5 rounded-lg inline-block">
                    <span class="flex items-center text-sm">
                        <svg class="w-4 h-4 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"></path>
                        </svg>
                        Informasi
                    </span>
                    <span x-show="$store.notification.unreadCount > 0"
                          x-text="$store.notification.unreadCount > 99 ? '99+' : $store.notification.unreadCount"
                          class="absolute -top-2 -right-2 flex h-5 min-w-[20px] items-center justify-center rounded-full bg-red-600 px-1 text-xs font-bold text-white">
                    </span>
                </div>
                <button 
                    @click="$store.notification.toggleHideRead()"
                    :title="$store.notification.hideRead ? 'Tampilkan semua' : 'Sembunyikan yang sudah dibaca'"
                    class="p-1 rounded hover:bg-gray-100 text-gray-400 hover:text-gray-600 transition-colors">
                    <svg x-show="!$store.notification.hideRead"
                        class="w-4 h-4" fill="none" stroke="currentColor" 
                        viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" 
                            stroke-width="2" 
                            d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
                        <path stroke-linecap="round" stroke-linejoin="round" 
                            stroke-width="2" 
                            d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 
                               8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 
                               7-4.477 0-8.268-2.943-9.542-7z"/>
                    </svg>
                    <svg x-show="$store.notification.hideRead"
                        class="w-4 h-4" fill="none" stroke="currentColor" 
                        viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" 
                            stroke-width="2" 
                            d="M13.875 18.825A10.05 10.05 0 0112 19c-4.478 
                               0-8.268-2.943-9.542-7a9.97 9.97 0 011.563-3.029
                               m5.858.908a3 3 0 114.243 4.243M9.878 9.878l4.242 
                               4.242M9.88 9.88l-3.29-3.29m7.532 7.532l3.29 3.29
                               M3 3l3.59 3.59m0 0A9.953 9.953 0 0112 5c4.478 0 
                               8.268 2.943 9.542 7a10.025 10.025 0 01-4.132 
                               5.411m0 0L21 21"/>
                    </svg>
                </button>
            </div>

            <!-- Information Items -->
            <div class="h-[150px] overflow-y-auto notifications-container">
                <div class="relative pl-14">
                    <!-- Vertical line behind icons -->
                    <div class="absolute left-5 top-0 bottom-0 w-[2px] bg-gray-200"></div>
                    
                    <!-- Notification list -->
                    <template x-for="item in $store.notification.visibleItems" :key="item.id">
                        <div class="mb-4 relative min-h-[80px] notification-item cursor-pointer"
                             @click="$store.notification.markAsRead(item.id)">
                            <!-- Envelope icon on the vertical line -->
                            <div class="absolute -left-12 top-3 w-10 h-10 bg-gray-200 rounded-full flex items-center justify-center z-10">
                                <svg class="w-5 h-5 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"></path>
                                </svg>
                            </div>

                            <!-- Notification content with visible timestamp -->
                            <div :class="item.is_read ? 'bg-white' : 'bg-green-50'"
                                 class="rounded-lg border shadow-sm p-3 notification-content transition-colors">
                                <div class="flex justify-between items-start">
                                    <div class="flex-1 min-w-0 pr-2">
                                        <!-- Title row with timestamp -->
                                        <div class="flex justify-between items-center mb-1">
                                            <h3 class="text-sm font-medium text-gray-900 truncate" x-text="item.title"></h3>
                                        </div>
                                        
                                        <!-- Content with no truncation -->
                                        <p class="text-xs text-gray-600 break-words whitespace-normal" x-text="item.content"></p>
                                        <p class="mt-2 text-[11px] text-gray-500" x-text="item.created_at_formatted"></p>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </template>

                    <!-- Empty state -->
                    <template x-if="$store.notification.visibleItems.length === 0">
                        <div class="flex items-center justify-center h-[150px]">
                            <p class="text-gray-500 text-sm">Tidak ada informasi baru</p>
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
@endpush
@endsection

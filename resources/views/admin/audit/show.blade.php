@extends('layouts.app')

@section('title', 'Audit Log Details')

@section('content')
<div class="p-4 bg-white block sm:flex items-center justify-between border-b border-gray-200">
    <div class="mb-1 w-full">
        <div class="mb-4">
            <h1 class="text-xl font-semibold text-gray-900 sm:text-2xl">Catatan Aktivitas Lengkap</h1>
        </div>
        <div class="sm:flex">
            <div class="flex items-center space-x-2 sm:space-x-3 ml-auto">
                <a href="{{ route('admin.audit.index') }}" class="w-full sm:w-auto flex items-center justify-center px-4 py-2 text-sm font-medium text-white rounded-lg bg-green-600 hover:bg-green-700 focus:ring-4 focus:ring-green-300">
                    <svg class="w-4 h-4 mr-2" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 17l-5-5m0 0l5-5m-5 5h12" />
                    </svg>
                    Kembali ke catatan aktivitas
                </a>
            </div>
        </div>
    </div>
</div>

<div class="p-4">
    <div class="bg-white shadow rounded-lg p-4 md:p-6 mb-6">
        <h2 class="text-lg font-semibold mb-4">Information Catatan Aktivitas</h2>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div>
                <p class="text-sm font-medium text-gray-500">ID Aktivitas</p>
                <p class="text-base font-medium text-gray-900">{{ $auditLog->id }}</p>
            </div>
            <div>
                <p class="text-sm font-medium text-gray-500">Tanggal & Waktu</p>
                <p class="text-base font-medium text-gray-900">{{ $auditLog->created_at->format('F d, Y H:i:s') }}</p>
            </div>
            <div>
                <p class="text-sm font-medium text-gray-500">Aksi</p>
                <p class="text-base font-medium">
                    <span class="px-2 py-1 text-xs rounded-full 
                        @if(in_array($auditLog->action, ['login_success', 'created']))
                            bg-green-100 text-green-800 
                        @elseif(in_array($auditLog->action, ['login_failed', 'deleted']))
                            bg-red-100 text-red-800
                        @elseif($auditLog->action === 'updated')
                            bg-yellow-100 text-yellow-800
                        @else
                            bg-gray-100 text-gray-800
                        @endif
                    ">
                        {{ ucfirst(str_replace('_', ' ', $auditLog->action)) }}
                    </span>
                </p>
            </div>
            <div>
                <p class="text-sm font-medium text-gray-500">Alamat IP</p>
                <p class="text-base font-medium text-gray-900">{{ $auditLog->ip_address ?? 'N/A' }}</p>
            </div>
            <div>
                <p class="text-sm font-medium text-gray-500">Jenis User</p>
                <p class="text-base font-medium text-gray-900 break-words">{{ $auditLog->user_agent ?? 'N/A' }}</p>
            </div>
            <div>
                <p class="text-sm font-medium text-gray-500">User</p>
                <p class="text-base font-medium text-gray-900">
                    @if($auditLog->user_type && $auditLog->user_id)
                        @if($auditLog->user_type === 'App\\Models\\User')
                            Admin: {{ optional(\App\Models\User::find($auditLog->user_id))->name ?? 'Unknown' }}
                        @elseif($auditLog->user_type === 'App\\Models\\Guru')
                            Guru: {{ optional(\App\Models\Guru::find($auditLog->user_id))->nama ?? 'Unknown' }}
                        @else
                            {{ class_basename($auditLog->user_type) }} #{{ $auditLog->user_id }}
                        @endif
                    @else
                        System
                    @endif
                </p>
            </div>
            <div>
                <p class="text-sm font-medium text-gray-500">Model</p>
                <p class="text-base font-medium text-gray-900">
                    @if($auditLog->model_type && $auditLog->model_id)
                        {{ class_basename($auditLog->model_type) }} #{{ $auditLog->model_id }}
                    @else
                        N/A
                    @endif
                </p>
            </div>
            <!-- Tambahan: Lokasi -->
            <div>
                <div>
                    <p class="text-sm font-medium text-gray-500">Lokasi</p>
                    <p class="text-base font-medium text-gray-900">
                        @php
                            $location = app(App\Services\GeoLocationService::class)->getLocation($auditLog->ip_address);
                        @endphp
                        {{ $location }}
                    </p>
                </div>
            </div>
        </div>
    </div>

    <div class="bg-white shadow rounded-lg p-4 md:p-6 mb-6">
        <h2 class="text-lg font-semibold mb-4">Deskripsi</h2>
        <div class="bg-gray-50 p-4 rounded-lg">
            <p class="text-base text-gray-900">{{ $auditLog->description ?? 'No description provided' }}</p>
        </div>
    </div>

    @if($auditLog->old_values || $auditLog->new_values)
        <div class="bg-white shadow rounded-lg p-4 md:p-6">
            <h2 class="text-lg font-semibold mb-4">Changes</h2>
            
            @if($auditLog->action === 'updated' && $auditLog->old_values && $auditLog->new_values)
                <div class="relative overflow-x-auto">
                    <table class="w-full text-sm text-left text-gray-500">
                        <thead class="text-xs text-gray-700 uppercase bg-gray-50">
                            <tr>
                                <th scope="col" class="px-6 py-3">Bidang</th>
                                <th scope="col" class="px-6 py-3">Nilai lama</th>
                                <th scope="col" class="px-6 py-3">Nilai Baru</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($auditLog->new_values as $key => $newValue)
                                @if(!in_array($key, ['id', 'created_at', 'updated_at', 'password', 'password_plain', 'remember_token']) && 
                                    (isset($auditLog->old_values[$key]) || $newValue !== null))
                                    <tr class="bg-white border-b">
                                        <th scope="row" class="px-6 py-4 font-medium text-gray-900 whitespace-nowrap">
                                            {{ ucfirst(str_replace('_', ' ', $key)) }}
                                        </th>
                                        <td class="px-6 py-4">
                                            @if(isset($auditLog->old_values[$key]))
                                                @if(is_array($auditLog->old_values[$key]))
                                                    <pre>{{ json_encode($auditLog->old_values[$key], JSON_PRETTY_PRINT) }}</pre>
                                                @else
                                                    {{ $auditLog->old_values[$key] ?? 'null' }}
                                                @endif
                                            @else
                                                <span class="text-gray-400">null</span>
                                            @endif
                                        </td>
                                        <td class="px-6 py-4">
                                            @if(isset($newValue))
                                                @if(is_array($newValue))
                                                    <pre>{{ json_encode($newValue, JSON_PRETTY_PRINT) }}</pre>
                                                @else
                                                    {{ $newValue }}
                                                @endif
                                            @else
                                                <span class="text-gray-400">null</span>
                                            @endif
                                        </td>
                                    </tr>
                                @endif
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @elseif($auditLog->action === 'created' && $auditLog->new_values)
                <div class="relative overflow-x-auto">
                    <table class="w-full text-sm text-left text-gray-500">
                        <thead class="text-xs text-gray-700 uppercase bg-gray-50">
                            <tr>
                                <th scope="col" class="px-6 py-3">Bidang</th>
                                <th scope="col" class="px-6 py-3">Nilai</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($auditLog->new_values as $key => $value)
                                @if(!in_array($key, ['id', 'created_at', 'updated_at', 'password', 'password_plain', 'remember_token']) && $value !== null)
                                    <tr class="bg-white border-b">
                                        <th scope="row" class="px-6 py-4 font-medium text-gray-900 whitespace-nowrap">
                                            {{ ucfirst(str_replace('_', ' ', $key)) }}
                                        </th>
                                        <td class="px-6 py-4">
                                            @if(is_array($value))
                                                <pre>{{ json_encode($value, JSON_PRETTY_PRINT) }}</pre>
                                            @else
                                                {{ $value }}
                                            @endif
                                        </td>
                                    </tr>
                                @endif
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @elseif($auditLog->action === 'deleted' && $auditLog->old_values)
                <div class="relative overflow-x-auto">
                    <table class="w-full text-sm text-left text-gray-500">
                        <thead class="text-xs text-gray-700 uppercase bg-gray-50">
                            <tr>
                                <th scope="col" class="px-6 py-3">Bidang</th>
                                <th scope="col" class="px-6 py-3">Nilai yang telah di delete</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($auditLog->old_values as $key => $value)
                                @if(!in_array($key, ['id', 'created_at', 'updated_at', 'password', 'password_plain', 'remember_token']) && $value !== null)
                                    <tr class="bg-white border-b">
                                        <th scope="row" class="px-6 py-4 font-medium text-gray-900 whitespace-nowrap">
                                            {{ ucfirst(str_replace('_', ' ', $key)) }}
                                        </th>
                                        <td class="px-6 py-4">
                                            @if(is_array($value))
                                                <pre>{{ json_encode($value, JSON_PRETTY_PRINT) }}</pre>
                                            @else
                                                {{ $value }}
                                            @endif
                                        </td>
                                    </tr>
                                @endif
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @else
                <div class="bg-gray-50 p-4 rounded-lg">
                    <p class="text-base text-gray-900">Tidak ada detail informasi yang ada</p>
                </div>
            @endif
        </div>
    @endif
</div>
@endsection

@extends('layouts.app')

@section('title', 'Edit Data Kelas')

@section('content')
<div data-page="edit-class">
    <div class="p-4 bg-white mt-14 rounded-lg shadow">
        <!-- Error Messages -->
        @if ($errors->any())
        <div class="mb-4 bg-red-100 border-l-4 border-red-500 text-red-700 p-4">
            <div class="flex">
                <div class="flex-shrink-0">
                    <svg class="h-5 w-5 text-red-400" viewBox="0 0 20 20" fill="currentColor">
                        <path d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z"/>
                    </svg>
                </div>
                <div class="ml-3">
                    <h3 class="text-sm font-medium">Terdapat beberapa kesalahan:</h3>
                    <ul class="mt-2 list-disc list-inside text-sm">
                        @foreach ($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            </div>
        </div>
        @endif

        @if(session('error'))
        <div class="mb-4 bg-red-100 border-l-4 border-red-500 text-red-700 p-4">
            <div class="flex">
                <div class="flex-shrink-0">
                    <svg class="h-5 w-5 text-red-400" viewBox="0 0 20 20" fill="currentColor">
                        <path d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z"/>
                    </svg>
                </div>
                <div class="ml-3">
                    <p class="text-sm">{{ session('error') }}</p>
                </div>
            </div>
        </div>
        @endif


        <!-- Success Message -->
        @if (session('success'))
        <div class="mb-4 bg-green-100 border-l-4 border-green-500 text-green-700 p-4 relative">
            <div class="flex">
                <div class="flex-shrink-0">
                    <svg class="h-5 w-5 text-green-400" viewBox="0 0 20 20" fill="currentColor">
                        <path d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z"/>
                    </svg>
                </div>
                <div class="ml-3">
                    <p class="text-sm font-medium">{{ session('success') }}</p>
                </div>
            </div>
        </div>
        @endif

        <!-- Header -->
        <div class="flex justify-between items-center mb-6">
            <h2 class="text-2xl font-bold text-green-700">Form Edit Data Kelas</h2>
            <div class="flex space-x-2">
                <button onclick="window.history.back()" class="px-4 py-2 bg-gray-600 text-white rounded-lg hover:bg-gray-700">
                    Kembali
                </button>
                <button form="editClassForm" type="submit" class="px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700">
                    Simpan
                </button>
            </div>
        </div>

        <!-- Form -->
        <form id="editClassForm" action="{{ route('kelas.update', $kelas->id) }}" method="POST" @submit="handleSubmit" data-turbo="false" data-needs-protection x-data="formProtection" class="space-y-6">
            @csrf
            @method('PUT')
        
            <!-- Nomor Kelas -->
            <div class="mb-4">
                <label for="nomor_kelas" class="block text-sm font-medium text-gray-700">Nomor Kelas</label>
                <input type="number" 
                    id="nomor_kelas" 
                    name="nomor_kelas" 
                    value="{{ old('nomor_kelas', $kelas->nomor_kelas) }}"
                    min="1"
                    max="99"
                    required
                    class="w-full mt-1 p-2 border @error('nomor_kelas') border-red-500 @enderror border-gray-300 rounded-lg">
                @error('nomor_kelas')
                    <p class="text-red-500 text-xs mt-1">{{ $message }}</p>
                @enderror
            </div>

            <!-- Nama Kelas -->
            <div class="mb-4">
                <label for="nama_kelas" class="block text-sm font-medium text-gray-700">Nama Kelas</label>
                <input type="text" 
                    id="nama_kelas" 
                    name="nama_kelas" 
                    value="{{ old('nama_kelas', $kelas->nama_kelas) }}" 
                    required
                    class="w-full mt-1 p-2 border @error('nama_kelas') border-red-500 @enderror border-gray-300 rounded-lg">
                @error('nama_kelas')
                    <p class="text-red-500 text-xs mt-1">{{ $message }}</p>
                @enderror
            </div>

        </form>
    </div>
</div>

@endsection

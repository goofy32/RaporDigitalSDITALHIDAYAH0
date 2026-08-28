@extends('layouts.app')

@section('title', 'Tujuan Pembelajaran')

@section('content')
<div
    data-page="admin-add-tp"
    data-csrf-token="{{ csrf_token() }}"
    data-mata-pelajaran-id="{{ $mataPelajaran->id }}"
    data-list-url="{{ route('tujuan_pembelajaran.list', $mataPelajaran->id) }}"
    data-store-url="{{ route('tujuan_pembelajaran.store') }}"
    data-destroy-base-url="{{ url('/admin/tujuan-pembelajaran') }}"
    data-dependency-check-base-url="{{ url('/admin/tujuan-pembelajaran') }}"
    data-delete-icon-url="{{ asset('images/icons/delete.png') }}"
>
    <div class="p-4 bg-white mt-14 shadow-lg rounded-lg">
        <!-- Header -->
        <div class="mb-6 flex flex-col items-start gap-4 md:flex-row md:items-center md:justify-between">
            <h2 class="max-w-full break-words text-2xl font-bold text-green-700 md:max-w-lg">Tujuan Pembelajaran untuk {{ $mataPelajaran->nama_pelajaran }}</h2>
            <div class="flex flex-wrap gap-2 md:justify-end">
                <button onclick="window.history.back()" class="px-4 py-2 bg-gray-500 text-white rounded-lg hover:bg-gray-600">
                    Kembali
                </button>
                <button @click="handleAjaxSubmit" onclick="saveData()" class="px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700">
                    Simpan ke Database
                </button>
            </div>
        </div>
        
        <!-- Informasi Alur Kerja -->
        <div class="bg-green-50 border-l-4 border-green-500 p-4 mb-6">
            <div class="flex">
                <div class="flex-shrink-0">
                    <svg class="h-5 w-5 text-green-500" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor">
                        <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2h-1V9z" clip-rule="evenodd" />
                    </svg>
                </div>
                <div class="ml-3">
                    <p class="text-sm text-green-800">
                        <strong>Petunjuk:</strong> Pilih lingkup materi, isi kode dan deskripsi TP, lalu klik tombol "Tambah ke Tabel" untuk menambahkan ke tabel. Klik "Simpan ke Database" untuk menyimpan semua data baru ke database.
                    </p>
                </div>
            </div>
        </div>
        
        <!-- Form -->
        <form id="addTPForm" x-data="formProtection" class="space-y-6">
            
            <input type="hidden" name="tahun_ajaran_id" value="{{ session('tahun_ajaran_id') }}">
            <!-- Mata Pelajaran -->
            <div>
                <label class="block mb-2 text-sm font-medium text-gray-900">Mata Pelajaran</label>
                <p class="text-gray-700 font-semibold">{{ $mataPelajaran->nama_pelajaran }}</p>
                <input type="hidden" id="mata_pelajaran_id" value="{{ $mataPelajaran->id }}">
            </div>

            <!-- Lingkup Materi Dropdown -->
            <div>
                <label for="lingkup_materi" class="block mb-2 text-sm font-medium text-gray-900">Lingkup Materi</label>
                <select id="lingkup_materi" required
                    class="block w-full p-2.5 bg-gray-50 border border-gray-300 rounded-lg focus:ring-green-500 focus:border-green-500">
                    <option value="">Pilih Lingkup Materi</option>
                    @foreach($mataPelajaran->lingkupMateris as $lm)
                        <option value="{{ $lm->id }}">{{ $lm->judul_lingkup_materi }}</option>
                    @endforeach
                </select>
            </div>

            <!-- Kode TP and Deskripsi TP Inputs -->
            <div id="tpContainer">
                <div class="flex items-center mb-2">
                    <input type="text" name="kode_tp[]" placeholder="Kode TP (contoh: 1)" inputmode="numeric" required
                        class="block w-1/3 p-2.5 bg-gray-50 border border-gray-300 rounded-lg focus:ring-green-500 focus:border-green-500 mr-2">
                    <input type="text" name="deskripsi_tp[]" placeholder="Deskripsi TP" required
                        class="block w-2/3 p-2.5 bg-gray-50 border border-gray-300 rounded-lg focus:ring-green-500 focus:border-green-500">
                    <button type="button" onclick="addTPRow()" class="ml-2 p-2 bg-green-600 text-white rounded-lg hover:bg-green-700" title="Tambah baris input">
                        <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 20 20">
                            <path fill-rule="evenodd" d="M10 5a1 1 0 011 1v3h3a1 1 0 110 2h-3v3a1 1 0 11-2 0v-3H6a1 1 0 110-2h3V6a1 1 0 011-1z" clip-rule="evenodd"/>
                        </svg>
                    </button>
                </div>
            </div>

            <!-- Tambah Button dengan label yang lebih jelas -->
            <button type="button" onclick="addRow()" class="px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700">
                Tambah ke Tabel
            </button>
        </form>

        <!-- Filter untuk tabel -->
        <div class="flex items-center mt-6 mb-3">
            <label class="mr-2 text-sm font-medium text-gray-900">Filter Tabel:</label>
            <select id="table-filter" class="p-2 bg-gray-50 border border-gray-300 rounded-lg focus:ring-green-500 focus:border-green-500">
                <option value="">Semua Lingkup Materi</option>
                @foreach($mataPelajaran->lingkupMateris as $lm)
                    <option value="{{ $lm->id }}">{{ $lm->judul_lingkup_materi }}</option>
                @endforeach
            </select>
        </div>

        <!-- Tabel dengan caption yang menjelaskan warna latar -->
        <div class="overflow-x-auto bg-white shadow-md rounded-lg mt-3">
            <table class="w-full text-sm text-left text-gray-500">
                <thead class="text-xs text-gray-700 uppercase bg-gray-50">
                    <tr>
                        <th class="px-6 py-3">No</th>
                        <th class="px-6 py-3">Lingkup Materi</th>
                        <th class="px-6 py-3">Kode TP</th>
                        <th class="px-6 py-3">Deskripsi TP</th>
                        <th class="px-6 py-3 text-center">Aksi</th>
                    </tr>
                </thead>
                <tbody id="tpTableBody">
                    <!-- Data will be added dynamically -->
                </tbody>
            </table>
        </div>
    </div>
</div>

@endsection

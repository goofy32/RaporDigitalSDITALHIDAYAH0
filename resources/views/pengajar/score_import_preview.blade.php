@extends('layouts.pengajar.app')

@section('title', 'Preview Import Nilai Excel')

@section('content')
<div class="p-4 mt-16 bg-white shadow-md rounded-lg">
    <div class="mb-6 flex flex-col gap-3 md:flex-row md:items-start md:justify-between">
        <div>
            <h2 class="text-2xl font-bold text-green-700">Preview Import Nilai Excel</h2>
            <p class="mt-1 text-sm text-gray-600">
                {{ $mataPelajaran->kelas?->label_kelas ?? '-' }} - {{ $mataPelajaran->nama_pelajaran }}
                | Tahun ajaran {{ $tahunAjaran->tahun_ajaran }} semester {{ $tahunAjaran->semester }}
            </p>
        </div>
        <a href="{{ route('pengajar.score.input_score', $mataPelajaran->id) }}"
           class="inline-flex items-center justify-center rounded-lg border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">
            Kembali ke Input Nilai
        </a>
    </div>

    <div class="mb-4 rounded-lg border border-yellow-300 bg-yellow-50 p-4 text-sm text-yellow-800">
        <p class="font-semibold">Ini baru preview. Nilai belum disimpan.</p>
        <p class="mt-1">Periksa validasi setiap baris sebelum fase simpan import diaktifkan pada tahap berikutnya.</p>
    </div>

    @if(!empty($preview['context_errors']))
        <div class="mb-4 rounded-lg border border-red-200 bg-red-50 p-4 text-sm text-red-700">
            <p class="font-semibold">File tidak sesuai konteks import.</p>
            <ul class="mt-2 list-disc space-y-1 pl-5">
                @foreach($preview['context_errors'] as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <div class="mb-4 grid grid-cols-1 gap-3 md:grid-cols-3">
        <div class="rounded-lg border border-gray-200 p-4">
            <p class="text-sm text-gray-500">Baris Dibaca</p>
            <p class="text-2xl font-semibold text-gray-800">{{ $preview['summary']['rows'] }}</p>
        </div>
        <div class="rounded-lg border border-green-200 p-4">
            <p class="text-sm text-green-700">Valid</p>
            <p class="text-2xl font-semibold text-green-800">{{ $preview['summary']['valid_rows'] }}</p>
        </div>
        <div class="rounded-lg border border-red-200 p-4">
            <p class="text-sm text-red-700">Tidak Valid</p>
            <p class="text-2xl font-semibold text-red-800">{{ $preview['summary']['invalid_rows'] }}</p>
        </div>
    </div>

    <div class="overflow-x-auto">
        <table class="min-w-full border-collapse text-left text-sm text-gray-600">
            <thead class="bg-gray-50 text-xs uppercase text-gray-700">
                <tr>
                    <th class="border px-3 py-2">Baris</th>
                    <th class="border px-3 py-2">Siswa</th>
                    <th class="border px-3 py-2">Nilai Saat Ini</th>
                    <th class="border px-3 py-2">Nilai Dari File</th>
                    <th class="border px-3 py-2">Status</th>
                    <th class="border px-3 py-2">Catatan</th>
                </tr>
            </thead>
            <tbody>
                @forelse($preview['rows'] as $row)
                    <tr class="{{ $row['valid'] ? 'bg-white' : 'bg-red-50' }}">
                        <td class="border px-3 py-2 align-top">{{ $row['row_number'] }}</td>
                        <td class="border px-3 py-2 align-top">
                            <div class="font-medium text-gray-800">{{ $row['student_name'] ?: '-' }}</div>
                            <div class="text-xs text-gray-500">ID: {{ $row['siswa_id'] ?: '-' }}</div>
                        </td>
                        <td class="border px-3 py-2 align-top">
                            @php
                                $existingValues = collect($row['existing_values'])->filter(fn ($value) => $value['value'] !== null);
                            @endphp
                            @if($existingValues->isEmpty())
                                <span class="text-gray-400">Belum ada nilai</span>
                            @else
                                <ul class="space-y-1">
                                    @foreach($existingValues as $value)
                                        <li><span class="font-medium">{{ $value['label'] }}:</span> {{ $value['value'] }}</li>
                                    @endforeach
                                </ul>
                            @endif
                        </td>
                        <td class="border px-3 py-2 align-top">
                            @php
                                $uploadedValues = collect($row['uploaded_values'])->filter(fn ($value) => $value['value'] !== null);
                            @endphp
                            @if($uploadedValues->isEmpty())
                                <span class="text-gray-400">Kosong</span>
                            @else
                                <ul class="space-y-1">
                                    @foreach($uploadedValues as $value)
                                        <li>
                                            <span class="font-medium">{{ $value['label'] }}:</span>
                                            {{ $value['value'] }}
                                            @if(!$value['editable'])
                                                <span class="text-xs text-gray-400">(referensi)</span>
                                            @endif
                                        </li>
                                    @endforeach
                                </ul>
                            @endif
                        </td>
                        <td class="border px-3 py-2 align-top">
                            @if($row['valid'])
                                <span class="inline-flex rounded-full bg-green-100 px-2 py-1 text-xs font-medium text-green-700">Valid</span>
                            @else
                                <span class="inline-flex rounded-full bg-red-100 px-2 py-1 text-xs font-medium text-red-700">Tidak valid</span>
                            @endif
                        </td>
                        <td class="border px-3 py-2 align-top">
                            @if(empty($row['errors']) && empty($row['warnings']))
                                <span class="text-gray-400">-</span>
                            @else
                                <ul class="list-disc space-y-1 pl-5">
                                    @foreach($row['errors'] as $error)
                                        <li class="text-red-700">{{ $error }}</li>
                                    @endforeach
                                    @foreach($row['warnings'] as $warning)
                                        <li class="text-yellow-700">{{ $warning }}</li>
                                    @endforeach
                                </ul>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="border px-3 py-6 text-center text-gray-500">Tidak ada baris nilai yang terbaca.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection

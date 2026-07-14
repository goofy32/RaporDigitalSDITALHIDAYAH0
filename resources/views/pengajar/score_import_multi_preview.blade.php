@extends('layouts.pengajar.app')

@section('title', 'Preview Upload Semua Nilai Excel')

@push('meta')
<meta name="turbo-cache-control" content="no-cache">
@endpush

@section('content')
<div data-page="pengajar-score-multi-import-preview" class="p-4 bg-white mt-14 rounded-lg">
    @php
        $sheets = collect($state['sheets'] ?? []);
        $savedSheets = $sheets->where('saved', true)->count();
        $errorSheets = $sheets->filter(fn ($sheet) => !empty($sheet['context_errors']) || ($sheet['summary']['invalid_rows'] ?? 0) > 0)->count();
        $readySheets = $sheets->filter(fn ($sheet) => empty($sheet['saved']) && ($sheet['valid'] ?? false))->count();
        $globalErrors = $state['global_errors'] ?? [];
    @endphp

    <div class="mb-6 flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
        <div>
            <h2 class="text-2xl font-bold text-green-700">Preview Upload Semua Nilai Excel</h2>
            <p class="mt-1 text-sm text-gray-600">
                Nilai belum disimpan otomatis. Periksa sheet saat ini, lalu klik Simpan &amp; Lanjut.
            </p>
        </div>
        <a href="{{ route('pengajar.score.index') }}"
           data-turbo="false"
           class="inline-flex items-center justify-center rounded-lg border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">
            Kembali ke Data Pembelajaran
        </a>
    </div>

    @if(session('success'))
        <div class="mb-4 rounded-lg border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-800">
            {{ session('success') }}
        </div>
    @endif

    @if(session('error'))
        <div class="mb-4 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">
            {{ session('error') }}
        </div>
    @endif

    @if(!empty($globalErrors))
        <div class="mb-4 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">
            <ul class="list-disc space-y-1 pl-5">
                @foreach($globalErrors as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <div class="mb-5 grid gap-3 sm:grid-cols-4">
        <div class="rounded-lg border border-gray-200 p-3">
            <div class="text-xs uppercase text-gray-500">Total Sheet</div>
            <div class="mt-1 text-xl font-semibold text-gray-800">{{ $sheets->count() }}</div>
        </div>
        <div class="rounded-lg border border-gray-200 p-3">
            <div class="text-xs uppercase text-gray-500">Siap Simpan</div>
            <div class="mt-1 text-xl font-semibold text-green-700">{{ $readySheets }}</div>
        </div>
        <div class="rounded-lg border border-gray-200 p-3">
            <div class="text-xs uppercase text-gray-500">Sudah Disimpan</div>
            <div class="mt-1 text-xl font-semibold text-blue-700">{{ $savedSheets }}</div>
        </div>
        <div class="rounded-lg border border-gray-200 p-3">
            <div class="text-xs uppercase text-gray-500">Perlu Diperbaiki</div>
            <div class="mt-1 text-xl font-semibold text-red-700">{{ $errorSheets }}</div>
        </div>
    </div>

    <div class="mb-6 overflow-x-auto rounded-lg border border-gray-200">
        <table class="min-w-full divide-y divide-gray-200 text-sm">
            <thead class="bg-gray-50 text-left text-xs font-medium uppercase text-gray-500">
                <tr>
                    <th class="px-4 py-3">Sheet</th>
                    <th class="px-4 py-3">Kelas</th>
                    <th class="px-4 py-3">Mata Pelajaran</th>
                    <th class="px-4 py-3">Status</th>
                    <th class="px-4 py-3">Ringkasan</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100 bg-white">
                @foreach($sheets as $index => $sheet)
                    <tr @class(['bg-green-50' => $currentIndex === $index && !$allSaved])>
                        <td class="px-4 py-3 font-medium text-gray-800">{{ $sheet['sheet_name'] }}</td>
                        <td class="px-4 py-3">{{ $sheet['kelas'] ?: '-' }}</td>
                        <td class="px-4 py-3">{{ $sheet['mata_pelajaran'] ?: '-' }}</td>
                        <td class="px-4 py-3">
                            @if(!empty($sheet['saved']))
                                <span class="rounded-full bg-blue-100 px-2 py-1 text-xs font-medium text-blue-700">Tersimpan</span>
                            @elseif($sheet['valid'] ?? false)
                                <span class="rounded-full bg-green-100 px-2 py-1 text-xs font-medium text-green-700">Siap disimpan</span>
                            @else
                                <span class="rounded-full bg-red-100 px-2 py-1 text-xs font-medium text-red-700">Perlu diperbaiki</span>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-gray-600">
                            {{ $sheet['summary']['rows'] ?? 0 }} siswa,
                            {{ $sheet['summary']['values'] ?? 0 }} nilai,
                            {{ $sheet['summary']['errors'] ?? 0 }} error
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    @if($allSaved)
        <div class="rounded-lg border border-green-200 bg-green-50 p-5 text-green-800">
            <h3 class="text-lg font-semibold">Semua sheet berhasil disimpan</h3>
            <p class="mt-1 text-sm">
                {{ $savedSheets }} sheet nilai sudah diproses. Silakan kembali ke Data Pembelajaran untuk melihat atau mengubah nilai jika diperlukan.
            </p>
        </div>
    @elseif($currentSheet)
        @php
            $sheetHasErrors = !($currentSheet['valid'] ?? false) || !empty($globalErrors);
            $rowErrors = collect($currentSheet['rows'] ?? [])->filter(fn ($row) => !empty($row['errors']));
            $editableColumns = collect($currentSheet['columns'] ?? [])->filter(fn ($column) => $column['editable'] ?? false)->values();
        @endphp

        <div class="mb-4 rounded-lg border border-gray-200 p-4">
            <div class="mb-4 flex flex-col gap-3 lg:flex-row lg:items-start lg:justify-between">
                <div class="max-w-3xl">
                    <h3 class="text-lg font-semibold text-gray-800">Preview Sheet Saat Ini</h3>
                    <p class="mt-1 text-sm text-gray-600">
                        Nilai disimpan per sheet. Setelah klik Simpan &amp; Lanjut, sheet ini langsung tersimpan, lalu Anda berpindah ke sheet berikutnya.
                    </p>
                    <p class="mt-1 text-sm text-gray-500">
                        Sheet lain belum tersimpan sampai Anda membuka preview sheet tersebut dan klik Simpan &amp; Lanjut.
                    </p>
                </div>
                <form action="{{ route('pengajar.score.import_templates.save_sheet', ['token' => $token, 'sheet' => $currentIndex + 1]) }}"
                      method="POST"
                      data-turbo="false"
                      class="shrink-0">
                    @csrf
                    <button type="submit"
                            @disabled($sheetHasErrors)
                            @class([
                                'w-full rounded-lg px-4 py-2 text-sm font-medium sm:w-auto',
                                'bg-green-700 text-white hover:bg-green-800 focus:ring-4 focus:ring-green-300' => ! $sheetHasErrors,
                                'cursor-not-allowed bg-gray-400 text-white' => $sheetHasErrors,
                            ])>
                        Simpan &amp; Lanjut
                    </button>
                </form>
            </div>

            <div class="grid gap-3 border-t border-gray-100 pt-4 text-sm sm:grid-cols-4">
                <div>
                    <div class="text-xs uppercase text-gray-500">Kelas</div>
                    <div class="font-medium text-gray-800">{{ $currentSheet['kelas'] ?: '-' }}</div>
                </div>
                <div>
                    <div class="text-xs uppercase text-gray-500">Mata Pelajaran</div>
                    <div class="font-medium text-gray-800">{{ $currentSheet['mata_pelajaran'] ?: '-' }}</div>
                </div>
                <div>
                    <div class="text-xs uppercase text-gray-500">Tahun Ajaran</div>
                    <div class="font-medium text-gray-800">{{ $currentSheet['tahun_ajaran'] ?? $state['tahun_ajaran'] }}</div>
                </div>
                <div>
                    <div class="text-xs uppercase text-gray-500">Semester</div>
                    <div class="font-medium text-gray-800">{{ $currentSheet['semester'] ?? $state['semester'] }}</div>
                </div>
            </div>
        </div>

        @if(!empty($currentSheet['context_errors']))
            <div class="mb-4 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">
                <ul class="list-disc space-y-1 pl-5">
                    @foreach($currentSheet['context_errors'] as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        @if($rowErrors->isNotEmpty())
            <div class="mb-4 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">
                <div class="mb-1 font-medium">Sheet ini belum bisa disimpan karena masih ada nilai yang perlu diperbaiki.</div>
                <ul class="list-disc space-y-1 pl-5">
                    @foreach($rowErrors as $row)
                        @foreach($row['errors'] as $error)
                            <li>Baris {{ $row['row_number'] }}, siswa {{ $row['student_name'] ?: '-' }}: {{ $error }}</li>
                        @endforeach
                    @endforeach
                </ul>
            </div>
        @endif

        <div class="overflow-x-auto rounded-lg border border-gray-200">
            <table class="min-w-full divide-y divide-gray-200 text-sm">
                <thead class="bg-gray-50 text-left text-xs font-medium uppercase text-gray-500">
                    <tr>
                        <th class="sticky left-0 bg-gray-50 px-4 py-3">Siswa</th>
                        @foreach($editableColumns as $column)
                            <th class="px-4 py-3">{{ $column['label'] }}</th>
                        @endforeach
                        <th class="px-4 py-3">Status</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 bg-white">
                    @forelse($currentSheet['rows'] as $row)
                        <tr @class(['bg-red-50' => !($row['valid'] ?? false)])>
                            <td class="sticky left-0 bg-inherit px-4 py-3 font-medium text-gray-800">
                                {{ $row['student_name'] ?: '-' }}
                            </td>
                            @foreach(collect($row['uploaded_values'])->filter(fn ($value) => $value['editable'] ?? false) as $value)
                                <td class="px-4 py-3">
                                    <span @class([
                                        'rounded px-2 py-1',
                                        'bg-yellow-50 text-gray-800' => $value['raw_value'] !== null,
                                        'text-gray-400' => $value['raw_value'] === null,
                                    ])>
                                        {{ $value['raw_value'] ?? '-' }}
                                    </span>
                                </td>
                            @endforeach
                            <td class="px-4 py-3">
                                @if($row['valid'] ?? false)
                                    <span class="rounded-full bg-green-100 px-2 py-1 text-xs font-medium text-green-700">Valid</span>
                                @else
                                    <span class="rounded-full bg-red-100 px-2 py-1 text-xs font-medium text-red-700">Error</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="{{ $editableColumns->count() + 2 }}" class="px-4 py-6 text-center text-gray-500">
                                Tidak ada data siswa yang dapat dibaca pada sheet ini.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    @endif
</div>
@endsection

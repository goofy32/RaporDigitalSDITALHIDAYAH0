@extends('layouts.app')

@section('title', 'Upload Data Siswa')

@section('content')
<div class="p-4 bg-white mt-14">
    <div class="max-w-2xl mx-auto">
        <h2 class="text-2xl font-bold text-green-700 mb-4">Upload Data Siswa</h2>

        @if (session('success'))
            <div class="mb-4 p-3 rounded-lg bg-green-50 text-green-700 border border-green-200">
                {{ session('success') }}
            </div>
        @endif

        @if (session('error'))
            <div class="mb-4 p-3 rounded-lg bg-red-50 text-red-700 border border-red-200">
                {{ session('error') }}
            </div>
        @endif

        @if (session('import_errors'))
            <div class="mb-4 p-3 rounded-lg bg-red-50 text-red-700 border border-red-200">
                <p class="font-semibold mb-2">Kesalahan import:</p>
                <ul class="list-disc list-inside space-y-1">
                    @foreach (session('import_errors') as $importError)
                        <li>{{ $importError }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <form action="{{ route('student.import') }}" method="POST" enctype="multipart/form-data" class="space-y-4">
            @csrf

            <div>
                <label for="file" class="block mb-2 text-sm font-medium text-gray-700">File Excel</label>
                <input type="file" name="file" id="file" accept=".xlsx"
                    class="block w-full text-sm text-gray-900 border border-gray-300 rounded-lg cursor-pointer bg-gray-50 focus:outline-none">
                @error('file')
                    <p class="mt-2 text-sm text-red-600">{{ $message }}</p>
                @enderror
            </div>

            <div class="flex gap-2">
                <button type="submit" class="text-white bg-green-700 hover:bg-green-800 focus:ring-4 focus:ring-green-300 font-medium rounded-lg text-sm px-4 py-2">
                    Upload
                </button>
                <a href="{{ route('student') }}" class="text-gray-700 bg-white border border-gray-300 hover:bg-gray-50 focus:ring-4 focus:ring-gray-200 font-medium rounded-lg text-sm px-4 py-2">
                    Kembali
                </a>
            </div>
        </form>
    </div>
</div>
@endsection

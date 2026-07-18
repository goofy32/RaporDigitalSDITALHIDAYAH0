<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="ie=edge">
    <link rel="icon" type="image/x-icon" href="{{ asset('favicon.ico') }}">
    <link rel="icon" type="image/png" sizes="16x16" href="{{ asset('favicon-16x16.png') }}">
    <link rel="icon" type="image/png" sizes="32x32" href="{{ asset('favicon-32x32.png') }}">
    <link rel="apple-touch-icon" sizes="180x180" href="{{ asset('apple-touch-icon.png') }}">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <link rel="stylesheet" href="https://rsms.me/inter/inter.css">
    <title>Data Ekstrakulikuler</title>
</head>

<body>

    <x-admin.topbar></x-admin.topbar>
    <x-admin.sidebar></x-admin.sidebar>

    <div class="p-4 sm:ml-64">
        <div class="p-4 bg-white mt-14">
            <!-- Header -->
            <div class="flex justify-between items-center mb-6">
                <h2 class="text-2xl font-bold text-green-700">Data Ekstrakulikuler</h2>
            </div>

            <!-- Tombol Tambah Data -->
            <div class="flex justify-start mb-4">
                <a href="{{ route('ekstra.create') }}" class="flex items-center justify-center text-white bg-green-700 hover:bg-green-800 focus:ring-4 focus:ring-green-300 font-medium rounded-lg text-sm px-4 py-2">
                    <svg class="h-3.5 w-3.5 mr-2" fill="currentColor" viewBox="0 0 20 20" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                        <path clip-rule="evenodd" fill-rule="evenodd" d="M10 3a1 1 0 011 1v5h5a1 1 0 110 2h-5v5a1 1 0 11-2 0v-5H4a1 1 0 110-2h5V4a1 1 0 011-1z" />
                    </svg>
                    Tambah Data
                </a>
            </div>

            <div data-live-list>
                <form action="{{ route('ekstra.index') }}" method="GET" class="mb-4" data-live-list-form data-turbo="false">
                    <div class="flex flex-col gap-3 md:flex-row">
                        <div class="flex flex-1 gap-2">
                            <input type="text" name="search" value="{{ request('search') }}"
                                data-live-search-input
                                class="block w-full rounded-lg border border-gray-300 bg-gray-50 p-2 text-sm text-gray-900 focus:border-green-500 focus:ring-green-500"
                                placeholder="Cari nama ekstrakurikuler atau pembina...">
                            <button type="submit" class="shrink-0 rounded-lg bg-green-600 px-4 py-2 text-sm font-medium text-white hover:bg-green-700">Cari</button>
                        </div>

                        <details class="relative" data-live-filter-panel>
                            <x-live-list.filter-button />
                            <div class="mt-2 w-full rounded-lg border border-gray-200 bg-white p-4 shadow-lg md:absolute md:right-0 md:z-20 md:w-80">
                                <div class="space-y-3">
                                    <div>
                                        <label class="mb-1 block text-sm font-medium text-gray-700">Pembina</label>
                                        <select name="pembina" class="w-full rounded-lg border-gray-300 text-sm focus:border-green-500 focus:ring-green-500">
                                            <option value="">Semua pembina</option>
                                            @foreach($pembinaOptions as $pembina)
                                                <option value="{{ $pembina }}" @selected(request('pembina') === $pembina)>{{ $pembina }}</option>
                                            @endforeach
                                        </select>
                                    </div>
                                    <div>
                                        <label class="mb-1 block text-sm font-medium text-gray-700">Urutkan</label>
                                        <select name="sort" class="w-full rounded-lg border-gray-300 text-sm focus:border-green-500 focus:ring-green-500">
                                            <option value="">A-Z</option>
                                            <option value="za" @selected(request('sort') === 'za')>Z-A</option>
                                        </select>
                                    </div>
                                    <div class="flex items-center justify-end gap-2 pt-2">
                                        <a href="{{ route('ekstra.index') }}" data-live-reset class="rounded-lg bg-gray-100 px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-200">Reset Filter</a>
                                        <button type="submit" class="rounded-lg bg-green-700 px-3 py-2 text-sm font-medium text-white hover:bg-green-800">Terapkan</button>
                                    </div>
                                </div>
                            </div>
                        </details>
                    </div>
                </form>

                @include('components.live-list.filter-chips', ['filters' => [
                    ['key' => 'search', 'label' => 'Pencarian'],
                    ['key' => 'pembina', 'label' => 'Pembina'],
                    ['key' => 'sort', 'label' => 'Urutan', 'values' => ['za' => 'Z-A']],
                ]])

                <div class="mb-3 hidden text-sm text-gray-500" data-live-list-loading>Memuat data...</div>

                <div data-live-list-results>
                    @include('admin.partials.ekstrakurikuler-results', ['ekstrakurikulers' => $ekstrakurikulers])
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdnjs.cloudflare.com/ajax/libs/flowbite/2.2.1/flowbite.min.js"></script>

</body>

</html>

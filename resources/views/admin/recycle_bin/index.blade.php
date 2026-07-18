@extends('layouts.app')

@section('title', 'Recycle Bin / Sampah')

@section('content')
<div data-recycle-bin-root class="p-4 bg-white block sm:flex items-center justify-between border-b border-gray-200">
    <div class="mb-1 w-full">
        <div class="mb-4">
            <h1 class="text-xl font-semibold text-gray-900 sm:text-2xl">Recycle Bin / Sampah</h1>
            <p class="text-sm text-gray-500 mt-1">Data yang dihapus akan tersimpan selama 60 hari sebelum dihapus permanen.</p>
        </div>
        <div class="sm:flex">
            <div class="flex items-center space-x-2 sm:space-x-3 ml-auto">
                <a href="{{ route('admin.audit.index') }}"
                    class="w-full sm:w-auto flex items-center justify-center px-4 py-2 text-sm font-medium text-gray-700 rounded-lg bg-gray-100 hover:bg-gray-200 focus:ring-4 focus:ring-gray-200">
                    <svg class="w-4 h-4 mr-2" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 17l-5-5m0 0l5-5m-5 5h12" />
                    </svg>
                    Catatan Aktivitas
                </a>
            </div>
        </div>
    </div>
</div>

<div class="p-4 grid gap-4 md:grid-cols-2">
    <div class="bg-white border border-gray-200 rounded-lg p-5 shadow-sm">
        <p class="text-sm font-medium text-gray-500">Total data di sampah</p>
        <p class="mt-2 text-3xl font-semibold text-gray-900">{{ $totalItems }}</p>
    </div>
    <div class="bg-white border border-yellow-200 rounded-lg p-5 shadow-sm">
        <p class="text-sm font-medium text-yellow-700">Kadaluarsa &lt; 7 hari</p>
        <p class="mt-2 text-3xl font-semibold text-yellow-700">{{ $expiringSoonCount }}</p>
    </div>
</div>

@if($expiringSoonCount > 0)
    <div class="px-4 pb-4">
        <div class="bg-yellow-50 border-l-4 border-yellow-400 p-4 rounded-r">
            <div class="flex items-start gap-3">
                <svg class="w-5 h-5 text-yellow-400 mt-0.5 flex-shrink-0" viewBox="0 0 20 20" fill="currentColor">
                    <path fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.72-1.36 3.485 0l5.58 9.92c.75 1.334-.213 2.981-1.742 2.981H4.42c-1.53 0-2.492-1.647-1.743-2.98l5.58-9.921zM11 13a1 1 0 10-2 0 1 1 0 002 0zm-1-6a1 1 0 00-1 1v3a1 1 0 102 0V8a1 1 0 00-1-1z" clip-rule="evenodd" />
                </svg>
                <div>
                    <p class="text-yellow-800 font-medium">Ada data yang akan segera kadaluarsa</p>
                    <p class="text-yellow-700 text-sm">{{ $expiringSoonCount }} data akan dihapus permanen dalam waktu kurang dari 7 hari.</p>
                </div>
            </div>
        </div>
    </div>
@endif

<div class="px-4 pb-4">
    <div class="bg-blue-50 border-l-4 border-blue-400 p-4 rounded-r">
        <div class="flex items-start gap-3">
            <svg class="w-5 h-5 text-blue-400 mt-0.5 flex-shrink-0" viewBox="0 0 20 20" fill="currentColor">
                <path fill-rule="evenodd" d="M18 10A8 8 0 114 5.53V4a1 1 0 10-2 0v4a1 1 0 001 1h4a1 1 0 100-2H5.8A6 6 0 1010 4a1 1 0 100-2 8 8 0 018 8zm-9 3a1 1 0 002 0V9a1 1 0 10-2 0v4zm1-8a1.25 1.25 0 100 2.5A1.25 1.25 0 0010 5z" clip-rule="evenodd" />
            </svg>
            <div>
                <p class="text-blue-800 font-medium">Catatan pemulihan Nilai</p>
                <p class="text-blue-700 text-sm">Nilai tidak ditampilkan sebagai item terpisah di recycle bin. Nilai akan dipulihkan otomatis saat Anda memulihkan Mata Pelajaran, Lingkup Materi, atau Tujuan Pembelajaran terkait.</p>
            </div>
        </div>
    </div>
</div>

<form method="GET" action="{{ route('admin.recycle-bin.index') }}" class="bg-white p-4 mb-4 border-b border-gray-200">
    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <div>
            <label for="type" class="block mb-2 text-sm font-medium text-gray-900">Tipe Data</label>
            <select id="type" name="type" class="bg-gray-50 border border-gray-300 text-gray-900 text-sm rounded-lg focus:ring-green-500 focus:border-green-500 block w-full p-2.5">
                <option value="">Semua Tipe</option>
                @foreach($typeOptions as $typeValue => $typeLabel)
                    <option value="{{ $typeValue }}" {{ $filters['type'] === $typeValue ? 'selected' : '' }}>{{ $typeLabel }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label for="date_from" class="block mb-2 text-sm font-medium text-gray-900">Tanggal hapus dari</label>
            <input type="date" name="date_from" id="date_from" value="{{ $filters['date_from'] }}" class="bg-gray-50 border border-gray-300 text-gray-900 text-sm rounded-lg focus:ring-green-500 focus:border-green-500 block w-full p-2.5">
        </div>
        <div>
            <label for="date_to" class="block mb-2 text-sm font-medium text-gray-900">Tanggal hapus sampai</label>
            <input type="date" name="date_to" id="date_to" value="{{ $filters['date_to'] }}" class="bg-gray-50 border border-gray-300 text-gray-900 text-sm rounded-lg focus:ring-green-500 focus:border-green-500 block w-full p-2.5">
        </div>
        <div>
            <label for="search" class="block mb-2 text-sm font-medium text-gray-900">Cari nama/deskripsi</label>
            <input type="text" name="search" id="search" value="{{ $filters['search'] }}" class="bg-gray-50 border border-gray-300 text-gray-900 text-sm rounded-lg focus:ring-green-500 focus:border-green-500 block w-full p-2.5" placeholder="Contoh: Matematika, Ahmad">
        </div>
    </div>
    <div class="mt-4 flex justify-end gap-2">
        <a href="{{ route('admin.recycle-bin.index') }}" class="px-4 py-2 text-sm font-medium text-gray-700 bg-gray-200 rounded-lg hover:bg-gray-300">
            Reset
        </a>
        <button type="submit" class="px-4 py-2 text-sm font-medium text-white bg-green-600 rounded-lg hover:bg-green-700">
            Terapkan Filter
        </button>
    </div>
</form>

<div class="px-4 pb-4">
    <div class="bg-white border border-gray-200 rounded-lg shadow-sm">
        <div class="p-4 border-b border-gray-200 flex flex-wrap items-center gap-2 justify-between">
            <div class="flex items-center gap-3">
                <label class="inline-flex items-center gap-2 text-sm text-gray-700">
                    <input type="checkbox" id="select-all-bin-items" class="rounded border-gray-300 text-green-600 focus:ring-green-500">
                    Pilih semua
                </label>
                <span id="selected-count" class="text-sm text-gray-500">0 item dipilih</span>
            </div>
            <div class="flex flex-wrap gap-2">
                <button type="button" id="restore-selected-btn" class="px-4 py-2 text-sm font-medium text-white bg-green-600 rounded-lg hover:bg-green-700">
                    Restore Terpilih
                </button>
                <button type="button" id="delete-selected-btn" class="px-4 py-2 text-sm font-medium text-white bg-red-600 rounded-lg hover:bg-red-700">
                    Hapus Permanen Terpilih
                </button>
                <button type="button" id="delete-all-btn" class="px-4 py-2 text-sm font-medium text-red-700 bg-red-100 rounded-lg hover:bg-red-200">
                    Hapus Semua
                </button>
            </div>
        </div>

        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="p-4">
                            <span class="sr-only">Pilih</span>
                        </th>
                        <th class="p-4 text-left text-xs font-medium text-gray-500 uppercase">Nama / Deskripsi</th>
                        <th class="p-4 text-left text-xs font-medium text-gray-500 uppercase">Tipe Data</th>
                        <th class="p-4 text-left text-xs font-medium text-gray-500 uppercase">Dihapus oleh</th>
                        <th class="p-4 text-left text-xs font-medium text-gray-500 uppercase">Tanggal dihapus</th>
                        <th class="p-4 text-left text-xs font-medium text-gray-500 uppercase">Kadaluarsa</th>
                        <th class="p-4 text-left text-xs font-medium text-gray-500 uppercase">Aksi</th>
                    </tr>
                </thead>
                @forelse($items as $item)
                    @php
                        $expiresSoon = $item['expires_at']->isFuture() && $item['expires_at']->lessThanOrEqualTo(now()->addDays(7));
                        $hasChildren = !empty($item['children']);
                    @endphp
                    <tbody x-data="{ expanded: false, childExpanded: {} }" class="bg-white divide-y divide-gray-200">
                        <tr class="hover:bg-gray-50">
                            <td class="p-4 align-top">
                                <input type="checkbox"
                                    class="bin-item-checkbox rounded border-gray-300 text-green-600 focus:ring-green-500"
                                    value="{{ $item['type'] }}:{{ $item['id'] }}">
                            </td>
                            <td class="p-4 align-top">
                                <div class="flex items-start gap-3">
                                    @if($hasChildren)
                                        <button type="button"
                                            @click="expanded = !expanded"
                                            class="mt-0.5 text-gray-500 hover:text-gray-700">
                                            <svg class="w-4 h-4 transition-transform duration-150" :class="expanded ? 'rotate-90' : ''" viewBox="0 0 20 20" fill="currentColor">
                                                <path fill-rule="evenodd" d="M7.293 14.707a1 1 0 010-1.414L10.586 10 7.293 6.707a1 1 0 111.414-1.414l4 4a1 1 0 010 1.414l-4 4a1 1 0 01-1.414 0z" clip-rule="evenodd" />
                                            </svg>
                                        </button>
                                    @else
                                        <span class="inline-block w-4"></span>
                                    @endif
                                    <div>
                                        <p class="text-sm font-medium text-gray-900">{{ $item['name'] }}</p>
                                        <p class="text-sm text-gray-500 mt-1">{{ \Illuminate\Support\Str::limit($item['description'], 100) }}</p>
                                        @if(!empty($item['force_delete_note']))
                                            <p class="mt-2 rounded-md bg-red-50 px-3 py-2 text-xs text-red-700">{{ $item['force_delete_note'] }}</p>
                                        @endif
                                        @if($hasChildren)
                                            <p class="text-xs text-gray-400 mt-1">{{ count($item['children']) }} item turunan</p>
                                        @endif
                                    </div>
                                </div>
                            </td>
                            <td class="p-4 align-top">
                                <span class="inline-flex px-2.5 py-1 text-xs font-medium rounded-full bg-gray-100 text-gray-700">
                                    {{ $item['type_label'] }}
                                </span>
                            </td>
                            <td class="p-4 align-top text-sm text-gray-700">{{ $item['deleted_by'] }}</td>
                            <td class="p-4 align-top text-sm text-gray-700">{{ $item['deleted_at']->format('d M Y H:i') }}</td>
                            <td class="p-4 align-top text-sm {{ $expiresSoon ? 'text-yellow-700 font-medium' : 'text-gray-700' }}">
                                <div>{{ $item['expires_at']->format('d M Y H:i') }}</div>
                                <div class="text-xs {{ $expiresSoon ? 'text-yellow-600' : 'text-gray-500' }}">
                                    {{ $item['expires_at']->diffForHumans() }}
                                </div>
                            </td>
                            <td class="p-4 align-top">
                                <div class="flex flex-wrap gap-2">
                                    <form method="POST" action="{{ route('admin.recycle-bin.restore', ['type' => $item['type'], 'id' => $item['id']]) }}">
                                        @csrf
                                        <button type="submit" class="px-3 py-1.5 text-xs font-medium text-white bg-green-600 rounded-lg hover:bg-green-700">
                                            Restore
                                        </button>
                                    </form>
                                    <form method="POST"
                                        action="{{ route('admin.recycle-bin.force-delete', ['type' => $item['type'], 'id' => $item['id']]) }}"
                                        data-force-delete-form
                                        data-item-type="{{ $item['type'] }}"
                                        data-confirmation="{{ $item['force_delete_confirmation'] ?? '' }}">
                                        @csrf
                                        @method('DELETE')
                                        <input type="hidden" name="purge_confirmation" value="">
                                        <button type="submit" class="px-3 py-1.5 text-xs font-medium text-white bg-red-600 rounded-lg hover:bg-red-700">
                                            Hapus Permanen
                                        </button>
                                    </form>
                                </div>
                            </td>
                        </tr>

                        @foreach($item['children'] as $child)
                            @php
                                $childKey = $child['type'] . '-' . $child['id'];
                                $childHasChildren = !empty($child['children']);
                            @endphp
                            <tr x-show="expanded" x-cloak class="bg-gray-50">
                                <td class="p-4 align-top"></td>
                                <td class="p-4 align-top">
                                    <div class="flex items-start gap-3 pl-8">
                                        @if($childHasChildren)
                                            <button type="button"
                                                @click="childExpanded['{{ $childKey }}'] = !childExpanded['{{ $childKey }}']"
                                                class="mt-0.5 text-gray-500 hover:text-gray-700">
                                                <svg class="w-4 h-4 transition-transform duration-150" :class="childExpanded['{{ $childKey }}'] ? 'rotate-90' : ''" viewBox="0 0 20 20" fill="currentColor">
                                                    <path fill-rule="evenodd" d="M7.293 14.707a1 1 0 010-1.414L10.586 10 7.293 6.707a1 1 0 111.414-1.414l4 4a1 1 0 010 1.414l-4 4a1 1 0 01-1.414 0z" clip-rule="evenodd" />
                                                </svg>
                                            </button>
                                        @else
                                            <span class="inline-block w-4"></span>
                                        @endif
                                        <div>
                                            <p class="text-sm font-medium text-gray-900">{{ $child['name'] }}</p>
                                            <p class="text-sm text-gray-500 mt-1">{{ \Illuminate\Support\Str::limit($child['description'], 100) }}</p>
                                            @if($childHasChildren)
                                                <p class="text-xs text-gray-400 mt-1">{{ count($child['children']) }} item turunan</p>
                                            @endif
                                        </div>
                                    </div>
                                </td>
                                <td class="p-4 align-top">
                                    <span class="inline-flex px-2.5 py-1 text-xs font-medium rounded-full bg-gray-100 text-gray-700">
                                        {{ $child['type_label'] }}
                                    </span>
                                </td>
                                <td class="p-4 align-top text-sm text-gray-700">{{ $child['deleted_by'] }}</td>
                                <td class="p-4 align-top text-sm text-gray-700">{{ $child['deleted_at']->format('d M Y H:i') }}</td>
                                <td class="p-4 align-top text-sm text-gray-700">
                                    <div>{{ $child['expires_at']->format('d M Y H:i') }}</div>
                                    <div class="text-xs text-gray-500">{{ $child['expires_at']->diffForHumans() }}</div>
                                </td>
                                <td class="p-4 align-top">
                                    <form method="POST" action="{{ route('admin.recycle-bin.restore', ['type' => $child['type'], 'id' => $child['id']]) }}">
                                        @csrf
                                        <button type="submit" class="px-3 py-1.5 text-xs font-medium text-white bg-green-600 rounded-lg hover:bg-green-700">
                                            Restore
                                        </button>
                                    </form>
                                </td>
                            </tr>

                            @foreach($child['children'] as $grandchild)
                                <tr x-show="expanded && childExpanded['{{ $childKey }}']" x-cloak class="bg-gray-50/80">
                                    <td class="p-4 align-top"></td>
                                    <td class="p-4 align-top">
                                        <div class="pl-16">
                                            <p class="text-sm font-medium text-gray-900">{{ $grandchild['name'] }}</p>
                                            <p class="text-sm text-gray-500 mt-1">{{ \Illuminate\Support\Str::limit($grandchild['description'], 100) }}</p>
                                        </div>
                                    </td>
                                    <td class="p-4 align-top">
                                        <span class="inline-flex px-2.5 py-1 text-xs font-medium rounded-full bg-gray-100 text-gray-700">
                                            {{ $grandchild['type_label'] }}
                                        </span>
                                    </td>
                                    <td class="p-4 align-top text-sm text-gray-700">{{ $grandchild['deleted_by'] }}</td>
                                    <td class="p-4 align-top text-sm text-gray-700">{{ $grandchild['deleted_at']->format('d M Y H:i') }}</td>
                                    <td class="p-4 align-top text-sm text-gray-700">
                                        <div>{{ $grandchild['expires_at']->format('d M Y H:i') }}</div>
                                        <div class="text-xs text-gray-500">{{ $grandchild['expires_at']->diffForHumans() }}</div>
                                    </td>
                                    <td class="p-4 align-top">
                                        <form method="POST" action="{{ route('admin.recycle-bin.restore', ['type' => $grandchild['type'], 'id' => $grandchild['id']]) }}">
                                            @csrf
                                            <button type="submit" class="px-3 py-1.5 text-xs font-medium text-white bg-green-600 rounded-lg hover:bg-green-700">
                                                Restore
                                            </button>
                                        </form>
                                    </td>
                                </tr>
                            @endforeach
                        @endforeach
                    </tbody>
                @empty
                    <tbody class="bg-white divide-y divide-gray-200">
                        <tr>
                            <td colspan="7" class="p-6 text-center text-sm text-gray-500">
                                Recycle bin kosong.
                            </td>
                        </tr>
                    </tbody>
                @endforelse
            </table>
        </div>

        <div class="p-4 border-t border-gray-200">
            {{ $items->withQueryString()->links() }}
        </div>
    </div>
</div>

<form id="force-delete-selected-form" method="POST" action="{{ route('admin.recycle-bin.force-delete-all') }}" class="hidden">
    @csrf
    @method('DELETE')
</form>

<form id="force-delete-all-form" method="POST" action="{{ route('admin.recycle-bin.force-delete-all') }}" class="hidden">
    @csrf
    @method('DELETE')
</form>

<script>
    function initRecycleBinPage() {
        var page = document.querySelector('[data-recycle-bin-root]');
        if (!page || page.dataset.recycleBinInit === 'true') {
            return;
        }

        page.dataset.recycleBinInit = 'true';
        var selectAll = document.getElementById('select-all-bin-items');
        var checkboxes = Array.from(document.querySelectorAll('.bin-item-checkbox'));
        var selectedCount = document.getElementById('selected-count');
        var restoreSelectedButton = document.getElementById('restore-selected-btn');
        var deleteSelectedButton = document.getElementById('delete-selected-btn');
        var deleteAllButton = document.getElementById('delete-all-btn');
        var selectedDeleteForm = document.getElementById('force-delete-selected-form');
        var deleteAllForm = document.getElementById('force-delete-all-form');
        var csrfToken = document.querySelector('meta[name="csrf-token"]')?.content;

        function getSelectedValues() {
            return checkboxes.filter((checkbox) => checkbox.checked).map((checkbox) => checkbox.value);
        }

        function sortRestoreItems(values) {
            var restoreOrder = {
                'kelas': 0,
                'mata-pelajaran': 1,
                'lingkup-materi': 2,
                'tujuan-pembelajaran': 3,
                'guru': 4,
                'siswa': 5,
                'ekstrakurikuler': 6,
                'prestasi': 7,
                'absensi': 8
            };

            return values.slice().sort(function (a, b) {
                var typeA = a.split(':')[0];
                var typeB = b.split(':')[0];
                var posA = Object.prototype.hasOwnProperty.call(restoreOrder, typeA) ? restoreOrder[typeA] : 999;
                var posB = Object.prototype.hasOwnProperty.call(restoreOrder, typeB) ? restoreOrder[typeB] : 999;

                if (posA === posB) {
                    return a.localeCompare(b);
                }

                return posA - posB;
            });
        }

        function confirmForceDelete(form) {
            if (form.dataset.itemType === 'siswa') {
                var expected = form.dataset.confirmation || '';
                var typed = prompt('Ketik "' + expected + '" untuk menghapus permanen siswa ini. Tindakan ini tidak dapat dibatalkan.');

                if (typed === null) {
                    return false;
                }

                if (typed.trim() !== expected) {
                    alert('Konfirmasi tidak sesuai. Data siswa tidak dihapus.');
                    return false;
                }

                var input = form.querySelector('input[name="purge_confirmation"]');
                if (input) {
                    input.value = typed.trim();
                }

                return true;
            }

            return confirm('Hapus permanen data ini? Tindakan ini tidak dapat dibatalkan.');
        }

        function updateSelectedCount() {
            var selected = getSelectedValues();
            selectedCount.textContent = selected.length + ' item dipilih';

            if (selectAll) {
                selectAll.checked = selected.length > 0 && selected.length === checkboxes.length;
            }
        }

        if (selectAll) {
            selectAll.addEventListener('change', function () {
                checkboxes.forEach((checkbox) => {
                    checkbox.checked = selectAll.checked;
                });

                updateSelectedCount();
            });
        }

        checkboxes.forEach((checkbox) => {
            checkbox.addEventListener('change', updateSelectedCount);
        });

        restoreSelectedButton?.addEventListener('click', async function () {
            var selected = sortRestoreItems(getSelectedValues());

            if (selected.length === 0) {
                alert('Pilih minimal satu item untuk dipulihkan.');
                return;
            }

            if (!confirm('Pulihkan item yang dipilih?')) {
                return;
            }

            for (var i = 0; i < selected.length; i += 1) {
                var parts = selected[i].split(':');
                var url = "{{ url('/admin/recycle-bin/restore') }}/" + parts[0] + "/" + parts[1];

                var response = await fetch(url, {
                    method: 'POST',
                    headers: {
                        'X-CSRF-TOKEN': csrfToken,
                        'Accept': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest'
                    }
                });

                if (!response.ok) {
                    var data = await response.json().catch(() => ({ message: 'Gagal memulihkan data.' }));
                    alert(data.message || 'Gagal memulihkan data.');
                    return;
                }
            }

            window.location.reload();
        });

        deleteSelectedButton?.addEventListener('click', function () {
            var selected = getSelectedValues();

            if (selected.length === 0) {
                alert('Pilih minimal satu item untuk dihapus permanen.');
                return;
            }

            if (!confirm('Hapus permanen item yang dipilih? Tindakan ini tidak dapat dibatalkan.')) {
                return;
            }

            selectedDeleteForm.innerHTML = '<input type="hidden" name="_token" value="' + csrfToken + '"><input type="hidden" name="_method" value="DELETE"><input type="hidden" name="confirmation" value="HAPUS PERMANEN">';

            selected.forEach(function (value) {
                var input = document.createElement('input');
                input.type = 'hidden';
                input.name = 'items[]';
                input.value = value;
                selectedDeleteForm.appendChild(input);
            });

            selectedDeleteForm.submit();
        });

        deleteAllButton?.addEventListener('click', function () {
            if (!confirm('Hapus permanen seluruh isi recycle bin? Tindakan ini tidak dapat dibatalkan.')) {
                return;
            }

            var confirmation = prompt('Ketik HAPUS PERMANEN untuk menghapus seluruh isi recycle bin.');

            if (confirmation === null) {
                return;
            }

            if (confirmation !== 'HAPUS PERMANEN') {
                alert('Konfirmasi tidak sesuai. Data tidak dihapus.');
                return;
            }

            deleteAllForm.innerHTML = '<input type="hidden" name="_token" value="' + csrfToken + '"><input type="hidden" name="_method" value="DELETE"><input type="hidden" name="confirmation" value="HAPUS PERMANEN">';
            deleteAllForm.submit();
        });

        updateSelectedCount();

        document.querySelectorAll('[data-force-delete-form]').forEach(function (form) {
            form.addEventListener('submit', function (event) {
                if (!confirmForceDelete(form)) {
                    event.preventDefault();
                }
            });
        });
    }

    document.addEventListener('turbo:load', initRecycleBinPage);

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initRecycleBinPage, { once: true });
    } else {
        initRecycleBinPage();
    }
</script>
@endsection

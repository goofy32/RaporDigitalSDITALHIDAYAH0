@extends('layouts.wali_kelas.app')

@section('title', 'Data Absensi')

@push('meta')
<meta name="turbo-cache-control" content="no-cache">
@endpush

@section('content')
<div
    x-data="absensiBulkEditor(window.waliKelasAbsensiData)"
    x-cloak
    class="p-4 bg-white mt-14"
>
    <div class="flex flex-col gap-4 mb-6 md:flex-row md:items-center md:justify-between">
        <div>
            <h2 class="text-2xl font-bold text-green-700">Data Absensi</h2>
            <p class="text-sm text-gray-500 mt-1">Semester {{ $currentSemester }} - kelola rekap absensi semua siswa sekaligus.</p>
        </div>

        <div class="flex items-center gap-2">
            <button
                type="button"
                @click="toggleEditMode()"
                class="inline-flex items-center justify-center rounded-lg border border-green-700 px-4 py-2 text-sm font-medium text-green-700 transition hover:bg-green-50"
                x-text="editMode ? 'Batal' : 'Edit'"
            ></button>
            <button
                type="button"
                x-show="editMode"
                x-cloak
                @click="saveAll()"
                :disabled="saving"
                class="inline-flex items-center justify-center rounded-lg bg-green-700 px-4 py-2 text-sm font-medium text-white transition hover:bg-green-800 disabled:cursor-not-allowed disabled:opacity-60"
            >
                <span x-show="!saving">Simpan</span>
                <span x-show="saving">Menyimpan...</span>
            </button>
        </div>
    </div>

    <div class="relative overflow-x-auto shadow-md sm:rounded-lg">
        <table class="w-full text-sm text-left text-gray-600">
            <thead class="bg-gray-50 text-xs uppercase text-gray-700">
                <tr>
                    <th class="px-6 py-3">No</th>
                    <th class="px-6 py-3">NIS</th>
                    <th class="px-6 py-3">Nama</th>
                    <th class="px-6 py-3">Sakit</th>
                    <th class="px-6 py-3">Izin</th>
                    <th class="px-6 py-3">Tanpa Keterangan</th>
                </tr>
            </thead>
            <tbody>
                <template x-if="rows.length === 0">
                    <tr class="bg-white">
                        <td colspan="6" class="px-6 py-4 text-center text-gray-500">Belum ada data siswa di kelas ini.</td>
                    </tr>
                </template>

                <template x-for="(row, index) in rows" :key="row.siswa_id">
                    <tr class="border-b bg-white hover:bg-gray-50">
                        <td class="px-6 py-4 font-medium text-gray-900" x-text="index + 1"></td>
                        <td class="px-6 py-4 text-gray-900" x-text="row.nis"></td>
                        <td class="px-6 py-4 font-medium text-gray-900" x-text="row.nama"></td>

                        <td class="px-6 py-4">
                            <template x-if="!editMode">
                                <span x-text="row.sakit"></span>
                            </template>
                            <template x-if="editMode">
                                <input
                                    type="number"
                                    min="0"
                                    x-model.number="row.sakit"
                                    class="block w-24 rounded-lg border border-gray-300 bg-gray-50 p-2 text-sm text-gray-900 focus:border-green-500 focus:ring-green-500"
                                >
                            </template>
                        </td>

                        <td class="px-6 py-4">
                            <template x-if="!editMode">
                                <span x-text="row.izin"></span>
                            </template>
                            <template x-if="editMode">
                                <input
                                    type="number"
                                    min="0"
                                    x-model.number="row.izin"
                                    class="block w-24 rounded-lg border border-gray-300 bg-gray-50 p-2 text-sm text-gray-900 focus:border-green-500 focus:ring-green-500"
                                >
                            </template>
                        </td>

                        <td class="px-6 py-4">
                            <template x-if="!editMode">
                                <span x-text="row.tanpa_keterangan"></span>
                            </template>
                            <template x-if="editMode">
                                <input
                                    type="number"
                                    min="0"
                                    x-model.number="row.tanpa_keterangan"
                                    class="block w-28 rounded-lg border border-gray-300 bg-gray-50 p-2 text-sm text-gray-900 focus:border-green-500 focus:ring-green-500"
                                >
                            </template>
                        </td>
                    </tr>
                </template>
            </tbody>
        </table>
    </div>
</div>
@endsection

@push('scripts')
<script>
    window.waliKelasAbsensiData = {
        rows: @json($absensiData),
        csrfToken: @json(csrf_token()),
        bulkSaveUrl: @json(route('wali_kelas.absence.bulk-save')),
    };

    window.absensiBulkEditor = function (config) {
        return {
            editMode: false,
            saving: false,
            rows: [],
            originalRows: [],
            csrfToken: config.csrfToken,
            bulkSaveUrl: config.bulkSaveUrl,
            init() {
                this.rows = this.normalizeRows(config.rows || []);
                this.originalRows = this.clone(this.rows);
            },
            clone(value) {
                return JSON.parse(JSON.stringify(value));
            },
            normalizeRows(rows) {
                return rows.map((row) => ({
                    siswa_id: Number(row.siswa_id),
                    nis: row.nis,
                    nama: row.nama,
                    sakit: Number(row.sakit || 0),
                    izin: Number(row.izin || 0),
                    tanpa_keterangan: Number(row.tanpa_keterangan || 0),
                }));
            },
            toggleEditMode() {
                if (this.saving) {
                    return;
                }

                if (this.editMode) {
                    this.rows = this.clone(this.originalRows);
                    this.editMode = false;
                    return;
                }

                this.editMode = true;
            },
            getErrorMessage(payload) {
                if (payload && payload.errors) {
                    return Object.values(payload.errors)
                        .flat()
                        .join("\n");
                }

                return (payload && payload.message) || "Terjadi kesalahan saat menyimpan data.";
            },
            async saveAll() {
                this.saving = true;

                try {
                    const response = await fetch(this.bulkSaveUrl, {
                        method: "POST",
                        headers: {
                            "Content-Type": "application/json",
                            "Accept": "application/json",
                            "X-CSRF-TOKEN": this.csrfToken,
                        },
                        body: JSON.stringify({ rows: this.rows }),
                    });

                    const payload = await response.json().catch(() => ({
                        success: false,
                        message: "Respons server tidak valid.",
                    }));

                    if (!response.ok || !payload.success) {
                        throw payload;
                    }

                    this.rows = this.normalizeRows(payload.rows || []);
                    this.originalRows = this.clone(this.rows);
                    this.editMode = false;

                    Swal.fire("Berhasil", payload.message, "success");
                } catch (error) {
                    Swal.fire("Gagal", this.getErrorMessage(error), "error");
                } finally {
                    this.saving = false;
                }
            },
        };
    };
</script>
@endpush

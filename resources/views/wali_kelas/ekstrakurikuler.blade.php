@extends('layouts.wali_kelas.app')

@section('title', 'Data Ekstrakurikuler')

@push('meta')
<meta name="turbo-cache-control" content="no-cache">
@endpush

@section('content')
<script>
    window.waliKelasEkstrakurikulerData = {
        students: @json($siswas->map(fn ($siswa) => ['id' => (int) $siswa->id, 'nis' => $siswa->nis, 'nama' => $siswa->nama])->values()),
        ekskulData: @json($ekskulData),
        masterEkskul: @json($masterEkskul->map(fn ($item) => ['id' => (int) $item->id, 'nama' => $item->nama_ekstrakurikuler])->values()),
        pramukaId: @json($pramukaId),
        csrfToken: @json(csrf_token()),
        bulkSaveUrl: @json(route('wali_kelas.ekstrakurikuler.bulk-save')),
    };

    window.ekstrakurikulerBulkEditor = function (incomingConfig) {
        const config = incomingConfig && typeof incomingConfig === 'object' ? incomingConfig : {};
        const initialStudents = Array.isArray(config.students) ? config.students : [];
        const initialEkskulData = config.ekskulData && typeof config.ekskulData === 'object' ? config.ekskulData : {};
        const initialMasterEkskul = Array.isArray(config.masterEkskul) ? config.masterEkskul : [];

        return {
            editMode: false,
            saving: false,
            deletedIds: [],
            rowErrors: {},
            students: [],
            originalStudents: [],
            studentDirectory: initialStudents,
            masterEkskul: initialMasterEkskul,
            pramukaId: config.pramukaId || null,
            csrfToken: config.csrfToken || '',
            bulkSaveUrl: config.bulkSaveUrl || '',
            lastSubmittedKeys: [],
            localKeyCounter: 0,
            init() {
                this.students = this.buildStudents(initialEkskulData);
                this.originalStudents = this.clone(this.students);
            },
            clone(value) {
                return JSON.parse(JSON.stringify(value));
            },
            buildStudents(ekskulData) {
                const safeEkskulData = ekskulData && typeof ekskulData === 'object' ? ekskulData : {};

                return this.studentDirectory.map((student) => {
                    return this.normalizeStudent(student, safeEkskulData[String(student.id)] || []);
                });
            },
            normalizeStudent(student, rows) {
                const normalizedRows = Array.isArray(rows) && rows.length > 0
                    ? rows.map((row) => this.normalizeRow(student.id, row))
                    : [this.makeEmptyRow(student.id, true)];

                return {
                    id: Number(student.id),
                    nis: student.nis,
                    nama: student.nama,
                    rows: normalizedRows,
                };
            },
            nextLocalKey(siswaId) {
                this.localKeyCounter += 1;
                return 'row-' + siswaId + '-' + this.localKeyCounter + '-' + Date.now();
            },
            normalizeRow(siswaId, row = {}) {
                const ekstrakurikulerId = row.ekstrakurikuler_id
                    ? Number(row.ekstrakurikuler_id)
                    : null;

                return {
                    localKey: row.localKey || this.nextLocalKey(siswaId),
                    id: row.id ? Number(row.id) : null,
                    siswa_id: Number(row.siswa_id || siswaId),
                    ekstrakurikuler_id: ekstrakurikulerId,
                    ekstrakurikuler_nama: row.ekstrakurikuler_nama || this.findEkskulName(ekstrakurikulerId),
                    deskripsi: row.deskripsi || '',
                    userTouched: Boolean(row.id || row.userTouched),
                    wasAdded: Boolean(row.wasAdded),
                };
            },
            makeEmptyRow(siswaId, usePramukaDefault = false) {
                const defaultEkskulId = null;

                return this.normalizeRow(siswaId, {
                    ekstrakurikuler_id: defaultEkskulId,
                    ekstrakurikuler_nama: this.findEkskulName(defaultEkskulId),
                    userTouched: false,
                    wasAdded: false,
                });
            },
            findEkskulName(ekstrakurikulerId) {
                if (!ekstrakurikulerId) {
                    return '-';
                }

                const selected = this.masterEkskul.find((item) => Number(item.id) === Number(ekstrakurikulerId));
                return selected ? selected.nama : '-';
            },
            toggleEditMode() {
                if (this.saving) {
                    return;
                }

                this.rowErrors = {};
                this.editMode = true;
            },
            cancelEdit() {
                if (this.saving) {
                    return;
                }

                this.students = this.clone(this.originalStudents);
                this.deletedIds = [];
                this.rowErrors = {};
                this.lastSubmittedKeys = [];
                this.editMode = false;
            },
            markRowTouched(row) {
                row.userTouched = true;
                row.ekstrakurikuler_id = row.ekstrakurikuler_id ? Number(row.ekstrakurikuler_id) : null;
                row.ekstrakurikuler_nama = this.findEkskulName(row.ekstrakurikuler_id);
                delete this.rowErrors[row.localKey];
            },
            addRow(studentIndex) {
                const student = this.students[studentIndex];
                const newRow = this.makeEmptyRow(student.id, false);
                newRow.wasAdded = true;
                student.rows.push(newRow);
            },
            removeRow(studentIndex, rowIndex) {
                const student = this.students[studentIndex];
                const row = student.rows[rowIndex];

                if (row.id && !this.deletedIds.includes(row.id)) {
                    this.deletedIds.push(row.id);
                }

                delete this.rowErrors[row.localKey];
                student.rows.splice(rowIndex, 1);

                if (student.rows.length === 0) {
                    student.rows.push(this.makeEmptyRow(student.id, true));
                }
            },
            shouldPersistRow(row) {
                if (row.id) {
                    return true;
                }

                const hasDeskripsi = (row.deskripsi || '').trim() !== '';
                const hasChosenEkskul = row.userTouched && row.ekstrakurikuler_id !== null;

                return hasDeskripsi || hasChosenEkskul;
            },
            showRowAsFilled(row) {
                if (row.id) {
                    return true;
                }

                return this.shouldPersistRow(row);
            },
            requiresEkskulSelection(row) {
                if (row.id) {
                    return true;
                }

                return row.wasAdded || row.userTouched || (row.deskripsi || '').trim() !== '';
            },
            collectRows() {
                const rows = [];

                this.students.forEach((student) => {
                    student.rows.forEach((row) => {
                        if (!this.shouldPersistRow(row)) {
                            return;
                        }

                        rows.push({
                            localKey: row.localKey,
                            id: row.id,
                            siswa_id: student.id,
                            ekstrakurikuler_id: row.ekstrakurikuler_id,
                            deskripsi: row.deskripsi || '',
                        });
                    });
                });

                return rows;
            },
            validateRows() {
                this.rowErrors = {};
                const rows = this.collectRows();
                const seenPairs = {};
                let hasError = false;

                rows.forEach((row) => {
                    const messages = [];

                    if (!row.ekstrakurikuler_id) {
                        messages.push('Pilih ekstrakurikuler.');
                    }

                    if ((row.deskripsi || '').length > 500) {
                        messages.push('Deskripsi maksimal 500 karakter.');
                    }

                    const pairKey = row.siswa_id + ':' + row.ekstrakurikuler_id;
                    if (row.ekstrakurikuler_id && seenPairs[pairKey]) {
                        messages.push('Ekstrakurikuler ini sudah dipilih untuk siswa yang sama.');

                        if (!this.rowErrors[seenPairs[pairKey]]) {
                            this.rowErrors[seenPairs[pairKey]] = 'Ekstrakurikuler ini sudah dipilih untuk siswa yang sama.';
                        }
                    } else if (row.ekstrakurikuler_id) {
                        seenPairs[pairKey] = row.localKey;
                    }

                    if (messages.length > 0) {
                        hasError = true;
                        this.rowErrors[row.localKey] = messages.join(' ');
                    }
                });

                return !hasError;
            },
            getRowError(row) {
                return this.rowErrors[row.localKey] || '';
            },
            applyServerErrors(errors) {
                this.rowErrors = {};

                Object.entries(errors || {}).forEach(([field, messages]) => {
                    const match = field.match(/^rows\.(\d+)\./);
                    if (!match) {
                        return;
                    }

                    const rowIndex = Number(match[1]);
                    const localKey = this.lastSubmittedKeys[rowIndex];
                    if (!localKey) {
                        return;
                    }

                    this.rowErrors[localKey] = Array.isArray(messages) ? messages.join(' ') : String(messages);
                });
            },
            getErrorMessage(payload) {
                if (payload && payload.errors) {
                    return Object.values(payload.errors)
                        .flat()
                        .join('\n');
                }

                return payload?.message || 'Terjadi kesalahan saat menyimpan data ekstrakurikuler.';
            },
            async saveAll() {
                if (this.saving) {
                    return;
                }

                if (!this.validateRows()) {
                    window.Swal?.fire('Gagal', 'Periksa kembali data ekstrakurikuler yang masih bermasalah.', 'error');
                    return;
                }

                const emptyEkskul = this.students.some((student) =>
                    student.rows.some((row) => this.requiresEkskulSelection(row) && !row.ekstrakurikuler_id)
                );

                if (emptyEkskul) {
                    window.Swal?.fire(
                        'Tidak dapat menyimpan',
                        'Semua baris harus memiliki ekstrakurikuler yang dipilih.',
                        'warning'
                    );
                    return;
                }

                const rows = this.collectRows();
                this.lastSubmittedKeys = rows.map((row) => row.localKey);
                const payloadRows = rows.map(({ localKey, ...row }) => row);
                this.saving = true;

                try {
                    const response = await fetch(this.bulkSaveUrl, {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'Accept': 'application/json',
                            'X-CSRF-TOKEN': this.csrfToken,
                        },
                        body: JSON.stringify({
                            rows: payloadRows,
                            deleted_ids: this.deletedIds,
                        }),
                    });

                    const result = await response.json();

                    if (!response.ok || !result.success) {
                        this.applyServerErrors(result.errors || {});
                        throw new Error(this.getErrorMessage(result));
                    }

                    this.students = this.buildStudents(result.ekskul_data || {});
                    this.originalStudents = this.clone(this.students);
                    this.deletedIds = [];
                    this.rowErrors = {};
                    this.lastSubmittedKeys = [];
                    this.editMode = false;

                    window.Swal?.fire('Berhasil', result.message, 'success');
                } catch (error) {
                    window.Swal?.fire('Gagal', error.message || 'Terjadi kesalahan saat menyimpan data.', 'error');
                } finally {
                    this.saving = false;
                }
            },
        };
    };
</script>

<div
    x-data="ekstrakurikulerBulkEditor(window.waliKelasEkstrakurikulerData || {})"
    x-cloak
    class="bg-white p-4 mt-14"
>
    <div class="mb-6 flex flex-col gap-4 md:flex-row md:items-center md:justify-between">
        <div>
            <h2 class="text-2xl font-bold text-green-700">Data Ekstrakurikuler</h2>
            <p class="mt-1 text-sm text-gray-500">Kelola nilai ekstrakurikuler seluruh siswa dalam satu tabel.</p>
        </div>

        <div class="flex items-center gap-2">
            <button
                type="button"
                x-show="!editMode"
                @click="toggleEditMode()"
                class="inline-flex items-center justify-center rounded-lg border border-green-700 px-4 py-2 text-sm font-medium text-green-700 transition hover:bg-green-50"
            >
                Edit
            </button>

            <template x-if="editMode">
                <div class="flex items-center gap-2">
                    <button
                        type="button"
                        @click="cancelEdit()"
                        :disabled="saving"
                        class="inline-flex items-center justify-center rounded-lg border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 transition hover:bg-gray-50 disabled:cursor-not-allowed disabled:opacity-60"
                    >
                        Batal
                    </button>
                    <button
                        type="button"
                        @click="saveAll()"
                        :disabled="saving"
                        class="inline-flex items-center justify-center rounded-lg bg-green-700 px-4 py-2 text-sm font-medium text-white transition hover:bg-green-800 disabled:cursor-not-allowed disabled:opacity-60"
                    >
                        <span x-show="!saving">Simpan</span>
                        <span x-show="saving">Menyimpan...</span>
                    </button>
                </div>
            </template>
        </div>
    </div>

    <div class="overflow-x-auto rounded-lg shadow-md">
        <table class="w-full text-left text-sm text-gray-600">
            <thead class="bg-gray-50 text-xs uppercase text-gray-700">
                <tr>
                    <th class="px-6 py-3">No</th>
                    <th class="px-6 py-3">NIS</th>
                    <th class="px-6 py-3">Nama</th>
                    <th class="px-6 py-3">Ekstrakurikuler</th>
                    <th class="px-6 py-3">Deskripsi</th>
                    <th class="px-6 py-3 text-center" x-show="editMode" x-cloak>Aksi</th>
                </tr>
            </thead>

            <template x-if="students.length === 0">
                <tbody>
                    <tr class="bg-white">
                        <td colspan="6" class="px-6 py-4 text-center text-gray-500">Belum ada data siswa di kelas ini.</td>
                    </tr>
                </tbody>
            </template>

            <template x-for="(student, studentIndex) in students" :key="student.id">
                <tbody>
                    <template x-for="(row, rowIndex) in student.rows" :key="row.localKey">
                        <tr class="border-b bg-white hover:bg-gray-50">
                            <template x-if="rowIndex === 0">
                                <td
                                    class="px-6 py-4 align-top font-medium text-gray-900"
                                    :rowspan="student.rows.length + (editMode ? 1 : 0)"
                                    x-text="studentIndex + 1"
                                ></td>
                            </template>

                            <template x-if="rowIndex === 0">
                                <td
                                    class="px-6 py-4 align-top text-gray-900"
                                    :rowspan="student.rows.length + (editMode ? 1 : 0)"
                                    x-text="student.nis"
                                ></td>
                            </template>

                            <template x-if="rowIndex === 0">
                                <td
                                    class="px-6 py-4 align-top font-medium text-gray-900"
                                    :rowspan="student.rows.length + (editMode ? 1 : 0)"
                                    x-text="student.nama"
                                ></td>
                            </template>

                            <td class="px-6 py-4 align-top">
                                <template x-if="!editMode">
                                    <span x-text="showRowAsFilled(row) ? (row.ekstrakurikuler_nama || '-') : '-'"></span>
                                </template>
                                <template x-if="editMode">
                                    <div>
                                        <select
                                            @change="row.ekstrakurikuler_id = Number($event.target.value) || null; markRowTouched(row)"
                                            class="block w-full rounded-lg border border-gray-300 bg-gray-50 p-2 text-sm text-gray-900 focus:border-green-500 focus:ring-green-500"
                                        >
                                            <option value="">Pilih Ekstrakurikuler</option>
                                            <template x-for="option in masterEkskul" :key="option.id">
                                                <option
                                                    :value="Number(option.id)"
                                                    :selected="Number(option.id) === row.ekstrakurikuler_id"
                                                    x-text="option.nama"
                                                ></option>
                                            </template>
                                        </select>
                                        <p
                                            x-show="getRowError(row)"
                                            x-text="getRowError(row)"
                                            class="mt-1 text-xs text-red-600"
                                        ></p>
                                    </div>
                                </template>
                            </td>

                            <td class="px-6 py-4 align-top">
                                <template x-if="!editMode">
                                    <span x-text="row.deskripsi || '-'"></span>
                                </template>
                                <template x-if="editMode">
                                    <input
                                        type="text"
                                        x-model="row.deskripsi"
                                        @input="markRowTouched(row)"
                                        class="block w-full rounded-lg border border-gray-300 bg-gray-50 p-2 text-sm text-gray-900 focus:border-green-500 focus:ring-green-500"
                                        maxlength="500"
                                        placeholder="Tulis deskripsi"
                                    >
                                </template>
                            </td>

                            <td class="px-6 py-4 text-center align-top" x-show="editMode" x-cloak>
                                <button
                                    type="button"
                                    @click="removeRow(studentIndex, rowIndex)"
                                    class="inline-flex h-8 w-8 items-center justify-center rounded-full bg-red-50 text-lg font-semibold text-red-600 transition hover:bg-red-100"
                                    title="Hapus baris"
                                >
                                    &times;
                                </button>
                            </td>
                        </tr>
                    </template>

                    <tr x-show="editMode" x-cloak class="border-b bg-green-50/60">
                        <td colspan="3" class="px-6 py-3">
                            <button
                                type="button"
                                @click="addRow(studentIndex)"
                                class="text-sm font-medium text-green-700 transition hover:text-green-800"
                            >
                                + Tambah Ekskul
                            </button>
                        </td>
                    </tr>
                </tbody>
            </template>
        </table>
    </div>
</div>
@endsection

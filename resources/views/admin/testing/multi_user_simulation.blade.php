@extends('layouts.app')

@section('title', 'Simulasi Multi-User Staging')

@section('content')
    <div
        class="mt-14 space-y-6"
        data-page="staging-simulation"
        data-simulation-config='@json($simulationData)'
    >
        <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
            <div>
                <p class="text-sm font-semibold uppercase tracking-wide text-amber-700">Staging/testing tool</p>
                <h1 class="text-2xl font-bold text-gray-900">Simulasi Multi-User Guru</h1>
                <p class="mt-1 max-w-3xl text-sm text-gray-600">
                    Gunakan alat ini hanya untuk uji beban terkendali di staging. Simulasi PDF memakai jalur rapor wali
                    dengan konteks wali kelas dummy, sedangkan simulasi nilai hanya menerima data dummy/test/simulasi.
                </p>
            </div>

            <a
                href="{{ route('admin.dashboard') }}"
                class="inline-flex items-center justify-center rounded-md border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-green-500 focus:ring-offset-2"
            >
                Kembali
            </a>
        </div>

        <div class="rounded-lg border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900">
            <p class="font-semibold">Gunakan hanya di staging. Jangan jalankan saat guru sedang testing nyata.</p>
            <p class="mt-1">
                PDF cache miss akan masuk antrean dan dapat selesai satu per satu. Batas simulasi maksimal
                {{ $maxRequests }} request per aksi.
            </p>
        </div>

        <div class="grid gap-6 xl:grid-cols-[minmax(0,2fr)_minmax(320px,1fr)]">
            <section class="rounded-lg border border-gray-200 bg-white p-5 shadow-sm">
                <div class="mb-4 flex flex-col gap-1">
                    <h2 class="text-lg font-semibold text-gray-900">Simulasi PDF Rapor</h2>
                    <p class="text-sm text-gray-600">
                        Memicu request preview/download PDF seperti wali kelas, tetapi hanya untuk kelas dan siswa dummy.
                    </p>
                </div>

                <div class="grid gap-4 md:grid-cols-2">
                    <div>
                        <label for="pdfYear" class="block text-sm font-medium text-gray-700">Tahun ajaran</label>
                        <select id="pdfYear" data-simulation-pdf-year class="mt-1 w-full rounded-md border-gray-300 text-sm focus:border-green-500 focus:ring-green-500">
                            @foreach($years as $year)
                                <option value="{{ $year->id }}" @selected($year->is_active)>
                                    {{ $year->tahun_ajaran }} - Semester {{ $year->semester }}{{ $year->is_active ? ' (Aktif)' : '' }}
                                </option>
                            @endforeach
                        </select>
                    </div>

                    <div>
                        <label for="pdfType" class="block text-sm font-medium text-gray-700">Tipe rapor</label>
                        <select id="pdfType" data-simulation-pdf-type class="mt-1 w-full rounded-md border-gray-300 text-sm focus:border-green-500 focus:ring-green-500">
                            <option value="UTS">UTS</option>
                            <option value="UAS">UAS</option>
                        </select>
                    </div>

                    <div>
                        <label for="pdfClass" class="block text-sm font-medium text-gray-700">Kelas dummy/test</label>
                        <select id="pdfClass" data-simulation-pdf-class class="mt-1 w-full rounded-md border-gray-300 text-sm focus:border-green-500 focus:ring-green-500"></select>
                    </div>

                    <div>
                        <label for="pdfCount" class="block text-sm font-medium text-gray-700">Jumlah request</label>
                        <input id="pdfCount" data-simulation-pdf-count type="number" min="1" max="{{ $maxRequests }}" value="{{ min(20, $maxRequests) }}" class="mt-1 w-full rounded-md border-gray-300 text-sm focus:border-green-500 focus:ring-green-500">
                    </div>

                    <div class="md:col-span-2">
                        <label for="pdfStudents" class="block text-sm font-medium text-gray-700">Siswa dummy/test</label>
                        <select id="pdfStudents" data-simulation-pdf-students multiple size="6" class="mt-1 w-full rounded-md border-gray-300 text-sm focus:border-green-500 focus:ring-green-500"></select>
                        <p class="mt-1 text-xs text-gray-500">Pilih satu atau beberapa siswa. Request akan dibagi bergiliran ke siswa yang dipilih.</p>
                    </div>
                </div>

                <div class="mt-5 flex flex-wrap gap-3">
                    <button
                        type="button"
                        data-simulation-start-pdf="preview"
                        class="inline-flex items-center justify-center rounded-md bg-green-600 px-4 py-2 text-sm font-medium text-white hover:bg-green-700 focus:outline-none focus:ring-2 focus:ring-green-500 focus:ring-offset-2"
                    >
                        Simulasi 20 Preview PDF
                    </button>
                    <button
                        type="button"
                        data-simulation-start-pdf="download"
                        class="inline-flex items-center justify-center rounded-md bg-emerald-700 px-4 py-2 text-sm font-medium text-white hover:bg-emerald-800 focus:outline-none focus:ring-2 focus:ring-emerald-500 focus:ring-offset-2"
                    >
                        Simulasi 20 Download PDF
                    </button>
                </div>
            </section>

            <section class="rounded-lg border border-gray-200 bg-white p-5 shadow-sm">
                <div class="mb-4 flex items-start justify-between gap-3">
                    <div>
                        <h2 class="text-lg font-semibold text-gray-900">Queue Health</h2>
                        <p class="text-sm text-gray-600">Ringkasan antrean PDF/database queue.</p>
                    </div>
                    <button
                        type="button"
                        data-simulation-refresh-queue
                        class="rounded-md border border-gray-300 px-3 py-1.5 text-sm font-medium text-gray-700 hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-green-500 focus:ring-offset-2"
                    >
                        Refresh
                    </button>
                </div>

                <dl class="grid grid-cols-2 gap-3">
                    <div class="rounded-md bg-gray-50 p-3">
                        <dt class="text-xs font-medium uppercase text-gray-500">Pending jobs</dt>
                        <dd class="mt-1 text-2xl font-semibold text-gray-900" data-queue-pending>{{ $queueHealth['pending_jobs'] ?? '-' }}</dd>
                    </div>
                    <div class="rounded-md bg-gray-50 p-3">
                        <dt class="text-xs font-medium uppercase text-gray-500">Failed jobs</dt>
                        <dd class="mt-1 text-2xl font-semibold text-gray-900" data-queue-failed>{{ $queueHealth['failed_jobs'] ?? '-' }}</dd>
                    </div>
                </dl>
                <p class="mt-3 rounded-md bg-green-50 p-3 text-sm text-green-800" data-queue-reminder>
                    {{ $queueHealth['worker_reminder'] }}
                </p>
            </section>
        </div>

        <section class="rounded-lg border border-gray-200 bg-white p-5 shadow-sm">
            <div class="mb-4">
                <h2 class="text-lg font-semibold text-gray-900">Simulasi Simpan Nilai Dummy</h2>
                <p class="text-sm text-gray-600">
                    Simulasi ini menulis nilai berulang hanya untuk kelas, mapel, dan siswa dummy/test/simulasi. Ini adalah low-level load test admin, bukan fitur guru biasa.
                </p>
            </div>

            <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                <div>
                    <label for="scoreYear" class="block text-sm font-medium text-gray-700">Tahun ajaran</label>
                    <select id="scoreYear" data-simulation-score-year class="mt-1 w-full rounded-md border-gray-300 text-sm focus:border-green-500 focus:ring-green-500">
                        @foreach($years as $year)
                            <option value="{{ $year->id }}" @selected($year->is_active)>
                                {{ $year->tahun_ajaran }} - Semester {{ $year->semester }}{{ $year->is_active ? ' (Aktif)' : '' }}
                            </option>
                        @endforeach
                    </select>
                </div>

                <div>
                    <label for="scoreClass" class="block text-sm font-medium text-gray-700">Kelas dummy/test</label>
                    <select id="scoreClass" data-simulation-score-class class="mt-1 w-full rounded-md border-gray-300 text-sm focus:border-green-500 focus:ring-green-500"></select>
                </div>

                <div>
                    <label for="scoreSubject" class="block text-sm font-medium text-gray-700">Mata pelajaran dummy/test</label>
                    <select id="scoreSubject" data-simulation-score-subject class="mt-1 w-full rounded-md border-gray-300 text-sm focus:border-green-500 focus:ring-green-500"></select>
                </div>

                <div>
                    <label for="scoreCount" class="block text-sm font-medium text-gray-700">Jumlah simpan nilai</label>
                    <input id="scoreCount" data-simulation-score-count type="number" min="1" max="{{ $maxRequests }}" value="{{ min(20, $maxRequests) }}" class="mt-1 w-full rounded-md border-gray-300 text-sm focus:border-green-500 focus:ring-green-500">
                </div>

                <div class="md:col-span-2">
                    <label for="scoreStudents" class="block text-sm font-medium text-gray-700">Siswa dummy/test</label>
                    <select id="scoreStudents" data-simulation-score-students multiple size="5" class="mt-1 w-full rounded-md border-gray-300 text-sm focus:border-green-500 focus:ring-green-500"></select>
                </div>

                <div class="md:col-span-2 xl:col-span-3">
                    <label for="scoreConfirmation" class="block text-sm font-medium text-gray-700">Konfirmasi wajib</label>
                    <input
                        id="scoreConfirmation"
                        data-simulation-score-confirmation
                        type="text"
                        placeholder="{{ $scoreConfirmation }}"
                        class="mt-1 w-full rounded-md border-gray-300 text-sm focus:border-green-500 focus:ring-green-500"
                    >
                    <p class="mt-1 text-xs text-gray-500">Ketik persis: <span class="font-semibold">{{ $scoreConfirmation }}</span></p>
                </div>
            </div>

            <div class="mt-5">
                <button
                    type="button"
                    data-simulation-start-score
                    class="inline-flex items-center justify-center rounded-md bg-green-600 px-4 py-2 text-sm font-medium text-white hover:bg-green-700 focus:outline-none focus:ring-2 focus:ring-green-500 focus:ring-offset-2"
                >
                    Simulasi Simpan Nilai Dummy
                </button>
            </div>
        </section>

        <section class="rounded-lg border border-gray-200 bg-white p-5 shadow-sm">
            <div class="mb-4 flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <h2 class="text-lg font-semibold text-gray-900">Hasil Simulasi</h2>
                    <p class="text-sm text-gray-600">Daftar ini hanya menampilkan status request, bukan data nilai atau identitas sensitif.</p>
                </div>
                <div class="flex flex-wrap gap-2 text-sm">
                    <span class="rounded-full bg-green-50 px-3 py-1 font-medium text-green-700">Sukses: <span data-simulation-success>0</span></span>
                    <span class="rounded-full bg-blue-50 px-3 py-1 font-medium text-blue-700">Processing: <span data-simulation-processing>0</span></span>
                    <span class="rounded-full bg-red-50 px-3 py-1 font-medium text-red-700">Gagal: <span data-simulation-failed>0</span></span>
                </div>
            </div>

            <div data-simulation-message class="mb-3 hidden rounded-md border px-3 py-2 text-sm"></div>

            <div class="max-h-[420px] overflow-auto rounded-md border border-gray-200">
                <ul data-simulation-results class="divide-y divide-gray-200 text-sm">
                    <li class="p-3 text-gray-500">Belum ada simulasi berjalan.</li>
                </ul>
            </div>
        </section>
    </div>
@endsection

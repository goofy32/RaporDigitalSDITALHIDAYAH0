<div class="space-y-6">
    @php
        $reportKelas = $kelas ?? $siswa->kelas;
        $reportTahunAjaranId = $tahunAjaranId ?? session('tahun_ajaran_id');
        $reportSemester = $semester ?? optional($tahunAjaran ?? null)->semester ?? session('selected_semester', 1);
        $reportTahunAjaranText = optional($tahunAjaran ?? null)->tahun_ajaran
            ?? optional($reportKelas?->tahunAjaran)->tahun_ajaran
            ?? '-';
    @endphp
    <!-- Data Siswa -->
    <div>
        <h3 class="text-lg font-semibold mb-3">Data Siswa</h3>
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
            <div>
                <p class="text-sm text-gray-600">Nama Siswa</p>
                <p class="font-medium">{{ $siswa->nama }}</p>
            </div>
            <div>
                <p class="text-sm text-gray-600">NIS/NISN</p>
                <p class="font-medium">{{ $siswa->nis }} / {{ $siswa->nisn }}</p>
            </div>
            <div>
                <p class="text-sm text-gray-600">Kelas</p>
                <p class="font-medium">{{ $reportKelas?->nama_kelas ?? '-' }}</p>
            </div>
            <div>
                <p class="text-sm text-gray-600">Tahun Ajaran</p>
                <p class="font-medium">{{ $reportTahunAjaranText }}</p>
            </div>
        </div>
    </div>

    <!-- Nilai Akademik -->
<!-- Nilai Akademik -->
<div>
    <h3 class="text-lg font-semibold mb-3">Nilai Akademik</h3>
    <div class="overflow-x-auto">
        <table class="w-full text-sm text-left text-gray-500">
            <thead class="text-xs text-gray-700 uppercase bg-gray-50">
                <tr>
                    <th class="px-6 py-3">Mata Pelajaran</th>
                    <th class="px-6 py-3">Nilai</th>
                    <th class="px-6 py-3">Capaian Kompetensi</th>
                </tr>
            </thead>
            <tbody>
                @foreach($siswa->nilais->groupBy('mataPelajaran.nama_pelajaran') as $mapel => $nilaiGroup)
                @php
                    // Get nilai akhir rapor if available
                    $nilaiAkhir = $nilaiGroup->where('nilai_akhir_rapor', '!=', null)->first();
                    $nilai = $nilaiAkhir ? $nilaiAkhir->nilai_akhir_rapor : $nilaiGroup->avg('nilai_tp');
                    $nilai = number_format($nilai, 1);
                    
                    // Get capaian kompetensi
                    $capaian = '';
                    if ($nilaiAkhir && $nilaiAkhir->deskripsi) {
                        $capaian = $nilaiAkhir->deskripsi;
                    } else {
                        // Generate capaian berdasarkan nilai
                        if ($nilai >= 90) {
                            $capaian = "Siswa menunjukkan penguasaan yang sangat baik dalam mata pelajaran {$mapel}. Mampu memahami konsep, menerapkan, dan menganalisis dengan sangat baik.";
                        } elseif ($nilai >= 80) {
                            $capaian = "Siswa menunjukkan penguasaan yang baik dalam mata pelajaran {$mapel}. Mampu memahami konsep dan menerapkannya dengan baik.";
                        } elseif ($nilai >= 70) {
                            $capaian = "Siswa menunjukkan penguasaan yang cukup dalam mata pelajaran {$mapel}. Sudah mampu memahami konsep dasar dengan baik.";
                        } elseif ($nilai >= 60) {
                            $capaian = "Siswa menunjukkan penguasaan yang sedang dalam mata pelajaran {$mapel}. Perlu meningkatkan pemahaman konsep dasar.";
                        } else {
                            $capaian = "Siswa perlu bimbingan lebih lanjut dalam mata pelajaran {$mapel}. Disarankan untuk mengulang pembelajaran materi dasar.";
                        }
                    }
                @endphp
                <tr class="bg-white border-b">
                    <td class="px-6 py-4 font-medium text-gray-900">{{ $mapel }}</td>
                    <td class="px-6 py-4">{{ $nilai }}</td>
                    <td class="px-6 py-4">{{ $capaian }}</td>
                </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</div>

    <!-- Ekstrakurikuler -->
    <div>
        <h3 class="text-lg font-semibold mb-3">Ekstrakurikuler</h3>
        <div class="overflow-x-auto">
            <table class="w-full text-sm text-left text-gray-500">
                <thead class="text-xs text-gray-700 uppercase bg-gray-50">
                    <tr>
                        <th class="px-6 py-3">Nama Kegiatan</th>
                        <th class="px-6 py-3">Keterangan</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($siswa->nilaiEkstrakurikuler->where('tahun_ajaran_id', $reportTahunAjaranId)->where('semester', $reportSemester) as $ekskul)
                    <tr class="bg-white border-b">
                        <td class="px-6 py-4">{{ $ekskul->ekstrakurikuler->nama_ekstrakurikuler }}</td>
                        <td class="px-6 py-4">{{ $ekskul->deskripsi }}</td>
                    </tr>
                    @empty
                    <tr class="bg-white border-b">
                        <td colspan="2" class="px-6 py-4 text-center text-gray-500">
                            Belum ada data ekstrakurikuler
                        </td>
                    </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <!-- Kehadiran -->
    <div>
        <h3 class="text-lg font-semibold mb-3">Kehadiran</h3>
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
                <div class="bg-white p-4 rounded-lg shadow">
                    <p class="text-sm text-gray-600">Sakit</p>
                    @php
                        $absensi = $siswa->absensi
                            && $siswa->absensi->tahun_ajaran_id == $reportTahunAjaranId
                            && $siswa->absensi->semester == $reportSemester
                                ? $siswa->absensi
                                : null;
                    @endphp
                    <p class="text-2xl font-bold">{{ $absensi ? $absensi->sakit : 0 }}</p>
                </div>
                <div class="bg-white p-4 rounded-lg shadow">
                    <p class="text-sm text-gray-600">Izin</p>
                    <p class="text-2xl font-bold">{{ $absensi ? $absensi->izin : 0 }}</p>
                </div>
                <div class="bg-white p-4 rounded-lg shadow">
                    <p class="text-sm text-gray-600">Tanpa Keterangan</p>
                    <p class="text-2xl font-bold">{{ $absensi ? $absensi->tanpa_keterangan : 0 }}</p>
                </div>
            </div>
    </div>

    <!-- Status Data -->
    <div class="mt-6 p-4 bg-gray-50 rounded-lg">
        <h4 class="font-medium mb-2">Status Kelengkapan Data:</h4>
        <ul class="space-y-2">
            <li class="flex items-center">
                <span class="w-4 h-4 mr-2 {{ $siswa->nilais->count() > 0 ? 'text-green-500' : 'text-red-500' }}">
                    @if($siswa->nilais->count() > 0)
                        ✓
                    @else
                        ✗
                    @endif
                </span>
                Data Nilai Akademik
            </li>
            <li class="flex items-center">
                <span class="w-4 h-4 mr-2 {{ $siswa->absensi ? 'text-green-500' : 'text-red-500' }}">
                    @if($siswa->absensi)
                        ✓
                    @else
                        ✗
                    @endif
                </span>
                Data Kehadiran
            </li>
            <li class="flex items-center">
                <span class="w-4 h-4 mr-2 {{ $siswa->nilaiEkstrakurikuler->count() > 0 ? 'text-green-500' : 'text-yellow-500' }}">
                    @if($siswa->nilaiEkstrakurikuler->count() > 0)
                        ✓
                    @else
                        !
                    @endif
                </span>
                Data Ekstrakurikuler
            </li>
        </ul>
    </div>
</div>

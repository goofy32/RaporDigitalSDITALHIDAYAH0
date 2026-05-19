<div class="space-y-6">
    <!-- Header Info -->
    <div class="bg-gray-50 p-4 rounded-lg">
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
            <div>
                <h3 class="text-lg font-semibold text-green-700">Rapor {{ $report->type }}</h3>
                <p class="text-sm text-gray-600">Tahun Ajaran: {{ $report->tahun_ajaran }}</p>
                <p class="text-sm text-gray-600">Semester: {{ $report->semester == 1 ? 'Ganjil' : 'Genap' }}</p>
            </div>
            <div class="text-right">
                <p class="text-sm text-gray-600">Dicetak oleh: {{ $report->generator->nama ?? '-' }}</p>
                <p class="text-sm text-gray-600">Tanggal: {{ $report->generated_at->format('d M Y H:i') }}</p>
            </div>
        </div>
    </div>

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
                <p class="font-medium">{{ $siswa->kelas->nama_kelas ?? '-' }}</p>
            </div>
            <div>
                <p class="text-sm text-gray-600">Tahun Ajaran</p>
                <p class="font-medium">{{ $report->tahun_ajaran }}</p>
            </div>
        </div>
    </div>

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
                    @php
                        // Group nilai by mata pelajaran and filter reguler vs muatan lokal
                        $regulerNilai = $siswa->nilais
                            ->filter(function($nilai) {
                                return $nilai->mataPelajaran && !$nilai->mataPelajaran->is_muatan_lokal;
                            })
                            ->groupBy('mataPelajaran.nama_pelajaran');
                            
                        $mulokNilai = $siswa->nilais
                            ->filter(function($nilai) {
                                return $nilai->mataPelajaran && $nilai->mataPelajaran->is_muatan_lokal;
                            })
                            ->groupBy('mataPelajaran.nama_pelajaran');
                    @endphp
                    
                    @forelse($regulerNilai as $mapel => $nilaiGroup)
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
                    @empty
                        <tr class="bg-white border-b">
                            <td colspan="3" class="px-6 py-4 text-center text-gray-500">
                                Tidak ada data nilai mata pelajaran
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <!-- Muatan Lokal -->
    @if($mulokNilai->count() > 0)
    <div>
        <h3 class="text-lg font-semibold mb-3">Muatan Lokal</h3>
        <div class="overflow-x-auto">
            <table class="w-full text-sm text-left text-gray-500">
                <thead class="text-xs text-gray-700 uppercase bg-gray-50">
                    <tr>
                        <th class="px-6 py-3">Muatan Lokal</th>
                        <th class="px-6 py-3">Nilai</th>
                        <th class="px-6 py-3">Capaian Kompetensi</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($mulokNilai as $mapel => $nilaiGroup)
                        @php
                            $nilaiAkhir = $nilaiGroup->where('nilai_akhir_rapor', '!=', null)->first();
                            $nilai = $nilaiAkhir ? $nilaiAkhir->nilai_akhir_rapor : $nilaiGroup->avg('nilai_tp');
                            $nilai = number_format($nilai, 1);
                            
                            // Similar capaian logic
                            $capaian = '';
                            if ($nilaiAkhir && $nilaiAkhir->deskripsi) {
                                $capaian = $nilaiAkhir->deskripsi;
                            } else {
                                if ($nilai >= 90) {
                                    $capaian = "Siswa menunjukkan penguasaan yang sangat baik dalam {$mapel}.";
                                } elseif ($nilai >= 80) {
                                    $capaian = "Siswa menunjukkan penguasaan yang baik dalam {$mapel}.";
                                } elseif ($nilai >= 70) {
                                    $capaian = "Siswa menunjukkan penguasaan yang cukup dalam {$mapel}.";
                                } elseif ($nilai >= 60) {
                                    $capaian = "Siswa perlu meningkatkan pemahaman dalam {$mapel}.";
                                } else {
                                    $capaian = "Siswa membutuhkan bimbingan lebih lanjut dalam {$mapel}.";
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
    @endif

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
                @forelse($siswa->nilaiEkstrakurikuler as $ekskul)
                    <tr class="bg-white border-b">
                        <td class="px-6 py-4">{{ $ekskul->ekstrakurikuler->nama_ekstrakurikuler ?? '-' }}</td>
                        <td class="px-6 py-4">{{ $ekskul->deskripsi ?? '-' }}</td>
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
                <p class="text-2xl font-bold">{{ $siswa->absensi->sakit ?? 0 }}</p>
            </div>
            <div class="bg-white p-4 rounded-lg shadow">
                <p class="text-sm text-gray-600">Izin</p>
                <p class="text-2xl font-bold">{{ $siswa->absensi->izin ?? 0 }}</p>
            </div>
            <div class="bg-white p-4 rounded-lg shadow">
                <p class="text-sm text-gray-600">Tanpa Keterangan</p>
                <p class="text-2xl font-bold">{{ $siswa->absensi->tanpa_keterangan ?? 0 }}</p>
            </div>
        </div>
    </div>

    <!-- Status data -->
    <div class="bg-gray-50 p-4 rounded-lg">
        <p class="text-sm text-gray-500">File dokumen rapor: 
            @if($report->generated_file && Storage::disk('public')->exists($report->generated_file))
                <span class="text-green-600">Tersedia</span>
            @else
                <span class="text-red-600">Tidak tersedia</span>
            @endif
        </p>
    </div>
</div>

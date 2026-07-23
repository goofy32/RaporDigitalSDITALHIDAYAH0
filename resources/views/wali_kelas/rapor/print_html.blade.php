<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Rapor Tengah Semester I - {{ $siswa->nama }}</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: Arial, sans-serif;
            font-size: 11px;
            line-height: 1.2;
            background: white;
            color: black;
        }

        @page {
            size: A4;
            margin: 2cm 1.5cm;
        }

        @media print {
            body {
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
                margin: 0;
                padding: 0;
            }
            
            .no-print {
                display: none !important;
            }

            .container {
                padding: 0;
                margin: 0;
                box-shadow: none;
            }
        }

        .container {
            max-width: 210mm;
            margin: 0 auto;
            background: white;
            padding: 20px;
        }

        /* Print Button */
        .print-button {
            position: fixed;
            top: 20px;
            right: 20px;
            background: #007bff;
            color: white;
            border: none;
            padding: 12px 20px;
            border-radius: 5px;
            cursor: pointer;
            font-size: 14px;
            font-weight: bold;
            z-index: 1000;
            box-shadow: 0 2px 10px rgba(0,0,0,0.3);
        }

        .print-button:hover {
            background: #0056b3;
        }

        /* Header Section */
        .header {
            position: relative;
            text-align: center;
            margin-bottom: 20px;
            padding-bottom: 10px;
            border-bottom: 3px solid #000;
        }

        .header-logos {
            position: absolute;
            right: 0;
            top: 0;
            width: 120px;
            height: 100px;
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 5px;
        }

        .logo-top, .logo-bottom {
            width: 80px;
            height: 45px;
            border: 2px solid #000;
            display: flex;
            align-items: center;
            justify-content: center;
            background: white;
        }

        .logo-top img, .logo-bottom img {
            max-width: 70px;
            max-height: 40px;
            object-fit: contain;
        }

        .logo-placeholder {
            font-size: 8px;
            text-align: center;
            font-weight: bold;
            color: #666;
        }

        .school-info {
            margin-right: 130px;
            padding-top: 5px;
        }

        .school-info h1 {
            font-size: 12px;
            font-weight: bold;
            margin-bottom: 2px;
            text-transform: uppercase;
        }

        .school-info h2 {
            font-size: 14px;
            font-weight: bold;
            margin-bottom: 3px;
            text-transform: uppercase;
        }

        .school-info p {
            font-size: 10px;
            margin-bottom: 1px;
        }

        /* Title */
        .title {
            text-align: center;
            margin: 20px 0;
            font-size: 14px;
            font-weight: bold;
            text-transform: uppercase;
            letter-spacing: 1px;
        }

        /* Student Info */
        .student-info {
            margin-bottom: 20px;
        }

        .student-info table {
            width: 100%;
            border-collapse: collapse;
            border: 2px solid #000;
        }

        .student-info td {
            padding: 8px 12px;
            border: 1px solid #000;
            font-size: 11px;
            vertical-align: middle;
        }

        .student-info .label {
            width: 20%;
            font-weight: bold;
            background: #f8f8f8;
        }

        .student-info .colon {
            width: 2%;
            text-align: center;
            background: #f8f8f8;
        }

        .student-info .value {
            width: 28%;
            font-weight: bold;
            line-height: 1.35;
            overflow-wrap: anywhere;
            word-break: normal;
        }

        /* Main Table */
        .main-table {
            width: 100%;
            border-collapse: collapse;
            border: 2px solid #000;
            margin-bottom: 20px;
        }

        .main-table th {
            background-color: #f0f0f0;
            border: 1px solid #000;
            padding: 8px 6px;
            text-align: center;
            font-weight: bold;
            font-size: 10px;
            vertical-align: middle;
        }

        .main-table td {
            border: 1px solid #000;
            padding: 6px 8px;
            font-size: 10px;
            vertical-align: top;
        }

        .main-table .col-no {
            width: 4%;
            text-align: center;
            font-weight: bold;
        }

        .main-table .col-subject {
            width: 22%;
            font-weight: bold;
        }

        .main-table .col-grade {
            width: 8%;
            text-align: center;
            font-weight: bold;
        }

        .main-table .col-achievement-high,
        .main-table .col-achievement-low {
            width: 33%;
            text-align: justify;
            line-height: 1.4;
        }

        /* Muatan Lokal Section */
        .section-header {
            text-align: center;
            font-weight: bold;
            font-size: 11px;
            margin: 20px 0 10px 0;
            text-transform: uppercase;
        }

        .muatan-lokal-table {
            width: 100%;
            border-collapse: collapse;
            border: 2px solid #000;
            margin-bottom: 20px;
        }

        .muatan-lokal-table th {
            background-color: #f0f0f0;
            border: 1px solid #000;
            padding: 8px 6px;
            text-align: center;
            font-weight: bold;
            font-size: 10px;
            vertical-align: middle;
        }

        .muatan-lokal-table td {
            border: 1px solid #000;
            padding: 6px 8px;
            font-size: 10px;
            vertical-align: top;
        }

        .muatan-lokal-table .col-no {
            width: 4%;
            text-align: center;
            font-weight: bold;
        }

        .muatan-lokal-table .col-subject {
            width: 22%;
            font-weight: bold;
        }

        .muatan-lokal-table .col-grade {
            width: 8%;
            text-align: center;
            font-weight: bold;
        }

        .muatan-lokal-table .col-achievement-high,
        .muatan-lokal-table .col-achievement-low {
            width: 33%;
            text-align: justify;
            line-height: 1.4;
        }

        /* Ekstrakurikuler Table */
        .ekstrakurikuler-table {
            width: 100%;
            border-collapse: collapse;
            border: 2px solid #000;
            margin-bottom: 20px;
        }

        .ekstrakurikuler-table th {
            background-color: #f0f0f0;
            border: 1px solid #000;
            padding: 8px 6px;
            text-align: center;
            font-weight: bold;
            font-size: 10px;
            vertical-align: middle;
        }

        .ekstrakurikuler-table td {
            border: 1px solid #000;
            padding: 6px 8px;
            font-size: 10px;
            vertical-align: top;
        }

        .ekstrakurikuler-table .col-no {
            width: 4%;
            text-align: center;
            font-weight: bold;
        }

        .ekstrakurikuler-table .col-activity {
            width: 40%;
            font-weight: bold;
        }

        .ekstrakurikuler-table .col-description {
            width: 56%;
        }

        /* Catatan Guru */
        .catatan-section {
            margin-bottom: 20px;
        }

        .catatan-table {
            width: 100%;
            border-collapse: collapse;
            border: 2px solid #000;
        }

        .catatan-table th {
            background-color: #f0f0f0;
            border: 1px solid #000;
            padding: 8px;
            text-align: center;
            font-weight: bold;
            font-size: 11px;
        }

        .catatan-table td {
            border: 1px solid #000;
            padding: 12px;
            font-size: 10px;
            line-height: 1.5;
            text-align: justify;
            min-height: 80px;
            vertical-align: top;
        }

        /* Ketidakhadiran */
        .ketidakhadiran-section {
            margin-bottom: 25px;
        }

        .ketidakhadiran-table {
            width: 100%;
            border-collapse: collapse;
            border: 2px solid #000;
        }

        .ketidakhadiran-table th {
            background-color: #f0f0f0;
            border: 1px solid #000;
            padding: 8px;
            text-align: center;
            font-weight: bold;
            font-size: 11px;
        }

        .ketidakhadiran-table td {
            border: 1px solid #000;
            padding: 8px 12px;
            font-size: 10px;
            font-weight: normal;
        }

        /* Signatures */
        .signatures {
            margin-top: 30px;
        }

        .date-location {
            text-align: right;
            font-size: 10px;
            font-weight: bold;
            margin-bottom: 15px;
        }

        .signatures table {
            width: 100%;
            border-collapse: collapse;
        }

        .signatures td {
            width: 50%;
            text-align: center;
            vertical-align: top;
            padding: 0 10px;
            font-size: 10px;
        }

        .signatures .sig-title {
            font-weight: bold;
            margin-bottom: 8px;
        }

        .signatures .sig-space {
            height: 60px;
            margin: 15px 0;
        }

        .signatures .sig-name {
            font-weight: bold;
            text-decoration: underline;
            margin-bottom: 3px;
        }

        .signatures .sig-nip {
            font-size: 9px;
        }

        /* Empty rows */
        .empty-row td {
            height: 20px;
        }

        /* Responsive adjustments */
        @media print {
            body {
                font-size: 10px;
            }
            
            .container {
                padding: 0;
            }
            
            .header {
                margin-bottom: 15px;
                padding-bottom: 8px;
            }
            
            .title {
                margin: 15px 0;
            }
            
            .signatures {
                margin-top: 20px;
            }
        }
    </style>
</head>
<body>
    @php
        $reportKelas = $kelas ?? $siswa->kelas;
        $reportTahunAjaranId = $tahunAjaranId ?? session('tahun_ajaran_id');
        $reportSemester = $semester ?? optional($tahunAjaran ?? null)->semester ?? session('selected_semester', 1);
    @endphp
    <!-- Print Button (hidden when printing) -->
    <button class="print-button no-print" onclick="window.print()">
        🖨️ Cetak Rapor
    </button>

    <div class="container">
        <!-- Header -->
        <div class="header">
            <div class="header-logos">
                <div class="logo-top">
                    @if(file_exists(public_path('images/icons/sdit-logo.png')))
                        <img src="{{ asset('images/icons/sdit-logo.png') }}" alt="Logo SDIT">
                    @else
                        <div class="logo-placeholder">LOGO<br>SDIT</div>
                    @endif
                </div>
                <div class="logo-bottom">
                    @if(file_exists(public_path('images/icons/sdit-logo.png')))
                        <img src="{{ asset('images/icons/sdit-logo.png') }}" alt="Logo Sekolah">
                    @else
                        <div class="logo-placeholder">LOGO<br>SEKOLAH</div>
                    @endif
                </div>
            </div>
            
            <div class="school-info">
                <h1>Pemerintah Kota Bandung</h1>
                <h1>Dinas Pendidikan Kota Bandung</h1>
                <h2>SD IT Al-Hidayah Logam</h2>
                <p>Jl. Logam No.12 (Jl. Timah No.1)</p>
                <p>Telp. (022) 87507287</p>
            </div>
        </div>

        <!-- Title -->
        <div class="title">
            Rapor Tengah Semester I
        </div>

        <!-- Student Information -->
        <div class="student-info">
            <table>
                <tr>
                    <td class="label">Nama Siswa</td>
                    <td class="colon">:</td>
                    <td class="value">{{ strtoupper($siswa->nama) }}</td>
                    <td class="label">Kelas</td>
                    <td class="colon">:</td>
                    <td class="value">{{ $reportKelas?->nomor_kelas }}{{ $reportKelas?->nama_kelas }}</td>
                </tr>
                <tr>
                    <td class="label">NISN/NIS</td>
                    <td class="colon">:</td>
                    <td class="value">{{ $siswa->nisn ?: '-' }}/{{ $siswa->nis ?: '-' }}</td>
                    <td class="label">Tahun Pelajaran</td>
                    <td class="colon">:</td>
                    <td class="value">{{ $tahunAjaran->tahun_ajaran ?? '2024/2025' }}</td>
                </tr>
            </table>
        </div>

        <!-- Main Subjects Table -->
        <table class="main-table">
            <thead>
                <tr>
                    <th class="col-no">No.</th>
                    <th class="col-subject">Mata Pelajaran</th>
                    <th class="col-grade">Nilai</th>
                    <th class="col-achievement-high">Capaian Tertinggi</th>
                    <th class="col-achievement-low">Capaian Terendah</th>
                </tr>
            </thead>
            <tbody>
                @php 
                    $no = 1; 
                    $mainSubjects = $siswa->nilais->filter(function($nilai) use ($semester) {
                        return $nilai->mataPelajaran && 
                               !$nilai->mataPelajaran->is_muatan_lokal && 
                               $nilai->mataPelajaran->semester == $semester &&
                               $nilai->nilai_akhir_rapor !== null;
                    });
                @endphp
                
                @foreach($mainSubjects as $nilai)
                    <tr>
                        <td class="col-no">{{ $no++ }}</td>
                        <td class="col-subject">{{ $nilai->mataPelajaran->nama_pelajaran }}</td>
                        <td class="col-grade">{{ number_format($nilai->nilai_akhir_rapor, 0) }}</td>
                        @php
                            $capaian = \App\Http\Controllers\CapaianKompetensiController::generateCapaianTertinggiTerendah(
                                $siswa->id,
                                $nilai->mata_pelajaran_id,
                                $tahunAjaranId ?? session('tahun_ajaran_id')
                            );
                        @endphp
                        <td class="col-achievement-high">{{ $capaian['tertinggi'] }}</td>
                        <td class="col-achievement-low">{{ $capaian['terendah'] }}</td>
                    </tr>
                @endforeach

                {{-- Fill empty rows to maintain layout --}}
                @for($i = $no; $i <= 8; $i++)
                <tr class="empty-row">
                    <td class="col-no">{{ $i }}</td>
                    <td class="col-subject"></td>
                    <td class="col-grade"></td>
                    <td class="col-achievement-high"></td>
                    <td class="col-achievement-low"></td>
                </tr>
                @endfor
            </tbody>
        </table>

        <!-- Muatan Lokal Section -->
        <div class="section-header">Muatan Lokal</div>
        <table class="muatan-lokal-table">
            <thead>
                <tr>
                    <th class="col-no">No.</th>
                    <th class="col-subject">Muatan Lokal</th>
                    <th class="col-grade">Nilai</th>
                    <th class="col-achievement-high">Capaian Tertinggi</th>
                    <th class="col-achievement-low">Capaian Terendah</th>
                </tr>
            </thead>
            <tbody>
                @php 
                    $no = 1; 
                    $muatanLokal = $siswa->nilais->filter(function($nilai) use ($semester) {
                        return $nilai->mataPelajaran && 
                               $nilai->mataPelajaran->is_muatan_lokal && 
                               $nilai->mataPelajaran->semester == $semester &&
                               $nilai->nilai_akhir_rapor !== null;
                    });
                @endphp
                
                @foreach($muatanLokal as $nilai)
                    <tr>
                        <td class="col-no">{{ $no++ }}</td>
                        <td class="col-subject">{{ $nilai->mataPelajaran->nama_pelajaran }}</td>
                        <td class="col-grade">{{ number_format($nilai->nilai_akhir_rapor, 0) }}</td>
                        @php
                            $capaian = \App\Http\Controllers\CapaianKompetensiController::generateCapaianTertinggiTerendah(
                                $siswa->id,
                                $nilai->mata_pelajaran_id,
                                $tahunAjaranId ?? session('tahun_ajaran_id')
                            );
                        @endphp
                        <td class="col-achievement-high">{{ $capaian['tertinggi'] }}</td>
                        <td class="col-achievement-low">{{ $capaian['terendah'] }}</td>
                    </tr>
                @endforeach
                
                {{-- Fill empty rows --}}
                @for($i = $no; $i <= 5; $i++)
                <tr class="empty-row">
                    <td class="col-no">{{ $i }}</td>
                    <td class="col-subject"></td>
                    <td class="col-grade"></td>
                    <td class="col-achievement-high"></td>
                    <td class="col-achievement-low"></td>
                </tr>
                @endfor
            </tbody>
        </table>

        <!-- Ekstrakurikuler Section -->
        <div class="section-header">Ekstrakurikuler</div>
        <table class="ekstrakurikuler-table">
            <thead>
                <tr>
                    <th class="col-no">No.</th>
                    <th class="col-activity">Kegiatan Ekstrakurikuler</th>
                    <th class="col-description">Keterangan</th>
                </tr>
            </thead>
            <tbody>
                @php $no = 1; @endphp
                @foreach($siswa->nilaiEkstrakurikuler->where('tahun_ajaran_id', $reportTahunAjaranId)->where('semester', $reportSemester) as $nilaiEkskul)
                <tr>
                    <td class="col-no">{{ $no++ }}</td>
                    <td class="col-activity">{{ $nilaiEkskul->ekstrakurikuler->nama_ekstrakurikuler }}</td>
                    <td class="col-description">{{ $nilaiEkskul->deskripsi ?? $nilaiEkskul->predikat }}</td>
                </tr>
                @endforeach
                
                {{-- Fill empty rows --}}
                @for($i = $no; $i <= 5; $i++)
                <tr class="empty-row">
                    <td class="col-no">{{ $i }}</td>
                    <td class="col-activity"></td>
                    <td class="col-description"></td>
                </tr>
                @endfor
            </tbody>
        </table>

        <!-- Catatan Guru Section -->
        <div class="catatan-section">
            <table class="catatan-table">
                <thead>
                    <tr>
                        <th>Catatan Guru</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td>
                            @php
                                $catatanGuru = $siswa->catatanSiswa()
                                    ->where('tahun_ajaran_id', $reportTahunAjaranId)
                                    ->where('semester', $reportSemester)
                                    ->where('type', 'umum')
                                    ->first();
                                if ($catatanGuru && $catatanGuru->catatan) {
                                    echo $catatanGuru->catatan;
                                } else {
                                    echo 'Alhamdulillah ananda ' . $siswa->nama . ' dalam semester ' . ($semester ?? 1) . ' ini sudah mengikuti pembelajaran dengan baik secara keseluruhan, untuk ananda ' . $siswa->nama . ' lebih percaya diri lagi dalam segala hal ya, ibu yakin ' . $siswa->nama . ' bisa dan banyak belajar di rumah ya, kemudian tingkatkan lagi prestasinya di semester berikutnya ya, tidak lupa untuk sholat lima waktunya.';
                                }
                            @endphp
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <!-- Ketidakhadiran Section -->
        <div class="ketidakhadiran-section">
            <table class="ketidakhadiran-table">
                <thead>
                    <tr>
                        <th>Ketidakhadiran</th>
                    </tr>
                </thead>
                <tbody>
                    @php
                        $absensi = $siswa->absensi()
                            ->where('semester', $reportSemester)
                            ->where('tahun_ajaran_id', $reportTahunAjaranId)
                            ->first();
                    @endphp
                    <tr>
                        <td>Sakit : {{ $absensi->sakit ?? 0 }} Hari</td>
                    </tr>
                    <tr>
                        <td>Izin : {{ $absensi->izin ?? 0 }} Hari</td>
                    </tr>
                    <tr>
                        <td>Tanpa Keterangan : {{ $absensi->tanpa_keterangan ?? 0 }} Hari</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <!-- Date and Signatures -->
        <div class="date-location">
            Bandung, {{ now()->format('d F Y') }}
        </div>

        <div class="signatures">
            <table>
                <tr>
                    <td>
                        <div class="sig-title">Mengetahui :</div>
                        <div class="sig-title">Kepala Sekolah</div>
                        <div class="sig-space"></div>
                        <div class="sig-name">{{ $profilSekolah->kepala_sekolah ?? 'M. Tsabit Mujahid, M.Pd.I.' }}</div>
                        <div class="sig-nip">NUPTK {{ $profilSekolah->nip_kepala_sekolah ?? '4759763664130152' }}</div>
                    </td>
                    <td>
                        <div class="sig-title">Guru Kelas,</div>
                        <div class="sig-space"></div>
                        <div class="sig-name">{{ $waliKelas->nama }}</div>
                        <div class="sig-nip">NUPTK {{ $waliKelas->nuptk ?? '3438761662220002' }}</div>
                    </td>
                </tr>
            </table>
        </div>
    </div>

    <script>
        // Auto print when page loads
        window.addEventListener('load', function() {
            setTimeout(function() {
                window.print();
            }, 1000);
        });

        // Handle print dialog close
        window.addEventListener('afterprint', function() {
            if (confirm('Tutup halaman ini dan kembali ke daftar siswa?')) {
                window.close();
                setTimeout(function() {
                    window.location.href = '{{ route("wali_kelas.rapor.print_index") }}';
                }, 100);
            }
        });

        // Handle escape key to close
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                if (confirm('Tutup halaman ini?')) {
                    window.close();
                    setTimeout(function() {
                        window.location.href = '{{ route("wali_kelas.rapor.print_index") }}';
                    }, 100);
                }
            }
        });
    </script>
</body>
</html>

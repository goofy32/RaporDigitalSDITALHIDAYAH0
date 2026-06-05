<?php

namespace Database\Seeders;

use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class TestDataSeeder extends Seeder
{
    public function run(): void
    {
        $now = now();
        $passwordHash = bcrypt('password123');

        $maleNames = [
            'Muhammad Farhan', 'Ahmad Zaki', 'Abdullah Hafizh', 'Umar Farouq',
            'Ali Murtadho', 'Ibrahim Hasan', 'Yusuf Rabbani', 'Ismail Fauzan',
            'Idris Mubarak', 'Nuh Saifullah', 'Yahya Taufiq', 'Ilyas Ridwan',
            'Daud Amanullah', 'Sulaiman Firdaus', 'Musa Kamal', 'Harun Rashid',
            'Isa Abdurrahman', 'Zakaria Nabil', 'Luqman Hakim', 'Sholeh Maulana',
            'Bilal Akbar', 'Hamzah Wicaksono', 'Khalid Syahid', 'Zaid Muttaqin',
            'Jabir Fadhlillah', 'Anas Mukhlis', 'Ubay Karimi', "Sa'ad Ikhwan",
            'Ammar Dzikri', 'Miqdad Fikri',
        ];

        $femaleNames = [
            'Fatimah Azzahra', 'Aisyah Nur', 'Khadijah Salsabila', 'Maryam Fadhilah',
            'Zainab Hasanah', 'Ruqayyah Naila', 'Ummu Kultsum', 'Hafshah Nabila',
            'Asma Bilqis', 'Sumayyah Rania', 'Aminah Syifa', 'Hajar Qotrunnada',
            'Safiyyah Layla', 'Juwairiyyah Salma', 'Maymunah Rahma', 'Ummu Salamah',
            'Ramlah Husna', 'Nusaibah Kamila', 'Khawlah Azzah', "Dhuba'ah Fitri",
            'Arwa Hanifah', 'Bushra Afifah', 'Durrah Nadhirah', 'Fakhitah Zahra',
            'Ghuzayyah Alimah', 'Halimah Mutiara', 'Ibnat Karimah', 'Jumanah Tsabita',
            'Lubabah Wardah', 'Mulaykah Sabrina',
        ];

        $fatherNames = ['Ahmad', 'Muhammad', 'Abdullah', 'Abdurrahman', 'Umar', 'Ali', 'Ibrahim', 'Hasan', 'Husein', 'Khalid'];
        $motherNames = ['Siti Fatimah', 'Nur Aisyah', 'Siti Aminah', 'Khadijah', 'Maryam', 'Zainab', 'Hafshah', 'Asma', 'Sumayyah', 'Ruqayyah'];
        $jobs = ['Wiraswasta', 'PNS', 'Guru', 'Dokter', 'Petani', 'Pedagang', 'Karyawan Swasta', 'TNI/Polri'];

        $classes = [
            ['id' => 1, 'nomor_kelas' => 1, 'nama_kelas' => 'Ubay'],
            ['id' => 2, 'nomor_kelas' => 1, 'nama_kelas' => 'Zaid'],
            ['id' => 3, 'nomor_kelas' => 2, 'nama_kelas' => "Sa'ad"],
            ['id' => 4, 'nomor_kelas' => 2, 'nama_kelas' => 'Abu Ubaidah'],
            ['id' => 5, 'nomor_kelas' => 3, 'nama_kelas' => "Sa'id"],
            ['id' => 6, 'nomor_kelas' => 3, 'nama_kelas' => 'Abdurrahman'],
            ['id' => 7, 'nomor_kelas' => 4, 'nama_kelas' => 'Zubair'],
            ['id' => 8, 'nomor_kelas' => 4, 'nama_kelas' => 'Thalhah'],
            ['id' => 9, 'nomor_kelas' => 5, 'nama_kelas' => 'Ali bin Abi Thalib'],
            ['id' => 10, 'nomor_kelas' => 5, 'nama_kelas' => 'Utsman bin Affan'],
            ['id' => 11, 'nomor_kelas' => 6, 'nama_kelas' => 'Abu Bakar'],
            ['id' => 12, 'nomor_kelas' => 6, 'nama_kelas' => 'Umar'],
        ];

        $gurus = [
            ['id' => 1, 'nuptk' => '9742771000001', 'nama' => 'Eneng Syarah Fatimah Al Zahro, S.Pd.', 'jenis_kelamin' => 'Perempuan', 'jabatan' => 'guru_wali', 'username' => 'eneng.syarah'],
            ['id' => 2, 'nuptk' => '3438762000002', 'nama' => 'Mistiarini, S.Pd.', 'jenis_kelamin' => 'Perempuan', 'jabatan' => 'guru_wali', 'username' => 'mistiarini'],
            ['id' => 3, 'nuptk' => '4359772000003', 'nama' => 'Nesty Rosiyanti, S.Pd.', 'jenis_kelamin' => 'Perempuan', 'jabatan' => 'guru_wali', 'username' => 'nesty.rosiyanti'],
            ['id' => 4, 'nuptk' => '4941773000004', 'nama' => 'Amala Putih, S.Pd.I.', 'jenis_kelamin' => 'Perempuan', 'jabatan' => 'guru_wali', 'username' => 'amala.putih'],
            ['id' => 5, 'nuptk' => '6938764000005', 'nama' => 'Hetty Herdiani Rahayu, S.Pd.I.', 'jenis_kelamin' => 'Perempuan', 'jabatan' => 'guru_wali', 'username' => 'hetty.herdiani'],
            ['id' => 6, 'nuptk' => '2442763000006', 'nama' => 'Fujianti Suryani, S.Pd.', 'jenis_kelamin' => 'Perempuan', 'jabatan' => 'guru_wali', 'username' => 'fujianti.suryani'],
            ['id' => 7, 'nuptk' => '8338772000007', 'nama' => "Silvia Ma'rifatunnisa, S.Pd.", 'jenis_kelamin' => 'Perempuan', 'jabatan' => 'guru_wali', 'username' => 'silvia.marifat'],
            ['id' => 8, 'nuptk' => '4540762000008', 'nama' => 'Dewi Chahyani, S.Pd.', 'jenis_kelamin' => 'Perempuan', 'jabatan' => 'guru_wali', 'username' => 'dewi.chahyani'],
            ['id' => 9, 'nuptk' => '5157770000009', 'nama' => 'Mutia Zata Yumni, S.Pd.', 'jenis_kelamin' => 'Perempuan', 'jabatan' => 'guru_wali', 'username' => 'mutia.yumni'],
            ['id' => 10, 'nuptk' => '1657771000010', 'nama' => 'Titin Nurlela, S.Pd.', 'jenis_kelamin' => 'Perempuan', 'jabatan' => 'guru_wali', 'username' => 'titin.nurlela'],
            ['id' => 11, 'nuptk' => '9533758000011', 'nama' => 'Sariningsih, S.Pd.', 'jenis_kelamin' => 'Perempuan', 'jabatan' => 'guru_wali', 'username' => 'sariningsih'],
            ['id' => 12, 'nuptk' => '8652764000012', 'nama' => 'Siti Martiyani, S.Pd.', 'jenis_kelamin' => 'Perempuan', 'jabatan' => 'guru_wali', 'username' => 'siti.martiyani'],
            ['id' => 13, 'nuptk' => '9543760000013', 'nama' => 'Nur Emaliah, S.Pd.I.', 'jenis_kelamin' => 'Perempuan', 'jabatan' => 'guru_pengajar', 'username' => 'nur.emaliah'],
            ['id' => 14, 'nuptk' => '1000000000014', 'nama' => 'Biri Rachman, S.Pd.', 'jenis_kelamin' => 'Laki-laki', 'jabatan' => 'guru_pengajar', 'username' => 'biri.rachman'],
            ['id' => 15, 'nuptk' => '9147773000015', 'nama' => 'Ulfah Nahriah Rahman, S.Pd.', 'jenis_kelamin' => 'Perempuan', 'jabatan' => 'guru_pengajar', 'username' => 'ulfah.nahriah'],
            ['id' => 16, 'nuptk' => '1000000000016', 'nama' => 'M. Robby A. Saputra, S.Pd.', 'jenis_kelamin' => 'Laki-laki', 'jabatan' => 'guru_pengajar', 'username' => 'robby.saputra'],
            ['id' => 17, 'nuptk' => '1000000000017', 'nama' => 'Nabila Putri Alifa, S.T.P.', 'jenis_kelamin' => 'Perempuan', 'jabatan' => 'guru_pengajar', 'username' => 'nabila.alifa'],
            ['id' => 18, 'nuptk' => '1000000000018', 'nama' => 'Rr. Ria Pujiasih, S.T.P.', 'jenis_kelamin' => 'Perempuan', 'jabatan' => 'guru_pengajar', 'username' => 'ria.pujiasih'],
        ];

        $truncateTables = [
            'capaian_custom',
            'capaian_templates',
            'capaian_range',
            'nilai_ekstrakurikuler',
            'ekstrakurikulers',
            'absensis',
            'catatan_siswa',
            'catatan_mata_pelajaran',
            'nilais',
            'tujuan_pembelajarans',
            'lingkup_materis',
            'bobot_nilais',
            'kkms',
            'mata_pelajarans',
            'guru_kelas',
            'siswas',
            'kelas',
            'gurus',
            'tahun_ajarans',
            'report_generations',
            'report_templates',
            'report_template_kelas',
            'report_mappings',
            'report_placeholders',
            'prestasis',
            'pembelajaran_siswa',
            'pembelajarans',
            'semester_snapshots',
            'format_rapor',
            'settings',
            'notifications',
            'notification_reads',
            'gemini_chats',
            'audit_logs',
            'batch_downloads',
        ];

        DB::statement('SET FOREIGN_KEY_CHECKS=0');

        try {
            foreach ($truncateTables as $table) {
                DB::table($table)->truncate();
            }
        } finally {
            DB::statement('SET FOREIGN_KEY_CHECKS=1');
        }

        DB::table('tahun_ajarans')->insert([
            'id' => 1,
            'tahun_ajaran' => '2024/2025',
            'is_active' => 1,
            'tanggal_mulai' => '2024-07-15',
            'tanggal_selesai' => '2024-12-20',
            'semester' => 1,
            'deskripsi' => 'Semester Ganjil 2024/2025',
            'created_at' => $now,
            'updated_at' => $now,
            'deleted_at' => null,
        ]);

        DB::table('kelas')->insert(array_map(function (array $kelas) use ($now) {
            return [
                'id' => $kelas['id'],
                'nomor_kelas' => $kelas['nomor_kelas'],
                'nama_kelas' => $kelas['nama_kelas'],
                'tahun_ajaran_id' => 1,
                'created_at' => $now,
                'updated_at' => $now,
                'deleted_at' => null,
            ];
        }, $classes));

        $guruRows = [];
        foreach ($gurus as $guru) {
            $year = 1980 + (($guru['id'] - 1) % 16);
            $month = (($guru['id'] - 1) % 12) + 1;
            $day = (($guru['id'] * 2) % 28) + 1;

            $guruRows[] = [
                'id' => $guru['id'],
                'nuptk' => $guru['nuptk'],
                'nama' => $guru['nama'],
                'jenis_kelamin' => $guru['jenis_kelamin'],
                'tanggal_lahir' => Carbon::create($year, $month, $day)->toDateString(),
                'no_handphone' => '08'.str_pad((string) (770000000 + $guru['id']), 9, '0', STR_PAD_LEFT),
                'email' => $guru['username'].'@sditalhidayah.sch.id',
                'alamat' => 'Jl. Contoh No.'.$guru['id'].', Logam',
                'jabatan' => $guru['jabatan'],
                'photo' => null,
                'username' => $guru['username'],
                'password' => $passwordHash,
                'is_wali_kelas' => $guru['id'] <= 12 ? 1 : 0,
                'created_at' => $now,
                'updated_at' => $now,
                'deleted_at' => null,
            ];
        }
        DB::table('gurus')->insert($guruRows);

        $studentRows = [];
        $studentsByClass = [];
        $studentId = 1;

        foreach ($classes as $classIndex => $kelas) {
            $maleOffset = ($classIndex * 10) % count($maleNames);
            $femaleOffset = ($classIndex * 10) % count($femaleNames);
            $maleCounter = 0;
            $femaleCounter = 0;
            $studentsByClass[$kelas['id']] = [];

            for ($urut = 1; $urut <= 20; $urut++) {
                $isMale = $urut % 2 === 1;
                if ($isMale) {
                    $name = $maleNames[($maleOffset + $maleCounter) % count($maleNames)];
                    $gender = 'Laki-laki';
                    $maleCounter++;
                } else {
                    $name = $femaleNames[($femaleOffset + $femaleCounter) % count($femaleNames)];
                    $gender = 'Perempuan';
                    $femaleCounter++;
                }

                $fatherName = $fatherNames[($studentId - 1) % count($fatherNames)];
                $motherName = $motherNames[($studentId - 1) % count($motherNames)];
                $fatherJob = $jobs[($studentId - 1) % count($jobs)];
                $motherJob = $jobs[($studentId + 3) % count($jobs)];
                $birthYear = 2014 + (($studentId - 1) % 6);
                $birthMonth = (($studentId - 1) % 12) + 1;
                $birthDay = (($studentId * 3) % 28) + 1;

                $studentRows[] = [
                    'id' => $studentId,
                    'nis' => sprintf('%d%02d%03d', $kelas['nomor_kelas'], $kelas['id'], $urut),
                    'nisn' => sprintf('%010d', 1000000000 + $studentId),
                    'nama' => $name,
                    'tanggal_lahir' => Carbon::create($birthYear, $birthMonth, $birthDay)->toDateString(),
                    'jenis_kelamin' => $gender,
                    'agama' => 'Islam',
                    'alamat' => 'Jl. Contoh No.'.$studentId.', Logam',
                    'kelas_id' => $kelas['id'],
                    'nama_ayah' => $fatherName,
                    'nama_ibu' => $motherName,
                    'pekerjaan_ayah' => $fatherJob,
                    'pekerjaan_ibu' => $motherJob,
                    'wali_siswa' => null,
                    'pekerjaan_wali' => null,
                    'alamat_orangtua' => 'Jl. Contoh No.'.$studentId.', Logam',
                    'photo' => 'default-avatar.png',
                    'created_at' => $now,
                    'updated_at' => $now,
                    'tahun_ajaran_id' => 1,
                    'status' => 'aktif',
                    'is_naik_kelas' => null,
                    'kelas_tujuan_id' => null,
                    'deleted_at' => null,
                ];

                $studentsByClass[$kelas['id']][] = $studentId;
                $studentId++;
            }
        }
        DB::table('siswas')->insert($studentRows);

        $guruKelasRows = [];
        $guruKelasId = 1;

        foreach ($classes as $kelas) {
            $waliGuruId = $kelas['id'];

            $guruKelasRows[] = [
                'id' => $guruKelasId++,
                'guru_id' => $waliGuruId,
                'kelas_id' => $kelas['id'],
                'is_wali_kelas' => 1,
                'role' => 'wali_kelas',
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        foreach (range(1, 4) as $kelasId) {
            $guruKelasRows[] = [
                'id' => $guruKelasId++,
                'guru_id' => 13,
                'kelas_id' => $kelasId,
                'is_wali_kelas' => 0,
                'role' => 'pengajar',
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        foreach (range(5, 12) as $kelasId) {
            $guruKelasRows[] = [
                'id' => $guruKelasId++,
                'guru_id' => 14,
                'kelas_id' => $kelasId,
                'is_wali_kelas' => 0,
                'role' => 'pengajar',
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        foreach (range(1, 6) as $kelasId) {
            $guruKelasRows[] = [
                'id' => $guruKelasId++,
                'guru_id' => 15,
                'kelas_id' => $kelasId,
                'is_wali_kelas' => 0,
                'role' => 'pengajar',
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        foreach (range(7, 12) as $kelasId) {
            $guruKelasRows[] = [
                'id' => $guruKelasId++,
                'guru_id' => 16,
                'kelas_id' => $kelasId,
                'is_wali_kelas' => 0,
                'role' => 'pengajar',
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        foreach (range(1, 4) as $kelasId) {
            $guruKelasRows[] = [
                'id' => $guruKelasId++,
                'guru_id' => 18,
                'kelas_id' => $kelasId,
                'is_wali_kelas' => 0,
                'role' => 'pengajar',
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        foreach (range(5, 12) as $kelasId) {
            $guruKelasRows[] = [
                'id' => $guruKelasId++,
                'guru_id' => 17,
                'kelas_id' => $kelasId,
                'is_wali_kelas' => 0,
                'role' => 'pengajar',
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        DB::table('guru_kelas')->insert($guruKelasRows);

        $subjectRows = [];
        $subjectId = 1;

        foreach ($classes as $kelas) {
            $waliGuruId = $kelas['id'];
            $grade = $kelas['nomor_kelas'];
            $isLowerGrade = $grade <= 2;

            $waliSubjects = $isLowerGrade
                ? ['Pendidikan Pancasila', 'Matematika', 'Bahasa Indonesia', 'Seni Budaya', 'PLH']
                : ['Pendidikan Pancasila', 'Matematika', 'Bahasa Indonesia', 'IPAS', 'Seni Budaya', 'PLH'];

            foreach ($waliSubjects as $subjectName) {
                $subjectRows[] = [
                    'id' => $subjectId++,
                    'nama_pelajaran' => $subjectName,
                    'kelas_id' => $kelas['id'],
                    'tahun_ajaran_id' => 1,
                    'semester' => 1,
                    'is_muatan_lokal' => 0,
                    'allow_non_wali' => 0,
                    'guru_id' => $waliGuruId,
                    'lingkup_materi' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                    'deleted_at' => null,
                ];
            }

            $subjectRows[] = [
                'id' => $subjectId++,
                'nama_pelajaran' => 'Bahasa Sunda',
                'kelas_id' => $kelas['id'],
                'tahun_ajaran_id' => 1,
                'semester' => 1,
                'is_muatan_lokal' => 1,
                'allow_non_wali' => 0,
                'guru_id' => $waliGuruId,
                'lingkup_materi' => null,
                'created_at' => $now,
                'updated_at' => $now,
                'deleted_at' => null,
            ];

            $paiGuruId = $kelas['nomor_kelas'] <= 2 ? 13 : 14;
            $pjokGuruId = $kelas['nomor_kelas'] <= 3 ? 15 : 16;
            $englishGuruId = $kelas['nomor_kelas'] <= 2 ? 18 : 17;

            $subjectRows[] = [
                'id' => $subjectId++,
                'nama_pelajaran' => 'Pendidikan Agama dan Budi Pekerti',
                'kelas_id' => $kelas['id'],
                'tahun_ajaran_id' => 1,
                'semester' => 1,
                'is_muatan_lokal' => 0,
                'allow_non_wali' => 1,
                'guru_id' => $paiGuruId,
                'lingkup_materi' => null,
                'created_at' => $now,
                'updated_at' => $now,
                'deleted_at' => null,
            ];

            $subjectRows[] = [
                'id' => $subjectId++,
                'nama_pelajaran' => 'Pendidikan Jasmani, Olahraga dan Kesehatan',
                'kelas_id' => $kelas['id'],
                'tahun_ajaran_id' => 1,
                'semester' => 1,
                'is_muatan_lokal' => 1,
                'allow_non_wali' => 1,
                'guru_id' => $pjokGuruId,
                'lingkup_materi' => null,
                'created_at' => $now,
                'updated_at' => $now,
                'deleted_at' => null,
            ];

            $subjectRows[] = [
                'id' => $subjectId++,
                'nama_pelajaran' => 'Bahasa Inggris',
                'kelas_id' => $kelas['id'],
                'tahun_ajaran_id' => 1,
                'semester' => 1,
                'is_muatan_lokal' => 1,
                'allow_non_wali' => 1,
                'guru_id' => $englishGuruId,
                'lingkup_materi' => null,
                'created_at' => $now,
                'updated_at' => $now,
                'deleted_at' => null,
            ];
        }

        DB::table('mata_pelajarans')->insert($subjectRows);

        $kkmRows = [];
        foreach ($subjectRows as $subjectRow) {
            $kkmRows[] = [
                'mata_pelajaran_id' => $subjectRow['id'],
                'kelas_id' => $subjectRow['kelas_id'],
                'tahun_ajaran_id' => 1,
                'nilai' => 70,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }
        DB::table('kkms')->insert($kkmRows);

        DB::table('bobot_nilais')->insert([
            'tahun_ajaran_id' => 1,
            'bobot_tp' => 1,
            'bobot_lm' => 1,
            'bobot_as' => 2,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('settings')->insertOrIgnore([
            'key' => 'kkm_notification_complete_scores_only',
            'value' => '0',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('profil_sekolah')->updateOrInsert(
            ['id' => 1],
            [
                'logo' => null,
                'nama_instansi' => 'Dinas Pendidikan Kota Bandung',
                'nama_sekolah' => 'SDIT Al-Hidayah Logam',
                'tahun_pelajaran' => '2024/2025',
                'semester' => 1,
                'npsn' => '69986203',
                'kepala_sekolah' => 'M. Tsabit Mujahid, M.Pd.I.',
                'alamat' => 'Jl. Logam No.12 (Jl. Timah No.1)',
                'guru_kelas' => 24,
                'kode_pos' => '40287',
                'kelas' => 12,
                'telepon' => '(022) 87507287',
                'jumlah_siswa' => 240,
                'email_sekolah' => 'sditalhidayahlogam@gmail.com',
                'tempat_terbit' => 'Bandung',
                'tanggal_terbit' => '2025-06-20',
                'website' => 'https://rapordigitalsditalhidayah.my.id/',
                'created_at' => $now,
                'updated_at' => $now,
                'nip_kepala_sekolah' => '4759763664130152',
                'kelurahan' => 'Cijawura',
                'kecamatan' => 'Buah Batu',
                'kabupaten' => 'Bandung',
                'provinsi' => 'Jawa Barat',
                'nip_wali_kelas' => '-',
            ]
        );

        $lingkupMateriTitles = [
            'Memahami konsep dasar %s',
            'Menerapkan %s dalam kehidupan',
            'Menganalisis dan mengevaluasi %s',
        ];

        $tujuanTemplates = [
            'Siswa dapat menjelaskan konsep utama pada %s.',
            'Siswa dapat mengidentifikasi contoh yang tepat terkait %s.',
            'Siswa dapat menerapkan pemahaman pada %s.',
        ];

        $lingkupMateriRows = [];
        $tujuanRows = [];
        $subjectStructures = [];
        $lingkupMateriId = 1;
        $tujuanId = 1;

        foreach ($subjectRows as $subjectRow) {
            $tpCounter = 1;
            $subjectStructures[$subjectRow['id']] = [];

            foreach ($lingkupMateriTitles as $titleTemplate) {
                $lingkupTitle = sprintf($titleTemplate, $subjectRow['nama_pelajaran']);
                $currentLingkupMateriId = $lingkupMateriId++;

                $lingkupMateriRows[] = [
                    'id' => $currentLingkupMateriId,
                    'mata_pelajaran_id' => $subjectRow['id'],
                    'judul_lingkup_materi' => $lingkupTitle,
                    'is_active' => 1,
                    'created_at' => $now,
                    'updated_at' => $now,
                    'deleted_at' => null,
                ];

                $subjectStructures[$subjectRow['id']][] = [
                    'id' => $currentLingkupMateriId,
                    'judul' => $lingkupTitle,
                    'tps' => [],
                ];

                $lingkupIndex = count($subjectStructures[$subjectRow['id']]) - 1;

                foreach ($tujuanTemplates as $tujuanTemplate) {
                    $currentTujuanId = $tujuanId++;
                    $kodeTp = (string) $tpCounter++;

                    $tujuanRows[] = [
                        'id' => $currentTujuanId,
                        'lingkup_materi_id' => $currentLingkupMateriId,
                        'kode_tp' => $kodeTp,
                        'deskripsi_tp' => sprintf($tujuanTemplate, $lingkupTitle),
                        'created_at' => $now,
                        'updated_at' => $now,
                        'deleted_at' => null,
                    ];

                    $subjectStructures[$subjectRow['id']][$lingkupIndex]['tps'][] = [
                        'id' => $currentTujuanId,
                        'kode_tp' => $kodeTp,
                    ];
                }
            }
        }

        DB::table('lingkup_materis')->insert($lingkupMateriRows);
        DB::table('tujuan_pembelajarans')->insert($tujuanRows);

        $nilaiRows = [];
        $nilaiId = 1;
        $seedSubjectRows = array_slice(
            array_values(array_filter($subjectRows, fn (array $subjectRow) => $subjectRow['kelas_id'] === 1)),
            0,
            3
        );

        foreach ($studentsByClass[1] as $siswaId) {
            foreach ($seedSubjectRows as $subjectRow) {
                $mapelId = $subjectRow['id'];
                $allTpScores = [];
                $lmAverages = [];

                foreach ($subjectStructures[$mapelId] as $lmIndex => $lingkupMateri) {
                    $tpScoresPerLm = [];

                    foreach ($lingkupMateri['tps'] as $tpIndex => $tp) {
                        $nilaiTp = $this->generateScore($siswaId * 101 + $mapelId * 17 + ($lmIndex + 1) * 11 + ($tpIndex + 1) * 7);
                        $tpScoresPerLm[] = $nilaiTp;
                        $allTpScores[] = $nilaiTp;

                        $nilaiRows[] = [
                            'id' => $nilaiId++,
                            'siswa_id' => $siswaId,
                            'mata_pelajaran_id' => $mapelId,
                            'tujuan_pembelajaran_id' => $tp['id'],
                            'lingkup_materi_id' => $lingkupMateri['id'],
                            'nilai_tp' => $nilaiTp,
                            'nilai_lm' => null,
                            'nilai_akhir_semester' => null,
                            'created_at' => $now,
                            'updated_at' => $now,
                            'na_tp' => null,
                            'na_lm' => null,
                            'tp_number' => (int) $tp['kode_tp'],
                            'nilai_tes' => null,
                            'nilai_non_tes' => null,
                            'na_sumatif_semester' => null,
                            'nilai_akhir_rapor' => null,
                            'tahun_ajaran_id' => 1,
                            'deleted_at' => null,
                        ];
                    }

                    $nilaiLm = $this->average($tpScoresPerLm);
                    $lmAverages[] = $nilaiLm;

                    $nilaiRows[] = [
                        'id' => $nilaiId++,
                        'siswa_id' => $siswaId,
                        'mata_pelajaran_id' => $mapelId,
                        'tujuan_pembelajaran_id' => null,
                        'lingkup_materi_id' => $lingkupMateri['id'],
                        'nilai_tp' => null,
                        'nilai_lm' => $nilaiLm,
                        'nilai_akhir_semester' => null,
                        'created_at' => $now,
                        'updated_at' => $now,
                        'na_tp' => null,
                        'na_lm' => null,
                        'tp_number' => null,
                        'nilai_tes' => null,
                        'nilai_non_tes' => null,
                        'na_sumatif_semester' => null,
                        'nilai_akhir_rapor' => null,
                        'tahun_ajaran_id' => 1,
                        'deleted_at' => null,
                    ];
                }

                $naTp = $this->average($allTpScores);
                $naLm = $this->average($lmAverages);
                $nilaiTes = $this->generateScore($siswaId * 37 + $mapelId * 13 + 5);
                $nilaiNonTes = $this->generateScore($siswaId * 29 + $mapelId * 19 + 9);
                $nilaiAkhirSemester = round(($nilaiTes + $nilaiNonTes) / 2, 2);
                $nilaiAkhirRapor = round(($naTp + $naLm + (2 * $nilaiAkhirSemester)) / 4, 0);

                $nilaiRows[] = [
                    'id' => $nilaiId++,
                    'siswa_id' => $siswaId,
                    'mata_pelajaran_id' => $mapelId,
                    'tujuan_pembelajaran_id' => null,
                    'lingkup_materi_id' => null,
                    'nilai_tp' => null,
                    'nilai_lm' => null,
                    'nilai_akhir_semester' => $nilaiAkhirSemester,
                    'created_at' => $now,
                    'updated_at' => $now,
                    'na_tp' => $naTp,
                    'na_lm' => $naLm,
                    'tp_number' => null,
                    'nilai_tes' => $nilaiTes,
                    'nilai_non_tes' => $nilaiNonTes,
                    'na_sumatif_semester' => $nilaiAkhirSemester,
                    'nilai_akhir_rapor' => $nilaiAkhirRapor,
                    'tahun_ajaran_id' => 1,
                    'deleted_at' => null,
                ];
            }
        }

        DB::table('nilais')->insert($nilaiRows);

        $absensiRows = [];
        foreach ($studentsByClass[1] as $index => $siswaId) {
            $absensiRows[] = [
                'siswa_id' => $siswaId,
                'sakit' => $index % 4,
                'izin' => $index % 3,
                'tanpa_keterangan' => $index % 2,
                'semester' => 1,
                'tahun_ajaran_id' => 1,
                'created_at' => $now,
                'updated_at' => $now,
                'deleted_at' => null,
            ];
        }
        DB::table('absensis')->insert($absensiRows);

        $requiredPlaceholders = [
            'nama_siswa' => ['description' => 'Nama siswa', 'category' => 'student'],
            'nis' => ['description' => 'NIS siswa', 'category' => 'student'],
            'nisn' => ['description' => 'NISN siswa', 'category' => 'student'],
            'kelas' => ['description' => 'Kelas siswa', 'category' => 'student'],
            'tahun_ajaran' => ['description' => 'Tahun ajaran aktif', 'category' => 'student'],
            'sakit' => ['description' => 'Jumlah sakit', 'category' => 'attendance'],
            'izin' => ['description' => 'Jumlah izin', 'category' => 'attendance'],
            'tanpa_keterangan' => ['description' => 'Jumlah tanpa keterangan', 'category' => 'attendance'],
        ];

        $placeholderRows = [];
        foreach ($requiredPlaceholders as $key => $meta) {
            $placeholderRows[] = [
                'placeholder_key' => $key,
                'description' => $meta['description'],
                'category' => $meta['category'],
                'sample_value' => null,
                'is_required' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        for ($i = 1; $i <= 10; $i++) {
            $isRequired = $i <= 4 ? 1 : 0;

            $placeholderRows[] = [
                'placeholder_key' => "nama_matapelajaran{$i}",
                'description' => "Nama mata pelajaran {$i}",
                'category' => 'mapel',
                'sample_value' => null,
                'is_required' => $isRequired,
                'created_at' => $now,
                'updated_at' => $now,
            ];
            $placeholderRows[] = [
                'placeholder_key' => "nilai_matapelajaran{$i}",
                'description' => "Nilai mata pelajaran {$i}",
                'category' => 'mapel',
                'sample_value' => null,
                'is_required' => $isRequired,
                'created_at' => $now,
                'updated_at' => $now,
            ];
            $placeholderRows[] = [
                'placeholder_key' => "capaian_tertinggi{$i}",
                'description' => "Capaian tertinggi mata pelajaran {$i}",
                'category' => 'mapel',
                'sample_value' => null,
                'is_required' => $isRequired,
                'created_at' => $now,
                'updated_at' => $now,
            ];
            $placeholderRows[] = [
                'placeholder_key' => "capaian_terendah{$i}",
                'description' => "Capaian terendah mata pelajaran {$i}",
                'category' => 'mapel',
                'sample_value' => null,
                'is_required' => $isRequired,
                'created_at' => $now,
                'updated_at' => $now,
            ];
            $placeholderRows[] = [
                'placeholder_key' => "kkm_matapelajaran{$i}",
                'description' => "KKM mata pelajaran {$i}",
                'category' => 'mapel',
                'sample_value' => null,
                'is_required' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        for ($i = 1; $i <= 5; $i++) {
            $placeholderRows[] = [
                'placeholder_key' => "nama_mulok{$i}",
                'description' => "Nama muatan lokal {$i}",
                'category' => 'mulok',
                'sample_value' => null,
                'is_required' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ];
            $placeholderRows[] = [
                'placeholder_key' => "nilai_mulok{$i}",
                'description' => "Nilai muatan lokal {$i}",
                'category' => 'mulok',
                'sample_value' => null,
                'is_required' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ];
            $placeholderRows[] = [
                'placeholder_key' => "capaian_tertinggi_mulok{$i}",
                'description' => "Capaian tertinggi muatan lokal {$i}",
                'category' => 'mulok',
                'sample_value' => null,
                'is_required' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ];
            $placeholderRows[] = [
                'placeholder_key' => "capaian_terendah_mulok{$i}",
                'description' => "Capaian terendah muatan lokal {$i}",
                'category' => 'mulok',
                'sample_value' => null,
                'is_required' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ];
            $placeholderRows[] = [
                'placeholder_key' => "kkm_mulok{$i}",
                'description' => "KKM muatan lokal {$i}",
                'category' => 'mulok',
                'sample_value' => null,
                'is_required' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        foreach ([
            'nama_wali_kelas' => 'Nama wali kelas',
            'nama_kepala_sekolah' => 'Nama kepala sekolah',
            'catatan_wali_kelas' => 'Catatan wali kelas',
        ] as $key => $description) {
            $placeholderRows[] = [
                'placeholder_key' => $key,
                'description' => $description,
                'category' => 'school',
                'sample_value' => null,
                'is_required' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        DB::table('report_placeholders')->insert($placeholderRows);
    }

    private function generateScore(int $seed): int
    {
        return 60 + ($seed % 41);
    }

    private function average(array $scores): float
    {
        if (empty($scores)) {
            return 0.0;
        }

        return round(array_sum($scores) / count($scores), 2);
    }
}

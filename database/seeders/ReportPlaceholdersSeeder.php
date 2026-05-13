<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class ReportPlaceholdersSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        // Hapus placeholder lama yang sudah tidak digunakan
        DB::table('report_placeholders')->truncate();
        
        // Set placeholder dasar (yang tidak terkait mata pelajaran)
        $basicPlaceholders = [
            // Data Siswa
            ['placeholder_key' => 'nama_siswa', 'description' => 'Nama lengkap siswa', 'category' => 'siswa', 'is_required' => true],
            ['placeholder_key' => 'nisn', 'description' => 'NISN siswa', 'category' => 'siswa', 'is_required' => true],
            ['placeholder_key' => 'nis', 'description' => 'NIS siswa', 'category' => 'siswa', 'is_required' => true],
            ['placeholder_key' => 'kelas', 'description' => 'Kelas siswa', 'category' => 'siswa', 'is_required' => true],
            ['placeholder_key' => 'tempat_lahir', 'description' => 'Tempat lahir siswa', 'category' => 'siswa', 'is_required' => false],
            ['placeholder_key' => 'jenis_kelamin', 'description' => 'Jenis kelamin siswa', 'category' => 'siswa', 'is_required' => false],
            ['placeholder_key' => 'agama', 'description' => 'Agama siswa', 'category' => 'siswa', 'is_required' => false],
            ['placeholder_key' => 'alamat_siswa', 'description' => 'Alamat siswa', 'category' => 'siswa', 'is_required' => false],
            ['placeholder_key' => 'nama_ayah', 'description' => 'Nama ayah siswa', 'category' => 'siswa', 'is_required' => false],
            ['placeholder_key' => 'nama_ibu', 'description' => 'Nama ibu siswa', 'category' => 'siswa', 'is_required' => false],
            ['placeholder_key' => 'pekerjaan_ayah', 'description' => 'Pekerjaan ayah siswa', 'category' => 'siswa', 'is_required' => false],
            ['placeholder_key' => 'pekerjaan_ibu', 'description' => 'Pekerjaan ibu siswa', 'category' => 'siswa', 'is_required' => false],
            ['placeholder_key' => 'alamat_orangtua', 'description' => 'Alamat orang tua siswa', 'category' => 'siswa', 'is_required' => false],
            ['placeholder_key' => 'wali_siswa', 'description' => 'Nama wali siswa', 'category' => 'siswa', 'is_required' => false],
            ['placeholder_key' => 'pekerjaan_wali', 'description' => 'Pekerjaan wali siswa', 'category' => 'siswa', 'is_required' => false],
            ['placeholder_key' => 'alamat_wali', 'description' => 'Alamat wali siswa', 'category' => 'siswa', 'is_required' => false],
            ['placeholder_key' => 'fase', 'description' => 'Fase pembelajaran siswa', 'category' => 'siswa', 'is_required' => false],
            ['placeholder_key' => 'semester', 'description' => 'Semester (Ganjil/Genap)', 'category' => 'siswa', 'is_required' => false],
            ['placeholder_key' => 'foto_siswa', 'description' => 'Foto siswa', 'category' => 'siswa', 'is_required' => false],

            // Data Sekolah
            ['placeholder_key' => 'tahun_ajaran', 'description' => 'Tahun ajaran', 'category' => 'sekolah', 'is_required' => true],
            ['placeholder_key' => 'nomor_telepon', 'description' => 'Nomor telepon sekolah', 'category' => 'sekolah', 'is_required' => false],
            ['placeholder_key' => 'kepala_sekolah', 'description' => 'Nama kepala sekolah', 'category' => 'sekolah', 'is_required' => false],
            ['placeholder_key' => 'wali_kelas', 'description' => 'Nama wali kelas', 'category' => 'sekolah', 'is_required' => false],
            ['placeholder_key' => 'nip_kepala_sekolah', 'description' => 'NIP kepala sekolah', 'category' => 'sekolah', 'is_required' => false],
            ['placeholder_key' => 'nip_wali_kelas', 'description' => 'NIP wali kelas', 'category' => 'sekolah', 'is_required' => false],
            ['placeholder_key' => 'nuptk_wali_kelas', 'description' => 'NUPTK wali kelas', 'category' => 'sekolah', 'is_required' => false],

            // TAMBAHAN BARU: Placeholder Tanggal
            ['placeholder_key' => 'tanggal_terbit', 'description' => 'Tanggal terbit rapor (format: dd-mm-yyyy)', 'category' => 'sekolah', 'is_required' => false],
            ['placeholder_key' => 'tanggal_lengkap', 'description' => 'Tanggal lengkap (format: 15 Januari 2025)', 'category' => 'sekolah', 'is_required' => false],
            ['placeholder_key' => 'tanggal_rapor', 'description' => 'Tanggal rapor (alias untuk tanggal_lengkap)', 'category' => 'sekolah', 'is_required' => false],
            ['placeholder_key' => 'tempat_terbit', 'description' => 'Tempat terbit rapor', 'category' => 'sekolah', 'is_required' => false],
            ['placeholder_key' => 'tempat_tanggal', 'description' => 'Tempat dan tanggal (format: Bandung, 15 Januari 2025)', 'category' => 'sekolah', 'is_required' => false],

            ['placeholder_key' => 'nama_sekolah', 'description' => 'Nama sekolah', 'category' => 'sekolah', 'is_required' => false],
            ['placeholder_key' => 'alamat_sekolah', 'description' => 'Alamat sekolah', 'category' => 'sekolah', 'is_required' => false],
            
            // Data profil sekolah untuk template UAS
            ['placeholder_key' => 'nama_sekolah', 'description' => 'Nama sekolah', 'category' => 'sekolah', 'is_required' => false],
            ['placeholder_key' => 'alamat_sekolah', 'description' => 'Alamat sekolah', 'category' => 'sekolah', 'is_required' => false],
            ['placeholder_key' => 'kelurahan', 'description' => 'Kelurahan sekolah', 'category' => 'sekolah', 'is_required' => false],
            ['placeholder_key' => 'kecamatan', 'description' => 'Kecamatan sekolah', 'category' => 'sekolah', 'is_required' => false],
            ['placeholder_key' => 'kabupaten', 'description' => 'Kabupaten sekolah', 'category' => 'sekolah', 'is_required' => false],
            ['placeholder_key' => 'provinsi', 'description' => 'Provinsi sekolah', 'category' => 'sekolah', 'is_required' => false],
            ['placeholder_key' => 'kode_pos', 'description' => 'Kode pos sekolah', 'category' => 'sekolah', 'is_required' => false],
            ['placeholder_key' => 'website', 'description' => 'Website sekolah', 'category' => 'sekolah', 'is_required' => false],
            ['placeholder_key' => 'email_sekolah', 'description' => 'Email sekolah', 'category' => 'sekolah', 'is_required' => false],
            ['placeholder_key' => 'npsn', 'description' => 'NPSN sekolah', 'category' => 'sekolah', 'is_required' => false],
            
            // Data Kehadiran
            ['placeholder_key' => 'sakit', 'description' => 'Jumlah hari sakit', 'category' => 'kehadiran', 'is_required' => true],
            ['placeholder_key' => 'izin', 'description' => 'Jumlah hari izin', 'category' => 'kehadiran', 'is_required' => true],
            ['placeholder_key' => 'tanpa_keterangan', 'description' => 'Jumlah hari tanpa keterangan', 'category' => 'kehadiran', 'is_required' => true],
            
            // Data Lainnya
            ['placeholder_key' => 'catatan_guru', 'description' => 'Catatan dari guru', 'category' => 'lainnya', 'is_required' => false],
        ];
        
        DB::table('report_placeholders')->insert($basicPlaceholders);
        
        // Set placeholder dinamis untuk mata pelajaran
        $mapelPlaceholders = [];
        for ($i = 1; $i <= 10; $i++) {
            $mapelPlaceholders[] = [
                'placeholder_key' => "nama_matapelajaran{$i}",
                'description' => "Nama mata pelajaran {$i}",
                'category' => 'mapel',
                'is_required' => $i <= 4
            ];
            
            $mapelPlaceholders[] = [
                'placeholder_key' => "nilai_matapelajaran{$i}",
                'description' => "Nilai mata pelajaran {$i}",
                'category' => 'mapel',
                'is_required' => $i <= 4
            ];

            $mapelPlaceholders[] = [
                'placeholder_key' => "capaian_kompetensi{$i}",
                'description' => "Capaian kompetensi mata pelajaran {$i} (legacy)",
                'category' => 'mapel',
                'is_required' => false
            ];

            $mapelPlaceholders[] = [
                'placeholder_key' => "capaian_tertinggi{$i}",
                'description' => "Capaian tertinggi mapel {$i}",
                'category' => 'mapel',
                'is_required' => $i <= 4
            ];

            $mapelPlaceholders[] = [
                'placeholder_key' => "capaian_terendah{$i}",
                'description' => "Capaian terendah mapel {$i}",
                'category' => 'mapel',
                'is_required' => $i <= 4
            ];

            $mapelPlaceholders[] = [
                'placeholder_key' => "kkm_matapelajaran{$i}",
                'description' => "KKM mata pelajaran {$i}",
                'category' => 'mapel',
                'is_required' => false
            ];
        }
        
        DB::table('report_placeholders')->insert($mapelPlaceholders);
        
        // Set placeholder untuk muatan lokal
        $mulokPlaceholders = [];
        for ($i = 1; $i <= 5; $i++) {
            $mulokPlaceholders[] = [
                'placeholder_key' => "nama_mulok{$i}",
                'description' => "Nama muatan lokal {$i}",
                'category' => 'mulok',
                'is_required' => false
            ];
            
            $mulokPlaceholders[] = [
                'placeholder_key' => "nilai_mulok{$i}",
                'description' => "Nilai muatan lokal {$i}",
                'category' => 'mulok',
                'is_required' => false
            ];

            $mulokPlaceholders[] = [
                'placeholder_key' => "capaian_mulok{$i}",
                'description' => "Capaian kompetensi muatan lokal {$i} (legacy)",
                'category' => 'mulok',
                'is_required' => false
            ];

            $mulokPlaceholders[] = [
                'placeholder_key' => "capaian_tertinggi_mulok{$i}",
                'description' => "Capaian tertinggi muatan lokal {$i}",
                'category' => 'mulok',
                'is_required' => false
            ];

            $mulokPlaceholders[] = [
                'placeholder_key' => "capaian_terendah_mulok{$i}",
                'description' => "Capaian terendah muatan lokal {$i}",
                'category' => 'mulok',
                'is_required' => false
            ];

            $mulokPlaceholders[] = [
                'placeholder_key' => "kkm_mulok{$i}",
                'description' => "KKM muatan lokal {$i}",
                'category' => 'mulok',
                'is_required' => false
            ];
        }
        
        DB::table('report_placeholders')->insert($mulokPlaceholders);
        
        // Set placeholder untuk ekstrakurikuler
        $ekskulPlaceholders = [];
        for ($i = 1; $i <= 6; $i++) {
            $ekskulPlaceholders[] = [
                'placeholder_key' => "ekskul{$i}_nama",
                'description' => "Nama ekstrakurikuler {$i}",
                'category' => 'ekskul',
                'is_required' => false
            ];
            
            $ekskulPlaceholders[] = [
                'placeholder_key' => "ekskul{$i}_keterangan",
                'description' => "Keterangan ekstrakurikuler {$i}",
                'category' => 'ekskul',
                'is_required' => false
            ];
        }
        
        DB::table('report_placeholders')->insert($ekskulPlaceholders);
        
        // Support juga untuk placeholder lama (backward compatibility)
        $oldPlaceholders = [
            ['placeholder_key' => 'nilai_pai', 'description' => 'Nilai Pendidikan Agama Islam', 'category' => 'mapel_lama', 'is_required' => false],
            ['placeholder_key' => 'capaian_kompetensi_pai', 'description' => 'Capaian kompetensi Pendidikan Agama Islam', 'category' => 'mapel_lama', 'is_required' => false],
            ['placeholder_key' => 'kkm_pai', 'description' => 'KKM Pendidikan Agama Islam', 'category' => 'mapel_lama', 'is_required' => false],
            
            ['placeholder_key' => 'nilai_ppkn', 'description' => 'Nilai PPKN', 'category' => 'mapel_lama', 'is_required' => false],
            ['placeholder_key' => 'capaian_kompetensi_ppkn', 'description' => 'Capaian kompetensi PPKN', 'category' => 'mapel_lama', 'is_required' => false],
            ['placeholder_key' => 'kkm_ppkn', 'description' => 'KKM PPKN', 'category' => 'mapel_lama', 'is_required' => false],
            
            ['placeholder_key' => 'nilai_bahasa_indonesia', 'description' => 'Nilai Bahasa Indonesia', 'category' => 'mapel_lama', 'is_required' => false],
            ['placeholder_key' => 'capaian_kompetensi_bahasa_indonesia', 'description' => 'Capaian kompetensi Bahasa Indonesia', 'category' => 'mapel_lama', 'is_required' => false],
            ['placeholder_key' => 'kkm_bahasa_indonesia', 'description' => 'KKM Bahasa Indonesia', 'category' => 'mapel_lama', 'is_required' => false],
            
            ['placeholder_key' => 'nilai_matematika', 'description' => 'Nilai Matematika', 'category' => 'mapel_lama', 'is_required' => false],
            ['placeholder_key' => 'capaian_kompetensi_matematika', 'description' => 'Capaian kompetensi Matematika', 'category' => 'mapel_lama', 'is_required' => false],
            ['placeholder_key' => 'kkm_matematika', 'description' => 'KKM Matematika', 'category' => 'mapel_lama', 'is_required' => false],
            
            ['placeholder_key' => 'nilai_pjok', 'description' => 'Nilai PJOK', 'category' => 'mapel_lama', 'is_required' => false],
            ['placeholder_key' => 'capaian_kompetensi_pjok', 'description' => 'Capaian kompetensi PJOK', 'category' => 'mapel_lama', 'is_required' => false],
            ['placeholder_key' => 'kkm_pjok', 'description' => 'KKM PJOK', 'category' => 'mapel_lama', 'is_required' => false],
            
            ['placeholder_key' => 'nilai_seni_musik', 'description' => 'Nilai Seni Musik', 'category' => 'mapel_lama', 'is_required' => false],
            ['placeholder_key' => 'capaian_kompetensi_seni_musik', 'description' => 'Capaian kompetensi Seni Musik', 'category' => 'mapel_lama', 'is_required' => false],
            ['placeholder_key' => 'kkm_seni_musik', 'description' => 'KKM Seni Musik', 'category' => 'mapel_lama', 'is_required' => false],
            
            ['placeholder_key' => 'nilai_bahasa_inggris', 'description' => 'Nilai Bahasa Inggris', 'category' => 'mapel_lama', 'is_required' => false],
            ['placeholder_key' => 'capaian_kompetensi_bahasa_inggris', 'description' => 'Capaian kompetensi Bahasa Inggris', 'category' => 'mapel_lama', 'is_required' => false],
            ['placeholder_key' => 'kkm_bahasa_inggris', 'description' => 'KKM Bahasa Inggris', 'category' => 'mapel_lama', 'is_required' => false],
        ];
        
        DB::table('report_placeholders')->insert($oldPlaceholders);
    }
}

<?php

namespace Database\Seeders;

use App\Models\Absensi;
use App\Models\BobotNilai;
use App\Models\CapaianKompetensiCustom;
use App\Models\CatatanMataPelajaran;
use App\Models\CatatanSiswa;
use App\Models\Ekstrakurikuler;
use App\Models\Guru;
use App\Models\Kelas;
use App\Models\Kkm;
use App\Models\LingkupMateri;
use App\Models\MataPelajaran;
use App\Models\Nilai;
use App\Models\NilaiEkstrakurikuler;
use App\Models\ProfilSekolah;
use App\Models\ReportTemplate;
use App\Models\Siswa;
use App\Models\TahunAjaran;
use App\Models\TujuanPembelajaran;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

class DemoSemesterGanjilSeeder extends Seeder
{
    private const YEAR = '2026/2027';

    private const NEXT_YEAR = '2027/2028';

    private const SEMESTER = 1;

    private const ADMIN_USERNAME = 'demo_admin_sdit';

    private const BUDI_USERNAME = 'demo_budi';

    private const ANI_USERNAME = 'demo_ani';

    private const YUSUF_USERNAME = 'demo_yusuf';

    public function run(): void
    {
        $this->assertSafeEnvironment();

        $passwords = [
            'admin' => $this->requiredPassword('DEMO_ADMIN_PASSWORD'),
            'budi' => $this->requiredPassword('DEMO_BUDI_PASSWORD'),
            'ani' => $this->requiredPassword('DEMO_ANI_PASSWORD'),
            'yusuf' => $this->requiredPassword('DEMO_YUSUF_PASSWORD'),
        ];

        DB::transaction(function () use ($passwords) {
            $this->assertAcademicYearIsSafeToSeed();

            $tahunAjaran = $this->createOrReuseAcademicYear();
            $this->createOrReuseSchoolProfile($tahunAjaran);

            $admin = $this->createDemoAdmin($passwords['admin']);
            $kelas5A = $this->createClass(5, 'A', $tahunAjaran);
            $kelas5B = $this->createClass(5, 'B', $tahunAjaran);
            $budi = $this->createTeacher([
                'nuptk' => '900000001',
                'nama' => 'Budi Santoso',
                'jenis_kelamin' => 'Laki-laki',
                'tanggal_lahir' => '1988-04-12',
                'no_handphone' => '081200000001',
                'email' => 'demo.budi@sdit-demo.local',
                'alamat' => 'Jl. Demo Guru 1',
                'jabatan' => 'guru_wali',
                'username' => self::BUDI_USERNAME,
                'password' => Hash::make($passwords['budi']),
            ]);
            $ani = $this->createTeacher([
                'nuptk' => '900000002',
                'nama' => 'Ani Rahmawati',
                'jenis_kelamin' => 'Perempuan',
                'tanggal_lahir' => '1990-08-20',
                'no_handphone' => '081200000002',
                'email' => 'demo.ani@sdit-demo.local',
                'alamat' => 'Jl. Demo Guru 2',
                'jabatan' => 'guru_wali',
                'username' => self::ANI_USERNAME,
                'password' => Hash::make($passwords['ani']),
            ]);
            $yusuf = $this->createTeacher([
                'nuptk' => '900000003',
                'nama' => 'Yusuf Hidayat',
                'jenis_kelamin' => 'Laki-laki',
                'tanggal_lahir' => '1986-10-05',
                'no_handphone' => '081200000003',
                'email' => 'demo.yusuf@sdit-demo.local',
                'alamat' => 'Jl. Demo Guru 3',
                'jabatan' => 'guru',
                'username' => self::YUSUF_USERNAME,
                'password' => Hash::make($passwords['yusuf']),
            ]);

            $this->assertNoUnexpectedWaliAssignments($budi, [$kelas5A->id], $tahunAjaran->id);
            $this->assertNoUnexpectedWaliAssignments($ani, [$kelas5B->id], $tahunAjaran->id);
            $this->assertNoUnexpectedWaliAssignments($yusuf, [], $tahunAjaran->id);

            $this->assignWali($budi, $kelas5A);
            $this->assignWali($ani, $kelas5B);
            $this->assignPengajar($budi, $kelas5A);
            $this->assignPengajar($ani, $kelas5B);
            $this->assignPengajar($yusuf, $kelas5A);
            $this->assignPengajar($yusuf, $kelas5B);
            $this->removeDemoPengajarAssignment($ani, $kelas5A);

            $students = [
                'ahmad' => $this->createStudent([
                    'nis' => '2605001',
                    'nisn' => '9000000001',
                    'nama' => 'Ahmad Fauzan',
                    'tanggal_lahir' => '2015-02-10',
                    'jenis_kelamin' => 'Laki-laki',
                    'kelas_id' => $kelas5A->id,
                    'tahun_ajaran_id' => $tahunAjaran->id,
                ]),
                'siti' => $this->createStudent([
                    'nis' => '2605002',
                    'nisn' => '9000000002',
                    'nama' => 'Siti Aisyah',
                    'tanggal_lahir' => '2015-05-18',
                    'jenis_kelamin' => 'Perempuan',
                    'kelas_id' => $kelas5A->id,
                    'tahun_ajaran_id' => $tahunAjaran->id,
                ]),
                'rina' => $this->createStudent([
                    'nis' => '2605003',
                    'nisn' => '9000000003',
                    'nama' => 'Rina Putri',
                    'tanggal_lahir' => '2015-11-04',
                    'jenis_kelamin' => 'Perempuan',
                    'kelas_id' => $kelas5A->id,
                    'tahun_ajaran_id' => $tahunAjaran->id,
                ]),
                'dimas' => $this->createStudent([
                    'nis' => '2605101',
                    'nisn' => '9000000101',
                    'nama' => 'Dimas Pratama',
                    'tanggal_lahir' => '2015-07-22',
                    'jenis_kelamin' => 'Laki-laki',
                    'kelas_id' => $kelas5B->id,
                    'tahun_ajaran_id' => $tahunAjaran->id,
                ]),
            ];

            $subjects = [
                'matematika5A' => $this->createSubject('Matematika', $kelas5A, $budi, false, false, $tahunAjaran),
                'bahasaIndonesia5A' => $this->createSubject('Bahasa Indonesia', $kelas5A, $budi, false, false, $tahunAjaran),
                'matematika5B' => $this->createSubject('Matematika', $kelas5B, $ani, false, false, $tahunAjaran),
                'bahasaIndonesia5B' => $this->createSubject('Bahasa Indonesia', $kelas5B, $ani, false, false, $tahunAjaran),
                'pai5A' => $this->createSubject('PAI', $kelas5A, $yusuf, false, true, $tahunAjaran),
                'pai5B' => $this->createSubject('PAI', $kelas5B, $yusuf, false, true, $tahunAjaran),
                'bahasaSunda5A' => $this->createSubject('Bahasa Sunda', $kelas5A, $yusuf, true, false, $tahunAjaran),
                'bahasaSunda5B' => $this->createSubject('Bahasa Sunda', $kelas5B, $yusuf, true, false, $tahunAjaran),
            ];

            $learning = $this->createLearningComponents($subjects);
            $this->createKkmAndBobot($subjects, $tahunAjaran);
            $this->createGrades($students, $subjects['matematika5A'], $learning['matematika5A'], $tahunAjaran);
            $this->createWaliSupportingData($students, $subjects, $budi, $tahunAjaran);

            $this->reportSummary($tahunAjaran, $admin);
        });
    }

    private function assertSafeEnvironment(): void
    {
        if (! app()->environment(['local', 'testing', 'demo'])) {
            throw new RuntimeException(
                'DemoSemesterGanjilSeeder may only run in local, testing, or demo environments.'
            );
        }
    }

    private function requiredPassword(string $key): string
    {
        $value = getenv($key);

        if (! is_string($value) || $value === '') {
            $value = $_ENV[$key] ?? $_SERVER[$key] ?? env($key);
        }

        if (! is_string($value) || trim($value) === '') {
            throw new RuntimeException("Missing required {$key} environment variable.");
        }

        return $value;
    }

    private function assertAcademicYearIsSafeToSeed(): void
    {
        $conflictingActive = TahunAjaran::query()
            ->where('is_active', true)
            ->where(function ($query) {
                $query->where('tahun_ajaran', '!=', self::YEAR)
                    ->orWhere('semester', '!=', self::SEMESTER);
            })
            ->first();

        if ($conflictingActive) {
            throw new RuntimeException(
                "Another active academic year exists ({$conflictingActive->tahun_ajaran} semester {$conflictingActive->semester}). "
                .'The demo seeder will not deactivate or alter it.'
            );
        }

        if (
            TahunAjaran::withTrashed()
                ->where('tahun_ajaran', self::YEAR)
                ->where('semester', 2)
                ->exists()
        ) {
            throw new RuntimeException('Academic year 2026/2027 semester genap already exists. Initial ganjil demo state is not clean.');
        }

        if (TahunAjaran::withTrashed()->where('tahun_ajaran', self::NEXT_YEAR)->exists()) {
            throw new RuntimeException('Academic year 2027/2028 already exists. This seeder only prepares the initial ganjil state.');
        }
    }

    private function createOrReuseAcademicYear(): TahunAjaran
    {
        $tahunAjaran = TahunAjaran::query()
            ->where('tahun_ajaran', self::YEAR)
            ->where('semester', self::SEMESTER)
            ->first();

        if ($tahunAjaran) {
            if (! $tahunAjaran->is_active) {
                $tahunAjaran->update(['is_active' => true]);
            }

            return $tahunAjaran->refresh();
        }

        return TahunAjaran::create([
            'tahun_ajaran' => self::YEAR,
            'is_active' => true,
            'tanggal_mulai' => '2026-07-13',
            'tanggal_selesai' => '2027-06-19',
            'semester' => self::SEMESTER,
            'deskripsi' => 'Demo - Tahun Ajaran 2026/2027 Semester Ganjil',
        ]);
    }

    private function createOrReuseSchoolProfile(TahunAjaran $tahunAjaran): void
    {
        if (ProfilSekolah::query()->exists()) {
            return;
        }

        ProfilSekolah::create($this->onlyExistingColumns('profil_sekolah', [
            'nama_instansi' => 'Yayasan Pendidikan Demo Al Hidayah',
            'nama_sekolah' => 'SDIT Al Hidayah',
            'tahun_pelajaran' => $tahunAjaran->tahun_ajaran,
            'semester' => $tahunAjaran->semester,
            'npsn' => '90000000',
            'kepala_sekolah' => 'Hendra Prasetya',
            'nip_kepala_sekolah' => '197901012006041001',
            'alamat' => 'Jl. Pendidikan Demo No. 5',
            'guru_kelas' => 2,
            'kode_pos' => '12345',
            'kelas' => 2,
            'telepon' => '021000000',
            'jumlah_siswa' => 4,
            'email_sekolah' => 'demo@sdit-alhidayah.test',
            'website' => 'https://sdit-alhidayah.test',
            'kelurahan' => 'Demo Jaya',
            'kecamatan' => 'Demo Timur',
            'kabupaten' => 'Kota Demo',
            'provinsi' => 'Jawa Barat',
            'tempat_terbit' => 'Kota Demo',
            'tanggal_terbit' => '2026-12-20',
        ]));
    }

    private function createDemoAdmin(string $password): User
    {
        return User::updateOrCreate(
            ['username' => self::ADMIN_USERNAME],
            [
                'name' => 'Demo Admin SDIT',
                'email' => 'demo.admin@sdit-demo.local',
                'password' => Hash::make($password),
            ]
        );
    }

    private function createClass(int $number, string $name, TahunAjaran $tahunAjaran): Kelas
    {
        return Kelas::firstOrCreate(
            [
                'nomor_kelas' => $number,
                'nama_kelas' => $name,
                'tahun_ajaran_id' => $tahunAjaran->id,
            ],
            []
        );
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function createTeacher(array $attributes): Guru
    {
        return Guru::updateOrCreate(
            ['username' => $attributes['username']],
            $this->onlyExistingColumns('gurus', $attributes)
        );
    }

    /**
     * @param  array<int, int>  $allowedClassIds
     */
    private function assertNoUnexpectedWaliAssignments(Guru $guru, array $allowedClassIds, int $tahunAjaranId): void
    {
        $unexpected = DB::table('guru_kelas')
            ->join('kelas', 'guru_kelas.kelas_id', '=', 'kelas.id')
            ->where('guru_kelas.guru_id', $guru->id)
            ->where('kelas.tahun_ajaran_id', $tahunAjaranId)
            ->where(function ($query) {
                $query->where('guru_kelas.is_wali_kelas', true)
                    ->orWhere('guru_kelas.role', 'wali_kelas');
            })
            ->whereNotIn('kelas.id', $allowedClassIds)
            ->exists();

        if ($unexpected) {
            throw new RuntimeException(
                "Guru {$guru->nama} already has an unexpected wali kelas assignment in ".self::YEAR.'.'
            );
        }
    }

    private function assignWali(Guru $guru, Kelas $kelas): void
    {
        $conflictingWali = DB::table('guru_kelas')
            ->where('kelas_id', $kelas->id)
            ->where('guru_id', '!=', $guru->id)
            ->where(function ($query) {
                $query->where('is_wali_kelas', true)
                    ->orWhere('role', 'wali_kelas');
            })
            ->exists();

        if ($conflictingWali) {
            throw new RuntimeException("Kelas {$kelas->nomor_kelas}{$kelas->nama_kelas} already has another wali kelas.");
        }

        $this->upsertGuruKelas($guru, $kelas, true, 'wali_kelas');
    }

    private function assignPengajar(Guru $guru, Kelas $kelas): void
    {
        $this->upsertGuruKelas($guru, $kelas, false, 'pengajar');
    }

    private function removeDemoPengajarAssignment(Guru $guru, Kelas $kelas): void
    {
        DB::table('guru_kelas')
            ->where('guru_id', $guru->id)
            ->where('kelas_id', $kelas->id)
            ->where('role', 'pengajar')
            ->where('is_wali_kelas', false)
            ->delete();
    }

    private function upsertGuruKelas(Guru $guru, Kelas $kelas, bool $isWaliKelas, string $role): void
    {
        DB::table('guru_kelas')->updateOrInsert(
            [
                'guru_id' => $guru->id,
                'kelas_id' => $kelas->id,
                'role' => $role,
            ],
            [
                'is_wali_kelas' => $isWaliKelas,
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function createStudent(array $attributes): Siswa
    {
        $defaults = [
            'agama' => 'Islam',
            'alamat' => 'Jl. Siswa Demo No. 1',
            'nama_ayah' => 'Ayah Demo',
            'nama_ibu' => 'Ibu Demo',
            'pekerjaan_ayah' => 'Wiraswasta',
            'pekerjaan_ibu' => 'Guru',
            'alamat_orangtua' => 'Jl. Orang Tua Demo No. 1',
            'wali_siswa' => '',
            'pekerjaan_wali' => '',
            'status' => 'aktif',
            'is_naik_kelas' => null,
            'kelas_tujuan_id' => null,
        ];

        return Siswa::updateOrCreate(
            ['nis' => $attributes['nis']],
            $this->onlyExistingColumns('siswas', array_merge($defaults, $attributes))
        );
    }

    private function createSubject(
        string $name,
        Kelas $kelas,
        Guru $guru,
        bool $isMuatanLokal,
        bool $allowNonWali,
        TahunAjaran $tahunAjaran
    ): MataPelajaran {
        return MataPelajaran::updateOrCreate(
            [
                'nama_pelajaran' => $name,
                'kelas_id' => $kelas->id,
                'tahun_ajaran_id' => $tahunAjaran->id,
                'semester' => self::SEMESTER,
            ],
            $this->onlyExistingColumns('mata_pelajarans', [
                'guru_id' => $guru->id,
                'is_muatan_lokal' => $isMuatanLokal,
                'allow_non_wali' => $allowNonWali,
            ])
        );
    }

    /**
     * @param  array<string, MataPelajaran>  $subjects
     * @return array<string, array{lm: LingkupMateri, tp: TujuanPembelajaran}>
     */
    private function createLearningComponents(array $subjects): array
    {
        return [
            'matematika5A' => $this->createLearningComponent(
                $subjects['matematika5A'],
                'Bilangan cacah sampai satu juta',
                'TP-1',
                'Menjelaskan nilai tempat dan operasi hitung bilangan cacah.'
            ),
            'bahasaIndonesia5A' => $this->createLearningComponent(
                $subjects['bahasaIndonesia5A'],
                'Membaca teks informasi',
                'TP-1',
                'Menemukan gagasan utama dan informasi rinci dalam teks.'
            ),
            'matematika5B' => $this->createLearningComponent(
                $subjects['matematika5B'],
                'Bilangan cacah sampai satu juta',
                'TP-1',
                'Menjelaskan nilai tempat dan operasi hitung bilangan cacah.'
            ),
            'bahasaIndonesia5B' => $this->createLearningComponent(
                $subjects['bahasaIndonesia5B'],
                'Menyusun paragraf sederhana',
                'TP-1',
                'Menulis paragraf dengan kalimat utama dan kalimat penjelas.'
            ),
            'pai5A' => $this->createLearningComponent(
                $subjects['pai5A'],
                'Akhlak terpuji dalam kehidupan sehari-hari',
                'TP-1',
                'Memberi contoh perilaku jujur dan tanggung jawab.'
            ),
            'pai5B' => $this->createLearningComponent(
                $subjects['pai5B'],
                'Akhlak terpuji dalam kehidupan sehari-hari',
                'TP-1',
                'Memberi contoh perilaku jujur dan tanggung jawab.'
            ),
            'bahasaSunda5A' => $this->createLearningComponent(
                $subjects['bahasaSunda5A'],
                'Paguneman sapopoe',
                'TP-1',
                'Menyimak dan menanggapi percakapan sederhana.'
            ),
            'bahasaSunda5B' => $this->createLearningComponent(
                $subjects['bahasaSunda5B'],
                'Paguneman sapopoe',
                'TP-1',
                'Menyimak dan menanggapi percakapan sederhana.'
            ),
        ];
    }

    /**
     * @return array{lm: LingkupMateri, tp: TujuanPembelajaran}
     */
    private function createLearningComponent(
        MataPelajaran $subject,
        string $materi,
        string $kodeTp,
        string $deskripsiTp
    ): array {
        $lm = LingkupMateri::firstOrCreate([
            'mata_pelajaran_id' => $subject->id,
            'judul_lingkup_materi' => $materi,
        ]);

        $tp = TujuanPembelajaran::firstOrCreate(
            [
                'lingkup_materi_id' => $lm->id,
                'kode_tp' => $kodeTp,
            ],
            ['deskripsi_tp' => $deskripsiTp]
        );

        return ['lm' => $lm, 'tp' => $tp];
    }

    /**
     * @param  array<string, MataPelajaran>  $subjects
     */
    private function createKkmAndBobot(array $subjects, TahunAjaran $tahunAjaran): void
    {
        foreach ($subjects as $subject) {
            Kkm::updateOrCreate(
                [
                    'mata_pelajaran_id' => $subject->id,
                    'kelas_id' => $subject->kelas_id,
                    'tahun_ajaran_id' => $tahunAjaran->id,
                ],
                ['nilai' => 75]
            );
        }

        BobotNilai::updateOrCreate(
            ['tahun_ajaran_id' => $tahunAjaran->id],
            [
                'bobot_tp' => 1,
                'bobot_lm' => 1,
                'bobot_as' => 2,
            ]
        );
    }

    /**
     * @param  array<string, Siswa>  $students
     * @param  array{lm: LingkupMateri, tp: TujuanPembelajaran}  $learning
     */
    private function createGrades(
        array $students,
        MataPelajaran $subject,
        array $learning,
        TahunAjaran $tahunAjaran
    ): void {
        $this->upsertAggregateNilai($students['ahmad'], $subject, $tahunAjaran, [
            'na_tp' => 86,
            'na_lm' => 84,
            'nilai_tes' => 88,
            'nilai_non_tes' => 90,
            'nilai_akhir_semester' => 89,
            'nilai_akhir_rapor' => 87,
            'is_submitted' => true,
        ]);
        $this->upsertTpNilai($students['ahmad'], $subject, $learning['lm'], $learning['tp'], $tahunAjaran, 86);
        $this->upsertLmNilai($students['ahmad'], $subject, $learning['lm'], $tahunAjaran, 84);

        $this->upsertAggregateNilai($students['siti'], $subject, $tahunAjaran, [
            'na_tp' => 78,
            'na_lm' => 76,
            'nilai_tes' => null,
            'nilai_non_tes' => null,
            'nilai_akhir_semester' => null,
            'nilai_akhir_rapor' => null,
            'is_submitted' => false,
        ]);
        $this->upsertTpNilai($students['siti'], $subject, $learning['lm'], $learning['tp'], $tahunAjaran, 78);
        $this->upsertLmNilai($students['siti'], $subject, $learning['lm'], $tahunAjaran, 76);
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private function upsertAggregateNilai(Siswa $student, MataPelajaran $subject, TahunAjaran $tahunAjaran, array $values): void
    {
        Nilai::updateOrCreate(
            [
                'siswa_id' => $student->id,
                'mata_pelajaran_id' => $subject->id,
                'lingkup_materi_id' => null,
                'tujuan_pembelajaran_id' => null,
                'tahun_ajaran_id' => $tahunAjaran->id,
            ],
            $this->onlyExistingColumns('nilais', $values)
        );
    }

    private function upsertTpNilai(
        Siswa $student,
        MataPelajaran $subject,
        LingkupMateri $lm,
        TujuanPembelajaran $tp,
        TahunAjaran $tahunAjaran,
        int $score
    ): void {
        Nilai::updateOrCreate(
            [
                'siswa_id' => $student->id,
                'mata_pelajaran_id' => $subject->id,
                'lingkup_materi_id' => $lm->id,
                'tujuan_pembelajaran_id' => $tp->id,
                'tahun_ajaran_id' => $tahunAjaran->id,
            ],
            ['nilai_tp' => $score]
        );
    }

    private function upsertLmNilai(
        Siswa $student,
        MataPelajaran $subject,
        LingkupMateri $lm,
        TahunAjaran $tahunAjaran,
        int $score
    ): void {
        Nilai::updateOrCreate(
            [
                'siswa_id' => $student->id,
                'mata_pelajaran_id' => $subject->id,
                'lingkup_materi_id' => $lm->id,
                'tujuan_pembelajaran_id' => null,
                'tahun_ajaran_id' => $tahunAjaran->id,
            ],
            ['nilai_lm' => $score]
        );
    }

    /**
     * @param  array<string, Siswa>  $students
     * @param  array<string, MataPelajaran>  $subjects
     */
    private function createWaliSupportingData(
        array $students,
        array $subjects,
        Guru $budi,
        TahunAjaran $tahunAjaran
    ): void {
        $this->upsertAbsensi($students['ahmad'], $tahunAjaran, 0, 1, 0);
        $this->upsertAbsensi($students['siti'], $tahunAjaran, 1, 0, 0);

        $pramuka = Ekstrakurikuler::updateOrCreate(
            [
                'nama_ekstrakurikuler' => 'Pramuka Demo',
                'tahun_ajaran_id' => $tahunAjaran->id,
            ],
            $this->onlyExistingColumns('ekstrakurikulers', [
                'pembina' => 'Budi Santoso',
            ])
        );

        NilaiEkstrakurikuler::updateOrCreate(
            [
                'siswa_id' => $students['ahmad']->id,
                'ekstrakurikuler_id' => $pramuka->id,
                'tahun_ajaran_id' => $tahunAjaran->id,
            ],
            [
                'predikat' => 'Baik',
                'deskripsi' => 'Aktif mengikuti latihan rutin dan bekerja sama dengan teman.',
            ]
        );

        CapaianKompetensiCustom::updateOrCreate(
            [
                'siswa_id' => $students['ahmad']->id,
                'mata_pelajaran_id' => $subjects['matematika5A']->id,
                'tahun_ajaran_id' => $tahunAjaran->id,
                'semester' => self::SEMESTER,
            ],
            [
                'custom_capaian' => 'Ahmad menunjukkan ketelitian yang baik saat menyelesaikan soal bilangan.',
                'custom_capaian_tertinggi' => 'Mampu menjelaskan strategi hitung secara runtut.',
                'custom_capaian_terendah' => 'Perlu lebih teliti saat memeriksa hasil akhir.',
            ]
        );

        CatatanSiswa::updateOrCreate(
            [
                'siswa_id' => $students['ahmad']->id,
                'tahun_ajaran_id' => $tahunAjaran->id,
                'semester' => self::SEMESTER,
                'type' => 'umum',
            ],
            [
                'catatan' => 'Ahmad menunjukkan sikap tanggung jawab dan mulai percaya diri bertanya.',
                'created_by' => $budi->id,
            ]
        );

        CatatanSiswa::updateOrCreate(
            [
                'siswa_id' => $students['siti']->id,
                'tahun_ajaran_id' => $tahunAjaran->id,
                'semester' => self::SEMESTER,
                'type' => 'umum',
            ],
            [
                'catatan' => 'Siti aktif berdiskusi, namun masih perlu melengkapi beberapa tugas.',
                'created_by' => $budi->id,
            ]
        );

        CatatanMataPelajaran::updateOrCreate(
            [
                'siswa_id' => $students['ahmad']->id,
                'mata_pelajaran_id' => $subjects['matematika5A']->id,
                'tahun_ajaran_id' => $tahunAjaran->id,
                'semester' => self::SEMESTER,
                'type' => 'umum',
            ],
            [
                'catatan' => 'Latihan operasi hitung sudah konsisten.',
                'created_by' => $budi->id,
            ]
        );
    }

    private function upsertAbsensi(Siswa $student, TahunAjaran $tahunAjaran, int $sakit, int $izin, int $tanpaKeterangan): void
    {
        Absensi::updateOrCreate(
            [
                'siswa_id' => $student->id,
                'semester' => self::SEMESTER,
                'tahun_ajaran_id' => $tahunAjaran->id,
            ],
            [
                'sakit' => $sakit,
                'izin' => $izin,
                'tanpa_keterangan' => $tanpaKeterangan,
            ]
        );
    }

    private function reportSummary(TahunAjaran $tahunAjaran, User $admin): void
    {
        if (! $this->command) {
            return;
        }

        $hasActiveTemplate = ReportTemplate::query()
            ->where('tahun_ajaran_id', $tahunAjaran->id)
            ->where('semester', self::SEMESTER)
            ->where('is_active', true)
            ->exists();

        $this->command->info('Demo semester ganjil dataset is ready.');
        $this->command->line("Admin username: {$admin->username}");
        $this->command->line('Guru usernames: '.self::BUDI_USERNAME.', '.self::ANI_USERNAME.', '.self::YUSUF_USERNAME);

        if (! $hasActiveTemplate) {
            $this->command->warn('No active DOCX report template was created. Upload and activate a valid UTS template manually before DOCX generation.');
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function onlyExistingColumns(string $table, array $data): array
    {
        return collect($data)
            ->filter(fn ($value, string $column) => Schema::hasColumn($table, $column))
            ->all();
    }
}

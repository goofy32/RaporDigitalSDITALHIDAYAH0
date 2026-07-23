<?php

namespace App\Console\Commands;

use App\Models\TahunAjaran;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

class CreateStagingSimulationData extends Command
{
    private const CLASS_NAME = 'Kelas Simulasi Load Test';

    private const TEACHER_NAME = 'Guru Dummy Simulasi Load Test';

    private const TEACHER_USERNAME = 'dummy_simulasi_load';

    private const TEACHER_EMAIL = 'dummy.simulasi.load@example.test';

    private const TEACHER_NUPTK = 'SIMLOADNUPTK01';

    private const TEACHER_PASSWORD = 'Simulasi123!';

    private const SUBJECT_NAME = 'Mapel Dummy Simulasi Load Test';

    private const STUDENT_COUNT = 20;

    protected $signature = 'staging:create-simulation-data
        {--dry-run : Preview dummy simulation data without writing anything}';

    protected $description = 'Create an idempotent staging-only dummy class, teacher, subject, students, and grading setup for multi-user simulation.';

    /**
     * @var array<string, array<int, string>>
     */
    private array $columns = [];

    public function handle(): int
    {
        if (! $this->isAllowedEnvironment()) {
            $this->error('Command ini hanya boleh dijalankan di local, testing, staging, atau saat STAGING_TEST_TOOLS_ENABLED=true.');

            return self::FAILURE;
        }

        $tahunAjaran = TahunAjaran::query()
            ->where('is_active', true)
            ->first();

        if (! $tahunAjaran) {
            $this->error('Tidak ada tahun ajaran aktif. Aktifkan tahun ajaran terlebih dahulu sebelum membuat data simulasi.');

            return self::FAILURE;
        }

        $this->warn('PERINGATAN: command ini membuat/menyesuaikan DATA DUMMY untuk simulasi staging saja.');
        $this->line('Data real tidak dihapus, tidak ditruncate, dan tahun ajaran aktif tidak diubah.');

        if ($this->option('dry-run')) {
            $this->displayDryRun($tahunAjaran);

            return self::SUCCESS;
        }

        $stats = [
            'classes_created' => 0,
            'classes_updated' => 0,
            'teachers_created' => 0,
            'teachers_updated' => 0,
            'subjects_created' => 0,
            'subjects_updated' => 0,
            'students_created' => 0,
            'students_updated' => 0,
            'enrollments_created' => 0,
            'enrollments_updated' => 0,
            'lm_created' => 0,
            'lm_updated' => 0,
            'tp_created' => 0,
            'tp_updated' => 0,
            'kkm_created' => 0,
            'kkm_updated' => 0,
            'bobot_created' => 0,
            'bobot_reused' => 0,
        ];

        try {
            $context = DB::transaction(function () use ($tahunAjaran, &$stats) {
                $kelasId = $this->createOrUpdateClass($tahunAjaran, $stats);
                $guruId = $this->createOrUpdateTeacher($kelasId, $stats);
                $this->createOrUpdateTeacherClassRoles($guruId, $kelasId);

                $subjectId = $this->createOrUpdateSubject($tahunAjaran, $kelasId, $guruId, $stats);
                $studentIds = $this->createOrUpdateStudents($tahunAjaran, $kelasId, $stats);

                $this->createOrUpdateLearningData($subjectId, $stats);
                $this->createOrUpdateKkm($tahunAjaran, $kelasId, $subjectId, $stats);
                $this->createBobotIfMissing($tahunAjaran, $stats);

                return compact('kelasId', 'guruId', 'subjectId', 'studentIds');
            });
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info("Data dummy simulasi siap untuk {$tahunAjaran->tahun_ajaran} semester {$tahunAjaran->semester}.");
        $this->table(
            ['Metric', 'Count'],
            collect($stats)->map(fn (int $count, string $metric) => [$metric, $count])->values()->all()
        );

        $this->line('Kelas dummy: '.self::CLASS_NAME.' (#'.$context['kelasId'].')');
        $this->line('Guru dummy: '.self::TEACHER_NAME.' / username: '.self::TEACHER_USERNAME);
        $this->line('Password dummy: '.self::TEACHER_PASSWORD);
        $this->line('Mapel dummy: '.self::SUBJECT_NAME.' (#'.$context['subjectId'].')');
        $this->line('Siswa dummy: '.count($context['studentIds']).' siswa');
        $this->warn('Gunakan hanya untuk staging/local. Jangan gunakan pada data testing guru nyata.');

        return self::SUCCESS;
    }

    private function isAllowedEnvironment(): bool
    {
        $environment = (string) config('app.env');

        return in_array($environment, ['local', 'testing', 'staging'], true)
            || (bool) config('staging_test_tools.enabled');
    }

    private function displayDryRun(TahunAjaran $tahunAjaran): void
    {
        $this->info('DRY RUN: tidak ada data yang ditulis.');
        $this->table(
            ['Data', 'Rencana'],
            [
                ['Tahun ajaran', "{$tahunAjaran->tahun_ajaran} semester {$tahunAjaran->semester}"],
                ['Kelas', self::CLASS_NAME],
                ['Guru', self::TEACHER_NAME.' / '.self::TEACHER_USERNAME],
                ['Mapel', self::SUBJECT_NAME],
                ['Siswa', self::STUDENT_COUNT.' siswa Dummy Simulasi'],
                ['LM/TP', '2 Lingkup Materi, masing-masing 3 TP'],
                ['KKM/Bobot', 'KKM 70 untuk mapel dummy, bobot dibuat hanya jika belum ada'],
            ]
        );
    }

    /**
     * @param  array<string, int>  $stats
     */
    private function createOrUpdateClass(TahunAjaran $tahunAjaran, array &$stats): int
    {
        $existing = DB::table('kelas')
            ->where('nama_kelas', self::CLASS_NAME)
            ->where('tahun_ajaran_id', $tahunAjaran->id)
            ->first();

        $payload = $this->filterPayload('kelas', [
            'nomor_kelas' => 5,
            'nama_kelas' => self::CLASS_NAME,
            'wali_kelas' => self::TEACHER_NAME,
            'tahun_ajaran_id' => $tahunAjaran->id,
            'deleted_at' => null,
            'updated_at' => now(),
        ]);

        if ($existing) {
            DB::table('kelas')->where('id', $existing->id)->update($payload);
            $stats['classes_updated']++;

            return (int) $existing->id;
        }

        $payload['created_at'] = now();
        $stats['classes_created']++;

        return (int) DB::table('kelas')->insertGetId($payload);
    }

    /**
     * @param  array<string, int>  $stats
     */
    private function createOrUpdateTeacher(int $kelasId, array &$stats): int
    {
        $this->assertGuruIdentityIsSafe();

        $existing = DB::table('gurus')
            ->where('username', self::TEACHER_USERNAME)
            ->first();

        if ($existing && ! $this->isDummyText($existing->nama.' '.$existing->username.' '.$existing->email)) {
            throw new RuntimeException('Username guru dummy sudah dipakai data non-dummy. Command dihentikan agar data real tidak berubah.');
        }

        $payload = $this->filterPayload('gurus', [
            'nuptk' => self::TEACHER_NUPTK,
            'nama' => self::TEACHER_NAME,
            'jenis_kelamin' => 'Laki-laki',
            'tanggal_lahir' => '1990-01-01',
            'no_handphone' => '080000000001',
            'email' => self::TEACHER_EMAIL,
            'alamat' => 'Data dummy simulasi load test',
            'jabatan' => 'Guru Simulasi',
            'kelas_pengajar_id' => $kelasId,
            'username' => self::TEACHER_USERNAME,
            'password' => Hash::make(self::TEACHER_PASSWORD),
            'password_plain' => null,
            'must_change_password' => false,
            'photo' => null,
            'signature_path' => null,
            'deleted_at' => null,
            'updated_at' => now(),
        ]);

        if ($existing) {
            DB::table('gurus')->where('id', $existing->id)->update($payload);
            $stats['teachers_updated']++;

            return (int) $existing->id;
        }

        $payload['created_at'] = now();
        $stats['teachers_created']++;

        return (int) DB::table('gurus')->insertGetId($payload);
    }

    private function assertGuruIdentityIsSafe(): void
    {
        foreach (['nuptk' => self::TEACHER_NUPTK, 'email' => self::TEACHER_EMAIL] as $column => $value) {
            if (! Schema::hasColumn('gurus', $column)) {
                continue;
            }

            $existing = DB::table('gurus')
                ->where($column, $value)
                ->where('username', '!=', self::TEACHER_USERNAME)
                ->first();

            if ($existing) {
                throw new RuntimeException("Identitas guru dummy {$column} sudah dipakai data lain. Command dihentikan.");
            }
        }
    }

    private function createOrUpdateTeacherClassRoles(int $guruId, int $kelasId): void
    {
        foreach ([
            ['role' => 'wali_kelas', 'is_wali_kelas' => true],
            ['role' => 'pengajar', 'is_wali_kelas' => false],
        ] as $role) {
            DB::table('guru_kelas')->updateOrInsert(
                [
                    'guru_id' => $guruId,
                    'kelas_id' => $kelasId,
                    'role' => $role['role'],
                ],
                [
                    'is_wali_kelas' => $role['is_wali_kelas'],
                    'updated_at' => now(),
                    'created_at' => now(),
                ]
            );
        }
    }

    /**
     * @param  array<string, int>  $stats
     */
    private function createOrUpdateSubject(TahunAjaran $tahunAjaran, int $kelasId, int $guruId, array &$stats): int
    {
        $existing = DB::table('mata_pelajarans')
            ->where('nama_pelajaran', self::SUBJECT_NAME)
            ->where('kelas_id', $kelasId)
            ->where('tahun_ajaran_id', $tahunAjaran->id)
            ->where('semester', $tahunAjaran->semester)
            ->first();

        $payload = $this->filterPayload('mata_pelajarans', [
            'nama_pelajaran' => self::SUBJECT_NAME,
            'kelas_id' => $kelasId,
            'semester' => $tahunAjaran->semester,
            'guru_id' => $guruId,
            'tahun_ajaran_id' => $tahunAjaran->id,
            'lingkup_materi' => json_encode(['Simulasi Dasar', 'Simulasi Penerapan']),
            'is_muatan_lokal' => false,
            'allow_non_wali' => true,
            'deleted_at' => null,
            'updated_at' => now(),
        ]);

        if ($existing) {
            DB::table('mata_pelajarans')->where('id', $existing->id)->update($payload);
            $stats['subjects_updated']++;

            return (int) $existing->id;
        }

        $payload['created_at'] = now();
        $stats['subjects_created']++;

        return (int) DB::table('mata_pelajarans')->insertGetId($payload);
    }

    /**
     * @param  array<string, int>  $stats
     * @return array<int, int>
     */
    private function createOrUpdateStudents(TahunAjaran $tahunAjaran, int $kelasId, array &$stats): array
    {
        $studentIds = [];

        for ($sequence = 1; $sequence <= self::STUDENT_COUNT; $sequence++) {
            $identity = $this->studentIdentity($tahunAjaran, $sequence);
            $existing = $this->existingStudent($identity['nis'], $identity['nisn']);

            if ($existing && ! $this->isDummyText($existing->nama.' '.$existing->nis.' '.$existing->nisn.' '.($existing->alamat ?? ''))) {
                throw new RuntimeException("NIS/NISN dummy {$identity['nis']} / {$identity['nisn']} sudah dipakai data non-dummy. Command dihentikan.");
            }

            $payload = $this->filterPayload('siswas', [
                'nis' => $identity['nis'],
                'nisn' => $identity['nisn'],
                'nama' => $identity['name'],
                'tanggal_lahir' => sprintf('2015-%02d-%02d', (($sequence - 1) % 12) + 1, (($sequence - 1) % 28) + 1),
                'jenis_kelamin' => $sequence % 2 === 0 ? 'Perempuan' : 'Laki-laki',
                'agama' => 'Islam',
                'alamat' => 'Data dummy simulasi - bukan data siswa asli',
                'kelas_id' => $kelasId,
                'nama_ayah' => 'Ayah Dummy Simulasi',
                'nama_ibu' => 'Ibu Dummy Simulasi',
                'pekerjaan_ayah' => 'Dummy',
                'pekerjaan_ibu' => 'Dummy',
                'wali_siswa' => null,
                'pekerjaan_wali' => null,
                'alamat_orangtua' => 'Data dummy simulasi - bukan alamat asli',
                'photo' => null,
                'tahun_ajaran_id' => $tahunAjaran->id,
                'status' => 'aktif',
                'is_naik_kelas' => null,
                'kelas_tujuan_id' => null,
                'deleted_at' => null,
                'updated_at' => now(),
            ]);

            if ($existing) {
                DB::table('siswas')->where('id', $existing->id)->update($payload);
                $stats['students_updated']++;
                $studentId = (int) $existing->id;
            } else {
                $payload['created_at'] = now();
                $studentId = (int) DB::table('siswas')->insertGetId($payload);
                $stats['students_created']++;
            }

            $this->createOrUpdateEnrollment($studentId, $kelasId, $tahunAjaran, $stats);
            $studentIds[] = $studentId;
        }

        return $studentIds;
    }

    /**
     * @return array{nis: string, nisn: string, name: string}
     */
    private function studentIdentity(TahunAjaran $tahunAjaran, int $sequence): array
    {
        return [
            'nis' => sprintf('SIMLOAD-%04d-%02d', $tahunAjaran->id, $sequence),
            'nisn' => sprintf('SIMLOADN-%04d-%02d', $tahunAjaran->id, $sequence),
            'name' => sprintf('Siswa Dummy Simulasi Load Test %02d', $sequence),
        ];
    }

    private function existingStudent(string $nis, string $nisn): ?object
    {
        $byNis = DB::table('siswas')->where('nis', $nis)->first();
        $byNisn = DB::table('siswas')->where('nisn', $nisn)->first();

        if ($byNis && $byNisn && (int) $byNis->id !== (int) $byNisn->id) {
            throw new RuntimeException("Generated NIS {$nis} dan NISN {$nisn} sudah digunakan oleh dua siswa berbeda.");
        }

        return $byNis ?: $byNisn;
    }

    /**
     * @param  array<string, int>  $stats
     */
    private function createOrUpdateEnrollment(int $studentId, int $kelasId, TahunAjaran $tahunAjaran, array &$stats): void
    {
        $existing = DB::table('siswa_kelas_semester')
            ->where('siswa_id', $studentId)
            ->where('tahun_ajaran_id', $tahunAjaran->id)
            ->where('semester', $tahunAjaran->semester)
            ->first();

        $payload = [
            'kelas_id' => $kelasId,
            'updated_at' => now(),
        ];

        if ($existing) {
            DB::table('siswa_kelas_semester')->where('id', $existing->id)->update($payload);
            $stats['enrollments_updated']++;

            return;
        }

        DB::table('siswa_kelas_semester')->insert([
            'siswa_id' => $studentId,
            'kelas_id' => $kelasId,
            'tahun_ajaran_id' => $tahunAjaran->id,
            'semester' => $tahunAjaran->semester,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $stats['enrollments_created']++;
    }

    /**
     * @param  array<string, int>  $stats
     */
    private function createOrUpdateLearningData(int $subjectId, array &$stats): void
    {
        foreach ($this->learningTemplates() as $lmIndex => $template) {
            $lmId = $this->createOrUpdateLingkupMateri($subjectId, $template['title'], $stats);

            foreach ($template['tp'] as $tpIndex => $description) {
                $this->createOrUpdateTujuanPembelajaran(
                    $lmId,
                    sprintf('SIM-TP%d.%d', $lmIndex + 1, $tpIndex + 1),
                    $description,
                    $stats
                );
            }
        }
    }

    /**
     * @param  array<string, int>  $stats
     */
    private function createOrUpdateLingkupMateri(int $subjectId, string $title, array &$stats): int
    {
        $existing = DB::table('lingkup_materis')
            ->where('mata_pelajaran_id', $subjectId)
            ->where('judul_lingkup_materi', $title)
            ->first();

        $payload = $this->filterPayload('lingkup_materis', [
            'mata_pelajaran_id' => $subjectId,
            'judul_lingkup_materi' => $title,
            'is_active' => true,
            'deleted_at' => null,
            'updated_at' => now(),
        ]);

        if ($existing) {
            DB::table('lingkup_materis')->where('id', $existing->id)->update($payload);
            $stats['lm_updated']++;

            return (int) $existing->id;
        }

        $payload['created_at'] = now();
        $stats['lm_created']++;

        return (int) DB::table('lingkup_materis')->insertGetId($payload);
    }

    /**
     * @param  array<string, int>  $stats
     */
    private function createOrUpdateTujuanPembelajaran(int $lmId, string $code, string $description, array &$stats): void
    {
        $existing = DB::table('tujuan_pembelajarans')
            ->where('lingkup_materi_id', $lmId)
            ->where('kode_tp', $code)
            ->first();

        $payload = $this->filterPayload('tujuan_pembelajarans', [
            'lingkup_materi_id' => $lmId,
            'kode_tp' => $code,
            'deskripsi_tp' => $description,
            'deleted_at' => null,
            'updated_at' => now(),
        ]);

        if ($existing) {
            DB::table('tujuan_pembelajarans')->where('id', $existing->id)->update($payload);
            $stats['tp_updated']++;

            return;
        }

        $payload['created_at'] = now();
        DB::table('tujuan_pembelajarans')->insert($payload);
        $stats['tp_created']++;
    }

    /**
     * @return array<int, array{title: string, tp: array<int, string>}>
     */
    private function learningTemplates(): array
    {
        return [
            [
                'title' => 'Simulasi Pemahaman Dasar',
                'tp' => [
                    'Memahami instruksi sederhana pada kegiatan simulasi.',
                    'Mengidentifikasi informasi utama dari contoh pembelajaran.',
                    'Menyelesaikan latihan dasar secara mandiri.',
                ],
            ],
            [
                'title' => 'Simulasi Penerapan',
                'tp' => [
                    'Menerapkan konsep pada soal latihan simulasi.',
                    'Menjelaskan alasan jawaban secara runtut.',
                    'Menunjukkan ketelitian saat menyelesaikan tugas.',
                ],
            ],
        ];
    }

    /**
     * @param  array<string, int>  $stats
     */
    private function createOrUpdateKkm(TahunAjaran $tahunAjaran, int $kelasId, int $subjectId, array &$stats): void
    {
        if (! Schema::hasTable('kkms')) {
            return;
        }

        $existing = DB::table('kkms')
            ->where('mata_pelajaran_id', $subjectId)
            ->where('kelas_id', $kelasId)
            ->where('tahun_ajaran_id', $tahunAjaran->id)
            ->first();

        $payload = $this->filterPayload('kkms', [
            'mata_pelajaran_id' => $subjectId,
            'kelas_id' => $kelasId,
            'tahun_ajaran_id' => $tahunAjaran->id,
            'nilai' => 70,
            'updated_at' => now(),
        ]);

        if ($existing) {
            DB::table('kkms')->where('id', $existing->id)->update($payload);
            $stats['kkm_updated']++;

            return;
        }

        $payload['created_at'] = now();
        DB::table('kkms')->insert($payload);
        $stats['kkm_created']++;
    }

    /**
     * @param  array<string, int>  $stats
     */
    private function createBobotIfMissing(TahunAjaran $tahunAjaran, array &$stats): void
    {
        if (! Schema::hasTable('bobot_nilais')) {
            return;
        }

        $existing = DB::table('bobot_nilais')
            ->where('tahun_ajaran_id', $tahunAjaran->id)
            ->first();

        if ($existing) {
            $stats['bobot_reused']++;

            return;
        }

        DB::table('bobot_nilais')->insert($this->filterPayload('bobot_nilais', [
            'tahun_ajaran_id' => $tahunAjaran->id,
            'bobot_tp' => 1,
            'bobot_lm' => 1,
            'bobot_as' => 2,
            'created_at' => now(),
            'updated_at' => now(),
        ]));

        $stats['bobot_created']++;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function filterPayload(string $table, array $payload): array
    {
        $columns = $this->columns[$table] ??= Schema::getColumnListing($table);

        return collect($payload)
            ->only($columns)
            ->all();
    }

    private function isDummyText(?string $value): bool
    {
        $value = mb_strtolower((string) $value, 'UTF-8');

        if ($value === '') {
            return false;
        }

        foreach ((array) config('staging_test_tools.dummy_markers', ['dummy', 'test', 'simulasi']) as $marker) {
            if ($marker !== '' && str_contains($value, mb_strtolower((string) $marker, 'UTF-8'))) {
                return true;
            }
        }

        return false;
    }
}

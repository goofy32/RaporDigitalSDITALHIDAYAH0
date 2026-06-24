<?php

namespace App\Console\Commands;

use App\Models\TahunAjaran;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

class CreateMultiWaliLoadData extends Command
{
    private const WALI_PREFIX = 'Wali Load Test';

    private const CLASS_PREFIX = 'Kelas Load Test';

    private const STUDENT_PREFIX = 'Siswa Load Test';

    private const USERNAME_PREFIX = 'wali_load_test_';

    private const PASSWORD = 'LoadTest123!';

    protected $signature = 'staging:create-multi-wali-load-data
        {--wali=20 : Number of dummy wali/classes to create}
        {--students=20 : Number of dummy students per class}
        {--dry-run : Preview load-test data without writing anything}';

    protected $description = 'Create idempotent staging-only multi-wali load-test data with representative report grading data.';

    /**
     * @var array<string, array<int, string>>
     */
    private array $columns = [];

    /**
     * @var array<int, string>
     */
    private array $warnings = [];

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
            $this->error('Tidak ada tahun ajaran aktif. Aktifkan tahun ajaran terlebih dahulu sebelum membuat data load test.');

            return self::FAILURE;
        }

        $semester = (int) $tahunAjaran->semester;
        if (! in_array($semester, [1, 2], true)) {
            $this->error("Semester aktif tidak valid: {$semester}. Command hanya mendukung semester 1/UTS atau 2/UAS.");

            return self::FAILURE;
        }

        $waliCount = max(1, (int) $this->option('wali'));
        $studentsPerClass = max(1, (int) $this->option('students'));
        $reportType = $semester === 1 ? 'UTS' : 'UAS';

        $this->warn('PERINGATAN: command ini membuat/menyesuaikan DATA DUMMY LOAD TEST untuk staging saja.');
        $this->line('Tidak ada truncate, tidak mengubah data real/non-test, dan tidak membuat konteks UAS saat semester 2 tidak aktif.');

        if ($this->option('dry-run')) {
            $this->displayDryRun($tahunAjaran, $waliCount, $studentsPerClass, $reportType);

            return self::SUCCESS;
        }

        $stats = $this->emptyStats();
        $classIds = [];

        try {
            DB::transaction(function () use ($tahunAjaran, $semester, $reportType, $waliCount, $studentsPerClass, &$stats, &$classIds): void {
                $this->ensureSettings($stats);
                $this->createBobotIfMissing($tahunAjaran, $stats);

                for ($waliSequence = 1; $waliSequence <= $waliCount; $waliSequence++) {
                    $kelasId = $this->createOrUpdateClass($tahunAjaran, $waliSequence, $stats);
                    $guruId = $this->createOrUpdateTeacher($waliSequence, $kelasId, $stats);
                    $this->createOrUpdateTeacherClassRoles($guruId, $kelasId);

                    $subjectContexts = $this->createSubjectsWithLearningData(
                        $tahunAjaran,
                        $semester,
                        $waliSequence,
                        $kelasId,
                        $guruId,
                        $stats
                    );

                    foreach ($subjectContexts as $subjectContext) {
                        $this->createOrUpdateKkm($tahunAjaran, $kelasId, $subjectContext['id'], $stats);
                    }

                    $students = $this->createOrUpdateStudents(
                        $tahunAjaran,
                        $semester,
                        $waliSequence,
                        $kelasId,
                        $stats,
                        $studentsPerClass
                    );

                    foreach ($students as $student) {
                        $this->createOrUpdateAttendance($student['id'], $tahunAjaran, $semester, $waliSequence, $student['sequence'], $stats);
                        $this->createOrUpdateStudentNote($student['id'], $guruId, $tahunAjaran, $semester, $reportType, $stats);

                        foreach ($subjectContexts as $subjectIndex => $subjectContext) {
                            $this->createOrUpdateScores(
                                $student['id'],
                                $subjectContext,
                                $tahunAjaran,
                                $waliSequence,
                                $student['sequence'],
                                $subjectIndex + 1,
                                $stats
                            );
                            $this->createOrUpdateSubjectNote($student['id'], $guruId, $subjectContext['id'], $tahunAjaran, $semester, $reportType, $stats);
                            $this->createOrUpdateCapaian($student['id'], $subjectContext['id'], $tahunAjaran, $semester, $subjectContext['name'], $stats);
                        }
                    }

                    $classIds[] = $kelasId;
                }

                $this->ensureTemplateAvailability($classIds, $tahunAjaran, $reportType, $stats);
            });
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info("Data multi-wali load test siap untuk {$tahunAjaran->tahun_ajaran} semester {$semester} ({$reportType}).");
        $this->displaySummary($stats, $tahunAjaran, $semester, $reportType);

        return self::SUCCESS;
    }

    private function isAllowedEnvironment(): bool
    {
        $environment = (string) config('app.env');

        return in_array($environment, ['local', 'testing', 'staging'], true)
            || (bool) config('staging_test_tools.enabled');
    }

    /**
     * @return array<string, int>
     */
    private function emptyStats(): array
    {
        return [
            'wali_created' => 0,
            'wali_reused' => 0,
            'classes_created' => 0,
            'classes_reused' => 0,
            'students_created' => 0,
            'students_reused' => 0,
            'enrollments_created' => 0,
            'enrollments_reused' => 0,
            'subjects_created' => 0,
            'subjects_reused' => 0,
            'lm_created' => 0,
            'lm_reused' => 0,
            'tp_created' => 0,
            'tp_reused' => 0,
            'kkm_created' => 0,
            'kkm_reused' => 0,
            'bobot_created' => 0,
            'bobot_reused' => 0,
            'settings_created' => 0,
            'settings_reused' => 0,
            'nilai_rows_created' => 0,
            'nilai_rows_reused' => 0,
            'attendance_rows_created' => 0,
            'attendance_rows_reused' => 0,
            'notes_capaian_rows_created' => 0,
            'notes_capaian_rows_reused' => 0,
            'template_links_created' => 0,
            'template_links_reused' => 0,
            'template_available_classes' => 0,
            'template_unavailable_classes' => 0,
        ];
    }

    private function displayDryRun(TahunAjaran $tahunAjaran, int $waliCount, int $studentsPerClass, string $reportType): void
    {
        $subjectCount = count($this->subjectTemplates());
        $studentsTotal = $waliCount * $studentsPerClass;
        $subjectsTotal = $waliCount * $subjectCount;
        $tpTotal = $subjectsTotal * 6;
        $nilaiRowsTotal = $studentsTotal * $subjectCount * 9;

        $this->info('DRY RUN: tidak ada data yang ditulis.');
        $this->table(
            ['Data', 'Rencana'],
            [
                ['Tahun ajaran aktif', "{$tahunAjaran->tahun_ajaran} semester {$tahunAjaran->semester} ({$reportType})"],
                ['Wali/Guru', "{$waliCount} wali: ".self::WALI_PREFIX.' 01 ...'],
                ['Kelas', "{$waliCount} kelas: ".self::CLASS_PREFIX.' 01 ...'],
                ['Siswa', "{$studentsTotal} siswa: ".self::STUDENT_PREFIX.' 01-01 ...'],
                ['Mapel', "{$subjectsTotal} mapel load-test, {$subjectCount} per kelas"],
                ['LM/TP', "{$tpTotal} TP dari 2 lingkup materi x 3 TP per mapel"],
                ['Nilai', "{$nilaiRowsTotal} baris nilai TP/LM/akhir rapor deterministik"],
                ['Absensi', "{$studentsTotal} baris sakit/izin/tanpa keterangan"],
                ['Catatan/Capaian', 'Catatan wali, catatan mapel, dan capaian custom untuk siswa dummy'],
                ['Template', 'Cek/tautkan template aktif yang sudah ada untuk kelas dummy bila tersedia'],
            ]
        );
    }

    /**
     * @param array<string, int> $stats
     */
    private function displaySummary(array $stats, TahunAjaran $tahunAjaran, int $semester, string $reportType): void
    {
        $this->table(
            ['Ringkasan', 'Jumlah'],
            [
                ['wali created/reused', $stats['wali_created'].' / '.$stats['wali_reused']],
                ['classes created/reused', $stats['classes_created'].' / '.$stats['classes_reused']],
                ['students created/reused', $stats['students_created'].' / '.$stats['students_reused']],
                ['subjects created/reused', $stats['subjects_created'].' / '.$stats['subjects_reused']],
                ['nilai rows created/reused', $stats['nilai_rows_created'].' / '.$stats['nilai_rows_reused']],
                ['attendance rows created/reused', $stats['attendance_rows_created'].' / '.$stats['attendance_rows_reused']],
                ['notes/capaian rows created/reused', $stats['notes_capaian_rows_created'].' / '.$stats['notes_capaian_rows_reused']],
                ['active tahun ajaran/semester', "{$tahunAjaran->tahun_ajaran} / {$semester} ({$reportType})"],
                ['template available/unavailable classes', $stats['template_available_classes'].' / '.$stats['template_unavailable_classes']],
                ['template links created/reused', $stats['template_links_created'].' / '.$stats['template_links_reused']],
            ]
        );

        $this->line('Naming convention: '.self::WALI_PREFIX.' 01, '.self::CLASS_PREFIX.' 01, '.self::STUDENT_PREFIX.' 01-01.');
        $this->line('Dummy grading data: TP 78-95, STS/UTS 80-92, absensi 0-2, catatan wali/mapel, capaian custom, KKM, dan bobot nilai.');

        if ($this->warnings !== []) {
            foreach (array_unique($this->warnings) as $warning) {
                $this->warn($warning);
            }
        }
    }

    /**
     * @param array<string, int> $stats
     */
    private function createOrUpdateClass(TahunAjaran $tahunAjaran, int $sequence, array &$stats): int
    {
        $name = $this->className($sequence);
        $existing = DB::table('kelas')
            ->where('nama_kelas', $name)
            ->where('tahun_ajaran_id', $tahunAjaran->id)
            ->first();

        $payload = $this->filterPayload('kelas', [
            'nomor_kelas' => 5,
            'nama_kelas' => $name,
            'wali_kelas' => $this->waliName($sequence),
            'tahun_ajaran_id' => $tahunAjaran->id,
            'deleted_at' => null,
            'updated_at' => now(),
        ]);

        if ($existing) {
            $this->assertDummyText($existing->nama_kelas, "Kelas {$name} sudah ada tetapi tidak terlihat sebagai data dummy/load-test.");
            DB::table('kelas')->where('id', $existing->id)->update($payload);
            $stats['classes_reused']++;

            return (int) $existing->id;
        }

        $payload['created_at'] = now();
        $stats['classes_created']++;

        return (int) DB::table('kelas')->insertGetId($payload);
    }

    /**
     * @param array<string, int> $stats
     */
    private function createOrUpdateTeacher(int $sequence, int $kelasId, array &$stats): int
    {
        $username = self::USERNAME_PREFIX.sprintf('%02d', $sequence);
        $existing = DB::table('gurus')
            ->where('username', $username)
            ->first();

        if ($existing && ! $this->isDummyText($existing->nama.' '.$existing->username.' '.($existing->email ?? ''))) {
            throw new RuntimeException("Username {$username} sudah dipakai data non-dummy. Command dihentikan.");
        }

        $this->assertTeacherIdentityIsSafe($sequence, $username);

        $payload = $this->filterPayload('gurus', [
            'nuptk' => sprintf('LOADTESTWALI%02d', $sequence),
            'nama' => $this->waliName($sequence),
            'jenis_kelamin' => $sequence % 2 === 0 ? 'Perempuan' : 'Laki-laki',
            'tanggal_lahir' => '1990-01-01',
            'no_handphone' => sprintf('08000010%04d', $sequence),
            'email' => sprintf('wali.load.test.%02d@example.test', $sequence),
            'alamat' => 'Data dummy load test - bukan data guru asli',
            'jabatan' => 'Wali Load Test',
            'kelas_pengajar_id' => $kelasId,
            'username' => $username,
            'password' => Hash::make(self::PASSWORD),
            'password_plain' => null,
            'must_change_password' => false,
            'photo' => null,
            'signature_path' => null,
            'deleted_at' => null,
            'updated_at' => now(),
        ]);

        if ($existing) {
            DB::table('gurus')->where('id', $existing->id)->update($payload);
            $stats['wali_reused']++;

            return (int) $existing->id;
        }

        $payload['created_at'] = now();
        $stats['wali_created']++;

        return (int) DB::table('gurus')->insertGetId($payload);
    }

    private function assertTeacherIdentityIsSafe(int $sequence, string $username): void
    {
        foreach ([
            'nuptk' => sprintf('LOADTESTWALI%02d', $sequence),
            'email' => sprintf('wali.load.test.%02d@example.test', $sequence),
        ] as $column => $value) {
            if (! Schema::hasColumn('gurus', $column)) {
                continue;
            }

            $existing = DB::table('gurus')
                ->where($column, $value)
                ->where('username', '!=', $username)
                ->first();

            if ($existing) {
                throw new RuntimeException("Identitas wali load test {$column} sudah dipakai data lain. Command dihentikan.");
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
     * @param array<string, int> $stats
     * @return array<int, array{id: int, name: string, lingkup: array<int, array{id: int, tp: array<int, int>}>}>
     */
    private function createSubjectsWithLearningData(
        TahunAjaran $tahunAjaran,
        int $semester,
        int $waliSequence,
        int $kelasId,
        int $guruId,
        array &$stats
    ): array {
        $contexts = [];

        foreach ($this->subjectTemplates() as $subjectIndex => $template) {
            $subjectId = $this->createOrUpdateSubject($tahunAjaran, $semester, $kelasId, $guruId, $template, $stats);
            $contexts[] = [
                'id' => $subjectId,
                'name' => $template['name'],
                'lingkup' => $this->createOrUpdateLearningData($subjectId, $template['name'], $waliSequence, $subjectIndex + 1, $stats),
            ];
        }

        return $contexts;
    }

    /**
     * @param array{name: string, muatan_lokal: bool} $template
     * @param array<string, int> $stats
     */
    private function createOrUpdateSubject(TahunAjaran $tahunAjaran, int $semester, int $kelasId, int $guruId, array $template, array &$stats): int
    {
        $existing = DB::table('mata_pelajarans')
            ->where('nama_pelajaran', $template['name'])
            ->where('kelas_id', $kelasId)
            ->where('tahun_ajaran_id', $tahunAjaran->id)
            ->where('semester', $semester)
            ->first();

        $payload = $this->filterPayload('mata_pelajarans', [
            'nama_pelajaran' => $template['name'],
            'kelas_id' => $kelasId,
            'semester' => $semester,
            'guru_id' => $guruId,
            'tahun_ajaran_id' => $tahunAjaran->id,
            'lingkup_materi' => json_encode(['Load Test Pemahaman', 'Load Test Penerapan']),
            'is_muatan_lokal' => $template['muatan_lokal'],
            'allow_non_wali' => true,
            'deleted_at' => null,
            'updated_at' => now(),
        ]);

        if ($existing) {
            DB::table('mata_pelajarans')->where('id', $existing->id)->update($payload);
            $stats['subjects_reused']++;

            return (int) $existing->id;
        }

        $payload['created_at'] = now();
        $stats['subjects_created']++;

        return (int) DB::table('mata_pelajarans')->insertGetId($payload);
    }

    /**
     * @param array<string, int> $stats
     * @return array<int, array{id: int, tp: array<int, int>}>
     */
    private function createOrUpdateLearningData(int $subjectId, string $subjectName, int $waliSequence, int $subjectIndex, array &$stats): array
    {
        $contexts = [];

        for ($lmIndex = 1; $lmIndex <= 2; $lmIndex++) {
            $lmTitle = sprintf('Load Test %s Lingkup %d', $subjectName, $lmIndex);
            $lmId = $this->createOrUpdateLingkupMateri($subjectId, $lmTitle, $stats);
            $tpIds = [];

            for ($tpIndex = 1; $tpIndex <= 3; $tpIndex++) {
                $code = sprintf('LT-%02d-%02d-%02d-%02d', $waliSequence, $subjectIndex, $lmIndex, $tpIndex);
                $description = sprintf(
                    'Load test TP %d.%d untuk %s: memahami, menerapkan, dan menjelaskan materi secara runtut.',
                    $lmIndex,
                    $tpIndex,
                    $subjectName
                );
                $tpIds[] = $this->createOrUpdateTujuanPembelajaran($lmId, $code, $description, $stats);
            }

            $contexts[] = [
                'id' => $lmId,
                'tp' => $tpIds,
            ];
        }

        return $contexts;
    }

    /**
     * @param array<string, int> $stats
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
            $stats['lm_reused']++;

            return (int) $existing->id;
        }

        $payload['created_at'] = now();
        $stats['lm_created']++;

        return (int) DB::table('lingkup_materis')->insertGetId($payload);
    }

    /**
     * @param array<string, int> $stats
     */
    private function createOrUpdateTujuanPembelajaran(int $lmId, string $code, string $description, array &$stats): int
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
            $stats['tp_reused']++;

            return (int) $existing->id;
        }

        $payload['created_at'] = now();
        $stats['tp_created']++;

        return (int) DB::table('tujuan_pembelajarans')->insertGetId($payload);
    }

    /**
     * @param array<string, int> $stats
     * @return array<int, array{id: int, sequence: int}>
     */
    private function createOrUpdateStudents(
        TahunAjaran $tahunAjaran,
        int $semester,
        int $waliSequence,
        int $kelasId,
        array &$stats,
        int $studentsPerClass
    ): array {
        $students = [];

        for ($studentSequence = 1; $studentSequence <= $studentsPerClass; $studentSequence++) {
            $identity = $this->studentIdentity($tahunAjaran, $waliSequence, $studentSequence);
            $existing = $this->existingStudent($identity['nis'], $identity['nisn']);

            if ($existing && ! $this->isDummyText($existing->nama.' '.$existing->nis.' '.$existing->nisn.' '.($existing->alamat ?? ''))) {
                throw new RuntimeException("NIS/NISN {$identity['nis']} / {$identity['nisn']} sudah dipakai data non-dummy. Command dihentikan.");
            }

            $payload = $this->filterPayload('siswas', [
                'nis' => $identity['nis'],
                'nisn' => $identity['nisn'],
                'nama' => $identity['name'],
                'tanggal_lahir' => sprintf('2015-%02d-%02d', (($studentSequence - 1) % 12) + 1, (($studentSequence - 1) % 28) + 1),
                'jenis_kelamin' => $studentSequence % 2 === 0 ? 'Perempuan' : 'Laki-laki',
                'agama' => 'Islam',
                'alamat' => 'Data dummy load test - bukan alamat siswa asli',
                'kelas_id' => $kelasId,
                'nama_ayah' => 'Ayah Load Test',
                'nama_ibu' => 'Ibu Load Test',
                'pekerjaan_ayah' => 'Dummy Load Test',
                'pekerjaan_ibu' => 'Dummy Load Test',
                'wali_siswa' => null,
                'pekerjaan_wali' => null,
                'alamat_orangtua' => 'Data dummy load test - bukan alamat orang tua asli',
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
                $studentId = (int) $existing->id;
                $stats['students_reused']++;
            } else {
                $payload['created_at'] = now();
                $studentId = (int) DB::table('siswas')->insertGetId($payload);
                $stats['students_created']++;
            }

            $this->createOrUpdateEnrollment($studentId, $kelasId, $tahunAjaran, $semester, $stats);
            $students[] = [
                'id' => $studentId,
                'sequence' => $studentSequence,
            ];
        }

        return $students;
    }

    /**
     * @return array{nis: string, nisn: string, name: string}
     */
    private function studentIdentity(TahunAjaran $tahunAjaran, int $waliSequence, int $studentSequence): array
    {
        return [
            'nis' => sprintf('LOADTEST-%04d-%02d-%02d', $tahunAjaran->id, $waliSequence, $studentSequence),
            'nisn' => sprintf('LOADTESTN-%04d-%02d-%02d', $tahunAjaran->id, $waliSequence, $studentSequence),
            'name' => sprintf('%s %02d-%02d', self::STUDENT_PREFIX, $waliSequence, $studentSequence),
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
     * @param array<string, int> $stats
     */
    private function createOrUpdateEnrollment(int $studentId, int $kelasId, TahunAjaran $tahunAjaran, int $semester, array &$stats): void
    {
        $existing = DB::table('siswa_kelas_semester')
            ->where('siswa_id', $studentId)
            ->where('tahun_ajaran_id', $tahunAjaran->id)
            ->where('semester', $semester)
            ->first();

        $payload = [
            'kelas_id' => $kelasId,
            'updated_at' => now(),
        ];

        if ($existing) {
            DB::table('siswa_kelas_semester')->where('id', $existing->id)->update($payload);
            $stats['enrollments_reused']++;

            return;
        }

        DB::table('siswa_kelas_semester')->insert([
            'siswa_id' => $studentId,
            'kelas_id' => $kelasId,
            'tahun_ajaran_id' => $tahunAjaran->id,
            'semester' => $semester,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $stats['enrollments_created']++;
    }

    /**
     * @param array{id: int, name: string, lingkup: array<int, array{id: int, tp: array<int, int>}>} $subjectContext
     * @param array<string, int> $stats
     */
    private function createOrUpdateScores(
        int $studentId,
        array $subjectContext,
        TahunAjaran $tahunAjaran,
        int $waliSequence,
        int $studentSequence,
        int $subjectIndex,
        array &$stats
    ): void {
        $tpScores = [];
        $lmScores = [];

        foreach ($subjectContext['lingkup'] as $lmIndex => $lmContext) {
            $scoresForLm = [];

            foreach ($lmContext['tp'] as $tpIndex => $tpId) {
                $score = $this->scoreBetween(78, 95, $waliSequence, $studentSequence, $subjectIndex, $lmIndex + 1, $tpIndex + 1);
                $scoresForLm[] = $score;
                $tpScores[] = $score;

                $this->createOrUpdateNilaiRow(
                    [
                        'siswa_id' => $studentId,
                        'mata_pelajaran_id' => $subjectContext['id'],
                        'tujuan_pembelajaran_id' => $tpId,
                        'lingkup_materi_id' => $lmContext['id'],
                        'tahun_ajaran_id' => $tahunAjaran->id,
                    ],
                    [
                        'nilai_tp' => $score,
                        'na_tp' => $score,
                        'nilai_lm' => null,
                        'nilai_akhir_semester' => null,
                        'nilai_akhir_rapor' => null,
                        'tp_number' => $tpIndex + 1,
                        'is_submitted' => true,
                        'deleted_at' => null,
                    ],
                    $stats
                );
            }

            $lmScore = round(array_sum($scoresForLm) / max(1, count($scoresForLm)), 2);
            $lmScores[] = $lmScore;

            $this->createOrUpdateNilaiRow(
                [
                    'siswa_id' => $studentId,
                    'mata_pelajaran_id' => $subjectContext['id'],
                    'tujuan_pembelajaran_id' => null,
                    'lingkup_materi_id' => $lmContext['id'],
                    'tahun_ajaran_id' => $tahunAjaran->id,
                ],
                [
                    'nilai_tp' => null,
                    'nilai_lm' => $lmScore,
                    'na_lm' => $lmScore,
                    'nilai_akhir_semester' => null,
                    'nilai_akhir_rapor' => null,
                    'is_submitted' => true,
                    'deleted_at' => null,
                ],
                $stats
            );
        }

        $nilaiTes = $this->scoreBetween(80, 92, $waliSequence, $studentSequence, $subjectIndex, 7, 1);
        $nilaiNonTes = $this->scoreBetween(80, 92, $waliSequence, $studentSequence, $subjectIndex, 7, 2);
        $tpAverage = round(array_sum($tpScores) / max(1, count($tpScores)), 2);
        $lmAverage = round(array_sum($lmScores) / max(1, count($lmScores)), 2);
        $nilaiAkhir = round(($tpAverage + $lmAverage + $nilaiTes + $nilaiNonTes) / 4, 2);

        $this->createOrUpdateNilaiRow(
            [
                'siswa_id' => $studentId,
                'mata_pelajaran_id' => $subjectContext['id'],
                'tujuan_pembelajaran_id' => null,
                'lingkup_materi_id' => null,
                'tahun_ajaran_id' => $tahunAjaran->id,
            ],
            [
                'nilai_tp' => null,
                'nilai_lm' => null,
                'na_tp' => $tpAverage,
                'na_lm' => $lmAverage,
                'nilai_tes' => $nilaiTes,
                'nilai_non_tes' => $nilaiNonTes,
                'na_sumatif_semester' => $nilaiTes,
                'nilai_akhir_semester' => $nilaiAkhir,
                'nilai_akhir_rapor' => $nilaiAkhir,
                'is_submitted' => true,
                'deleted_at' => null,
            ],
            $stats
        );
    }

    /**
     * @param array<string, mixed> $criteria
     * @param array<string, mixed> $values
     * @param array<string, int> $stats
     */
    private function createOrUpdateNilaiRow(array $criteria, array $values, array &$stats): void
    {
        if (! Schema::hasTable('nilais')) {
            return;
        }

        $criteria = $this->filterPayload('nilais', $criteria);
        $payload = $this->filterPayload('nilais', array_merge($criteria, $values, [
            'updated_at' => now(),
        ]));

        $existing = $this->firstByCriteria('nilais', $criteria);

        if ($existing) {
            DB::table('nilais')->where('id', $existing->id)->update($payload);
            $stats['nilai_rows_reused']++;

            return;
        }

        $payload['created_at'] = now();
        DB::table('nilais')->insert($payload);
        $stats['nilai_rows_created']++;
    }

    /**
     * @param array<string, int> $stats
     */
    private function createOrUpdateAttendance(int $studentId, TahunAjaran $tahunAjaran, int $semester, int $waliSequence, int $studentSequence, array &$stats): void
    {
        if (! Schema::hasTable('absensis')) {
            return;
        }

        $criteria = $this->filterPayload('absensis', [
            'siswa_id' => $studentId,
            'tahun_ajaran_id' => $tahunAjaran->id,
            'semester' => $semester,
        ]);

        $payload = $this->filterPayload('absensis', array_merge($criteria, [
            'sakit' => ($waliSequence + $studentSequence) % 3,
            'izin' => ($waliSequence + $studentSequence + 1) % 3,
            'tanpa_keterangan' => ($waliSequence + $studentSequence + 2) % 2,
            'deleted_at' => null,
            'updated_at' => now(),
        ]));

        $existing = $this->firstByCriteria('absensis', $criteria);

        if ($existing) {
            DB::table('absensis')->where('id', $existing->id)->update($payload);
            $stats['attendance_rows_reused']++;

            return;
        }

        $payload['created_at'] = now();
        DB::table('absensis')->insert($payload);
        $stats['attendance_rows_created']++;
    }

    /**
     * @param array<string, int> $stats
     */
    private function createOrUpdateStudentNote(int $studentId, int $guruId, TahunAjaran $tahunAjaran, int $semester, string $reportType, array &$stats): void
    {
        if (! Schema::hasTable('catatan_siswa')) {
            return;
        }

        $criteria = $this->filterPayload('catatan_siswa', [
            'siswa_id' => $studentId,
            'tahun_ajaran_id' => $tahunAjaran->id,
            'semester' => $semester,
            'type' => strtolower($reportType),
        ]);

        $payload = $this->filterPayload('catatan_siswa', array_merge($criteria, [
            'catatan' => 'Catatan load test: siswa menunjukkan kebiasaan belajar baik dan konsisten mengikuti kegiatan kelas.',
            'created_by' => $guruId,
            'updated_at' => now(),
        ]));

        $this->upsertNotesOrCapaianRow('catatan_siswa', $criteria, $payload, $stats);
    }

    /**
     * @param array<string, int> $stats
     */
    private function createOrUpdateSubjectNote(int $studentId, int $guruId, int $subjectId, TahunAjaran $tahunAjaran, int $semester, string $reportType, array &$stats): void
    {
        if (! Schema::hasTable('catatan_mata_pelajaran')) {
            return;
        }

        $criteria = $this->filterPayload('catatan_mata_pelajaran', [
            'mata_pelajaran_id' => $subjectId,
            'siswa_id' => $studentId,
            'tahun_ajaran_id' => $tahunAjaran->id,
            'semester' => $semester,
            'type' => strtolower($reportType),
        ]);

        $payload = $this->filterPayload('catatan_mata_pelajaran', array_merge($criteria, [
            'catatan' => 'Catatan mapel load test: capaian stabil, latihan selesai, dan respons pembelajaran baik.',
            'created_by' => $guruId,
            'updated_at' => now(),
        ]));

        $this->upsertNotesOrCapaianRow('catatan_mata_pelajaran', $criteria, $payload, $stats);
    }

    /**
     * @param array<string, int> $stats
     */
    private function createOrUpdateCapaian(int $studentId, int $subjectId, TahunAjaran $tahunAjaran, int $semester, string $subjectName, array &$stats): void
    {
        if (! Schema::hasTable('capaian_custom')) {
            return;
        }

        $criteria = $this->filterPayload('capaian_custom', [
            'siswa_id' => $studentId,
            'mata_pelajaran_id' => $subjectId,
            'tahun_ajaran_id' => $tahunAjaran->id,
            'semester' => $semester,
        ]);

        $payload = $this->filterPayload('capaian_custom', array_merge($criteria, [
            'custom_capaian' => "Capaian load test {$subjectName}: siswa mampu mengikuti pembelajaran dan menyelesaikan tugas dengan baik.",
            'custom_capaian_tertinggi' => "Menguasai materi utama {$subjectName} dengan percaya diri.",
            'custom_capaian_terendah' => "Perlu latihan lanjutan untuk memperkuat ketelitian pada {$subjectName}.",
            'tertinggi_prefix_mode' => 'default',
            'tertinggi_prefix_text' => null,
            'terendah_prefix_mode' => 'default',
            'terendah_prefix_text' => null,
            'updated_at' => now(),
        ]));

        $this->upsertNotesOrCapaianRow('capaian_custom', $criteria, $payload, $stats);
    }

    /**
     * @param array<string, mixed> $criteria
     * @param array<string, mixed> $payload
     * @param array<string, int> $stats
     */
    private function upsertNotesOrCapaianRow(string $table, array $criteria, array $payload, array &$stats): void
    {
        $existing = $this->firstByCriteria($table, $criteria);

        if ($existing) {
            DB::table($table)->where('id', $existing->id)->update($payload);
            $stats['notes_capaian_rows_reused']++;

            return;
        }

        $payload['created_at'] = now();
        DB::table($table)->insert($payload);
        $stats['notes_capaian_rows_created']++;
    }

    /**
     * @param array<string, int> $stats
     */
    private function createOrUpdateKkm(TahunAjaran $tahunAjaran, int $kelasId, int $subjectId, array &$stats): void
    {
        if (! Schema::hasTable('kkms')) {
            return;
        }

        $criteria = $this->filterPayload('kkms', [
            'mata_pelajaran_id' => $subjectId,
            'kelas_id' => $kelasId,
            'tahun_ajaran_id' => $tahunAjaran->id,
        ]);

        $payload = $this->filterPayload('kkms', array_merge($criteria, [
            'nilai' => 70,
            'updated_at' => now(),
        ]));

        $existing = $this->firstByCriteria('kkms', $criteria);

        if ($existing) {
            DB::table('kkms')->where('id', $existing->id)->update($payload);
            $stats['kkm_reused']++;

            return;
        }

        $payload['created_at'] = now();
        DB::table('kkms')->insert($payload);
        $stats['kkm_created']++;
    }

    /**
     * @param array<string, int> $stats
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
     * @param array<string, int> $stats
     */
    private function ensureSettings(array &$stats): void
    {
        if (! Schema::hasTable('settings')) {
            return;
        }

        $existing = DB::table('settings')->where('key', 'kkm_notification_complete_scores_only')->first();

        if ($existing) {
            $stats['settings_reused']++;

            return;
        }

        DB::table('settings')->insert($this->filterPayload('settings', [
            'key' => 'kkm_notification_complete_scores_only',
            'value' => '0',
            'created_at' => now(),
            'updated_at' => now(),
        ]));

        $stats['settings_created']++;
    }

    /**
     * @param array<int, int> $classIds
     * @param array<string, int> $stats
     */
    private function ensureTemplateAvailability(array $classIds, TahunAjaran $tahunAjaran, string $reportType, array &$stats): void
    {
        if (! Schema::hasTable('report_templates')) {
            $stats['template_unavailable_classes'] += count($classIds);
            $this->warnings[] = 'WARNING: report_templates table tidak tersedia; PDF warm-up akan skip template_unavailable.';

            return;
        }

        $sourceTemplate = $this->sourceTemplate($tahunAjaran, $reportType);

        foreach ($classIds as $classId) {
            if ($this->hasTemplateForClass($classId, $tahunAjaran, $reportType)) {
                $stats['template_available_classes']++;

                continue;
            }

            if ($sourceTemplate && Schema::hasTable('report_template_kelas')) {
                $existing = DB::table('report_template_kelas')
                    ->where('report_template_id', $sourceTemplate->id)
                    ->where('kelas_id', $classId)
                    ->first();

                DB::table('report_template_kelas')->updateOrInsert(
                    [
                        'report_template_id' => $sourceTemplate->id,
                        'kelas_id' => $classId,
                    ],
                    [
                        'created_at' => $existing->created_at ?? now(),
                        'updated_at' => now(),
                    ]
                );

                $existing ? $stats['template_links_reused']++ : $stats['template_links_created']++;
            }

            if ($this->hasTemplateForClass($classId, $tahunAjaran, $reportType)) {
                $stats['template_available_classes']++;

                continue;
            }

            $stats['template_unavailable_classes']++;
        }

        if ($stats['template_unavailable_classes'] > 0) {
            $this->warnings[] = "WARNING: {$stats['template_unavailable_classes']} kelas load-test belum memiliki template {$reportType}; warm-up akan skip unavailable untuk kelas itu.";
        }
    }

    private function sourceTemplate(TahunAjaran $tahunAjaran, string $reportType): ?object
    {
        return DB::table('report_templates')
            ->where('type', $reportType)
            ->where('is_active', true)
            ->where('tahun_ajaran_id', $tahunAjaran->id)
            ->where(function ($query) use ($tahunAjaran) {
                $query->whereNull('semester')
                    ->orWhere('semester', (int) $tahunAjaran->semester);
            })
            ->whereNotNull('path')
            ->orderBy('id')
            ->first();
    }

    private function hasTemplateForClass(int $classId, TahunAjaran $tahunAjaran, string $reportType): bool
    {
        $base = fn () => DB::table('report_templates')
            ->where('type', $reportType)
            ->where('is_active', true)
            ->where('tahun_ajaran_id', $tahunAjaran->id)
            ->where(function ($query) use ($tahunAjaran) {
                $query->whereNull('semester')
                    ->orWhere('semester', (int) $tahunAjaran->semester);
            });

        if (Schema::hasTable('report_template_kelas')) {
            if ($base()
                ->join('report_template_kelas', 'report_templates.id', '=', 'report_template_kelas.report_template_id')
                ->where('report_template_kelas.kelas_id', $classId)
                ->exists()) {
                return true;
            }
        }

        if (Schema::hasColumn('report_templates', 'kelas_id') && $base()->where('kelas_id', $classId)->exists()) {
            return true;
        }

        $globalQuery = $base();

        if (Schema::hasColumn('report_templates', 'kelas_id')) {
            $globalQuery->whereNull('kelas_id');
        }

        if (Schema::hasTable('report_template_kelas')) {
            $globalQuery->whereNotExists(function ($query) {
                $query->selectRaw('1')
                    ->from('report_template_kelas')
                    ->whereColumn('report_template_kelas.report_template_id', 'report_templates.id');
            });
        }

        return $globalQuery->exists();
    }

    private function scoreBetween(int $min, int $max, int ...$parts): int
    {
        $seed = 0;

        foreach ($parts as $index => $part) {
            $seed += $part * (($index + 2) * 7);
        }

        return $min + ($seed % ($max - $min + 1));
    }

    /**
     * @return array<int, array{name: string, muatan_lokal: bool}>
     */
    private function subjectTemplates(): array
    {
        return [
            ['name' => 'Load Test Pendidikan Agama Islam', 'muatan_lokal' => false],
            ['name' => 'Load Test Pendidikan Pancasila', 'muatan_lokal' => false],
            ['name' => 'Load Test Bahasa Indonesia', 'muatan_lokal' => false],
            ['name' => 'Load Test Matematika', 'muatan_lokal' => false],
            ['name' => 'Load Test IPAS', 'muatan_lokal' => false],
            ['name' => 'Load Test PJOK', 'muatan_lokal' => false],
            ['name' => 'Load Test Seni Musik', 'muatan_lokal' => false],
            ['name' => 'Load Test Bahasa Inggris', 'muatan_lokal' => false],
            ['name' => 'Load Test Bahasa Arab', 'muatan_lokal' => true],
        ];
    }

    private function waliName(int $sequence): string
    {
        return sprintf('%s %02d', self::WALI_PREFIX, $sequence);
    }

    private function className(int $sequence): string
    {
        return sprintf('%s %02d', self::CLASS_PREFIX, $sequence);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function filterPayload(string $table, array $payload): array
    {
        $columns = $this->columns[$table] ??= Schema::getColumnListing($table);

        return collect($payload)
            ->only($columns)
            ->all();
    }

    /**
     * @param array<string, mixed> $criteria
     */
    private function firstByCriteria(string $table, array $criteria): ?object
    {
        $query = DB::table($table);

        foreach ($criteria as $column => $value) {
            $value === null
                ? $query->whereNull($column)
                : $query->where($column, $value);
        }

        return $query->first();
    }

    private function assertDummyText(?string $value, string $message): void
    {
        if (! $this->isDummyText($value)) {
            throw new RuntimeException($message);
        }
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

        return str_contains($value, 'load test');
    }
}

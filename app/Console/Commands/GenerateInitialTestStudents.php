<?php

namespace App\Console\Commands;

use App\Models\Kelas;
use App\Models\TahunAjaran;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

class GenerateInitialTestStudents extends Command
{
    private const NIS_PREFIX = '70';

    private const NISN_PREFIX = '8';

    protected $signature = 'initial-data:generate-test-students
        {--per-class=20 : Number of generated test students per active-year class}
        {--force : Allow running outside local/testing/demo environments}';

    protected $description = 'Generate deterministic test students and semester enrollment rows for active-year classes';

    public function handle(): int
    {
        if (! app()->environment(['local', 'testing', 'demo']) && ! $this->option('force')) {
            $this->error('Generator ini hanya boleh dijalankan di environment local, testing, atau demo kecuali menggunakan --force.');

            return self::FAILURE;
        }

        $perClass = (int) $this->option('per-class');

        if ($perClass < 1) {
            $this->error('Jumlah siswa per kelas harus minimal 1.');

            return self::FAILURE;
        }

        if ($perClass > 999) {
            $this->error('Jumlah siswa per kelas maksimal 999 agar NIS/NISN generated tetap unik dan stabil.');

            return self::FAILURE;
        }

        $tahunAjaran = TahunAjaran::where('is_active', true)->first();

        if (! $tahunAjaran) {
            $this->error('Tidak ada tahun ajaran aktif. Buat tahun ajaran aktif terlebih dahulu.');

            return self::FAILURE;
        }

        $classes = Kelas::query()
            ->where('tahun_ajaran_id', $tahunAjaran->id)
            ->orderBy('nomor_kelas')
            ->orderBy('nama_kelas')
            ->get();

        if ($classes->isEmpty()) {
            $this->error('Tidak ada kelas pada tahun ajaran aktif. Import atau buat kelas terlebih dahulu.');

            return self::FAILURE;
        }

        $stats = [
            'classes_processed' => 0,
            'students_created' => 0,
            'students_reused' => 0,
            'enrollments_created' => 0,
            'enrollments_reused' => 0,
        ];

        try {
            DB::transaction(function () use ($classes, $tahunAjaran, $perClass, &$stats) {
                foreach ($classes as $class) {
                    $stats['classes_processed']++;

                    for ($sequence = 1; $sequence <= $perClass; $sequence++) {
                        $studentId = $this->createOrReuseStudent($tahunAjaran, $class, $sequence, $stats);
                        $this->createOrReuseEnrollment($tahunAjaran, $class, $studentId, $stats);
                    }
                }
            });
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info("Siswa uji selesai disiapkan untuk {$tahunAjaran->tahun_ajaran} semester {$tahunAjaran->semester}.");
        $this->table(
            ['Metric', 'Count'],
            [
                ['Classes processed', $stats['classes_processed']],
                ['Students created', $stats['students_created']],
                ['Students reused', $stats['students_reused']],
                ['Enrollments created', $stats['enrollments_created']],
                ['Enrollments reused', $stats['enrollments_reused']],
            ]
        );
        $this->line('Data nilai, absensi, catatan, capaian, ekstrakurikuler, dan rapor tidak dibuat.');

        return self::SUCCESS;
    }

    /**
     * @param  array<string, int>  $stats
     */
    private function createOrReuseStudent(TahunAjaran $tahunAjaran, Kelas $class, int $sequence, array &$stats): int
    {
        $identity = $this->studentIdentity($tahunAjaran, $class, $sequence);
        $existing = $this->existingStudent($identity['nis'], $identity['nisn']);

        if ($existing && ! $this->isGeneratedStudent($existing)) {
            throw new RuntimeException(
                "NIS/NISN generated {$identity['nis']} / {$identity['nisn']} sudah digunakan oleh siswa lain. Generator dihentikan tanpa membuat data parsial."
            );
        }

        $payload = $this->studentPayload($tahunAjaran, $class, $sequence, $identity);
        $now = now();

        if ($existing) {
            $payload['updated_at'] = $now;
            DB::table('siswas')->where('id', $existing->id)->update($payload);
            $stats['students_reused']++;

            return (int) $existing->id;
        }

        $payload['created_at'] = $now;
        $payload['updated_at'] = $now;

        $stats['students_created']++;

        return (int) DB::table('siswas')->insertGetId($payload);
    }

    /**
     * @param  array<string, int>  $stats
     */
    private function createOrReuseEnrollment(TahunAjaran $tahunAjaran, Kelas $class, int $studentId, array &$stats): void
    {
        $existing = DB::table('siswa_kelas_semester')
            ->where('siswa_id', $studentId)
            ->where('tahun_ajaran_id', $tahunAjaran->id)
            ->where('semester', $tahunAjaran->semester)
            ->first();

        if ($existing) {
            if ((int) $existing->kelas_id !== (int) $class->id) {
                throw new RuntimeException(
                    "Enrollment siswa #{$studentId} untuk tahun ajaran aktif sudah menunjuk kelas lain. Generator dihentikan tanpa membuat data parsial."
                );
            }

            $stats['enrollments_reused']++;

            return;
        }

        DB::table('siswa_kelas_semester')->insert([
            'siswa_id' => $studentId,
            'kelas_id' => $class->id,
            'tahun_ajaran_id' => $tahunAjaran->id,
            'semester' => $tahunAjaran->semester,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $stats['enrollments_created']++;
    }

    /**
     * @return array{nis: string, nisn: string, name: string}
     */
    private function studentIdentity(TahunAjaran $tahunAjaran, Kelas $class, int $sequence): array
    {
        [$startYear, $endYear] = $this->academicYearParts($tahunAjaran->tahun_ajaran);
        $classId = (int) $class->id % 10000;

        return [
            'nis' => sprintf('%s%02d%02d%04d%03d', self::NIS_PREFIX, $startYear % 100, $endYear % 100, $classId, $sequence),
            'nisn' => sprintf('%s%02d%04d%03d', self::NISN_PREFIX, $endYear % 100, $classId, $sequence),
            'name' => sprintf(
                'Siswa %s %s %02d',
                $class->nomor_kelas,
                trim((string) $class->nama_kelas),
                $sequence
            ),
        ];
    }

    /**
     * @param  array{nis: string, nisn: string, name: string}  $identity
     * @return array<string, mixed>
     */
    private function studentPayload(TahunAjaran $tahunAjaran, Kelas $class, int $sequence, array $identity): array
    {
        [$startYear] = $this->academicYearParts($tahunAjaran->tahun_ajaran);
        $grade = max(1, (int) $class->nomor_kelas);
        $birthYear = $startYear - (6 + min($grade, 6));
        $birthMonth = (($sequence - 1) % 12) + 1;
        $birthDay = (($sequence - 1) % 28) + 1;

        $payload = [
            'nis' => $identity['nis'],
            'nisn' => $identity['nisn'],
            'nama' => $identity['name'],
            'tanggal_lahir' => sprintf('%04d-%02d-%02d', $birthYear, $birthMonth, $birthDay),
            'jenis_kelamin' => $sequence % 2 === 0 ? 'Perempuan' : 'Laki-laki',
            'agama' => 'Islam',
            'alamat' => 'Data uji awal - bukan data siswa asli',
            'kelas_id' => $class->id,
            'nama_ayah' => 'Ayah Data Uji',
            'nama_ibu' => 'Ibu Data Uji',
            'pekerjaan_ayah' => 'Data Uji',
            'pekerjaan_ibu' => 'Data Uji',
            'wali_siswa' => null,
            'pekerjaan_wali' => null,
            'alamat_orangtua' => 'Data uji awal - bukan alamat asli',
            'photo' => null,
            'tahun_ajaran_id' => $tahunAjaran->id,
            'status' => 'aktif',
            'is_naik_kelas' => null,
            'kelas_tujuan_id' => null,
        ];

        return collect($payload)
            ->only(Schema::getColumnListing('siswas'))
            ->all();
    }

    /**
     * @return array{0: int, 1: int}
     */
    private function academicYearParts(string $academicYear): array
    {
        if (preg_match('/(\d{4})\D+(\d{4})/', $academicYear, $matches)) {
            return [(int) $matches[1], (int) $matches[2]];
        }

        $year = (int) now()->format('Y');

        return [$year, $year + 1];
    }

    private function existingStudent(string $nis, string $nisn): ?object
    {
        $byNis = DB::table('siswas')->where('nis', $nis)->first();
        $byNisn = DB::table('siswas')->where('nisn', $nisn)->first();

        if ($byNis && $byNisn && (int) $byNis->id !== (int) $byNisn->id) {
            throw new RuntimeException(
                "Generated NIS {$nis} dan NISN {$nisn} sudah digunakan oleh dua siswa berbeda. Generator dihentikan tanpa membuat data parsial."
            );
        }

        return $byNis ?: $byNisn;
    }

    private function isGeneratedStudent(object $student): bool
    {
        $address = property_exists($student, 'alamat') ? (string) $student->alamat : '';
        $name = property_exists($student, 'nama') ? (string) $student->nama : '';

        return str_starts_with((string) $student->nis, self::NIS_PREFIX)
            && str_starts_with((string) $student->nisn, self::NISN_PREFIX)
            && ! str_starts_with((string) $student->nis, 'S2-')
            && ! str_starts_with((string) $student->nisn, 'S2-')
            && (str_starts_with($name, 'Siswa ') || str_contains($address, 'Data uji awal'));
    }
}

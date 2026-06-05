<?php

namespace App\Services;

use App\Models\Kelas;
use App\Models\Siswa;
use App\Models\SiswaKelasSemester;
use App\Models\TahunAjaran;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use RuntimeException;

class SiswaKelasSemesterResolver
{
    public function resolveEnrollment(int|Siswa $siswa, int $tahunAjaranId, int $semester): ?SiswaKelasSemester
    {
        $siswaId = $siswa instanceof Siswa ? $siswa->id : $siswa;

        $enrollments = SiswaKelasSemester::with(['kelas', 'tahunAjaran'])
            ->where('siswa_id', $siswaId)
            ->where('tahun_ajaran_id', $tahunAjaranId)
            ->where('semester', $semester)
            ->get();

        if ($enrollments->count() > 1) {
            throw new RuntimeException(
                "Ambiguous semester enrollment for siswa_id={$siswaId}, tahun_ajaran_id={$tahunAjaranId}, semester={$semester}."
            );
        }

        return $enrollments->first();
    }

    /**
     * @return array{source: string, enrollment: ?SiswaKelasSemester, kelas: ?Kelas}
     */
    public function resolveClassContext(
        int|Siswa $siswa,
        int $tahunAjaranId,
        int $semester,
        bool $allowLegacyFallback = true
    ): array {
        $enrollment = $this->resolveEnrollment($siswa, $tahunAjaranId, $semester);

        if ($enrollment) {
            return [
                'source' => 'enrollment',
                'enrollment' => $enrollment,
                'kelas' => $enrollment->kelas,
            ];
        }

        if ($allowLegacyFallback) {
            $legacyClass = $this->resolveLegacyClass($siswa, $tahunAjaranId, $semester);

            if ($legacyClass) {
                return [
                    'source' => 'legacy_kelas_id',
                    'enrollment' => null,
                    'kelas' => $legacyClass,
                ];
            }
        }

        return [
            'source' => 'missing',
            'enrollment' => null,
            'kelas' => null,
        ];
    }

    public function resolveClass(
        int|Siswa $siswa,
        int $tahunAjaranId,
        int $semester,
        bool $allowLegacyFallback = true
    ): ?Kelas {
        return $this->resolveClassContext($siswa, $tahunAjaranId, $semester, $allowLegacyFallback)['kelas'];
    }

    public function resolveClassOrFail(
        int|Siswa $siswa,
        int $tahunAjaranId,
        int $semester,
        bool $allowLegacyFallback = true
    ): Kelas {
        $context = $this->resolveClassContext($siswa, $tahunAjaranId, $semester, $allowLegacyFallback);

        if (! $context['kelas']) {
            $siswaId = $siswa instanceof Siswa ? $siswa->id : $siswa;

            throw new RuntimeException(
                "No class context for siswa_id={$siswaId}, tahun_ajaran_id={$tahunAjaranId}, semester={$semester}."
            );
        }

        return $context['kelas'];
    }

    public function isEnrolledInClass(
        int|Siswa $siswa,
        int $kelasId,
        int $tahunAjaranId,
        int $semester,
        bool $allowLegacyFallback = true
    ): bool {
        $kelas = $this->resolveClass($siswa, $tahunAjaranId, $semester, $allowLegacyFallback);

        return $kelas && (int) $kelas->id === (int) $kelasId;
    }

    /**
     * @return EloquentCollection<int, Siswa>
     */
    public function studentsEnrolledInClass(int $kelasId, int $tahunAjaranId, int $semester): EloquentCollection
    {
        return Siswa::query()
            ->whereHas('semesterEnrollments', function ($query) use ($kelasId, $tahunAjaranId, $semester) {
                $query->where('kelas_id', $kelasId)
                    ->where('tahun_ajaran_id', $tahunAjaranId)
                    ->where('semester', $semester);
            })
            ->orderBy('nama')
            ->get();
    }

    /**
     * @return EloquentCollection<int, Siswa>
     */
    public function studentsForClass(
        int $kelasId,
        int $tahunAjaranId,
        int $semester,
        bool $includeLegacyFallback = false
    ): EloquentCollection {
        $query = Siswa::query()
            ->whereHas('semesterEnrollments', function ($query) use ($kelasId, $tahunAjaranId, $semester) {
                $query->where('kelas_id', $kelasId)
                    ->where('tahun_ajaran_id', $tahunAjaranId)
                    ->where('semester', $semester);
            });

        if ($includeLegacyFallback && $this->classMatchesContext($kelasId, $tahunAjaranId, $semester)) {
            $query->orWhere(function ($query) use ($kelasId, $tahunAjaranId, $semester) {
                $query->where('kelas_id', $kelasId)
                    ->whereDoesntHave('semesterEnrollments', function ($query) use ($tahunAjaranId, $semester) {
                        $query->where('tahun_ajaran_id', $tahunAjaranId)
                            ->where('semester', $semester);
                    });
            });
        }

        return $query->orderBy('nama')->get();
    }

    private function resolveLegacyClass(int|Siswa $siswa, int $tahunAjaranId, int $semester): ?Kelas
    {
        $student = $siswa instanceof Siswa ? $siswa : Siswa::find($siswa);

        if (! $student || ! $student->kelas_id) {
            return null;
        }

        $kelas = $student->relationLoaded('kelas')
            ? $student->kelas
            : Kelas::with('tahunAjaran')->find($student->kelas_id);

        if (! $kelas || ! $this->classMatchesContext($kelas->id, $tahunAjaranId, $semester, $kelas)) {
            return null;
        }

        return $kelas;
    }

    private function classMatchesContext(
        int $kelasId,
        int $tahunAjaranId,
        int $semester,
        ?Kelas $kelas = null
    ): bool {
        $kelas = $kelas ?: Kelas::with('tahunAjaran')->find($kelasId);
        $tahunAjaran = $kelas?->tahunAjaran ?: TahunAjaran::find($tahunAjaranId);

        return $kelas
            && (int) $kelas->tahun_ajaran_id === (int) $tahunAjaranId
            && $tahunAjaran
            && (int) $tahunAjaran->semester === (int) $semester;
    }
}

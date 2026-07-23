<?php

namespace App\Services;

use App\Models\Kelas;
use App\Models\Siswa;
use App\Models\SiswaKelasSemester;
use App\Models\TahunAjaran;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class SiswaKelasSemesterResolver
{
    /**
     * Scoped-instance memoization only. The container binding is scoped to the
     * current request/job lifecycle, and these arrays are never persisted.
     *
     * @var array<string, ?SiswaKelasSemester>
     */
    private array $enrollmentMemo = [];

    /**
     * @var array<string, bool>
     */
    private array $classContextMemo = [];

    /**
     * @var array<string, array<int, int>>
     */
    private array $rosterIdMemo = [];

    /**
     * @var array<string, bool>
     */
    private array $legacyFallbackLogged = [];

    /**
     * @var array<string, int>
     */
    private array $diagnostics = [
        'enrollment_queries' => 0,
        'class_context_queries' => 0,
        'roster_id_queries' => 0,
        'legacy_fallback_logs' => 0,
    ];

    public function resolveEnrollment(int|Siswa $siswa, int $tahunAjaranId, int $semester): ?SiswaKelasSemester
    {
        $siswaId = $siswa instanceof Siswa ? $siswa->id : $siswa;
        $memoKey = $this->enrollmentMemoKey((int) $siswaId, $tahunAjaranId, $semester);

        if (! array_key_exists($memoKey, $this->enrollmentMemo)) {
            $this->diagnostics['enrollment_queries']++;

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

            $this->enrollmentMemo[$memoKey] = $enrollments->first();
        }

        return $this->enrollmentMemo[$memoKey] instanceof SiswaKelasSemester
            ? $this->cloneEnrollmentForCaller($this->enrollmentMemo[$memoKey])
            : null;
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
                $this->logLegacyClassFallbackOnce((int) $legacyClass->id, $tahunAjaranId, $semester);

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
        return $this->queryFromRosterIds($kelasId, $tahunAjaranId, $semester, $includeLegacyFallback)
            ->orderBy('nama')
            ->get();
    }

    public function studentQueryForClass(
        int $kelasId,
        int $tahunAjaranId,
        int $semester,
        bool $includeLegacyFallback = false
    ): Builder {
        return $this->queryFromRosterIds($kelasId, $tahunAjaranId, $semester, $includeLegacyFallback);
    }

    public function resetMemoization(): void
    {
        $this->enrollmentMemo = [];
        $this->classContextMemo = [];
        $this->rosterIdMemo = [];
        $this->legacyFallbackLogged = [];
        $this->diagnostics = [
            'enrollment_queries' => 0,
            'class_context_queries' => 0,
            'roster_id_queries' => 0,
            'legacy_fallback_logs' => 0,
        ];
    }

    public function invalidateEnrollment(int $siswaId, int $tahunAjaranId, int $semester): void
    {
        unset($this->enrollmentMemo[$this->enrollmentMemoKey($siswaId, $tahunAjaranId, $semester)]);
    }

    public function invalidateClassRoster(int $kelasId, int $tahunAjaranId, int $semester): void
    {
        foreach ([false, true] as $includeLegacyFallback) {
            unset($this->rosterIdMemo[$this->rosterMemoKey($kelasId, $tahunAjaranId, $semester, $includeLegacyFallback, 'ids')]);
        }

        unset($this->classContextMemo[$this->classContextMemoKey($kelasId, $tahunAjaranId, $semester)]);
        unset($this->legacyFallbackLogged[$this->legacyFallbackLogKey($kelasId, $tahunAjaranId, $semester)]);
    }

    /**
     * @return array<string, int>
     */
    public function diagnostics(): array
    {
        return app()->runningUnitTests() ? $this->diagnostics : [];
    }

    private function queryFromRosterIds(
        int $kelasId,
        int $tahunAjaranId,
        int $semester,
        bool $includeLegacyFallback = false
    ): Builder {
        $ids = $this->studentIdsForClassContext($kelasId, $tahunAjaranId, $semester, $includeLegacyFallback);
        $query = Siswa::query();

        return $ids === []
            ? $query->whereRaw('1 = 0')
            : $query->whereKey($ids);
    }

    /**
     * @return array<int, int>
     */
    private function studentIdsForClassContext(
        int $kelasId,
        int $tahunAjaranId,
        int $semester,
        bool $includeLegacyFallback = false
    ): array {
        $memoKey = $this->rosterMemoKey($kelasId, $tahunAjaranId, $semester, $includeLegacyFallback, 'ids');

        if (array_key_exists($memoKey, $this->rosterIdMemo)) {
            return $this->rosterIdMemo[$memoKey];
        }

        $canUseLegacyFallback = $includeLegacyFallback
            && $this->classMatchesContext($kelasId, $tahunAjaranId, $semester);

        $this->diagnostics['roster_id_queries']++;

        $ids = Siswa::query()
            ->where(function ($query) use ($kelasId, $tahunAjaranId, $semester, $canUseLegacyFallback) {
                $query->whereHas('semesterEnrollments', function ($query) use ($kelasId, $tahunAjaranId, $semester) {
                    $query->where('kelas_id', $kelasId)
                        ->where('tahun_ajaran_id', $tahunAjaranId)
                        ->where('semester', $semester);
                });

                if ($canUseLegacyFallback) {
                    $query->orWhere(function ($query) use ($kelasId) {
                        $query->where('kelas_id', $kelasId)
                            ->whereDoesntHave('semesterEnrollments');
                    });
                }
            })
            ->orderBy('nama')
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        if ($canUseLegacyFallback) {
            $this->logLegacyRosterFallbackIfUsed($kelasId, $tahunAjaranId, $semester, $ids);
        }

        return $this->rosterIdMemo[$memoKey] = $ids;
    }

    private function resolveLegacyClass(int|Siswa $siswa, int $tahunAjaranId, int $semester): ?Kelas
    {
        $student = $siswa instanceof Siswa ? $siswa : Siswa::find($siswa);

        if (! $student || ! $student->kelas_id) {
            return null;
        }

        if ($student->semesterEnrollments()->exists()) {
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
        if (! $kelas) {
            $memoKey = $this->classContextMemoKey($kelasId, $tahunAjaranId, $semester);

            if (array_key_exists($memoKey, $this->classContextMemo)) {
                return $this->classContextMemo[$memoKey];
            }
        }

        $this->diagnostics['class_context_queries']++;

        $kelas = $kelas ?: Kelas::with('tahunAjaran')->find($kelasId);
        $tahunAjaran = $kelas?->tahunAjaran ?: TahunAjaran::find($tahunAjaranId);

        $matches = $kelas
            && (int) $kelas->tahun_ajaran_id === (int) $tahunAjaranId
            && $tahunAjaran
            && (int) $tahunAjaran->semester === (int) $semester;

        if (! isset($memoKey)) {
            return $matches;
        }

        return $this->classContextMemo[$memoKey] = $matches;
    }

    private function logLegacyRosterFallbackIfUsed(int $kelasId, int $tahunAjaranId, int $semester, array $studentIds): void
    {
        if (! config('logging.diagnostics.log_roster_fallback') || $studentIds === []) {
            return;
        }

        $hasLegacyRows = Siswa::query()
            ->whereIn('id', $studentIds)
            ->where('kelas_id', $kelasId)
            ->whereDoesntHave('semesterEnrollments')
            ->exists();

        if ($hasLegacyRows) {
            $this->logLegacyClassFallbackOnce($kelasId, $tahunAjaranId, $semester);
        }
    }

    private function logLegacyClassFallbackOnce(
        int $kelasId,
        int $tahunAjaranId,
        int $semester
    ): void {
        if (! config('logging.diagnostics.log_roster_fallback')) {
            return;
        }

        $logKey = $this->legacyFallbackLogKey($kelasId, $tahunAjaranId, $semester);

        if (isset($this->legacyFallbackLogged[$logKey])) {
            return;
        }

        $this->legacyFallbackLogged[$logKey] = true;
        $this->diagnostics['legacy_fallback_logs']++;

        Log::debug('Student roster legacy siswa.kelas_id fallback used', [
            'kelas_id' => $kelasId,
            'tahun_ajaran_id' => $tahunAjaranId,
            'semester' => $semester,
        ]);
    }

    private function cloneEnrollmentForCaller(SiswaKelasSemester $enrollment): SiswaKelasSemester
    {
        $clone = clone $enrollment;

        foreach ($enrollment->getRelations() as $name => $relation) {
            $clone->setRelation($name, $relation instanceof Model ? clone $relation : $relation);
        }

        return $clone;
    }

    private function enrollmentMemoKey(int $siswaId, int $tahunAjaranId, int $semester): string
    {
        return "enrollment:{$siswaId}:{$tahunAjaranId}:{$semester}";
    }

    private function classContextMemoKey(int $kelasId, int $tahunAjaranId, int $semester): string
    {
        return "class-context:{$kelasId}:{$tahunAjaranId}:{$semester}";
    }

    private function rosterMemoKey(
        int $kelasId,
        int $tahunAjaranId,
        int $semester,
        bool $includeLegacyFallback,
        string $mode
    ): string {
        return implode(':', [
            'roster',
            $mode,
            $kelasId,
            $tahunAjaranId,
            $semester,
            $includeLegacyFallback ? 'with-legacy' : 'enrollment-only',
        ]);
    }

    private function legacyFallbackLogKey(int $kelasId, int $tahunAjaranId, int $semester): string
    {
        return "legacy-fallback:{$kelasId}:{$tahunAjaranId}:{$semester}";
    }
}

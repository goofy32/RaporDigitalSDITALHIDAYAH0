<?php

namespace App\Observers;

use App\Models\Siswa;
use App\Models\SiswaKelasSemester;
use App\Services\PdfCacheService;
use App\Services\SiswaKelasSemesterResolver;

class ReportContextCacheInvalidationObserver
{
    public function saved(SiswaKelasSemester $enrollment): void
    {
        if (! $enrollment->wasRecentlyCreated
            && ! $enrollment->wasChanged(['siswa_id', 'kelas_id', 'tahun_ajaran_id', 'semester'])) {
            return;
        }

        $this->invalidate((int) $enrollment->siswa_id, (int) $enrollment->tahun_ajaran_id, (int) $enrollment->semester);

        $oldSiswaId = (int) $enrollment->getOriginal('siswa_id');
        $oldTahunAjaranId = (int) $enrollment->getOriginal('tahun_ajaran_id');
        $oldSemester = (int) $enrollment->getOriginal('semester');

        if ($oldSiswaId && $oldTahunAjaranId
            && ($oldSiswaId !== (int) $enrollment->siswa_id
                || $oldTahunAjaranId !== (int) $enrollment->tahun_ajaran_id
                || $oldSemester !== (int) $enrollment->semester)) {
            $this->invalidate($oldSiswaId, $oldTahunAjaranId, $oldSemester);
        }
    }

    public function deleted(SiswaKelasSemester $enrollment): void
    {
        $this->invalidate(
            (int) $enrollment->siswa_id,
            (int) $enrollment->tahun_ajaran_id,
            (int) $enrollment->semester
        );
    }

    private function invalidate(int $siswaId, int $tahunAjaranId, int $semester): void
    {
        if (! $siswaId || ! $tahunAjaranId) {
            return;
        }

        app(SiswaKelasSemesterResolver::class)
            ->invalidateEnrollment($siswaId, $tahunAjaranId, $semester);

        $siswa = Siswa::find($siswaId);
        if ($siswa) {
            PdfCacheService::clearStudentCache($siswa, $tahunAjaranId, false, null, $semester);
        }
    }
}

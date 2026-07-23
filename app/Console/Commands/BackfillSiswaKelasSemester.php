<?php

namespace App\Console\Commands;

use App\Models\Siswa;
use App\Models\SiswaKelasSemester;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

class BackfillSiswaKelasSemester extends Command
{
    protected $signature = 'enrollment:backfill
        {--apply : Persist missing enrollment records instead of dry-run only}
        {--force : Allow running outside local/testing/demo environments after confirmation}';

    protected $description = 'Safely backfill siswa_kelas_semester records from existing non-S2 student class data';

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');

        if (! app()->environment(['local', 'testing', 'demo']) && ! $this->option('force')) {
            $this->error('This command may only run in local, testing, or demo environments unless --force is provided.');

            return self::FAILURE;
        }

        if (! $apply) {
            $this->info('Dry run only. Use --apply to create missing enrollment records.');
        }

        if ($apply && ! $this->confirm('Create missing semester enrollment records now?', false)) {
            $this->warn('Backfill aborted before any changes were made.');

            return self::FAILURE;
        }

        $stats = [
            'checked' => 0,
            'eligible' => 0,
            'created' => 0,
            'already_present' => 0,
            'skipped' => 0,
            'ambiguous' => 0,
            'failed' => 0,
        ];

        Siswa::with(['kelas.tahunAjaran'])
            ->orderBy('id')
            ->chunkById(100, function ($students) use (&$stats, $apply) {
                foreach ($students as $student) {
                    $this->processStudent($student, $stats, $apply);
                }
            });

        $this->table(
            ['Metric', 'Count'],
            [
                ['Records checked', $stats['checked']],
                ['Records eligible', $stats['eligible']],
                ['Records created', $stats['created']],
                ['Records already present', $stats['already_present']],
                ['Records skipped', $stats['skipped']],
                ['Records ambiguous', $stats['ambiguous']],
                ['Records failed', $stats['failed']],
            ]
        );

        return $stats['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @param  array<string, int>  $stats
     */
    private function processStudent(Siswa $student, array &$stats, bool $apply): void
    {
        $stats['checked']++;

        if ($this->isS2Duplicate($student)) {
            $stats['skipped']++;

            return;
        }

        $kelas = $student->kelas;
        $tahunAjaran = $kelas?->tahunAjaran;

        if (! $kelas || ! $tahunAjaran || ! in_array((int) $tahunAjaran->semester, [1, 2], true)) {
            $stats['skipped']++;

            return;
        }

        $existing = SiswaKelasSemester::query()
            ->where('siswa_id', $student->id)
            ->where('tahun_ajaran_id', $tahunAjaran->id)
            ->where('semester', $tahunAjaran->semester)
            ->get();

        if ($existing->count() > 1) {
            $stats['ambiguous']++;

            return;
        }

        if ($existing->count() === 1) {
            if ((int) $existing->first()->kelas_id === (int) $kelas->id) {
                $stats['already_present']++;
            } else {
                $stats['ambiguous']++;
            }

            return;
        }

        $stats['eligible']++;

        if (! $apply) {
            return;
        }

        try {
            DB::transaction(function () use ($student, $kelas, $tahunAjaran) {
                SiswaKelasSemester::create([
                    'siswa_id' => $student->id,
                    'kelas_id' => $kelas->id,
                    'tahun_ajaran_id' => $tahunAjaran->id,
                    'semester' => $tahunAjaran->semester,
                ]);
            });

            $stats['created']++;
        } catch (Throwable) {
            $stats['failed']++;
        }
    }

    private function isS2Duplicate(Siswa $student): bool
    {
        return str_starts_with((string) $student->nis, 'S2-')
            || str_starts_with((string) $student->nisn, 'S2-');
    }
}

<?php

namespace App\Console\Commands;

use App\Models\TahunAjaran;
use App\Services\InitialGuruStructureImportService;
use Illuminate\Console\Command;
use RuntimeException;

class ImportInitialGuruStructure extends Command
{
    protected $signature = 'initial-data:import-guru-structure
        {--file= : Path to the guru structure Excel workbook}
        {--force : Allow running outside local/testing/demo environments}';

    protected $description = 'Import initial guru, class, subject, wali, and teaching assignments from the SDIT guru workbook';

    public function handle(InitialGuruStructureImportService $importer): int
    {
        if (! app()->environment(['local', 'testing', 'demo']) && ! $this->option('force')) {
            $this->error('Import ini hanya boleh dijalankan di environment local, testing, atau demo kecuali menggunakan --force.');

            return self::FAILURE;
        }

        $tahunAjaran = TahunAjaran::where('is_active', true)->first();

        if (! $tahunAjaran) {
            $this->error('Tidak ada tahun ajaran aktif. Buat tahun ajaran aktif terlebih dahulu.');

            return self::FAILURE;
        }

        $password = $this->temporaryPassword();

        if ($password === '') {
            $this->error('INITIAL_GURU_PASSWORD belum diatur. Set environment variable ini sebelum menjalankan import.');

            return self::FAILURE;
        }

        $filePath = $this->resolveFilePath();

        if (! $filePath) {
            $this->error('File import guru tidak ditemukan. Gunakan opsi --file=path\\to\\file.xlsx.');

            return self::FAILURE;
        }

        try {
            $stats = $importer->import($filePath, $tahunAjaran, $password);
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info("Import selesai untuk tahun ajaran aktif {$tahunAjaran->tahun_ajaran} semester {$tahunAjaran->semester}.");
        $this->table(
            ['Data', 'Created', 'Updated/Reused'],
            [
                ['Classes', $stats['classes_created'], $stats['classes_updated']],
                ['Gurus', $stats['gurus_created'], $stats['gurus_updated']],
                ['Wali assignments', $stats['wali_assignments_created'], $stats['wali_assignments_updated']],
                ['Teacher class assignments', $stats['teacher_class_assignments_created'], $stats['teacher_class_assignments_updated']],
                ['Subjects', $stats['subjects_created'], $stats['subjects_updated']],
            ]
        );
        $this->line("Rows processed: {$stats['rows_processed']}");
        $this->line("Rows skipped: {$stats['rows_skipped']}");
        $this->line('Password guru tidak ditampilkan di output.');

        return self::SUCCESS;
    }

    private function temporaryPassword(): string
    {
        $password = getenv('INITIAL_GURU_PASSWORD');

        if ($password === false || $password === '') {
            $password = env('INITIAL_GURU_PASSWORD', '');
        }

        return trim((string) $password);
    }

    private function resolveFilePath(): ?string
    {
        $provided = $this->option('file');

        $candidates = array_filter([
            $provided ? base_path((string) $provided) : null,
            $provided ? (string) $provided : null,
            storage_path('app/imports/Data Kebutuhan Testing - Guru SDIT Al-Hidayah.xlsx'),
            base_path('app/Imports/Data Kebutuhan Testing - Guru SDIT Al-Hidayah (1).xlsx'),
        ]);

        foreach ($candidates as $candidate) {
            if (is_file($candidate)) {
                return $candidate;
            }
        }

        return null;
    }
}

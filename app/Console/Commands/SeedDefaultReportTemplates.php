<?php

namespace App\Console\Commands;

use App\Models\ReportTemplate;
use App\Models\TahunAjaran;
use App\Services\ReportTemplateDocxValidationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class SeedDefaultReportTemplates extends Command
{
    protected $signature = 'initial-data:seed-default-report-templates
        {--force : Replace existing seeded default template files/records for the active academic period}';

    protected $description = 'Seed bundled default Global UTS and UAS report templates for the active academic year.';

    public function handle(ReportTemplateDocxValidationService $validator): int
    {
        $tahunAjaran = TahunAjaran::query()
            ->where('is_active', true)
            ->orderByDesc('id')
            ->first();

        if (! $tahunAjaran) {
            $this->error('Tidak ada tahun ajaran aktif. Buat atau aktifkan tahun ajaran terlebih dahulu sebelum memasang template rapor default.');

            return self::FAILURE;
        }

        $semester = (int) ($tahunAjaran->semester ?: 1);
        $force = (bool) $this->option('force');

        $this->info("Tahun ajaran aktif: {$tahunAjaran->tahun_ajaran} | Semester: " . ($semester === 1 ? 'Ganjil' : 'Genap'));

        $results = [];

        foreach (['UTS', 'UAS'] as $type) {
            try {
                $result = $this->seedTemplateForType($type, $tahunAjaran, $semester, $force, $validator);
            } catch (\Throwable $e) {
                $this->error("{$type}: {$e->getMessage()}");

                return self::FAILURE;
            }

            $results[$type] = $result;

            match ($result['status']) {
                'created' => $this->info("{$type}: created ({$result['path']})"),
                'replaced' => $this->info("{$type}: replaced with --force ({$result['path']})"),
                'exists' => $this->line("{$type}: already exists ({$result['path']})"),
                'skipped' => $this->warn("{$type}: skipped - {$result['message']}"),
                default => $this->line("{$type}: {$result['status']}"),
            };
        }

        $this->newLine();
        $this->table(
            ['Jenis', 'Status', 'Path'],
            collect($results)->map(fn ($result, $type) => [
                $type,
                $result['status'],
                $result['path'] ?? '-',
            ])->values()->all()
        );

        return self::SUCCESS;
    }

    private function seedTemplateForType(
        string $type,
        TahunAjaran $tahunAjaran,
        int $semester,
        bool $force,
        ReportTemplateDocxValidationService $validator
    ): array {
        $sourcePath = $this->sourcePathFor($type);

        if (! is_file($sourcePath)) {
            throw new \RuntimeException("File template default {$type} tidak ditemukan: {$sourcePath}");
        }

        if ($validationMessage = $validator->validateTypeFromDocxText($sourcePath, $type)) {
            throw new \RuntimeException($validationMessage);
        }

        return DB::transaction(function () use ($type, $tahunAjaran, $semester, $force, $sourcePath) {
            $defaultTemplate = $this->findSeededDefaultTemplate($type, $tahunAjaran, $semester);

            if ($defaultTemplate && ! $force) {
                $this->ensureDefaultFileExists($defaultTemplate, $sourcePath);

                return [
                    'status' => 'exists',
                    'path' => $defaultTemplate->path,
                ];
            }

            if (! $defaultTemplate && $this->globalTemplateExists($type, $tahunAjaran, $semester)) {
                return [
                    'status' => 'skipped',
                    'path' => null,
                    'message' => 'template Global untuk konteks ini sudah ada; template default tidak ditambahkan agar tidak menimpa pilihan sekolah.',
                ];
            }

            $destinationPath = $this->destinationPathFor($type, $tahunAjaran, $semester);
            $this->copyTemplateFile($sourcePath, $destinationPath);

            if ($defaultTemplate) {
                if ($defaultTemplate->path && $defaultTemplate->path !== $destinationPath) {
                    Storage::disk('public')->delete($defaultTemplate->path);
                }

                $defaultTemplate->update([
                    'filename' => $this->filenameFor($type),
                    'path' => $destinationPath,
                    'is_active' => true,
                    'tahun_ajaran' => $tahunAjaran->tahun_ajaran,
                    'tahun_ajaran_text' => $tahunAjaran->tahun_ajaran,
                    'semester' => $semester,
                    'kelas_id' => null,
                    'tahun_ajaran_id' => $tahunAjaran->id,
                ]);

                return [
                    'status' => 'replaced',
                    'path' => $destinationPath,
                ];
            }

            ReportTemplate::create([
                'filename' => $this->filenameFor($type),
                'path' => $destinationPath,
                'type' => $type,
                'kelas_id' => null,
                'is_active' => true,
                'tahun_ajaran' => $tahunAjaran->tahun_ajaran,
                'tahun_ajaran_text' => $tahunAjaran->tahun_ajaran,
                'semester' => $semester,
                'tahun_ajaran_id' => $tahunAjaran->id,
            ]);

            return [
                'status' => 'created',
                'path' => $destinationPath,
            ];
        });
    }

    private function sourcePathFor(string $type): string
    {
        return (string) config("report.default_templates.{$type}");
    }

    private function filenameFor(string $type): string
    {
        return "Template Default {$type}.docx";
    }

    private function destinationPathFor(string $type, TahunAjaran $tahunAjaran, int $semester): string
    {
        $yearSlug = Str::slug($tahunAjaran->tahun_ajaran ?: 'tahun-ajaran');

        return "templates/defaults/template-default-" . Str::lower($type) . "-{$yearSlug}-s{$semester}.docx";
    }

    private function findSeededDefaultTemplate(string $type, TahunAjaran $tahunAjaran, int $semester): ?ReportTemplate
    {
        return ReportTemplate::query()
            ->where('type', $type)
            ->where('tahun_ajaran_id', $tahunAjaran->id)
            ->where('semester', $semester)
            ->whereNull('kelas_id')
            ->where(function ($query) use ($type) {
                $query->where('filename', $this->filenameFor($type))
                    ->orWhere('path', 'like', 'templates/defaults/template-default-' . Str::lower($type) . '-%.docx');
            })
            ->whereDoesntHave('kelasList')
            ->orderByDesc('id')
            ->first();
    }

    private function globalTemplateExists(string $type, TahunAjaran $tahunAjaran, int $semester): bool
    {
        return ReportTemplate::query()
            ->where('type', $type)
            ->where('tahun_ajaran_id', $tahunAjaran->id)
            ->where(function ($query) use ($semester) {
                $query->whereNull('semester')
                    ->orWhere('semester', $semester);
            })
            ->whereNull('kelas_id')
            ->whereDoesntHave('kelasList')
            ->exists();
    }

    private function copyTemplateFile(string $sourcePath, string $destinationPath): void
    {
        Storage::disk('public')->makeDirectory('templates/defaults');

        $contents = file_get_contents($sourcePath);

        if ($contents === false) {
            throw new \RuntimeException("File template default tidak dapat dibaca: {$sourcePath}");
        }

        Storage::disk('public')->put($destinationPath, $contents);
    }

    private function ensureDefaultFileExists(ReportTemplate $template, string $sourcePath): void
    {
        if ($template->path && Storage::disk('public')->exists($template->path)) {
            return;
        }

        $destinationPath = $template->path ?: $this->destinationPathFor($template->type, $template->tahunAjaran, (int) $template->semester);
        $this->copyTemplateFile($sourcePath, $destinationPath);

        if ($template->path !== $destinationPath) {
            $template->update(['path' => $destinationPath]);
        }
    }
}

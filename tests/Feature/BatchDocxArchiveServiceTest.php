<?php

namespace Tests\Feature;

use App\Services\BatchDocxArchiveService;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

class BatchDocxArchiveServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
    }

    public function test_cleanup_removes_only_expired_batch_workspaces(): void
    {
        $expiredWorkspace = $this->createWorkspace(10, now()->subHours(25)->getTimestamp());
        $recentWorkspace = $this->createWorkspace(10, now()->subHours(2)->getTimestamp());
        Storage::disk('local')->put('outside/keep.zip', 'outside');
        touch(Storage::disk('local')->path('outside/keep.zip'), now()->subDays(2)->getTimestamp());

        $this->artisan('reports:cleanup-batch-docx')
            ->assertSuccessful()
            ->expectsOutputToContain('Workspace batch rapor kedaluwarsa yang dihapus: 1.');

        Storage::disk('local')->assertMissing($expiredWorkspace);
        Storage::disk('local')->assertExists($recentWorkspace.'/Rapor_Kelas_4A_UTS.zip');
        Storage::disk('local')->assertExists('outside/keep.zip');
    }

    public function test_cleanup_ignores_non_uuid_directories_inside_batch_prefix(): void
    {
        Storage::disk('local')->put('batch_reports/10/not-a-workspace/keep.zip', 'keep');
        touch(
            Storage::disk('local')->path('batch_reports/10/not-a-workspace/keep.zip'),
            now()->subDays(2)->getTimestamp()
        );

        $deleted = app(BatchDocxArchiveService::class)->cleanupExpired();

        $this->assertSame(0, $deleted);
        Storage::disk('local')->assertExists('batch_reports/10/not-a-workspace/keep.zip');
    }

    public function test_normal_archive_stays_inside_private_root_and_can_be_resolved_and_cleaned(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('docx_reports/cached.docx', 'PK cached DOCX');

        $service = app(BatchDocxArchiveService::class);
        $archive = $service->createArchive(10, 'Kelas 4A', 'UTS', [[
            'student_id' => 42,
            'student_name' => 'Siswa Aman',
            'path' => 'docx_reports/cached.docx',
        ]]);

        $this->assertNotNull($archive);
        $this->assertStringStartsWith('batch_reports/10/', $archive['path']);
        $resolved = $service->resolveOwnedArchivePath($archive['path'], 10);
        $this->assertNotNull($resolved);
        $this->assertSame(
            str_replace('\\', '/', (string) realpath(Storage::disk('local')->path($archive['path']))),
            $resolved
        );

        touch($resolved, now()->subHours(25)->getTimestamp());

        $this->assertSame(1, $service->cleanupExpired());
        Storage::disk('local')->assertMissing(dirname($archive['path']));
    }

    public function test_archive_resolver_rejects_absolute_sibling_malformed_and_backslash_traversal_paths(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('docx_reports/cached.docx', 'PK cached DOCX');
        $service = app(BatchDocxArchiveService::class);
        $archive = $service->createArchive(10, 'Kelas 4A', 'UTS', [[
            'student_id' => 42,
            'student_name' => 'Siswa Aman',
            'path' => 'docx_reports/cached.docx',
        ]]);
        $this->assertNotNull($archive);

        $this->assertNull($service->resolveOwnedArchivePath(Storage::disk('local')->path($archive['path']), 10));
        $this->assertNull($service->resolveOwnedArchivePath(str_replace('/10/', '/11/', $archive['path']), 10));
        $this->assertNull($service->resolveOwnedArchivePath('batch_reports/10/not-a-uuid/Rapor_Kelas_4A_UTS.zip', 10));
        $this->assertNull($service->resolveOwnedArchivePath('batch_reports\\10\\'.Str::uuid().'\\..\\outside.zip', 10));
    }

    public function test_root_symlink_outside_local_disk_is_rejected_without_touching_target(): void
    {
        $outside = $this->outsideDirectory('root');
        $link = Storage::disk('local')->path(BatchDocxArchiveService::ROOT_DIRECTORY);
        File::put($outside.'/keep.txt', 'keep');
        $this->createDirectorySymlinkOrSkip($outside, $link);

        try {
            $exception = null;
            try {
                app(BatchDocxArchiveService::class)->createArchive(10, 'Kelas 4A', 'UTS', [[
                    'student_id' => 42,
                    'student_name' => 'Siswa Aman',
                    'path' => 'docx_reports/missing.docx',
                ]]);
            } catch (RuntimeException $caught) {
                $exception = $caught;
            }

            $this->assertNotNull($exception);
            $this->assertSame(0, app(BatchDocxArchiveService::class)->cleanupExpired());
            $this->assertFileExists($outside.'/keep.txt');
            $this->assertSame(['keep.txt'], array_values(array_diff(scandir($outside) ?: [], ['.', '..'])));
        } finally {
            $this->removeDirectoryLink($link);
            File::deleteDirectory($outside);
        }
    }

    public function test_owner_symlink_outside_batch_root_is_rejected_for_create_resolve_and_cleanup(): void
    {
        Storage::disk('local')->makeDirectory(BatchDocxArchiveService::ROOT_DIRECTORY);
        $outside = $this->outsideDirectory('owner');
        $workspaceId = (string) Str::uuid();
        File::makeDirectory($outside.'/'.$workspaceId, 0777, true);
        File::put($outside.'/'.$workspaceId.'/Rapor_Kelas_4A_UTS.zip', 'ZIP');
        File::put($outside.'/keep.txt', 'keep');
        $link = Storage::disk('local')->path('batch_reports/10');
        $this->createDirectorySymlinkOrSkip($outside, $link);

        try {
            $exception = null;
            try {
                app(BatchDocxArchiveService::class)->createArchive(10, 'Kelas 4A', 'UTS', [[
                    'student_id' => 42,
                    'student_name' => 'Siswa Aman',
                    'path' => 'docx_reports/missing.docx',
                ]]);
            } catch (RuntimeException $caught) {
                $exception = $caught;
            }

            $this->assertNotNull($exception);
            $this->assertNull(app(BatchDocxArchiveService::class)->resolveOwnedArchivePath(
                "batch_reports/10/{$workspaceId}/Rapor_Kelas_4A_UTS.zip",
                10
            ));
            $this->assertSame(0, app(BatchDocxArchiveService::class)->cleanupExpired());
            $this->assertFileExists($outside.'/keep.txt');
            $this->assertFileExists($outside.'/'.$workspaceId.'/Rapor_Kelas_4A_UTS.zip');
        } finally {
            $this->removeDirectoryLink($link);
            File::deleteDirectory($outside);
        }
    }

    public function test_workspace_symlink_is_not_followed_by_cleanup(): void
    {
        Storage::disk('local')->makeDirectory('batch_reports/10');
        $outside = $this->outsideDirectory('workspace');
        File::put($outside.'/Rapor_Kelas_4A_UTS.zip', 'ZIP');
        touch($outside.'/Rapor_Kelas_4A_UTS.zip', now()->subDays(2)->getTimestamp());
        $workspaceId = (string) Str::uuid();
        $link = Storage::disk('local')->path("batch_reports/10/{$workspaceId}");
        $this->createDirectorySymlinkOrSkip($outside, $link);

        try {
            $this->assertSame(0, app(BatchDocxArchiveService::class)->cleanupExpired());
            $this->assertFileExists($outside.'/Rapor_Kelas_4A_UTS.zip');
            $this->assertNull(app(BatchDocxArchiveService::class)->resolveOwnedArchivePath(
                "batch_reports/10/{$workspaceId}/Rapor_Kelas_4A_UTS.zip",
                10
            ));
        } finally {
            $this->removeDirectoryLink($link);
            File::deleteDirectory($outside);
        }
    }

    public function test_zip_symlink_is_rejected_by_resolver_and_cleanup(): void
    {
        $workspaceId = (string) Str::uuid();
        Storage::disk('local')->makeDirectory("batch_reports/10/{$workspaceId}");
        $outside = $this->outsideDirectory('zip');
        File::put($outside.'/keep.zip', 'ZIP');
        touch($outside.'/keep.zip', now()->subDays(2)->getTimestamp());
        $link = Storage::disk('local')->path("batch_reports/10/{$workspaceId}/Rapor_Kelas_4A_UTS.zip");
        $this->createFileSymlinkOrSkip($outside.'/keep.zip', $link);

        try {
            $this->assertNull(app(BatchDocxArchiveService::class)->resolveOwnedArchivePath(
                "batch_reports/10/{$workspaceId}/Rapor_Kelas_4A_UTS.zip",
                10
            ));
            $this->assertSame(0, app(BatchDocxArchiveService::class)->cleanupExpired());
            $this->assertFileExists($outside.'/keep.zip');
        } finally {
            $this->removeFileLink($link);
            File::deleteDirectory($outside);
        }
    }

    private function createWorkspace(int $guruId, int $modifiedAt): string
    {
        $workspace = BatchDocxArchiveService::ROOT_DIRECTORY.'/'.$guruId.'/'.Str::uuid();
        $archive = $workspace.'/Rapor_Kelas_4A_UTS.zip';
        Storage::disk('local')->put($archive, 'ZIP');
        touch(Storage::disk('local')->path($archive), $modifiedAt);

        return $workspace;
    }

    private function outsideDirectory(string $label): string
    {
        $path = storage_path('framework/testing/batch-docx-outside-'.$label.'-'.Str::uuid());
        File::makeDirectory($path, 0777, true);

        return $path;
    }

    private function createDirectorySymlinkOrSkip(string $target, string $link): void
    {
        if (! function_exists('symlink') || ! @symlink($target, $link)) {
            File::deleteDirectory($target);
            $this->markTestSkipped('Runtime ini tidak mengizinkan pembuatan symlink direktori; junction Windows tidak terverifikasi.');
        }
    }

    private function createFileSymlinkOrSkip(string $target, string $link): void
    {
        if (! function_exists('symlink') || ! @symlink($target, $link)) {
            File::deleteDirectory(dirname($target));
            $this->markTestSkipped('Runtime ini tidak mengizinkan pembuatan symlink file.');
        }
    }

    private function removeDirectoryLink(string $link): void
    {
        if (is_link($link)) {
            @unlink($link);
        } elseif (file_exists($link)) {
            @rmdir($link);
        }
    }

    private function removeFileLink(string $link): void
    {
        if (is_link($link) || file_exists($link)) {
            @unlink($link);
        }
    }
}

<?php

namespace App\Services;

use FilesystemIterator;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;
use ZipArchive;

class BatchDocxArchiveService
{
    public const DISK = 'local';

    public const ROOT_DIRECTORY = 'batch_reports';

    public const RETENTION_HOURS = 24;

    /**
     * @param  array<int, array{student_id:int, student_name:string, path:string}>  $documents
     * @return array{path:string, filename:string, workspace_id:string, entries:array<int, array{student_id:int, filename:string}>, skipped_student_ids:array<int, int>}|null
     */
    public function createArchive(int $guruId, string $className, string $type, array $documents): ?array
    {
        if ($guruId < 1 || $documents === []) {
            return null;
        }

        $type = strtoupper(trim($type));
        if (! in_array($type, ['UTS', 'UAS'], true)) {
            throw new RuntimeException('Invalid batch report type.');
        }

        $workspaceId = (string) Str::uuid();
        $workspace = $this->workspacePath($guruId, $workspaceId);
        $filename = $this->archiveFilename($className, $type);
        $archivePath = $workspace.'/'.$filename;
        $batchRoot = $this->trustedBatchRoot(true);
        $ownerRoot = $this->trustedOwnerDirectory($guruId, true, $batchRoot);
        $workspaceRoot = $this->trustedWorkspaceDirectory($guruId, $workspaceId, true, $ownerRoot);
        $archiveAbsolutePath = $this->joinPath($workspaceRoot, $filename);

        if ($this->pathExistsOrIsLink($archiveAbsolutePath)) {
            throw new RuntimeException('Batch report archive path is not available.');
        }

        $zip = new ZipArchive;
        $opened = false;
        $completed = false;

        try {
            if ($zip->open($archiveAbsolutePath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                throw new RuntimeException('Unable to create batch report archive.');
            }
            $opened = true;

            $entries = [];
            $skippedStudentIds = [];
            $entryNames = [];

            foreach ($documents as $document) {
                $studentId = (int) ($document['student_id'] ?? 0);
                $sourcePath = $this->resolveCachedDocxPath((string) ($document['path'] ?? ''));

                if ($studentId < 1 || $sourcePath === null) {
                    if ($studentId > 0) {
                        $skippedStudentIds[] = $studentId;
                    }
                    continue;
                }

                $entryName = $this->entryFilename(
                    $type,
                    $studentId,
                    (string) ($document['student_name'] ?? '')
                );

                if (isset($entryNames[$entryName]) || ! $zip->addFile($sourcePath, $entryName)) {
                    $skippedStudentIds[] = $studentId;
                    continue;
                }

                $entryNames[$entryName] = true;
                $entries[] = [
                    'student_id' => $studentId,
                    'filename' => $entryName,
                ];
            }

            if ($entries === []) {
                $zip->close();
                $opened = false;
                $this->deleteTrustedWorkspace($guruId, $workspaceId);

                return null;
            }

            if (! $zip->close()) {
                $opened = false;
                throw new RuntimeException('Unable to finalize batch report archive.');
            }
            $opened = false;

            $resolvedArchive = $this->resolveTrustedArchiveFile(
                $guruId,
                $workspaceId,
                $filename,
                $archivePath
            );
            if ($resolvedArchive === null || filesize($resolvedArchive) < 1) {
                throw new RuntimeException('Batch report archive was not created correctly.');
            }

            $completed = true;

            return [
                'path' => $archivePath,
                'filename' => $filename,
                'workspace_id' => $workspaceId,
                'entries' => $entries,
                'skipped_student_ids' => array_values(array_unique($skippedStudentIds)),
            ];
        } finally {
            if ($opened) {
                $zip->close();
            }

            if (! $completed) {
                $this->deleteTrustedWorkspace($guruId, $workspaceId);
            }
        }
    }

    public function resolveOwnedArchivePath(string $relativePath, int $guruId): ?string
    {
        $relativePath = $this->normalizeRelativePath($relativePath);

        if ($guruId < 1 || ! preg_match(
            '#^'.preg_quote(self::ROOT_DIRECTORY, '#').'/'.preg_quote((string) $guruId, '#').'/([0-9a-f-]{36})/(Rapor_Kelas_[A-Za-z0-9_-]+_(?:UTS|UAS)\.zip)$#',
            $relativePath,
            $matches
        ) || ! Str::isUuid($matches[1])) {
            return null;
        }

        try {
            return $this->resolveTrustedArchiveFile(
                $guruId,
                $matches[1],
                $matches[2],
                $relativePath
            );
        } catch (Throwable) {
            return null;
        }
    }

    public function cleanupExpired(int $retentionHours = self::RETENTION_HOURS): int
    {
        try {
            $batchRoot = $this->trustedBatchRoot(false);
        } catch (Throwable $exception) {
            Log::warning('Batch report cleanup skipped an untrusted root.', [
                'exception' => $exception,
            ]);

            return 0;
        }

        if ($batchRoot === null) {
            return 0;
        }

        $cutoff = now()->subHours(max(1, $retentionHours))->getTimestamp();
        $deleted = 0;

        try {
            $owners = new FilesystemIterator($batchRoot, FilesystemIterator::SKIP_DOTS);
        } catch (Throwable $exception) {
            Log::warning('Batch report cleanup could not inspect the private root.', [
                'exception' => $exception,
            ]);

            return 0;
        }

        foreach ($owners as $ownerEntry) {
            $ownerName = $ownerEntry->getFilename();
            if (! preg_match('/^[1-9][0-9]*$/', $ownerName)
                || ! $ownerEntry->isDir()
                || $this->isRedirectingLink($ownerEntry->getPathname())) {
                continue;
            }

            $guruId = (int) $ownerName;
            try {
                $ownerRoot = $this->trustedOwnerDirectory($guruId, false, $batchRoot);
                if ($ownerRoot === null) {
                    continue;
                }

                $workspaces = new FilesystemIterator($ownerRoot, FilesystemIterator::SKIP_DOTS);
            } catch (Throwable $exception) {
                Log::warning('Batch report cleanup skipped an untrusted owner directory.', [
                    'guru_id' => $guruId,
                    'exception' => $exception,
                ]);
                continue;
            }

            foreach ($workspaces as $workspaceEntry) {
                $workspaceId = $workspaceEntry->getFilename();
                if (! Str::isUuid($workspaceId)
                    || ! $workspaceEntry->isDir()
                    || $this->isRedirectingLink($workspaceEntry->getPathname())) {
                    continue;
                }

                try {
                    $workspaceRoot = $this->trustedWorkspaceDirectory(
                        $guruId,
                        $workspaceId,
                        false,
                        $ownerRoot
                    );
                    if ($workspaceRoot === null) {
                        continue;
                    }

                    $snapshot = $this->workspaceDeletionSnapshot($workspaceRoot);
                    if ($snapshot === null || $snapshot['last_modified'] > $cutoff) {
                        continue;
                    }

                    if ($this->deleteTrustedWorkspace($guruId, $workspaceId)) {
                        $deleted++;
                    }
                } catch (Throwable $exception) {
                    Log::warning('Batch report cleanup skipped a suspicious workspace.', [
                        'guru_id' => $guruId,
                        'workspace_id' => $workspaceId,
                        'exception' => $exception,
                    ]);
                }
            }
        }

        return $deleted;
    }

    private function resolveCachedDocxPath(string $relativePath): ?string
    {
        $relativePath = $this->normalizeRelativePath($relativePath);

        if (! preg_match('#^'.preg_quote(PdfCacheService::DOCX_DIRECTORY, '#').'/[A-Za-z0-9._-]+\.docx$#i', $relativePath)) {
            return null;
        }

        $disk = Storage::disk(PdfCacheService::STORAGE_DISK);
        if (! $disk->exists($relativePath)) {
            return null;
        }

        $rootPath = realpath($disk->path(PdfCacheService::DOCX_DIRECTORY));
        $sourcePath = realpath($disk->path($relativePath));

        if ($rootPath === false
            || $sourcePath === false
            || $this->isRedirectingLink($disk->path($relativePath))
            || ! is_file($sourcePath)
            || ! is_readable($sourcePath)) {
            return null;
        }

        return $this->isWithinDirectory($sourcePath, $rootPath) ? $sourcePath : null;
    }

    private function trustedLocalDiskRoot(): string
    {
        $diskRoot = Storage::disk(self::DISK)->path('');
        $resolved = realpath($diskRoot);

        if ($resolved === false || ! is_dir($resolved)) {
            throw new RuntimeException('Private local storage root is unavailable.');
        }

        return $this->normalizeAbsolutePath($resolved);
    }

    private function trustedBatchRoot(bool $create): ?string
    {
        $disk = Storage::disk(self::DISK);
        $localRoot = $this->trustedLocalDiskRoot();
        $batchPath = $disk->path(self::ROOT_DIRECTORY);

        if (! $this->pathExistsOrIsLink($batchPath)) {
            if (! $create) {
                return null;
            }

            if (! $disk->makeDirectory(self::ROOT_DIRECTORY)) {
                throw new RuntimeException('Unable to create private batch report root.');
            }
        }

        return $this->resolveExactTrustedDirectory(
            $batchPath,
            $this->joinPath($localRoot, self::ROOT_DIRECTORY),
            $localRoot,
            'Private batch report root is not trusted.'
        );
    }

    private function trustedOwnerDirectory(int $guruId, bool $create, string $batchRoot): ?string
    {
        if ($guruId < 1) {
            throw new RuntimeException('Invalid batch report owner.');
        }

        $relativePath = self::ROOT_DIRECTORY.'/'.$guruId;
        $ownerPath = Storage::disk(self::DISK)->path($relativePath);

        if (! $this->pathExistsOrIsLink($ownerPath)) {
            if (! $create) {
                return null;
            }

            if (! Storage::disk(self::DISK)->makeDirectory($relativePath)) {
                throw new RuntimeException('Unable to create private batch report owner directory.');
            }
        }

        return $this->resolveExactTrustedDirectory(
            $ownerPath,
            $this->joinPath($batchRoot, (string) $guruId),
            $batchRoot,
            'Private batch report owner directory is not trusted.'
        );
    }

    private function trustedWorkspaceDirectory(
        int $guruId,
        string $workspaceId,
        bool $create,
        string $ownerRoot
    ): ?string {
        if (! Str::isUuid($workspaceId)) {
            throw new RuntimeException('Invalid batch report workspace.');
        }

        $relativePath = $this->workspacePath($guruId, $workspaceId);
        $workspacePath = Storage::disk(self::DISK)->path($relativePath);

        if (! $this->pathExistsOrIsLink($workspacePath)) {
            if (! $create) {
                return null;
            }

            if (! Storage::disk(self::DISK)->makeDirectory($relativePath)) {
                throw new RuntimeException('Unable to create private batch report workspace.');
            }
        } elseif ($create) {
            throw new RuntimeException('Batch report workspace already exists.');
        }

        return $this->resolveExactTrustedDirectory(
            $workspacePath,
            $this->joinPath($ownerRoot, $workspaceId),
            $ownerRoot,
            'Private batch report workspace is not trusted.'
        );
    }

    private function resolveTrustedArchiveFile(
        int $guruId,
        string $workspaceId,
        string $filename,
        string $relativePath
    ): ?string {
        if (! $this->isExpectedArchiveFilename($filename)) {
            return null;
        }

        $batchRoot = $this->trustedBatchRoot(false);
        if ($batchRoot === null) {
            return null;
        }

        $ownerRoot = $this->trustedOwnerDirectory($guruId, false, $batchRoot);
        if ($ownerRoot === null) {
            return null;
        }

        $workspaceRoot = $this->trustedWorkspaceDirectory($guruId, $workspaceId, false, $ownerRoot);
        if ($workspaceRoot === null) {
            return null;
        }

        $archivePath = Storage::disk(self::DISK)->path($relativePath);
        if ($this->isRedirectingLink($archivePath)) {
            return null;
        }

        $resolved = realpath($archivePath);
        $expected = $this->joinPath($workspaceRoot, $filename);

        if ($resolved === false
            || ! is_file($resolved)
            || ! $this->pathsEqual($resolved, $expected)
            || ! $this->isWithinDirectory($resolved, $workspaceRoot)) {
            return null;
        }

        return $this->normalizeAbsolutePath($resolved);
    }

    private function resolveExactTrustedDirectory(
        string $path,
        string $expectedPath,
        string $trustedParent,
        string $errorMessage
    ): string {
        if ($this->isRedirectingLink($path) || ! is_dir($path)) {
            throw new RuntimeException($errorMessage);
        }

        $resolved = realpath($path);
        if ($resolved === false
            || ! $this->pathsEqual($resolved, $expectedPath)
            || ! $this->isWithinDirectory($resolved, $trustedParent)) {
            throw new RuntimeException($errorMessage);
        }

        return $this->normalizeAbsolutePath($resolved);
    }

    private function deleteTrustedWorkspace(int $guruId, string $workspaceId): bool
    {
        try {
            $batchRoot = $this->trustedBatchRoot(false);
            if ($batchRoot === null) {
                return false;
            }

            $ownerRoot = $this->trustedOwnerDirectory($guruId, false, $batchRoot);
            if ($ownerRoot === null) {
                return false;
            }

            $workspaceRoot = $this->trustedWorkspaceDirectory($guruId, $workspaceId, false, $ownerRoot);
            if ($workspaceRoot === null) {
                return false;
            }

            $snapshot = $this->workspaceDeletionSnapshot($workspaceRoot);
            if ($snapshot === null) {
                return false;
            }

            foreach ($snapshot['files'] as $file) {
                if ($this->isRedirectingLink($file)
                    || ! $this->isWithinDirectory($file, $workspaceRoot)
                    || ! @unlink($file)) {
                    return false;
                }
            }

            clearstatcache(true, $workspaceRoot);

            return is_dir($workspaceRoot) && ! $this->isRedirectingLink($workspaceRoot)
                ? @rmdir($workspaceRoot)
                : false;
        } catch (Throwable $exception) {
            Log::warning('Unable to remove a private batch report workspace safely.', [
                'guru_id' => $guruId,
                'workspace_id' => $workspaceId,
                'exception' => $exception,
            ]);

            return false;
        }
    }

    /**
     * @return array{files:array<int, string>, last_modified:int}|null
     */
    private function workspaceDeletionSnapshot(string $workspaceRoot): ?array
    {
        $files = [];
        $fileModificationTimes = [];

        try {
            $entries = new FilesystemIterator($workspaceRoot, FilesystemIterator::SKIP_DOTS);
        } catch (Throwable) {
            return null;
        }

        foreach ($entries as $entry) {
            $entryPath = $entry->getPathname();
            if ($this->isRedirectingLink($entryPath)
                || ! $entry->isFile()
                || ! $this->isExpectedArchiveFilename($entry->getFilename())) {
                return null;
            }

            $resolved = realpath($entryPath);
            if ($resolved === false || ! $this->isWithinDirectory($resolved, $workspaceRoot)) {
                return null;
            }

            $files[] = $this->normalizeAbsolutePath($resolved);
            $fileModificationTimes[] = $entry->getMTime();
        }

        return [
            'files' => $files,
            'last_modified' => $fileModificationTimes !== []
                ? max($fileModificationTimes)
                : (@filemtime($workspaceRoot) ?: now()->getTimestamp()),
        ];
    }

    private function workspacePath(int $guruId, string $workspaceId): string
    {
        return self::ROOT_DIRECTORY.'/'.$guruId.'/'.$workspaceId;
    }

    private function archiveFilename(string $className, string $type): string
    {
        return 'Rapor_Kelas_'.$this->sanitizeComponent($className, 'Kelas').'_'.strtoupper($type).'.zip';
    }

    private function entryFilename(string $type, int $studentId, string $studentName): string
    {
        return 'Rapor_'.strtoupper($type).'_'.$studentId.'_'.$this->sanitizeComponent($studentName, 'Siswa').'.docx';
    }

    private function sanitizeComponent(string $value, string $fallback): string
    {
        $value = preg_replace('/[^A-Za-z0-9_-]+/', '_', trim($value)) ?? '';
        $value = trim($value, '._-');

        return substr($value !== '' ? $value : $fallback, 0, 80);
    }

    private function isExpectedArchiveFilename(string $filename): bool
    {
        return (bool) preg_match('/^Rapor_Kelas_[A-Za-z0-9_-]+_(?:UTS|UAS)\.zip$/', $filename);
    }

    private function normalizeRelativePath(string $path): string
    {
        return ltrim(str_replace('\\', '/', trim($path)), '/');
    }

    private function normalizeAbsolutePath(string $path): string
    {
        $path = str_replace('\\', '/', $path);

        if ($path === '/' || preg_match('/^[A-Za-z]:\/$/', $path)) {
            return $path;
        }

        return rtrim($path, '/');
    }

    private function joinPath(string $parent, string $child): string
    {
        return $this->normalizeAbsolutePath($parent).'/'.ltrim(str_replace('\\', '/', $child), '/');
    }

    private function pathsEqual(string $first, string $second): bool
    {
        $first = $this->normalizeAbsolutePath($first);
        $second = $this->normalizeAbsolutePath($second);

        if (DIRECTORY_SEPARATOR === '\\') {
            return strcasecmp($first, $second) === 0;
        }

        return $first === $second;
    }

    private function isWithinDirectory(string $path, string $directory): bool
    {
        $path = $this->normalizeAbsolutePath($path);
        $directory = $this->normalizeAbsolutePath($directory);

        if (DIRECTORY_SEPARATOR === '\\') {
            $path = strtolower($path);
            $directory = strtolower($directory);
        }

        return str_starts_with($path, rtrim($directory, '/').'/');
    }

    private function pathExistsOrIsLink(string $path): bool
    {
        clearstatcache(true, $path);

        return file_exists($path) || is_link($path) || @lstat($path) !== false;
    }

    private function isRedirectingLink(string $path): bool
    {
        clearstatcache(true, $path);

        if (is_link($path)) {
            return true;
        }

        $stat = @lstat($path);
        if ($stat === false) {
            return false;
        }

        if ((($stat['mode'] ?? 0) & 0170000) === 0120000) {
            return true;
        }

        $resolvedParent = realpath(dirname($path));
        $resolvedPath = realpath($path);
        if ($resolvedParent === false || $resolvedPath === false) {
            return true;
        }

        return ! $this->pathsEqual(
            $resolvedPath,
            $this->joinPath($resolvedParent, basename($path))
        );
    }
}

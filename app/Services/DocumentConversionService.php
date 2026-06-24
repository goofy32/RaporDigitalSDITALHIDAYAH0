<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Throwable;

class DocumentConversionService
{
    private ?string $libreOfficePath = null;

    private ?string $libreOfficePathError = null;

    private ?bool $libreOfficeAvailable = null;

    /**
     * Detect LibreOffice installation path
     */
    private function getLibreOfficePath(): string
    {
        if ($this->libreOfficePath !== null) {
            return $this->libreOfficePath;
        }

        if ($this->libreOfficePathError !== null) {
            throw new \Exception($this->libreOfficePathError);
        }

        return ReportPerformanceTracker::measureSegment('libreoffice_lookup', function () {
            $isWindows = PHP_OS === 'WINNT' || PHP_OS === 'WIN32' || strtoupper(substr(PHP_OS, 0, 3)) === 'WIN';

            try {
                if ($isWindows) {
                    // Multiple possible paths for Windows
                    $possiblePaths = [
                        'C:\\Program Files\\LibreOffice\\program\\soffice.exe',
                        'C:\\Program Files (x86)\\LibreOffice\\program\\soffice.exe',
                        'C:\\LibreOffice\\program\\soffice.exe',
                        // Untuk XAMPP/Laragon yang portable
                        env('LIBREOFFICE_PATH', 'C:\\Program Files\\LibreOffice\\program\\soffice.exe')
                    ];

                    foreach ($possiblePaths as $path) {
                        if (file_exists($path)) {
                            Log::debug('LibreOffice executable resolved', [
                                'platform' => 'windows',
                                'executable' => basename($path),
                            ]);

                            return $this->libreOfficePath = $path;
                        }
                    }

                    throw new \Exception("LibreOffice tidak ditemukan. Install LibreOffice atau set LIBREOFFICE_PATH di .env");
                }

                $configuredPath = env('LIBREOFFICE_PATH');

                if (! $isWindows && is_string($configuredPath) && trim($configuredPath) !== '' && file_exists($configuredPath)) {
                    Log::debug('LibreOffice executable resolved', [
                        'platform' => 'unix',
                        'executable' => basename($configuredPath),
                        'source' => 'env',
                    ]);

                    return $this->libreOfficePath = $configuredPath;
                }

                // Linux/macOS - check if soffice is in PATH
                $process = new Process(['which', 'soffice']);
                $process->run();

                if ($process->isSuccessful()) {
                    $path = trim($process->getOutput());

                    Log::debug('LibreOffice executable resolved', [
                        'platform' => 'unix',
                        'executable' => basename($path),
                    ]);

                    return $this->libreOfficePath = $path;
                }

                // Try common Linux paths
                $linuxPaths = [
                    '/usr/bin/soffice',
                    '/usr/local/bin/soffice',
                    '/opt/libreoffice/program/soffice'
                ];

                foreach ($linuxPaths as $path) {
                    if (file_exists($path)) {
                        Log::debug('LibreOffice executable resolved', [
                            'platform' => 'unix',
                            'executable' => basename($path),
                        ]);

                        return $this->libreOfficePath = $path;
                    }
                }

                throw new \Exception("LibreOffice not found in system PATH or common locations");
            } catch (\Exception $exception) {
                $this->libreOfficePathError = $exception->getMessage();

                throw $exception;
            }
        });
    }

    /**
     * Check if LibreOffice is available
     */
    public function isLibreOfficeAvailable(): bool
    {
        if ($this->libreOfficeAvailable !== null) {
            return $this->libreOfficeAvailable;
        }

        try {
            $this->getLibreOfficePath();
            return $this->libreOfficeAvailable = true;
        } catch (\Exception $e) {
            Log::warning("LibreOffice not available: " . $e->getMessage());
            return $this->libreOfficeAvailable = false;
        }
    }

    /**
     * Convert DOCX file to PDF using LibreOffice
     */
    public function convertDocxToPdf(string $sourcePath, string $outputDir): array
    {
        $token = ReportPerformanceTracker::startSegmentIfEnabled('libreoffice');

        try {
            // Check if LibreOffice is available
            if (!$this->isLibreOfficeAvailable()) {
                return [
                    'success' => false,
                    'message' => 'LibreOffice tidak tersedia. Pastikan LibreOffice sudah terinstall.'
                ];
            }

            // Ensure source file exists
            if (!file_exists($sourcePath)) {
                return [
                    'success' => false,
                    'message' => "Source file tidak ditemukan: $sourcePath"
                ];
            }

            ReportPerformanceTracker::measureSegment('libreoffice_profile_setup', function () use ($outputDir) {
                File::ensureDirectoryExists($outputDir, 0755, true);
            });

            return $this->runWithLibreOfficeConcurrencyLimit(
                fn () => $this->convertDocxToPdfWithRetry($sourcePath, $outputDir)
            );
        } catch (Throwable $e) {
            Log::error('Exception during PDF conversion', [
                'exception' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return [
                'success' => false,
                'message' => 'PDF conversion error: ' . $e->getMessage()
            ];
        } finally {
            ReportPerformanceTracker::endSegmentIfEnabled($token);
        }
    }

    private function convertDocxToPdfWithRetry(string $sourcePath, string $outputDir): array
    {
        $result = $this->runIsolatedLibreOfficeConversion($sourcePath, $outputDir, 1);

        if ($this->shouldRetryLibreOfficeConversion($result, $sourcePath)) {
            Log::warning('libreoffice_conversion_retry', [
                'exit_code' => $result['exit_code'] ?? null,
                'source_exists' => file_exists($sourcePath),
                'expected_pdf_exists' => $result['expected_pdf_exists'] ?? false,
                'final_pdf_exists' => $result['final_pdf_exists'] ?? false,
            ]);

            $retryResult = $this->runIsolatedLibreOfficeConversion($sourcePath, $outputDir, 2);
            $retryResult['retried'] = true;

            return $retryResult;
        }

        return $result;
    }

    private function runIsolatedLibreOfficeConversion(string $sourcePath, string $outputDir, int $attempt): array
    {
        $startedAt = microtime(true);
        $context = $this->createLibreOfficeConversionContext($sourcePath, $outputDir);
        $libreOfficePath = $this->resolveLibreOfficePathForConversion();
        $command = $this->buildLibreOfficeCommand($libreOfficePath, $context);
        $environment = $this->buildLibreOfficeEnvironment($context);
        $processResult = [
            'exit_code' => null,
            'stdout' => '',
            'stderr' => '',
            'successful' => false,
        ];
        $result = [
            'success' => false,
            'message' => 'PDF conversion failed',
            'exit_code' => null,
            'expected_pdf_exists' => false,
            'final_pdf_exists' => false,
        ];

        try {
            $this->prepareLibreOfficeConversionContext($context, $sourcePath);

            Log::info('libreoffice_conversion_started', [
                'attempt' => $attempt,
                'command_path' => $libreOfficePath,
                'command' => $command,
                'source_exists' => file_exists($sourcePath),
                'source_size_bytes' => file_exists($sourcePath) ? filesize($sourcePath) : null,
                'output_dir' => $context['output_dir'],
                'expected_output_path' => $context['expected_temp_pdf_path'],
                'final_output_path' => $context['final_pdf_path'],
                'profile_dir' => $context['profile_dir'],
                'profile_url' => $context['profile_url'],
                'temp_dir' => $context['tmp_dir'],
            ]);

            $processResult = $this->executeLibreOfficeProcess($command, $environment);
            $result['exit_code'] = $processResult['exit_code'];

            $expectedPdfExists = ReportPerformanceTracker::measureSegment(
                'libreoffice_output_validation',
                fn () => file_exists($context['expected_temp_pdf_path'])
            );
            $result['expected_pdf_exists'] = $expectedPdfExists;

            if (! $processResult['successful'] || ! $expectedPdfExists) {
                $stderr = trim((string) $processResult['stderr']);
                $stdout = trim((string) $processResult['stdout']);

                $result['message'] = 'PDF conversion failed'
                    .($stderr !== '' ? ': '.$stderr : ($stdout !== '' ? ': '.$stdout : ''));
            } else {
                File::ensureDirectoryExists($context['final_output_dir'], 0755, true);

                if (! copy($context['expected_temp_pdf_path'], $context['final_pdf_path'])) {
                    $result['message'] = 'PDF file could not be copied to final output path';
                } else {
                    $finalPdfExists = file_exists($context['final_pdf_path']);
                    $result['final_pdf_exists'] = $finalPdfExists;

                    if (! $finalPdfExists) {
                        $result['message'] = 'PDF file was not created';
                    } else {
                        $result = array_merge($result, [
                            'success' => true,
                            'message' => 'PDF conversion successful',
                            'path' => str_replace('\\', '/', $context['final_pdf_path']),
                            'filename' => $context['final_filename'],
                            'final_pdf_exists' => true,
                        ]);
                    }
                }
            }
        } catch (Throwable $exception) {
            $result['message'] = 'PDF conversion error: '.$exception->getMessage();
            $result['exception'] = $exception::class;
        }

        $result['duration_ms'] = round((microtime(true) - $startedAt) * 1000, 2);
        $result['cleanup_success'] = $this->cleanupLibreOfficeConversionContext($context);

        $logContext = $this->conversionLogContext(
            $context,
            $libreOfficePath,
            $command,
            $processResult,
            $result,
            $attempt
        );

        if ($result['success']) {
            Log::info('libreoffice_conversion_completed', $logContext);
        } else {
            Log::error('LibreOffice conversion failed', $logContext);
        }

        return $result;
    }

    protected function executeLibreOfficeProcess(array $command, array $environment): array
    {
        $process = new Process($command, null, $environment);
        $process->setTimeout(120); // Increase timeout for large documents

        ReportPerformanceTracker::measureSegment('libreoffice_process', fn () => $process->run());

        return [
            'exit_code' => $process->getExitCode(),
            'stdout' => $process->getOutput(),
            'stderr' => $process->getErrorOutput(),
            'successful' => $process->isSuccessful(),
        ];
    }

    protected function resolveLibreOfficePathForConversion(): string
    {
        return $this->getLibreOfficePath();
    }

    /**
     * @return array<string, string>
     */
    public function createLibreOfficeConversionContext(string $sourcePath, string $outputDir): array
    {
        $id = (string) Str::uuid();
        $rootDir = storage_path('app/tmp/libreoffice/'.$id);
        $finalFilename = pathinfo($sourcePath, PATHINFO_FILENAME).'.pdf';

        $context = [
            'id' => $id,
            'original_source_path' => $sourcePath,
            'root_dir' => $rootDir,
            'profile_dir' => $rootDir.DIRECTORY_SEPARATOR.'profile',
            'home_dir' => $rootDir.DIRECTORY_SEPARATOR.'home',
            'tmp_dir' => $rootDir.DIRECTORY_SEPARATOR.'tmp',
            'source_dir' => $rootDir.DIRECTORY_SEPARATOR.'source',
            'output_dir' => $rootDir.DIRECTORY_SEPARATOR.'output',
            'source_path' => $rootDir.DIRECTORY_SEPARATOR.'source'.DIRECTORY_SEPARATOR.'input.docx',
            'expected_temp_pdf_path' => $rootDir.DIRECTORY_SEPARATOR.'output'.DIRECTORY_SEPARATOR.'input.pdf',
            'final_output_dir' => $outputDir,
            'final_filename' => $finalFilename,
            'final_pdf_path' => rtrim($outputDir, DIRECTORY_SEPARATOR.'/\\').DIRECTORY_SEPARATOR.$finalFilename,
        ];
        $context['profile_url'] = $this->pathToFileUrl($context['profile_dir']);

        return $context;
    }

    public function buildLibreOfficeCommand(string $libreOfficePath, array $context): array
    {
        return [
            $libreOfficePath,
            '-env:UserInstallation='.$context['profile_url'],
            '--headless',
            '--nologo',
            '--nodefault',
            '--nofirststartwizard',
            '--nolockcheck',
            '--norestore',
            '--convert-to',
            'pdf',
            '--outdir',
            $context['output_dir'],
            $context['source_path'],
        ];
    }

    public function cleanupLibreOfficeConversionContext(array $context): bool
    {
        try {
            if (! isset($context['root_dir']) || ! is_dir($context['root_dir'])) {
                return true;
            }

            return File::deleteDirectory($context['root_dir']);
        } catch (Throwable $exception) {
            Log::warning('libreoffice_conversion_cleanup_failed', [
                'profile_dir' => $context['profile_dir'] ?? null,
                'temp_dir' => $context['tmp_dir'] ?? null,
                'root_dir' => $context['root_dir'] ?? null,
                'exception' => $exception::class,
            ]);

            return false;
        }
    }

    private function prepareLibreOfficeConversionContext(array $context, string $sourcePath): void
    {
        foreach (['root_dir', 'profile_dir', 'home_dir', 'tmp_dir', 'source_dir', 'output_dir'] as $key) {
            File::ensureDirectoryExists($context[$key], 0755, true);
        }

        File::ensureDirectoryExists($context['final_output_dir'], 0755, true);

        if (! copy($sourcePath, $context['source_path'])) {
            throw new \RuntimeException('Unable to copy DOCX into isolated LibreOffice conversion directory.');
        }
    }

    private function buildLibreOfficeEnvironment(array $context): array
    {
        return [
            'HOME' => $context['home_dir'],
            'TMPDIR' => $context['tmp_dir'],
            'TEMP' => $context['tmp_dir'],
            'TMP' => $context['tmp_dir'],
        ];
    }

    private function shouldRetryLibreOfficeConversion(array $result, string $sourcePath): bool
    {
        return ! ($result['success'] ?? false)
            && (int) ($result['exit_code'] ?? 0) === 1
            && file_exists($sourcePath)
            && ! (bool) ($result['expected_pdf_exists'] ?? false)
            && ! (bool) ($result['final_pdf_exists'] ?? false);
    }

    private function runWithLibreOfficeConcurrencyLimit(callable $callback): array
    {
        $limit = max(0, (int) config('report.pdf_libreoffice.max_concurrent', 0));

        if ($limit <= 0) {
            return $callback();
        }

        $lockSeconds = max(30, (int) config('report.pdf_libreoffice.lock_seconds', 180));
        $waitSeconds = max(1, (int) config('report.pdf_libreoffice.lock_wait_seconds', 120));
        $deadline = microtime(true) + $waitSeconds;

        do {
            for ($slot = 1; $slot <= $limit; $slot++) {
                try {
                    $lock = Cache::lock("report_pdf_libreoffice_conversion_slot_{$slot}", $lockSeconds);
                    $acquired = $lock->get();
                } catch (Throwable $exception) {
                    Log::warning('libreoffice_conversion_lock_unavailable', [
                        'max_concurrent' => $limit,
                        'exception' => $exception::class,
                    ]);

                    return $callback();
                }

                if (! $acquired) {
                    continue;
                }

                Log::info('libreoffice_conversion_lock_acquired', [
                    'slot' => $slot,
                    'max_concurrent' => $limit,
                ]);

                try {
                    return $callback();
                } finally {
                    $lock->release();

                    Log::info('libreoffice_conversion_lock_released', [
                        'slot' => $slot,
                        'max_concurrent' => $limit,
                    ]);
                }
            }

            usleep(250000);
        } while (microtime(true) < $deadline);

        return [
            'success' => false,
            'message' => 'LibreOffice conversion concurrency limit timed out.',
            'lock_timeout' => true,
        ];
    }

    private function conversionLogContext(
        array $context,
        string $libreOfficePath,
        array $command,
        array $processResult,
        array $result,
        int $attempt
    ): array {
        $stdout = (string) ($processResult['stdout'] ?? '');
        $stderr = (string) ($processResult['stderr'] ?? '');

        return [
            'attempt' => $attempt,
            'command_path' => $libreOfficePath,
            'command' => $command,
            'source_path' => $context['original_source_path'] ?? null,
            'source_path_exists' => isset($context['original_source_path']) && file_exists($context['original_source_path']),
            'source_size_bytes' => isset($context['original_source_path']) && file_exists($context['original_source_path'])
                ? filesize($context['original_source_path'])
                : null,
            'staged_source_path_exists' => file_exists($context['source_path']),
            'output_dir' => $context['output_dir'],
            'expected_output_path' => $context['expected_temp_pdf_path'],
            'final_output_path' => $context['final_pdf_path'],
            'exit_code' => $processResult['exit_code'] ?? null,
            'stdout_length' => strlen($stdout),
            'stdout_excerpt' => $this->sanitizeProcessExcerpt($stdout),
            'stderr_length' => strlen($stderr),
            'stderr_excerpt' => $this->sanitizeProcessExcerpt($stderr),
            'has_error_output' => trim($stderr) !== '',
            'output_bytes' => strlen($stdout),
            'profile_dir' => $context['profile_dir'],
            'profile_url' => $context['profile_url'],
            'temp_dir' => $context['tmp_dir'],
            'duration_ms' => $result['duration_ms'] ?? null,
            'expected_pdf_exists' => $result['expected_pdf_exists'] ?? false,
            'final_pdf_exists' => $result['final_pdf_exists'] ?? file_exists($context['final_pdf_path']),
            'cleanup_success' => $result['cleanup_success'] ?? null,
            'exception' => $result['exception'] ?? null,
        ];
    }

    private function sanitizeProcessExcerpt(string $value): string
    {
        $value = trim(preg_replace('/\s+/', ' ', $value) ?? '');

        if ($value === '') {
            return '';
        }

        return mb_substr($value, 0, 500, 'UTF-8');
    }

    private function pathToFileUrl(string $path): string
    {
        $normalized = str_replace('\\', '/', $path);
        $segments = explode('/', $normalized);
        $encoded = implode('/', array_map('rawurlencode', $segments));
        $encoded = preg_replace('/^([A-Za-z])%3A/', '$1:', $encoded) ?? $encoded;

        return str_starts_with($encoded, '/')
            ? 'file://'.$encoded
            : 'file:///'.$encoded;
    }

    /**
     * Convert DOCX to PDF using Storage paths
     */
    public function convertStorageDocxToPdf(string $storagePath, string $outputFolder = 'pdf'): array
    {
        // Get full source path
        $fullSourcePath = storage_path('app/public/' . $storagePath);
        
        // Set output directory
        $outputDir = storage_path('app/public/' . $outputFolder);
        
        // Perform conversion
        $result = $this->convertDocxToPdf($fullSourcePath, $outputDir);
        
        // If successful, adjust the path to be relative to storage
        if ($result['success']) {
            $filename = $result['filename'];
            $relativePath = $outputFolder . '/' . $filename;
            
            $result['storage_path'] = $relativePath;
            $result['url'] = Storage::url($relativePath);
        }
        
        return $result;
    }

    /**
     * Test LibreOffice installation
     */
    public function testInstallation(): array
    {
        try {
            $libreOfficePath = $this->getLibreOfficePath();
            
            // Test with version command
            $isWindows = PHP_OS === 'WINNT' || PHP_OS === 'WIN32' || strtoupper(substr(PHP_OS, 0, 3)) === 'WIN';
            
            if ($isWindows) {
                $output = [];
                $returnCode = 0;
                exec('"' . $libreOfficePath . '" --version 2>&1', $output, $returnCode);
                
                return [
                    'success' => $returnCode === 0,
                    'path' => $libreOfficePath,
                    'version' => implode("\n", $output),
                    'platform' => 'Windows'
                ];
            } else {
                $process = new Process([$libreOfficePath, '--version']);
                $process->run();
                
                return [
                    'success' => $process->isSuccessful(),
                    'path' => $libreOfficePath,
                    'version' => $process->getOutput(),
                    'platform' => 'Linux/Unix'
                ];
            }
            
        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }
    }
}

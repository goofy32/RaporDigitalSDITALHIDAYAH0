<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;
use Symfony\Component\Process\Exception\ProcessFailedException;

class DocumentConversionService
{
    /**
     * Detect LibreOffice installation path
     */
    private function getLibreOfficePath(): string
    {
        $isWindows = PHP_OS === 'WINNT' || PHP_OS === 'WIN32' || strtoupper(substr(PHP_OS, 0, 3)) === 'WIN';
        
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
                    Log::info("LibreOffice found at: $path");
                    return $path;
                }
            }
            
            throw new \Exception("LibreOffice tidak ditemukan. Install LibreOffice atau set LIBREOFFICE_PATH di .env");
        }
        
        // Linux/macOS - check if soffice is in PATH
        $process = new Process(['which', 'soffice']);
        $process->run();
        
        if ($process->isSuccessful()) {
            return trim($process->getOutput());
        }
        
        // Try common Linux paths
        $linuxPaths = [
            '/usr/bin/soffice',
            '/usr/local/bin/soffice',
            '/opt/libreoffice/program/soffice'
        ];
        
        foreach ($linuxPaths as $path) {
            if (file_exists($path)) {
                return $path;
            }
        }
        
        throw new \Exception("LibreOffice not found in system PATH or common locations");
    }

    /**
     * Check if LibreOffice is available
     */
    public function isLibreOfficeAvailable(): bool
    {
        try {
            $this->getLibreOfficePath();
            return true;
        } catch (\Exception $e) {
            Log::warning("LibreOffice not available: " . $e->getMessage());
            return false;
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

            // Ensure output directory exists
            if (!file_exists($outputDir)) {
                mkdir($outputDir, 0755, true);
            }

            $isWindows = PHP_OS === 'WINNT' || PHP_OS === 'WIN32' || strtoupper(substr(PHP_OS, 0, 3)) === 'WIN';

            $libreOfficePath = $this->getLibreOfficePath();

            if ($isWindows) {
                return $this->convertOnWindows($libreOfficePath, $sourcePath, $outputDir);
            }

            return $this->convertOnLinux($libreOfficePath, $sourcePath, $outputDir);
        } catch (\Exception $e) {
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

    /**
     * Convert on Windows using exec
     */
    private function convertOnWindows(string $libreOfficePath, string $sourcePath, string $outputDir): array
    {
        // Escape paths for Windows command line
        $escapedLibreOffice = '"' . $libreOfficePath . '"';
        $escapedSource = '"' . $sourcePath . '"';
        $escapedOutput = '"' . $outputDir . '"';
        
        // Build command
        $command = "{$escapedLibreOffice} --headless --convert-to pdf --outdir {$escapedOutput} {$escapedSource}";
        
        Log::info('Running LibreOffice command on Windows', [
            'command' => $command
        ]);
        
        // Execute command
        $output = [];
        $returnCode = 0;
        exec($command . ' 2>&1', $output, $returnCode);
        
        Log::info('Command execution result', [
            'return_code' => $returnCode,
            'output' => $output
        ]);
        
        if ($returnCode !== 0) {
            return [
                'success' => false,
                'message' => 'LibreOffice conversion failed: ' . implode("\n", $output),
                'return_code' => $returnCode,
                'output' => $output
            ];
        }
        
        // Check if PDF was created
        $filename = pathinfo($sourcePath, PATHINFO_FILENAME) . '.pdf';
        $pdfPath = $outputDir . DIRECTORY_SEPARATOR . $filename;
        
        if (!file_exists($pdfPath)) {
            return [
                'success' => false,
                'message' => "PDF file tidak terbuat: $pdfPath"
            ];
        }
        
        return [
            'success' => true,
            'path' => str_replace('\\', '/', $pdfPath), // Normalize path
            'filename' => $filename
        ];
    }

    /**
     * Convert on Linux using Symfony Process
     */
    private function convertOnLinux(string $libreOfficePath, string $sourcePath, string $outputDir): array
    {
        $command = [
            $libreOfficePath,
            '--headless',
            '--convert-to', 'pdf',
            '--outdir', $outputDir,
            $sourcePath
        ];
        
        Log::info('Running LibreOffice command on Linux', [
            'command' => implode(' ', $command)
        ]);
        
        // Create and run the process
        $process = new Process($command);
        $process->setTimeout(120); // Increase timeout for large documents
        $process->run();
        
        // Check if the process was successful
        if (!$process->isSuccessful()) {
            Log::error('LibreOffice conversion failed', [
                'command' => $process->getCommandLine(),
                'error' => $process->getErrorOutput(),
                'output' => $process->getOutput()
            ]);
            
            return [
                'success' => false,
                'message' => 'PDF conversion failed: ' . $process->getErrorOutput()
            ];
        }
        
        // Check if PDF was created
        $filename = pathinfo($sourcePath, PATHINFO_FILENAME) . '.pdf';
        $pdfPath = $outputDir . '/' . $filename;
        
        if (!file_exists($pdfPath)) {
            return [
                'success' => false,
                'message' => 'PDF file was not created'
            ];
        }
        
        return [
            'success' => true,
            'path' => $pdfPath,
            'filename' => $filename
        ];
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

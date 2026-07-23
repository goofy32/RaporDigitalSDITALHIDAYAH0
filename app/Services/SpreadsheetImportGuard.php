<?php

namespace App\Services;

use DomainException;
use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\Reader\Exception as ReaderException;
use PhpOffice\PhpSpreadsheet\Reader\Xlsx;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Throwable;
use ZipArchive;

class SpreadsheetImportGuard
{
    public const PROFILE_STUDENT = 'student';
    public const PROFILE_SCORE = 'score';
    public const PROFILE_MULTI_SCORE = 'multi_score';
    public const PROFILE_INITIAL_GURU = 'initial_guru';

    public const MAX_STUDENT_IMPORT_ROWS = 1000;
    public const MAX_SCORE_IMPORT_ROWS = 500;
    public const MAX_MULTI_SCORE_WORKSHEETS = 100;

    private const MIB = 1048576;
    private const GENERIC_UNREADABLE_MESSAGE = 'File Excel tidak dapat diproses. Pastikan file XLSX berasal dari template aplikasi.';
    private const UNSUPPORTED_FORMAT_MESSAGE = 'Format file tidak didukung. Gunakan file Excel XLSX dari template aplikasi.';
    private const TOO_MANY_ROWS_MESSAGE = 'File memiliki terlalu banyak baris untuk diproses.';
    private const TOO_MANY_SHEETS_MESSAGE = 'Workbook memiliki terlalu banyak worksheet untuk diproses.';
    private const TOO_LARGE_MESSAGE = 'File terlalu besar untuk diproses.';
    private const INVALID_STRUCTURE_MESSAGE = 'Struktur file Excel tidak valid.';

    /**
     * @var array<string, array{
     *     max_bytes: int,
     *     max_sheets: int,
     *     max_zip_entries: int,
     *     max_uncompressed_bytes: int,
     *     max_entry_bytes: int,
     *     max_compression_ratio: int,
     *     read_data_only: bool
     * }>
     */
    private const POLICIES = [
        self::PROFILE_STUDENT => [
            'max_bytes' => 2 * self::MIB,
            'max_sheets' => 5,
            'max_zip_entries' => 750,
            'max_uncompressed_bytes' => 64 * self::MIB,
            'max_entry_bytes' => 16 * self::MIB,
            'max_compression_ratio' => 100,
            'read_data_only' => false,
        ],
        self::PROFILE_SCORE => [
            'max_bytes' => 2 * self::MIB,
            'max_sheets' => 3,
            'max_zip_entries' => 750,
            'max_uncompressed_bytes' => 64 * self::MIB,
            'max_entry_bytes' => 16 * self::MIB,
            'max_compression_ratio' => 100,
            'read_data_only' => true,
        ],
        self::PROFILE_MULTI_SCORE => [
            'max_bytes' => 4 * self::MIB,
            'max_sheets' => self::MAX_MULTI_SCORE_WORKSHEETS,
            'max_zip_entries' => 1500,
            'max_uncompressed_bytes' => 128 * self::MIB,
            'max_entry_bytes' => 32 * self::MIB,
            'max_compression_ratio' => 100,
            'read_data_only' => true,
        ],
        self::PROFILE_INITIAL_GURU => [
            'max_bytes' => 2 * self::MIB,
            'max_sheets' => 3,
            'max_zip_entries' => 750,
            'max_uncompressed_bytes' => 64 * self::MIB,
            'max_entry_bytes' => 16 * self::MIB,
            'max_compression_ratio' => 100,
            'read_data_only' => true,
        ],
    ];

    public function loadUploadedXlsx(UploadedFile $file, string $profile): Spreadsheet
    {
        return $this->loadXlsxFromPath($this->trustedUploadedPath($file), $profile);
    }

    public function loadXlsxFromPath(string $path, string $profile): Spreadsheet
    {
        $policy = $this->policy($profile);
        $path = $this->trustedLocalPath($path);

        $this->assertFileSize($path, $policy['max_bytes']);
        $this->assertXlsxArchive($path, $policy);

        $reader = new Xlsx();
        $reader->setReadDataOnly($policy['read_data_only']);

        if (method_exists($reader, 'setReadEmptyCells')) {
            $reader->setReadEmptyCells(false);
        }

        try {
            $spreadsheet = $reader->load($path);
        } catch (ReaderException) {
            throw new DomainException(self::GENERIC_UNREADABLE_MESSAGE);
        } catch (Throwable) {
            throw new DomainException(self::GENERIC_UNREADABLE_MESSAGE);
        }

        $this->assertWorksheetCount($spreadsheet, $policy['max_sheets']);

        return $spreadsheet;
    }

    public function assertWorksheetCount(Spreadsheet $spreadsheet, int $maxSheets, ?string $message = null): void
    {
        if ($spreadsheet->getSheetCount() > $maxSheets) {
            throw new DomainException($message ?? self::TOO_MANY_SHEETS_MESSAGE);
        }
    }

    public function assertDataRowLimit(Worksheet $sheet, int $dataStartRow, int $maxDataRows, ?string $message = null): int
    {
        $highestRow = (int) $sheet->getHighestDataRow();
        $dataRows = max(0, $highestRow - $dataStartRow + 1);

        if ($dataRows > $maxDataRows) {
            throw new DomainException($message ?? self::TOO_MANY_ROWS_MESSAGE);
        }

        return $highestRow;
    }

    public function trustedLocalPath(string $path): string
    {
        $path = trim($path);

        if ($path === '' || $this->hasDisallowedStreamScheme($path)) {
            throw new DomainException(self::UNSUPPORTED_FORMAT_MESSAGE);
        }

        if (! is_file($path) || ! is_readable($path)) {
            throw new DomainException(self::GENERIC_UNREADABLE_MESSAGE);
        }

        return $path;
    }

    private function trustedUploadedPath(UploadedFile $file): string
    {
        $path = $file->getRealPath() ?: $file->getPathname();

        return $this->trustedLocalPath(is_string($path) ? $path : '');
    }

    /**
     * @return array{
     *     max_bytes: int,
     *     max_sheets: int,
     *     max_zip_entries: int,
     *     max_uncompressed_bytes: int,
     *     max_entry_bytes: int,
     *     max_compression_ratio: int,
     *     read_data_only: bool
     * }
     */
    private function policy(string $profile): array
    {
        if (! isset(self::POLICIES[$profile])) {
            throw new DomainException(self::GENERIC_UNREADABLE_MESSAGE);
        }

        return self::POLICIES[$profile];
    }

    private function assertFileSize(string $path, int $maxBytes): void
    {
        $size = filesize($path);

        if ($size === false || $size > $maxBytes) {
            throw new DomainException(self::TOO_LARGE_MESSAGE);
        }
    }

    /**
     * @param array{
     *     max_zip_entries: int,
     *     max_uncompressed_bytes: int,
     *     max_entry_bytes: int,
     *     max_compression_ratio: int
     * } $policy
     */
    private function assertXlsxArchive(string $path, array $policy): void
    {
        if (! class_exists(ZipArchive::class)) {
            throw new DomainException(self::GENERIC_UNREADABLE_MESSAGE);
        }

        $zip = new ZipArchive();
        $openResult = $zip->open($path, ZipArchive::CHECKCONS);

        if ($openResult !== true) {
            throw new DomainException(self::UNSUPPORTED_FORMAT_MESSAGE);
        }

        try {
            if ($zip->numFiles <= 0 || $zip->numFiles > $policy['max_zip_entries']) {
                throw new DomainException(self::INVALID_STRUCTURE_MESSAGE);
            }

            $totalUncompressedSize = 0;
            $hasContentTypes = false;
            $hasWorkbook = false;

            for ($index = 0; $index < $zip->numFiles; $index++) {
                $stat = $zip->statIndex($index);

                if (! is_array($stat)) {
                    throw new DomainException(self::INVALID_STRUCTURE_MESSAGE);
                }

                $name = (string) ($stat['name'] ?? '');
                $size = $this->zipStatSize($stat, 'size');
                $compressedSize = $this->zipStatSize($stat, 'comp_size');

                if ($name === '' || str_contains($name, "\0")) {
                    throw new DomainException(self::INVALID_STRUCTURE_MESSAGE);
                }

                if ($name === '[Content_Types].xml') {
                    $hasContentTypes = true;
                } elseif ($name === 'xl/workbook.xml') {
                    $hasWorkbook = true;
                }

                if ($size > $policy['max_entry_bytes']) {
                    throw new DomainException(self::INVALID_STRUCTURE_MESSAGE);
                }

                $totalUncompressedSize += $size;
                if ($totalUncompressedSize > $policy['max_uncompressed_bytes']) {
                    throw new DomainException(self::INVALID_STRUCTURE_MESSAGE);
                }

                $this->assertZipCompressionRatio($size, $compressedSize, $policy['max_compression_ratio']);
            }

            if (! $hasContentTypes || ! $hasWorkbook) {
                throw new DomainException(self::UNSUPPORTED_FORMAT_MESSAGE);
            }
        } finally {
            $zip->close();
        }
    }

    /**
     * @param array<string, mixed> $stat
     */
    private function zipStatSize(array $stat, string $key): int
    {
        if (! array_key_exists($key, $stat)) {
            throw new DomainException(self::INVALID_STRUCTURE_MESSAGE);
        }

        $value = $stat[$key];

        if (is_int($value)) {
            if ($value < 0) {
                throw new DomainException(self::INVALID_STRUCTURE_MESSAGE);
            }

            return $value;
        }

        if (is_string($value) && preg_match('/^\d+$/', $value) === 1) {
            $max = (string) PHP_INT_MAX;

            if (strlen($value) > strlen($max) || (strlen($value) === strlen($max) && strcmp($value, $max) > 0)) {
                throw new DomainException(self::INVALID_STRUCTURE_MESSAGE);
            }

            return (int) $value;
        }

        throw new DomainException(self::INVALID_STRUCTURE_MESSAGE);
    }

    private function assertZipCompressionRatio(int $size, int $compressedSize, int $maxCompressionRatio): void
    {
        if ($size === 0) {
            return;
        }

        if ($compressedSize <= 0) {
            throw new DomainException(self::INVALID_STRUCTURE_MESSAGE);
        }

        if ($size > self::MIB && ($size / $compressedSize) > $maxCompressionRatio) {
            throw new DomainException(self::INVALID_STRUCTURE_MESSAGE);
        }
    }

    private function hasDisallowedStreamScheme(string $path): bool
    {
        if (preg_match('/^(?:php|phar|data|http|https|ftp|zip):/i', $path) === 1) {
            return true;
        }

        return preg_match('/^[A-Za-z][A-Za-z0-9+.-]*:\/\//', $path) === 1;
    }
}

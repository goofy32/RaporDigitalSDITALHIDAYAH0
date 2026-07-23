<?php

namespace App\Services;

use App\Models\Guru;
use App\Models\Kelas;
use App\Models\MataPelajaran;
use App\Models\TahunAjaran;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use RuntimeException;

class InitialGuruStructureImportService
{
    private const ALL_REGULAR_SUBJECTS = [
        'Pendidikan Pancasila',
        'Mtk',
        'B.Indonesia',
        'Seni Budaya',
        'PLH',
        'Bahasa sunda',
    ];

    public function __construct(
        private readonly SpreadsheetImportGuard $spreadsheetGuard
    ) {
    }

    /**
     * @return array<string, int>
     */
    public function import(string $filePath, TahunAjaran $tahunAjaran, string $temporaryPassword): array
    {
        $stats = $this->emptyStats();
        $rows = $this->readRows($filePath);

        return DB::transaction(function () use ($rows, $tahunAjaran, $temporaryPassword, &$stats) {
            foreach ($rows as $rowNumber => $row) {
                if ($this->isEmptyRow($row) || $this->isExampleRow($row)) {
                    $stats['rows_skipped']++;

                    continue;
                }

                $stats['rows_processed']++;

                $guru = $this->upsertGuru($row, $rowNumber, $temporaryPassword, $stats);
                $isWali = $this->isWaliRow($row['jabatan'] ?? '');
                $classes = $this->resolveClasses($row['kelas_mengajar'] ?? '', $tahunAjaran, true, $stats);
                $subjects = $this->parseSubjects($row['pelajaran'] ?? '', $isWali);

                if ($classes->isEmpty()) {
                    throw new RuntimeException("Baris {$rowNumber}: kelas mengajar tidak dapat dipetakan.");
                }

                if ($isWali && $classes->count() !== 1) {
                    throw new RuntimeException("Baris {$rowNumber}: wali kelas harus memiliki tepat satu kelas.");
                }

                foreach ($classes as $class) {
                    if ($isWali) {
                        $this->upsertWaliAssignment($guru, $class, $stats);
                    } else {
                        $this->upsertTeacherClassAssignment($guru, $class, $stats);
                    }

                    foreach ($subjects as $subjectName) {
                        $flags = $isWali
                            ? ['is_muatan_lokal' => false, 'allow_non_wali' => false]
                            : $this->flagsForNonWaliSubject($subjectName);

                        $this->upsertSubject($subjectName, $guru, $class, $tahunAjaran, $flags, $stats);
                    }
                }
            }

            return $stats;
        });
    }

    /**
     * @return array<int, array<string, string>>
     */
    public function readRows(string $filePath): array
    {
        $spreadsheet = $this->spreadsheetGuard->loadXlsxFromPath($filePath, SpreadsheetImportGuard::PROFILE_INITIAL_GURU);

        try {
            $sheet = $spreadsheet->getActiveSheet();
            $highestRow = $this->spreadsheetGuard->assertDataRowLimit(
                $sheet,
                2,
                SpreadsheetImportGuard::MAX_STUDENT_IMPORT_ROWS
            );
            $highestColumn = $sheet->getHighestDataColumn();

            $rawHeaders = $sheet->rangeToArray("A1:{$highestColumn}1", null, true, true, true)[1] ?? [];
            $headers = [];

            foreach ($rawHeaders as $column => $header) {
                $headers[$column] = $this->normalizeHeader((string) $header);
            }

            $rows = [];

            for ($rowNumber = 2; $rowNumber <= $highestRow; $rowNumber++) {
                $row = [];

                foreach ($headers as $column => $header) {
                    if ($header === '') {
                        continue;
                    }

                    $cell = $sheet->getCell("{$column}{$rowNumber}");
                    $row[$header] = trim($cell->getFormattedValue());
                }

                $rows[$rowNumber] = $row;
            }

            return $rows;
        } finally {
            $spreadsheet->disconnectWorksheets();
        }
    }

    /**
     * @return Collection<int, Kelas>
     */
    public function resolveClasses(string $expression, TahunAjaran $tahunAjaran, bool $createSpecificClasses, ?array &$stats = null): Collection
    {
        $expression = trim(preg_replace('/\s+/', ' ', str_ireplace('Kelas', '', $expression)) ?? '');
        $classes = collect();

        foreach (array_filter(array_map('trim', explode(',', $expression))) as $token) {
            if (preg_match('/^(\d+)\s*-\s*(\d+)$/', $token, $matches)) {
                $from = (int) $matches[1];
                $to = (int) $matches[2];

                $classes = $classes->merge(
                    Kelas::query()
                        ->where('tahun_ajaran_id', $tahunAjaran->id)
                        ->whereBetween('nomor_kelas', [min($from, $to), max($from, $to)])
                        ->orderBy('nomor_kelas')
                        ->orderBy('nama_kelas')
                        ->get()
                );

                continue;
            }

            $specific = $this->parseSpecificClassToken($token);

            if (! $specific) {
                continue;
            }

            if (! $createSpecificClasses) {
                $class = $this->findClass($specific['nomor_kelas'], $specific['nama_kelas'], $tahunAjaran);
                if ($class) {
                    $classes->push($class);
                }

                continue;
            }

            $classes->push($this->upsertClass($specific['nomor_kelas'], $specific['nama_kelas'], $tahunAjaran, $stats));
        }

        return $classes
            ->unique('id')
            ->values();
    }

    /**
     * @return array{is_muatan_lokal: bool, allow_non_wali: bool}
     */
    public function flagsForNonWaliSubject(string $subjectName): array
    {
        $normalized = $this->normalizeToken($subjectName);

        if (in_array($normalized, ['pai', 'pjok', 'penjas'], true)) {
            return ['is_muatan_lokal' => false, 'allow_non_wali' => true];
        }

        if (in_array($normalized, ['b inggris', 'bahasa inggris'], true)) {
            return ['is_muatan_lokal' => true, 'allow_non_wali' => false];
        }

        return ['is_muatan_lokal' => false, 'allow_non_wali' => true];
    }

    /**
     * @return array<string, int>
     */
    private function emptyStats(): array
    {
        return [
            'rows_processed' => 0,
            'rows_skipped' => 0,
            'classes_created' => 0,
            'classes_updated' => 0,
            'gurus_created' => 0,
            'gurus_updated' => 0,
            'wali_assignments_created' => 0,
            'wali_assignments_updated' => 0,
            'teacher_class_assignments_created' => 0,
            'teacher_class_assignments_updated' => 0,
            'subjects_created' => 0,
            'subjects_updated' => 0,
        ];
    }

    /**
     * @param  array<string, string>  $row
     */
    private function isEmptyRow(array $row): bool
    {
        return trim(implode('', [
            $row['nama'] ?? '',
            $row['jabatan'] ?? '',
            $row['pelajaran'] ?? '',
            $row['kelas_mengajar'] ?? '',
        ])) === '';
    }

    /**
     * @param  array<string, string>  $row
     */
    private function isExampleRow(array $row): bool
    {
        $no = $this->normalizeToken($row['no'] ?? '');

        return in_array($no, ['contoh', 'sample', 'example'], true)
            || str_starts_with($no, 'contoh ');
    }

    private function normalizeHeader(string $header): string
    {
        return match ($this->normalizeToken($header)) {
            'no' => 'no',
            'nuptk' => 'nuptk',
            'nama' => 'nama',
            'jenis kelamin' => 'jenis_kelamin',
            'jabatan' => 'jabatan',
            'pelajaran' => 'pelajaran',
            'kelas mengajar' => 'kelas_mengajar',
            default => '',
        };
    }

    /**
     * @param  array<string, string>  $row
     */
    private function upsertGuru(array $row, int $rowNumber, string $temporaryPassword, array &$stats): Guru
    {
        $name = trim((string) ($row['nama'] ?? ''));

        if ($name === '') {
            throw new RuntimeException("Baris {$rowNumber}: nama guru wajib diisi.");
        }

        $nuptk = $this->normalizeNuptk($row['nuptk'] ?? '');
        $existing = $nuptk
            ? Guru::where('nuptk', $nuptk)->first()
            : $this->findGuruByNormalizedName($name);

        $data = [
            'nuptk' => $nuptk,
            'nama' => $name,
            'jenis_kelamin' => $this->normalizeGender($row['jenis_kelamin'] ?? ''),
            'jabatan' => $this->isWaliRow($row['jabatan'] ?? '') ? 'guru_wali' : 'guru',
        ];

        if ($existing) {
            $existing->fill($data);
            $existing->save();
            $stats['gurus_updated']++;

            return $existing;
        }

        $data += [
            'tanggal_lahir' => null,
            'no_handphone' => null,
            'email' => null,
            'alamat' => null,
            'username' => $this->uniqueUsernameFor($name),
            'password' => Hash::make($temporaryPassword),
        ];

        $stats['gurus_created']++;

        return Guru::create($data);
    }

    private function normalizeNuptk(string $nuptk): ?string
    {
        $nuptk = trim($nuptk);

        return $nuptk === '' ? null : preg_replace('/\D+/', '', $nuptk);
    }

    private function normalizeGender(string $gender): string
    {
        return str_contains($this->normalizeToken($gender), 'perempuan') ? 'Perempuan' : 'Laki-laki';
    }

    private function isWaliRow(string $jabatan): bool
    {
        return str_contains($this->normalizeToken($jabatan), 'wali');
    }

    private function findGuruByNormalizedName(string $name): ?Guru
    {
        $target = $this->normalizeToken($name);

        return Guru::whereNull('nuptk')
            ->get()
            ->first(fn (Guru $guru) => $this->normalizeToken($guru->nama) === $target);
    }

    private function uniqueUsernameFor(string $name): string
    {
        $base = Str::slug(Str::ascii($name), '_') ?: 'guru';
        $username = $base;
        $suffix = 2;

        while (Guru::where('username', $username)->exists()) {
            $username = "{$base}_{$suffix}";
            $suffix++;
        }

        return $username;
    }

    /**
     * @return array<int, string>
     */
    private function parseSubjects(string $subjects, bool $isWali): array
    {
        if ($isWali && $this->normalizeToken($subjects) === 'semua mapel') {
            return self::ALL_REGULAR_SUBJECTS;
        }

        return collect(explode(',', $subjects))
            ->map(fn ($subject) => trim(preg_replace('/\s+/', ' ', (string) $subject) ?? ''))
            ->filter()
            ->values()
            ->all();
    }

    /**
     * @return array{nomor_kelas: int, nama_kelas: string}|null
     */
    private function parseSpecificClassToken(string $token): ?array
    {
        if (preg_match('/^(\d+)\s*-\s*(.+)$/', $token, $matches)) {
            return [
                'nomor_kelas' => (int) $matches[1],
                'nama_kelas' => $this->normalizeClassName($matches[2]),
            ];
        }

        if (preg_match('/^(\d+)\s*([A-Za-z].*)$/u', $token, $matches)) {
            return [
                'nomor_kelas' => (int) $matches[1],
                'nama_kelas' => $this->normalizeClassName($matches[2]),
            ];
        }

        return null;
    }

    private function normalizeClassName(string $name): string
    {
        return trim(preg_replace('/\s+/', ' ', $name) ?? '');
    }

    private function upsertClass(int $number, string $name, TahunAjaran $tahunAjaran, ?array &$stats): Kelas
    {
        $class = $this->findClass($number, $name, $tahunAjaran);

        if ($class) {
            $class->update(['nama_kelas' => $name]);
            $class->refresh();
            if ($stats !== null) {
                $stats['classes_updated']++;
            }

            return $class;
        }

        $class = Kelas::create([
            'nomor_kelas' => $number,
            'nama_kelas' => $name,
            'tahun_ajaran_id' => $tahunAjaran->id,
        ]);

        if ($stats !== null) {
            $stats['classes_created']++;
        }

        return $class;
    }

    private function findClass(int $number, string $name, TahunAjaran $tahunAjaran): ?Kelas
    {
        return Kelas::query()
            ->where('tahun_ajaran_id', $tahunAjaran->id)
            ->where('nomor_kelas', $number)
            ->whereRaw('LOWER(nama_kelas) = ?', [Str::lower($name)])
            ->first();
    }

    private function upsertWaliAssignment(Guru $guru, Kelas $class, array &$stats): void
    {
        $otherWali = DB::table('guru_kelas')
            ->where('kelas_id', $class->id)
            ->where('is_wali_kelas', true)
            ->where('role', 'wali_kelas')
            ->where('guru_id', '!=', $guru->id)
            ->exists();

        if ($otherWali) {
            throw new RuntimeException("{$class->label_kelas} sudah memiliki wali kelas lain.");
        }

        $this->upsertAssignment($guru, $class, true, 'wali_kelas', $stats, 'wali_assignments');
    }

    private function upsertTeacherClassAssignment(Guru $guru, Kelas $class, array &$stats): void
    {
        $this->upsertAssignment($guru, $class, false, 'pengajar', $stats, 'teacher_class_assignments');
    }

    private function upsertAssignment(Guru $guru, Kelas $class, bool $isWali, string $role, array &$stats, string $prefix): void
    {
        $exists = DB::table('guru_kelas')
            ->where('guru_id', $guru->id)
            ->where('kelas_id', $class->id)
            ->where('role', $role)
            ->exists();

        DB::table('guru_kelas')->updateOrInsert(
            [
                'guru_id' => $guru->id,
                'kelas_id' => $class->id,
                'role' => $role,
            ],
            [
                'is_wali_kelas' => $isWali,
                'updated_at' => now(),
                'created_at' => $exists ? DB::raw('created_at') : now(),
            ]
        );

        $stats[$exists ? "{$prefix}_updated" : "{$prefix}_created"]++;
    }

    /**
     * @param  array{is_muatan_lokal: bool, allow_non_wali: bool}  $flags
     */
    private function upsertSubject(string $subjectName, Guru $guru, Kelas $class, TahunAjaran $tahunAjaran, array $flags, array &$stats): MataPelajaran
    {
        $subject = MataPelajaran::query()
            ->where('nama_pelajaran', $subjectName)
            ->where('kelas_id', $class->id)
            ->where('tahun_ajaran_id', $tahunAjaran->id)
            ->where('semester', $tahunAjaran->semester)
            ->first();

        $data = [
            'guru_id' => $guru->id,
            'is_muatan_lokal' => $flags['is_muatan_lokal'],
            'allow_non_wali' => $flags['allow_non_wali'],
        ];

        if ($subject) {
            $subject->update($data);
            $stats['subjects_updated']++;

            return $subject;
        }

        $stats['subjects_created']++;

        return MataPelajaran::create($data + [
            'nama_pelajaran' => $subjectName,
            'kelas_id' => $class->id,
            'tahun_ajaran_id' => $tahunAjaran->id,
            'semester' => $tahunAjaran->semester,
        ]);
    }

    private function normalizeToken(string $value): string
    {
        $value = Str::of($value)
            ->lower()
            ->replaceMatches('/[._]+/', ' ')
            ->replaceMatches('/\s+/', ' ')
            ->trim();

        return (string) $value;
    }
}

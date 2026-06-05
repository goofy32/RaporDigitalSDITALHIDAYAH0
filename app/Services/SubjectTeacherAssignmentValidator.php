<?php

namespace App\Services;

use App\Models\Guru;
use App\Models\Kelas;
use Illuminate\Support\Facades\DB;

class SubjectTeacherAssignmentValidator
{
    public const TYPE_REGULAR = 'regular';

    public const TYPE_MUATAN_LOKAL = 'muatan_lokal';

    public const TYPE_SPECIALIST = 'specialist';

    /**
     * @return array{is_muatan_lokal: bool, allow_non_wali: bool}
     */
    public function flagsFromRequest(array $data): array
    {
        $type = $data['teaching_type'] ?? null;

        if ($type) {
            return match ($type) {
                self::TYPE_REGULAR => ['is_muatan_lokal' => false, 'allow_non_wali' => false],
                self::TYPE_MUATAN_LOKAL => ['is_muatan_lokal' => true, 'allow_non_wali' => false],
                self::TYPE_SPECIALIST => ['is_muatan_lokal' => false, 'allow_non_wali' => true],
                default => ['is_muatan_lokal' => false, 'allow_non_wali' => false],
            };
        }

        return [
            'is_muatan_lokal' => $this->truthy($data['is_muatan_lokal'] ?? false),
            'allow_non_wali' => $this->truthy($data['allow_non_wali'] ?? false),
        ];
    }

    public function teachingTypeFromFlags(bool $isMuatanLokal, bool $allowNonWali): string
    {
        if ($isMuatanLokal) {
            return self::TYPE_MUATAN_LOKAL;
        }

        if ($allowNonWali) {
            return self::TYPE_SPECIALIST;
        }

        return self::TYPE_REGULAR;
    }

    /**
     * @return array<string, string>
     */
    public function validate(Guru $guru, Kelas $kelas, bool $isMuatanLokal, bool $allowNonWali): array
    {
        $tahunAjaranId = $kelas->tahun_ajaran_id ? (int) $kelas->tahun_ajaran_id : null;

        if ($isMuatanLokal && $allowNonWali) {
            return [
                'teaching_type' => 'Pilih salah satu jenis pengajaran: reguler, muatan lokal, atau wajib guru mapel.',
            ];
        }

        if (! $isMuatanLokal && ! $allowNonWali) {
            if (! $this->isWaliForClass($guru, $kelas)) {
                return [
                    'guru_pengampu' => 'Mata pelajaran wajib reguler harus diajar oleh wali kelas dari kelas tersebut.',
                ];
            }

            return [];
        }

        if ($this->hasWaliAssignmentInAcademicYear($guru, $tahunAjaranId)) {
            $message = $isMuatanLokal
                ? 'Mata pelajaran muatan lokal harus diajar oleh guru non-wali kelas.'
                : 'Mata pelajaran wajib guru mapel harus diajar oleh guru non-wali kelas.';

            return ['guru_pengampu' => $message];
        }

        return [];
    }

    private function isWaliForClass(Guru $guru, Kelas $kelas): bool
    {
        return DB::table('guru_kelas')
            ->where('guru_id', $guru->id)
            ->where('kelas_id', $kelas->id)
            ->where('is_wali_kelas', true)
            ->where('role', 'wali_kelas')
            ->exists();
    }

    private function hasWaliAssignmentInAcademicYear(Guru $guru, ?int $tahunAjaranId): bool
    {
        return DB::table('guru_kelas')
            ->join('kelas', 'guru_kelas.kelas_id', '=', 'kelas.id')
            ->where('guru_kelas.guru_id', $guru->id)
            ->where('guru_kelas.is_wali_kelas', true)
            ->where('guru_kelas.role', 'wali_kelas')
            ->when($tahunAjaranId, function ($query) use ($tahunAjaranId) {
                $query->where('kelas.tahun_ajaran_id', $tahunAjaranId);
            })
            ->exists();
    }

    private function truthy(mixed $value): bool
    {
        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }
}

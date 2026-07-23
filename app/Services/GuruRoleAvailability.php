<?php

namespace App\Services;

use App\Models\Guru;

class GuruRoleAvailability
{
    /** @var array<string, array<int, string>> */
    private array $memo = [];

    /**
     * @return array<int, string>
     */
    public function availableRoles(Guru $guru, ?int $tahunAjaranId = null, ?int $semester = null): array
    {
        $key = implode(':', [
            (int) $guru->id,
            $tahunAjaranId ?? 'none',
            $semester ?? 'none',
        ]);

        if (array_key_exists($key, $this->memo)) {
            return $this->memo[$key];
        }

        $roles = [];

        if ($guru->hasPengajarAssignment($tahunAjaranId, $semester)) {
            $roles[] = 'pengajar';
        }

        if ($guru->hasWaliKelasAssignment($tahunAjaranId)) {
            $roles[] = 'wali_kelas';
        }

        return $this->memo[$key] = $roles;
    }
}

<?php

namespace App\Services;

use App\Models\Setting;
use App\Models\TahunAjaran;
use Illuminate\Support\Facades\Schema;

class ReportPeriodService
{
    public const SETTING_KEY = 'active_wali_report_period';

    public const TYPES = ['UTS', 'UAS'];

    public function openedType(?TahunAjaran $tahunAjaran = null, ?int $tahunAjaranId = null): string
    {
        $storedType = $this->storedOpenedType();

        if ($storedType) {
            return $storedType;
        }

        if (!$tahunAjaran && $tahunAjaranId && Schema::hasTable('tahun_ajarans')) {
            $tahunAjaran = TahunAjaran::find($tahunAjaranId);
        }

        return $this->typeForSemester((int) ($tahunAjaran?->semester ?: 1));
    }

    public function setOpenedType(string $type): void
    {
        $normalized = $this->normalizeType($type);

        if (!$normalized) {
            throw new \InvalidArgumentException('Jenis rapor tidak valid.');
        }

        if (!Schema::hasTable('settings')) {
            throw new \RuntimeException('Tabel pengaturan belum tersedia.');
        }

        Setting::set(self::SETTING_KEY, $normalized);
    }

    public function isOpened(string $type, ?TahunAjaran $tahunAjaran = null, ?int $tahunAjaranId = null): bool
    {
        return $this->normalizeType($type) === $this->openedType($tahunAjaran, $tahunAjaranId);
    }

    public function filterOpenedTypes(array $types, ?TahunAjaran $tahunAjaran = null, ?int $tahunAjaranId = null): array
    {
        $openedType = $this->openedType($tahunAjaran, $tahunAjaranId);

        return collect($types)
            ->map(fn ($type) => $this->normalizeType((string) $type))
            ->filter(fn ($type) => $type === $openedType)
            ->unique()
            ->values()
            ->all();
    }

    public function unopenedMessage(string $type): string
    {
        $type = $this->normalizeType($type) ?: strtoupper($type);

        return "Rapor {$type} belum dibuka oleh admin.";
    }

    public function normalizeType(mixed $type): ?string
    {
        if (! is_string($type)) {
            return null;
        }

        $type = strtoupper(trim($type));

        return in_array($type, self::TYPES, true) ? $type : null;
    }

    public function typeForSemester(int $semester): string
    {
        return $semester === 2 ? 'UAS' : 'UTS';
    }

    private function storedOpenedType(): ?string
    {
        if (!Schema::hasTable('settings')) {
            return null;
        }

        try {
            return $this->normalizeType(Setting::get(self::SETTING_KEY));
        } catch (\Throwable) {
            return null;
        }
    }
}

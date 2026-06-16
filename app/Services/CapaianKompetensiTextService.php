<?php

namespace App\Services;

use App\Models\CapaianKompetensiCustom;
use App\Models\CapaianPhraseDefault;
use App\Models\MataPelajaran;
use App\Models\Siswa;
use App\Models\TahunAjaran;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class CapaianKompetensiTextService
{
    public const TYPE_TERTINGGI = 'tertinggi';

    public const TYPE_TERENDAH = 'terendah';

    public function resolvePair(
        int $siswaId,
        int $mataPelajaranId,
        ?int $tahunAjaranId,
        bool $includeFullCustom = true
    ): array {
        $context = $this->resolveContext($siswaId, $mataPelajaranId, $tahunAjaranId);

        if (! $context['siswa']) {
            return [
                self::TYPE_TERTINGGI => 'Data siswa tidak tersedia.',
                self::TYPE_TERENDAH => 'Data siswa tidak tersedia.',
            ];
        }

        $lmData = $this->resolveLingkupMateriData(
            $context['siswa']->id,
            $mataPelajaranId,
            $context['tahun_ajaran_id'],
            $context['semester']
        );

        $lmTertinggi = $lmData->sortByDesc('nilai_lm')->first();
        $lmTerendah = $lmData->sortBy('nilai_lm')->first();

        return [
            self::TYPE_TERTINGGI => $this->resolveDescription(
                self::TYPE_TERTINGGI,
                $context,
                $lmTertinggi,
                $includeFullCustom
            ),
            self::TYPE_TERENDAH => $this->resolveDescription(
                self::TYPE_TERENDAH,
                $context,
                $lmTerendah,
                $includeFullCustom
            ),
        ];
    }

    public function preload(
        int $siswaId,
        array $mataPelajaranIds,
        int $tahunAjaranId
    ): array {
        $result = [];

        foreach (array_values(array_unique(array_filter($mataPelajaranIds))) as $mataPelajaranId) {
            $result[(int) $mataPelajaranId] = $this->resolvePair(
                $siswaId,
                (int) $mataPelajaranId,
                $tahunAjaranId
            );
        }

        return $result;
    }

    public function resetPrefix(CapaianKompetensiCustom $custom, string $type): void
    {
        if ($type === self::TYPE_TERTINGGI) {
            $custom->forceFill([
                'tertinggi_prefix_mode' => 'default',
                'tertinggi_prefix_text' => null,
            ])->save();

            return;
        }

        if ($type === self::TYPE_TERENDAH) {
            $custom->forceFill([
                'terendah_prefix_mode' => 'default',
                'terendah_prefix_text' => null,
            ])->save();
        }
    }

    private function resolveContext(int $siswaId, int $mataPelajaranId, ?int $tahunAjaranId): array
    {
        $tahunAjaranId = $tahunAjaranId ?: session('tahun_ajaran_id');
        $tahunAjaran = $tahunAjaranId ? TahunAjaran::find($tahunAjaranId) : null;
        $semester = (int) ($tahunAjaran?->semester ?? 1);
        $siswa = Siswa::find($siswaId);
        $mataPelajaran = MataPelajaran::find($mataPelajaranId);

        $custom = null;

        if ($tahunAjaranId) {
            $custom = CapaianKompetensiCustom::where([
                'siswa_id' => $siswaId,
                'mata_pelajaran_id' => $mataPelajaranId,
                'tahun_ajaran_id' => $tahunAjaranId,
                'semester' => $semester,
            ])->first();
        }

        return [
            'siswa' => $siswa,
            'mata_pelajaran' => $mataPelajaran,
            'tahun_ajaran_id' => (int) $tahunAjaranId,
            'semester' => $semester,
            'kelas_id' => $mataPelajaran?->kelas_id ? (int) $mataPelajaran->kelas_id : null,
            'custom' => $custom,
        ];
    }

    private function resolveLingkupMateriData(
        int $siswaId,
        int $mataPelajaranId,
        int $tahunAjaranId,
        int $semester
    ): Collection {
        return DB::table('nilais')
            ->join('lingkup_materis', 'nilais.lingkup_materi_id', '=', 'lingkup_materis.id')
            ->join('mata_pelajarans', 'nilais.mata_pelajaran_id', '=', 'mata_pelajarans.id')
            ->where('nilais.siswa_id', $siswaId)
            ->where('nilais.mata_pelajaran_id', $mataPelajaranId)
            ->where('nilais.tahun_ajaran_id', $tahunAjaranId)
            ->where('mata_pelajarans.tahun_ajaran_id', $tahunAjaranId)
            ->where('mata_pelajarans.semester', $semester)
            ->whereNull('nilais.deleted_at')
            ->whereNull('lingkup_materis.deleted_at')
            ->whereNull('mata_pelajarans.deleted_at')
            ->whereNotNull('nilais.nilai_lm')
            ->groupBy('lingkup_materis.id', 'lingkup_materis.judul_lingkup_materi')
            ->select(
                'lingkup_materis.id',
                'lingkup_materis.judul_lingkup_materi',
                DB::raw('MAX(nilais.nilai_lm) as nilai_lm')
            )
            ->get();
    }

    private function resolveDescription(string $type, array $context, ?object $lm, bool $includeFullCustom): string
    {
        $siswa = $context['siswa'];
        $custom = $context['custom'];

        if ($includeFullCustom && $custom) {
            $fullCustom = $type === self::TYPE_TERTINGGI
                ? $custom->custom_capaian_tertinggi
                : $custom->custom_capaian_terendah;

            if (filled($fullCustom)) {
                return trim((string) $fullCustom);
            }
        }

        if (! $lm || blank($lm->judul_lingkup_materi ?? null)) {
            return $this->fallbackWithoutLingkupMateri($type, $siswa->nama);
        }

        $prefix = $this->resolveStudentPrefix($type, $context)
            ?? $this->resolveContextualDefaultPrefix($type, $context)
            ?? $this->fallbackPrefix($type);

        return $this->composePrefixDescription(
            $siswa->nama,
            $prefix,
            (string) $lm->judul_lingkup_materi
        );
    }

    private function resolveStudentPrefix(string $type, array $context): ?string
    {
        $custom = $context['custom'];

        if (! $custom) {
            return null;
        }

        $modeColumn = $type === self::TYPE_TERTINGGI
            ? 'tertinggi_prefix_mode'
            : 'terendah_prefix_mode';
        $textColumn = $type === self::TYPE_TERTINGGI
            ? 'tertinggi_prefix_text'
            : 'terendah_prefix_text';

        $mode = $custom->{$modeColumn} ?? null;

        if (! in_array($mode, ['preset', 'custom'], true)) {
            return null;
        }

        return $this->normalizePrefix(
            $custom->{$textColumn} ?? null,
            $context['siswa'],
            $mode === 'custom'
        );
    }

    private function resolveContextualDefaultPrefix(string $type, array $context): ?string
    {
        if (
            ! Schema::hasTable('capaian_phrase_defaults')
            || ! $context['tahun_ajaran_id']
            || ! $context['kelas_id']
        ) {
            return null;
        }

        $default = CapaianPhraseDefault::query()
            ->where('tahun_ajaran_id', $context['tahun_ajaran_id'])
            ->where('semester', $context['semester'])
            ->where('kelas_id', $context['kelas_id'])
            ->where('mata_pelajaran_id', $context['mata_pelajaran']?->id)
            ->where('type', $type)
            ->first();

        if (! $default || ! in_array($default->mode, CapaianPhraseDefault::validModes(), true)) {
            return null;
        }

        return $this->normalizePrefix(
            $default->phrase,
            $context['siswa'],
            $default->mode === CapaianPhraseDefault::MODE_CUSTOM
        );
    }

    private function normalizePrefix(?string $prefix, Siswa $siswa, bool $checkOtherStudents = false): ?string
    {
        $prefix = $this->normalizeWhitespace((string) $prefix);
        $prefix = rtrim($prefix, ". \t\n\r\0\x0B");

        if ($prefix === '' || $this->containsCurrentStudentName($prefix, $siswa)) {
            return null;
        }

        if ($checkOtherStudents && $this->containsOtherStudentName($prefix, $siswa)) {
            return null;
        }

        return $prefix;
    }

    private function containsCurrentStudentName(string $prefix, Siswa $siswa): bool
    {
        $normalizedPrefix = mb_strtolower($prefix, 'UTF-8');
        $studentName = $this->normalizeWhitespace((string) $siswa->nama);

        return $studentName !== '' && str_contains($normalizedPrefix, mb_strtolower($studentName, 'UTF-8'));
    }

    private function containsOtherStudentName(string $prefix, Siswa $siswa): bool
    {
        $normalizedPrefix = mb_strtolower($prefix, 'UTF-8');

        return DB::table('siswas')
            ->where('id', '!=', $siswa->id)
            ->pluck('nama')
            ->contains(function ($name) use ($normalizedPrefix) {
                $name = $this->normalizeWhitespace((string) $name);

                return $name !== '' && str_contains($normalizedPrefix, mb_strtolower($name, 'UTF-8'));
            });
    }

    private function composePrefixDescription(string $studentName, string $prefix, string $lingkupMateri): string
    {
        $studentName = $this->normalizeWhitespace($studentName);
        $prefix = $this->normalizeWhitespace($prefix);
        $lingkupMateri = $this->normalizeWhitespace($lingkupMateri);
        $lingkupMateri = rtrim($lingkupMateri, ". \t\n\r\0\x0B");

        $sentence = $this->normalizeWhitespace("{$studentName} {$prefix} {$lingkupMateri}");

        return rtrim($sentence, ". \t\n\r\0\x0B").'.';
    }

    private function fallbackPrefix(string $type): string
    {
        return $type === self::TYPE_TERTINGGI
            ? 'menunjukkan pemahaman dalam'
            : 'berkembang dalam';
    }

    private function fallbackWithoutLingkupMateri(string $type, string $studentName): string
    {
        $studentName = $this->normalizeWhitespace($studentName);

        return $type === self::TYPE_TERTINGGI
            ? "{$studentName} menunjukkan pemahaman yang baik."
            : "{$studentName} terus berkembang dalam pembelajaran.";
    }

    private function normalizeWhitespace(string $value): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $value));
    }
}

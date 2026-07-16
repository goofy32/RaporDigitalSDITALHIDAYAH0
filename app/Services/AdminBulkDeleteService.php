<?php

namespace App\Services;

use App\Models\Ekstrakurikuler;
use App\Models\Guru;
use App\Models\Kelas;
use App\Models\MataPelajaran;
use App\Models\Prestasi;
use App\Models\Siswa;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Throwable;

class AdminBulkDeleteService
{
    private const TYPES = [
        'kelas' => [
            'model' => Kelas::class,
            'label' => 'Kelas',
            'redirect' => 'kelas.index',
        ],
        'teachers' => [
            'model' => Guru::class,
            'label' => 'Guru',
            'redirect' => 'teacher',
        ],
        'students' => [
            'model' => Siswa::class,
            'label' => 'Siswa',
            'redirect' => 'student',
        ],
        'subjects' => [
            'model' => MataPelajaran::class,
            'label' => 'Mata Pelajaran',
            'redirect' => 'subject.index',
        ],
        'ekstrakurikulers' => [
            'model' => Ekstrakurikuler::class,
            'label' => 'Ekstrakurikuler',
            'redirect' => 'ekstra.index',
        ],
        'achievements' => [
            'model' => Prestasi::class,
            'label' => 'Prestasi',
            'redirect' => 'achievement.index',
        ],
    ];

    public function exists(string $type): bool
    {
        return array_key_exists($type, self::TYPES);
    }

    public function label(string $type): string
    {
        return self::TYPES[$type]['label'] ?? 'Data';
    }

    public function redirectRoute(string $type): string
    {
        return self::TYPES[$type]['redirect'] ?? 'admin.dashboard';
    }

    /**
     * @param  array<int, int|string>  $ids
     * @return array{success: int, failed: int, missing: int, failures: array<int, string>}
     */
    public function delete(string $type, array $ids): array
    {
        $config = self::TYPES[$type] ?? null;

        if (! $config) {
            abort(404);
        }

        $modelClass = $config['model'];
        $ids = collect($ids)
            ->map(fn ($id) => (int) $id)
            ->filter(fn ($id) => $id > 0)
            ->unique()
            ->values();

        $result = [
            'success' => 0,
            'failed' => 0,
            'missing' => 0,
            'failures' => [],
        ];

        foreach ($ids as $id) {
            /** @var Model|null $record */
            $record = $modelClass::query()->find($id);

            if (! $record) {
                $result['missing']++;
                continue;
            }

            try {
                DB::transaction(function () use ($type, $record): void {
                    if ($type === 'kelas' && $record instanceof Kelas) {
                        $this->prepareClassWaliBeforeDelete($record);
                    }

                    $record->delete();
                });

                $result['success']++;
            } catch (Throwable) {
                $result['failed']++;
                $result['failures'][] = $this->recordLabel($record);
            }
        }

        return $result;
    }

    public function message(string $type, array $result): string
    {
        $label = $this->label($type);
        $messages = [];

        if ($result['success'] > 0) {
            $messages[] = "{$result['success']} data {$label} berhasil dihapus.";
        }

        $blocked = $result['failed'] + $result['missing'];

        if ($blocked > 0) {
            $messages[] = "{$blocked} data tidak dapat dihapus karena tidak ditemukan atau masih terhubung dengan data lain.";
        }

        if (! empty($result['failures'])) {
            $failedLabels = collect($result['failures'])
                ->unique()
                ->take(5)
                ->join(', ');

            $messages[] = "Data yang tidak dapat dihapus: {$failedLabels}.";
        }

        if ($messages === []) {
            return "Tidak ada data {$label} yang dihapus.";
        }

        return implode(' ', $messages);
    }

    private function prepareClassWaliBeforeDelete(Kelas $kelas): void
    {
        $waliKelas = $kelas->guru()
            ->wherePivot('is_wali_kelas', true)
            ->wherePivot('role', 'wali_kelas')
            ->first();

        if (! $waliKelas) {
            return;
        }

        $otherWaliKelasCount = DB::table('guru_kelas')
            ->where('guru_id', $waliKelas->id)
            ->where('kelas_id', '!=', $kelas->id)
            ->where('is_wali_kelas', true)
            ->where('role', 'wali_kelas')
            ->count();

        if ($otherWaliKelasCount === 0) {
            $waliKelas->jabatan = 'guru';
            $waliKelas->save();
        }
    }

    private function recordLabel(Model $record): string
    {
        foreach (['nama', 'nama_pelajaran', 'nama_ekstrakurikuler', 'jenis_prestasi', 'label_kelas'] as $attribute) {
            if (! empty($record->{$attribute})) {
                return (string) $record->{$attribute};
            }
        }

        return '#'.$record->getKey();
    }
}

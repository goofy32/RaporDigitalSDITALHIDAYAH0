<?php

namespace App\Console\Commands;

use App\Models\Guru;
use App\Models\Kelas;
use App\Models\LingkupMateri;
use App\Models\MataPelajaran;
use App\Models\Nilai;
use App\Models\Ekstrakurikuler;
use App\Models\NilaiEkstrakurikuler;
use App\Models\Prestasi;
use App\Models\Absensi;
use App\Models\Siswa;
use App\Models\TujuanPembelajaran;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class CleanupSoftDeletes extends Command
{
    protected $signature = 'cleanup:soft-deletes';

    protected $description = 'Permanently delete soft-deleted records older than 60 days.';

    public function handle(): int
    {
        $cutoff = now()->subDays(60);
        $deletedCount = 0;

        $deletedCount += $this->forceDeleteCollection(
            Nilai::onlyTrashed()->where('deleted_at', '<', $cutoff)->get()
        );

        $deletedCount += $this->forceDeleteCollection(
            TujuanPembelajaran::onlyTrashed()->where('deleted_at', '<', $cutoff)->get()
        );

        $deletedCount += $this->forceDeleteCollection(
            LingkupMateri::onlyTrashed()->where('deleted_at', '<', $cutoff)->get()
        );

        $deletedCount += $this->forceDeleteCollection(
            MataPelajaran::onlyTrashed()->where('deleted_at', '<', $cutoff)->get()
        );

        $deletedCount += $this->forceDeleteCollection(
            NilaiEkstrakurikuler::onlyTrashed()->where('deleted_at', '<', $cutoff)->get()
        );

        $deletedCount += $this->forceDeleteCollection(
            Prestasi::onlyTrashed()->where('deleted_at', '<', $cutoff)->get()
        );

        $deletedCount += $this->forceDeleteCollection(
            Absensi::onlyTrashed()->where('deleted_at', '<', $cutoff)->get()
        );

        $deletedCount += $this->forceDeleteCollection(
            Siswa::onlyTrashed()->where('deleted_at', '<', $cutoff)->get(),
            fn (Siswa $siswa) => $this->deletePhotoIfExists($siswa->photo)
        );

        $deletedCount += $this->forceDeleteCollection(
            Ekstrakurikuler::onlyTrashed()->where('deleted_at', '<', $cutoff)->get()
        );

        $deletedCount += $this->forceDeleteCollection(
            Guru::onlyTrashed()->where('deleted_at', '<', $cutoff)->get(),
            fn (Guru $guru) => $this->deletePhotoIfExists($guru->photo)
        );

        $deletedCount += $this->forceDeleteCollection(
            Kelas::onlyTrashed()->where('deleted_at', '<', $cutoff)->get()
        );

        $this->info("Soft deleted records older than 60 days have been permanently deleted. Total: {$deletedCount}");

        return self::SUCCESS;
    }

    protected function forceDeleteCollection(iterable $models, ?callable $beforeDelete = null): int
    {
        $count = 0;

        foreach ($models as $model) {
            if ($beforeDelete) {
                $beforeDelete($model);
            }

            $model->forceDelete();
            $count++;
        }

        return $count;
    }

    protected function deletePhotoIfExists(?string $path): void
    {
        if ($path && Storage::disk('public')->exists($path)) {
            Storage::disk('public')->delete($path);
        }
    }
}

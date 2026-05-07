<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Guru;

class DeleteTeacherSubjects extends Command
{
    protected $signature = 'teacher:delete-subjects {username}';
    protected $description = 'Delete all subjects and related data for a specific teacher';

    public function handle()
    {
        $username = $this->argument('username');
        $guru = Guru::where('username', $username)->first();

        if (!$guru) {
            $this->error("Guru dengan username '$username' tidak ditemukan.");
            return 1;
        }

        $this->info("Menghapus data untuk guru: {$guru->nama}");
        $count = $guru->mataPelajarans()->count();

        $guru->mataPelajarans()->each(function ($mataPelajaran) {
            $this->info("Menghapus mata pelajaran: {$mataPelajaran->nama_pelajaran}");
            $mataPelajaran->delete();
        });

        $this->info("$count mata pelajaran dan data terkait telah dihapus.");
    }
}

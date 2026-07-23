<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class TahunAjaran extends Model
{
    use HasFactory, SoftDeletes; // Add SoftDeletes trait

    protected $table = 'tahun_ajarans';

    protected $fillable = [
        'tahun_ajaran', // Format: "2024/2025"
        'is_active',
        'tanggal_mulai',
        'tanggal_selesai',
        'semester', // 1: Ganjil, 2: Genap
        'deskripsi',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'tanggal_mulai' => 'date',
        'tanggal_selesai' => 'date',
        'semester' => 'integer',
        'deleted_at' => 'datetime', // Add this for soft delete support
    ];

    // Boot method untuk setup model events
    protected static function boot()
    {
        parent::boot();

        // Event ketika model diupdate
        static::updated(function ($tahunAjaran) {
            // Jika semester berubah dan tahun ajaran ini aktif
            if ($tahunAjaran->is_active && $tahunAjaran->isDirty('semester')) {
                $oldSemester = $tahunAjaran->getOriginal('semester');
                $newSemester = $tahunAjaran->semester;
                static::updateRelatedData($tahunAjaran->id, $newSemester, $oldSemester);
            }

            // Jika status aktif berubah menjadi true
            if ($tahunAjaran->is_active && $tahunAjaran->isDirty('is_active')) {
                // Update data profil sekolah
                $profil = ProfilSekolah::first();
                if ($profil) {
                    $profil->update([
                        'tahun_pelajaran' => $tahunAjaran->tahun_ajaran,
                        'semester' => $tahunAjaran->semester,
                    ]);
                }
            }
        });
    }

    /**
     * Update data terkait ketika semester berubah
     */
    protected static function updateRelatedData($tahunAjaranId, $newSemester, $oldSemester)
    {
        \Log::info("Memperbarui data terkait untuk tahun ajaran #{$tahunAjaranId} dari semester {$oldSemester} ke {$newSemester}");

        // Check if the tables have the semester column before updating
        // Update absensi dengan semester baru
        if (Schema::hasColumn('absensis', 'semester')) {
            DB::table('absensis')
                ->where('tahun_ajaran_id', $tahunAjaranId)
                ->update(['semester' => $newSemester]);
        }

        // Update mata pelajaran dengan semester baru
        if (Schema::hasColumn('mata_pelajarans', 'semester')) {
            DB::table('mata_pelajarans')
                ->where('tahun_ajaran_id', $tahunAjaranId)
                ->update(['semester' => $newSemester]);
        }

        // Update nilai-nilai dengan semester baru
        // Skip this - nilais doesn't have a semester column

        // Update template rapor dengan semester baru
        if (Schema::hasColumn('report_templates', 'semester')) {
            DB::table('report_templates')
                ->where('tahun_ajaran_id', $tahunAjaranId)
                ->update(['semester' => $newSemester]);
        }

        // Tambahkan model lain yang memiliki semester dan tahun_ajaran_id jika ada
    }

    // Relasi dengan kelas (bisa ada banyak kelas dalam satu tahun ajaran)
    public function kelas()
    {
        return $this->hasMany(Kelas::class);
    }

    // Relasi dengan siswa (semua siswa dalam tahun ajaran ini)
    public function siswas()
    {
        return $this->hasManyThrough(Siswa::class, Kelas::class);
    }

    public function semesterEnrollments()
    {
        return $this->hasMany(SiswaKelasSemester::class, 'tahun_ajaran_id');
    }

    // Relasi dengan mata pelajaran
    public function mataPelajarans()
    {
        return $this->hasMany(MataPelajaran::class);
    }

    // Relasi dengan template rapor
    public function reportTemplates()
    {
        return $this->hasMany(ReportTemplate::class);
    }

    // Get tahun ajaran aktif
    public static function getActive()
    {
        return self::where('is_active', true)->first();
    }

    // Format label semester
    public function getSemesterLabelAttribute()
    {
        return $this->semester == 1 ? 'Ganjil' : 'Genap';
    }

    // Cek apakah tahun ajaran sedang aktif berdasarkan tanggal
    public function isCurrentlyActive()
    {
        $today = now();

        return $this->is_active &&
               $today->between($this->tanggal_mulai, $this->tanggal_selesai);
    }

    // Scopre untuk include softDeleted tahun ajaran
    public function scopeWithSoftDeleted($query, $includeSoftDeleted = true)
    {
        if ($includeSoftDeleted) {
            return $query->withTrashed();
        }

        return $query;
    }
}

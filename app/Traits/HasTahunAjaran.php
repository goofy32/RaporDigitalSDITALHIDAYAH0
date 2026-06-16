<?php

namespace App\Traits;

trait HasTahunAjaran
{
    /**
     * Boot the trait
     */
    public static function bootHasTahunAjaran()
    {
        // Saat membuat model baru
        static::creating(function ($model) {
            static::logTahunAjaranLifecycle("HasTahunAjaran: creating {$model->getTable()} record", [
                'model' => get_class($model),
                'table' => $model->getTable(),
                'has_tahun_ajaran_id' => isset($model->tahun_ajaran_id),
                'tahun_ajaran_id_value' => $model->tahun_ajaran_id ?? null,
                'session_tahun_ajaran' => session('tahun_ajaran_id')
            ]);
            
            // Otomatis isi tahun_ajaran_id saat create jika tidak ada
            if (!$model->tahun_ajaran_id && session('tahun_ajaran_id')) {
                $model->tahun_ajaran_id = session('tahun_ajaran_id');
                static::logTahunAjaranLifecycle("HasTahunAjaran: auto-filling tahun_ajaran_id", [
                    'model' => get_class($model),
                    'tahun_ajaran_id' => $model->tahun_ajaran_id
                ]);
            }
            
            // Jika model memiliki field semester tapi tidak diisi, ambil dari tahun ajaran
            if (array_key_exists('semester', $model->getAttributes()) && $model->semester === null) {
                $model->loadSemesterFromTahunAjaran();
            }
        });
        
        // Tambahkan hook untuk updating
        static::updating(function ($model) {
            static::logTahunAjaranLifecycle("HasTahunAjaran: updating {$model->getTable()} record", [
                'model' => get_class($model),
                'table' => $model->getTable(),
                'model_id' => $model->id,
                'dirty_attributes' => array_keys($model->getDirty()),
                'has_tahun_ajaran_id' => isset($model->tahun_ajaran_id),
                'tahun_ajaran_id_value' => $model->tahun_ajaran_id,
                'is_dirty_tahun_ajaran' => $model->isDirty('tahun_ajaran_id'),
                'session_tahun_ajaran' => session('tahun_ajaran_id')
            ]);
            
            // Jika tahun_ajaran_id dihapus atau diubah menjadi null, kembalikan ke nilai session
            if ($model->isDirty('tahun_ajaran_id') && $model->tahun_ajaran_id === null && session('tahun_ajaran_id')) {
                $model->tahun_ajaran_id = session('tahun_ajaran_id');
                static::logTahunAjaranLifecycle("HasTahunAjaran: restored tahun_ajaran_id during update", [
                    'model' => get_class($model),
                    'tahun_ajaran_id' => $model->tahun_ajaran_id
                ]);
            }
            
            // Jika tahun_ajaran_id berubah dan model memiliki field semester,
            // perbarui semester sesuai tahun ajaran baru
            if ($model->isDirty('tahun_ajaran_id') && array_key_exists('semester', $model->getAttributes())) {
                $model->loadSemesterFromTahunAjaran();
            }
        });
        
        static::created(function ($model) {
            static::logTahunAjaranLifecycle("HasTahunAjaran: created {$model->getTable()} record", [
                'model' => get_class($model),
                'table' => $model->getTable(),
                'model_id' => $model->id,
                'final_tahun_ajaran_id' => $model->tahun_ajaran_id
            ]);
        });
    }

    /**
     * Mengisi field semester dari tahun ajaran yang terkait
     */
    protected function loadSemesterFromTahunAjaran()
    {
        if (!$this->tahun_ajaran_id) {
            return;
        }
        
        $tahunAjaran = \App\Models\TahunAjaran::find($this->tahun_ajaran_id);
        if ($tahunAjaran) {
            $this->semester = $tahunAjaran->semester;
            static::logTahunAjaranLifecycle("HasTahunAjaran: auto-setting semester from tahun ajaran", [
                'model' => get_class($this),
                'tahun_ajaran_id' => $this->tahun_ajaran_id,
                'semester' => $this->semester
            ]);
        }
    }

    /**
     * Scope untuk filter berdasarkan tahun ajaran
     */
    public function scopeTahunAjaran($query, $tahunAjaranId = null)
    {
        $tahunAjaranId = $tahunAjaranId ?: session('tahun_ajaran_id');
        
        if ($tahunAjaranId) {
            return $query->where('tahun_ajaran_id', $tahunAjaranId);
        }
        
        return $query;
    }

    /**
     * Scope untuk filter berdasarkan tahun ajaran aktif saja
     */
    public function scopeAktif($query)
    {
        return $this->scopeTahunAjaran($query);
    }
    
    /**
     * Scope untuk filter berdasarkan semester
     */
    public function scopeSemester($query, $semester = null)
    {
        $semester = $semester ?: session('selected_semester');
        
        if ($semester && schema_has_column($this->getTable(), 'semester')) {
            return $query->where('semester', $semester);
        }
        
        return $query;
    }   
    
    /**
     * Relasi dengan model TahunAjaran
     */
    public function tahunAjaran()
    {
        return $this->belongsTo(\App\Models\TahunAjaran::class, 'tahun_ajaran_id');
    }

    private static function logTahunAjaranLifecycle(string $message, array $context = []): void
    {
        if (! config('logging.diagnostics.log_tahun_ajaran_model_lifecycle')) {
            return;
        }

        \Log::debug($message, $context);
    }
}

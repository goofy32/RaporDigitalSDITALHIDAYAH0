<?php

namespace App\Observers;

use App\Services\AuditService;
use Illuminate\Database\Eloquent\Model;

class AuditObserver
{
    /**
     * Handle the Model "created" event.
     */
    public function created(Model $model): void
    {
        AuditService::logCreated($model);
    }

    /**
     * Handle the Model "updated" event.
     */
    public function updated(Model $model): void
    {
        // Get the original values before update
        $oldValues = $model->getOriginal();
        
        AuditService::logUpdated($model, $oldValues);
    }

    /**
     * Handle the Model "deleted" event.
     */
    public function deleted(Model $model): void
    {
        AuditService::logDeleted($model);
    }

    /**
     * Handle the Model "restored" event.
     */
    public function restored(Model $model): void
    {
        AuditService::log(
            'restored',
            get_class($model),
            $model->id,
            class_basename(get_class($model)).' dipulihkan',
            null,
            $model->toArray()
        );
    }

    /**
     * Handle the Model "force deleted" event.
     */
    public function forceDeleted(Model $model): void
    {
        AuditService::log(
            'force_deleted',
            get_class($model),
            $model->id,
            class_basename(get_class($model)).' dihapus permanen',
            $model->toArray(),
            null
        );
    }
}

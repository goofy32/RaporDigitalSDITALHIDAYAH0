<?php

namespace App\Services;

use App\Models\AuditLog;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Request;

class AuditService
{
    private const ACTION_LABELS = [
        'login_success' => 'Login berhasil',
        'login_failed' => 'Login gagal',
        'logout' => 'Keluar',
        'create' => 'Dibuat',
        'created' => 'Dibuat',
        'update' => 'Diperbarui',
        'updated' => 'Diperbarui',
        'delete' => 'Dihapus',
        'deleted' => 'Dihapus',
        'restored' => 'Dipulihkan',
        'force_deleted' => 'Dihapus permanen',
        'permanent_purge' => 'Dihapus permanen',
        'guru_password_reset' => 'Reset password Guru',
        'guru_password_changed' => 'Password Guru diubah',
        'admin_password_changed' => 'Password Admin diubah',
        'admin_username_changed' => 'Username Admin diubah',
        'admin_email_change_requested' => 'Verifikasi email Admin diminta',
        'admin_email_change_cancelled' => 'Perubahan email Admin dibatalkan',
        'admin_email_changed' => 'Email Admin diubah',
        'cascade_delete_snapshot' => 'Pencatatan penghapusan terkait',
    ];

    public static function actionLabel(?string $action): string
    {
        if (! is_string($action) || trim($action) === '') {
            return 'Tidak diketahui';
        }

        $action = trim($action);

        return self::ACTION_LABELS[$action]
            ?? mb_convert_case(str_replace('_', ' ', $action), MB_CASE_TITLE, 'UTF-8');
    }

    public static function localizedDescription(?string $description): string
    {
        if (! is_string($description) || trim($description) === '') {
            return 'Tidak ada deskripsi';
        }

        $description = trim($description);

        if (preg_match('/\ALogin attempt with username:\s*(.+)\z/ui', $description, $matches) === 1) {
            return 'Percobaan login dengan username/email: '.$matches[1];
        }

        $legacySuffixes = [
            'force deleted' => 'dihapus permanen',
            'created' => 'dibuat',
            'updated' => 'diperbarui',
            'deleted' => 'dihapus',
            'restored' => 'dipulihkan',
        ];

        foreach ($legacySuffixes as $english => $indonesian) {
            if (preg_match('/\A(.+?)\s+'.preg_quote($english, '/').'\.?\z/ui', $description, $matches) === 1) {
                return $matches[1].' '.$indonesian;
            }
        }

        return $description;
    }

    /**
     * Log an action in the audit trail
     *
     * @param string $action The action being performed (login, create, update, delete, etc.)
     * @param string|null $modelType The type of model being affected
     * @param int|null $modelId The ID of the model being affected
     * @param string|null $description A description of the action
     * @param array|null $oldValues Previous values (for updates)
     * @param array|null $newValues New values (for updates)
     * @return AuditLog
     */
    public static function log(
        string $action, 
        ?string $modelType = null, 
        ?int $modelId = null, 
        ?string $description = null, 
        ?array $oldValues = null, 
        ?array $newValues = null
    ): AuditLog {
        // Determine the authenticated user type and ID
        $userType = null;
        $userId = null;
        
        if (Auth::guard('web')->check()) {
            $userType = 'App\\Models\\User';
            $userId = Auth::guard('web')->id();
        } elseif (Auth::guard('guru')->check()) {
            $userType = 'App\\Models\\Guru';
            $userId = Auth::guard('guru')->id();
        }
        
        // Create the audit log entry
        return AuditLog::create([
            'user_type' => $userType,
            'user_id' => $userId,
            'action' => $action,
            'model_type' => $modelType,
            'model_id' => $modelId,
            'description' => $description,
            'old_values' => $oldValues,
            'new_values' => $newValues,
            'ip_address' => Request::ip(),
            'user_agent' => Request::userAgent(),
        ]);
    }
    
    /**
     * Log a login attempt
     * 
     * @param string $status 'success' or 'failed'
     * @param string $username The username that attempted to login
     * @return AuditLog
     */
    public static function logLogin(string $status, string $username): AuditLog
    {
        $action = $status === 'success' ? 'login_success' : 'login_failed';
        return self::log(
            $action,
            null,
            null,
            "Percobaan login dengan username/email: {$username}"
        );
    }
    
    /**
     * Log a logout event
     * 
     * @return AuditLog
     */
    public static function logLogout(): AuditLog
    {
        return self::log('logout', description: 'Pengguna keluar dari aplikasi.');
    }
    
    /**
     * Log model creation
     * 
     * @param Model $model The model that was created
     * @param string|null $description Additional description
     * @return AuditLog
     */
    public static function logCreated($model, ?string $description = null): AuditLog
    {
        $modelType = get_class($model);
        $modelName = class_basename($modelType);
        
        if (!$description) {
            $description = "{$modelName} dibuat";
        }
        
        return self::log(
            'created',
            $modelType,
            $model->id,
            $description,
            null,
            $model->toArray()
        );
    }
    
    /**
     * Log model update
     * 
     * @param Model $model The model after being updated
     * @param array $oldValues The old values before update
     * @param string|null $description Additional description
     * @return AuditLog
     */
    public static function logUpdated($model, array $oldValues, ?string $description = null): AuditLog
    {
        $modelType = get_class($model);
        $modelName = class_basename($modelType);
        
        if (!$description) {
            $description = "{$modelName} diperbarui";
        }
        
        return self::log(
            'updated',
            $modelType,
            $model->id,
            $description,
            $oldValues,
            $model->toArray()
        );
    }
    
    /**
     * Log model deletion
     * 
     * @param Model $model The model being deleted
     * @param string|null $description Additional description
     * @return AuditLog
     */
    public static function logDeleted($model, ?string $description = null): AuditLog
    {
        $modelType = get_class($model);
        $modelName = class_basename($modelType);
        
        if (!$description) {
            $description = "{$modelName} dihapus";
        }
        
        return self::log(
            'deleted',
            $modelType,
            $model->id,
            $description,
            $model->toArray(),
            null
        );
    }
}

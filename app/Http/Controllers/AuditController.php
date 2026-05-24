<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\User;
use App\Models\Guru;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class AuditController extends Controller
{
    /**
     * Display a listing of audit logs.
     */
    public function index(Request $request)
    {
        $query = $this->applyFilters(AuditLog::query(), $request);
        
        // Get actions for filter dropdown
        $actions = AuditLog::select('action')->distinct()->pluck('action');
        
        // Get model types for filter dropdown
        $modelTypes = AuditLog::select('model_type')->whereNotNull('model_type')->distinct()->pluck('model_type');
        
        // Get users for filter dropdown
        $adminUsers = User::select('id', 'name')->get()->map(function($user) {
            return [
                'id' => $user->id,
                'name' => $user->name,
                'type' => 'App\\Models\\User'
            ];
        });
        
        $guruUsers = Guru::select('id', 'nama as name')->get()->map(function($guru) {
            return [
                'id' => $guru->id,
                'name' => $guru->name,
                'type' => 'App\\Models\\Guru'
            ];
        });
        
        $users = $adminUsers->concat($guruUsers);
        
        // Order by most recent first - we're avoiding eager loading 'user' here
        $logs = $query->latest()->paginate(20);
        
        return view('admin.audit.index', compact('logs', 'actions', 'modelTypes', 'users'));
    }
    
    /**
     * Display the specified audit log details.
     */
    public function show(AuditLog $auditLog)
    {
        return view('admin.audit.show', compact('auditLog'));
    }
    
    /**
     * Export audit logs as CSV.
     */
    public function export(Request $request)
    {
        $filename = 'audit_logs_' . Carbon::now()->format('Y-m-d_H-i-s') . '.csv';
        $headers = [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => "attachment; filename={$filename}",
        ];
        
        $columns = ['ID', 'User', 'Action', 'Model Type', 'Model ID', 'Description', 'IP Address', 'Date/Time'];
        
        $callback = function() use ($request, $columns) {
            $file = fopen('php://output', 'w');
            fputcsv($file, $columns);

            $this->applyFilters(AuditLog::query(), $request)
                ->latest()
                ->cursor()
                ->each(function ($log) use ($file) {
                    fputcsv($file, [
                        $log->id,
                        $log->user_type && $log->user_id ? "{$log->user_type} (ID: {$log->user_id})" : 'System',
                        $log->action,
                        $log->model_type ?: 'N/A',
                        $log->model_id ?: 'N/A',
                        $log->description ?: 'N/A',
                        $log->ip_address ?: 'N/A',
                        $log->created_at->format('Y-m-d H:i:s'),
                    ]);
                });
            
            fclose($file);
        };
        
        return response()->stream($callback, 200, $headers);
    }

    private function applyFilters($query, Request $request)
    {
        if ($request->has('action') && $request->action) {
            $query->where('action', $request->action);
        }
        
        if ($request->has('user_type') && $request->user_type) {
            $query->where('user_type', $request->user_type);
        }
        
        if ($request->has('user_id') && $request->user_id) {
            $query->where('user_id', $request->user_id);
        }
        
        if ($request->has('model_type') && $request->model_type) {
            $query->where('model_type', $request->model_type);
        }
        
        if ($request->has('date_from') && $request->date_from) {
            $query->whereDate('created_at', '>=', $request->date_from);
        }
        
        if ($request->has('date_to') && $request->date_to) {
            $query->whereDate('created_at', '<=', $request->date_to);
        }
        
        if ($request->has('search') && $request->search) {
            $search = $request->search;
            $query->where(function($q) use ($search) {
                $q->where('description', 'LIKE', "%{$search}%")
                  ->orWhere('ip_address', 'LIKE', "%{$search}%")
                  ->orWhere('action', 'LIKE', "%{$search}%");
            });
        }

        return $query;
    }
    
    /**
     * Clear audit logs older than the specified time period.
     */
    public function clear(Request $request)
    {
        $request->validate([
            'period' => 'required|in:1month,3months,6months,1year,all'
        ]);
        
        $period = $request->period;
        
        if ($period === 'all') {
            // Clear all logs, but be careful with this!
            AuditLog::truncate();
            return redirect()->route('admin.audit.index')->with('success', 'All audit logs have been cleared.');
        }
        
        // Calculate the cutoff date based on the selected period
        $cutoffDate = match($period) {
            '1month' => Carbon::now()->subMonth(),
            '3months' => Carbon::now()->subMonths(3),
            '6months' => Carbon::now()->subMonths(6),
            '1year' => Carbon::now()->subYear(),
            default => null
        };
        
        if ($cutoffDate) {
            AuditLog::where('created_at', '<', $cutoffDate)->delete();
            return redirect()->route('admin.audit.index')->with('success', "Audit logs older than {$period} have been cleared.");
        }
        
        return redirect()->route('admin.audit.index')->with('error', 'Invalid period specified.');
    }
}

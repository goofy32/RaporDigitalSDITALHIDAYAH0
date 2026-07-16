<?php

namespace App\Http\Controllers;

use App\Services\AdminBulkDeleteService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class AdminBulkDeleteController extends Controller
{
    public function destroySelected(
        Request $request,
        string $type,
        AdminBulkDeleteService $bulkDeleteService
    ): RedirectResponse {
        if (! $bulkDeleteService->exists($type)) {
            abort(404);
        }

        $validated = $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer', 'min:1'],
        ], [
            'ids.required' => 'Pilih minimal satu data yang ingin dihapus.',
            'ids.min' => 'Pilih minimal satu data yang ingin dihapus.',
        ]);

        $result = $bulkDeleteService->delete($type, $validated['ids']);
        $message = $bulkDeleteService->message($type, $result);
        $status = $result['failed'] > 0 || $result['missing'] > 0 ? 'warning' : 'success';

        return redirect()
            ->route($bulkDeleteService->redirectRoute($type))
            ->with($status, $message);
    }
}

<?php

namespace App\Traits;

use App\Models\TahunAjaran;
use Illuminate\Http\Request;

trait RequiresTahunAjaran
{
    protected function getValidTahunAjaranId(?int $override = null): ?int
    {
        if ($override === null && session('no_tahun_ajaran')) {
            return null;
        }

        $id = $override ?? session('tahun_ajaran_id');

        if (!$id) {
            return null;
        }

        if (!TahunAjaran::where('id', $id)->exists()) {
            return null;
        }

        return (int) $id;
    }

    protected function failTahunAjaranNotSet(Request $request, bool $forceJson = false)
    {
        $message = 'Tahun ajaran belum aktif. Hubungi administrator untuk mengaktifkan tahun ajaran.';

        if ($forceJson || $request->expectsJson() || $request->wantsJson() || $request->ajax()) {
            return response()->json([
                'success' => false,
                'message' => $message,
                'error_code' => 'NO_TAHUN_AJARAN',
            ], 422);
        }

        return redirect()->back()->with('error', $message);
    }
}

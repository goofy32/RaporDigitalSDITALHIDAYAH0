<?php

namespace App\Http\Middleware;

use App\Services\DocumentConversionService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

class CheckLibreOfficeAvailability
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Only check LibreOffice availability for PDF-related routes
        if ($this->isPdfRoute($request)) {
            // Check if we already cached the availability status
            $available = Cache::remember('libreoffice_available', 3600, function () {
                return $this->isLibreOfficeAvailable();
            });
            
            if (!$available) {
                if ($request->wantsJson()) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Fitur PDF belum tersedia karena LibreOffice tidak terdeteksi. DOCX dan cetak HTML tetap dapat digunakan.',
                        'error_type' => 'libreoffice_unavailable'
                    ], 503);
                } else {
                    return redirect()->back()->with('error', 'Fitur konversi PDF tidak tersedia. Silakan hubungi administrator.');
                }
            }
        }
        
        return $next($request);
    }
    
    /**
     * Check if LibreOffice is available
     *
     * @return bool
     */
    private function isLibreOfficeAvailable(): bool
    {
        return app(DocumentConversionService::class)->isLibreOfficeAvailable();
    }
    
    /**
     * Determine if the request is for a PDF-related route
     *
     * @param \Illuminate\Http\Request $request
     * @return bool
     */
    private function isPdfRoute(Request $request): bool
    {
        $pdfRoutes = [
            'wali_kelas.rapor.download-pdf',
            'wali_kelas.rapor.preview-pdf',
            'wali_kelas.rapor.generate-pdf',
            'wali_kelas.rapor.batch.generate-pdf',
            'wali_kelas.rapor.test.pdf',
            'wali_kelas.rapor.conversion.status'
        ];
        
        return in_array($request->route()->getName(), $pdfRoutes);
    }
}

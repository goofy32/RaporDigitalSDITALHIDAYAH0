<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;

class GeoLocationService
{
    public function getLocation($ip)
    {
        // Skip untuk localhost
        if ($ip == '127.0.0.1' || $ip == '::1') {
            return 'Localhost (Jaringan Lokal)';
        }
        
        // Cache hasil untuk menghemat request API
        return Cache::remember('ip_location_'.$ip, 60*24*7, function() use ($ip) {
            try {
                $response = Http::get("http://ip-api.com/json/{$ip}");
                
                if ($response->successful()) {
                    $data = $response->json();
                    
                    if ($data['status'] === 'success') {
                        return $data['city'] . ', ' . $data['country'];
                    }
                }
                
                return 'Tidak terdeteksi';
            } catch (\Exception $e) {
                return 'Tidak terdeteksi';
            }
        });
    }
}

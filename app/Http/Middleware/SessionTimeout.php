<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Session\Store;
use Symfony\Component\HttpFoundation\Response;

class SessionTimeout
{
    protected $session;
    protected $timeout;

    public function __construct(Store $session)
    {
        $this->session = $session;
        // Fix: Convert minutes to seconds properly
        $this->timeout = config('session.lifetime') * 60; // 120 minutes * 60 = 7200 seconds
    }

    public function handle(Request $request, Closure $next): Response
    {
        // Skip timeout check for login/logout routes
        if ($this->shouldSkipTimeout($request)) {
            return $next($request);
        }

        if (! Auth::guard('web')->check() && ! Auth::guard('guru')->check()) {
            return $next($request);
        }

        $lastActivity = $this->session->get('last_activity', time());
        $currentTime = time();
        
        // Check if session has expired
        if ($currentTime - $lastActivity > $this->timeout) {
            // Clear all auth guards
            Auth::guard('web')->logout();
            Auth::guard('guru')->logout();
            
            // Flush session
            $this->session->flush();
            $this->session->regenerate();
            
            if ($request->wantsJson() || $request->hasHeader('Turbo-Frame')) {
                return response()->json([
                    'message' => 'Session expired',
                    'redirect' => route('login')
                ], 401);
            }
            
            return redirect()->route('login')
                ->with('message', 'Sesi Anda telah berakhir karena tidak ada aktivitas.');
        }

        // Update last activity
        $this->session->put('last_activity', $currentTime);
        
        return $next($request);
    }

    /**
     * Check if timeout should be skipped for certain routes
     */
    private function shouldSkipTimeout(Request $request): bool
    {
        $skipRoutes = [
            'login',
            'password.request',
            'password.email',
            'password.reset',
            'password.update',
            'logout',
            'login.post'
        ];

        return in_array($request->route()?->getName(), $skipRoutes) ||
               $request->is('login*') ||
               $request->is('logout*');
    }
}

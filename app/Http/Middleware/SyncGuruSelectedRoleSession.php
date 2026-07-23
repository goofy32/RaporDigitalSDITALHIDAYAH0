<?php

namespace App\Http\Middleware;

use App\Services\GuruSelectedRoleSessionState;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class SyncGuruSelectedRoleSession
{
    public function __construct(private readonly GuruSelectedRoleSessionState $roleSessionState)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $this->reconcile($request, 'before_next');

        try {
            return $next($request);
        } finally {
            $this->reconcile($request, 'after_next');
        }
    }

    private function reconcile(Request $request, string $phase): void
    {
        if (! $request->hasSession()) {
            return;
        }

        $before = $request->session()->get(GuruSelectedRoleSessionState::ROLE_KEY);

        if ($this->roleSessionState->reconcile($request->session())) {
            Log::info('Session role guru diselaraskan setelah request overlap.', [
                'request_id' => (string) Str::uuid(),
                'phase' => $phase,
                'route_name' => $request->route()?->getName(),
                'route_uri' => $request->route()?->uri(),
                'selected_role_before' => $before,
                'selected_role_after' => $request->session()->get(GuruSelectedRoleSessionState::ROLE_KEY),
            ]);
        }
    }
}

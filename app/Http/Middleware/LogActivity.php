<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * Manager request (2026-09-30): track what a logged-in user actually did
 * (edits/creates/deletes), not just that they logged in. Logs every
 * state-changing request (POST/PUT/PATCH/DELETE) from an authenticated
 * user after the response is sent, so it never slows the request down.
 * Never logs the request body — only route/method/status metadata — so
 * this can't leak a password or other sensitive field into the log.
 */
class LogActivity
{
    public function handle(Request $request, Closure $next): Response
    {
        return $next($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        if (! in_array($request->method(), ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            return;
        }

        $user = $request->user();
        if ($user === null) {
            return;
        }

        DB::table('activity_logs')->insert([
            'user_id' => $user->id,
            'method' => $request->method(),
            'route_name' => $request->route()?->getName(),
            'url' => substr($request->path(), 0, 500),
            'description' => $this->describe($request),
            'status_code' => $response->getStatusCode(),
            'ip_address' => $request->ip(),
            'created_at' => now(),
        ]);
    }

    private function describe(Request $request): ?string
    {
        $routeName = $request->route()?->getName();
        if ($routeName === null) {
            return null;
        }

        $params = collect($request->route()?->parameters() ?? [])
            ->map(fn ($v) => is_object($v) && method_exists($v, 'getKey') ? $v->getKey() : $v)
            ->implode(', ');

        return $params !== '' ? "{$routeName} ({$params})" : $routeName;
    }
}

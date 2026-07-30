<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Guards the internal cron-tick endpoint (Phase E — see docs/DEPLOYMENT.md).
 * Mirrors the shared-secret pattern already used in the other direction by
 * the Python services' before_request check (python/services/*_service.py) —
 * here it's Laravel validating an inbound caller instead of Laravel being
 * the caller. No session/CSRF involved; this route is stateless and only
 * ever hit by the scheduled GitHub Actions workflow.
 */
class VerifyCronToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $expected = config('services.cron.token');

        if (blank($expected) || ! hash_equals($expected, (string) $request->header('X-Cron-Token'))) {
            abort(403, 'Invalid or missing cron token.');
        }

        return $next($request);
    }
}

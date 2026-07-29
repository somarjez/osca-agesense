<?php

use App\Http\Middleware\NoTimeLimit;
use App\Http\Middleware\RoleMiddleware;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        api: __DIR__.'/../routes/api.php',
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->alias([
            'role' => RoleMiddleware::class,
            'no.time.limit' => NoTimeLimit::class,
        ]);

        // Trust Render's (and, once fronted by it, Cloudflare's) reverse proxy
        // so Laravel correctly detects HTTPS and the real client IP from the
        // X-Forwarded-* headers instead of the proxy hop's own address.
        // Render's edge IPs aren't published/pinnable, so trust all proxies
        // (`*`) rather than an IP allowlist — standard practice for PaaS
        // deployments where the app only ever receives traffic through the
        // platform's own proxy layer, never directly from the internet.
        $middleware->trustProxies(
            at: '*',
            headers: Request::HEADER_X_FORWARDED_FOR
                | Request::HEADER_X_FORWARDED_HOST
                | Request::HEADER_X_FORWARDED_PORT
                | Request::HEADER_X_FORWARDED_PROTO,
        );
    })
    ->withExceptions(function (Exceptions $exceptions) {
        // Production safety net: render friendly, information-free error pages
        // instead of Laravel's default output.
        //
        // IMPORTANT NUANCE (verified against Laravel 11's own Handler source —
        // vendor/laravel/framework/.../Foundation/Exceptions/Handler.php):
        // Laravel already auto-discovers resources/views/errors/{status}.blade.php
        // via a built-in `errors::` view namespace and — for any exception that is
        // already an HttpExceptionInterface (403/404/419/503, and 401/429/etc. if
        // ever added) — renders that view UNCONDITIONALLY, regardless of
        // APP_DEBUG. That is a deliberate Laravel convention, not a bug: those are
        // intentional aborts (RoleMiddleware::abort(403,...), route-model-binding
        // 404s, CSRF 419s), not stack-trace leaks, so showing the friendly page
        // even in local dev is safe and is normal Laravel behavior once these view
        // files exist. This closure is therefore a defensive, explicit restatement
        // of that behavior for those four codes (belt-and-suspenders — harmless if
        // Laravel's own convention already handled it first).
        //
        // The one code where APP_DEBUG genuinely changes behavior is 500 from a
        // *plain, uncaught* exception (not already an HttpException): Laravel's
        // Handler::prepareResponse() only shows the full debug trace when
        // `! isHttpException($e) && config('app.debug')` — so debug=true still
        // shows developers the real stack trace for unexpected errors, and
        // debug=false renders errors/500.blade.php instead. That is the actual
        // "zero effect on local dev" guarantee this closure provides, and it is
        // covered by ErrorPagesTest's debug-gated 500 test.
        $exceptions->render(function (Throwable $e, $request) {
            // Any JSON/API client always gets Laravel's normal JSON error
            // rendering — never one of our HTML views.
            if ($request->expectsJson()) {
                return null;
            }

            // Never intercept these — their default (non-error-page) behavior is
            // functional, not an information-disclosure risk, and must keep working:
            // redirect-back-with-errors, redirect-to-login, and a caller-supplied
            // response, respectively.
            if ($e instanceof ValidationException
                || $e instanceof AuthenticationException
                || $e instanceof HttpResponseException) {
                return null;
            }

            // Illuminate\Foundation\Exceptions\Handler::prepareException() runs
            // before render callbacks and already normalizes AuthorizationException,
            // ModelNotFoundException, and TokenMismatchException into Symfony
            // HttpException subtypes exposing getStatusCode() — so that's the
            // primary branch below. The AuthorizationException check is kept as a
            // defensive fallback in case that normalization behavior ever changes.
            if (method_exists($e, 'getStatusCode')) {
                $status = $e->getStatusCode();
            } elseif ($e instanceof AuthorizationException) {
                $status = 403;
            } else {
                // Genuine uncaught, non-HTTP exception → would become a 500. This
                // is the one branch APP_DEBUG must gate: local/dev developers need
                // the real stack trace, so defer to Laravel's default handling.
                if (config('app.debug')) {
                    return null;
                }

                $status = 500;
            }

            if (! in_array($status, [403, 404, 419, 500, 503], true)) {
                return null;
            }

            return response()->view("errors.{$status}", ['exception' => $e], $status);
        });
    })->create();

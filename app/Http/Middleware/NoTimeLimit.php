<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class NoTimeLimit
{
    public function handle(Request $request, Closure $next): Response
    {
        set_time_limit(0);

        return $next($request);
    }
}

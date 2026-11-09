<?php

namespace App\Http\Middleware;

use App\Helpers\ResponseStatusCodes;
use Closure;
use Illuminate\Http\Request;

/** Rejects tokens of users disabled (or whose institution was rejected) after signing in. */
class EnsureAccountActive
{
    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();

        if ($user && !$user->isActive()) {
            return errorResponse(ResponseStatusCodes::UNAUTHORIZED, 'Your account is not active. Contact FMDQ for help.', [], 401);
        }

        return $next($request);
    }
}

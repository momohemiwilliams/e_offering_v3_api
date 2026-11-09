<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/** Route middleware "role:tsp", "role:csd|admin" or "role:tsp,authoriser". */
class EnsureUserType
{
    public function handle(Request $request, Closure $next, string $type, ?string $accessLevel = null)
    {
        $user = $request->user();

        if (!$user || !in_array($user->type, explode('|', $type), true)) {
            return errorResponse(403, 'You do not have access to this area.', [], 403);
        }
        if ($accessLevel !== null && $user->access_level !== $accessLevel) {
            return errorResponse(403, 'This action needs the ' . ucfirst($accessLevel) . ' access level.', [], 403);
        }

        return $next($request);
    }
}

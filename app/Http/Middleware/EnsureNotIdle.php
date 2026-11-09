<?php

namespace App\Http\Middleware;

use App\Helpers\ResponseStatusCodes;
use App\Services\AuditLogger;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * Server-side idle sign-out (SESSION_IDLE_LIFETIME minutes). The browser warns and signs
 * out on its own; this is the authoritative check for closed or sleeping tabs.
 */
class EnsureNotIdle
{
    public static function minutes(): int
    {
        return max(0, (int) config('session.idle_lifetime', 0));
    }

    public static function touch(int $userId): void
    {
        Cache::put('idle:' . $userId, now()->getTimestamp(), now()->addDay());
    }

    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();
        $minutes = static::minutes();

        if ($user && $minutes > 0) {
            $last = (int) Cache::get('idle:' . $user->id, 0);

            if ($last > 0 && now()->getTimestamp() - $last > $minutes * 60) {
                app(AuditLogger::class)->log('auth.idle_logout', 'Signed out after ' . $minutes . ' minutes of inactivity', $user, [], $user);
                Cache::forget('idle:' . $user->id);
                auth('api')->logout();

                return errorResponse(ResponseStatusCodes::UNAUTHORIZED, sprintf('You were signed out after %d %s of inactivity. Please sign in again.', $minutes, $minutes === 1 ? 'minute' : 'minutes'), [], 401);
            }

            static::touch($user->id);
        }

        return $next($request);
    }
}

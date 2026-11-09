<?php

namespace App\Http\Middleware;

use App\Services\PlatformFeeService;
use Closure;
use Illuminate\Http\Request;

/** CR s3.2 steps 5-12: full TSP access follows registration and, once enabled, the annual fee. */
class EnsureTspAccess
{
    private PlatformFeeService $fees;

    public function __construct(PlatformFeeService $fees)
    {
        $this->fees = $fees;
    }

    public function handle(Request $request, Closure $next)
    {
        $institution = optional($request->user())->institution;

        if (!$institution || !$this->fees->hasFullAccess($institution)) {
            $message = $institution && $institution->isSuspended()
                ? 'Your access is suspended until the platform fee is paid.'
                : 'Complete your registration to unlock the Transaction Sponsor modules.';

            return errorResponse(403, $message, ['redirect' => '/tsp/registration'], 403);
        }

        return $next($request);
    }
}

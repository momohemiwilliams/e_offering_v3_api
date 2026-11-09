<?php

namespace App\Http\Controllers;

use App\Helpers\ResponseStatusCodes;
use App\Http\Middleware\EnsureNotIdle;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\OnboardingService;
use App\Services\OtpService;
use App\Services\PasswordPolicy;
use App\Services\PlatformFeeService;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Two-step sign-in (CR "Login"): password first, then the emailed OTP.
 * No JWT is issued until the code is verified; the password step only yields a
 * short-lived encrypted challenge that identifies the user to the OTP step.
 */
class AuthController extends Controller
{
    private const CHALLENGE_MINUTES = 15;

    /** "Keep me signed in": 30 days. The idle sign-out still applies. */
    private const REMEMBER_TTL_MINUTES = 43200;

    public function login(Request $request, OtpService $otp, PasswordPolicy $policy): JsonResponse
    {
        $data = $request->validate(['email' => 'required|email', 'password' => 'required|string', 'remember' => 'sometimes|boolean']);
        $remember = (bool) ($data['remember'] ?? false);

        $user = User::where('email', $data['email'])->first();
        if ($user && $user->locked_at) {
            return errorResponse(ResponseStatusCodes::UNAUTHORIZED, 'This account is locked after too many wrong passwords. Use "Forgot password?" to reset it.', [], 401);
        }
        if (!$user || !$user->password || !Hash::check($data['password'], $user->password)) {
            app(AuditLogger::class)->log('auth.failed', 'Failed sign-in attempt for ' . $data['email'], null, ['email' => $data['email']]);

            if ($user && $policy->registerFailure($user)) {
                app(AuditLogger::class)->log('auth.account_locked', 'Account locked after too many wrong passwords', $user, [], $user);

                return errorResponse(ResponseStatusCodes::UNAUTHORIZED, 'This account is now locked after too many wrong passwords. Use "Forgot password?" to reset it.', [], 401);
            }

            return errorResponse(ResponseStatusCodes::UNAUTHORIZED, 'These credentials do not match our records.', [], 401);
        }
        $policy->clearFailures($user);
        if ($policy->isExpired($user)) {
            return errorResponse(403, 'Your password has expired. Request a reset link to set a new one.', ['redirect' => '/auth/forgot-password'], 403);
        }
        if (!$user->isActive()) {
            return errorResponse(ResponseStatusCodes::UNAUTHORIZED, 'Your account is not active. Contact FMDQ for help.', [], 401);
        }

        if (config('eoffering.otp.bypass')) {
            return $this->issueToken($user, $remember);
        }

        $otp->issue($user);

        return successResponse('A sign-in code was sent to your email address.', [
            'challenge' => $this->challengeFor($user, $remember),
            'email' => $this->mask($user->email),
            'ttl' => $otp->ttl(),
        ]);
    }

    public function verifyOtp(Request $request, OtpService $otp, AuditLogger $audit): JsonResponse
    {
        $data = $request->validate(['challenge' => 'required|string', 'code' => 'required|string|max:12']);

        $user = $this->userFromChallenge($data['challenge']);
        if (!$user) {
            return errorResponse(ResponseStatusCodes::UNAUTHORIZED, 'Your sign-in session expired. Sign in again.', [], 401);
        }
        $remember = (bool) (Crypt::decrypt($data['challenge'])['remember'] ?? false);

        $error = $otp->verify($user, $data['code']);
        if ($error !== null) {
            $audit->log('auth.otp_failed', 'Incorrect sign-in code for ' . $user->email, $user, [], $user);

            return errorResponse(ResponseStatusCodes::BAD_REQUEST, $error, ['errors' => ['code' => [$error]]]);
        }

        return $this->issueToken($user, $remember);
    }

    public function resendOtp(Request $request, OtpService $otp): JsonResponse
    {
        $data = $request->validate(['challenge' => 'required|string']);

        $user = $this->userFromChallenge($data['challenge']);
        if (!$user) {
            return errorResponse(ResponseStatusCodes::UNAUTHORIZED, 'Your sign-in session expired. Sign in again.', [], 401);
        }

        $key = 'otp-resend:' . $user->id;
        if (RateLimiter::tooManyAttempts($key, 3)) {
            return errorResponse(429, 'Please wait ' . RateLimiter::availableIn($key) . ' seconds before requesting another code.', [], 429);
        }
        RateLimiter::hit($key, 300);

        $otp->issue($user);

        return successResponse('A new code was sent to your email address.');
    }

    public function forgotPassword(Request $request, OnboardingService $onboarding): JsonResponse
    {
        $data = $request->validate(['email' => 'required|email']);

        $user = User::where('email', $data['email'])->first();
        if ($user && $user->isActive()) {
            $onboarding->sendActivation($user, 'Reset your e-Offering password');
        }

        return successResponse('If that address has an account, a reset link is on its way.');
    }

    public function resetPassword(Request $request, PasswordPolicy $policy): JsonResponse
    {
        $request->validate([
            'token' => 'required|string',
            'email' => 'required|email',
            'password' => ['required', 'confirmed', $policy->rule()],
        ]);

        $status = Password::reset($request->only('email', 'password', 'password_confirmation', 'token'), function (User $user) use ($request, $policy) {
            $policy->change($user, $request->password);
            // The emailed link proves the address belongs to the user.
            $user->forceFill(['email_verified_at' => $user->email_verified_at ?: now()])->save();
            app(AuditLogger::class)->log('auth.password_reset', 'Set password via emailed link', $user, [], $user);
        });

        if ($status !== Password::PASSWORD_RESET) {
            return errorResponse(ResponseStatusCodes::BAD_REQUEST, 'This link is invalid or has expired. Request a new one.');
        }

        return successResponse('Your password was set. You can now sign in.');
    }

    public function me(Request $request): JsonResponse
    {
        return successResponse('Profile fetched.', $this->present($request->user()));
    }

    public function logout(Request $request, AuditLogger $audit): JsonResponse
    {
        $audit->log('auth.logout', 'Signed out', $request->user(), [], $request->user());
        auth('api')->logout();

        return successResponse('Signed out.');
    }

    private function issueToken(User $user, bool $remember = false): JsonResponse
    {
        $user->forceFill(['last_login_at' => now()])->save();
        EnsureNotIdle::touch($user->id);
        app(AuditLogger::class)->log('auth.login', 'Signed in', $user, [], $user);
        $token = auth('api')->setTTL($remember ? self::REMEMBER_TTL_MINUTES : (int) config('jwt.ttl'))->login($user);

        return successResponse('Signed in.', ['accessToken' => $token, 'user' => $this->present($user)]);
    }

    /** Everything the app shell needs: menus, role badge, notification bell, idle timer. */
    private function present(User $user): array
    {
        $institution = $user->institution;

        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'type' => $user->type,
            'accessLevel' => $user->access_level,
            'roleLabel' => $user->roleLabel(),
            'investorCategory' => $user->investorCategory(),
            'institutionId' => $user->institution_id,
            'institution' => $institution ? [
                'id' => $institution->id,
                'name' => $institution->name,
                'type' => $institution->type,
                'regulatorBody' => $institution->regulator_body,
            ] : null,
            'tspFullAccess' => $user->isTsp() && $institution ? app(PlatformFeeService::class)->hasFullAccess($institution) : false,
            'unreadNotifications' => $user->unreadNotifications()->count(),
            'idleTimeout' => EnsureNotIdle::minutes() * 60,
        ];
    }

    private function challengeFor(User $user, bool $remember = false): string
    {
        return Crypt::encrypt(['user_id' => $user->id, 'at' => now()->getTimestamp(), 'remember' => $remember]);
    }

    private function userFromChallenge(string $challenge): ?User
    {
        try {
            $payload = Crypt::decrypt($challenge);
        } catch (DecryptException $e) {
            return null;
        }
        if (!is_array($payload) || now()->getTimestamp() - (int) ($payload['at'] ?? 0) > self::CHALLENGE_MINUTES * 60) {
            return null;
        }

        $user = User::find($payload['user_id'] ?? 0);

        return $user && $user->isActive() ? $user : null;
    }

    private function mask(string $email): string
    {
        [$name, $domain] = array_pad(explode('@', $email, 2), 2, '');

        return substr($name, 0, 2) . str_repeat('*', max(1, strlen($name) - 2)) . '@' . $domain;
    }
}

<?php

namespace App\Services;

use App\Models\OtpCode;
use App\Models\User;
use App\Notifications\PlatformNotification;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

/**
 * Login one-time passwords (CR "Login": OTP before access is granted).
 *
 * Driver "mail" (default) generates the code here, stores only its hash and emails it.
 * Driver "fmdq" delegates generation and validation to the FMDQ OTP master API, the
 * service the legacy platform used, and the code is checked server-side.
 */
class OtpService
{
    public function issue(User $user, string $purpose = 'login'): void
    {
        OtpCode::where('user_id', $user->id)->where('purpose', $purpose)->whereNull('consumed_at')
            ->update(['consumed_at' => now()]);

        if ($this->driver() === 'fmdq') {
            $this->fmdqRequest('generator', ['appID' => config('services.otp.app_id'), 'username' => $user->email]);
            OtpCode::create([
                'user_id' => $user->id, 'purpose' => $purpose, 'code_hash' => 'remote',
                'expires_at' => now()->addMinutes($this->ttl()),
            ]);

            return;
        }

        $length = (int) config('eoffering.otp.length', 6);
        $code = str_pad((string) random_int(0, (10 ** $length) - 1), $length, '0', STR_PAD_LEFT);

        OtpCode::create([
            'user_id' => $user->id,
            'purpose' => $purpose,
            'code_hash' => Hash::make($code),
            'expires_at' => now()->addMinutes($this->ttl()),
        ]);

        $user->notify(new PlatformNotification(
            'Your e-Offering sign-in code',
            "Use this one-time password to finish signing in: {$code}. It expires in {$this->ttl()} minutes. If you did not try to sign in, change your password.",
            null,
            ['mail_only' => true, 'code' => $code]
        ));
    }

    /**
     * @return string|null error message, or null when the code is correct
     */
    public function verify(User $user, string $code, string $purpose = 'login'): ?string
    {
        $otp = OtpCode::where('user_id', $user->id)->where('purpose', $purpose)->whereNull('consumed_at')
            ->latest('id')->first();

        if (! $otp || ! $otp->isUsable()) {
            return 'This code has expired. Request a new one.';
        }
        if ($otp->attempts >= (int) config('eoffering.otp.max_attempts', 5)) {
            return 'Too many incorrect attempts. Request a new code.';
        }

        $ok = $otp->code_hash === 'remote'
            ? $this->fmdqValidate($user, $code)
            : Hash::check(trim($code), $otp->code_hash);

        if (! $ok) {
            $otp->increment('attempts');

            return 'The code is incorrect.';
        }

        $otp->forceFill(['consumed_at' => now()])->save();

        return null;
    }

    public function ttl(): int
    {
        return max(1, (int) config('eoffering.otp.ttl_minutes', 10));
    }

    private function driver(): string
    {
        return (string) config('services.otp.driver', 'mail');
    }

    private function fmdqValidate(User $user, string $code): bool
    {
        try {
            $response = $this->fmdqRequest('validator', [
                'appID' => config('services.otp.app_id'),
                'username' => $user->email,
                'otp' => trim($code),
            ]);
        } catch (Throwable $e) {
            report($e);

            return false;
        }

        $status = strtolower((string) (data_get($response, 'status') ?? data_get($response, 'responseCode') ?? ''));

        return in_array($status, ['success', 'true', '00', '200', 'valid'], true) || data_get($response, 'valid') === true;
    }

    private function fmdqRequest(string $endpoint, array $payload): array
    {
        $url = rtrim((string) config('services.otp.url'), '/');
        if ($url === '') {
            throw new RuntimeException('OTP_URL is not configured.');
        }

        $response = Http::withHeaders([
            'ID' => (string) config('services.otp.app_id'),
            'username' => (string) config('services.otp.username'),
            'password' => (string) config('services.otp.password'),
        ])->asJson()->post($url.'/'.$endpoint, $payload);

        if ($response->failed()) {
            throw new RuntimeException('The OTP service could not be reached.');
        }

        return (array) $response->json();
    }
}

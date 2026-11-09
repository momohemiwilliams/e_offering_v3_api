<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

/** The legacy password rules: complexity, lock on wrong passwords, no reuse, periodic change. */
class PasswordPolicy
{
    public function rule(): Password
    {
        return Password::min(8)->mixedCase()->numbers()->symbols();
    }

    public function lockAfter(): int
    {
        return max(1, (int) config('eoffering.password.lock_after', 3));
    }

    public function registerFailure(User $user): bool
    {
        $failures = $user->failed_logins + 1;
        $locked = $failures >= $this->lockAfter();
        $user->forceFill(['failed_logins' => $failures, 'locked_at' => $locked ? now() : null])->save();

        return $locked;
    }

    public function clearFailures(User $user): void
    {
        if ($user->failed_logins > 0 || $user->locked_at) {
            $user->forceFill(['failed_logins' => 0, 'locked_at' => null])->save();
        }
    }

    public function isExpired(User $user): bool
    {
        $days = (int) config('eoffering.password.max_age_days', 30);
        $since = $user->password_changed_at ?? $user->created_at;

        return $days > 0 && $since !== null && $since->lt(now()->subDays($days));
    }

    public function assertNotReused(User $user, string $plain): void
    {
        $history = (int) config('eoffering.password.history', 10);
        $previous = DB::table('password_histories')->where('user_id', $user->id)->latest('id')->limit($history)->pluck('password');
        if ($user->password) {
            $previous->prepend($user->password);
        }

        foreach ($previous as $hash) {
            if (Hash::check($plain, $hash)) {
                throw ValidationException::withMessages(['password' => "You cannot reuse any of your last {$history} passwords."]);
            }
        }
    }

    /** Sets the new password, keeps the old one in the history and clears any lock. */
    public function change(User $user, string $plain): void
    {
        $this->assertNotReused($user, $plain);

        if ($user->password) {
            DB::table('password_histories')->insert(['user_id' => $user->id, 'password' => $user->password, 'created_at' => now()]);
        }
        $user->forceFill([
            'password' => Hash::make($plain),
            'password_changed_at' => now(),
            'failed_logins' => 0,
            'locked_at' => null,
        ])->save();
    }
}

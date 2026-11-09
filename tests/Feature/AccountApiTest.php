<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureNotIdle;
use App\Models\User;
use App\Notifications\PlatformNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AccountApiTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $email = 'ada@example.com'): User
    {
        return User::create(['type' => 'investor', 'name' => 'Ada', 'email' => $email, 'password' => Hash::make('Password@1'), 'status' => 'active']);
    }

    private function bearer(User $user): self
    {
        return $this->withHeader('Authorization', 'Bearer ' . auth('api')->login($user));
    }

    public function test_me_returns_what_the_app_shell_needs(): void
    {
        $this->bearer($this->user())->getJson('/api/auth/me')->assertOk()
            ->assertJsonStructure(['data' => ['roleLabel', 'institution', 'tspFullAccess', 'unreadNotifications', 'idleTimeout']]);
    }

    public function test_idle_user_is_signed_out_by_the_server(): void
    {
        config(['session.idle_lifetime' => 5]);
        $user = $this->user();
        EnsureNotIdle::touch($user->id);
        $token = auth('api')->setTTL(100000)->login($user);

        Carbon::setTestNow(now()->addMinutes(6));

        $this->withHeader('Authorization', "Bearer $token")->getJson('/api/session/ping')->assertStatus(401);
        Carbon::setTestNow();
    }

    public function test_activity_keeps_the_user_signed_in(): void
    {
        config(['session.idle_lifetime' => 5]);
        $user = $this->user();
        EnsureNotIdle::touch($user->id);
        $token = auth('api')->setTTL(100000)->login($user);

        Carbon::setTestNow(now()->addMinutes(4));
        $this->withHeader('Authorization', "Bearer $token")->getJson('/api/session/ping')->assertStatus(204);

        Carbon::setTestNow(now()->addMinutes(4));
        $this->withHeader('Authorization', "Bearer $token")->getJson('/api/session/ping')->assertStatus(204);
        Carbon::setTestNow();
    }

    public function test_profile_email_must_be_unique(): void
    {
        $this->user('taken@example.com');

        $this->bearer($this->user())->patchJson('/api/settings/profile', ['name' => 'Ada', 'email' => 'taken@example.com'])
            ->assertStatus(422)->assertJsonValidationErrors('email');
    }

    public function test_password_change_needs_the_current_password(): void
    {
        $user = $this->user();

        $this->bearer($user)->putJson('/api/settings/password', ['current_password' => 'wrong', 'password' => 'Str0ng!Pass#21', 'password_confirmation' => 'Str0ng!Pass#21'])
            ->assertStatus(422)->assertJsonValidationErrors('current_password');

        $this->putJson('/api/settings/password', ['current_password' => 'Password@1', 'password' => 'Str0ng!Pass#21', 'password_confirmation' => 'Str0ng!Pass#21'])->assertOk();

        $this->assertTrue(Hash::check('Str0ng!Pass#21', $user->fresh()->password));
    }

    public function test_a_user_cannot_read_someone_elses_notification(): void
    {
        $owner = $this->user('owner@example.com');
        $owner->notifyNow(new PlatformNotification('Hello', 'Body', null, []), ['database']);
        $id = $owner->notifications()->first()->id;

        $this->bearer($this->user())->postJson("/api/notifications/{$id}/read")->assertStatus(404);
    }

    public function test_remember_me_issues_a_longer_token(): void
    {
        $this->user();
        config(['eoffering.otp.bypass' => true]);

        $short = $this->postJson('/api/auth/login', ['email' => 'ada@example.com', 'password' => 'Password@1'])->json('data.accessToken');
        $long = $this->postJson('/api/auth/login', ['email' => 'ada@example.com', 'password' => 'Password@1', 'remember' => true])->json('data.accessToken');

        $exp = function (string $token) {
            return json_decode(base64_decode(strtr(explode('.', $token)[1], '-_', '+/')), true)['exp'];
        };

        $this->assertGreaterThan($exp($short), $exp($long));
    }
}

<?php

namespace Tests\Feature;

use App\Models\OtpCode;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AuthOtpTest extends TestCase
{
    use RefreshDatabase;

    private function user(array $attrs = []): User
    {
        return User::create($attrs + [
            'type' => 'investor', 'name' => 'Ada', 'email' => 'ada@example.com',
            'password' => Hash::make('Password@1'), 'status' => 'active',
        ]);
    }

    private function challenge(): string
    {
        return $this->postJson('/api/auth/login', ['email' => 'ada@example.com', 'password' => 'Password@1'])
            ->assertOk()->json('data.challenge');
    }

    private function knownCode(User $user): void
    {
        OtpCode::where('user_id', $user->id)->latest('id')->first()->forceFill(['code_hash' => Hash::make('123456')])->save();
    }

    public function test_password_alone_gives_no_token(): void
    {
        $this->user();
        $response = $this->postJson('/api/auth/login', ['email' => 'ada@example.com', 'password' => 'Password@1'])->assertOk();
        $this->assertNull($response->json('data.accessToken'));
    }

    public function test_wrong_password_is_rejected(): void
    {
        $this->user();
        $this->postJson('/api/auth/login', ['email' => 'ada@example.com', 'password' => 'nope'])->assertStatus(401);
    }

    public function test_correct_code_issues_a_working_token(): void
    {
        $user = $this->user();
        $challenge = $this->challenge();
        $this->knownCode($user);

        $token = $this->postJson('/api/auth/otp', ['challenge' => $challenge, 'code' => '123456'])->assertOk()->json('data.accessToken');

        $this->withHeader('Authorization', "Bearer $token")->getJson('/api/auth/me')->assertOk()->assertJsonPath('data.email', 'ada@example.com');
    }

    public function test_wrong_code_gives_no_token(): void
    {
        $user = $this->user();
        $challenge = $this->challenge();
        $this->knownCode($user);

        $response = $this->postJson('/api/auth/otp', ['challenge' => $challenge, 'code' => '000000'])->assertStatus(422);
        $this->assertNull($response->json('data.accessToken'));
    }

    public function test_forged_challenge_is_rejected(): void
    {
        $this->postJson('/api/auth/otp', ['challenge' => 'forged', 'code' => '123456'])->assertStatus(401);
    }

    public function test_disabled_user_token_stops_working(): void
    {
        $user = $this->user();
        $token = auth('api')->login($user);
        $user->forceFill(['status' => 'disabled'])->save();

        $this->withHeader('Authorization', "Bearer $token")->getJson('/api/auth/me')->assertStatus(401);
    }

    public function test_protected_route_needs_a_token(): void
    {
        $this->getJson('/api/auth/me')->assertStatus(401);
    }
}

<?php

namespace Tests\Feature;

use App\Models\Bid;
use App\Models\Offer;
use App\Models\OfferNote;
use App\Models\User;
use App\Models\UserChangeRequest;
use App\Notifications\PlatformNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Tests\Concerns\BuildsPlatform;
use Tests\TestCase;

class LegacyControlsTest extends TestCase
{
    use BuildsPlatform, RefreshDatabase;

    private const GOOD = 'Str0ng!Pass#21';

    private function member(string $type = 'investor', string $email = 'ada@example.com'): User
    {
        return User::create(['type' => $type, 'name' => 'Ada', 'email' => $email, 'password' => Hash::make('Password@1'), 'status' => 'active', 'password_changed_at' => now()]);
    }

    private function bearer(User $user): self
    {
        return $this->withHeader('Authorization', 'Bearer ' . auth('api')->login($user));
    }

    private function attempt(string $password)
    {
        return $this->postJson('/api/auth/login', ['email' => 'ada@example.com', 'password' => $password]);
    }

    private function offer(string $status = Offer::LIVE): Offer
    {
        $tsp = $this->tspInstitution();
        $inputter = $this->tspUser($tsp, User::INPUTTER);
        $payload = array_diff_key($this->offerPayload(['offer_size' => 1000000, 'min_subscription_amount' => 100000, 'subsequent_increment' => 50000]), ['receiving_agents' => 1, 'co_sponsors' => 1]);

        $offer = new Offer();
        $offer->forceFill($payload + ['reference' => 'OFF-' . uniqid(), 'institution_id' => $tsp->id, 'created_by' => $inputter->id, 'status' => $status])->save();

        return $offer;
    }

    private function bid(Offer $offer, User $investor, array $attrs = []): Bid
    {
        $bid = new Bid();
        $bid->forceFill($attrs + [
            'reference' => 'BID-' . uniqid(), 'offer_id' => $offer->id, 'user_id' => $investor->id, 'investor_category' => 'retail',
            'investor_type' => 'hni', 'investor_name' => 'Ada', 'amount' => 100000, 'payee_type' => 'sponsor', 'payee_name' => 'Lead',
            'submitted_at' => now(), 'status' => Bid::ACTIVE, 'payment_status' => 'pending',
        ])->save();

        return $bid;
    }

    public function test_a_mail_outage_gives_a_clear_message_at_sign_in(): void
    {
        $this->member();
        $this->mock(\App\Services\OtpService::class)->shouldReceive('issue')->andThrow(new \Symfony\Component\Mailer\Exception\TransportException('Connection could not be established with host "mail.internal"'));

        $response = $this->attempt('Password@1')->assertStatus(503);

        $this->assertStringNotContainsString('mail.internal', $response->getContent());
        $this->assertNull($response->json('data.accessToken'));
    }

    public function test_a_mail_outage_does_not_undo_an_onboarding_approval(): void
    {
        config(['mail.default' => 'smtp', 'mail.mailers.smtp.host' => '127.0.0.1', 'mail.mailers.smtp.port' => 1]);
        $institution = \App\Models\Institution::create(['type' => 'regulator', 'regulator_body' => 'SEC', 'name' => 'SEC', 'email' => 's@sec.test']);
        $reviewer = $institution->users()->create(['type' => 'regulator', 'name' => 'Rev', 'email' => 'rev@sec.test', 'status' => 'pending']);

        $this->bearer($this->member('admin', 'a@fmdq.test'))->postJson("/api/admin/onboarding/{$institution->id}/approve")->assertOk();

        $this->assertSame('active', $reviewer->fresh()->status);
    }

    public function test_three_wrong_passwords_lock_the_account_even_for_the_right_password(): void
    {
        $this->member();
        $this->attempt('wrong')->assertStatus(401);
        $this->attempt('wrong')->assertStatus(401);
        $this->attempt('wrong')->assertStatus(401);

        $this->assertNotNull(User::first()->locked_at);
        $this->attempt('Password@1')->assertStatus(401)->assertJsonPath('message', fn ($m) => str_contains($m, 'locked'));
    }

    public function test_a_correct_password_resets_the_failure_count(): void
    {
        $this->member();
        config(['eoffering.otp.bypass' => true]);
        $this->attempt('wrong');
        $this->attempt('wrong');
        $this->attempt('Password@1')->assertOk();

        $this->assertSame(0, User::first()->failed_logins);
    }

    public function test_resetting_the_password_unlocks_the_account(): void
    {
        $user = $this->member();
        $user->forceFill(['failed_logins' => 3, 'locked_at' => now()])->save();

        $this->postJson('/api/auth/reset-password', ['token' => Password::broker()->createToken($user), 'email' => $user->email, 'password' => self::GOOD, 'password_confirmation' => self::GOOD])->assertOk();

        $this->assertNull($user->fresh()->locked_at);
        $this->assertTrue(Hash::check(self::GOOD, $user->fresh()->password));
    }

    public function test_recent_passwords_cannot_be_reused(): void
    {
        $user = $this->member();
        $this->bearer($user)->putJson('/api/settings/password', ['current_password' => 'Password@1', 'password' => self::GOOD, 'password_confirmation' => self::GOOD])->assertOk();

        $this->putJson('/api/settings/password', ['current_password' => self::GOOD, 'password' => 'Password@1', 'password_confirmation' => 'Password@1'])
            ->assertStatus(422)->assertJsonValidationErrors('password');
    }

    public function test_weak_passwords_are_refused(): void
    {
        $this->bearer($this->member())->putJson('/api/settings/password', ['current_password' => 'Password@1', 'password' => 'alllowercase', 'password_confirmation' => 'alllowercase'])
            ->assertStatus(422)->assertJsonValidationErrors('password');
    }

    public function test_an_expired_password_blocks_sign_in_and_points_to_reset(): void
    {
        $user = $this->member();
        $user->forceFill(['password_changed_at' => now()->subDays(31)])->save();

        $this->attempt('Password@1')->assertStatus(403)->assertJsonPath('data.redirect', '/auth/forgot-password');
    }

    public function test_a_new_staff_account_waits_for_a_second_admin(): void
    {
        Notification::fake();
        $maker = $this->member('admin', 'maker@fmdq.test');
        $checker = $this->member('admin', 'checker@fmdq.test');

        $this->bearer($maker)->postJson('/api/admin/users', ['name' => 'New', 'email' => 'new@fmdq.test', 'type' => 'admin'])->assertOk();
        $new = User::where('email', 'new@fmdq.test')->first();
        $this->assertSame('pending', $new->status);
        $request = UserChangeRequest::first();

        $this->postJson("/api/admin/user-requests/{$request->id}/approve")->assertStatus(422);
        $this->assertSame('pending', $new->fresh()->status);

        $this->bearer($checker)->postJson("/api/admin/user-requests/{$request->id}/approve")->assertOk();
        $this->assertSame('active', $new->fresh()->status);
        Notification::assertSentTo($new, PlatformNotification::class);
    }

    public function test_disabling_a_user_needs_a_second_admin_and_can_be_rejected(): void
    {
        $maker = $this->member('admin', 'maker@fmdq.test');
        $checker = $this->member('admin', 'checker@fmdq.test');
        $target = $this->member('investor', 'target@x.test');

        $this->bearer($maker)->postJson("/api/admin/users/{$target->id}/status")->assertOk();
        $this->assertSame('active', $target->fresh()->status, 'unchanged until approved');
        $this->postJson("/api/admin/users/{$target->id}/status")->assertStatus(422);

        $request = UserChangeRequest::first();
        $this->bearer($checker)->postJson("/api/admin/user-requests/{$request->id}/reject", ['comment' => 'No basis'])->assertOk();
        $this->assertSame('active', $target->fresh()->status);
    }

    public function test_rejecting_a_new_staff_account_removes_it(): void
    {
        $maker = $this->member('admin', 'maker@fmdq.test');
        $checker = $this->member('admin', 'checker@fmdq.test');
        $this->bearer($maker)->postJson('/api/admin/users', ['name' => 'New', 'email' => 'new@fmdq.test', 'type' => 'csd'])->assertOk();

        $this->bearer($checker)->postJson('/api/admin/user-requests/' . UserChangeRequest::first()->id . '/reject', ['comment' => 'Duplicate'])->assertOk();

        $this->assertNull(User::where('email', 'new@fmdq.test')->first());
    }

    public function test_payment_reminders_follow_twelve_hour_marks_and_send_once_each(): void
    {
        Notification::fake();
        $investor = $this->retailInvestor();
        $bid = $this->bid($this->offer(), $investor);

        $this->artisan('payments:remind');
        Notification::assertNothingSent();

        Carbon::setTestNow(now()->addHours(13));
        $this->artisan('payments:remind');
        $this->artisan('payments:remind');
        Notification::assertSentToTimes($investor, PlatformNotification::class, 1);
        $this->assertSame(1, $bid->fresh()->payment_reminders_sent);
        Carbon::setTestNow();
    }

    public function test_no_reminder_once_evidence_is_uploaded(): void
    {
        Notification::fake();
        $this->bid($this->offer(), $this->retailInvestor(), ['payment_evidence_path' => 'x.pdf', 'created_at' => now()->subDays(2)]);

        $this->artisan('payments:remind');

        Notification::assertNothingSent();
    }

    public function test_public_offer_page_shows_live_offers_without_internal_fields(): void
    {
        $offer = $this->offer();

        $response = $this->getJson("/api/offers/{$offer->reference}")->assertOk()->assertJsonPath('data.offer.reference', $offer->reference);
        $this->assertArrayNotHasKey('bank_account_number', $response->json('data.offer'));
        $this->assertArrayNotHasKey('created_by', $response->json('data.offer'));

        $draft = $this->offer('draft');
        $this->getJson("/api/offers/{$draft->reference}")->assertStatus(404);
    }

    public function test_only_admins_can_add_commitment_notes(): void
    {
        $offer = $this->offer();
        $this->bearer($this->member('admin', 'a@fmdq.test'))->postJson("/api/admin/offers/{$offer->id}/notes", ['title' => 'Call', 'body' => 'Spoke to sponsor'])->assertOk();
        $this->assertSame(1, OfferNote::count());
        $this->getJson("/api/admin/offers/{$offer->id}")->assertOk()->assertJsonPath('data.notes.0.title', 'Call');

        $this->bearer($this->retailInvestor())->postJson("/api/admin/offers/{$offer->id}/notes", ['title' => 'x', 'body' => 'y'])->assertStatus(403);
    }

    public function test_investors_see_only_their_own_audit_entries(): void
    {
        $me = $this->retailInvestor();
        $other = $this->retailInvestor();
        app(\App\Services\AuditLogger::class)->log('a.mine', 'Mine', null, [], $me);
        app(\App\Services\AuditLogger::class)->log('a.other', 'Other', null, [], $other);

        $logs = $this->bearer($me)->getJson('/api/investor/audit-trail')->assertOk()->json('data.logs.data');

        $this->assertSame(['Mine'], array_column($logs, 'description'));
    }
}

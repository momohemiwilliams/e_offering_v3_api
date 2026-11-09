<?php

namespace Tests\Feature;

use App\Models\Allotment;
use App\Models\Bid;
use App\Models\Institution;
use App\Models\Offer;
use App\Models\User;
use App\Services\Allotment\AllotmentService;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DemoOfferSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Every screen renders for its role with realistic data (no 500s, right component).
 */
class PageSmokeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $this->seed(DatabaseSeeder::class);
        $this->seed(DemoOfferSeeder::class);
    }

    private function page(User $user, string $url, string $component): void
    {
        $this->actingAs($user)->getJson($url)->assertOk();
        $this->assertFileExists(base_path('../web/src/views/' . $component . '.tsx'));
    }

    private function user(string $email): User
    {
        return User::where('email', $email)->firstOrFail();
    }

    public function test_public_pages_render(): void
    {
        foreach (['/api/public/welcome', '/api/onboarding/account-types'] as $url) {
            $this->getJson($url)->assertOk();
        }
    }

    public function test_admin_pages_render(): void
    {
        $admin = $this->user('admin@example.com');
        $offer = Offer::firstOrFail();
        $institution = Institution::where('type', 'tsp')->firstOrFail();

        $this->page($admin, route('admin.dashboard'), 'admin/dashboard');
        $this->page($admin, route('admin.onboarding.index', ['status' => 'all']), 'admin/onboarding/index');
        $this->page($admin, route('admin.onboarding.show', $institution), 'admin/onboarding/show');
        $this->page($admin, route('admin.users.index'), 'admin/users/index');
        $this->page($admin, route('admin.fees.index'), 'admin/fees/index');
        $this->page($admin, route('admin.settings'), 'admin/settings');
        $this->page($admin, route('admin.offers.index'), 'admin/offers/index');
        $this->page($admin, route('admin.offers.show', $offer), 'admin/offers/show');
        $this->page($admin, route('admin.audit'), 'audit/index');
        $this->page($admin, route('csd.lodgements.index'), 'csd/lodgements/index');
        $this->page($admin, route('notifications.index'), 'notifications/index');
    }

    public function test_tsp_pages_render_through_allotment(): void
    {
        $inputter = $this->user('inputter@example.com');
        $authoriser = $this->user('authoriser@example.com');
        $open = Offer::where('close_at', '>', now())->firstOrFail();
        $closed = Offer::where('close_at', '<', now())->firstOrFail();

        $this->page($inputter, route('tsp.dashboard'), 'tsp/dashboard');
        $this->page($authoriser, route('tsp.dashboard'), 'tsp/dashboard');
        $this->page($inputter, route('tsp.registration.show'), 'tsp/registration');
        $this->page($inputter, route('tsp.offers.index'), 'tsp/offers/index');
        $this->page($inputter, route('tsp.offers.create'), 'tsp/offers/form');
        $this->page($inputter, route('tsp.offers.show', $open), 'tsp/offers/show');
        $this->page($inputter, route('tsp.offers.amend', $open), 'tsp/offers/amend');
        $this->page($inputter, route('tsp.order-book.index'), 'tsp/order-book/index');
        $this->page($inputter, route('tsp.order-book.show', $open), 'tsp/order-book/show');
        $this->page($inputter, route('tsp.allotments.index'), 'tsp/allotments/index');
        $this->page($inputter, route('tsp.allotments.show', $closed), 'tsp/allotments/show');

        app(AllotmentService::class)->initiate($closed, $inputter);
        $this->page($inputter, route('tsp.allotments.show', $closed), 'tsp/allotments/show');
        $this->page($inputter, route('tsp.reports.index'), 'tsp/reports/index');
        $this->page($inputter, route('tsp.reports.show', $closed), 'tsp/reports/show');
        $this->page($inputter, route('tsp.reports.exceptions'), 'tsp/reports/exceptions');
        $this->page($inputter, route('tsp.audit'), 'audit/index');
        $this->actingAs($inputter)->get(route('tsp.order-book.export', $open))->assertOk();
        $this->actingAs($inputter)->get(route('tsp.allotments.export', $closed))->assertOk();
    }

    public function test_investor_regulator_and_csd_pages_render(): void
    {
        $retail = $this->user('retail@example.com');
        $qii = $this->user('qii@example.com');
        $open = Offer::where('close_at', '>', now())->firstOrFail();
        $bid = Bid::where('user_id', $retail->id)->where('offer_id', $open->id)->firstOrFail();

        foreach ([$retail, $qii] as $investor) {
            $this->page($investor, route('investor.dashboard'), 'investor/dashboard');
            $this->page($investor, route('investor.offers.index'), 'investor/offers/index');
            $this->page($investor, route('investor.offers.show', $open), 'investor/offers/show');
            $this->page($investor, route('investor.bids.create', $open), 'investor/bids/form');
            $this->page($investor, route('investor.history'), 'investor/bids/index');
        }
        $this->page($retail, route('investor.bids.show', $bid), 'investor/bids/show');
        $this->page($retail, route('investor.bids.edit', $bid), 'investor/bids/form');

        // Put the closed offer's allotment in front of SEC, then the CSD.
        $closed = Offer::where('close_at', '<', now())->firstOrFail();
        $allotment = app(AllotmentService::class)->initiate($closed, $this->user('inputter@example.com'));
        app(AllotmentService::class)->configure($allotment, $this->user('inputter@example.com'), ['method' => 'pro_rata', 'pro_rata_option' => 3], 18.75);
        $allotment->forceFill(['status' => Allotment::SEC_PENDING])->save();

        $sec = $this->user('sec@example.com');
        $this->page($sec, route('regulator.dashboard'), 'regulator/dashboard');
        $this->page($sec, route('regulator.allotments.show', $allotment), 'regulator/allotments/show');

        $allotment->forceFill(['status' => Allotment::APPROVED])->save();
        $csd = $this->user('csd@example.com');
        $this->page($csd, route('csd.lodgements.index'), 'csd/lodgements/index');
        $this->page($csd, route('csd.lodgements.show', $allotment), 'csd/lodgements/show');
    }
}

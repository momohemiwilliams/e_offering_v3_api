<?php

namespace Tests\Feature;

use App\Models\Allotment;
use App\Models\Bid;
use App\Models\Offer;
use App\Models\OfferAmendment;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsPlatform;
use Tests\TestCase;

/**
 * Offer creation -> approval -> launch -> bids -> book building -> allotment -> SEC -> CSD.
 */
class OfferLifecycleTest extends TestCase
{
    use BuildsPlatform;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withHeaders(['Accept' => 'application/json']);
        Storage::fake('documents');
        Notification::fake();
    }

    private function createLaunchedOffer(User $inputter, User $authoriser, array $overrides = []): Offer
    {
        $this->actingAs($inputter)->post(route('tsp.offers.store'), $this->offerPayload($overrides))->assertSuccessful();
        $offer = Offer::latest('id')->firstOrFail();

        foreach (config('eoffering.security_types.'.$offer->security_type.'.documents') as $type) {
            $this->post(route('tsp.offers.documents.store', $offer), [
                'document_type' => $type,
                'file' => UploadedFile::fake()->create('doc.pdf', 20, 'application/pdf'),
            ])->assertSuccessful();
        }

        $this->post(route('tsp.offers.submit', $offer))->assertSuccessful();
        $this->assertSame(Offer::PENDING_AUTHORISATION, $offer->fresh()->status);

        $this->actingAs($authoriser)->post(route('tsp.offers.authorise', $offer), ['approve' => true])->assertSuccessful();
        $this->assertSame(Offer::APPROVED, $offer->fresh()->status, 'FMDQ admin review is disabled by default (s4.4)');

        $this->actingAs($inputter)->post(route('tsp.offers.launch', $offer))->assertSuccessful();
        $this->assertSame(Offer::LIVE, $offer->fresh()->status);

        return $offer->fresh();
    }

    private function bid(User $investor, Offer $offer, array $data = []): Bid
    {
        $this->actingAs($investor)->post(route('investor.bids.store', $offer), array_merge([
            'investor_type' => $investor->institution ? 'pfa' : 'individual',
            'amount' => '500,000',
            'rate' => 18.0,
            'csd_name' => 'CSCS',
            'csd_account_number' => 'C123',
            'bank_name' => 'Demo Bank',
            'bank_account_name' => 'Investor',
            'bank_account_number' => '1234567890',
            'payee_type' => 'sponsor',
            'declaration' => true,
            'evidence' => UploadedFile::fake()->create('proof.pdf', 10, 'application/pdf'),
        ], $data))->assertSuccessful();

        return Bid::latest('id')->firstOrFail();
    }

    public function test_full_offer_lifecycle(): void
    {
        $tsp = $this->tspInstitution();
        $inputter = $this->tspUser($tsp, User::INPUTTER);
        $authoriser = $this->tspUser($tsp, User::AUTHORISER);
        $regulator = $this->regulator();

        $offer = $this->createLaunchedOffer($inputter, $authoriser);

        // Bidding opens tomorrow.
        $this->travel(2)->days();
        $retail = $this->retailInvestor();
        $qii = $this->qiiInvestor();

        $b1 = $this->bid($retail, $offer, ['amount' => '600000', 'rate' => 17.5]);
        $b2 = $this->bid($qii, $offer, ['amount' => '800000', 'rate' => 18.0]);

        // Below minimum and wrong increment are refused (s6.1 step 2).
        $this->actingAs($retail)->post(route('investor.bids.store', $offer), [
            'investor_type' => 'individual', 'amount' => '50000', 'rate' => 18, 'csd_name' => 'CSCS', 'csd_account_number' => 'C1',
            'bank_name' => 'B', 'bank_account_name' => 'N', 'bank_account_number' => '1234567890', 'payee_type' => 'sponsor', 'declaration' => true,
        ])->assertJsonValidationErrors('amount');

        // Investor amends then the bid shows in the order book immediately with Pending payment.
        $this->actingAs($retail)->put(route('investor.bids.update', $b1), [
            'investor_type' => 'individual', 'amount' => '700000', 'rate' => 17.5, 'csd_name' => 'CSCS', 'csd_account_number' => 'C123',
            'bank_name' => 'Demo Bank', 'bank_account_name' => 'Investor', 'bank_account_number' => '1234567890', 'payee_type' => 'sponsor', 'declaration' => true,
        ])->assertSuccessful();
        $this->assertSame('700000.00', $b1->fresh()->amount);
        $this->assertSame(Bid::PAYMENT_PENDING, $b1->fresh()->payment_status);
        $this->assertDatabaseHas('bid_events', ['bid_id' => $b1->id, 'action' => 'amended']);

        $this->actingAs($inputter)->get(route('tsp.order-book.show', $offer))->assertOk();

        // Sponsor validates payment evidence; Invalid requires a comment.
        $this->post(route('tsp.bids.payment', $b2), ['valid' => false])->assertJsonValidationErrors('comment');
        $this->post(route('tsp.bids.payment', $b1), ['valid' => true])->assertSuccessful();
        $this->post(route('tsp.bids.payment', $b2), ['valid' => true])->assertSuccessful();
        $this->assertSame(Bid::PAYMENT_PAID, $b1->fresh()->payment_status);

        // Cannot initiate before close.
        $this->post(route('tsp.allotments.initiate', $offer))->assertJsonValidationErrors('offer');

        $this->travel(5)->days();
        $this->post(route('tsp.allotments.initiate', $offer))->assertSuccessful();
        $allotment = $offer->fresh()->allotment;
        $this->assertSame(Allotment::OVERSUBSCRIBED, $allotment->subscription_status, '1.5m demand for a 1m offer');

        // Competitive bid: final rate + rate-based pro-rata (Option 2).
        $this->put(route('tsp.allotments.configure', $offer), [
            'method' => 'pro_rata', 'pro_rata_option' => 2, 'final_rate' => 18.0,
        ])->assertSuccessful();
        $allotment->refresh();
        $this->assertSame('1000000.00', $allotment->total_allotted);
        $this->assertSame('700000.00', $allotment->items()->where('bid_id', $b1->id)->value('allotted'), 'below final rate: 100%');
        $this->assertSame('300000.00', $allotment->items()->where('bid_id', $b2->id)->value('allotted'), 'at final rate: residual');

        // Manual override needs a reason; recalculates the rest.
        $item = $allotment->items()->where('bid_id', $b1->id)->first();
        $this->post(route('tsp.allotments.override', $item), ['amount' => 600000, 'reason' => ''])->assertJsonValidationErrors('reason');
        $this->post(route('tsp.allotments.override', $item), ['amount' => 600000, 'reason' => 'Issuer request'])->assertSuccessful();
        $this->assertSame('400000.00', $allotment->items()->where('bid_id', $b2->id)->value('allotted'));

        $this->post(route('tsp.allotments.documents', $offer), [
            'document_type' => 'Letter from Sponsor',
            'file' => UploadedFile::fake()->create('letter.pdf', 10, 'application/pdf'),
        ])->assertSuccessful();

        $this->post(route('tsp.allotments.submit', $offer))->assertSuccessful();
        $this->assertSame(Allotment::PENDING_AUTHORISATION, $allotment->fresh()->status);

        // Inputter cannot authorise; authoriser returns, inputter resubmits, authoriser approves.
        $this->post(route('tsp.allotments.authorise', $offer), ['approve' => true])->assertForbidden();
        $this->actingAs($authoriser)->post(route('tsp.allotments.authorise', $offer), ['approve' => false, 'reason' => 'Check the override'])->assertSuccessful();
        $this->assertSame(Allotment::RETURNED, $allotment->fresh()->status);
        $this->actingAs($inputter)->post(route('tsp.allotments.submit', $offer))->assertSuccessful();
        $this->actingAs($authoriser)->post(route('tsp.allotments.authorise', $offer), ['approve' => true])->assertSuccessful();
        $this->assertSame(Allotment::SEC_PENDING, $allotment->fresh()->status);

        // SEC review.
        $this->actingAs($regulator)->get(route('regulator.allotments.show', $allotment))->assertOk();
        $this->post(route('regulator.allotments.decide', $allotment), ['approve' => false])->assertJsonValidationErrors('reason');
        $this->post(route('regulator.allotments.decide', $allotment), ['approve' => true])->assertSuccessful();
        $this->assertSame(Allotment::APPROVED, $allotment->fresh()->status);

        // CSD lodgement.
        $this->actingAs($inputter)->post(route('tsp.allotments.lodge', $offer))->assertSuccessful();
        $allotment->refresh();
        $this->assertSame(Allotment::LODGED, $allotment->status);
        $this->assertNotNull($allotment->lodgement_reference);
        $this->get(route('tsp.allotments.export', [$offer, 'type' => 'lodgement']))->assertOk();

        // Exception reporting picks up the override and the authoriser's return.
        $this->get(route('tsp.reports.show', $offer))->assertOk();
        $this->assertDatabaseHas('workflow_actions', ['offer_id' => $offer->id, 'stage' => 'tsp_authoriser', 'decision' => 'rejected']);
        $this->assertDatabaseHas('audit_logs', ['offer_id' => $offer->id, 'action' => 'allotment.lodged']);
    }

    public function test_post_launch_amendment_is_approved_audited_and_applied(): void
    {
        $tsp = $this->tspInstitution();
        $inputter = $this->tspUser($tsp, User::INPUTTER);
        $authoriser = $this->tspUser($tsp, User::AUTHORISER);
        $offer = $this->createLaunchedOffer($inputter, $authoriser);
        $newClose = now()->addDays(9)->format('Y-m-d H:i:s');

        $this->actingAs($inputter)->post(route('tsp.offers.amendments.store', $offer), [
            'reason' => 'Extend the book',
            'changes' => ['close_at' => $newClose, 'min_subscription_amount' => '150000'],
        ])->assertSuccessful();
        $amendment = OfferAmendment::firstOrFail();
        $this->assertSame($offer->close_at->format('Y-m-d H:i:s'), $amendment->changes['close_at']['from']);

        $this->actingAs($authoriser)->post(route('tsp.amendments.review', $amendment), ['approve' => true])->assertSuccessful();
        $offer->refresh();
        $this->assertSame($newClose, $offer->close_at->format('Y-m-d H:i:s'));
        $this->assertSame('150000.00', $offer->min_subscription_amount, 'the minimum bid follows the amendment (s6.1)');
        $this->assertDatabaseHas('audit_logs', ['offer_id' => $offer->id, 'action' => 'offer.amended']);
    }

    public function test_fmdq_admin_review_gate_applies_when_enabled(): void
    {
        Setting::put('fmdq_admin_review_enabled', true);
        $tsp = $this->tspInstitution();
        $inputter = $this->tspUser($tsp, User::INPUTTER);
        $authoriser = $this->tspUser($tsp, User::AUTHORISER);
        $admin = User::factory()->create();

        $this->actingAs($inputter)->post(route('tsp.offers.store'), $this->offerPayload())->assertSuccessful();
        $offer = Offer::firstOrFail();
        foreach (config('eoffering.security_types.commercial_papers.documents') as $type) {
            $this->post(route('tsp.offers.documents.store', $offer), ['document_type' => $type, 'file' => UploadedFile::fake()->create('d.pdf', 5, 'application/pdf')]);
        }
        $this->post(route('tsp.offers.submit', $offer))->assertSuccessful();
        $this->actingAs($authoriser)->post(route('tsp.offers.authorise', $offer), ['approve' => true]);
        $this->assertSame(Offer::PENDING_ADMIN_REVIEW, $offer->fresh()->status);

        $this->actingAs($admin)->post(route('admin.offers.review', $offer), ['approve' => false, 'reason' => 'Missing rating'])->assertSuccessful();
        $this->assertSame(Offer::RETURNED, $offer->fresh()->status);
    }

    public function test_offer_needs_required_documents_before_submission(): void
    {
        $tsp = $this->tspInstitution();
        $inputter = $this->tspUser($tsp, User::INPUTTER);
        $this->actingAs($inputter)->post(route('tsp.offers.store'), $this->offerPayload())->assertSuccessful();

        $this->post(route('tsp.offers.submit', Offer::firstOrFail()))->assertJsonValidationErrors('documents');
    }

    public function test_roles_are_enforced_on_routes(): void
    {
        $tsp = $this->tspInstitution();
        $authoriser = $this->tspUser($tsp, User::AUTHORISER);
        $investor = $this->retailInvestor();

        $this->actingAs($authoriser)->get(route('tsp.offers.create'))->assertForbidden();
        $this->actingAs($investor)->get(route('admin.dashboard'))->assertForbidden();
        $this->actingAs($investor)->get(route('tsp.dashboard'))->assertForbidden();
    }

    public function test_tsp_without_registration_lands_on_the_registration_module(): void
    {
        $tsp = $this->tspInstitution(['registration_completed_at' => null]);
        $inputter = $this->tspUser($tsp, User::INPUTTER);

        $this->actingAs($inputter)->get(route('tsp.dashboard'))->assertForbidden()->assertJsonPath('data.redirect', '/tsp/registration');
        $this->put(route('tsp.registration.update'), [
            'registered_name' => 'Lead Securities', 'registered_address' => 'Lagos', 'contact_name' => 'Ada',
            'contact_role' => 'MD', 'contact_phone' => '0800',
        ])->assertSuccessful();
        $this->get(route('tsp.dashboard'))->assertOk();
    }

    public function test_annual_fee_blocks_access_until_admin_confirms_payment(): void
    {
        Setting::put('platform_fee_enabled', true);
        Setting::put('platform_fee_amount', 250000);
        $tsp = $this->tspInstitution();
        $inputter = $this->tspUser($tsp, User::INPUTTER);
        $admin = User::factory()->create();

        $this->actingAs($inputter)->get(route('tsp.dashboard'))->assertForbidden()->assertJsonPath('data.redirect', '/tsp/registration');
        $this->get(route('tsp.registration.show'))->assertOk();
        $invoice = $tsp->feeInvoices()->firstOrFail();

        $this->post(route('tsp.registration.transfer', $invoice), [
            'evidence' => UploadedFile::fake()->create('transfer.pdf', 10, 'application/pdf'),
            'reference' => 'TRF123',
        ])->assertSuccessful();

        $this->actingAs($admin)->post(route('admin.fees.approve', $invoice))->assertSuccessful();
        // fresh(): the in-memory $inputter still caches the institution as it was before approval.
        $this->actingAs($inputter->fresh())->get(route('tsp.dashboard'))->assertOk();
        $this->get(route('tsp.registration.receipt', $invoice))->assertOk();
    }
}

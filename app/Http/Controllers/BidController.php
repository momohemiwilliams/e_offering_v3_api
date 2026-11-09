<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use App\Http\Requests\BidRequest;
use App\Models\Allotment;
use App\Models\Bid;
use App\Models\Offer;
use App\Services\BidService;
use App\Support\Access;
use Illuminate\Http\Request;

/**
 * Commitment form: submit, amend, revoke, and upload evidence of payment (CR s6.1-6.2).
 * No OTP at submission: the login OTP replaces it (s6.1).
 */
class BidController extends Controller
{
    private BidService $bids;

    public function __construct(BidService $bids)
    {
        $this->bids = $bids;
    }

    public function create(Request $request, Offer $offer): JsonResponse
    {
        $user = $request->user();
        Access::abortUnless(Access::investorCanSee($user, $offer));
        abort_unless($offer->isOpenForBidding(), 403, 'This offer is not open for bidding.');

        return successResponse('Fetched.', $this->formProps($request, $offer, null));
    }

    public function store(BidRequest $request, Offer $offer): JsonResponse
    {
        $bid = $this->bids->place($offer, $request->user(), $request->validated(), $request->file('evidence'));

        // s6.1: a bid received does not guarantee allotment.
        return successResponse('Your bid has been received. Please note that submitting a bid does not guarantee allocation or allotment.', ['redirect' => screenPath(route('investor.bids.show', $bid))]);
    }

    public function show(Request $request, Bid $bid): JsonResponse
    {
        $this->own($request, $bid);
        $bid->load('offer.institution:id,name', 'offer.allotment', 'events.user:id,name', 'allotmentItem');
        $allotmentStatus = optional($bid->offer->allotment)->status;
        $visibleAllotment = in_array($allotmentStatus, [Allotment::APPROVED, Allotment::LODGED], true);

        return successResponse('Fetched.', [
            'bid' => array_merge($bid->toArray(), [
                'evidence_url' => $bid->payment_evidence_path ? signedFileUrl('bids.evidence', $bid) : null,
            ]),
            'canChange' => $bid->canBeChangedByInvestor(),
            'canUploadEvidence' => $bid->isActive() && $bid->payment_status !== Bid::PAYMENT_PAID && ! $bid->offer->allotmentStarted(),
            'allotment' => $visibleAllotment && $bid->allotmentItem ? $bid->allotmentItem->only(['allotted', 'units', 'released', 'filled_pct']) : null,
            'allotmentStatus' => $allotmentStatus,
        ]);
    }

    public function edit(Request $request, Bid $bid): JsonResponse
    {
        $this->own($request, $bid);
        abort_unless($bid->canBeChangedByInvestor(), 403, 'Bids can only be changed while the offer is open.');

        return successResponse('Fetched.', $this->formProps($request, $bid->offer, $bid));
    }

    public function update(BidRequest $request, Bid $bid): JsonResponse
    {
        $this->own($request, $bid);
        $this->bids->amend($bid, $request->user(), $request->validated());
        if ($request->file('evidence')) {
            $this->bids->uploadEvidence($bid, $request->user(), $request->file('evidence'), $request->input('payment_reference'));
        }

        return successResponse('Your bid was amended.', ['redirect' => screenPath(route('investor.bids.show', $bid))]);
    }

    public function revoke(Request $request, Bid $bid): JsonResponse
    {
        $this->own($request, $bid);
        $this->bids->revoke($bid, $request->user());

        return successResponse('Your bid was revoked.', ['redirect' => screenPath(route('investor.bids.show', $bid))]);
    }

    public function evidence(Request $request, Bid $bid): JsonResponse
    {
        $this->own($request, $bid);
        $data = $request->validate([
            'evidence' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:5120'],
            'payment_reference' => ['nullable', 'string', 'max:100'],
        ]);
        $this->bids->uploadEvidence($bid, $request->user(), $request->file('evidence'), $data['payment_reference'] ?? null);

        return successResponse('Evidence of payment uploaded. Payment status stays "Pending" until the sponsor confirms it.');
    }

    private function formProps(Request $request, Offer $offer, ?Bid $bid): array
    {
        $user = $request->user();
        $offer->load('receivingAgents', 'coSponsors.institution:id,name', 'institution:id,name');
        $profile = $user->investorProfile;

        return [
            'offer' => $offer,
            'bid' => $bid,
            'payees' => $offer->payeeOptions(),
            'investorTypes' => config('eoffering.investor_types'),
            'defaults' => [
                'investor_type' => $user->institution ? '' : 'individual',
                'csd_name' => optional($profile)->csd_name,
                'csd_account_number' => optional($profile)->csd_account_number,
                'bank_name' => optional($profile)->bank_name,
                'bank_account_name' => optional($profile)->bank_account_name ?: ($user->institution ? $user->institution->name : $user->name),
                'bank_account_number' => optional($profile)->bank_account_number,
            ],
        ];
    }

    private function own(Request $request, Bid $bid): void
    {
        abort_unless($bid->user_id === $request->user()->id, 403);
    }
}

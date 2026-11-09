<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use App\Models\Allotment;
use App\Models\Bid;
use App\Models\Offer;
use App\Models\OfferDocument;
use App\Support\Access;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * Investor journey (CR s6) and dashboard / analytics (s6.3).
 */
class InvestorController extends Controller
{
    public function dashboard(Request $request): JsonResponse
    {
        $user = $request->user();
        $bids = Bid::where('user_id', $user->id)->with('offer:id,reference,issuer_name,security_type,close_at,status,issue_price', 'offer.allotment:id,offer_id,status', 'allotmentItem')
            ->latest('submitted_at')->get();

        // Portfolio = allotments approved by SEC (or lodged), by security type.
        $portfolio = [];
        foreach ($bids as $bid) {
            $item = $bid->allotmentItem;
            $status = optional(optional($bid->offer)->allotment)->status;
            if ($item && in_array($status, [Allotment::APPROVED, Allotment::LODGED], true) && (float) $item->allotted > 0) {
                $type = $bid->offer->security_type_label;
                $portfolio[$type] = ($portfolio[$type] ?? 0) + (float) $item->allotted;
            }
        }

        return successResponse('Fetched.', [
            'stats' => [
                'active_bids' => $bids->where('status', Bid::ACTIVE)->count(),
                'subscribed' => (float) $bids->where('status', Bid::ACTIVE)->sum('amount'),
                'paid' => (float) $bids->where('status', Bid::ACTIVE)->where('payment_status', Bid::PAYMENT_PAID)->sum('amount'),
                'allotted' => array_sum($portfolio),
            ],
            'portfolio' => collect($portfolio)->map(function ($v, $k) {
                return ['type' => $k, 'value' => $v];
            })->values(),
            'openOffers' => $this->eligibleQuery($request)->openForBidding()->with('institution:id,name')->orderBy('close_at')->limit(6)->get(),
            'bids' => $bids->take(10)->values()->map(function (Bid $bid) {
                return $this->bidRow($bid);
            }),
            'notifications' => $user->notifications()->latest()->limit(5)->get()->map(function ($n) {
                return ['id' => $n->id, 'title' => $n->data['title'] ?? '', 'message' => $n->data['message'] ?? '', 'url' => $n->data['url'] ?? null, 'read_at' => $n->read_at, 'created_at' => $n->created_at->toDateTimeString()];
            }),
        ]);
    }

    public function offers(Request $request): JsonResponse
    {
        $offers = $this->eligibleQuery($request)
            ->with('institution:id,name')
            ->when($request->query('type'), function ($q, $type) {
                $q->where('security_type', $type);
            })
            ->when($request->query('search'), function ($q, $term) {
                $q->where(function ($w) use ($term) {
                    $w->where('issuer_name', 'like', '%'.$term.'%')->orWhere('issue_description', 'like', '%'.$term.'%')->orWhere('reference', 'like', '%'.$term.'%');
                });
            })
            ->when($request->query('show') !== 'all', function ($q) {
                $q->where('close_at', '>=', now());
            })
            ->orderBy('close_at')->paginate(12)->withQueryString();

        return successResponse('Fetched.', [
            'offers' => $offers,
            'filters' => $request->only('type', 'search', 'show'),
            'securityTypes' => collect(config('eoffering.security_types'))->map(function ($t, $k) {
                return ['value' => $k, 'label' => $t['label']];
            })->values(),
        ]);
    }

    public function offer(Request $request, Offer $offer): JsonResponse
    {
        $user = $request->user();
        Access::abortUnless(Access::canViewOffer($user, $offer));
        $offer->load('institution:id,name', 'allotment:id,offer_id,status');

        return successResponse('Fetched.', [
            'offer' => $offer,
            'documents' => $offer->documents()->where('visible_to_investors', true)->whereIn('category', [OfferDocument::OFFER, OfferDocument::AMENDMENT])->get(),
            'myBids' => $offer->bids()->where('user_id', $user->id)->latest('submitted_at')->get(),
            'canBid' => $offer->isOpenForBidding() && Access::investorCanSee($user, $offer),
            'amendments' => $offer->amendments()->where('status', 'approved')->latest('applied_at')->get(['id', 'changes', 'reason', 'applied_at']),
        ]);
    }

    /** Subscription and payment history (s6.3). */
    public function history(Request $request): JsonResponse
    {
        $bids = Bid::where('user_id', $request->user()->id)
            ->with('offer:id,reference,issuer_name,security_type,close_at,status', 'offer.allotment:id,offer_id,status', 'allotmentItem')
            ->latest('submitted_at')->paginate(20);

        return successResponse('Fetched.', [
            'bids' => $bids->through(function (Bid $bid) {
                return $this->bidRow($bid);
            }),
        ]);
    }

    private function eligibleQuery(Request $request): Builder
    {
        $category = $request->user()->investorCategory();

        return Offer::query()->where('status', Offer::LIVE)->where(function (Builder $q) use ($category) {
            $q->where('investor_universe', 'like', '%"'.$category.'"%');
        });
    }

    private function bidRow(Bid $bid): array
    {
        $item = $bid->allotmentItem;
        $allotmentStatus = optional(optional($bid->offer)->allotment)->status;
        $outcome = 'pending';
        if (in_array($allotmentStatus, [Allotment::APPROVED, Allotment::LODGED], true)) {
            $outcome = 'approved';
        } elseif ($allotmentStatus === Allotment::CANCELLED) {
            $outcome = 'rejected';
        }

        return [
            'id' => $bid->id,
            'reference' => $bid->reference,
            'offer' => $bid->offer ? $bid->offer->only(['id', 'reference', 'issuer_name', 'security_type_label']) : null,
            'amount' => (float) $bid->amount,
            'rate' => $bid->rate !== null ? (float) $bid->rate : null,
            'status' => $bid->status,
            'payment_status' => $bid->payment_status,
            'submitted_at' => optional($bid->submitted_at)->toDateTimeString(),
            'allotment' => [
                'status' => $outcome,
                'allotted' => $outcome === 'approved' && $item ? (float) $item->allotted : null,
                'units' => $outcome === 'approved' && $item ? (float) $item->units : null,
            ],
        ];
    }
}

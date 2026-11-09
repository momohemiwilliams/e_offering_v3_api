<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use App\Models\AllotmentItem;
use App\Models\Bid;
use App\Models\Offer;
use App\Models\WorkflowAction;
use App\Services\Reports\BookAnalytics;
use App\Services\Reports\Exporter;
use App\Support\Access;
use Illuminate\Http\Request;

/**
 * Sponsor reporting and analytics (CR s7) and the Offer Summary Report (s5.5).
 */
class ReportController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $offers = Offer::visibleToInstitution($request->user()->institution_id)
            ->whereIn('status', [Offer::LIVE, Offer::CANCELLED])
            ->with('allotment:id,offer_id,status,total_allotted,subscription_level')
            ->withSum(['bids as subscribed' => function ($q) {
                $q->where('status', Bid::ACTIVE);
            }], 'amount')
            ->withCount(['bids as bids_count' => function ($q) {
                $q->where('status', Bid::ACTIVE);
            }])
            ->latest('open_at')->get();

        return successResponse('Fetched.', ['offers' => $offers]);
    }

    public function show(Request $request, Offer $offer, BookAnalytics $analytics): JsonResponse
    {
        Access::abortUnless(Access::tspCanView($request->user(), $offer));
        $allotment = $offer->allotment;

        $allotmentSummary = null;
        if ($allotment) {
            $allotmentSummary = [
                'method' => $allotment->method_label,
                'status' => $allotment->status,
                'total_allotted' => (float) $allotment->total_allotted,
                'residual' => max(0, (float) $allotment->issue_size - (float) $allotment->total_allotted),
                'released' => (float) $allotment->items()->sum('released'),
                'final_rate' => $allotment->final_rate !== null ? (float) $allotment->final_rate : null,
                'investors_allotted' => $allotment->items()->where('allotted', '>', 0)->count(),
            ];
        }

        return successResponse('Fetched.', [
            'offer' => $offer->load('institution:id,name', 'allotment'),
            'summary' => $analytics->summary($offer),
            'ranking' => array_slice($analytics->ranking($offer), 0, 50),
            'allotmentSummary' => $allotmentSummary,
            'exceptions' => $this->exceptionsFor([$offer->id]),
        ]);
    }

    /** Exception report across all the sponsor's offers. */
    public function exceptions(Request $request): JsonResponse
    {
        $ids = Offer::visibleToInstitution($request->user()->institution_id)->pluck('id')->all();

        return successResponse('Fetched.', $this->exceptionsFor($ids) + [
            'offers' => Offer::whereIn('id', $ids)->get(['id', 'reference', 'issuer_name'])->keyBy('id'),
        ]);
    }

    /** Offer Summary Report export (unchanged content, s5.5). */
    public function summaryExport(Request $request, Offer $offer, BookAnalytics $analytics, Exporter $exporter)
    {
        Access::abortUnless(Access::tspCanView($request->user(), $offer));
        $s = $analytics->summary($offer);
        $a = $offer->allotment;
        $rows = [
            ['Offer ID', $offer->reference],
            ['Issuer', $offer->issuer_name],
            ['Issue Description', $offer->issue_description],
            ['Security Type', $offer->security_type_label],
            ['Price-Discovery Method', $offer->price_discovery_label],
            ['Offer Size', (float) $offer->offer_size],
            ['Open Date', $offer->open_at->format('F j, Y g:i A')],
            ['Close Date', $offer->close_at->format('F j, Y g:i A')],
            ['Tenor', $offer->tenor],
            ['Implied Yield', $offer->implied_yield],
            ['Issue Price per Unit', $offer->issue_price],
            [$offer->rate_label, $offer->rate ?? (($offer->rate_min ?? '').' - '.($offer->rate_max ?? ''))],
            ['Total Bids', $s['bids_count']],
            ['Total Subscription', $s['total_subscription']],
            ['Valid Subscription', $s['valid_subscription']],
            ['Subscription Level (%)', $s['subscription_level']],
            ['Weighted Average Bid Rate', $s['weighted_average_rate']],
            ['Final Rate', $a ? $a->final_rate : $offer->final_rate],
            ['Allotment Method', $a ? $a->method_label : 'Not started'],
            ['Total Allotted', $a ? (float) $a->total_allotted : 0],
            ['Allotment Status', $a ? $a->status : '—'],
        ];

        return $exporter->download('offer-summary-'.$offer->reference, ['Item', 'Value'], $rows, (string) $request->query('format', 'xlsx'), 'Offer Summary Report — '.$offer->issuer_name);
    }

    private function exceptionsFor(array $offerIds): array
    {
        return [
            'rejectedBids' => Bid::whereIn('offer_id', $offerIds)->where('payment_status', Bid::PAYMENT_INVALID)
                ->orderByDesc('payment_reviewed_at')->get(['id', 'offer_id', 'reference', 'investor_name', 'amount', 'payment_comment', 'payment_reviewed_at']),
            'revokedBids' => Bid::whereIn('offer_id', $offerIds)->where('status', Bid::REVOKED)
                ->orderByDesc('revoked_at')->get(['id', 'offer_id', 'reference', 'investor_name', 'amount', 'revoked_at']),
            'overrides' => AllotmentItem::whereNotNull('override_amount')
                ->whereHas('allotment', function ($q) use ($offerIds) {
                    $q->whereIn('offer_id', $offerIds);
                })->with('bid:id,offer_id,reference,investor_name', 'overrider:id,name', 'allotment:id,offer_id')
                ->get(),
            'returns' => WorkflowAction::whereIn('offer_id', $offerIds)->where('decision', 'rejected')
                ->orderByDesc('id')->get(),
        ];
    }
}

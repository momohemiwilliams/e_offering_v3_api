<?php

namespace App\Http\Controllers;

use App\Models\Offer;
use Illuminate\Http\JsonResponse;

class PublicController extends Controller
{
    /** Shareable offer page: published terms only, for offers that are live. */
    public function offer(string $reference): JsonResponse
    {
        $offer = Offer::where('reference', $reference)->where('status', Offer::LIVE)->with('institution:id,name')->firstOrFail();

        return successResponse('Fetched.', ['offer' => array_merge($offer->only([
            'reference', 'issuer_name', 'issuer_type', 'issue_description', 'issue_series', 'offer_size', 'open_at', 'close_at',
            'tenor', 'rate', 'rate_min', 'rate_max', 'issue_price', 'min_subscription_amount',
            'security_type_label', 'rate_label', 'price_discovery_label',
        ]), [
            'sponsor' => optional($offer->institution)->name,
            'co_sponsors' => $offer->coSponsors()->with('institution:id,name')->get()->pluck('institution.name')->filter()->values(),
        ])]);
    }
}

<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use App\Models\Bid;
use App\Models\Offer;
use App\Services\BidService;
use App\Services\Reports\BookAnalytics;
use App\Services\Reports\Exporter;
use App\Support\Access;
use Illuminate\Http\Request;

/**
 * "View Order Book" (CR s5.1). The page polls for updates, so new, amended and
 * revoked bids appear without reloading (s6.1 step 4).
 */
class OrderBookController extends Controller
{
    public const HEADINGS = [
        'S/N', 'Bid Reference', 'Investor Name', 'Placed Through (TSP/RM)', 'Investor Category', 'Investor Type',
        'Bid Rate', 'Bid Yield', 'Face Value (Bid Amount)', 'Payment Status', 'Evidence Uploaded', 'Offer Open Date',
        'Offer Close Date', 'Bid Received', 'CSD Name', 'CSD No.', 'Account Name', 'Account Number', 'Bank', 'Status',
    ];

    public function index(Request $request): JsonResponse
    {
        $offers = Offer::visibleToInstitution($request->user()->institution_id)
            ->where('status', Offer::LIVE)
            ->with('allotment:id,offer_id,status')
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
        $user = $request->user();
        Access::abortUnless(Access::tspCanView($user, $offer));
        $offer->load('allotment:id,offer_id,status', 'institution:id,name');

        $bids = $offer->bids()->with('placedThrough:id,name', 'paymentReviewer:id,name')
            ->when($request->query('category'), function ($q, $category) {
                $q->where('investor_category', $category);
            })
            ->when($request->query('payment'), function ($q, $payment) {
                $q->where('payment_status', $payment);
            })
            ->when(! $request->boolean('include_revoked'), function ($q) {
                $q->where('status', Bid::ACTIVE);
            })
            // s5.1 step 5: in the order the bids were received.
            ->orderBy('submitted_at')->orderBy('id')
            ->get()
            ->map(function (Bid $bid) {
                return array_merge($bid->toArray(), [
                    'evidence_url' => $bid->payment_evidence_path ? signedFileUrl('bids.evidence', $bid) : null,
                    'placed_through' => optional($bid->placedThrough)->name,
                    'reviewer' => optional($bid->paymentReviewer)->name,
                ]);
            });

        return successResponse('Fetched.', [
            'offer' => $offer,
            'bids' => $bids,
            'summary' => $analytics->summary($offer),
            'filters' => $request->only('category', 'payment', 'include_revoked'),
            'canConfirm' => Access::tspCanConfirmPayments($user, $offer) && (! $offer->allotment || $offer->allotment->isEditable()),
            'canInitiate' => Access::tspOwns($user, $offer) && $user->isInputter() && ! $offer->allotment && $offer->close_at->isPast(),
            'refreshedAt' => now()->toDateTimeString(),
        ]);
    }

    public function export(Request $request, Offer $offer, Exporter $exporter)
    {
        Access::abortUnless(Access::tspCanView($request->user(), $offer));

        return $exporter->download(
            'order-book-'.$offer->reference,
            self::HEADINGS,
            self::rows($offer),
            (string) $request->query('format', 'xlsx'),
            'Order Book — '.$offer->issuer_name.' ('.$offer->reference.')'
        );
    }

    public function reviewPayment(Request $request, Bid $bid, BidService $bids): JsonResponse
    {
        Access::abortUnless(Access::tspCanConfirmPayments($request->user(), $bid->offer));
        $data = $request->validate([
            'valid' => ['required', 'boolean'],
            'comment' => ['required_if:valid,false', 'nullable', 'string', 'max:1000'],
        ], ['comment.required_if' => 'A comment is required when payment is marked Invalid.']);
        $bids->reviewPayment($bid, $request->user(), (bool) $data['valid'], $data['comment'] ?? null);

        return successResponse('Payment marked '.($data['valid'] ? 'Valid (Paid)' : 'Invalid').' for '.$bid->investor_name.'.');
    }

    public static function rows(Offer $offer): array
    {
        $rows = [];
        $n = 0;
        foreach ($offer->bids()->with('placedThrough:id,name')->orderBy('submitted_at')->orderBy('id')->get() as $bid) {
            $rows[] = [
                ++$n, $bid->reference, $bid->investor_name, optional($bid->placedThrough)->name ?? $bid->payee_name,
                strtoupper(str_replace('_', '-', $bid->investor_category)), $bid->investor_type_label,
                $bid->rate, $bid->yield, (float) $bid->amount, ucfirst($bid->payment_status), $bid->payment_evidence_path ? 'Yes' : 'No',
                $offer->open_at->format('F j, Y g:i A'), $offer->close_at->format('F j, Y g:i A'),
                $bid->submitted_at->format('F j, Y g:i:s A'), $bid->csd_name, $bid->csd_account_number,
                $bid->bank_account_name, $bid->bank_account_number, $bid->bank_name, ucfirst($bid->status),
            ];
        }

        return $rows;
    }
}

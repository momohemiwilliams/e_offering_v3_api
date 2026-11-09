<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use App\Models\Allotment;
use App\Models\Bid;
use App\Services\Allotment\AllotmentService;
use App\Services\Reports\Exporter;
use Illuminate\Http\Request;

/**
 * SEC / CBN review of allotments (CR s3.3 steps 3-5, s5.3 step 4).
 */
class RegulatorController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $status = $request->query('status', Allotment::SEC_PENDING);
        $allotments = Allotment::with('offer:id,reference,issuer_name,security_type,offer_size,close_at,institution_id', 'offer.institution:id,name')
            ->whereIn('status', [Allotment::SEC_PENDING, Allotment::SEC_REJECTED, Allotment::APPROVED, Allotment::LODGED])
            ->when($status !== 'all', function ($q) use ($status) {
                $q->where('status', $status);
            })
            ->latest('authorised_at')->paginate(20)->withQueryString();

        return successResponse('Fetched.', [
            'allotments' => $allotments,
            'filters' => ['status' => $status],
            'counts' => Allotment::selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status'),
        ]);
    }

    public function show(Allotment $allotment, AllotmentService $service): JsonResponse
    {
        abort_if(in_array($allotment->status, [Allotment::DRAFT, Allotment::PENDING_AUTHORISATION, Allotment::RETURNED, Allotment::CANCELLED], true), 404);
        $offer = $allotment->offer()->with('institution:id,name')->first();

        $items = $allotment->items()->with('bid')->get()->map(function ($item) {
            return array_merge($item->only(['id', 'subscribed', 'allotted', 'units', 'released', 'filled_pct', 'eligible', 'ineligible_reason', 'override_reason']), [
                'bid' => $item->bid->only(['reference', 'investor_name', 'investor_category', 'investor_type_label', 'rate', 'csd_name', 'csd_account_number', 'payment_status', 'status']),
            ]);
        });

        return successResponse('Fetched.', [
            'allotment' => $allotment,
            'offer' => $offer,
            'basis' => $service->basisOfAllotment($allotment),
            'schedule' => $items->where('eligible', true)->values(),
            // "Rejected applications": invalid payments, revoked bids and bids that received nothing.
            'rejected' => $items->filter(function ($i) {
                return ! $i['eligible'] || (float) $i['allotted'] === 0.0;
            })->values(),
            'offerDocuments' => $offer->documents()->whereIn('category', ['offer', 'amendment'])->get(),
            'secDocuments' => $allotment->documents()->get(),
            'workflow' => $offer->workflowActions()->orderBy('id')->get(),
            'canDecide' => $allotment->status === Allotment::SEC_PENDING,
            'paymentSummary' => [
                'paid' => $offer->bids()->where('status', Bid::ACTIVE)->where('payment_status', Bid::PAYMENT_PAID)->count(),
                'pending' => $offer->bids()->where('status', Bid::ACTIVE)->where('payment_status', Bid::PAYMENT_PENDING)->count(),
                'invalid' => $offer->bids()->where('payment_status', Bid::PAYMENT_INVALID)->count(),
            ],
        ]);
    }

    public function decide(Request $request, Allotment $allotment, AllotmentService $service): JsonResponse
    {
        $data = $request->validate([
            'approve' => ['required', 'boolean'],
            'reason' => ['required_if:approve,false', 'nullable', 'string', 'max:2000'],
        ], ['reason.required_if' => 'Give the reason for rejecting the allotment.']);
        $service->secDecision($allotment, $request->user(), (bool) $data['approve'], $data['reason'] ?? null);

        return successResponse($data['approve'] ? 'Allotment approved.' : 'Allotment rejected and returned to the sponsor.', ['redirect' => screenPath(route('regulator.dashboard'))]);
    }

    public function export(Request $request, Allotment $allotment, Exporter $exporter)
    {
        abort_if(in_array($allotment->status, [Allotment::DRAFT, Allotment::PENDING_AUTHORISATION, Allotment::RETURNED], true), 404);

        return $exporter->download('allotment-schedule-'.$allotment->offer->reference, AllotmentController::HEADINGS, AllotmentController::rows($allotment), (string) $request->query('format', 'xlsx'), 'Final Allotment Schedule — '.$allotment->offer->issuer_name);
    }
}

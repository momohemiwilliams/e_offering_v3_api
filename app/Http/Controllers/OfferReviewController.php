<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use App\Models\Offer;
use App\Models\OfferNote;
use App\Services\AuditLogger;
use App\Models\OfferAmendment;
use App\Services\OfferWorkflow;
use Illuminate\Http\Request;

/**
 * All offers on the platform, plus the FMDQ Admin review gate (CR s4.4 row 3),
 * which only receives work when enabled in Settings.
 */
class OfferReviewController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $offers = Offer::with('institution:id,name', 'allotment:id,offer_id,status')
            ->withSum(['bids as subscribed' => function ($q) {
                $q->where('status', 'active');
            }], 'amount')
            ->when($request->query('status'), function ($q, $status) {
                $q->where('status', $status);
            })
            ->when($request->query('search'), function ($q, $term) {
                $q->where(function ($w) use ($term) {
                    $w->where('reference', 'like', '%'.$term.'%')->orWhere('issuer_name', 'like', '%'.$term.'%');
                });
            })
            ->when($request->query('from'), function ($q, $from) {
                $q->where('open_at', '>=', $from);
            })
            ->when($request->query('to'), function ($q, $to) {
                $q->where('open_at', '<=', $to.' 23:59:59');
            })
            ->latest('id')->paginate(20)->withQueryString();

        return successResponse('Fetched.', [
            'offers' => $offers,
            'filters' => $request->only('status', 'search', 'from', 'to'),
        ]);
    }

    public function show(Offer $offer): JsonResponse
    {
        $notes = OfferNote::with('author:id,name')->where('offer_id', $offer->id)->latest('id')->get();

        return successResponse('Fetched.', array_merge(OfferController::detailProps($offer), ['notes' => $notes]));
    }

    public function storeNote(Request $request, Offer $offer, AuditLogger $audit): JsonResponse
    {
        $data = $request->validate(['title' => ['required', 'string', 'max:255'], 'body' => ['required', 'string', 'max:5000']]);

        OfferNote::create($data + ['offer_id' => $offer->id, 'user_id' => $request->user()->id]);
        $audit->log('offer.note_added', 'Added commitment note: ' . $data['title'], $offer, [], $request->user(), $offer->id);

        return successResponse('Note saved.');
    }

    public function review(Request $request, Offer $offer, OfferWorkflow $workflow): JsonResponse
    {
        $data = $request->validate([
            'approve' => ['required', 'boolean'],
            'reason' => ['required_if:approve,false', 'nullable', 'string', 'max:2000'],
        ], ['reason.required_if' => 'Give the reason for returning the offer.']);
        $workflow->adminReview($offer, $request->user(), (bool) $data['approve'], $data['reason'] ?? null);

        return successResponse($data['approve'] ? 'Offer approved; the TSP/RM can launch it.' : 'Offer returned to the TSP/RM Inputter.');
    }

    public function reviewAmendment(Request $request, OfferAmendment $amendment, OfferWorkflow $workflow): JsonResponse
    {
        abort_unless($amendment->status === OfferAmendment::PENDING_ADMIN_REVIEW, 422);
        $data = $request->validate([
            'approve' => ['required', 'boolean'],
            'comment' => ['required_if:approve,false', 'nullable', 'string', 'max:2000'],
        ]);
        $workflow->reviewAmendment($amendment, $request->user(), (bool) $data['approve'], $data['comment'] ?? null);

        return successResponse($data['approve'] ? 'Amendment approved and applied.' : 'Amendment rejected.');
    }
}

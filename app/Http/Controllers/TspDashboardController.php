<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use App\Models\Allotment;
use App\Models\AuditLog;
use App\Models\Bid;
use App\Models\Offer;
use App\Models\OfferAmendment;
use App\Models\User;
use Illuminate\Http\Request;

class TspDashboardController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $user = $request->user();
        $institutionId = $user->institution_id;
        $offers = Offer::visibleToInstitution($institutionId)->with('allotment:id,offer_id,status')
            ->withSum(['bids as subscribed' => function ($q) {
                $q->where('status', Bid::ACTIVE);
            }], 'amount')
            ->withCount(['bids as bids_count' => function ($q) {
                $q->where('status', Bid::ACTIVE);
            }])
            ->latest('id')->get();

        $open = $offers->filter(function (Offer $o) {
            return $o->isOpenForBidding();
        });
        $closed = $offers->filter(function (Offer $o) {
            return $o->status === Offer::LIVE && $o->close_at->isPast();
        });

        $byType = $offers->groupBy('security_type')->map(function ($group, $type) {
            return ['type' => config('eoffering.security_types.'.$type.'.label', $type), 'count' => $group->count()];
        })->values();

        // Work waiting for this user (inputter / authoriser queues).
        $tasks = [];
        if ($user->isAuthoriser()) {
            $tasks['offers'] = Offer::where('institution_id', $institutionId)->where('status', Offer::PENDING_AUTHORISATION)->count();
            $tasks['amendments'] = OfferAmendment::whereHas('offer', function ($q) use ($institutionId) {
                $q->where('institution_id', $institutionId);
            })->where('status', OfferAmendment::PENDING_AUTHORISATION)->count();
            $tasks['allotments'] = Allotment::whereHas('offer', function ($q) use ($institutionId) {
                $q->where('institution_id', $institutionId);
            })->where('status', Allotment::PENDING_AUTHORISATION)->count();
        } else {
            $tasks['returned'] = Offer::where('institution_id', $institutionId)->where('status', Offer::RETURNED)->count();
            $tasks['to_launch'] = Offer::where('institution_id', $institutionId)->where('status', Offer::APPROVED)->count();
            $tasks['payments'] = Bid::whereIn('offer_id', $offers->pluck('id'))->where('status', Bid::ACTIVE)
                ->where('payment_status', Bid::PAYMENT_PENDING)->whereNotNull('payment_evidence_path')->count();
            $tasks['ready_to_allot'] = $closed->filter(function (Offer $o) {
                return $o->allotment === null;
            })->count();
        }

        return successResponse('Fetched.', [
            'stats' => [
                'open' => $open->count(),
                'closed' => $closed->count(),
                'total_subscribed' => (float) $offers->sum('subscribed'),
                'offers' => $offers->count(),
            ],
            'byType' => $byType,
            'recentOffers' => $offers->take(8)->values(),
            'tasks' => $tasks,
            'activity' => AuditLog::where('institution_id', $institutionId)->latest('id')->limit(8)->get(),
            'team' => User::where('institution_id', $institutionId)->get(['id', 'name', 'email', 'access_level', 'last_login_at']),
        ]);
    }
}

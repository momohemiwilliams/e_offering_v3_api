<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use App\Models\AuditLog;
use App\Models\Offer;
use App\Services\Reports\Exporter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * End-to-end audit trail (CR s7): admins see everything; a TSP/RM sees its own users'
 * actions plus every action on its offers (from creation through to CSD lodgement).
 */
class AuditController extends Controller
{
    public function index(Request $request, Exporter $exporter)
    {
        $user = $request->user();
        $query = AuditLog::query()->latest('id');

        if ($user->isTsp()) {
            $offerIds = Offer::visibleToInstitution($user->institution_id)->pluck('id');
            $query->where(function (Builder $q) use ($user, $offerIds) {
                $q->where('institution_id', $user->institution_id)->orWhereIn('offer_id', $offerIds);
            });
        }

        if ($user->isInvestor()) {
            $query->where('user_id', $user->id);
        }

        if ($offer = $request->query('offer')) {
            $query->where('offer_id', (int) $offer);
        }
        if ($term = trim((string) $request->query('search'))) {
            $like = '%'.$term.'%';
            $query->where(function (Builder $q) use ($like) {
                $q->where('description', 'like', $like)->orWhere('actor_name', 'like', $like)->orWhere('action', 'like', $like);
            });
        }
        if ($from = $request->query('from')) {
            $query->where('created_at', '>=', $from.' 00:00:00');
        }
        if ($to = $request->query('to')) {
            $query->where('created_at', '<=', $to.' 23:59:59');
        }

        if ($request->query('export')) {
            $rows = $query->limit(10000)->get()->map(function (AuditLog $log) {
                return [optional($log->created_at)->format('F j, Y g:i:s A'), $log->actor_name, $log->action, $log->description, $log->ip_address];
            })->all();

            return $exporter->download('audit-trail-'.now()->format('Ymd'), ['Date & Time', 'User', 'Action', 'Description', 'IP Address'], $rows, (string) $request->query('export'), 'Audit Trail');
        }

        $offers = $user->isInvestor() ? collect() : ($user->isTsp()
            ? Offer::visibleToInstitution($user->institution_id)->orderByDesc('id')->get(['id', 'reference', 'issuer_name'])
            : Offer::orderByDesc('id')->limit(200)->get(['id', 'reference', 'issuer_name']));

        return successResponse('Fetched.', [
            'logs' => $query->paginate(25)->withQueryString(),
            'filters' => $request->only('search', 'from', 'to', 'offer'),
            'offers' => $offers,
        ]);
    }
}

<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use App\Models\Allotment;
use App\Models\AllotmentItem;
use App\Models\Bid;
use App\Models\Offer;
use App\Services\Allotment\AllotmentService;
use App\Services\CsdLodgement;
use App\Services\Reports\Exporter;
use App\Support\Access;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Initiate Allotment, methodology, overrides, approvals and lodgement (CR s5.2-5.4).
 * The "Allotment" list shows every issue with its SEC status (s5.3).
 */
class AllotmentController extends Controller
{
    public const HEADINGS = [
        'S/N', 'Investor Name', 'Category', 'Investor Type', 'Bid Reference', 'Bid Rate', 'Amount Subscribed',
        'Amount Allotted', 'Units Allotted', 'Amount Released', '% Filled', 'CSD Name', 'CSD Account', 'Override Reason',
    ];

    private AllotmentService $allotments;

    public function __construct(AllotmentService $allotments)
    {
        $this->allotments = $allotments;
    }

    public function index(Request $request): JsonResponse
    {
        $institutionId = $request->user()->institution_id;
        $offers = Offer::visibleToInstitution($institutionId)->where('status', Offer::LIVE)
            ->with('allotment')
            ->withSum(['bids as subscribed' => function ($q) {
                $q->where('status', Bid::ACTIVE);
            }], 'amount')
            ->orderByDesc('close_at')->get();

        return successResponse('Fetched.', ['offers' => $offers]);
    }

    public function show(Request $request, Offer $offer): JsonResponse
    {
        $user = $request->user();
        Access::abortUnless(Access::tspCanView($user, $offer));
        $allotment = $offer->allotment;

        $items = [];
        $basis = null;
        $errors = [];
        if ($allotment) {
            $allotment->load('documents');
            $items = $allotment->items()->with('bid', 'overrider:id,name')->get()
                ->sortBy(function (AllotmentItem $item) {
                    return [$item->eligible ? 0 : 1, optional($item->bid->submitted_at)->getTimestamp()];
                })->values()
                ->map(function (AllotmentItem $item) {
                    $bid = $item->bid;

                    return array_merge($item->toArray(), [
                        'bid' => $bid->only(['id', 'reference', 'investor_name', 'investor_category', 'investor_type', 'investor_type_label', 'rate', 'amount', 'csd_name', 'csd_account_number', 'payment_status', 'status', 'submitted_at']),
                        'overrider' => optional($item->overrider)->name,
                    ]);
                });
            $basis = $this->allotments->basisOfAllotment($allotment);
            $allotment->load('items.bid');
            $errors = $this->allotments->validationErrors($allotment);
        }

        return successResponse('Fetched.', [
            'offer' => $offer->load('institution:id,name'),
            'allotment' => $allotment,
            'items' => $items,
            'basis' => $basis,
            'validationErrors' => $errors,
            'workflow' => $allotment ? $offer->workflowActions()->where('subject_type', $allotment->getMorphClass())->orderBy('id')->get() : [],
            'guaranteeClasses' => config('eoffering.guarantee_classes'),
            'isOwner' => Access::tspOwns($user, $offer),
            'canInitiate' => Access::tspOwns($user, $offer) && $user->isInputter() && ! $allotment && $offer->close_at->isPast(),
        ]);
    }

    public function initiate(Request $request, Offer $offer): JsonResponse
    {
        Access::abortUnless(Access::tspOwns($request->user(), $offer));
        $this->allotments->initiate($offer, $request->user());

        return successResponse('Allotment initiated. Choose the methodology and review the proposed allotment.', ['redirect' => screenPath(route('tsp.allotments.show', $offer))]);
    }

    public function configure(Request $request, Offer $offer): JsonResponse
    {
        $allotment = $this->ownedAllotment($request, $offer);
        $data = $request->validate([
            'method' => ['required', Rule::in(['fcfs', 'pro_rata', 'hybrid'])],
            'pro_rata_option' => ['nullable', 'required_if:method,pro_rata', Rule::in([1, 2, 3, '1', '2', '3'])],
            'components' => ['array', 'required_if:method,hybrid'],
            'components.*' => [Rule::in(['guarantee', 'rate', 'fcfs', 'pro_rata'])],
            'guaranteed_bid_ids' => ['array'],
            'guaranteed_bid_ids.*' => ['integer'],
            'guaranteed_classes' => ['array'],
            'guaranteed_classes.*' => [Rule::in(array_keys(config('eoffering.guarantee_classes')))],
            'final_rate' => ['nullable', 'numeric', 'gt:0'],
            'shares' => ['array'],
            'shares.*' => ['nullable', 'numeric', 'min:0', 'max:100'],
        ]);
        $method = $data;
        unset($method['final_rate']);
        $this->allotments->configure($allotment, $request->user(), $method, $data['final_rate'] ?? null);

        return successResponse('Allotment recalculated with '.$allotment->fresh()->method_label.'.');
    }

    public function override(Request $request, AllotmentItem $item): JsonResponse
    {
        Access::abortUnless(Access::tspOwns($request->user(), $item->allotment->offer));
        $data = $request->validate([
            'amount' => ['nullable', 'numeric', 'min:0'],
            'reason' => ['required', 'string', 'max:1000'],
        ], ['reason.required' => 'A reason is required for a manual override.']);
        $this->allotments->override($item, $request->user(), isset($data['amount']) ? (string) $data['amount'] : null, $data['reason']);

        return successResponse('Override saved and the order book recalculated.');
    }

    public function attach(Request $request, Offer $offer): JsonResponse
    {
        $allotment = $offer->allotment;
        Access::abortUnless($allotment && Access::tspOwns($request->user(), $offer));
        $data = $request->validate([
            'document_type' => ['required', Rule::in(['Letter from Sponsor', 'Supporting Document'])],
            'file' => ['required', 'file', 'mimes:pdf,doc,docx,xls,xlsx,jpg,jpeg,png', 'max:20480'],
        ]);
        $this->allotments->attachDocument($allotment, $request->user(), $request->file('file'), $data['document_type']);

        return successResponse($data['document_type'].' attached for SEC review.');
    }

    public function submit(Request $request, Offer $offer): JsonResponse
    {
        $allotment = $this->ownedAllotment($request, $offer);
        $this->allotments->submit($allotment, $request->user(), $request->boolean('continue_below_minimum'));

        return successResponse('Allotment submitted to your Authoriser.');
    }

    public function cancel(Request $request, Offer $offer): JsonResponse
    {
        $allotment = $this->ownedAllotment($request, $offer);
        abort_unless($allotment->subscription_status === Allotment::BELOW_MINIMUM, 422, 'Only offers below the minimum subscription threshold can be cancelled here.');
        $data = $request->validate(['reason' => ['required', 'string', 'max:1000']]);
        $this->allotments->cancelOffer($allotment, $request->user(), $data['reason']);

        return successResponse('Offer cancelled and investors notified.', ['redirect' => screenPath(route('tsp.allotments.index'))]);
    }

    public function authorise(Request $request, Offer $offer): JsonResponse
    {
        Access::abortUnless($offer->allotment && Access::tspOwns($request->user(), $offer));
        $data = $request->validate([
            'approve' => ['required', 'boolean'],
            'reason' => ['required_if:approve,false', 'nullable', 'string', 'max:2000'],
        ], ['reason.required_if' => 'Give the reason for returning the allotment.']);
        $this->allotments->authorise($offer->allotment, $request->user(), (bool) $data['approve'], $data['reason'] ?? null);

        return successResponse($data['approve'] ? 'Allotment approved and sent to SEC (SEC Pending).' : 'Allotment returned to the Inputter.');
    }

    public function lodge(Request $request, Offer $offer, CsdLodgement $csd): JsonResponse
    {
        Access::abortUnless($offer->allotment && Access::tspOwns($request->user(), $offer));
        $this->allotments->lodge($offer->allotment, $request->user(), $csd);

        return successResponse('Lodgement report transmitted to the CSD.');
    }

    public function export(Request $request, Offer $offer, Exporter $exporter, CsdLodgement $csd)
    {
        Access::abortUnless($offer->allotment && Access::tspCanView($request->user(), $offer));
        $allotment = $offer->allotment;

        if ($request->query('type') === 'lodgement') {
            abort_unless($allotment->status === Allotment::LODGED, 404);

            return $exporter->download('lodgement-summary-'.$offer->reference, CsdLodgement::HEADINGS, $csd->rows($allotment), (string) $request->query('format', 'xlsx'), 'Lodgement Summary — '.$offer->issuer_name);
        }

        return $exporter->download('allotment-schedule-'.$offer->reference, self::HEADINGS, self::rows($allotment), (string) $request->query('format', 'xlsx'), 'Allotment Schedule — '.$offer->issuer_name.' ('.$allotment->method_label.')');
    }

    public static function rows(Allotment $allotment): array
    {
        $rows = [];
        $n = 0;
        foreach ($allotment->items()->with('bid')->get() as $item) {
            $bid = $item->bid;
            $rows[] = [
                ++$n, $bid->investor_name, strtoupper(str_replace('_', '-', $bid->investor_category)), $bid->investor_type_label,
                $bid->reference, $bid->rate, (float) $item->subscribed, (float) $item->allotted, (float) $item->units,
                (float) $item->released, (float) $item->filled_pct, $bid->csd_name, $bid->csd_account_number,
                $item->override_reason ?: ($item->eligible ? '' : $item->ineligible_reason),
            ];
        }

        return $rows;
    }

    private function ownedAllotment(Request $request, Offer $offer): Allotment
    {
        Access::abortUnless(Access::tspOwns($request->user(), $offer));
        abort_unless($offer->allotment, 404, 'Initiate the allotment first.');

        return $offer->allotment;
    }
}

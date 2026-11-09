<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use App\Http\Requests\OfferRequest;
use App\Models\AuditLog;
use App\Models\Bid;
use App\Models\Institution;
use App\Models\Offer;
use App\Models\OfferAmendment;
use App\Models\OfferDocument;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\DocumentStore;
use App\Services\OfferWorkflow;
use App\Services\Reports\Exporter;
use App\Support\Access;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Throwable;

/**
 * Offer creation, approval, launch and amendment for the TSP/RM (CR s4).
 */
class OfferController extends Controller
{
    private OfferWorkflow $workflow;

    private AuditLogger $audit;

    public function __construct(OfferWorkflow $workflow, AuditLogger $audit)
    {
        $this->workflow = $workflow;
        $this->audit = $audit;
    }

    public function index(Request $request): JsonResponse
    {
        $institutionId = $request->user()->institution_id;
        $offers = Offer::visibleToInstitution($institutionId)
            ->with('creator:id,name', 'allotment:id,offer_id,status', 'institution:id,name')
            ->withSum(['bids as subscribed' => function ($q) {
                $q->where('status', Bid::ACTIVE);
            }], 'amount')
            ->when($request->query('status'), function ($q, $status) {
                $q->where('status', $status);
            })
            ->when($request->query('search'), function ($q, $term) {
                $q->where(function ($w) use ($term) {
                    $w->where('reference', 'like', '%'.$term.'%')->orWhere('issuer_name', 'like', '%'.$term.'%')->orWhere('issue_description', 'like', '%'.$term.'%');
                });
            })
            ->latest('id')->paginate(15)->withQueryString();

        return successResponse('Fetched.', [
            'offers' => $offers,
            'filters' => $request->only('status', 'search'),
        ]);
    }

    public function create(Request $request): JsonResponse
    {
        return successResponse('Fetched.', $this->formProps($request, null));
    }

    public function store(OfferRequest $request): JsonResponse
    {
        $user = $request->user();
        $offer = DB::transaction(function () use ($request, $user) {
            $offer = Offer::create(array_merge($request->offerFields(), [
                'institution_id' => $user->institution_id,
                'created_by' => $user->id,
                'status' => Offer::DRAFT,
            ]));
            $this->syncParties($offer, $request);

            return $offer;
        });
        $this->audit->log('offer.created', 'Created offer '.$offer->reference.' ('.$offer->issuer_name.')', $offer, [], $user);

        return successResponse('Offer saved as a draft. Upload the supporting documents, then submit it for approval.', ['redirect' => screenPath(route('tsp.offers.show', $offer))]);
    }

    public function show(Request $request, Offer $offer): JsonResponse
    {
        Access::abortUnless(Access::tspCanView($request->user(), $offer));

        return successResponse('Fetched.', array_merge(self::detailProps($offer), [
            'isOwner' => Access::tspOwns($request->user(), $offer),
        ]));
    }

    public function edit(Request $request, Offer $offer): JsonResponse
    {
        $this->owned($request, $offer);
        abort_unless($offer->isEditable(), 403, 'Only draft or returned offers can be edited. Use "Amend offer" after launch.');

        return successResponse('Fetched.', $this->formProps($request, $offer));
    }

    public function update(OfferRequest $request, Offer $offer): JsonResponse
    {
        $this->owned($request, $offer);
        abort_unless($offer->isEditable(), 403);

        DB::transaction(function () use ($request, $offer) {
            $offer->update($request->offerFields());
            $this->syncParties($offer, $request);
        });
        $this->audit->log('offer.updated', 'Updated draft offer '.$offer->reference, $offer, [], $request->user());

        return successResponse('Offer updated.', ['redirect' => screenPath(route('tsp.offers.show', $offer))]);
    }

    public function uploadDocument(Request $request, Offer $offer, DocumentStore $store): JsonResponse
    {
        $this->owned($request, $offer);
        abort_unless($offer->isEditable() || $offer->canBeAmended(), 403);
        $data = $request->validate([
            'document_type' => ['required', 'string', 'max:191'],
            'file' => ['required', 'file', 'mimes:pdf,doc,docx,xls,xlsx,jpg,jpeg,png', 'max:20480'],
            'visible_to_investors' => ['boolean'],
        ]);
        $file = $request->file('file');

        // Replace an earlier upload of the same required document while the offer is a draft.
        if ($offer->isEditable()) {
            foreach ($offer->documents()->where('category', OfferDocument::OFFER)->where('document_type', $data['document_type'])->get() as $old) {
                $store->delete($old->path);
                $old->delete();
            }
        }

        $offer->documents()->create([
            'category' => $offer->isEditable() ? OfferDocument::OFFER : OfferDocument::AMENDMENT,
            'document_type' => $data['document_type'],
            'original_name' => $file->getClientOriginalName(),
            'path' => $store->store($file, 'offers/'.$offer->id),
            'size' => (int) $file->getSize(),
            'visible_to_investors' => $request->boolean('visible_to_investors', true),
            'uploaded_by' => $request->user()->id,
        ]);
        $this->audit->log('offer.document', 'Uploaded '.$data['document_type'].' to '.$offer->reference, $offer, ['file' => $file->getClientOriginalName()], $request->user());

        return successResponse($data['document_type'].' uploaded.');
    }

    public function deleteDocument(Request $request, Offer $offer, OfferDocument $document, DocumentStore $store): JsonResponse
    {
        $this->owned($request, $offer);
        abort_unless($document->offer_id === $offer->id && $offer->isEditable(), 403);
        $store->delete($document->path);
        $document->delete();
        $this->audit->log('offer.document_removed', 'Removed '.$document->document_type.' from '.$offer->reference, $offer, [], $request->user());

        return successResponse('Document removed.');
    }

    public function submit(Request $request, Offer $offer): JsonResponse
    {
        $this->owned($request, $offer);
        $this->workflow->submit($offer, $request->user());

        return successResponse('Offer submitted to your Authoriser for approval.');
    }

    public function authorise(Request $request, Offer $offer): JsonResponse
    {
        $this->owned($request, $offer);
        $data = $request->validate([
            'approve' => ['required', 'boolean'],
            'reason' => ['required_if:approve,false', 'nullable', 'string', 'max:2000'],
        ], ['reason.required_if' => 'Give the reason for returning the offer to the Inputter.']);
        $this->workflow->authorise($offer, $request->user(), (bool) $data['approve'], $data['reason'] ?? null);

        return successResponse($data['approve'] ? 'Offer approved.' : 'Offer returned to the Inputter.');
    }

    public function launch(Request $request, Offer $offer): JsonResponse
    {
        $this->owned($request, $offer);
        $this->workflow->launch($offer, $request->user());

        return successResponse('Offer launched. Eligible investors have been notified.');
    }

    /** CR s4.6 form. */
    public function amend(Request $request, Offer $offer): JsonResponse
    {
        $this->owned($request, $offer);
        abort_unless($offer->canBeAmended(), 403, 'This offer can no longer be amended.');

        return successResponse('Fetched.', [
            'offer' => $offer,
            'amendable' => Offer::AMENDABLE,
            'dayCounts' => config('eoffering.day_count_fractions'),
            'activeBidders' => $offer->activeBids()->distinct('user_id')->count('user_id'),
        ]);
    }

    public function storeAmendment(Request $request, Offer $offer): JsonResponse
    {
        $this->owned($request, $offer);
        $data = $request->validate([
            'reason' => ['required', 'string', 'max:2000'],
            'changes' => ['required', 'array'],
            'changes.open_at' => ['nullable', 'date'],
            'changes.close_at' => ['nullable', 'date'],
            'changes.offer_size' => ['nullable', 'numeric', 'gt:0'],
            'changes.min_subscription_threshold' => ['nullable', 'numeric', 'between:0,100'],
            'changes.min_subscription_amount' => ['nullable', 'numeric', 'gt:0'],
            'changes.subsequent_increment' => ['nullable', 'numeric', 'min:0'],
            'changes.rate' => ['nullable', 'numeric', 'gt:0'],
            'changes.rate_min' => ['nullable', 'numeric', 'gt:0'],
            'changes.rate_max' => ['nullable', 'numeric', 'gt:0'],
            'changes.implied_yield' => ['nullable', 'numeric', 'min:0'],
            'changes.issue_price' => ['nullable', 'numeric', 'gt:0'],
            'changes.maturity_date' => ['nullable', 'date'],
            'changes.allotment_date' => ['nullable', 'date'],
            'changes.tenor' => ['nullable', 'string', 'max:60'],
            'changes.day_count_fraction' => ['nullable', Rule::in(config('eoffering.day_count_fractions'))],
            'changes.issue_description' => ['nullable', 'string', 'max:2000'],
            'changes.bank_name' => ['nullable', 'string', 'max:100'],
            'changes.bank_account_name' => ['nullable', 'string', 'max:191'],
            'changes.bank_account_number' => ['nullable', 'digits:10'],
        ]);
        $changes = array_filter($data['changes'], function ($v) {
            return $v !== null && $v !== '';
        });
        $this->workflow->requestAmendment($offer, $request->user(), $changes, $data['reason']);

        return successResponse('Amendment submitted for approval.', ['redirect' => screenPath(route('tsp.offers.show', $offer))]);
    }

    public function reviewAmendment(Request $request, OfferAmendment $amendment): JsonResponse
    {
        $this->owned($request, $amendment->offer);
        abort_unless($amendment->status === OfferAmendment::PENDING_AUTHORISATION, 422);
        $data = $request->validate([
            'approve' => ['required', 'boolean'],
            'comment' => ['required_if:approve,false', 'nullable', 'string', 'max:2000'],
        ], ['comment.required_if' => 'Give the reason for rejecting the amendment.']);
        $this->workflow->reviewAmendment($amendment, $request->user(), (bool) $data['approve'], $data['comment'] ?? null);

        return successResponse($data['approve'] ? 'Amendment approved.' : 'Amendment rejected.');
    }

    /** Excel template for the receiving agents list (CR s4.3, s6.2). */
    public function agentsTemplate(Exporter $exporter)
    {
        return $exporter->download('receiving-agents-template', ['Name', 'Bank Name', 'Account Name', 'Account Number'], [
            ['Example Securities Ltd', 'Example Bank Plc', 'Example Securities Ltd - Offer Account', '0123456789'],
        ]);
    }

    /** Reads an uploaded Excel/CSV list so the form can show and edit the rows before saving. */
    public function importAgents(Request $request): JsonResponse
    {
        $request->validate(['file' => ['required', 'file', 'mimes:xlsx,xls,csv,txt', 'max:5120']]);

        try {
            $sheet = IOFactory::load($request->file('file')->getRealPath())->getActiveSheet()->toArray(null, true, true, false);
        } catch (Throwable $e) {
            throw ValidationException::withMessages(['file' => 'The file could not be read. Use the Excel template.']);
        }

        $rows = [];
        $errors = [];
        foreach ($sheet as $i => $row) {
            $row = array_map(function ($v) {
                return trim((string) $v);
            }, array_pad($row, 4, ''));
            if ($i === 0 && stripos($row[0], 'name') !== false) {
                continue;
            }
            if (implode('', $row) === '') {
                continue;
            }
            $number = preg_replace('/\D/', '', $row[3]);
            if ($row[0] === '' || $row[2] === '' || strlen($number) !== 10) {
                $errors[] = 'Row '.($i + 1).': name, account name and a 10-digit account number are required.';
                continue;
            }
            $rows[] = ['name' => $row[0], 'bank_name' => $row[1], 'bank_account_name' => $row[2], 'bank_account_number' => $number];
        }

        return response()->json(['rows' => $rows, 'errors' => $errors]);
    }

    /** Detail payload shared with the FMDQ Admin offer page. */
    public static function detailProps(Offer $offer): array
    {
        $offer->load([
            'institution:id,name,reference', 'creator:id,name', 'documents.uploader:id,name', 'receivingAgents',
            'coSponsors.institution:id,name', 'allotment', 'amendments.requester:id,name', 'amendments.reviewer:id,name',
        ]);
        $bids = $offer->bids()->where('status', Bid::ACTIVE);

        return [
            'offer' => $offer,
            'requiredDocuments' => (array) config('eoffering.security_types.'.$offer->security_type.'.documents', []),
            'workflow' => $offer->workflowActions()->orderBy('id')->get(),
            'history' => AuditLog::where('offer_id', $offer->id)->latest('id')->limit(50)->get(),
            'bidStats' => [
                'count' => (clone $bids)->count(),
                'total' => (float) (clone $bids)->sum('amount'),
            ],
            'universeLabels' => config('eoffering.investor_categories'),
            'canBeAmended' => $offer->canBeAmended(),
        ];
    }

    private function formProps(Request $request, ?Offer $offer): array
    {
        if ($offer) {
            $offer->load('receivingAgents', 'coSponsors');
        }

        return [
            'offer' => $offer,
            'options' => [
                'issuerTypes' => config('eoffering.issuer_types'),
                'securityTypes' => config('eoffering.security_types'),
                'methods' => config('eoffering.price_discovery_methods'),
                'dayCounts' => config('eoffering.day_count_fractions'),
                'categories' => config('eoffering.investor_categories'),
            ],
            'sponsors' => Institution::where('type', Institution::TYPE_TSP)->where('status', Institution::STATUS_APPROVED)
                ->where('id', '!=', $request->user()->institution_id)->orderBy('name')->get(['id', 'name']),
            'defaults' => $request->user()->institution->only(['bank_name', 'bank_account_name', 'bank_account_number']),
        ];
    }

    private function syncParties(Offer $offer, OfferRequest $request): void
    {
        $offer->receivingAgents()->delete();
        if ($request->boolean('uses_receiving_agents')) {
            foreach ((array) ($request->validated()['receiving_agents'] ?? []) as $agent) {
                $offer->receivingAgents()->create($agent);
            }
        }

        $offer->coSponsors()->delete();
        foreach ((array) ($request->validated()['co_sponsors'] ?? []) as $co) {
            $offer->coSponsors()->create([
                'institution_id' => $co['institution_id'],
                'can_view_order_book' => (bool) ($co['can_view_order_book'] ?? true),
                'can_confirm_payments' => (bool) ($co['can_confirm_payments'] ?? false),
                'bank_name' => $co['bank_name'] ?? null,
                'bank_account_name' => $co['bank_account_name'] ?? null,
                'bank_account_number' => $co['bank_account_number'] ?? null,
            ]);
        }
    }

    private function owned(Request $request, Offer $offer): void
    {
        Access::abortUnless(Access::tspOwns($request->user(), $offer));
    }
}

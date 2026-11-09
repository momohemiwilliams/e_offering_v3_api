<?php

namespace App\Services;

use App\Models\Offer;
use App\Models\OfferAmendment;
use App\Models\Setting;
use App\Models\User;
use App\Models\WorkflowAction;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Offer approval chain (CR s4.4), launch (s4.5) and post-launch amendments (s4.6).
 *
 * Inputter submits -> TSP Authoriser approves / returns -> [FMDQ Admin, only when the
 * backend setting is switched on] -> approved -> Inputter launches -> live.
 */
class OfferWorkflow
{
    private AuditLogger $audit;

    private Notifier $notifier;

    public function __construct(AuditLogger $audit, Notifier $notifier)
    {
        $this->audit = $audit;
        $this->notifier = $notifier;
    }

    public function submit(Offer $offer, User $inputter): void
    {
        if (! $offer->isEditable()) {
            throw ValidationException::withMessages(['offer' => 'Only draft or returned offers can be submitted.']);
        }
        $this->assertComplete($offer);

        $offer->forceFill(['status' => Offer::PENDING_AUTHORISATION, 'submitted_at' => now(), 'return_reason' => null])->save();
        WorkflowAction::record($offer, $offer->id, WorkflowAction::STAGE_INPUTTER, 'submitted', null, $inputter);
        $this->audit->log('offer.submitted', 'Submitted offer '.$offer->reference.' for authorisation', $offer, [], $inputter);

        $this->notifier->send(
            $this->notifier->tspUsers($offer->institution_id, User::AUTHORISER),
            'Offer awaiting your approval: '.$offer->reference,
            $inputter->name.' submitted '.$offer->issuer_name.' ('.$offer->security_type_label.') for authorisation.',
            frontendUrl('/tsp/offers/'.$offer->id)
        );
    }

    public function authorise(Offer $offer, User $authoriser, bool $approve, ?string $reason): void
    {
        if ($offer->status !== Offer::PENDING_AUTHORISATION) {
            throw ValidationException::withMessages(['offer' => 'This offer is not waiting for authorisation.']);
        }

        if (! $approve) {
            $this->returnToInputter($offer, $authoriser, WorkflowAction::STAGE_AUTHORISER, (string) $reason, 'TSP Authoriser');

            return;
        }

        $offer->forceFill(['authorised_by' => $authoriser->id, 'authorised_at' => now()]);
        WorkflowAction::record($offer, $offer->id, WorkflowAction::STAGE_AUTHORISER, 'approved', $reason, $authoriser);

        if (Setting::adminReviewRequiredFor($offer->security_type)) {
            $offer->forceFill(['status' => Offer::PENDING_ADMIN_REVIEW])->save();
            $this->audit->log('offer.authorised', 'Authorised offer '.$offer->reference.'; routed to FMDQ Admin review', $offer, [], $authoriser);
            $this->notifier->send(
                $this->notifier->admins(),
                'Offer awaiting FMDQ review: '.$offer->reference,
                $offer->issuer_name.' ('.$offer->security_type_label.') was approved by the TSP Authoriser and needs FMDQ Admin review.',
                frontendUrl('/admin/offers/'.$offer->id)
            );

            return;
        }

        $this->approved($offer, $authoriser);
    }

    /** CR s4.4 row 3 (backend only, disabled by default). */
    public function adminReview(Offer $offer, User $admin, bool $approve, ?string $reason): void
    {
        if ($offer->status !== Offer::PENDING_ADMIN_REVIEW) {
            throw ValidationException::withMessages(['offer' => 'This offer is not waiting for FMDQ review.']);
        }
        if (! $approve) {
            $this->returnToInputter($offer, $admin, WorkflowAction::STAGE_ADMIN, (string) $reason, 'FMDQ Admin');

            return;
        }
        $offer->forceFill(['admin_reviewed_by' => $admin->id, 'admin_reviewed_at' => now()]);
        WorkflowAction::record($offer, $offer->id, WorkflowAction::STAGE_ADMIN, 'approved', $reason, $admin);
        $this->approved($offer, $admin);
    }

    /** CR s4.5. */
    public function launch(Offer $offer, User $inputter): void
    {
        if ($offer->status !== Offer::APPROVED) {
            throw ValidationException::withMessages(['offer' => 'Only approved offers can be launched.']);
        }
        if ($offer->close_at->isPast()) {
            throw ValidationException::withMessages(['offer' => 'The close date has passed. Amend the dates before launching.']);
        }

        $offer->forceFill(['status' => Offer::LIVE, 'launched_by' => $inputter->id, 'launched_at' => now()])->save();
        $this->audit->log('offer.launched', 'Launched offer '.$offer->reference, $offer, [], $inputter);

        $this->notifier->send(
            $this->notifier->eligibleInvestors($offer),
            'New offer: '.$offer->issuer_name,
            $offer->issue_description.' opens on '.$offer->open_at->format('F j, Y g:i A').' and closes on '.$offer->close_at->format('F j, Y g:i A').'.',
            frontendUrl('/investor/offers/'.$offer->id)
        );
        $this->notifier->send(
            $this->notifier->tspUsers($offer->institution_id),
            'Offer launched: '.$offer->reference,
            $offer->issuer_name.' is now live on the e-Offering Platform.',
            frontendUrl('/tsp/offers/'.$offer->id)
        );
    }

    public function cancel(Offer $offer, User $user, string $reason): void
    {
        $offer->forceFill(['status' => Offer::CANCELLED, 'cancelled_at' => now(), 'cancel_reason' => $reason])->save();
        WorkflowAction::record($offer, $offer->id, WorkflowAction::STAGE_INPUTTER, 'cancelled', $reason, $user);
        $this->audit->log('offer.cancelled', 'Cancelled offer '.$offer->reference, $offer, ['reason' => $reason], $user);
        $this->notifier->send(
            $this->notifier->activeBidders($offer),
            'Offer cancelled: '.$offer->issuer_name,
            'The sponsor cancelled this offer. Reason: '.$reason.'. Contact the sponsor or receiving agent about refunds of any payment made.',
            frontendUrl('/investor/offers/'.$offer->id)
        );
    }

    /**
     * CR s4.6 step 1. $changes = [field => new value]; only fields that actually change are kept.
     */
    public function requestAmendment(Offer $offer, User $inputter, array $changes, string $reason): OfferAmendment
    {
        if (! $offer->canBeAmended()) {
            throw ValidationException::withMessages(['offer' => 'This offer can no longer be amended (allotment has started).']);
        }
        if ($offer->amendments()->whereIn('status', [OfferAmendment::PENDING_AUTHORISATION, OfferAmendment::PENDING_ADMIN_REVIEW])->exists()) {
            throw ValidationException::withMessages(['offer' => 'An amendment is already waiting for approval.']);
        }

        $diff = [];
        foreach ($changes as $field => $value) {
            if (! in_array($field, Offer::AMENDABLE, true)) {
                continue;
            }
            $from = $this->comparable($offer, $field, $offer->getAttribute($field));
            $to = $this->comparable($offer, $field, $value);
            if ((string) $from !== (string) $to) {
                $diff[$field] = ['from' => $from, 'to' => $to];
            }
        }
        if ($diff === []) {
            throw ValidationException::withMessages(['changes' => 'Change at least one detail.']);
        }
        if (isset($diff['close_at']) || isset($diff['open_at'])) {
            $open = Carbon::parse($diff['open_at']['to'] ?? $offer->open_at);
            $close = Carbon::parse($diff['close_at']['to'] ?? $offer->close_at);
            if ($close->lessThanOrEqualTo($open)) {
                throw ValidationException::withMessages(['close_at' => 'The close date must be after the open date.']);
            }
        }

        $amendment = $offer->amendments()->create([
            'requested_by' => $inputter->id,
            'reason' => $reason,
            'changes' => $diff,
            'status' => OfferAmendment::PENDING_AUTHORISATION,
        ]);
        WorkflowAction::record($amendment, $offer->id, WorkflowAction::STAGE_INPUTTER, 'submitted', $reason, $inputter);
        $this->audit->log('offer.amendment_requested', 'Requested amendment to '.$offer->reference, $offer, ['changes' => $diff, 'reason' => $reason], $inputter);

        $this->notifier->send(
            $this->notifier->tspUsers($offer->institution_id, User::AUTHORISER),
            'Amendment awaiting approval: '.$offer->reference,
            $inputter->name.' requested changes to '.$offer->issuer_name.'. Reason: '.$reason,
            frontendUrl('/tsp/offers/'.$offer->id)
        );

        return $amendment;
    }

    /** CR s4.6 steps 2-4. */
    public function reviewAmendment(OfferAmendment $amendment, User $reviewer, bool $approve, ?string $comment): void
    {
        $offer = $amendment->offer;
        $stage = $amendment->status === OfferAmendment::PENDING_ADMIN_REVIEW ? WorkflowAction::STAGE_ADMIN : WorkflowAction::STAGE_AUTHORISER;

        if (! $amendment->isPending()) {
            throw ValidationException::withMessages(['amendment' => 'This amendment has already been decided.']);
        }

        if (! $approve) {
            $amendment->forceFill([
                'status' => OfferAmendment::REJECTED, 'reviewed_by' => $reviewer->id, 'reviewed_at' => now(), 'review_comment' => $comment,
            ])->save();
            WorkflowAction::record($amendment, $offer->id, $stage, 'rejected', $comment, $reviewer);
            $this->audit->log('offer.amendment_rejected', 'Rejected amendment to '.$offer->reference, $offer, ['reason' => $comment], $reviewer);
            $this->notifier->send(
                $this->notifier->tspUsers($offer->institution_id, User::INPUTTER),
                'Amendment returned: '.$offer->reference,
                'Your amendment was not approved. Reason: '.$comment,
                frontendUrl('/tsp/offers/'.$offer->id)
            );

            return;
        }

        WorkflowAction::record($amendment, $offer->id, $stage, 'approved', $comment, $reviewer);

        if ($stage === WorkflowAction::STAGE_AUTHORISER && Setting::adminReviewRequiredFor($offer->security_type)) {
            $amendment->forceFill(['status' => OfferAmendment::PENDING_ADMIN_REVIEW])->save();
            $this->notifier->send(
                $this->notifier->admins(),
                'Amendment awaiting FMDQ review: '.$offer->reference,
                'An approved amendment to '.$offer->issuer_name.' needs FMDQ Admin review. Reason: '.$amendment->reason,
                frontendUrl('/admin/offers/'.$offer->id)
            );

            return;
        }

        DB::transaction(function () use ($amendment, $offer, $reviewer, $comment) {
            foreach ($amendment->changes as $field => $change) {
                $offer->setAttribute($field, $change['to']);
            }
            $offer->save();
            $amendment->forceFill([
                'status' => OfferAmendment::APPROVED, 'reviewed_by' => $reviewer->id, 'reviewed_at' => now(),
                'review_comment' => $comment, 'applied_at' => now(),
            ])->save();
        });

        $this->audit->log('offer.amended', 'Amended offer '.$offer->reference.' (approved by '.$reviewer->name.')', $offer, [
            'changes' => $amendment->changes, 'reason' => $amendment->reason, 'approved_by' => $reviewer->name,
        ], $reviewer);

        $summary = collect($amendment->changes)->map(function ($change, $field) {
            return ucwords(str_replace('_', ' ', $field)).': '.($change['from'] ?? '—').' -> '.($change['to'] ?? '—');
        })->implode('; ');

        $this->notifier->send(
            $this->notifier->activeBidders($offer),
            'Offer details changed: '.$offer->issuer_name,
            'The sponsor amended this offer. '.$summary.'. Reason: '.$amendment->reason.'. Review your bid if needed.',
            frontendUrl('/investor/offers/'.$offer->id)
        );
        $this->notifier->send(
            $this->notifier->tspUsers($offer->institution_id, User::INPUTTER),
            'Amendment approved: '.$offer->reference,
            'Your amendment to '.$offer->issuer_name.' was approved and applied.',
            frontendUrl('/tsp/offers/'.$offer->id)
        );
    }

    private function approved(Offer $offer, User $approver): void
    {
        $offer->forceFill(['status' => Offer::APPROVED])->save();
        $this->audit->log('offer.approved', 'Approved offer '.$offer->reference.' for launch', $offer, [], $approver);
        $this->notifier->send(
            $this->notifier->tspUsers($offer->institution_id, User::INPUTTER),
            'Offer approved: '.$offer->reference,
            $offer->issuer_name.' was approved. You can now launch it.',
            frontendUrl('/tsp/offers/'.$offer->id)
        );
    }

    private function returnToInputter(Offer $offer, User $reviewer, string $stage, string $reason, string $who): void
    {
        $offer->forceFill(['status' => Offer::RETURNED, 'return_reason' => $reason])->save();
        WorkflowAction::record($offer, $offer->id, $stage, 'rejected', $reason, $reviewer);
        $this->audit->log('offer.returned', 'Returned offer '.$offer->reference.' to the inputter', $offer, ['reason' => $reason, 'by' => $who], $reviewer);
        $this->notifier->send(
            $this->notifier->tspUsers($offer->institution_id, User::INPUTTER),
            'Offer returned for update: '.$offer->reference,
            'The '.$who.' returned '.$offer->issuer_name.'. Reason: '.$reason,
            frontendUrl('/tsp/offers/'.$offer->id)
        );
    }

    private function assertComplete(Offer $offer): void
    {
        $errors = [];
        $required = (array) config('eoffering.security_types.'.$offer->security_type.'.documents', []);
        $uploaded = $offer->documents()->where('category', 'offer')->pluck('document_type')->all();
        $missing = array_diff($required, $uploaded);
        if ($missing !== []) {
            $errors['documents'] = 'Upload the required documents: '.implode(', ', $missing).'.';
        }
        if (! $offer->bank_account_number && $offer->receivingAgents()->doesntExist()) {
            $errors['bank_account_number'] = 'Add the bank account for payment or at least one receiving agent.';
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    /** @param mixed $value */
    private function comparable(Offer $offer, string $field, $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (in_array($field, ['open_at', 'close_at'], true)) {
            return Carbon::parse($value)->format('Y-m-d H:i:s');
        }
        if (in_array($field, ['maturity_date', 'allotment_date'], true)) {
            return Carbon::parse($value)->format('Y-m-d');
        }
        if (is_numeric($value)) {
            return rtrim(rtrim(number_format((float) $value, 4, '.', ''), '0'), '.');
        }

        return (string) $value;
    }
}

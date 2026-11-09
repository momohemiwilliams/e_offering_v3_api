<?php

namespace App\Services\Allotment;

use App\Models\Allotment;
use App\Models\AllotmentItem;
use App\Models\Bid;
use App\Models\Offer;
use App\Models\OfferDocument;
use App\Models\User;
use App\Models\WorkflowAction;
use App\Services\AuditLogger;
use App\Services\CsdLodgement;
use App\Services\DocumentStore;
use App\Services\Notifier;
use App\Services\OfferWorkflow;
use App\Support\Money;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Book building -> allotment -> TSP authorisation -> SEC review -> CSD lodgement (CR s5.2-5.4).
 */
class AllotmentService
{
    private AllotmentEngine $engine;

    private AuditLogger $audit;

    private Notifier $notifier;

    private DocumentStore $documents;

    public function __construct(AllotmentEngine $engine, AuditLogger $audit, Notifier $notifier, DocumentStore $documents)
    {
        $this->engine = $engine;
        $this->audit = $audit;
        $this->notifier = $notifier;
        $this->documents = $documents;
    }

    /** s5.2 step 1: "Initiate Allotment" from the order book view. */
    public function initiate(Offer $offer, User $inputter): Allotment
    {
        if ($offer->status !== Offer::LIVE) {
            throw ValidationException::withMessages(['offer' => 'Only launched offers can be allotted.']);
        }
        if ($offer->close_at->isFuture()) {
            throw ValidationException::withMessages(['offer' => 'Allotment can start once the offer has closed ('.$offer->close_at->format('F j, Y g:i A').').']);
        }
        if ($offer->allotment) {
            return $offer->allotment;
        }

        $allotment = DB::transaction(function () use ($offer, $inputter) {
            $allotment = $offer->allotment()->create([
                'method' => ['method' => AllotmentEngine::METHOD_PRO_RATA, 'pro_rata_option' => 3],
                'issue_size' => $offer->offer_size,
                'final_rate' => $offer->price_discovery_method === Offer::FIXED_RATE ? $offer->rate : null,
                'status' => Allotment::DRAFT,
                'initiated_by' => $inputter->id,
            ]);
            $this->recalculate($allotment);

            return $allotment;
        });

        $this->audit->log('allotment.initiated', 'Initiated allotment for '.$offer->reference, $allotment, [], $inputter, $offer->id);

        return $allotment;
    }

    /** s5.2 steps 2 and 5: final rate and methodology. */
    public function configure(Allotment $allotment, User $inputter, array $method, $finalRate): void
    {
        $this->assertEditable($allotment);
        $offer = $allotment->offer;

        if ($offer->needsFinalRate() && ($finalRate === null || $finalRate === '')) {
            throw ValidationException::withMessages(['final_rate' => 'Enter the final '.strtolower($offer->rate_label).'.']);
        }

        $config = AllotmentConfig::fromArray(array_merge($method, ['final_rate' => $finalRate]));
        try {
            $config->steps();
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['method' => $e->getMessage()]);
        }
        if ($config->usesFinalRate() && $config->finalRate === null) {
            throw ValidationException::withMessages(['final_rate' => 'Rate-based allotment needs the final / clearing rate.']);
        }

        $allotment->forceFill([
            'method' => array_diff_key($config->toArray(), ['final_rate' => true]),
            'final_rate' => $finalRate === '' ? null : $finalRate,
        ])->save();
        $offer->forceFill(['final_rate' => $finalRate === '' ? null : $finalRate])->save();

        $this->recalculate($allotment);
        $this->audit->log('allotment.configured', 'Set allotment method to '.$allotment->fresh()->method_label, $allotment, ['method' => $allotment->method, 'final_rate' => $finalRate], $inputter, $offer->id);
    }

    /** s5.2 step 8: manual override with a mandatory reason; the rest is recalculated. */
    public function override(AllotmentItem $item, User $inputter, ?string $amount, string $reason): void
    {
        $allotment = $item->allotment;
        $this->assertEditable($allotment);
        if (trim($reason) === '') {
            throw ValidationException::withMessages(['reason' => 'A reason is required for a manual override.']);
        }

        if ($amount === null || $amount === '') {
            $item->forceFill(['override_amount' => null, 'override_reason' => null, 'overridden_by' => null, 'overridden_at' => null])->save();
            $label = 'Removed manual override';
        } else {
            $kobo = Money::toKobo($amount);
            if ($kobo < 0 || $kobo > Money::toKobo($item->subscribed)) {
                throw ValidationException::withMessages(['amount' => 'An allotment cannot exceed the amount subscribed.']);
            }
            $item->forceFill([
                'override_amount' => Money::fromKobo($kobo), 'override_reason' => $reason,
                'overridden_by' => $inputter->id, 'overridden_at' => now(),
            ])->save();
            $label = 'Overrode allotment to '.Money::format($amount);
        }

        $this->recalculate($allotment);
        $this->audit->log('allotment.override', $label.' for '.$item->bid->investor_name.' ('.$item->bid->reference.')', $allotment, [
            'bid' => $item->bid->reference, 'amount' => $amount, 'reason' => $reason,
        ], $inputter, $allotment->offer_id);
    }

    /**
     * Runs the engine against the current order book and stores per-investor results
     * (s5.2 steps 3-7).
     */
    public function recalculate(Allotment $allotment): void
    {
        $offer = $allotment->offer()->with('bids')->first();
        $issueSize = Money::toKobo($allotment->issue_size);

        $items = [];
        foreach ($offer->bids as $bid) {
            $items[$bid->id] = AllotmentItem::firstOrNew(['allotment_id' => $allotment->id, 'bid_id' => $bid->id]);
        }

        $eligible = [];
        $totalDemand = 0;
        $validDemand = 0;
        foreach ($offer->bids as $bid) {
            $item = $items[$bid->id];
            $amount = Money::toKobo($bid->amount);
            $item->subscribed = $bid->amount;
            $reason = null;
            if (! $bid->isActive()) {
                $reason = 'Revoked by investor';
            } elseif ($bid->payment_status === Bid::PAYMENT_INVALID) {
                $reason = 'Payment marked invalid';
            }
            if ($bid->isActive()) {
                $totalDemand += $amount;
            }
            $item->eligible = $reason === null;
            $item->ineligible_reason = $reason;
            if ($reason === null) {
                $validDemand += $amount;
                $eligible[] = new AllotmentBid(
                    $bid->id,
                    $amount,
                    $bid->rate !== null ? (float) $bid->rate : null,
                    $bid->submitted_at ? $bid->submitted_at->getTimestamp() : 0,
                    $bid->investor_type,
                    $item->override_amount !== null ? Money::toKobo($item->override_amount) : null
                );
            }
        }

        $level = $issueSize > 0 ? round($validDemand / $issueSize * 100, 2) : 0;
        $threshold = (float) $offer->min_subscription_threshold;
        if ($validDemand > $issueSize) {
            $status = Allotment::OVERSUBSCRIBED;
        } elseif ($validDemand === $issueSize) {
            $status = Allotment::FULLY_SUBSCRIBED;
        } elseif ($threshold > 0 && $level < $threshold) {
            $status = Allotment::BELOW_MINIMUM;
        } else {
            $status = Allotment::UNDERSUBSCRIBED;
        }

        $notes = [];
        $allocations = [];
        try {
            $config = $this->configFor($allotment);
            $result = $this->engine->run($eligible, $issueSize, Money::toKobo($offer->min_subscription_amount), $config);
            $allocations = $result->allocations;
            $notes = $result->notes;
        } catch (InvalidArgumentException $e) {
            $notes[] = $e->getMessage();
        }

        $price = (float) $offer->issue_price;
        $totalAllotted = 0;
        DB::transaction(function () use ($items, $allocations, $price, &$totalAllotted) {
            foreach ($items as $bidId => $item) {
                $allotted = $item->eligible ? ($allocations[$bidId] ?? 0) : 0;
                $subscribed = Money::toKobo($item->subscribed);
                $totalAllotted += $allotted;
                $item->allotted = Money::fromKobo($allotted);
                $item->released = Money::fromKobo(max(0, $subscribed - $allotted));
                $item->filled_pct = $subscribed > 0 ? round($allotted / $subscribed * 100, 2) : 0;
                $item->units = $price > 0 ? floor(($allotted / 100) / $price * 10000) / 10000 : 0;
                $item->save();
            }
        });

        $allotment->forceFill([
            'total_demand' => Money::fromKobo($totalDemand),
            'valid_demand' => Money::fromKobo($validDemand),
            'total_allotted' => Money::fromKobo($totalAllotted),
            'subscription_level' => $level,
            'subscription_status' => $status,
            'notes' => $notes,
        ])->save();
    }

    private function configFor(Allotment $allotment): AllotmentConfig
    {
        $config = $allotment->config();
        $config->higherRateWins = $allotment->offer->ranksHighestFirst();

        return $config;
    }

    /**
     * Final Validation (s5.2.1): never above the issue size or any subscription; a pro-rata
     * allotment of an oversubscribed book must equal the issue size exactly.
     *
     * @return string[] problems; empty when valid
     */
    public function validationErrors(Allotment $allotment): array
    {
        $errors = [];
        $issue = Money::toKobo($allotment->issue_size);
        $total = 0;
        foreach ($allotment->items as $item) {
            $allotted = Money::toKobo($item->allotted);
            $total += $allotted;
            if ($allotted > Money::toKobo($item->subscribed)) {
                $errors[] = 'An allotment exceeds the amount subscribed ('.$item->bid->reference.').';
            }
        }
        if ($total > $issue) {
            $errors[] = 'Total allotment exceeds the approved issue size.';
        }
        $isFcfs = in_array(AllotmentEngine::STEP_FCFS, $this->safeSteps($allotment), true);
        $config = $this->configFor($allotment);
        // FCFS and shared Hybrid can legitimately stop short; other methods must reach the target.
        if ($allotment->subscription_status === Allotment::OVERSUBSCRIBED && ! $isFcfs && ! $config->hasShares()) {
            $bids = $allotment->items->where('eligible', true)->map(function ($item) {
                return new AllotmentBid($item->bid_id, Money::toKobo($item->subscribed), $item->bid->rate !== null ? (float) $item->bid->rate : null);
            })->values()->all();
            $target = $this->engine->reachableTotal($bids, $issue, $config);
            if ($total !== $target) {
                $errors[] = 'Total allotment ('.Money::format(Money::fromKobo($total)).') must equal '.($target === $issue ? 'the approved issue size' : 'the total of bids within the final rate').' ('.Money::format(Money::fromKobo($target)).').';
            }
        }
        if ($allotment->subscription_status !== Allotment::OVERSUBSCRIBED) {
            $valid = Money::toKobo($allotment->valid_demand);
            if ($total !== $valid && ! $isFcfs && ! in_array(AllotmentEngine::STEP_RATE, $this->safeSteps($allotment), true)) {
                $hasOverride = $allotment->items->contains(function ($i) {
                    return $i->override_amount !== null;
                });
                if (! $hasOverride) {
                    $errors[] = 'Every valid subscription should be allotted in full when the offer is not oversubscribed.';
                }
            }
        }
        if ($allotment->offer->needsFinalRate() && $allotment->final_rate === null) {
            $errors[] = 'Enter the final rate before submitting.';
        }

        return $errors;
    }

    /** s5.2 step 10. */
    public function submit(Allotment $allotment, User $inputter, bool $continueBelowMinimum = false): void
    {
        $this->assertEditable($allotment);
        $this->recalculate($allotment);
        $allotment->refresh()->load('items.bid', 'offer');

        if ($allotment->subscription_status === Allotment::BELOW_MINIMUM && ! $continueBelowMinimum) {
            throw ValidationException::withMessages(['allotment' => 'Subscription is below the minimum threshold. Confirm that you want to continue, or cancel the offer.']);
        }
        $errors = $this->validationErrors($allotment);
        if ($errors !== []) {
            throw ValidationException::withMessages(['allotment' => implode(' ', $errors)]);
        }

        $allotment->forceFill([
            'status' => Allotment::PENDING_AUTHORISATION, 'submitted_by' => $inputter->id, 'submitted_at' => now(), 'return_reason' => null,
        ])->save();
        WorkflowAction::record($allotment, $allotment->offer_id, WorkflowAction::STAGE_INPUTTER, 'submitted', null, $inputter);
        $this->audit->log('allotment.submitted', 'Submitted allotment for '.$allotment->offer->reference.' for authorisation', $allotment, [], $inputter, $allotment->offer_id);

        $this->notifier->send(
            $this->notifier->tspUsers($allotment->offer->institution_id, User::AUTHORISER),
            'Allotment awaiting approval: '.$allotment->offer->reference,
            $inputter->name.' submitted the allotment ('.$allotment->method_label.') for '.$allotment->offer->issuer_name.'.',
            frontendUrl('/tsp/allotments/'.$allotment->offer->id)
        );
    }

    /** s5.3 steps 1 and 3. */
    public function authorise(Allotment $allotment, User $authoriser, bool $approve, ?string $reason): void
    {
        if ($allotment->status !== Allotment::PENDING_AUTHORISATION) {
            throw ValidationException::withMessages(['allotment' => 'This allotment is not waiting for authorisation.']);
        }
        $offer = $allotment->offer;

        if (! $approve) {
            $allotment->forceFill(['status' => Allotment::RETURNED, 'return_reason' => $reason])->save();
            WorkflowAction::record($allotment, $offer->id, WorkflowAction::STAGE_AUTHORISER, 'rejected', $reason, $authoriser);
            $this->audit->log('allotment.returned', 'Returned allotment for '.$offer->reference, $allotment, ['reason' => $reason], $authoriser, $offer->id);
            $this->notifier->send(
                $this->notifier->tspUsers($offer->institution_id, User::INPUTTER),
                'Allotment returned: '.$offer->reference,
                'The TSP Authoriser returned the allotment. Reason: '.$reason,
                frontendUrl('/tsp/allotments/'.$offer->id)
            );

            return;
        }

        $allotment->forceFill(['status' => Allotment::SEC_PENDING, 'authorised_by' => $authoriser->id, 'authorised_at' => now()])->save();
        WorkflowAction::record($allotment, $offer->id, WorkflowAction::STAGE_AUTHORISER, 'approved', $reason, $authoriser);
        $this->audit->log('allotment.authorised', 'Authorised allotment for '.$offer->reference.'; sent to SEC (SEC Pending)', $allotment, [], $authoriser, $offer->id);

        $this->notifier->send(
            $this->notifier->regulators(),
            'Allotment pending SEC approval: '.$offer->issuer_name,
            'The allotment schedule for '.$offer->reference.' ('.$offer->security_type_label.') is ready for your review.',
            frontendUrl('/regulator/allotments/'.$allotment->id)
        );
        $this->notifier->send(
            $this->notifier->tspUsers($offer->institution_id),
            'Allotment sent to SEC: '.$offer->reference,
            'The allotment was authorised and is now SEC Pending.',
            frontendUrl('/tsp/allotments/'.$offer->id)
        );
    }

    /** s3.3 step 5 and s5.3 steps 4-5. */
    public function secDecision(Allotment $allotment, User $regulator, bool $approve, ?string $reason): void
    {
        if ($allotment->status !== Allotment::SEC_PENDING) {
            throw ValidationException::withMessages(['allotment' => 'This allotment is not pending SEC review.']);
        }
        if (! $approve && trim((string) $reason) === '') {
            throw ValidationException::withMessages(['reason' => 'Give a reason for the rejection.']);
        }
        $offer = $allotment->offer;

        $allotment->forceFill([
            'status' => $approve ? Allotment::APPROVED : Allotment::SEC_REJECTED,
            'sec_reviewed_by' => $regulator->id,
            'sec_reviewed_at' => now(),
            'sec_reason' => $reason,
        ])->save();
        WorkflowAction::record($allotment, $offer->id, WorkflowAction::STAGE_SEC, $approve ? 'approved' : 'rejected', $reason, $regulator);
        $this->audit->log($approve ? 'allotment.sec_approved' : 'allotment.sec_rejected', ($approve ? 'SEC approved' : 'SEC rejected').' the allotment for '.$offer->reference, $allotment, ['reason' => $reason], $regulator, $offer->id);

        $this->notifier->send(
            $this->notifier->tspUsers($offer->institution_id),
            ($approve ? 'Allotment approved by SEC: ' : 'Allotment rejected by SEC: ').$offer->reference,
            $approve ? 'You can now initiate CSD lodgement.' : 'Reason: '.$reason.'. Update the order book / allotment schedule and resubmit.',
            frontendUrl('/tsp/allotments/'.$offer->id)
        );

        if ($approve) {
            foreach ($allotment->items()->with('bid.user')->get() as $item) {
                if (! $item->bid || ! $item->bid->user) {
                    continue;
                }
                $this->notifier->send(
                    $item->bid->user,
                    'Allotment outcome: '.$offer->issuer_name,
                    'You were allotted '.Money::format($item->allotted).' ('.number_format((float) $item->units, 4).' units) against your subscription of '.Money::format($item->subscribed).'.'
                        .((float) $item->released > 0 ? ' '.Money::format($item->released).' will be returned.' : ''),
                    frontendUrl('/investor/bids/'.$item->bid->id)
                );
            }
        }
    }

    /** s5.3 step 2: extra documents for SEC review (also the Sponsor letter). */
    public function attachDocument(Allotment $allotment, User $user, UploadedFile $file, string $type): OfferDocument
    {
        if (! in_array($allotment->status, [Allotment::DRAFT, Allotment::RETURNED, Allotment::SEC_REJECTED, Allotment::PENDING_AUTHORISATION], true)) {
            throw ValidationException::withMessages(['document' => 'Documents can only be added before the schedule goes to SEC.']);
        }
        $document = OfferDocument::create([
            'offer_id' => $allotment->offer_id,
            'allotment_id' => $allotment->id,
            'category' => $type === 'Letter from Sponsor' ? OfferDocument::SPONSOR_LETTER : OfferDocument::SEC_SUPPORT,
            'document_type' => $type,
            'original_name' => $file->getClientOriginalName(),
            'path' => $this->documents->store($file, 'allotments/'.$allotment->offer_id),
            'size' => (int) $file->getSize(),
            'visible_to_investors' => false,
            'uploaded_by' => $user->id,
        ]);
        $this->audit->log('allotment.document', 'Attached '.$type.' to the allotment for SEC review', $allotment, ['file' => $document->original_name], $user, $allotment->offer_id);

        return $document;
    }

    /** s5.2 step 4: undersubscribed below threshold -> TSP may cancel the offer. */
    public function cancelOffer(Allotment $allotment, User $inputter, string $reason): void
    {
        $this->assertEditable($allotment);
        $allotment->forceFill(['status' => Allotment::CANCELLED, 'return_reason' => $reason])->save();
        app(OfferWorkflow::class)->cancel($allotment->offer, $inputter, $reason);
    }

    /** s5.4. */
    public function lodge(Allotment $allotment, User $inputter, CsdLodgement $csd): void
    {
        if ($allotment->status !== Allotment::APPROVED) {
            throw ValidationException::withMessages(['allotment' => 'Only SEC-approved allotments can be lodged.']);
        }
        $result = $csd->transmit($allotment);

        $allotment->forceFill([
            'status' => Allotment::LODGED,
            'lodgement_reference' => $result['reference'],
            'lodgement_status' => $result['status'],
            'lodged_by' => $inputter->id,
            'lodged_at' => now(),
        ])->save();

        $this->audit->log('allotment.lodged', 'Transmitted the lodgement report for '.$allotment->offer->reference.' to the CSD ('.$result['reference'].')', $allotment, $result, $inputter, $allotment->offer_id);
        $this->notifier->send(
            $this->notifier->csdUsers(),
            'New lodgement: '.$allotment->offer->issuer_name,
            'The lodgement report for '.$allotment->offer->reference.' is available to download.',
            frontendUrl('/csd/lodgements/'.$allotment->id)
        );
    }

    /**
     * Basis of allotment shown to SEC: share of the total allotted by investor category (s3.3).
     *
     * @return array<string, array{allotted: string, percent: float, investors: int}>
     */
    public function basisOfAllotment(Allotment $allotment): array
    {
        $totals = ['retail' => 0, 'qii' => 0, 'non_qii' => 0];
        $counts = ['retail' => 0, 'qii' => 0, 'non_qii' => 0];
        $sum = 0;
        foreach ($allotment->items()->with('bid')->get() as $item) {
            $category = optional($item->bid)->investor_category ?? 'retail';
            $kobo = Money::toKobo($item->allotted);
            $totals[$category] = ($totals[$category] ?? 0) + $kobo;
            if ($kobo > 0) {
                $counts[$category] = ($counts[$category] ?? 0) + 1;
            }
            $sum += $kobo;
        }

        $basis = [];
        foreach ($totals as $category => $kobo) {
            $basis[$category] = [
                'allotted' => Money::fromKobo($kobo),
                'percent' => $sum > 0 ? round($kobo / $sum * 100, 2) : 0.0,
                'investors' => $counts[$category],
            ];
        }

        return $basis;
    }

    private function assertEditable(Allotment $allotment): void
    {
        if (! $allotment->isEditable()) {
            throw ValidationException::withMessages(['allotment' => 'The allotment can no longer be changed in its current status.']);
        }
    }

    private function safeSteps(Allotment $allotment): array
    {
        try {
            return $allotment->config()->steps();
        } catch (InvalidArgumentException $e) {
            return [];
        }
    }
}

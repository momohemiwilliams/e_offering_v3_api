<?php

namespace App\Services;

use App\Models\Bid;
use App\Models\BidEvent;
use App\Models\Offer;
use App\Models\User;
use App\Support\Money;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Investor bids: submission, amendment, revocation and payment evidence (CR s6.1-6.2),
 * plus the sponsor's Valid / Invalid review of payment evidence (s5.1 step 4).
 */
class BidService
{
    private const TRACKED = ['amount', 'rate', 'investor_type', 'payee_name', 'csd_account_number', 'bank_account_number'];

    private AuditLogger $audit;

    private Notifier $notifier;

    private DocumentStore $documents;

    public function __construct(AuditLogger $audit, Notifier $notifier, DocumentStore $documents)
    {
        $this->audit = $audit;
        $this->notifier = $notifier;
        $this->documents = $documents;
    }

    public function place(Offer $offer, User $investor, array $data, ?UploadedFile $evidence = null): Bid
    {
        $this->assertCanBid($offer, $investor);
        $this->assertTerms($offer, $data);
        $payee = $this->resolvePayee($offer, $data);

        $bid = DB::transaction(function () use ($offer, $investor, $data, $payee, $evidence) {
            $bid = Bid::create(array_merge($this->bidFields($data), $payee, [
                'offer_id' => $offer->id,
                'user_id' => $investor->id,
                'institution_id' => $investor->institution_id,
                'investor_category' => $investor->investorCategory(),
                'investor_name' => $investor->institution ? $investor->institution->name : $investor->name,
                'payment_status' => Bid::PAYMENT_PENDING,
                'status' => Bid::ACTIVE,
                'submitted_at' => now(),
            ]));
            $this->event($bid, $investor, 'submitted', null, $this->snapshot($bid));

            if ($evidence) {
                $this->storeEvidence($bid, $investor, $evidence, $data['payment_reference'] ?? null);
            }

            return $bid;
        });

        $this->audit->log('bid.submitted', 'Submitted bid '.$bid->reference.' of '.Money::format($bid->amount).' on '.$offer->reference, $bid, [], $investor, $offer->id);
        $this->notifySponsor($offer, 'New bid on '.$offer->reference, $bid->investor_name.' placed a bid of '.Money::format($bid->amount).'.');

        return $bid;
    }

    public function amend(Bid $bid, User $investor, array $data): Bid
    {
        $this->assertOwnedAndChangeable($bid, $investor);
        $offer = $bid->offer;
        $this->assertTerms($offer, $data);
        $before = $this->snapshot($bid);

        $bid->fill(array_merge($this->bidFields($data), $this->resolvePayee($offer, $data)))->save();
        $this->event($bid, $investor, 'amended', $before, $this->snapshot($bid));
        $this->audit->log('bid.amended', 'Amended bid '.$bid->reference, $bid, ['before' => $before, 'after' => $this->snapshot($bid)], $investor, $offer->id);
        $this->notifySponsor($offer, 'Bid amended on '.$offer->reference, $bid->investor_name.' amended bid '.$bid->reference.' to '.Money::format($bid->amount).'.');

        return $bid;
    }

    public function revoke(Bid $bid, User $investor): void
    {
        $this->assertOwnedAndChangeable($bid, $investor);
        $bid->forceFill(['status' => Bid::REVOKED, 'revoked_at' => now()])->save();
        $this->event($bid, $investor, 'revoked');
        $this->audit->log('bid.revoked', 'Revoked bid '.$bid->reference, $bid, [], $investor, $bid->offer_id);
        $this->notifySponsor($bid->offer, 'Bid revoked on '.$bid->offer->reference, $bid->investor_name.' revoked bid '.$bid->reference.'.');
    }

    public function uploadEvidence(Bid $bid, User $investor, UploadedFile $file, ?string $reference): void
    {
        if ($bid->user_id !== $investor->id || ! $bid->isActive()) {
            throw ValidationException::withMessages(['evidence' => 'You cannot upload evidence for this bid.']);
        }
        if ($bid->payment_status === Bid::PAYMENT_PAID) {
            throw ValidationException::withMessages(['evidence' => 'Payment has already been confirmed.']);
        }
        if ($bid->offer->allotmentStarted()) {
            throw ValidationException::withMessages(['evidence' => 'Allotment has started on this offer.']);
        }
        $this->storeEvidence($bid, $investor, $file, $reference);
        $this->notifySponsor($bid->offer, 'Payment evidence uploaded', $bid->investor_name.' uploaded payment evidence for bid '.$bid->reference.'.');
    }

    /** CR s5.1 step 4 / s6.2 step 5: Valid (=> Paid) or Invalid with a mandatory comment. */
    public function reviewPayment(Bid $bid, User $reviewer, bool $valid, ?string $comment): void
    {
        if (! $valid && trim((string) $comment) === '') {
            throw ValidationException::withMessages(['comment' => 'A comment is required when payment is marked Invalid.']);
        }
        if ($bid->offer->allotment && ! $bid->offer->allotment->isEditable()) {
            throw ValidationException::withMessages(['bid' => 'The allotment has been submitted; payments can no longer change.']);
        }

        $before = ['payment_status' => $bid->payment_status];
        $bid->forceFill([
            'payment_status' => $valid ? Bid::PAYMENT_PAID : Bid::PAYMENT_INVALID,
            'payment_comment' => $comment,
            'payment_reviewed_by' => $reviewer->id,
            'payment_reviewed_at' => now(),
        ])->save();

        $this->event($bid, $reviewer, $valid ? 'payment_valid' : 'payment_invalid', $before, ['payment_status' => $bid->payment_status], $comment);
        $this->audit->log($valid ? 'bid.payment_valid' : 'bid.payment_invalid', 'Marked payment for bid '.$bid->reference.' '.($valid ? 'Valid' : 'Invalid'), $bid, ['comment' => $comment], $reviewer, $bid->offer_id);

        $this->notifier->send(
            $bid->user,
            $valid ? 'Payment confirmed: '.$bid->offer->issuer_name : 'Payment evidence rejected: '.$bid->offer->issuer_name,
            $valid
                ? 'The sponsor confirmed receipt of your payment for bid '.$bid->reference.'. Status: Paid.'
                : 'The sponsor could not validate your payment evidence for bid '.$bid->reference.'. Comment: '.$comment,
            frontendUrl('/investor/bids/'.$bid->id)
        );
    }

    private function storeEvidence(Bid $bid, User $investor, UploadedFile $file, ?string $reference): void
    {
        $this->documents->delete($bid->payment_evidence_path);
        $bid->forceFill([
            'payment_evidence_path' => $this->documents->store($file, 'payments/'.$bid->offer_id),
            'payment_reference' => $reference ?: $bid->payment_reference,
            'payment_uploaded_at' => now(),
            'payment_status' => Bid::PAYMENT_PENDING,
            'payment_comment' => null,
        ])->save();
        $this->event($bid, $investor, 'payment_uploaded', null, ['payment_reference' => $bid->payment_reference]);
    }

    private function assertCanBid(Offer $offer, User $investor): void
    {
        if (! $investor->isInvestor()) {
            throw ValidationException::withMessages(['offer' => 'Only investors can place bids.']);
        }
        if (! $offer->isOpenForBidding()) {
            throw ValidationException::withMessages(['offer' => 'This offer is not open for bidding.']);
        }
        if (! in_array($investor->investorCategory(), (array) $offer->investor_universe, true)) {
            throw ValidationException::withMessages(['offer' => 'This offer is not available to your investor category.']);
        }
    }

    /**
     * Minimum Subscription Amount and increments (s6.1 step 2) and rate limits by method.
     */
    private function assertTerms(Offer $offer, array $data): void
    {
        $amount = Money::toKobo($data['amount'] ?? 0);
        $minimum = Money::toKobo($offer->min_subscription_amount);
        $increment = Money::toKobo($offer->subsequent_increment);

        if ($amount <= 0) {
            throw ValidationException::withMessages(['amount' => 'Enter the amount you want to subscribe for.']);
        }
        if ($amount < $minimum) {
            throw ValidationException::withMessages(['amount' => 'The minimum bid amount is '.Money::format($offer->min_subscription_amount).'.']);
        }
        if ($increment > 0 && ($amount - $minimum) % $increment !== 0) {
            throw ValidationException::withMessages(['amount' => 'Amounts above the minimum must be in multiples of '.Money::format($offer->subsequent_increment).'.']);
        }

        $rate = $data['rate'] ?? null;
        if ($offer->price_discovery_method !== Offer::FIXED_RATE && ($rate === null || $rate === '')) {
            throw ValidationException::withMessages(['rate' => 'Enter your bid '.strtolower($offer->rate_label).'.']);
        }
        if ($offer->price_discovery_method === Offer::BOOK_BUILDING && $rate !== null && $rate !== '') {
            if (($offer->rate_min !== null && (float) $rate < (float) $offer->rate_min) || ($offer->rate_max !== null && (float) $rate > (float) $offer->rate_max)) {
                throw ValidationException::withMessages(['rate' => 'Your bid must be within the range '.(float) $offer->rate_min.' to '.(float) $offer->rate_max.'.']);
            }
        }
    }

    private function bidFields(array $data): array
    {
        return [
            'investor_type' => $data['investor_type'],
            'amount' => Money::fromKobo(Money::toKobo($data['amount'])),
            'rate' => isset($data['rate']) && $data['rate'] !== '' ? $data['rate'] : null,
            'yield' => isset($data['yield']) && $data['yield'] !== '' ? $data['yield'] : null,
            'csd_name' => $data['csd_name'] ?? null,
            'csd_account_number' => $data['csd_account_number'] ?? null,
            'bank_name' => $data['bank_name'] ?? null,
            'bank_account_name' => $data['bank_account_name'] ?? null,
            'bank_account_number' => $data['bank_account_number'] ?? null,
        ];
    }

    /** s6.2: receiving agent / co-sponsor chosen from the offer's list, else the main sponsor. */
    private function resolvePayee(Offer $offer, array $data): array
    {
        $offer->loadMissing('receivingAgents', 'coSponsors.institution', 'institution');
        $options = $offer->payeeOptions();
        $type = $data['payee_type'] ?? $options[0]['type'];
        $id = isset($data['payee_id']) && $data['payee_id'] !== '' ? (int) $data['payee_id'] : null;

        foreach ($options as $option) {
            if ($option['type'] === $type && ($option['id'] === null || $option['id'] === $id)) {
                return [
                    'payee_type' => $option['type'],
                    'payee_id' => $option['id'],
                    'payee_name' => $option['name'],
                    'payee_bank_name' => $option['bank_name'],
                    'payee_account_name' => $option['account_name'],
                    'payee_account_number' => $option['account_number'],
                    'placed_through_institution_id' => $option['institution_id'] ?? $offer->institution_id,
                ];
            }
        }

        throw ValidationException::withMessages(['payee_id' => 'Choose who you are paying.']);
    }

    private function assertOwnedAndChangeable(Bid $bid, User $investor): void
    {
        if ($bid->user_id !== $investor->id) {
            abort(403);
        }
        if (! $bid->canBeChangedByInvestor()) {
            throw ValidationException::withMessages(['bid' => 'Bids can only be changed while the offer is open.']);
        }
    }

    private function snapshot(Bid $bid): array
    {
        return array_intersect_key($bid->only(self::TRACKED), array_flip(self::TRACKED));
    }

    private function event(Bid $bid, User $user, string $action, ?array $before = null, ?array $after = null, ?string $comment = null): void
    {
        BidEvent::create([
            'bid_id' => $bid->id, 'user_id' => $user->id, 'action' => $action,
            'before' => $before, 'after' => $after, 'comment' => $comment, 'created_at' => now(),
        ]);
    }

    private function notifySponsor(Offer $offer, string $title, string $message): void
    {
        $this->notifier->send($this->notifier->tspUsers($offer->institution_id), $title, $message, frontendUrl('/tsp/order-book/'.$offer->id));
    }
}

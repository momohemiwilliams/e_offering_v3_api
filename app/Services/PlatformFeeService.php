<?php

namespace App\Services;

use App\Models\Institution;
use App\Models\PlatformFeeInvoice;
use App\Models\Setting;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Annual TSP/RM platform fee, decoupled from offer creation (CR s3.2 steps 7-12).
 * Switched off for the first year of launch (setting "platform_fee_enabled").
 */
class PlatformFeeService
{
    private AuditLogger $audit;

    private Notifier $notifier;

    private DocumentStore $documents;

    public function __construct(AuditLogger $audit, Notifier $notifier, DocumentStore $documents)
    {
        $this->audit = $audit;
        $this->notifier = $notifier;
        $this->documents = $documents;
    }

    public function enabled(): bool
    {
        return (bool) Setting::get('platform_fee_enabled');
    }

    /**
     * Full access to the Transaction Sponsor modules (s3.2 step 10): registration done and,
     * while the fee is enabled, a paid year that has not lapsed.
     */
    public function hasFullAccess(Institution $institution): bool
    {
        if ($institution->isSuspended() || ! $institution->registration_completed_at) {
            return false;
        }
        if (! $this->enabled()) {
            return true;
        }

        return $institution->fee_paid_until !== null && $institution->fee_paid_until->endOfDay()->isFuture();
    }

    /** The invoice the TSP should pay now, created on demand. */
    public function currentInvoice(Institution $institution): ?PlatformFeeInvoice
    {
        if (! $this->enabled()) {
            return null;
        }

        $open = $institution->feeInvoices()->whereIn('status', [PlatformFeeInvoice::UNPAID, PlatformFeeInvoice::REJECTED, PlatformFeeInvoice::PENDING_REVIEW])
            ->latest('id')->first();
        if ($open) {
            return $open;
        }
        if ($institution->fee_paid_until && $institution->fee_paid_until->copy()->subDays((int) config('eoffering.platform_fee.invoice_days_before_due', 30))->isFuture()) {
            return null;
        }

        return $this->createInvoice($institution);
    }

    public function createInvoice(Institution $institution): PlatformFeeInvoice
    {
        $start = $institution->fee_paid_until ? $institution->fee_paid_until->copy()->addDay() : Carbon::today();

        return $institution->feeInvoices()->create([
            'period_start' => $start,
            'period_end' => $start->copy()->addYear()->subDay(),
            'due_date' => $institution->fee_paid_until ? $institution->fee_paid_until->copy() : Carbon::today()->addDays(30),
            'amount' => (string) Setting::get('platform_fee_amount'),
            'status' => PlatformFeeInvoice::UNPAID,
        ]);
    }

    /** Paystack / Flutterwave: returns the hosted checkout URL. */
    public function startGatewayPayment(PlatformFeeInvoice $invoice, string $gateway, User $payer, string $callbackUrl): string
    {
        $this->assertPayable($invoice);
        $reference = $invoice->reference.'-'.time();

        if ($gateway === 'paystack') {
            $response = Http::withToken((string) config('services.paystack.secret_key'))->asJson()
                ->post(rtrim((string) config('services.paystack.url'), '/').'/transaction/initialize', [
                    'email' => $payer->email,
                    'amount' => (int) round((float) $invoice->amount * 100),
                    'reference' => $reference,
                    'callback_url' => $callbackUrl,
                    'metadata' => ['invoice' => $invoice->reference],
                ]);
            $url = $response->json('data.authorization_url');
        } elseif ($gateway === 'flutterwave') {
            $response = Http::withToken((string) config('services.flutterwave.secret_key'))->asJson()
                ->post(rtrim((string) config('services.flutterwave.url'), '/').'/payments', [
                    'tx_ref' => $reference,
                    'amount' => (string) $invoice->amount,
                    'currency' => 'NGN',
                    'redirect_url' => $callbackUrl,
                    'customer' => ['email' => $payer->email, 'name' => $payer->name],
                    'customizations' => ['title' => 'FMDQ e-Offering platform fee'],
                ]);
            $url = $response->json('data.link');
        } else {
            throw ValidationException::withMessages(['method' => 'Unknown payment method.']);
        }

        if ($response->failed() || ! $url) {
            throw ValidationException::withMessages(['method' => 'The payment gateway could not be reached. Try again or pay by bank transfer.']);
        }

        $invoice->forceFill(['method' => $gateway, 'gateway_reference' => $reference])->save();

        return (string) $url;
    }

    /** Verifies the gateway callback server-side; a confirmed payment then waits for FMDQ Admin review. */
    public function confirmGatewayPayment(PlatformFeeInvoice $invoice, string $reference, ?string $transactionId = null): bool
    {
        if ($invoice->gateway_reference !== $reference) {
            return false;
        }

        try {
            if ($invoice->method === 'paystack') {
                $response = Http::withToken((string) config('services.paystack.secret_key'))
                    ->get(rtrim((string) config('services.paystack.url'), '/').'/transaction/verify/'.rawurlencode($reference));
                $ok = $response->json('data.status') === 'success' && (int) $response->json('data.amount') === (int) round((float) $invoice->amount * 100);
            } elseif ($invoice->method === 'flutterwave' && $transactionId) {
                $response = Http::withToken((string) config('services.flutterwave.secret_key'))
                    ->get(rtrim((string) config('services.flutterwave.url'), '/').'/transactions/'.rawurlencode($transactionId).'/verify');
                $ok = $response->json('data.status') === 'successful' && (float) $response->json('data.amount') >= (float) $invoice->amount
                    && $response->json('data.tx_ref') === $reference;
            } else {
                return false;
            }
        } catch (RuntimeException $e) {
            report($e);

            return false;
        }

        if (! $ok) {
            return false;
        }

        $invoice->forceFill([
            'status' => PlatformFeeInvoice::PENDING_REVIEW,
            'gateway_response' => (array) $response->json('data'),
            'paid_at' => now(),
        ])->save();
        $this->submitted($invoice);

        return true;
    }

    public function submitBankTransfer(PlatformFeeInvoice $invoice, UploadedFile $evidence, ?string $reference): void
    {
        $this->assertPayable($invoice);
        $this->documents->delete($invoice->evidence_path);
        $invoice->forceFill([
            'method' => 'bank_transfer',
            'gateway_reference' => $reference,
            'evidence_path' => $this->documents->store($evidence, 'fees/'.$invoice->institution_id),
            'status' => PlatformFeeInvoice::PENDING_REVIEW,
            'paid_at' => now(),
            'review_comment' => null,
        ])->save();
        $this->submitted($invoice);
    }

    /** s3.2 steps 8-10. */
    public function approve(PlatformFeeInvoice $invoice, User $admin): void
    {
        $invoice->forceFill(['status' => PlatformFeeInvoice::PAID, 'reviewed_by' => $admin->id, 'reviewed_at' => now()])->save();
        $institution = $invoice->institution;
        $paidUntil = $institution->fee_paid_until && $institution->fee_paid_until->greaterThan($invoice->period_end)
            ? $institution->fee_paid_until : $invoice->period_end;
        $institution->forceFill([
            'fee_paid_until' => $paidUntil,
            'suspended_at' => null,
            'status' => $institution->status === Institution::STATUS_SUSPENDED ? Institution::STATUS_APPROVED : $institution->status,
        ])->save();

        $this->audit->log('fee.approved', 'Approved platform fee '.$invoice->reference.' for '.$institution->name, $invoice, [], $admin);
        $this->notifier->send(
            $this->notifier->tspUsers($institution->id),
            'Platform fee payment confirmed',
            'FMDQ confirmed your platform fee payment '.$invoice->reference.'. Your receipt is ready and you have full access until '.$paidUntil->format('F j, Y').'.',
            frontendUrl('/tsp/registration/fee/'.$invoice->id.'/receipt')
        );
    }

    public function reject(PlatformFeeInvoice $invoice, User $admin, string $comment): void
    {
        $invoice->forceFill([
            'status' => PlatformFeeInvoice::REJECTED, 'reviewed_by' => $admin->id, 'reviewed_at' => now(), 'review_comment' => $comment,
        ])->save();
        $this->audit->log('fee.rejected', 'Rejected platform fee '.$invoice->reference, $invoice, ['comment' => $comment], $admin);
        $this->notifier->send(
            $this->notifier->tspUsers($invoice->institution_id),
            'Platform fee payment not confirmed',
            'FMDQ could not confirm payment '.$invoice->reference.'. Comment: '.$comment,
            frontendUrl('/tsp/registration')
        );
    }

    /**
     * Scheduled daily (s3.2 steps 11-12): renewal invoices ahead of the due date and
     * suspension of TSPs whose fee lapsed; payment approval reinstates them.
     *
     * @return array{invoiced: int, suspended: int}
     */
    public function runRenewals(): array
    {
        $invoiced = 0;
        $suspended = 0;
        if (! $this->enabled()) {
            return compact('invoiced', 'suspended');
        }

        $leadDays = (int) config('eoffering.platform_fee.invoice_days_before_due', 30);
        $tsps = Institution::where('type', Institution::TYPE_TSP)
            ->whereIn('status', [Institution::STATUS_APPROVED, Institution::STATUS_SUSPENDED])
            ->whereNotNull('fee_paid_until')->get();

        foreach ($tsps as $institution) {
            $hasOpen = $institution->feeInvoices()->whereIn('status', [PlatformFeeInvoice::UNPAID, PlatformFeeInvoice::PENDING_REVIEW, PlatformFeeInvoice::REJECTED])->exists();

            if (! $hasOpen && $institution->fee_paid_until->copy()->subDays($leadDays)->lessThanOrEqualTo(Carbon::today())) {
                $invoice = $this->createInvoice($institution);
                $invoiced++;
                $this->notifier->send(
                    $this->notifier->tspUsers($institution->id),
                    'Annual platform fee invoice '.$invoice->reference,
                    'Your e-Offering platform fee for '.$invoice->period_start->format('F j, Y').' to '.$invoice->period_end->format('F j, Y').' is due on '.$invoice->due_date->format('F j, Y').'.',
                    frontendUrl('/tsp/registration')
                );
            }

            if ($institution->fee_paid_until->endOfDay()->isPast() && ! $institution->suspended_at) {
                $institution->forceFill(['suspended_at' => now(), 'status' => Institution::STATUS_SUSPENDED])->save();
                $suspended++;
                $this->audit->log('fee.suspended', 'Suspended '.$institution->name.' for an unpaid platform fee', $institution, [], null);
                $this->notifier->send(
                    $this->notifier->tspUsers($institution->id),
                    'e-Offering access suspended',
                    'Your platform fee was not paid by the due date, so access to the Transaction Sponsor modules is suspended. Access is restored as soon as payment is confirmed.',
                    frontendUrl('/tsp/registration')
                );
            }
        }

        return compact('invoiced', 'suspended');
    }

    private function assertPayable(PlatformFeeInvoice $invoice): void
    {
        if (! $invoice->isOpen()) {
            throw ValidationException::withMessages(['invoice' => 'This invoice is not awaiting payment.']);
        }
    }

    private function submitted(PlatformFeeInvoice $invoice): void
    {
        $this->audit->log('fee.submitted', 'Submitted payment for platform fee '.$invoice->reference.' ('.$invoice->method.')', $invoice);
        $this->notifier->send(
            $this->notifier->admins(),
            'Platform fee payment to review',
            optional($invoice->institution)->name.' submitted payment '.$invoice->reference.' by '.str_replace('_', ' ', (string) $invoice->method).'.',
            frontendUrl('/admin/fees')
        );
    }
}

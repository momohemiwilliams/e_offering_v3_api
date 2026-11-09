<?php

namespace App\Http\Controllers;

use App\Models\PlatformFeeInvoice;
use App\Models\Setting;
use App\Services\AuditLogger;
use App\Services\PlatformFeeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** The "Registration" module a TSP/RM lands on after approval (CR s3.2 steps 5-12). */
class RegistrationController extends Controller
{
    private PlatformFeeService $fees;

    public function __construct(PlatformFeeService $fees)
    {
        $this->fees = $fees;
    }

    public function show(Request $request): JsonResponse
    {
        $institution = $request->user()->institution;
        $invoice = $institution->registration_completed_at ? $this->fees->currentInvoice($institution) : null;

        return successResponse('Registration fetched.', [
            'institution' => $institution->only([
                'id', 'name', 'reference', 'email', 'registered_name', 'registered_address', 'contact_name', 'contact_role',
                'contact_phone', 'registration_completed_at', 'bank_name', 'bank_account_name', 'bank_account_number',
                'fee_paid_until', 'suspended_at', 'status',
            ]),
            'feeEnabled' => $this->fees->enabled(),
            'fullAccess' => $this->fees->hasFullAccess($institution),
            'invoice' => $invoice,
            'invoices' => $institution->feeInvoices()->latest('id')->get(),
            'bank' => [
                'bank_name' => Setting::get('platform_fee_bank_name'),
                'account_name' => Setting::get('platform_fee_account_name'),
                'account_number' => Setting::get('platform_fee_account_number'),
            ],
            'gateways' => config('eoffering.platform_fee.gateways'),
        ]);
    }

    /** Registered details and contact person (s3.2 step 6). */
    public function update(Request $request, AuditLogger $audit): JsonResponse
    {
        $data = $request->validate([
            'registered_name' => ['required', 'string', 'max:191'],
            'registered_address' => ['required', 'string', 'max:255'],
            'contact_name' => ['required', 'string', 'max:191'],
            'contact_role' => ['required', 'string', 'max:100'],
            'contact_phone' => ['required', 'string', 'max:30'],
            'bank_name' => ['nullable', 'string', 'max:100'],
            'bank_account_name' => ['nullable', 'string', 'max:191'],
            'bank_account_number' => ['nullable', 'digits:10'],
        ]);

        $institution = $request->user()->institution;
        $first = $institution->registration_completed_at === null;
        $institution->fill($data);
        if ($first) {
            $institution->registration_completed_at = now();
        }
        $institution->save();
        $audit->log('tsp.registration', ($first ? 'Completed' : 'Updated') . ' TSP/RM registration details', $institution, [], $request->user());

        return successResponse($this->fees->hasFullAccess($institution)
            ? 'Registration saved. You now have full access to the Transaction Sponsor modules.'
            : 'Registration saved. Pay the annual platform fee below to unlock the Transaction Sponsor modules.');
    }

    public function payOnline(Request $request, PlatformFeeInvoice $invoice): JsonResponse
    {
        $this->own($request, $invoice);
        $data = $request->validate(['method' => ['required', Rule::in(['paystack', 'flutterwave'])]]);

        $url = $this->fees->startGatewayPayment($invoice, $data['method'], $request->user(), frontendUrl('/tsp/registration?invoice=' . $invoice->id));

        return successResponse('Continue to payment.', ['redirect' => $url]);
    }

    /** The gateway returns the browser to the Vue app, which confirms the payment here. */
    public function confirm(Request $request, PlatformFeeInvoice $invoice): JsonResponse
    {
        $this->own($request, $invoice);
        $data = $request->validate([
            'reference' => ['required', 'string', 'max:191'],
            'transaction_id' => ['nullable', 'string', 'max:191'],
        ]);

        if (!$this->fees->confirmGatewayPayment($invoice, $data['reference'], $data['transaction_id'] ?? null)) {
            return errorResponse(422, 'We could not confirm the payment. If you were debited, contact FMDQ with reference ' . $data['reference'] . '.');
        }

        return successResponse('Payment received. FMDQ will confirm it shortly.');
    }

    public function bankTransfer(Request $request, PlatformFeeInvoice $invoice): JsonResponse
    {
        $this->own($request, $invoice);
        $data = $request->validate([
            'evidence' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:5120'],
            'reference' => ['nullable', 'string', 'max:100'],
        ]);

        $this->fees->submitBankTransfer($invoice, $request->file('evidence'), $data['reference'] ?? null);

        return successResponse('Evidence of payment uploaded. FMDQ will review it.');
    }

    /** Printable receipt (s3.2 step 9). */
    public function receipt(Request $request, PlatformFeeInvoice $invoice): JsonResponse
    {
        $this->own($request, $invoice);
        abort_unless($invoice->status === PlatformFeeInvoice::PAID, 404);

        return successResponse('Receipt fetched.', ['invoice' => $invoice->load('institution', 'reviewer:id,name')]);
    }

    private function own(Request $request, PlatformFeeInvoice $invoice): void
    {
        abort_unless($invoice->institution_id === $request->user()->institution_id, 403);
    }
}

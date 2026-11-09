<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Commitment form (CR s6.1). Business rules (minimum amount, increments, rate range,
 * investor universe, open window) are enforced in BidService.
 */
class BidRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('amount'))) {
            $this->merge(['amount' => str_replace([',', ' '], '', $this->input('amount'))]);
        }
    }

    public function rules(): array
    {
        return [
            'investor_type' => ['required', Rule::in(array_keys(config('eoffering.investor_types')))],
            'amount' => ['required', 'numeric', 'gt:0'],
            'rate' => ['nullable', 'numeric', 'gt:0', 'max:1000000'],
            'yield' => ['nullable', 'numeric', 'min:0', 'max:1000'],
            'csd_name' => ['required', 'string', 'max:60'],
            'csd_account_number' => ['required', 'string', 'max:40'],
            'bank_name' => ['required', 'string', 'max:100'],
            'bank_account_name' => ['required', 'string', 'max:191'],
            'bank_account_number' => ['required', 'digits:10'],
            'payee_type' => ['required', Rule::in(['sponsor', 'co_sponsor', 'receiving_agent'])],
            'payee_id' => ['nullable', 'integer'],
            'payment_reference' => ['nullable', 'string', 'max:100'],
            'evidence' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:5120'],
            'declaration' => ['accepted'],
        ];
    }

    public function messages(): array
    {
        return [
            'declaration.accepted' => 'Confirm that you have read the offer documents and that the details are correct.',
            'bank_account_number.digits' => 'Enter a 10-digit NUBAN account number.',
        ];
    }

    public function attributes(): array
    {
        return [
            'csd_name' => 'CSD',
            'csd_account_number' => 'CSD account number (CHN / BPID)',
            'payee_type' => 'payee',
        ];
    }
}

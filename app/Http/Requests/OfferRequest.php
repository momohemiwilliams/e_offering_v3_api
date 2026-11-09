<?php

namespace App\Http\Requests;

use App\Models\Institution;
use App\Models\Offer;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * CR s4.1 / s4.2 / s4.3 offer data requirements.
 */
class OfferRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $clean = function ($v) {
            return is_string($v) ? str_replace([',', ' '], '', $v) : $v;
        };
        $this->merge([
            'offer_size' => $clean($this->input('offer_size')),
            'min_subscription_amount' => $clean($this->input('min_subscription_amount')),
            'subsequent_increment' => $clean($this->input('subsequent_increment')),
            'uses_receiving_agents' => $this->boolean('uses_receiving_agents'),
        ]);
    }

    public function rules(): array
    {
        $types = config('eoffering.security_types');
        $securityType = (string) $this->input('security_type');
        $hasTenor = (bool) ($types[$securityType]['has_tenor'] ?? true);
        $universe = (array) ($types[$securityType]['universe'] ?? []);
        $method = (string) $this->input('price_discovery_method');
        $isCreate = $this->isMethod('post');

        return [
            'issuer_name' => ['required', 'string', 'max:191'],
            'issue_description' => ['required', 'string', 'max:2000'],
            'issue_series' => ['nullable', 'string', 'max:100'],
            'offer_size' => ['required', 'numeric', 'gt:0'],
            'min_subscription_threshold' => ['required', 'numeric', 'between:0,100'],
            'issuer_type' => ['required', Rule::in(config('eoffering.issuer_types'))],
            'security_type' => ['required', Rule::in(array_keys($types))],
            'open_at' => array_filter(['required', 'date', $isCreate ? 'after:now' : null]),
            'close_at' => ['required', 'date', 'after:open_at'],
            'min_subscription_amount' => ['required', 'numeric', 'gt:0'],
            'subsequent_increment' => ['required', 'numeric', 'min:0'],
            'tenor' => [$hasTenor ? 'required' : 'nullable', 'string', 'max:60'],
            'day_count_fraction' => ['nullable', Rule::in(config('eoffering.day_count_fractions'))],
            'price_discovery_method' => ['required', Rule::in(array_keys(config('eoffering.price_discovery_methods')))],
            'investor_universe' => ['required', 'array', 'min:1'],
            'investor_universe.*' => [Rule::in($universe)],

            'rate' => [$method === Offer::FIXED_RATE ? 'required' : 'nullable', 'numeric', 'gt:0'],
            'rate_min' => [$method === Offer::BOOK_BUILDING ? 'required' : 'nullable', 'numeric', 'gt:0'],
            'rate_max' => [$method === Offer::BOOK_BUILDING ? 'required' : 'nullable', 'numeric', 'gte:rate_min'],
            'implied_yield' => ['required', 'numeric', 'min:0'],
            'issue_price' => ['required', 'numeric', 'gt:0'],
            'maturity_date' => [$hasTenor ? 'required' : 'nullable', 'date', 'after:close_at'],
            'allotment_date' => ['required', 'date', 'after_or_equal:close_at'],

            'uses_receiving_agents' => ['boolean'],
            'bank_name' => ['required', 'string', 'max:100'],
            'bank_account_name' => ['required', 'string', 'max:191'],
            'bank_account_number' => ['required', 'digits:10'],

            'receiving_agents' => ['array', 'max:200'],
            'receiving_agents.*.name' => ['required', 'string', 'max:191'],
            'receiving_agents.*.bank_name' => ['nullable', 'string', 'max:100'],
            'receiving_agents.*.bank_account_name' => ['required', 'string', 'max:191'],
            'receiving_agents.*.bank_account_number' => ['required', 'digits:10'],

            'co_sponsors' => ['array', 'max:20'],
            'co_sponsors.*.institution_id' => ['required', 'distinct', Rule::exists('institutions', 'id')->where('type', Institution::TYPE_TSP)->where('status', Institution::STATUS_APPROVED)],
            'co_sponsors.*.can_view_order_book' => ['boolean'],
            'co_sponsors.*.can_confirm_payments' => ['boolean'],
            'co_sponsors.*.bank_name' => ['nullable', 'string', 'max:100'],
            'co_sponsors.*.bank_account_name' => ['nullable', 'string', 'max:191'],
            'co_sponsors.*.bank_account_number' => ['nullable', 'digits:10'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v) {
            if ($this->boolean('uses_receiving_agents') && count((array) $this->input('receiving_agents', [])) === 0) {
                $v->errors()->add('receiving_agents', 'Add at least one receiving agent, or switch receiving agents off.');
            }
            foreach ((array) $this->input('co_sponsors', []) as $i => $co) {
                if ((int) ($co['institution_id'] ?? 0) === (int) optional($this->user())->institution_id) {
                    $v->errors()->add("co_sponsors.$i.institution_id", 'You cannot add your own institution as a co-sponsor.');
                }
            }
        });
    }

    public function attributes(): array
    {
        return [
            'open_at' => 'proposed offer open date',
            'close_at' => 'proposed offer close date',
            'rate' => 'rate',
            'rate_min' => 'minimum of the range',
            'rate_max' => 'maximum of the range',
            'issue_price' => 'issue price per unit',
            'maturity_date' => 'indicative maturity date',
            'allotment_date' => 'indicative allotment date',
            'min_subscription_threshold' => 'minimum subscription threshold',
            'min_subscription_amount' => 'minimum subscription amount',
            'receiving_agents.*.bank_account_number' => 'account number',
            'co_sponsors.*.institution_id' => 'co-sponsor',
        ];
    }

    public function offerFields(): array
    {
        $data = $this->validated();

        return array_intersect_key($data, array_flip([
            'issuer_name', 'issue_description', 'issue_series', 'offer_size', 'min_subscription_threshold',
            'issuer_type', 'security_type', 'open_at', 'close_at', 'min_subscription_amount', 'subsequent_increment',
            'tenor', 'day_count_fraction', 'price_discovery_method', 'investor_universe', 'rate', 'rate_min', 'rate_max',
            'implied_yield', 'issue_price', 'maturity_date', 'allotment_date', 'uses_receiving_agents', 'bank_name',
            'bank_account_name', 'bank_account_number',
        ])) + ['rate' => null, 'rate_min' => null, 'rate_max' => null, 'tenor' => null, 'maturity_date' => null, 'day_count_fraction' => null, 'issue_series' => null];
    }
}

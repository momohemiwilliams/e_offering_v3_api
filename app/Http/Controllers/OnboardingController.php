<?php

namespace App\Http\Controllers;

use App\Models\Institution;
use App\Models\User;
use App\Services\BvnVerifier;
use App\Services\OnboardingService;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * CR s3: four account types and a Data Privacy and Consent notice before onboarding.
 *
 * The API is stateless, so a verified BVN travels between the two retail steps as a
 * short-lived encrypted token: the browser holds it but can neither read nor alter it.
 */
class OnboardingController extends Controller
{
    private const BVN_TOKEN_MINUTES = 30;
    private const CONSENT_MESSAGE = 'Please review and accept the Data Privacy and Consent notice.';

    private OnboardingService $onboarding;

    public function __construct(OnboardingService $onboarding)
    {
        $this->onboarding = $onboarding;
    }

    public function accountTypes(BvnVerifier $verifier): JsonResponse
    {
        return successResponse('Account types fetched.', [
            'accountTypes' => config('eoffering.account_types'),
            'usesIgree' => $verifier->usesConsentRedirect(),
        ]);
    }

    public function storeTsp(Request $request): JsonResponse
    {
        $data = $request->validate(array_merge([
            'consent' => ['accepted'],
            'name' => ['required', 'string', 'max:191'],
            'email' => ['required', 'email', 'max:191'],
            'phone' => ['nullable', 'string', 'max:30'],
            'registration_number' => ['nullable', 'string', 'max:60'],
            'proof' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:5120'],
        ], $this->userRules()), $this->messages());

        $institution = $this->onboarding->registerTsp($data, $request->file('proof'), $data['users']);

        return successResponse('Your request was submitted for review.', ['kind' => 'tsp', 'reference' => $institution->reference]);
    }

    public function storeInstitutional(Request $request): JsonResponse
    {
        $data = $request->validate(array_merge([
            'consent' => ['accepted'],
            'category' => ['required', Rule::in(['qii', 'non_qii'])],
            'name' => ['required', 'string', 'max:191'],
            'registration_number' => ['required', 'string', 'max:60', Rule::unique('institutions', 'registration_number')->whereIn('type', ['qii', 'non_qii'])->whereNot('status', Institution::STATUS_REJECTED)],
            'email' => ['required', 'email', 'max:191'],
            'phone' => ['nullable', 'string', 'max:30'],
            'address' => ['nullable', 'string', 'max:255'],
            'certificate' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:5120'],
            'memorandum' => ['nullable', 'file', 'mimes:pdf', 'max:10240'],
            'cac_directors' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:5120'],
            'cac_shareholders' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:5120'],
        ], $this->userRules()), $this->messages() + [
            'registration_number.unique' => 'An institution with this CAC registration number is already onboarded.',
        ]);

        $files = array_filter([
            'Certificate of Incorporation' => $request->file('certificate'),
            'Memorandum and Articles of Association' => $request->file('memorandum'),
            'CAC 7 (Directors)' => $request->file('cac_directors'),
            'CAC 7 (Shareholders)' => $request->file('cac_shareholders'),
        ]);

        $institution = $this->onboarding->registerInstitutionalInvestor($data, $files, $data['users']);

        return successResponse('Your request was submitted for review.', ['kind' => 'institutional', 'reference' => $institution->reference]);
    }

    public function storeRegulator(Request $request): JsonResponse
    {
        $data = $request->validate([
            'consent' => ['accepted'],
            'regulator_body' => ['required', Rule::in(['SEC', 'CBN'])],
            'name' => ['required', 'string', 'max:191'],
            'email' => ['required', 'email', 'max:191', Rule::unique('users', 'email')],
            'phone' => ['required', 'string', 'max:30'],
        ], $this->messages());

        $institution = $this->onboarding->registerRegulator($data);

        return successResponse('Your request was submitted for review.', ['kind' => 'regulator', 'reference' => $institution->reference]);
    }

    /** Retail step 1: returns the iGree consent URL, or verifies manually in test environments. */
    public function retailVerify(Request $request, BvnVerifier $verifier): JsonResponse
    {
        $request->validate(['consent' => ['accepted']], $this->messages());

        if ($verifier->usesConsentRedirect()) {
            try {
                return successResponse('Continue to BVN consent.', ['redirect' => $verifier->consentUrl(frontendUrl('/register/retail'))]);
            } catch (RuntimeException $e) {
                throw ValidationException::withMessages(['bvn' => $e->getMessage()]);
            }
        }

        $data = $request->validate([
            'bvn' => ['required', 'digits:11'],
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'date_of_birth' => ['nullable', 'date', 'before:today'],
        ]);

        try {
            $record = $verifier->manual($data['bvn'], $data['first_name'], $data['last_name'], $data['date_of_birth'] ?? null);
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages(['bvn' => $e->getMessage()]);
        }

        return $this->verified($record);
    }

    /** iGree redirects the browser back with a temporary code, exchanged here server-side. */
    public function retailFetch(Request $request, BvnVerifier $verifier): JsonResponse
    {
        $data = $request->validate(['code' => ['required', 'string', 'max:255']]);

        try {
            return $this->verified($verifier->fetch($data['code']));
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages(['bvn' => $e->getMessage()]);
        }
    }

    /** Retail step 2: contact and settlement details. */
    public function storeRetail(Request $request): JsonResponse
    {
        $data = $request->validate([
            'verification' => ['required', 'string'],
            'email' => ['required', 'email', 'max:191', Rule::unique('users', 'email')],
            'phone' => ['required', 'string', 'max:30'],
            'address' => ['nullable', 'string', 'max:255'],
            'csd_name' => ['nullable', 'string', 'max:60'],
            'csd_account_number' => ['nullable', 'string', 'max:40'],
            'bank_name' => ['nullable', 'string', 'max:100'],
            'bank_account_name' => ['nullable', 'string', 'max:191'],
            'bank_account_number' => ['nullable', 'digits:10'],
            'terms' => ['accepted'],
        ], [
            'email.unique' => 'This email address already has an account.',
            'terms.accepted' => 'You must accept the terms and conditions.',
        ]);

        $bvn = $this->readVerification($data['verification']);
        $data['name'] = trim($bvn['first_name'] . ' ' . $bvn['last_name']);

        $user = $this->onboarding->registerRetail($bvn, $data);

        return successResponse('Your account was created.', ['kind' => 'retail', 'reference' => $user->email]);
    }

    private function verified(array $record): JsonResponse
    {
        return successResponse('Your BVN was verified.', [
            'verification' => Crypt::encrypt(['record' => $record, 'at' => now()->getTimestamp()]),
            'verified' => [
                'first_name' => $record['first_name'],
                'last_name' => $record['last_name'],
                'bvn' => substr($record['bvn'], 0, 3) . '*****' . substr($record['bvn'], -3),
                'phone' => $record['phone'] ?? null,
                'email' => $record['email'] ?? null,
                'address' => $record['address'] ?? null,
            ],
        ]);
    }

    private function readVerification(string $token): array
    {
        try {
            $payload = Crypt::decrypt($token);
        } catch (DecryptException $e) {
            throw ValidationException::withMessages(['verification' => 'Verify your BVN first.']);
        }

        if (!is_array($payload) || now()->getTimestamp() - (int) ($payload['at'] ?? 0) > self::BVN_TOKEN_MINUTES * 60) {
            throw ValidationException::withMessages(['verification' => 'Your BVN verification expired. Verify it again.']);
        }

        return $payload['record'];
    }

    private function userRules(): array
    {
        return [
            'users' => ['required', 'array', 'min:2', 'max:20'],
            'users.*.name' => ['required', 'string', 'max:191'],
            'users.*.email' => ['required', 'email', 'max:191', 'distinct', Rule::unique('users', 'email')],
            'users.*.phone' => ['nullable', 'string', 'max:30'],
            'users.*.access_level' => ['required', Rule::in([User::INPUTTER, User::AUTHORISER])],
        ];
    }

    private function messages(): array
    {
        return [
            'consent.accepted' => self::CONSENT_MESSAGE,
            'users.*.email.unique' => 'This email address already has an account.',
        ];
    }
}

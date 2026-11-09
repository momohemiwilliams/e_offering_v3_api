<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Retail onboarding BVN verification through iGree (legacy flow, kept unchanged per CR s3.1)
 * but hardened: the BVN record is fetched server-to-server with the temporary code iGree
 * returns, never accepted from the browser.
 *
 * Driver "manual" is for local/test use only: the BVN entered on the form is accepted as is.
 */
class BvnVerifier
{
    public function usesConsentRedirect(): bool
    {
        return config('services.igree.driver') === 'igree';
    }

    /** URL of the iGree consent page; the user returns to $returnUrl with ?code=... */
    public function consentUrl(string $returnUrl): string
    {
        $token = $this->merchantToken();
        $query = http_build_query([
            'token' => $token,
            'scope' => 'profile,bvn,address,contact_info',
            'redirect_url' => $returnUrl,
        ]);

        return rtrim((string) config('services.igree.url'), '?&').(str_contains((string) config('services.igree.url'), '?') ? '&' : '?').$query;
    }

    /**
     * @return array{bvn: string, first_name: string, last_name: string, middle_name: ?string, date_of_birth: ?string, phone: ?string, email: ?string, address: ?string, nin: ?string, raw: array}
     */
    public function fetch(string $code): array
    {
        $response = Http::withToken($this->merchantToken())->asJson()
            ->post(rtrim((string) config('services.igree.api'), '/').'/merchant/bvn-details', ['temp_code' => $code]);

        if ($response->failed()) {
            throw new RuntimeException('We could not confirm your BVN. Please try again.');
        }

        $data = (array) ($response->json('data') ?? $response->json());

        return $this->normalise($data);
    }

    /**
     * Local/test verification: accept the typed values.
     */
    public function manual(string $bvn, string $firstName, string $lastName, ?string $dob = null): array
    {
        return $this->normalise([
            'bvn' => $bvn, 'first_name' => $firstName, 'surname' => $lastName, 'DateOfBirth' => $dob,
        ]);
    }

    private function normalise(array $data): array
    {
        $pick = function (array $keys) use ($data) {
            foreach ($keys as $key) {
                if (! empty($data[$key])) {
                    return trim((string) $data[$key]);
                }
            }

            return null;
        };

        $bvn = $pick(['bvn', 'BVN']);
        if (! $bvn || ! preg_match('/^\d{11}$/', $bvn)) {
            throw new RuntimeException('The BVN record returned is incomplete.');
        }

        return [
            'bvn' => $bvn,
            'first_name' => (string) $pick(['first_name', 'firstName', 'FirstName']),
            'last_name' => (string) $pick(['surname', 'last_name', 'lastName', 'LastName']),
            'middle_name' => $pick(['middle_name', 'middleName', 'MiddleName']),
            'date_of_birth' => $pick(['DateOfBirth', 'date_of_birth', 'dob']),
            'phone' => $pick(['phone_number1', 'phone_number', 'phone_number2', 'phone']),
            'email' => $pick(['email']),
            'address' => $pick(['residential_address', 'address']),
            'nin' => $pick(['nin', 'NIN']),
            'raw' => array_diff_key($data, ['image' => true, 'base64Image' => true]),
        ];
    }

    private function merchantToken(): string
    {
        $response = Http::asJson()->post(rtrim((string) config('services.igree.api'), '/').'/merchant/token', [
            'client_id' => config('services.igree.client'),
            'client_secret' => config('services.igree.secret'),
        ]);

        $token = $response->json('token') ?? $response->json('data.token') ?? $response->json('access_token');
        if ($response->failed() || ! $token) {
            throw new RuntimeException('BVN verification is unavailable right now. Please try again later.');
        }

        return (string) $token;
    }
}

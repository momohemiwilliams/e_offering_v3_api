<?php

namespace Tests\Concerns;

use App\Models\Institution;
use App\Models\Offer;
use App\Models\User;

/**
 * Small builders for the actors of the platform.
 */
trait BuildsPlatform
{
    protected function tspInstitution(array $attributes = []): Institution
    {
        return Institution::create(array_merge([
            'type' => Institution::TYPE_TSP,
            'name' => 'Lead Securities Ltd',
            'email' => 'lead'.uniqid().'@test.local',
            'status' => Institution::STATUS_APPROVED,
            'registered_name' => 'Lead Securities Ltd',
            'registered_address' => 'Lagos',
            'contact_name' => 'Contact',
            'contact_role' => 'MD',
            'contact_phone' => '0800',
            'registration_completed_at' => now(),
        ], $attributes));
    }

    protected function tspUser(Institution $institution, string $level): User
    {
        return User::factory()->create([
            'type' => User::TYPE_TSP,
            'access_level' => $level,
            'institution_id' => $institution->id,
        ]);
    }

    protected function retailInvestor(): User
    {
        $user = User::factory()->create(['type' => User::TYPE_INVESTOR]);
        $user->investorProfile()->create(['category' => 'retail', 'bvn' => (string) random_int(10000000000, 99999999999)]);

        return $user;
    }

    protected function qiiInvestor(): User
    {
        $institution = Institution::create([
            'type' => Institution::TYPE_QII, 'name' => 'Pension Fund '.uniqid(), 'email' => uniqid().'@pfa.test',
            'status' => Institution::STATUS_APPROVED, 'registration_number' => 'RC'.random_int(1000, 999999),
        ]);

        return User::factory()->create(['type' => User::TYPE_INVESTOR, 'institution_id' => $institution->id, 'access_level' => User::INPUTTER]);
    }

    protected function regulator(): User
    {
        $sec = Institution::create([
            'type' => Institution::TYPE_REGULATOR, 'regulator_body' => 'SEC', 'name' => 'SEC',
            'email' => uniqid().'@sec.test', 'status' => Institution::STATUS_APPROVED,
        ]);

        return User::factory()->create(['type' => User::TYPE_REGULATOR, 'institution_id' => $sec->id]);
    }

    protected function offerPayload(array $overrides = []): array
    {
        return array_merge([
            'issuer_name' => 'Acme Industries Plc',
            'issue_description' => 'Series 1 Commercial Paper',
            'issue_series' => 'Series 1',
            'offer_size' => '1,000,000',
            'min_subscription_threshold' => 50,
            'issuer_type' => 'Corporate',
            'security_type' => 'commercial_papers',
            'open_at' => now()->addDay()->format('Y-m-d H:i:s'),
            'close_at' => now()->addDays(5)->format('Y-m-d H:i:s'),
            'min_subscription_amount' => '100000',
            'subsequent_increment' => '50000',
            'tenor' => '270 days',
            'day_count_fraction' => 'Actual/365',
            'price_discovery_method' => Offer::COMPETITIVE,
            'investor_universe' => ['qii', 'non_qii', 'retail'],
            'implied_yield' => 18.5,
            'issue_price' => 100,
            'maturity_date' => now()->addDays(280)->format('Y-m-d'),
            'allotment_date' => now()->addDays(7)->format('Y-m-d'),
            'bank_name' => 'Demo Bank',
            'bank_account_name' => 'Lead Securities - Offer',
            'bank_account_number' => '0123456789',
            'uses_receiving_agents' => false,
            'receiving_agents' => [],
            'co_sponsors' => [],
        ], $overrides);
    }
}

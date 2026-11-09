<?php

namespace Database\Seeders;

use App\Models\Bid;
use App\Models\Institution;
use App\Models\Offer;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Local demo data on top of DatabaseSeeder: one open offer with live bids and one closed
 * competitive-bid offer ready for allotment. Run: php artisan db:seed --class=DemoOfferSeeder
 */
class DemoOfferSeeder extends Seeder
{
    public function run(): void
    {
        $tsp = Institution::where('email', 'tsp@example.com')->firstOrFail();
        $inputter = User::where('email', 'inputter@example.com')->firstOrFail();
        $retail = User::where('email', 'retail@example.com')->firstOrFail();
        $qii = User::where('email', 'qii@example.com')->firstOrFail();

        $base = [
            'institution_id' => $tsp->id,
            'created_by' => $inputter->id,
            'issuer_type' => 'Corporate',
            'min_subscription_threshold' => 50,
            'investor_universe' => ['qii', 'non_qii', 'retail'],
            'bank_name' => 'Demo Bank Plc',
            'bank_account_name' => 'Demo Securities Ltd - Offers',
            'bank_account_number' => '0123456789',
            'status' => Offer::LIVE,
            'submitted_at' => now()->subDays(12),
            'authorised_at' => now()->subDays(11),
            'launched_at' => now()->subDays(10),
            'launched_by' => $inputter->id,
        ];

        $open = Offer::create(array_merge($base, [
            'issuer_name' => 'Dangote Cement Plc',
            'issue_description' => 'Series 18 Commercial Paper under the ₦300bn CP Programme',
            'issue_series' => 'Series 18',
            'offer_size' => 5000000000,
            'security_type' => 'commercial_papers',
            'open_at' => now()->subDays(2),
            'close_at' => now()->addDays(5),
            'min_subscription_amount' => 5000000,
            'subsequent_increment' => 1000000,
            'tenor' => '270 days',
            'day_count_fraction' => 'Actual/365',
            'price_discovery_method' => Offer::BOOK_BUILDING,
            'rate_min' => 17.0,
            'rate_max' => 19.5,
            'implied_yield' => 20.15,
            'issue_price' => 1000,
            'maturity_date' => now()->addDays(275)->toDateString(),
            'allotment_date' => now()->addDays(7)->toDateString(),
        ]));

        $closed = Offer::create(array_merge($base, [
            'issuer_name' => 'Lagos State Government',
            'issue_description' => '₦25bn 7-Year Fixed Rate Bond',
            'issue_series' => 'Series IV',
            'offer_size' => 25000000000,
            'issuer_type' => 'Sub-National',
            'security_type' => 'bonds',
            'open_at' => now()->subDays(9),
            'close_at' => now()->subDay(),
            'min_subscription_amount' => 10000000,
            'subsequent_increment' => 1000000,
            'tenor' => '7 years',
            'day_count_fraction' => 'Actual/Actual',
            'price_discovery_method' => Offer::COMPETITIVE,
            'implied_yield' => 18.75,
            'issue_price' => 1000,
            'maturity_date' => now()->addYears(7)->toDateString(),
            'allotment_date' => now()->addDays(3)->toDateString(),
        ]));

        // Synthetic bids from extra demo investors so the book, curve and allotment have shape.
        $investors = [$retail, $qii];
        $types = ['pfa', 'fund_manager', 'bank', 'insurance', 'hni', 'trustee'];
        for ($i = 1; $i <= 8; $i++) {
            $investors[] = User::updateOrCreate(['email' => "investor{$i}@example.com"], [
                'name' => "Demo Investor {$i}", 'type' => User::TYPE_INVESTOR, 'status' => User::STATUS_ACTIVE,
                'password' => bcrypt('Password@1'), 'email_verified_at' => now(),
            ]);
            $investors[$i + 1]->investorProfile()->updateOrCreate(['user_id' => $investors[$i + 1]->id], ['category' => 'retail']);
        }

        $make = function (Offer $offer, User $investor, $amount, $rate, $minutesAgo, $status, $type) {
            return Bid::create([
                'offer_id' => $offer->id, 'user_id' => $investor->id, 'institution_id' => $investor->institution_id,
                'investor_category' => $investor->investorCategory(), 'investor_type' => $type,
                'investor_name' => $investor->institution ? $investor->institution->name : $investor->name,
                'amount' => $amount, 'rate' => $rate, 'csd_name' => 'CSCS', 'csd_account_number' => 'C'.str_pad((string) $investor->id, 7, '0', STR_PAD_LEFT),
                'bank_name' => 'Demo Bank Plc', 'bank_account_name' => $investor->name, 'bank_account_number' => '09'.str_pad((string) $investor->id, 8, '0', STR_PAD_LEFT),
                'payee_type' => 'sponsor', 'payee_name' => $offer->institution->name.' (Sponsor)', 'payee_bank_name' => $offer->bank_name,
                'payee_account_name' => $offer->bank_account_name, 'payee_account_number' => $offer->bank_account_number,
                'placed_through_institution_id' => $offer->institution_id,
                'payment_status' => $status, 'status' => Bid::ACTIVE, 'submitted_at' => now()->subMinutes($minutesAgo),
            ]);
        };

        $openRates = [17.25, 17.5, 17.75, 18.0, 18.0, 18.25, 18.5, 18.75, 19.0, 19.25];
        foreach ($investors as $i => $investor) {
            $make($open, $investor, (5 + $i * 45) * 1000000 + ($i % 2) * 1000000, $openRates[$i], 2000 - $i * 90, $i % 3 === 0 ? Bid::PAYMENT_PAID : Bid::PAYMENT_PENDING, $investor->institution ? 'pfa' : $types[$i % count($types)]);
        }

        $closedRates = [18.1, 18.25, 18.4, 18.5, 18.5, 18.6, 18.75, 18.9, 19.0, 19.2];
        foreach ($investors as $i => $investor) {
            $make($closed, $investor, (1200 + $i * 1100) * 1000000, $closedRates[$i], 12000 - $i * 600, $i === 7 ? Bid::PAYMENT_INVALID : Bid::PAYMENT_PAID, $investor->institution ? 'pfa' : $types[$i % count($types)]);
        }
    }
}

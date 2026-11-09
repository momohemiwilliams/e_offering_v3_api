<?php

namespace App\Services\Reports;

use App\Models\Bid;
use App\Models\Offer;
use App\Support\Money;

/**
 * Real-time book-building analytics (CR s5.1 step 2, s7): demand, bid ranking,
 * rate distribution, demand curve and subscription level.
 */
class BookAnalytics
{
    public function summary(Offer $offer): array
    {
        $bids = $offer->bids()->where('status', Bid::ACTIVE)->get();
        $size = Money::toKobo($offer->offer_size);
        $total = 0;
        $valid = 0;
        $paid = 0;
        $weighted = 0.0;
        $rated = 0;
        $byCategory = ['retail' => 0, 'qii' => 0, 'non_qii' => 0];
        $byType = [];
        $rates = [];

        foreach ($bids as $bid) {
            $amount = Money::toKobo($bid->amount);
            $total += $amount;
            if ($bid->payment_status !== Bid::PAYMENT_INVALID) {
                $valid += $amount;
            }
            if ($bid->payment_status === Bid::PAYMENT_PAID) {
                $paid += $amount;
            }
            $byCategory[$bid->investor_category] = ($byCategory[$bid->investor_category] ?? 0) + $amount;
            $byType[$bid->investor_type] = ($byType[$bid->investor_type] ?? 0) + $amount;
            if ($bid->rate !== null) {
                $weighted += (float) $bid->rate * $amount;
                $rated += $amount;
                $key = number_format((float) $bid->rate, 2, '.', '');
                $rates[$key] = ($rates[$key] ?? 0) + $amount;
            }
        }

        ksort($rates, SORT_NUMERIC);
        $curve = [];
        $running = 0;
        $priceBased = $offer->isPriceBased();
        // Yield-based offers fill from the lowest rate up; price-based (equities) from the highest price down.
        $ordered = $priceBased ? array_reverse($rates, true) : $rates;
        foreach ($ordered as $rate => $amount) {
            $running += $amount;
            $curve[] = ['rate' => (float) $rate, 'cumulative' => $running / 100, 'amount' => $amount / 100];
        }

        $level = $size > 0 ? round($valid / $size * 100, 2) : 0;
        if ($valid > $size) {
            $status = 'oversubscribed';
        } elseif ($valid === $size) {
            $status = 'fully_subscribed';
        } else {
            $status = 'undersubscribed';
        }

        $labels = config('eoffering.investor_types');
        arsort($byType);

        return [
            'offer_size' => $size / 100,
            'total_subscription' => $total / 100,
            'valid_subscription' => $valid / 100,
            'paid_subscription' => $paid / 100,
            'bids_count' => $bids->count(),
            'investors_count' => $bids->pluck('user_id')->unique()->count(),
            'paid_count' => $bids->where('payment_status', Bid::PAYMENT_PAID)->count(),
            'pending_count' => $bids->where('payment_status', Bid::PAYMENT_PENDING)->count(),
            'invalid_count' => $bids->where('payment_status', Bid::PAYMENT_INVALID)->count(),
            'subscription_level' => $level,
            'oversubscription' => max(0, round($level - 100, 2)),
            'status' => $status,
            'weighted_average_rate' => $rated > 0 ? round($weighted / $rated, 4) : null,
            'min_rate' => $rates ? (float) array_key_first($rates) : null,
            'max_rate' => $rates ? (float) array_key_last($rates) : null,
            'final_rate' => $offer->final_rate !== null ? (float) $offer->final_rate : null,
            'close_at' => optional($offer->close_at)->toIso8601String(),
            'by_category' => array_map(function ($k) {
                return $k / 100;
            }, $byCategory),
            'by_investor_type' => collect($byType)->map(function ($kobo, $type) use ($labels) {
                return ['type' => $labels[$type] ?? $type, 'amount' => $kobo / 100];
            })->values()->all(),
            'rate_distribution' => collect($rates)->map(function ($kobo, $rate) {
                return ['rate' => (float) $rate, 'amount' => $kobo / 100];
            })->values()->all(),
            'demand_curve' => $curve,
        ];
    }

    /** Bids ranked by rate (lowest first; highest price first for equities), then time. */
    public function ranking(Offer $offer): array
    {
        $priceBased = $offer->isPriceBased();

        return $offer->bids()->where('status', Bid::ACTIVE)->get()
            ->sort(function (Bid $a, Bid $b) use ($priceBased) {
                $ra = $a->rate === null ? INF : (float) $a->rate;
                $rb = $b->rate === null ? INF : (float) $b->rate;
                $cmp = $priceBased ? $rb <=> $ra : $ra <=> $rb;

                return $cmp !== 0 ? $cmp : $a->submitted_at <=> $b->submitted_at;
            })
            ->values()
            ->map(function (Bid $bid, $i) {
                return [
                    'rank' => $i + 1,
                    'reference' => $bid->reference,
                    'investor_name' => $bid->investor_name,
                    'rate' => $bid->rate !== null ? (float) $bid->rate : null,
                    'amount' => (float) $bid->amount,
                    'payment_status' => $bid->payment_status,
                ];
            })->all();
    }
}

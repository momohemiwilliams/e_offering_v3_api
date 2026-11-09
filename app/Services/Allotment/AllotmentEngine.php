<?php

namespace App\Services\Allotment;

use InvalidArgumentException;

/**
 * Change Request s5.2 / s5.2.1: computes the proposed allotment for every bid on an offer.
 *
 * All money is handled in integer kobo so the totals reconcile exactly with the
 * Approved Issue Size (Final Validation rule). The engine is pure: it takes plain
 * value objects and returns a result, so it can be unit-tested without a database.
 *
 * Methodology is expressed as "steps":
 *   - guarantee : selected bids / investor classes receive 100% first (Pro-Rata Option 1)
 *   - rate      : bids above the final/clearing rate get nothing, bids below get 100%,
 *                 bids at the rate share the residual (Pro-Rata Option 2)
 *   - fcfs      : remaining pool filled by bid timestamp, stopping at the bid that would
 *                 exceed the issue size (First Come First Served)
 *   - pro_rata  : remaining pool shared pro-rata with the Minimum Subscription Rule
 *                 (Pro-Rata Option 3, and the tail of Options 1 and 2)
 * Hybrid = any combination of two or more of these.
 */
class AllotmentEngine
{
    public const METHOD_FCFS = 'fcfs';
    public const METHOD_PRO_RATA = 'pro_rata';
    public const METHOD_HYBRID = 'hybrid';

    public const STEP_GUARANTEE = 'guarantee';
    public const STEP_RATE = 'rate';
    public const STEP_FCFS = 'fcfs';
    public const STEP_PRO_RATA = 'pro_rata';

    /**
     * @param AllotmentBid[] $bids eligible bids (revoked / invalid-payment bids already removed)
     */
    public function run(array $bids, int $issueSize, int $minimumSubscription, AllotmentConfig $config): AllotmentResult
    {
        if ($issueSize <= 0) {
            throw new InvalidArgumentException('The approved issue size must be greater than zero.');
        }

        $steps = $config->steps();
        $notes = [];
        $allocations = [];
        $pool = [];
        foreach ($bids as $bid) {
            $allocations[$bid->id] = 0;
            $pool[$bid->id] = $bid;
        }

        $remaining = $issueSize;

        // Manual overrides are fixed first; everything else is recalculated around them (s5.2 step 8).
        foreach ($bids as $bid) {
            if ($bid->override !== null) {
                $amount = max(0, min($bid->override, $bid->amount));
                $allocations[$bid->id] = $amount;
                $remaining -= $amount;
                unset($pool[$bid->id]);
            }
        }
        if ($remaining < 0) {
            $notes[] = 'Manual overrides exceed the approved issue size.';
            $remaining = 0;
        }

        if ($config->hasShares()) {
            $this->runTranches($pool, $allocations, $remaining, $minimumSubscription, $config, $notes);

            return new AllotmentResult($allocations, $issueSize, $notes);
        }

        // Rate filter: bids priced outside the final rate receive nothing.
        if (in_array(self::STEP_RATE, $steps, true)) {
            if ($config->finalRate === null) {
                throw new InvalidArgumentException('A final/clearing rate is required for rate-based allotment.');
            }
            $below = [];
            $at = [];
            foreach ($pool as $id => $bid) {
                $cmp = $this->compareRate($bid->rate, $config->finalRate, $config);
                if ($cmp > 0) {
                    unset($pool[$id]);
                } elseif ($cmp < 0) {
                    $below[$id] = $bid;
                } else {
                    $at[$id] = $bid;
                }
            }
            $belowTotal = $this->sum($below);
            if ($belowTotal <= $remaining) {
                foreach ($below as $id => $bid) {
                    $allocations[$id] = $bid->amount;
                }
                $remaining -= $belowTotal;
                $pool = $at;
            } else {
                // Not even the bids below the final rate fit: they share the issue, bids at the rate get nothing.
                $notes[] = 'Bids better than the final rate exceed the issue size; they were allotted pro-rata.';
                $pool = $below;
            }
        }

        // Guaranteed investors / classes receive 100% (Option 1).
        if (in_array(self::STEP_GUARANTEE, $steps, true)) {
            $guaranteed = [];
            foreach ($pool as $id => $bid) {
                if ($config->isGuaranteed($bid)) {
                    $guaranteed[$id] = $bid;
                }
            }
            $guaranteedTotal = $this->sum($guaranteed);
            if ($guaranteedTotal <= $remaining) {
                foreach ($guaranteed as $id => $bid) {
                    $allocations[$id] = $bid->amount;
                    unset($pool[$id]);
                }
                $remaining -= $guaranteedTotal;
            } else {
                $notes[] = 'Guaranteed subscriptions exceed the issue size; guaranteed investors were allotted pro-rata and the rest received nothing.';
                $pool = $guaranteed;
            }
        }

        // Distribute what is left.
        if (in_array(self::STEP_FCFS, $steps, true)) {
            $ordered = array_values($pool);
            usort($ordered, function (AllotmentBid $a, AllotmentBid $b) {
                return [$a->receivedAt, $a->id] <=> [$b->receivedAt, $b->id];
            });
            foreach ($ordered as $bid) {
                if ($bid->amount > $remaining) {
                    // Stop at the bid closest to the issue size without exceeding it.
                    break;
                }
                $allocations[$bid->id] = $bid->amount;
                $remaining -= $bid->amount;
            }
        } else {
            foreach ($this->prorate($pool, $remaining, $minimumSubscription, $notes) as $id => $amount) {
                $allocations[$id] = $amount;
            }
        }

        return new AllotmentResult($allocations, $issueSize, $notes);
    }

    /**
     * Pro-rata with the Minimum Subscription Rule (s5.2.1): an investor whose share falls below
     * the minimum is lifted to the minimum (if they asked for at least that), removed from the
     * pool, and the percentage is recalculated for everybody else until nobody is below it.
     *
     * @param AllotmentBid[] $pool keyed by bid id
     * @param string[]       $notes
     * @return array<int|string, int> bid id => allotted kobo
     */
    public function prorate(array $pool, int $available, int $minimum, array &$notes = []): array
    {
        $result = [];
        if ($available <= 0 || $pool === []) {
            foreach ($pool as $id => $bid) {
                $result[$id] = 0;
            }

            return $result;
        }

        while ($pool !== []) {
            $total = $this->sum($pool);
            if ($total <= $available) {
                foreach ($pool as $id => $bid) {
                    $result[$id] = $bid->amount;
                }

                return $result;
            }

            $ratio = $available / $total;
            $lifted = [];
            foreach ($pool as $id => $bid) {
                $share = (int) floor($bid->amount * $ratio);
                if ($minimum > 0 && $share < $minimum && $bid->amount >= $minimum) {
                    $lifted[$id] = $minimum;
                }
            }

            if ($lifted !== [] && array_sum($lifted) <= $available && count($lifted) < count($pool)) {
                foreach ($lifted as $id => $amount) {
                    $result[$id] = $amount;
                    unset($pool[$id]);
                }
                $available -= array_sum($lifted);
                continue;
            }

            if ($lifted !== [] && array_sum($lifted) > $available) {
                $notes[] = 'The issue size is too small to give every investor the minimum subscription amount.';
            }

            // Final pass: floor every share, then hand out the leftover kobo by largest remainder
            // so the pool total equals the amount available exactly.
            $shares = [];
            $remainders = [];
            foreach ($pool as $id => $bid) {
                $exact = $bid->amount * $ratio;
                $shares[$id] = min($bid->amount, (int) floor($exact));
                $remainders[$id] = $exact - floor($exact);
            }
            $left = $available - array_sum($shares);
            arsort($remainders);
            while ($left > 0) {
                $given = false;
                foreach (array_keys($remainders) as $id) {
                    if ($left <= 0) {
                        break;
                    }
                    if ($shares[$id] < $pool[$id]->amount) {
                        $shares[$id]++;
                        $left--;
                        $given = true;
                    }
                }
                if (! $given) {
                    break;
                }
            }
            foreach ($shares as $id => $amount) {
                $result[$id] = $amount;
            }

            return $result;
        }

        return $result;
    }

    /**
     * Hybrid with shares: methods run in the order chosen, each limited to its share of the
     * issue. A share that is not fully used rolls into the next method, because each budget
     * is cumulative. Every bid is allotted by at most one method.
     *
     * @param AllotmentBid[]    $pool        keyed by bid id
     * @param array<int|string, int> $allocations
     * @param string[]          $notes
     */
    private function runTranches(array $pool, array &$allocations, int $available, int $minimum, AllotmentConfig $config, array &$notes): void
    {
        $steps = $config->steps();

        // Not oversubscribed: nobody needs cutting back, unless a final rate excludes bids.
        if (! in_array(self::STEP_RATE, $steps, true) && $this->sum($pool) <= $available) {
            foreach ($pool as $id => $bid) {
                $allocations[$id] = $bid->amount;
            }

            return;
        }

        $spent = 0;
        $percent = 0.0;
        foreach ($steps as $index => $step) {
            $percent += $config->shares[$step];
            $target = $index === count($steps) - 1 ? $available : min($available, (int) floor($available * $percent / 100));
            $budget = max(0, $target - $spent);

            foreach ($this->allocateTranche($step, $pool, $budget, $minimum, $config, $notes) as $id => $amount) {
                $allocations[$id] = $amount;
                $spent += $amount;
                if ($amount > 0) {
                    unset($pool[$id]);
                }
            }
        }
    }

    /**
     * @param AllotmentBid[] $pool keyed by bid id
     * @param string[]       $notes
     * @return array<int|string, int> bid id => allotted kobo
     */
    private function allocateTranche(string $step, array $pool, int $budget, int $minimum, AllotmentConfig $config, array &$notes): array
    {
        if ($step === self::STEP_FCFS) {
            $ordered = array_values($pool);
            usort($ordered, function (AllotmentBid $a, AllotmentBid $b) {
                return [$a->receivedAt, $a->id] <=> [$b->receivedAt, $b->id];
            });
            $result = [];
            foreach ($ordered as $bid) {
                if ($bid->amount > $budget) {
                    break;
                }
                $result[$bid->id] = $bid->amount;
                $budget -= $bid->amount;
            }

            return $result;
        }

        if ($step === self::STEP_GUARANTEE) {
            $guaranteed = array_filter($pool, function (AllotmentBid $bid) use ($config) {
                return $config->isGuaranteed($bid);
            });

            return $this->prorate($guaranteed, $budget, $minimum, $notes);
        }

        if ($step === self::STEP_RATE) {
            if ($config->finalRate === null) {
                throw new InvalidArgumentException('A final/clearing rate is required for rate-based allotment.');
            }
            $better = [];
            $at = [];
            foreach ($pool as $id => $bid) {
                $cmp = $this->compareRate($bid->rate, $config->finalRate, $config);
                if ($cmp < 0) {
                    $better[$id] = $bid;
                } elseif ($cmp === 0) {
                    $at[$id] = $bid;
                }
            }
            $betterTotal = $this->sum($better);
            if ($betterTotal > $budget) {
                return $this->prorate($better, $budget, $minimum, $notes);
            }
            $result = [];
            foreach ($better as $id => $bid) {
                $result[$id] = $bid->amount;
            }

            return $result + $this->prorate($at, $budget - $betterTotal, $minimum, $notes);
        }

        return $this->prorate($pool, $budget, $minimum, $notes);
    }

    /**
     * The most that can be allotted: rate-based methods exclude bids on the wrong side of
     * the final rate, so the Issue Size may be out of reach however the book is allotted.
     *
     * @param AllotmentBid[] $bids eligible bids
     */
    public function reachableTotal(array $bids, int $issueSize, AllotmentConfig $config): int
    {
        if (! $config->usesFinalRate() || $config->finalRate === null) {
            return $issueSize;
        }

        $reachable = 0;
        foreach ($bids as $bid) {
            if ($this->compareRate($bid->rate, $config->finalRate, $config) <= 0) {
                $reachable += $bid->amount;
            }
        }

        return min($issueSize, $reachable);
    }

    /**
     * @param AllotmentBid[] $bids
     */
    private function sum(array $bids): int
    {
        $total = 0;
        foreach ($bids as $bid) {
            $total += $bid->amount;
        }

        return $total;
    }

    /** Negative when the bid is better than the final rate, positive when it is worse. */
    private function compareRate(?float $rate, float $finalRate, AllotmentConfig $config): int
    {
        if ($rate === null) {
            return 0;
        }

        $comparison = round($rate, 4) <=> round($finalRate, 4);

        return $config->higherRateWins ? -$comparison : $comparison;
    }
}

<?php

namespace App\Services\Allotment;

use InvalidArgumentException;

/**
 * The allotment methodology chosen by the TSP/RM (s5.2 step 5, s5.2.1).
 *
 *  method = fcfs                       -> [fcfs]
 *  method = pro_rata, option 1         -> [guarantee, pro_rata]
 *  method = pro_rata, option 2         -> [rate, pro_rata]
 *  method = pro_rata, option 3         -> [pro_rata]
 *  method = hybrid, components = [...] -> two or more of guarantee / rate / fcfs / pro_rata
 */
class AllotmentConfig
{
    public string $method;

    public ?int $proRataOption;

    /** @var string[] */
    public array $components;

    /** @var array<int, int|string> */
    public array $guaranteedBidIds;

    /** @var string[] */
    public array $guaranteedClasses;

    public ?float $finalRate;

    /** Hybrid only: method => percentage of the issue, in the order the methods run. */
    public array $shares = [];

    /** Share prices rank highest first; yields and rates rank lowest first. */
    public bool $higherRateWins = false;

    public function __construct(
        string $method,
        ?int $proRataOption = null,
        array $components = [],
        array $guaranteedBidIds = [],
        array $guaranteedClasses = [],
        ?float $finalRate = null
    ) {
        $this->method = $method;
        $this->proRataOption = $proRataOption;
        $this->components = array_values($components);
        $this->guaranteedBidIds = array_values($guaranteedBidIds);
        $this->guaranteedClasses = array_values($guaranteedClasses);
        $this->finalRate = $finalRate;
    }

    public static function fromArray(array $data): self
    {
        $config = new self(
            (string) ($data['method'] ?? AllotmentEngine::METHOD_PRO_RATA),
            isset($data['pro_rata_option']) && $data['pro_rata_option'] !== '' ? (int) $data['pro_rata_option'] : null,
            (array) ($data['components'] ?? []),
            (array) ($data['guaranteed_bid_ids'] ?? []),
            (array) ($data['guaranteed_classes'] ?? []),
            isset($data['final_rate']) && $data['final_rate'] !== '' ? (float) $data['final_rate'] : null
        );
        foreach ((array) ($data['shares'] ?? []) as $component => $share) {
            if ($share !== null && $share !== '') {
                $config->shares[$component] = (float) $share;
            }
        }

        return $config;
    }

    public function hasShares(): bool
    {
        return $this->method === AllotmentEngine::METHOD_HYBRID && $this->shares !== [];
    }

    public function toArray(): array
    {
        return [
            'method' => $this->method,
            'pro_rata_option' => $this->proRataOption,
            'components' => $this->components,
            'guaranteed_bid_ids' => $this->guaranteedBidIds,
            'guaranteed_classes' => $this->guaranteedClasses,
            'final_rate' => $this->finalRate,
            'shares' => $this->shares,
        ];
    }

    /**
     * @return string[]
     */
    public function steps(): array
    {
        if ($this->method === AllotmentEngine::METHOD_FCFS) {
            return [AllotmentEngine::STEP_FCFS];
        }

        if ($this->method === AllotmentEngine::METHOD_PRO_RATA) {
            if ($this->proRataOption === 1) {
                return [AllotmentEngine::STEP_GUARANTEE, AllotmentEngine::STEP_PRO_RATA];
            }
            if ($this->proRataOption === 2) {
                return [AllotmentEngine::STEP_RATE, AllotmentEngine::STEP_PRO_RATA];
            }

            return [AllotmentEngine::STEP_PRO_RATA];
        }

        if ($this->method === AllotmentEngine::METHOD_HYBRID) {
            $allowed = [AllotmentEngine::STEP_GUARANTEE, AllotmentEngine::STEP_RATE, AllotmentEngine::STEP_FCFS, AllotmentEngine::STEP_PRO_RATA];
            $steps = array_values(array_unique(array_intersect($this->components, $allowed)));
            if (count($steps) < 2) {
                throw new InvalidArgumentException('A hybrid allotment needs two or more methodology options.');
            }
            if ($this->shares !== []) {
                foreach ($steps as $step) {
                    if (($this->shares[$step] ?? 0) <= 0) {
                        throw new InvalidArgumentException('Give every selected method a share of the issue, or leave all the shares blank.');
                    }
                }
                if (abs(array_sum(array_intersect_key($this->shares, array_flip($steps))) - 100) > 0.001) {
                    throw new InvalidArgumentException('The shares of the issue must add up to 100%.');
                }

                return $steps;
            }
            if (in_array(AllotmentEngine::STEP_FCFS, $steps, true) && in_array(AllotmentEngine::STEP_PRO_RATA, $steps, true)) {
                throw new InvalidArgumentException('Choose either First Come First Served or Pro-Rata to share the remaining amount, not both.');
            }

            return $steps;
        }

        throw new InvalidArgumentException("Unknown allotment method [{$this->method}].");
    }

    public function isGuaranteed(AllotmentBid $bid): bool
    {
        if (in_array($bid->id, $this->guaranteedBidIds, false)) {
            return true;
        }

        return $bid->investorType !== null && in_array($bid->investorType, $this->guaranteedClasses, true);
    }

    public function usesFinalRate(): bool
    {
        try {
            return in_array(AllotmentEngine::STEP_RATE, $this->steps(), true);
        } catch (InvalidArgumentException $e) {
            return false;
        }
    }

    public function label(): string
    {
        if ($this->method === AllotmentEngine::METHOD_FCFS) {
            return 'First Come First Served';
        }
        if ($this->method === AllotmentEngine::METHOD_PRO_RATA) {
            $labels = [
                1 => 'Pro-Rata (Option 1: guaranteed investors / class)',
                2 => 'Pro-Rata (Option 2: rate-based)',
                3 => 'Pro-Rata (Option 3: all investors)',
            ];

            return $labels[$this->proRataOption ?? 3] ?? 'Pro-Rata';
        }

        $names = [
            AllotmentEngine::STEP_GUARANTEE => 'Guaranteed investors',
            AllotmentEngine::STEP_RATE => 'Rate-based',
            AllotmentEngine::STEP_FCFS => 'First Come First Served',
            AllotmentEngine::STEP_PRO_RATA => 'Pro-Rata',
        ];

        return 'Hybrid ('.implode(' + ', array_map(function ($s) use ($names) {
            return ($names[$s] ?? $s).(isset($this->shares[$s]) ? ' '.$this->shares[$s].'%' : '');
        }, $this->components)).')';
    }
}

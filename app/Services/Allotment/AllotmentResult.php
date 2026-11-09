<?php

namespace App\Services\Allotment;

class AllotmentResult
{
    /** @var array<int|string, int> bid id => allotted kobo */
    public array $allocations;

    public int $issueSize;

    /** @var string[] */
    public array $notes;

    public function __construct(array $allocations, int $issueSize, array $notes = [])
    {
        $this->allocations = $allocations;
        $this->issueSize = $issueSize;
        $this->notes = $notes;
    }

    public function total(): int
    {
        return array_sum($this->allocations);
    }

    public function allotted($bidId): int
    {
        return $this->allocations[$bidId] ?? 0;
    }
}

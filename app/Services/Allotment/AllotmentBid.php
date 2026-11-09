<?php

namespace App\Services\Allotment;

/**
 * One eligible bid as the allotment engine sees it. Amounts are integer kobo.
 */
class AllotmentBid
{
    /** @var int|string */
    public $id;

    public int $amount;

    public ?float $rate;

    public int $receivedAt;

    public ?string $investorType;

    /** Manually overridden allotment in kobo, or null when the system calculates it. */
    public ?int $override;

    /**
     * @param int|string $id
     */
    public function __construct($id, int $amount, ?float $rate = null, int $receivedAt = 0, ?string $investorType = null, ?int $override = null)
    {
        $this->id = $id;
        $this->amount = $amount;
        $this->rate = $rate;
        $this->receivedAt = $receivedAt;
        $this->investorType = $investorType;
        $this->override = $override;
    }
}

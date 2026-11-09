<?php

namespace Tests\Unit;

use App\Services\Allotment\AllotmentBid;
use App\Services\Allotment\AllotmentConfig;
use App\Services\Allotment\AllotmentEngine;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class AllotmentEngineTest extends TestCase
{
    private AllotmentEngine $engine;

    protected function setUp(): void
    {
        parent::setUp();
        $this->engine = new AllotmentEngine();
    }

    public function test_fcfs_stops_at_the_bid_that_would_exceed_the_issue_size(): void
    {
        $bids = [
            new AllotmentBid(1, 400, null, 10),
            new AllotmentBid(2, 300, null, 20),
            new AllotmentBid(3, 500, null, 30),
            new AllotmentBid(4, 100, null, 40),
        ];

        $result = $this->engine->run($bids, 1000, 0, new AllotmentConfig('fcfs'));

        $this->assertSame([1 => 400, 2 => 300, 3 => 0, 4 => 0], $result->allocations);
        $this->assertSame(700, $result->total());
    }

    public function test_option_three_prorates_everyone_and_reconciles_exactly(): void
    {
        $bids = [
            new AllotmentBid(1, 1000),
            new AllotmentBid(2, 2000),
            new AllotmentBid(3, 3001),
        ];

        $result = $this->engine->run($bids, 3000, 0, new AllotmentConfig('pro_rata', 3));

        $this->assertSame(3000, $result->total());
        foreach ($bids as $bid) {
            $this->assertLessThanOrEqual($bid->amount, $result->allotted($bid->id));
        }
    }

    public function test_minimum_subscription_rule_lifts_small_shares_and_recalculates(): void
    {
        // 50% pro-rata would give bid 1 only 100; the minimum is 150.
        $bids = [
            new AllotmentBid(1, 200),
            new AllotmentBid(2, 900),
            new AllotmentBid(3, 900),
        ];

        $result = $this->engine->run($bids, 1000, 150, new AllotmentConfig('pro_rata', 3));

        $this->assertSame(150, $result->allotted(1));
        $this->assertSame(425, $result->allotted(2));
        $this->assertSame(425, $result->allotted(3));
        $this->assertSame(1000, $result->total());
    }

    public function test_option_one_guarantees_an_investor_class_before_prorating_the_rest(): void
    {
        $bids = [
            new AllotmentBid(1, 400, null, 0, 'pfa'),
            new AllotmentBid(2, 600, null, 0, 'bank'),
            new AllotmentBid(3, 600, null, 0, 'hni'),
        ];
        $config = new AllotmentConfig('pro_rata', 1, [], [], ['pfa']);

        $result = $this->engine->run($bids, 1000, 0, $config);

        $this->assertSame(400, $result->allotted(1));
        $this->assertSame(300, $result->allotted(2));
        $this->assertSame(300, $result->allotted(3));
    }

    public function test_option_two_is_rate_based(): void
    {
        $bids = [
            new AllotmentBid(1, 300, 17.0),
            new AllotmentBid(2, 400, 18.0),
            new AllotmentBid(3, 400, 18.0),
            new AllotmentBid(4, 500, 19.0),
        ];
        $config = new AllotmentConfig('pro_rata', 2, [], [], [], 18.0);

        $result = $this->engine->run($bids, 900, 0, $config);

        $this->assertSame(300, $result->allotted(1), 'below the final rate: 100%');
        $this->assertSame(300, $result->allotted(2), 'at the final rate: pro-rata of the residual');
        $this->assertSame(300, $result->allotted(3));
        $this->assertSame(0, $result->allotted(4), 'above the final rate: nothing');
    }

    public function test_hybrid_combines_guarantee_with_fcfs(): void
    {
        $bids = [
            new AllotmentBid(1, 500, null, 30, 'pfa'),
            new AllotmentBid(2, 300, null, 10, 'bank'),
            new AllotmentBid(3, 300, null, 20, 'bank'),
        ];
        $config = new AllotmentConfig('hybrid', null, ['guarantee', 'fcfs'], [], ['pfa']);

        $result = $this->engine->run($bids, 1000, 0, $config);

        $this->assertSame(500, $result->allotted(1));
        $this->assertSame(300, $result->allotted(2));
        $this->assertSame(0, $result->allotted(3));
    }

    public function test_hybrid_needs_two_components(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->engine->run([new AllotmentBid(1, 10)], 10, 0, new AllotmentConfig('hybrid', null, ['fcfs']));
    }

    public function test_manual_override_is_kept_and_the_rest_recalculated(): void
    {
        $bids = [
            new AllotmentBid(1, 1000, null, 0, null, 700),
            new AllotmentBid(2, 1000),
            new AllotmentBid(3, 1000),
        ];

        $result = $this->engine->run($bids, 1000, 0, new AllotmentConfig('pro_rata', 3));

        $this->assertSame(700, $result->allotted(1));
        $this->assertSame(150, $result->allotted(2));
        $this->assertSame(150, $result->allotted(3));
    }

    public function test_undersubscribed_offer_allots_every_subscription_in_full(): void
    {
        $bids = [new AllotmentBid(1, 200), new AllotmentBid(2, 300)];

        $result = $this->engine->run($bids, 1000, 0, new AllotmentConfig('pro_rata', 3));

        $this->assertSame([1 => 200, 2 => 300], $result->allocations);
    }
    public function test_option_two_ranks_highest_first_for_share_prices(): void
    {
        $bids = [
            new AllotmentBid(1, 300, 19.0),
            new AllotmentBid(2, 400, 18.0),
            new AllotmentBid(3, 400, 18.0),
            new AllotmentBid(4, 500, 17.0),
        ];
        $config = new AllotmentConfig('pro_rata', 2, [], [], [], 18.0);
        $config->higherRateWins = true;

        $result = $this->engine->run($bids, 900, 0, $config);

        $this->assertSame(300, $result->allotted(1), 'above the clearing price: 100%');
        $this->assertSame(300, $result->allotted(2), 'at the clearing price: pro-rata of the residual');
        $this->assertSame(300, $result->allotted(3));
        $this->assertSame(0, $result->allotted(4), 'below the clearing price: nothing');
    }

    private function hybrid(array $components, array $shares, array $classes = ['pfa']): AllotmentConfig
    {
        $config = new AllotmentConfig('hybrid', null, $components, [], $classes);
        $config->shares = $shares;

        return $config;
    }

    public function test_hybrid_shares_limit_each_method_to_its_part_of_the_issue(): void
    {
        $bids = [new AllotmentBid(1, 1000, null, 1, 'pfa'), new AllotmentBid(2, 1000, null, 2, 'bank'), new AllotmentBid(3, 1000, null, 3, 'bank')];

        $result = $this->engine->run($bids, 1000, 0, $this->hybrid(['guarantee', 'pro_rata'], ['guarantee' => 40, 'pro_rata' => 60]));

        $this->assertSame(400, $result->allotted(1));
        $this->assertSame(300, $result->allotted(2));
        $this->assertSame(300, $result->allotted(3));
        $this->assertSame(1000, $result->total());
    }

    public function test_an_unused_hybrid_share_rolls_into_the_next_method(): void
    {
        $bids = [new AllotmentBid(1, 100, null, 1, 'pfa'), new AllotmentBid(2, 1000, null, 2, 'bank'), new AllotmentBid(3, 1000, null, 3, 'bank')];

        $result = $this->engine->run($bids, 1000, 0, $this->hybrid(['guarantee', 'pro_rata'], ['guarantee' => 40, 'pro_rata' => 60]));

        $this->assertSame(100, $result->allotted(1));
        $this->assertSame(450, $result->allotted(2));
        $this->assertSame(450, $result->allotted(3));
    }

    public function test_hybrid_shares_follow_the_order_chosen(): void
    {
        $bids = [new AllotmentBid(1, 500, null, 1), new AllotmentBid(2, 500, null, 2), new AllotmentBid(3, 500, null, 3)];

        $result = $this->engine->run($bids, 1000, 0, $this->hybrid(['fcfs', 'pro_rata'], ['fcfs' => 50, 'pro_rata' => 50]));

        $this->assertSame(500, $result->allotted(1), 'first bid fills the FCFS share');
        $this->assertSame(250, $result->allotted(2), 'the rest share the pro-rata part');
        $this->assertSame(250, $result->allotted(3));
    }

    public function test_hybrid_shares_must_total_one_hundred(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->engine->run([new AllotmentBid(1, 10)], 10, 0, $this->hybrid(['fcfs', 'pro_rata'], ['fcfs' => 50, 'pro_rata' => 40]));
    }

    public function test_every_hybrid_method_needs_a_share_once_any_has_one(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->engine->run([new AllotmentBid(1, 10)], 10, 0, $this->hybrid(['fcfs', 'pro_rata'], ['fcfs' => 100]));
    }

    public function test_undersubscribed_book_is_filled_whatever_the_shares(): void
    {
        $bids = [new AllotmentBid(1, 300, null, 1, 'pfa'), new AllotmentBid(2, 300, null, 2, 'bank')];

        $result = $this->engine->run($bids, 1000, 0, $this->hybrid(['guarantee', 'pro_rata'], ['guarantee' => 10, 'pro_rata' => 90]));

        $this->assertSame(300, $result->allotted(1));
        $this->assertSame(300, $result->allotted(2));
    }

    public function test_issue_size_can_be_out_of_reach_after_a_final_rate(): void
    {
        $bids = [new AllotmentBid(1, 200, 17.0), new AllotmentBid(2, 100, 18.0), new AllotmentBid(3, 5000, 19.0)];
        $config = new AllotmentConfig('pro_rata', 2, [], [], [], 18.0);

        $this->assertSame(300, $this->engine->reachableTotal($bids, 1000, $config));
        $this->assertSame(1000, $this->engine->reachableTotal($bids, 1000, new AllotmentConfig('pro_rata', 3)));
    }
}

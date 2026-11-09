<?php

namespace App\Console\Commands;

use App\Services\PlatformFeeService;
use Illuminate\Console\Command;

/**
 * Daily: annual platform fee invoices ahead of renewal and suspension of lapsed TSPs (CR s3.2 steps 11-12).
 */
class RunFeeRenewals extends Command
{
    protected $signature = 'fees:renew';

    protected $description = 'Issue annual platform fee invoices and suspend TSP/RMs whose fee lapsed';

    public function handle(PlatformFeeService $fees): int
    {
        $result = $fees->runRenewals();
        $this->info("Invoices issued: {$result['invoiced']}; institutions suspended: {$result['suspended']}.");

        return 0;
    }
}

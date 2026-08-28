<?php

namespace App\Console\Commands;

use App\Services\IncentiveApprovalService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('incentives:release-matured')]
#[Description('Move Calculated incentives whose retention period has elapsed into Pending Verification')]
class ReleaseMaturedIncentives extends Command
{
    public function handle(IncentiveApprovalService $approvals): int
    {
        $count = $approvals->releaseMatured();

        $this->info("Released {$count} incentive calculation(s) past their retention period.");

        return self::SUCCESS;
    }
}

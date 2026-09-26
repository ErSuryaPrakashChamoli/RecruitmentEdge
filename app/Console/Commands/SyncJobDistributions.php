<?php

namespace App\Console\Commands;

use App\Services\Distribution\JobDistributionService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('jobs:sync-distributions')]
#[Description('Unpublish job postings whose requisition is no longer open or whose closing date has passed')]
class SyncJobDistributions extends Command
{
    public function handle(JobDistributionService $distribution): int
    {
        $count = $distribution->closeStalePostings();

        $this->info("Closed {$count} stale job posting(s).");

        return self::SUCCESS;
    }
}

<?php

namespace App\Console\Commands;

use App\Services\InterviewSchedulingService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('interview-slots:expire')]
#[Description('Mark self-scheduling slots whose booking window has closed as Expired')]
class ExpireLapsedInterviewSlots extends Command
{
    public function handle(InterviewSchedulingService $scheduling): int
    {
        $count = $scheduling->expireLapsedSlots();

        $this->info("Expired {$count} interview slot(s).");

        return self::SUCCESS;
    }
}

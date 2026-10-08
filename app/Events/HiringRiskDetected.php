<?php

namespace App\Events;

use App\Models\HiringRisk;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A new risk was opened by the Hiring Risk Radar (Phase 7) — an automation trigger
 * (intelligence.risk_detected) so organisations can notify or escalate. Carries no decision.
 */
class HiringRiskDetected implements ShouldDispatchAfterCommit
{
    use Dispatchable, SerializesModels;

    public function __construct(public readonly HiringRisk $risk) {}
}

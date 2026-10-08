<?php

namespace App\Console\Commands;

use App\Services\PipelineTemplateService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Phase 4 backward-compatibility backfill: seeds the system stage library and default pipeline
 * template if missing, snapshots that template onto every requisition that has no pipeline yet,
 * and points every application without a configured stage at the stage matching its existing
 * canonical stage. Idempotent — safe to re-run; never rewrites stage history.
 */
#[Signature('recruitment:assign-default-pipelines')]
#[Description('Give legacy requisitions and applications the default configurable hiring pipeline')]
class AssignDefaultPipelines extends Command
{
    public function handle(PipelineTemplateService $pipelines): int
    {
        $result = $pipelines->assignDefaultPipelines();

        $this->info("Assigned the default pipeline to {$result['requisitions']} requisition(s) and mapped {$result['applications']} additional application(s).");

        return self::SUCCESS;
    }
}

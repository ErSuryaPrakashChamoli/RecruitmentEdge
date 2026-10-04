<?php

namespace App\Console\Commands;

use App\Services\Platform\Commercial\PlanCatalogService;
use DomainException;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * SaaS-3: publishes plan versions defined in PlanCatalog that are not published yet. Never edits a
 * published version (a changed definition is refused — publish the next version).
 */
#[Signature('plans:sync')]
#[Description('Publish the code-defined plan catalog (idempotent, platform)')]
class PlansSync extends Command
{
    public function handle(PlanCatalogService $catalog): int
    {
        try {
            $result = $catalog->sync();
        } catch (DomainException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info('Published: '.($result['published'] === [] ? 'nothing new' : implode(', ', $result['published'])).'. Unchanged: '.implode(', ', $result['unchanged']).'.');

        return self::SUCCESS;
    }
}

<?php

namespace App\Console\Commands;

use App\Services\AI\Actions\ActionExecutor;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Phase 8.4, every five minutes: AI actions not approved within ai.actions.pending_ttl_minutes are
 * expired (never run) and the conversation is told. Approval refuses an expired action on its own;
 * this keeps the record and the conversation honest.
 */
#[Signature('ai:expire-pending-actions')]
#[Description('Expire AI actions that were not approved in time')]
class ExpirePendingAiActions extends Command
{
    public function handle(ActionExecutor $executor): int
    {
        $this->info("Expired {$executor->expirePending()} pending AI action(s).");

        return self::SUCCESS;
    }
}

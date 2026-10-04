<?php

namespace App\Console\Commands;

use App\Enums\BillingEventStatus;
use App\Models\BillingEvent;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * SaaS-4 (platform task, daily): clears the raw payload of handled provider events older than the
 * retention period (billing.webhooks.payload_retention_days). The event row — id, type, status,
 * hash, tenant — stays, so a redelivery is still recognised.
 */
#[Signature('billing:prune-events')]
#[Description('Clear raw payloads of old provider events (platform)')]
class BillingPruneEvents extends Command
{
    public function handle(): int
    {
        $cleared = BillingEvent::query()
            ->whereIn('status', [BillingEventStatus::Processed->value, BillingEventStatus::Ignored->value])
            ->whereNotNull('payload')
            ->where('received_at', '<', now()->subDays((int) config('billing.webhooks.payload_retention_days', 90)))
            ->update(['payload' => null, 'updated_at' => now()]);

        $this->info("Cleared {$cleared} payload(s).");

        return self::SUCCESS;
    }
}

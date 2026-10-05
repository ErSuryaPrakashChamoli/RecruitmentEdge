<?php

namespace App\Services\Platform;

use App\Enums\PlatformEventSeverity;
use App\Mail\PlatformEventMail;
use App\Models\PlatformEvent;
use App\Models\Tenant;
use App\Services\Tenancy\TenantContext;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * SaaS-5: the platform's operational event feed (platform panel, Operational events) — provisioning
 * failures, lifecycle changes, support requests and expiries, deletion and purge steps, compliance
 * exports. Recorded once per dedupe key (a scheduler run twice records nothing twice); a critical
 * event is also mailed to the platform address, when one is configured (platform.notify_email).
 * Never a secret or tenant data beyond the tenant's id and slug.
 */
class PlatformEvents
{
    /**
     * @param  array<string, mixed>  $context
     */
    public function record(string $type, PlatformEventSeverity $severity, string $title, ?Tenant $tenant = null, array $context = [], ?string $dedupeKey = null, bool $mailNow = false): PlatformEvent
    {
        try {
            $event = PlatformEvent::query()->create([
                'type' => mb_substr($type, 0, 64),
                'severity' => $severity,
                'tenant_id' => $tenant?->getKey(),
                'title' => mb_substr($title, 0, 255),
                'context' => $context === [] ? null : $context,
                'dedupe_key' => $dedupeKey !== null ? mb_substr($dedupeKey, 0, 191) : null,
                'occurred_at' => now(),
            ]);
        } catch (UniqueConstraintViolationException) {
            return PlatformEvent::query()->where('dedupe_key', $dedupeKey)->firstOrFail();
        }

        Log::log($severity === PlatformEventSeverity::Critical ? 'warning' : 'notice', 'platform.event', ['type' => $type, 'tenant_id' => $tenant?->getKey(), 'event_id' => $event->id]);

        // Platform mail: queued with no tenant (a closed tenant's events must still reach operators),
        // and only once the event is committed.
        if ($severity === PlatformEventSeverity::Critical && filled(config('platform.notify_email'))) {
            if ($mailNow) {
                // SaaS-7 (C8): a health problem may be the queue itself (a silent worker) — sent now,
                // from the caller (the scheduler), never waiting on the queue it reports on.
                try {
                    TenantContext::current()->runWithoutTenant(fn () => Mail::to((string) config('platform.notify_email'))->sendNow(new PlatformEventMail((int) $event->id)));
                } catch (Throwable $e) {
                    report($e);
                }
            } else {
                TenantContext::current()->runWithoutTenant(fn () => Mail::to((string) config('platform.notify_email'))->queue((new PlatformEventMail((int) $event->id))->afterCommit()));
            }
        }

        return $event;
    }
}

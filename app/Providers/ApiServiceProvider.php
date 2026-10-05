<?php

namespace App\Providers;

use App\Enums\WebhookEventType;
use App\Events\CandidateStageChanged;
use App\Events\RequisitionStatusChanged;
use App\Http\Middleware\Api\AuthenticateApiCredential;
use App\Models\Candidate;
use App\Models\CandidateApplication;
use App\Services\Api\ApiPrincipal;
use App\Services\Integrations\Connections\InboundWebhookConnection;
use App\Services\Integrations\Connections\OutboundWebhookConnection;
use App\Services\Integrations\Handlers\ApplicationsSubmitHandler;
use App\Services\Integrations\Http\DnsHostResolver;
use App\Services\Integrations\Http\HostResolver;
use App\Services\Integrations\IntegrationRegistry;
use App\Services\Tenancy\TenantCache;
use App\Services\Tenancy\TenantContext;
use App\Services\Webhooks\WebhookEventRecorder;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

/**
 * SaaS-6: the API, integration connections and webhooks — the `api` guard, the API's rate limits
 * (keyed by credential and by tenant, never only by address), the connection types and inbound
 * handlers in IntegrationRegistry, and the domain changes that become outbound webhook events.
 */
class ApiServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(HostResolver::class, DnsHostResolver::class);

        $this->app->extend(IntegrationRegistry::class, function (IntegrationRegistry $registry): IntegrationRegistry {
            $registry->registerConnectionType(OutboundWebhookConnection::KEY, OutboundWebhookConnection::class);
            $registry->registerConnectionType(InboundWebhookConnection::KEY, InboundWebhookConnection::class);
            $registry->registerInboundHandler(ApplicationsSubmitHandler::KEY, ApplicationsSubmitHandler::class);

            return $registry;
        });
    }

    public function boot(): void
    {
        // The member an authenticated credential acts for; set by AuthenticateApiCredential.
        Auth::viaRequest('api-credential', fn (Request $request) => $request->attributes->get(AuthenticateApiCredential::PRINCIPAL)?->owner);

        RateLimiter::for('api', function (Request $request): array|Limit {
            $principal = $request->attributes->get(AuthenticateApiCredential::PRINCIPAL);

            if (! $principal instanceof ApiPrincipal) {
                return Limit::perMinute(30)->by('api:address:'.sha1((string) $request->ip()));
            }

            $tenantId = (int) $principal->tenant->getKey();

            return [
                Limit::perMinute((int) config('api.rate_limits.per_credential', 120))->by(TenantCache::key('api:credential:'.$principal->credential->getKey(), $tenantId)),
                Limit::perMinute((int) config('api.rate_limits.per_tenant', 600))->by(TenantCache::key('api:tenant', $tenantId)),
            ];
        });

        // Before the tenant is known: keyed by the connection's opaque, globally unique public key.
        RateLimiter::for('api-hooks', fn (Request $request): Limit => Limit::perMinute((int) config('api.rate_limits.per_inbound_connection', 300))->by('api:hook:'.sha1((string) $request->route('publicKey'))));

        $this->recordWebhookEvents();
    }

    /**
     * Outbound webhook events, once the change has committed, in the change's own tenant.
     */
    private function recordWebhookEvents(): void
    {
        $record = static function (int $tenantId, WebhookEventType $type, string $subjectType, int $subjectId, array $data): void {
            DB::afterCommit(function () use ($tenantId, $type, $subjectType, $subjectId, $data): void {
                TenantContext::current()->run($tenantId, function () use ($type, $subjectType, $subjectId, $data): void {
                    app(WebhookEventRecorder::class)->record($type, $subjectType, $subjectId, $data);
                });
            });
        };

        Candidate::created(fn (Candidate $candidate) => $record((int) $candidate->tenant_id, WebhookEventType::CandidateCreated, 'candidate', (int) $candidate->getKey(), []));

        CandidateApplication::created(fn (CandidateApplication $application) => $record((int) $application->tenant_id, WebhookEventType::ApplicationCreated, 'application', (int) $application->getKey(), [
            'candidate_id' => $application->candidate_id,
            'requisition_id' => $application->requisition_id,
            'job_posting_id' => $application->job_posting_id,
            'stage' => $application->current_stage?->value,
            'status' => $application->status?->value,
            'origin_channel' => $application->origin_channel,
        ]));

        Event::listen(CandidateStageChanged::class, fn (CandidateStageChanged $event) => $record((int) $event->application->tenant_id, WebhookEventType::ApplicationStageChanged, 'application', (int) $event->application->getKey(), [
            'candidate_id' => $event->application->candidate_id,
            'requisition_id' => $event->application->requisition_id,
            'previous_stage' => $event->previousStage?->value,
            'stage' => $event->newStage->value,
            'previous_status' => $event->previousStatus->value,
            'status' => $event->newStatus->value,
        ]));

        Event::listen(RequisitionStatusChanged::class, fn (RequisitionStatusChanged $event) => $record((int) $event->requisition->tenant_id, WebhookEventType::RequisitionStatusChanged, 'requisition', (int) $event->requisition->getKey(), [
            'previous_status' => $event->from->value,
            'status' => $event->to->value,
        ]));
    }
}

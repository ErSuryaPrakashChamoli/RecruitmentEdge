<?php

/**
 * SaaS-6: the API and webhook boundaries, enforced by construction — outbound HTTP only through
 * reviewed classes, API output only through explicit resources, no tenant from the request, no
 * secrets in logs, entitlement keys only through the registry.
 */
function apiSources(?string $under = null): array
{
    $root = dirname(__DIR__, 3);

    return collect(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/app')))
        ->filter(fn (SplFileInfo $file) => $file->getExtension() === 'php')
        ->mapWithKeys(fn (SplFileInfo $file) => [str_replace($root.'/', '', $file->getPathname()) => (string) file_get_contents($file->getPathname())])
        ->filter(fn (string $source, string $path): bool => $under === null || str_starts_with($path, $under))
        ->all();
}

/**
 * The SaaS-6 code.
 *
 * @return array<string, string>
 */
function apiIntegrationSources(): array
{
    return array_merge(
        apiSources('app/Services/Api/'), apiSources('app/Services/Webhooks/'), apiSources('app/Http/Controllers/Api/'),
        apiSources('app/Http/Middleware/Api/'), apiSources('app/Http/Resources/Api/'), apiSources('app/Services/Integrations/Http/'),
        apiSources('app/Services/Integrations/Handlers/'), apiSources('app/Services/Integrations/Connections/'),
        array_intersect_key(apiSources(), array_flip(['app/Services/Integrations/IntegrationConnectionService.php', 'app/Jobs/DeliverWebhook.php', 'app/Jobs/ProcessInboundWebhook.php', 'app/Providers/ApiServiceProvider.php', 'app/Console/Commands/IntegrationsSweep.php'])),
    );
}

test('outbound HTTP is sent only by reviewed classes; a tenant-chosen URL only through the URL guard, pinned, without redirects', function (): void {
    $reviewed = [
        // Fixed provider endpoints (configured by the platform, not by tenants).
        'app/Console/Commands/AiTestProviderCommand.php', 'app/Services/AI/Providers/OpenAiProvider.php', 'app/Services/AI/Providers/GeminiProvider.php',
        'app/Services/Communication/Providers/WhatsAppCloudProvider.php', 'app/Services/Communication/Providers/TwilioSmsProvider.php',
        'app/Services/Integrations/Calendar/Providers/GoogleCalendarProvider.php', 'app/Services/Integrations/Calendar/Providers/MicrosoftCalendarProvider.php',
        'app/Services/Integrations/Video/ZoomMeetingProvider.php',
        // SaaS-6: tenant-chosen webhook URLs.
        'app/Services/Webhooks/WebhookDeliveryService.php',
    ];

    foreach (apiSources() as $path => $source) {
        if (preg_match('/\bHttp::|new \\\\?GuzzleHttp\\\\Client|curl_init\(|file_get_contents\(\s*[\'"]https?:/', $source) === 1) {
            expect(in_array($path, $reviewed, true))->toBeTrue("{$path} sends HTTP: review it for SSRF and add it here");
        }
    }

    $delivery = apiSources()['app/Services/Webhooks/WebhookDeliveryService.php'];
    expect($delivery)->toContain('$this->urls->check(', "'allow_redirects' => false", 'CURLOPT_RESOLVE => [$destination->pin()]', '->post($destination->url)');
});

test('API resources list their fields: no model serialisation, no secret or tenant column', function (): void {
    foreach (apiSources('app/Http/Resources/Api/') as $path => $source) {
        expect((bool) preg_match('/parent::toArray|->toArray\(\)|attributesToArray|getAttributes\(\)|->only\(|->except\(/', $source))->toBeFalse("{$path} serialises a model — list the fields")
            ->and((bool) preg_match("/'[a-z_]*(secret|password|token|hash|remember|encrypted|tenant_id)[a-z_]*'\s*=>/", $source))->toBeFalse("{$path} exposes a secret or the tenant");
    }
});

test('no API code takes a tenant from the request', function (): void {
    foreach (apiIntegrationSources() as $path => $source) {
        expect((bool) preg_match('/\$request->(input|query|get|header|json|route|post|all)\(\s*[\'"](tenant|x-tenant)/i', $source))->toBeFalse("{$path} reads a tenant from the request")
            ->and((bool) preg_match('/[\'"]X-Tenant/i', $source))->toBeFalse("{$path} reads a tenant header");
    }
});

test('integration code never logs a secret, token, signature or payload', function (): void {
    foreach (apiIntegrationSources() as $path => $source) {
        preg_match_all('/Log::\w+\((?:[^;]|;(?!\s*$))*?\);/m', $source, $calls);

        foreach ($calls[0] as $call) {
            expect((bool) preg_match("/'[a-z_]*(secret|token|signature|payload|body|authorization|email|mobile|password)[a-z_]*'\s*=>/i", $call))->toBeFalse("{$path}: {$call}");
        }
    }
});

test('the SaaS-6 entitlement keys are named through the registry only', function (): void {
    foreach (apiSources() as $path => $source) {
        if ($path !== 'app/Enums/Entitlement.php') {
            expect((bool) preg_match('/[\'"](api\.access|integrations\.webhooks)[\'"]/', $source))->toBeFalse("{$path} names an entitlement key as a string — use App\\Enums\\Entitlement");
        }
    }
});

test('outbound webhook events carry identifiers and states, never personal data', function (): void {
    $provider = apiSources()['app/Providers/ApiServiceProvider.php'];
    $recording = substr($provider, (int) strpos($provider, 'function recordWebhookEvents'));

    expect((bool) preg_match('/->(full_name|email|mobile|phone|current_ctc|expected_ctc|resume|address|date_of_birth|remarks|notes)\b/', $recording))->toBeFalse();
});

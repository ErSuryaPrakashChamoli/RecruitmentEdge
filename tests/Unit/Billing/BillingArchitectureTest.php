<?php

/**
 * SaaS-4: the billing boundaries, enforced by construction. Billing knows what was purchased and
 * paid; it reaches SaaS-3 through one bridge, never touches access, and never handles a float.
 */
function billingSources(): array
{
    $root = dirname(__DIR__, 3);

    return collect(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/app')))
        ->filter(fn (SplFileInfo $file) => $file->getExtension() === 'php')
        ->mapWithKeys(fn (SplFileInfo $file) => [str_replace($root.'/', '', $file->getPathname()) => (string) file_get_contents($file->getPathname())])
        ->all();
}

/**
 * The billing domain: its services, models, job, webhook controller, page and console commands.
 *
 * @return array<string, string>
 */
function billingDomain(): array
{
    return collect(billingSources())->filter(fn (string $source, string $path): bool => str_starts_with($path, 'app/Services/Billing/')
        || preg_match('#^app/Models/Billing\w+\.php$#', $path) === 1
        || preg_match('#^app/Console/Commands/Billing\w+\.php$#', $path) === 1
        || in_array($path, ['app/Jobs/ProcessBillingEvent.php', 'app/Http/Controllers/Webhooks/BillingWebhookController.php', 'app/Filament/Pages/Billing.php'], true))->all();
}

test('provider-specific code lives only in the provider adapters', function (): void {
    foreach (billingSources() as $path => $source) {
        if (str_starts_with($path, 'app/Services/Billing/Providers/')) {
            continue;
        }

        expect((bool) preg_match('/\b(Stripe|Razorpay|Paddle|Braintree|Cashfree|PayU|FakeBillingProvider)\b/', $source))->toBeFalse("{$path} names a payment provider — go through BillingProvider");
    }
});

test('billing never touches access: no role, permission, membership, entitlement override or lifecycle writer', function (): void {
    $forbidden = '/\b(givePermissionTo|syncPermissions|assignRole|syncRoles|removeRole|revokePermissionTo|RoleAssignmentService|StaffAccessService|TenantMembership|EntitlementOverrideService|TenantEntitlementOverride|PlanAssignmentService::class\)->assign|TenantLifecycleService|EntitlementService)\b/';

    foreach (billingDomain() as $path => $source) {
        expect((bool) preg_match($forbidden, $source))->toBeFalse("{$path} reaches access or entitlements directly — billing state flows through CommercialSubscriptionService only");
    }
});

test('billing reaches the commercial control plane only through its bridge and the platform gate', function (): void {
    foreach (billingDomain() as $path => $source) {
        if (str_starts_with($path, 'app/Console/Commands/')) {
            continue;
        }

        preg_match_all('/Platform\\\\Commercial\\\\(\w+)/', $source, $uses);

        expect(array_values(array_diff(array_unique($uses[1]), ['CommercialSubscriptionService', 'PlatformOperatorGate'])))->toBe([], "{$path} reaches the commercial control plane beyond its bridge");
    }
});

/**
 * The code only — comments and docblocks removed.
 */
function billingCode(string $source): string
{
    return implode('', array_map(fn (mixed $token): string => is_array($token) ? (in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true) ? '' : $token[1]) : $token, token_get_all($source)));
}

test('money is never a float in billing', function (): void {
    foreach (billingDomain() as $path => $source) {
        $source = billingCode($source);

        expect((bool) preg_match('/\bfloat\b|\(float\)|floatval\(|\bround\(|number_format\(|\bfloor\(|\bceil\(/', $source))->toBeFalse("{$path} handles money with floating point — use App\\Services\\Billing\\Money (integer minor units)");
    }
});

test('webhook receipt only verifies, stores and queues: processing runs on the queue', function (): void {
    $sources = billingSources();

    foreach (['app/Services/Billing/BillingWebhookIngestor.php', 'app/Http/Controllers/Webhooks/BillingWebhookController.php'] as $path) {
        expect((bool) preg_match('/\b(PaymentService|SubscriptionService|SubscriptionStateMachine|BillingEventProcessor|CommercialSubscriptionService)\b/', $sources[$path]))->toBeFalse("{$path} applies billing changes inside the HTTP request");
    }
});

<?php

namespace App\Services\Platform;

use App\Enums\BillingInterval;
use App\Enums\Entitlement;
use App\Enums\PlatformCapability;
use App\Enums\SubscriptionSource;
use App\Models\AuditLog;
use App\Models\BillingInvoice;
use App\Models\BillingPayment;
use App\Models\BillingPrice;
use App\Models\BillingSubscription;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Billing\InvoiceService;
use App\Services\Billing\Money;
use App\Services\Billing\PaymentService;
use App\Services\Billing\SubscriptionService;
use App\Services\Platform\Commercial\EntitlementOverrideService;
use App\Services\Platform\Commercial\PlanAssignmentService;
use App\Services\Platform\Commercial\PlanCatalog;
use App\Services\Platform\Commercial\ProvisioningRequest;
use App\Services\Platform\Commercial\TenantProvisioningService;
use App\Services\Tenancy\TenantContext;
use Carbon\CarbonInterface;
use DomainException;
use InvalidArgumentException;
use Throwable;

/**
 * Platform commercial UI: the platform panel's thin adapter onto the SaaS-3 commercial control
 * plane and the SaaS-4 billing services — the same services the tenants:* and billing:* commands
 * call. It authorises the operator's platform capability, attributes the audit to them and turns
 * UI input into the services' arguments; every rule (state machines, locks, idempotency, audit)
 * stays in the service it calls. Billing records are only ever found inside the tenant the
 * operator is acting on, so an id from another tenant resolves to nothing.
 */
class TenantCommercialService
{
    /**
     * A limit with no ceiling, as the entitlement services take it.
     */
    public const string UNLIMITED = PlanCatalog::UNLIMITED;

    public function __construct(
        private readonly PlatformAuthorization $authorization,
        private readonly TenantProvisioningService $provisioning,
        private readonly PlanAssignmentService $plans,
        private readonly EntitlementOverrideService $overrides,
        private readonly SubscriptionService $subscriptions,
        private readonly PaymentService $payments,
        private readonly InvoiceService $invoices,
    ) {}

    /**
     * Provisions a tenant (tenants:provision). Region fields left empty are not passed, so the
     * provisioning request's own defaults apply.
     *
     * @param  array{slug: string, name: string, owner_email: string, owner_name?: ?string, plan: string, trial_days?: ?int, legal_name?: ?string, timezone?: ?string, locale?: ?string, currency?: ?string, country?: ?string}  $input
     */
    public function provision(array $input, User $operator): Tenant
    {
        $this->authorization->authorize($operator, PlatformCapability::TenantsManage);

        $arguments = [
            'slug' => trim($input['slug']),
            'name' => trim($input['name']),
            'ownerEmail' => trim($input['owner_email']),
            'planCode' => $input['plan'],
            'trialDays' => $input['trial_days'] ?? null,
            'ownerName' => filled($input['owner_name'] ?? null) ? trim((string) $input['owner_name']) : null,
            'legalName' => filled($input['legal_name'] ?? null) ? trim((string) $input['legal_name']) : null,
        ];

        foreach (['timezone', 'locale', 'currency', 'country'] as $field) {
            if (filled($input[$field] ?? null)) {
                $arguments[$field] = trim((string) $input[$field]);
            }
        }

        $request = new ProvisioningRequest(...$arguments);

        try {
            return AuditLog::asPlatformOperator($operator, fn (): Tenant => $this->provisioning->provision($request, $operator));
        } catch (DomainException $e) {
            throw $e;
        } catch (Throwable) {
            // The service has recorded the failure on the tenant and raised a critical platform event.
            throw new DomainException('Provisioning did not finish. It can be retried with the same details; the failure is under Operations → Events.');
        }
    }

    /**
     * Moves the tenant to the latest published version of an offered plan (tenants:plan).
     */
    public function assignPlan(Tenant $tenant, string $planCode, string $reason, User $operator): void
    {
        $this->authorization->authorize($operator, PlatformCapability::TenantsManage);

        AuditLog::asPlatformOperator($operator, fn () => $this->plans->assign($tenant, $this->plans->latestVersion($planCode), 'platform', $reason, $operator));
    }

    /**
     * Sets a per-tenant entitlement override (tenants:entitlement): true/false for a feature, an
     * integer or self::UNLIMITED for a limit.
     */
    public function setOverride(Tenant $tenant, Entitlement $entitlement, bool|int|string $value, string $reason, ?CarbonInterface $endsAt, User $operator): void
    {
        $this->authorization->authorize($operator, PlatformCapability::TenantsManage);

        AuditLog::asPlatformOperator($operator, fn () => $this->overrides->set($tenant, $entitlement, $value, $reason, $endsAt, $operator));
    }

    public function removeOverride(Tenant $tenant, Entitlement $entitlement, string $reason, User $operator): void
    {
        $this->authorization->authorize($operator, PlatformCapability::TenantsManage);
        $this->requireReason($reason);

        AuditLog::asPlatformOperator($operator, fn () => $this->overrides->remove($tenant, $entitlement, $reason, $operator));
    }

    /**
     * Subscribes the tenant (billing:subscription subscribe), billed manually at a current price or
     * under a contract. Provider billing starts with the payment provider, not here.
     *
     * @param  array{price_id?: ?int, plan?: ?string, amount?: ?string, currency?: ?string, interval?: ?string, contract_reference?: ?string}  $terms
     */
    public function subscribe(Tenant $tenant, SubscriptionSource $source, array $terms, string $reason, User $operator): BillingSubscription
    {
        $this->authorization->authorize($operator, PlatformCapability::CommercialManage);

        if ($source === SubscriptionSource::Provider) {
            throw new DomainException('Provider billing is started through the payment provider, not from the platform panel.');
        }

        if ($source === SubscriptionSource::Manual) {
            return AuditLog::asPlatformOperator($operator, fn (): BillingSubscription => $this->subscriptions->subscribe($tenant, $source, $reason, $operator, price: $this->currentPrice((int) ($terms['price_id'] ?? 0))));
        }

        $interval = BillingInterval::tryFrom((string) ($terms['interval'] ?? '')) ?? throw new DomainException('A contract needs a billing interval.');
        $amount = $this->money((string) ($terms['amount'] ?? ''), (string) ($terms['currency'] ?? ''));
        $plan = $this->plans->latestVersion((string) ($terms['plan'] ?? ''));

        return AuditLog::asPlatformOperator($operator, fn (): BillingSubscription => $this->subscriptions->subscribe(
            $tenant,
            $source,
            $reason,
            $operator,
            contractPlan: $plan,
            contractAmount: $amount,
            contractInterval: $interval,
            contractReference: $terms['contract_reference'] ?? null,
        ));
    }

    /**
     * Moves the live subscription to another current price (billing:subscription change-plan).
     */
    public function changeSubscriptionPlan(Tenant $tenant, int $priceId, string $reason, User $operator): BillingSubscription
    {
        $this->authorization->authorize($operator, PlatformCapability::CommercialManage);
        $subscription = $this->liveSubscription($tenant);

        return AuditLog::asPlatformOperator($operator, fn (): BillingSubscription => $this->subscriptions->changePlan($subscription, $this->currentPrice($priceId), $reason, $operator));
    }

    /**
     * Ends the live subscription at the end of its period, or now (billing:subscription cancel / cancel-now).
     */
    public function cancelSubscription(Tenant $tenant, bool $immediately, string $reason, User $operator): BillingSubscription
    {
        $this->authorization->authorize($operator, PlatformCapability::CommercialManage);
        $subscription = $this->liveSubscription($tenant);

        return AuditLog::asPlatformOperator($operator, fn (): BillingSubscription => $this->subscriptions->cancelForPlatform($subscription, $reason, $operator, $immediately));
    }

    /**
     * Records an offline payment of the whole amount still due on the invoice (billing:payment).
     * The amount comes from the stored invoice, never from the form: once it is paid, the invoice is
     * no longer due and the same submission is refused by PaymentService under the invoice's lock.
     */
    public function recordFullPayment(Tenant $tenant, int $invoiceId, string $externalReference, string $reason, User $operator): BillingPayment
    {
        $this->authorization->authorize($operator, PlatformCapability::CommercialManage);
        $invoice = $this->invoice($tenant, $invoiceId);

        return AuditLog::asPlatformOperator($operator, fn (): BillingPayment => $this->payments->recordManualPayment($invoice, $invoice->amountDue(), $externalReference, $reason, $operator));
    }

    /**
     * Refunds whatever of the payment is still refundable (billing:payment refund). Once refunded in
     * full, the same submission is refused by PaymentService.
     */
    public function refundRemaining(Tenant $tenant, int $paymentId, string $reason, User $operator): BillingPayment
    {
        $this->authorization->authorize($operator, PlatformCapability::CommercialManage);
        $payment = $this->payment($tenant, $paymentId);
        $remaining = $payment->amount()->minus(Money::of((int) $payment->amount_refunded_minor, $payment->currency));

        return AuditLog::asPlatformOperator($operator, fn (): BillingPayment => $this->payments->refund($payment, $remaining, $reason, $operator));
    }

    /**
     * Voids an open invoice with no payment towards it (billing:payment void).
     */
    public function voidInvoice(Tenant $tenant, int $invoiceId, string $reason, User $operator): BillingInvoice
    {
        $this->authorization->authorize($operator, PlatformCapability::CommercialManage);
        $invoice = $this->invoice($tenant, $invoiceId);

        return AuditLog::asPlatformOperator($operator, fn (): BillingInvoice => $this->invoices->void($invoice, $reason, $operator));
    }

    private function liveSubscription(Tenant $tenant): BillingSubscription
    {
        return TenantContext::current()->run($tenant, fn (): ?BillingSubscription => BillingSubscription::query()->where('is_live', true)->first())
            ?? throw new DomainException("{$tenant->name} has no live subscription.");
    }

    private function invoice(Tenant $tenant, int $invoiceId): BillingInvoice
    {
        return TenantContext::current()->run($tenant, fn (): ?BillingInvoice => BillingInvoice::query()->find($invoiceId))
            ?? throw new DomainException("That invoice is not one of {$tenant->name}'s.");
    }

    private function payment(Tenant $tenant, int $paymentId): BillingPayment
    {
        return TenantContext::current()->run($tenant, fn (): ?BillingPayment => BillingPayment::query()->find($paymentId))
            ?? throw new DomainException("That payment is not one of {$tenant->name}'s.");
    }

    private function currentPrice(int $priceId): BillingPrice
    {
        return BillingPrice::query()->whereKey($priceId)->where('is_current', true)->first()
            ?? throw new DomainException('Choose a current price.');
    }

    private function money(string $amount, string $currency): Money
    {
        try {
            return Money::parse($amount, $currency);
        } catch (InvalidArgumentException $e) {
            throw new DomainException($e->getMessage());
        }
    }

    private function requireReason(string $reason): void
    {
        if (trim($reason) === '') {
            throw new DomainException('A reason is required.');
        }
    }
}

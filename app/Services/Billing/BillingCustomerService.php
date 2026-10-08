<?php

namespace App\Services\Billing;

use App\Enums\AccessState;
use App\Models\AuditLog;
use App\Models\BillingCustomer;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Billing\Providers\BillingProvider;
use App\Services\Tenancy\TenantContext;
use DomainException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * SaaS-4: the tenant's billing identity — one per tenant, the company, never a person — and its
 * provider counterpart. The billing contact is the member invoices are addressed to; choosing one
 * grants nothing (no role, no permission, no ownership) and changes nobody's access.
 */
class BillingCustomerService
{
    public function __construct(private readonly BillingStatusService $status) {}

    /**
     * The current tenant's billing customer, created on first use from the tenant's own details.
     */
    public function forCurrentTenant(): BillingCustomer
    {
        $tenant = TenantContext::current()->requireTenant();

        if (($existing = BillingCustomer::query()->first()) !== null) {
            return $existing;
        }

        try {
            return DB::transaction(function () use ($tenant): BillingCustomer {
                $customer = BillingCustomer::query()->create(['legal_name' => $tenant->legal_name ?? $tenant->name, 'country' => $tenant->country]);
                AuditLog::record($customer, 'billing_customer_created', null, ['legal_name' => $customer->legal_name]);

                return $customer;
            });
        } catch (UniqueConstraintViolationException) {
            // Created a moment ago by a concurrent request: one customer per tenant.
            return BillingCustomer::query()->firstOrFail();
        }
    }

    /**
     * The provider's customer for $customer — created once (idempotency key = our id), recorded once.
     */
    public function ensureProviderCustomer(BillingCustomer $customer, BillingProvider $provider): BillingCustomer
    {
        if ($customer->provider_customer_ref !== null) {
            if ($customer->provider !== $provider->name()) {
                throw new DomainException('This customer is billed by another provider.');
            }

            return $customer;
        }

        $ref = $provider->createCustomer($customer, 'customer-'.$customer->tenant_id.'-'.$customer->id);

        BillingCustomer::query()->whereKey($customer->id)->whereNull('provider_customer_ref')->update(['provider' => $provider->name(), 'provider_customer_ref' => $ref, 'updated_at' => now()]);
        AuditLog::record($customer, 'billing_provider_customer_linked', null, ['provider' => $provider->name(), 'provider_customer_ref' => $ref]);

        return $customer->fresh();
    }

    /**
     * Tenant billing administrators (billing.manage) keep the invoice details current.
     *
     * @param  array{legal_name?: string|null, email?: string|null, tax_id?: string|null, address?: string|null, country?: string|null}  $details
     */
    public function updateDetails(array $details, User $actor): BillingCustomer
    {
        BillingAuthorization::assertCanManage($actor);
        $customer = $this->forCurrentTenant();
        $values = array_intersect_key($details, array_flip(['legal_name', 'email', 'tax_id', 'address', 'country']));

        if (filled($values['email'] ?? null) && filter_var($values['email'], FILTER_VALIDATE_EMAIL) === false) {
            throw new DomainException('Enter a valid billing email address.');
        }

        if (filled($values['country'] ?? null) && preg_match('/^[A-Z]{2}$/', (string) $values['country']) !== 1) {
            throw new DomainException('The country is a two-letter code.');
        }

        if (blank($values['legal_name'] ?? $customer->legal_name)) {
            throw new DomainException('Invoices need the organisation\'s legal name.');
        }

        return DB::transaction(function () use ($customer, $values): BillingCustomer {
            $old = $customer->only(array_keys($values));
            $customer->forceFill(array_map(fn (mixed $value): mixed => is_string($value) ? (trim($value) === '' ? null : mb_substr(trim($value), 0, 255)) : $value, $values))->save();
            AuditLog::record($customer, 'billing_details_updated', $old, $customer->only(array_keys($values)));
            $this->status->touch((int) $customer->tenant_id);

            return $customer;
        });
    }

    /**
     * Sets (or clears) the billing contact: an active member of this organisation.
     */
    public function setContact(?User $contact, User $actor): BillingCustomer
    {
        BillingAuthorization::assertCanManage($actor);
        $customer = $this->forCurrentTenant();

        if ($contact !== null && ! User::query()->whereKey($contact->getKey())->membersOfCurrentTenant(fn ($membership) => $membership->where('status', AccessState::Active->value))->exists()) {
            throw new DomainException('The billing contact must be an active member of this organisation.');
        }

        return DB::transaction(function () use ($customer, $contact): BillingCustomer {
            $old = $customer->contact_user_id;
            $customer->forceFill(['contact_user_id' => $contact?->getKey()])->save();
            AuditLog::record($customer, 'billing_contact_changed', ['contact_user_id' => $old], ['contact_user_id' => $contact?->getKey()]);
            $this->status->touch((int) $customer->tenant_id);

            return $customer;
        });
    }

    /**
     * The tenant's billing customer, read without creating one (platform reads).
     */
    public static function of(Tenant $tenant): ?BillingCustomer
    {
        return TenantContext::current()->run($tenant, fn (): ?BillingCustomer => BillingCustomer::query()->first());
    }
}

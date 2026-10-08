<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * SaaS-4: the tenant's billing identity — the company, never a person (people change; the billing
 * relationship stays with the organisation). One per tenant. The billing contact is a member the
 * invoices are addressed to; being the contact grants nothing (no role, no ownership).
 * state_version is bumped with every billing change of the tenant: it is the billing cache key.
 */
#[Fillable(['provider', 'provider_customer_ref', 'legal_name', 'email', 'contact_user_id', 'tax_id', 'address', 'country'])]
class BillingCustomer extends Model
{
    use BelongsToTenant;

    protected function casts(): array
    {
        return ['state_version' => 'integer'];
    }

    /**
     * @return HasMany<BillingSubscription, $this>
     */
    public function subscriptions(): HasMany
    {
        return $this->hasMany(BillingSubscription::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(User::class, 'contact_user_id');
    }
}

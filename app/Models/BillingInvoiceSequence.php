<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * SaaS-4: the platform's invoice series counter — one row per series (financial year), taken under
 * a row lock in the issuing transaction, so numbers are unique and gap-free. Platform table: the
 * platform issues every invoice, whatever the tenant.
 */
#[Fillable(['series', 'last_number'])]
class BillingInvoiceSequence extends Model
{
    protected function casts(): array
    {
        return ['last_number' => 'integer'];
    }
}

<?php

namespace App\Services\Billing;

use App\Models\BillingInvoiceSequence;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * SaaS-4: the platform's single invoice series — the platform issues every invoice, so numbers are
 * global, not per tenant: {prefix}/{financial year}/{000001}, e.g. RE/2026-27/000042. Taken under
 * the series row lock inside the issuing transaction: unique, and gap-free (a rolled-back issue
 * returns its number). Never count + 1, never a timestamp.
 */
class InvoiceNumberer
{
    public function next(?CarbonInterface $at = null): string
    {
        $series = $this->series($at ?? now());

        // Created once per series; a concurrent creation is a no-op.
        BillingInvoiceSequence::query()->insertOrIgnore(['series' => $series, 'last_number' => 0, 'created_at' => now(), 'updated_at' => now()]);

        return DB::transaction(function () use ($series): string {
            /** @var BillingInvoiceSequence $sequence */
            $sequence = BillingInvoiceSequence::query()->where('series', $series)->lockForUpdate()->firstOrFail();
            $number = $sequence->last_number + 1;
            $sequence->forceFill(['last_number' => $number])->save();

            return sprintf('%s/%06d', $series, $number);
        });
    }

    /**
     * "RE/2026-27" for a financial year starting in April, "RE/2026" for a calendar year.
     */
    public function series(CarbonInterface $at): string
    {
        $prefix = (string) config('billing.invoice_number.prefix', 'RE');
        $startMonth = (int) config('billing.invoice_number.year_starts_month', 4);

        if ($startMonth === 1) {
            return $prefix.'/'.$at->year;
        }

        $startYear = $at->month >= $startMonth ? $at->year : $at->year - 1;

        return sprintf('%s/%d-%02d', $prefix, $startYear, ($startYear + 1) % 100);
    }
}

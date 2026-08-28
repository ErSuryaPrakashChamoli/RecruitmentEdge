<?php

namespace App\Models;

use Database\Factories\RecruiterIncentivePaymentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['recruiter_incentive_calculation_id', 'amount', 'payment_date', 'payment_reference', 'paid_by', 'remarks'])]
class RecruiterIncentivePayment extends Model
{
    /** @use HasFactory<RecruiterIncentivePaymentFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'payment_date' => 'date',
        ];
    }

    /**
     * @return BelongsTo<RecruiterIncentiveCalculation, $this>
     */
    public function calculation(): BelongsTo
    {
        return $this->belongsTo(RecruiterIncentiveCalculation::class, 'recruiter_incentive_calculation_id');
    }

    /**
     * @return BelongsTo<Employee, $this>
     */
    public function paidBy(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'paid_by');
    }
}

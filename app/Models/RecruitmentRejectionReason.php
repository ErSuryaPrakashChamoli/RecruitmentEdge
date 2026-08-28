<?php

namespace App\Models;

use App\Enums\RejectionCategory;
use Database\Factories\RecruitmentRejectionReasonFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['name', 'code', 'category', 'is_active'])]
class RecruitmentRejectionReason extends Model
{
    /** @use HasFactory<RecruitmentRejectionReasonFactory> */
    use HasFactory, SoftDeletes;

    protected function casts(): array
    {
        return [
            'category' => RejectionCategory::class,
            'is_active' => 'boolean',
        ];
    }
}

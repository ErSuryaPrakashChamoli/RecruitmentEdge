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

    /**
     * Active reasons as Select option groups keyed by category label, in RejectionCategory case
     * order (General, Interview, Offer, Joining) — empty categories are omitted. Every
     * rejection/dropout reason dropdown should use this rather than listing every reason.
     *
     * @return array<string, array<int, string>>
     */
    public static function groupedActiveOptions(): array
    {
        $reasons = self::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'category'])
            ->groupBy(fn (self $reason): string => $reason->category->value);

        $groups = [];

        foreach (RejectionCategory::cases() as $category) {
            if ($reasons->has($category->value)) {
                $groups[$category->label()] = $reasons->get($category->value)->pluck('name', 'id')->all();
            }
        }

        return $groups;
    }

    public function isSelectable(): bool
    {
        return $this->is_active && ! $this->trashed();
    }
}

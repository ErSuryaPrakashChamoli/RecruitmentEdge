<?php

namespace App\Models;

use Database\Factories\SavedTableViewFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A user's saved filter/sort/search state for one List page (`resource` is that page's FQCN).
 * Strictly user-owned — loading one only ever replays Filament's own table filter/sort/search
 * input, so it can never surface data the resource's normal hierarchy-scoped query wouldn't
 * already show that user (Section 39).
 */
#[Fillable(['user_id', 'resource', 'name', 'filters', 'sort', 'search', 'is_default'])]
class SavedTableView extends Model
{
    /** @use HasFactory<SavedTableViewFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'filters' => 'array',
            'is_default' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}

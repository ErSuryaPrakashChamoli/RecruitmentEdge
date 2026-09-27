<?php

namespace App\Policies;

use App\Models\OfferLetterTemplate;
use App\Models\User;

/**
 * Offer letter templates are organisation-wide wording (no hierarchy scoping) — managing them is
 * gated by `settings.manage`. The protected standard (system) template can never be deleted.
 * Tailoring the letter of an individual offer is governed by OfferPolicy::update instead.
 */
class OfferLetterTemplatePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('settings.manage');
    }

    public function view(User $user, OfferLetterTemplate $offerLetterTemplate): bool
    {
        return $user->can('settings.manage');
    }

    public function create(User $user): bool
    {
        return $user->can('settings.manage');
    }

    public function update(User $user, OfferLetterTemplate $offerLetterTemplate): bool
    {
        return $user->can('settings.manage');
    }

    public function delete(User $user, OfferLetterTemplate $offerLetterTemplate): bool
    {
        return $user->can('settings.manage') && ! $offerLetterTemplate->is_system;
    }

    /**
     * Bulk actions (Phase 8.6 discovery security guard): explicit, never Filament's missing-method
     * fallback. Each selected record is still checked against the per-record rule.
     */
    public function deleteAny(User $user): bool
    {
        return $user->can('settings.manage');
    }
}

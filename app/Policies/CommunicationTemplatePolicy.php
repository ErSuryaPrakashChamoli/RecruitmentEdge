<?php

namespace App\Policies;

use App\Models\CommunicationTemplate;
use App\Models\User;

/**
 * Templates are organisation-wide wording, managed with communications.templates. They are never
 * deleted (archived instead) so sent messages keep their template reference.
 */
class CommunicationTemplatePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('communications.templates');
    }

    public function view(User $user, CommunicationTemplate $communicationTemplate): bool
    {
        return $user->can('communications.templates');
    }

    public function create(User $user): bool
    {
        return $user->can('communications.templates');
    }

    public function update(User $user, CommunicationTemplate $communicationTemplate): bool
    {
        return $user->can('communications.templates');
    }

    public function delete(User $user, CommunicationTemplate $communicationTemplate): bool
    {
        return false;
    }
}

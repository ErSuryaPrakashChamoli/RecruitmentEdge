<?php

namespace App\Policies;

use App\Models\CalendarConnection;
use App\Models\User;

/**
 * Employees manage their own calendar connection (calendar.connect); integrations.manage users may
 * see and disconnect anyone's. Tokens are never shown to anyone.
 */
class CalendarConnectionPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('calendar.connect') || $user->can('integrations.manage');
    }

    public function view(User $user, CalendarConnection $calendarConnection): bool
    {
        return $user->can('integrations.manage') || ($user->can('calendar.connect') && $calendarConnection->employee_id === $user->employee_id);
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, CalendarConnection $calendarConnection): bool
    {
        return $this->view($user, $calendarConnection);
    }

    public function delete(User $user, CalendarConnection $calendarConnection): bool
    {
        return false;
    }
}

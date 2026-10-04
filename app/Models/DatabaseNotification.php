<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Notifications\DatabaseNotification as BaseDatabaseNotification;

/**
 * SaaS-1: an in-app notification belongs to the tenant it was raised in. Staff identities are
 * global, so the bell and the Notification Center show a person only the notifications of the
 * tenant they are working in (User::notifications() uses this model).
 */
class DatabaseNotification extends BaseDatabaseNotification
{
    use BelongsToTenant;
}

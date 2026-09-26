<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Database\Factories\CalendarConnectionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An employee's connected Google/Microsoft calendar. Tokens are encrypted at rest, hidden from
 * serialisation and excluded from audit diffs (Auditable skips hidden attributes). Written only
 * by CalendarConnectionService (OAuth callback, token refresh, disconnect).
 */
#[Fillable(['employee_id', 'provider', 'account_email', 'access_token', 'refresh_token', 'token_expires_at', 'calendar_id', 'status', 'last_error', 'connected_at', 'last_synced_at'])]
#[Hidden(['access_token', 'refresh_token'])]
class CalendarConnection extends Model
{
    /** @use HasFactory<CalendarConnectionFactory> */
    use Auditable, HasFactory;

    protected function casts(): array
    {
        return [
            'access_token' => 'encrypted',
            'refresh_token' => 'encrypted',
            'token_expires_at' => 'datetime',
            'connected_at' => 'datetime',
            'last_synced_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Employee, $this>
     */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function tokenExpired(): bool
    {
        return $this->token_expires_at === null || $this->token_expires_at->subMinute()->isPast();
    }
}

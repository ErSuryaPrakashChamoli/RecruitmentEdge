<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Maps an interview to its event in an external calendar (CalendarSyncService only).
 */
#[Fillable(['interview_id', 'calendar_connection_id', 'provider', 'external_event_id', 'status', 'last_error', 'synced_at'])]
class InterviewCalendarEvent extends Model
{
    protected function casts(): array
    {
        return ['synced_at' => 'datetime'];
    }

    /**
     * @return BelongsTo<Interview, $this>
     */
    public function interview(): BelongsTo
    {
        return $this->belongsTo(Interview::class);
    }

    /**
     * @return BelongsTo<CalendarConnection, $this>
     */
    public function connection(): BelongsTo
    {
        return $this->belongsTo(CalendarConnection::class, 'calendar_connection_id');
    }
}

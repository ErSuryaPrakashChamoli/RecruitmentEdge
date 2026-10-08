<?php

namespace App\Filament\Resources\InterviewAvailabilitySlots\Widgets;

use App\Enums\InterviewSlotStatus;
use App\Enums\SlotBookingStatus;
use App\Filament\Resources\InterviewAvailabilitySlots\InterviewAvailabilitySlotResource;
use App\Models\InterviewSlotBooking;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * Self-scheduling KPIs over the slots the viewer can see (same scope as the slot list).
 */
class SelfSchedulingStats extends StatsOverviewWidget
{
    protected function getStats(): array
    {
        $slots = InterviewAvailabilitySlotResource::getEloquentQuery()->where('starts_at', '>=', now());

        $upcomingBookings = InterviewSlotBooking::query()
            ->where('status', SlotBookingStatus::Booked)
            ->whereIn('slot_id', (clone $slots)->select('id'))
            ->count();

        return [
            Stat::make('Upcoming self-scheduled interviews', $upcomingBookings),
            Stat::make('Unfilled upcoming slots', (clone $slots)->where('status', InterviewSlotStatus::Available)->count())
                ->description('Open for candidates to book'),
            Stat::make('Unfilled in the next 7 days', (clone $slots)->where('status', InterviewSlotStatus::Available)->where('starts_at', '<=', now()->addDays(7))->count()),
        ];
    }
}

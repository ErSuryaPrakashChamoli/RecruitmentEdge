<?php

namespace App\Filament\Resources\CalendarConnections\Pages;

use App\Filament\Resources\CalendarConnections\CalendarConnectionResource;
use App\Services\Integrations\Calendar\CalendarManager;
use Filament\Actions\Action;
use Filament\Resources\Pages\ListRecords;

class ListCalendarConnections extends ListRecords
{
    protected static string $resource = CalendarConnectionResource::class;

    protected function getHeaderActions(): array
    {
        return collect(CalendarManager::PROVIDERS)->keys()->map(fn (string $provider) => Action::make('connect_'.$provider)
            ->label('Connect '.($provider === 'google_calendar' ? 'Google Calendar' : 'Microsoft 365'))
            ->icon('heroicon-o-link')
            ->color('gray')
            ->visible(fn (): bool => (bool) auth()->user()?->can('calendar.connect') && auth()->user()?->employee_id !== null)
            ->url(route('integrations.calendar.connect', $provider)))
            ->all();
    }
}

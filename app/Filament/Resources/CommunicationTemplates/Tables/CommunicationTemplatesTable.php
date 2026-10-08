<?php

namespace App\Filament\Resources\CommunicationTemplates\Tables;

use App\Enums\CommunicationChannel;
use App\Enums\TemplateStatus;
use App\Models\CommunicationTemplate;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class CommunicationTemplatesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('key')
            ->columns([
                TextColumn::make('name')->searchable()->sortable()->description(fn (CommunicationTemplate $record) => $record->key),
                TextColumn::make('channel')->badge()->color('gray')->formatStateUsing(fn (CommunicationChannel $state) => $state->label()),
                TextColumn::make('language'),
                TextColumn::make('version')->prefix('v'),
                TextColumn::make('status')->badge()->formatStateUsing(fn (TemplateStatus $state) => $state->label())->color(fn (TemplateStatus $state) => $state->color()),
                TextColumn::make('updated_at')->since()->label('Updated')->sortable(),
            ])
            ->filters([
                SelectFilter::make('channel')->options(CommunicationChannel::options(sendableOnly: true)),
                SelectFilter::make('status')->options(TemplateStatus::options()),
            ])
            ->recordActions([EditAction::make()])
            ->emptyStateHeading('No templates yet')
            ->emptyStateIcon('heroicon-o-document-text');
    }
}

<?php

namespace App\Filament\Resources\CandidateJoinings\RelationManagers;

use App\Enums\DocumentStatus;
use App\Enums\DocumentType;
use App\Models\CandidateDocument;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Actions\EditAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class DocumentsRelationManager extends RelationManager
{
    protected static string $relationship = 'documents';

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('document_type')
                    ->options(collect(DocumentType::cases())->mapWithKeys(fn (DocumentType $t) => [$t->value => $t->label()]))
                    ->required(),
                FileUpload::make('file_path')
                    ->disk('local')
                    ->visibility('private')
                    ->directory('candidate-documents'),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('document_type')
            ->columns([
                TextColumn::make('document_type')
                    ->badge()
                    ->formatStateUsing(fn (DocumentType $state) => $state->label()),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (DocumentStatus $state) => $state->label())
                    ->color(fn (DocumentStatus $state) => match ($state) {
                        DocumentStatus::Verified => 'success',
                        DocumentStatus::Rejected => 'danger',
                        DocumentStatus::Submitted => 'warning',
                        DocumentStatus::Pending => 'gray',
                    }),
                TextColumn::make('verifiedBy.first_name')
                    ->label('Verified By')
                    ->formatStateUsing(fn ($record) => $record->verifiedBy?->fullName() ?? '—'),
            ])
            ->headerActions([
                CreateAction::make()
                    ->mutateFormDataUsing(function (array $data): array {
                        $data['status'] = DocumentStatus::Submitted;

                        return $data;
                    }),
            ])
            ->recordActions([
                Action::make('verify')
                    ->label('Verify')
                    ->color('success')
                    ->icon('heroicon-o-check-badge')
                    ->visible(fn (CandidateDocument $record) => $record->status !== DocumentStatus::Verified)
                    ->action(function (CandidateDocument $record): void {
                        $record->update([
                            'status' => DocumentStatus::Verified,
                            'verified_by' => Filament::auth()->user()?->employee_id,
                            'verified_at' => now(),
                        ]);
                        Notification::make()->title('Document verified')->success()->send();
                    }),
                Action::make('rejectDocument')
                    ->label('Reject')
                    ->color('danger')
                    ->icon('heroicon-o-x-circle')
                    ->visible(fn (CandidateDocument $record) => $record->status !== DocumentStatus::Rejected)
                    ->requiresConfirmation()
                    ->action(fn (CandidateDocument $record) => $record->update(['status' => DocumentStatus::Rejected])),
                EditAction::make(),
            ])
            ->toolbarActions([]);
    }
}

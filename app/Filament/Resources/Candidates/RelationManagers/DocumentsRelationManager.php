<?php

namespace App\Filament\Resources\Candidates\RelationManagers;

use App\Enums\DocumentStatus;
use App\Enums\DocumentType;
use App\Models\CandidateDocument;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Candidate-level documents (resume, ID proof, education, etc.) that can be collected before a
 * joining record exists. Documents added from a joining also appear here (read via candidate_id)
 * but stay governed by the joining — see CandidateDocumentPolicy.
 */
class DocumentsRelationManager extends RelationManager
{
    protected static string $relationship = 'documents';

    protected static ?string $title = 'Documents';

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('document_type')
                    ->options(collect(DocumentType::cases())->mapWithKeys(fn (DocumentType $t) => [$t->value => $t->label()]))
                    ->required(),
                Select::make('status')
                    ->options(collect(DocumentStatus::cases())->mapWithKeys(fn (DocumentStatus $s) => [$s->value => $s->label()]))
                    ->default(DocumentStatus::Submitted->value)
                    ->required(),
                FileUpload::make('file_path')
                    ->label('File')
                    ->disk('local')
                    ->visibility('private')
                    ->directory('candidate-documents')
                    ->columnSpanFull(),
                Textarea::make('remarks')
                    ->columnSpanFull(),
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
                    ->color(fn (DocumentStatus $state) => $state->color()),
                TextColumn::make('candidate_joining_id')
                    ->label('Added From')
                    ->state(fn (CandidateDocument $record): string => $record->candidate_joining_id !== null ? 'Joining' : 'Candidate'),
                TextColumn::make('remarks')
                    ->wrap()
                    ->placeholder('—'),
                TextColumn::make('verifiedBy.first_name')
                    ->label('Verified By')
                    ->formatStateUsing(fn (CandidateDocument $record) => $record->verifiedBy?->fullName() ?? '—'),
            ])
            ->headerActions([
                CreateAction::make()
                    ->visible(fn (): bool => (bool) auth()->user()?->can('update', $this->getOwnerRecord()))
                    ->mutateFormDataUsing(fn (array $data): array => self::stampVerification($data)),
            ])
            ->recordActions([
                EditAction::make()
                    ->mutateFormDataUsing(fn (array $data): array => self::stampVerification($data)),
                DeleteAction::make(),
            ])
            ->toolbarActions([]);
    }

    /**
     * Records who verified a document when it is saved with the Verified status.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function stampVerification(array $data): array
    {
        $data['status'] ??= DocumentStatus::Submitted->value;

        if (($data['status'] instanceof DocumentStatus ? $data['status'] : DocumentStatus::from($data['status'])) === DocumentStatus::Verified) {
            $data['verified_by'] = Filament::auth()->user()?->employee_id;
            $data['verified_at'] = now();
        }

        return $data;
    }
}

<?php

namespace App\Filament\Resources\AiDocuments\Pages;

use App\Filament\Resources\AiDocuments\AiDocumentResource;
use App\Models\AuditLog;
use Filament\Facades\Filament;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Facades\Storage;

class CreateAiDocument extends CreateRecord
{
    protected static string $resource = AiDocumentResource::class;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['uploaded_by'] = Filament::auth()->user()?->employee_id;
        $data['status'] = 'pending';
        $data['mime_type'] = Storage::disk('local')->mimeType($data['file_path']);
        // The form only submits once the no-personal-data declaration is accepted (Phase 8.1).
        $data['privacy_declared_at'] = now();
        $data['privacy_declared_by'] = Filament::auth()->id();

        return $data;
    }

    protected function afterCreate(): void
    {
        AuditLog::record($this->record, 'ai_document_privacy_declared', null, ['declared_by' => Filament::auth()->id()]);
    }
}

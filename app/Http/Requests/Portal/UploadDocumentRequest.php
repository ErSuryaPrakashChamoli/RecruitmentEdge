<?php

namespace App\Http\Requests\Portal;

use App\Enums\DocumentType;
use App\Services\CandidatePortalService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\File;

class UploadDocumentRequest extends FormRequest
{
    public const int MAX_KILOBYTES = 5120;

    public function authorize(): bool
    {
        return $this->user('candidate') !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'document_type' => ['required', Rule::in(array_map(fn (DocumentType $t) => $t->value, CandidatePortalService::UPLOADABLE_DOCUMENT_TYPES))],
            'file' => ['required', File::types(['pdf', 'jpg', 'jpeg', 'png', 'doc', 'docx'])->max(self::MAX_KILOBYTES)],
        ];
    }
}

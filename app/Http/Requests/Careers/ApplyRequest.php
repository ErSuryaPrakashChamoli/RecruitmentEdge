<?php

namespace App\Http\Requests\Careers;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\File;

/**
 * A public job application. Only these fields reach CareerApplicationService; `website` is a
 * honeypot that humans never fill.
 */
class ApplyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'full_name' => ['required', 'string', 'min:2', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'mobile' => ['required', 'string', 'max:20', 'regex:/^[0-9+\-\s()]{8,20}$/'],
            'current_city' => ['nullable', 'string', 'max:255'],
            'current_company' => ['nullable', 'string', 'max:255'],
            'total_experience' => ['nullable', 'numeric', 'min:0', 'max:60'],
            'resume' => ['required', File::types(['pdf', 'doc', 'docx'])->max(5120)],
            'privacy_consent' => ['accepted'],
            'consent_email' => ['nullable', 'boolean'],
            'consent_whatsapp' => ['nullable', 'boolean'],
            'website' => ['prohibited'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['privacy_consent.accepted' => 'Please agree to the processing of your application data.'];
    }
}

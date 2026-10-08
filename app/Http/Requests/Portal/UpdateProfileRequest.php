<?php

namespace App\Http\Requests\Portal;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Only the whitelisted profile fields validate here, and CandidatePortalService re-applies the
 * same whitelist — any other key in the payload is ignored.
 */
class UpdateProfileRequest extends FormRequest
{
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
            'alternate_mobile' => ['nullable', 'string', 'max:20', 'regex:/^[0-9+\-\s()]{7,20}$/'],
            'current_city' => ['nullable', 'string', 'max:255'],
            'location' => ['nullable', 'string', 'max:255'],
            'current_company' => ['nullable', 'string', 'max:255'],
            'current_designation' => ['nullable', 'string', 'max:255'],
            'notice_period_days' => ['nullable', 'integer', 'min:0', 'max:365'],
            'communication_preferences' => ['nullable', 'array'],
            'communication_preferences.email' => ['boolean'],
            'communication_preferences.sms' => ['boolean'],
            'communication_preferences.whatsapp' => ['boolean'],
            'communication_preferences.phone' => ['boolean'],
        ];
    }
}

<?php

namespace App\Http\Requests\Portal;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Access to the invitation itself is checked by SchedulingController (signed link or owning
 * portal account); this only validates the payload.
 */
class BookSlotRequest extends FormRequest
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
            'slot' => ['required', 'string', 'ulid'],
            'note' => ['nullable', 'string', 'max:500'],
            'reason' => ['nullable', 'string', 'max:500'],
        ];
    }
}

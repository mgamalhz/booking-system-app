<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AvailabilityRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'start_date' => ['required', 'date_format:Y-m-d'],
            'end_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:start_date'],
            'timezone' => ['required', 'timezone:all'],
            'starts_after' => ['sometimes', 'date_format:H:i'],
            'ends_before' => ['sometimes', 'date_format:H:i'],
        ];
    }

    public function filters(): array
    {
        return array_filter($this->safe()->only(['starts_after', 'ends_before']), fn ($value) => $value !== null);
    }
}

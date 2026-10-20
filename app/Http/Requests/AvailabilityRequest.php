<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AvailabilityRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'start_date' => ['required', 'date_format:Y-m-d'],
            'end_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:start_date', function (string $attribute, mixed $value, \Closure $fail): void {
                $start = strtotime((string) $this->input('start_date'));
                $end = is_string($value) ? strtotime($value) : false;
                if ($start !== false && $end !== false && $end > strtotime('+1 year', $start)) {
                    $fail('The date range must not exceed one year.');
                }
            }],
            'timezone' => ['required', 'timezone:all'],
            'type' => ['sometimes', Rule::in(['one-on-one', 'group'])],
            'starts_after' => ['sometimes', 'date_format:H:i'],
            'ends_before' => ['sometimes', 'date_format:H:i'],
        ];
    }

    /**
     * @return array{starts_after?: string, ends_before?: string}
     */
    public function filters(): array
    {
        return array_filter($this->safe()->only(['starts_after', 'ends_before', 'type']), fn ($value) => $value !== null);
    }
}

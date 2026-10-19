<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreBookingRequest extends FormRequest
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
            'customer_id' => ['required', 'integer', 'exists:customers,id'],
            'resource_id' => ['required', 'integer', 'exists:resources,id'],
            'slot_id' => ['required', 'integer', 'exists:slots,id'],
            'type' => ['sometimes', 'nullable', Rule::in(['one-on-one', 'recurring', 'group'])],
            'max_participants' => ['required_if:type,group', 'nullable', 'integer', 'min:1'],
            'recurrence_rule' => ['required_if:type,recurring', 'nullable', Rule::in(['weekly', 'biweekly', 'monthly'])],
            'start_date' => ['required_if:type,recurring', 'nullable', 'date'],
            'end_date' => ['required_if:type,recurring', 'nullable', 'date', 'after:start_date', function (string $attribute, mixed $value, \Closure $fail): void {
                $start = strtotime((string) $this->input('start_date'));
                $end = is_string($value) ? strtotime($value) : false;
                if ($start !== false && $end !== false && $end > strtotime('+1 year', $start)) {
                    $fail('The date range must not exceed one year.');
                }
            }],
            'start_time' => ['required_if:type,recurring', 'date_format:H:i'],
            'end_time' => ['required_if:type,recurring', 'date_format:H:i', 'after:start_time'],
        ];
    }
}

<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateBookingRequest extends FormRequest
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
            'customer_id' => ['sometimes', 'integer', 'exists:customers,id'],
            'resource_id' => ['sometimes', 'integer', 'exists:resources,id',
                Rule::unique('bookings', 'customer_id')
                    ->where('customer_id', $this->customer_id)
                    ->where('slot_id', $this->slot_id),
            ],
            'slot_id' => ['sometimes', 'integer', 'exists:slots,id'],
            'status' => ['sometimes', Rule::in(['pending', 'confirmed', 'canceled', 'completed'])],
            'type' => ['sometimes', 'nullable', Rule::in(['one-on-one', 'recurring', 'group'])],
            'max_participants' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'recurrence_rule' => ['sometimes', 'nullable', Rule::in(['weekly', 'biweekly', 'monthly'])],
            'start_date' => ['sometimes', 'nullable', 'date'],
            'end_date' => ['sometimes', 'nullable', 'date', 'after_or_equal:start_date'],
        ];
    }
}

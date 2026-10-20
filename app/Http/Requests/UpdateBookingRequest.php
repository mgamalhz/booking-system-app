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
            'status' => ['required', Rule::in(['confirmed', 'canceled'])],
            ...array_fill_keys([
                'customer_id', 'resource_id', 'slot_id', 'type', 'max_participants',
                'recurrence_rule', 'start_date', 'end_date', 'start_time', 'end_time',
            ], ['missing']),
        ];
    }
}

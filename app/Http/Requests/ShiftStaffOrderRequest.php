<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ShiftStaffOrderRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'store_id' => ['required', 'integer', 'exists:stores,id'],
            'staff_ids' => ['required', 'array', 'min:1', 'max:2000'],
            'staff_ids.*' => ['required', 'integer', 'distinct', 'exists:staffs,id'],
        ];
    }
}

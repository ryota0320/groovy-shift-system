<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class MonthlyShiftStaffRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'store_id' => ['required', 'integer', 'exists:stores,id'],
            'staff_id' => ['required', 'integer', 'exists:staffs,id'],
            'month' => ['required', 'date_format:Y-m'],
        ];
    }
}

<?php

namespace App\Http\Requests;

use App\Enums\ShiftType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class ShiftDailyRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'store_id' => ['required', 'integer', 'exists:stores,id'],
            'shift_date' => ['required', 'date'],
            'shifts' => ['required', 'array'],
            'shifts.*.staff_id' => ['required', 'integer', 'distinct', 'exists:staffs,id'],
            'shifts.*.shift_type' => ['nullable', Rule::enum(ShiftType::class)],
            'shifts.*.start_time' => ['nullable', 'date_format:H:i'],
            'shifts.*.work_store_id' => ['nullable', 'integer', 'exists:stores,id'],
        ];
    }

    /** @return list<callable(Validator): void> */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                foreach ($this->input('shifts', []) as $index => $shift) {
                    if (! is_array($shift)) {
                        continue;
                    }

                    ShiftCellRequest::validateShiftFields(
                        $validator,
                        $shift['shift_type'] ?? null,
                        $shift['start_time'] ?? null,
                        $shift['work_store_id'] ?? null,
                        "shifts.{$index}.",
                    );
                }
            },
        ];
    }
}

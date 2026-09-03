<?php

namespace App\Http\Requests;

use App\Enums\ShiftType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class ShiftCellRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'store_id' => ['required', 'integer', 'exists:stores,id'],
            'staff_id' => ['required', 'integer', 'exists:staffs,id'],
            'shift_date' => ['required', 'date'],
            'shift_type' => ['nullable', Rule::enum(ShiftType::class)],
            'start_time' => ['nullable', 'date_format:H:i'],
        ];
    }

    /** @return list<callable(Validator): void> */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                self::validateShiftFields(
                    $validator,
                    $this->input('shift_type'),
                    $this->input('start_time'),
                );
            },
        ];
    }

    public static function validateShiftFields(
        Validator $validator,
        mixed $shiftType,
        mixed $startTime,
        string $prefix = '',
    ): void {
        $type = is_string($shiftType) ? ShiftType::tryFrom($shiftType) : null;
        $timeField = $prefix.'start_time';

        if ($type === ShiftType::Time) {
            if (! is_string($startTime) || ! preg_match('/^(?:[01]\\d|2[0-3]):00$/', $startTime)) {
                $validator->errors()->add($timeField, '時刻指定は00:00〜23:00の1時間単位で入力してください。');
            }

            return;
        }

        if ($startTime !== null && $startTime !== '') {
            $validator->errors()->add($timeField, '早番・休み・未設定では開始時刻を指定できません。');
        }
    }
}

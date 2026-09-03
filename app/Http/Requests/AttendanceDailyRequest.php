<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AttendanceDailyRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'store_id' => ['required', 'integer', 'exists:stores,id'],
            'work_date' => ['required', 'date_format:Y-m-d'],
            'holiday_confirmed' => ['sometimes', 'boolean'],
            'records' => ['required', 'array', 'min:1', 'max:500'],
            'records.*.staff_id' => ['required', 'integer', 'distinct', 'exists:staffs,id'],
            'records.*.clock_in_offset_minutes' => [
                'required', 'integer', 'min:0', 'max:2025', 'multiple_of:15',
            ],
            'records.*.clock_out_offset_minutes' => [
                'required', 'integer', 'min:15', 'max:2040', 'multiple_of:15',
            ],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'records.required' => '保存する勤怠を1件以上指定してください。',
            'records.min' => '保存する勤怠を1件以上指定してください。',
            'records.*.staff_id.distinct' => '同じスタッフを重複して保存できません。',
            'records.*.clock_in_offset_minutes.required' => '実出勤を選択してください。',
            'records.*.clock_out_offset_minutes.required' => '実退勤を選択してください。',
            'records.*.clock_in_offset_minutes.multiple_of' => '実出勤は15分単位で指定してください。',
            'records.*.clock_out_offset_minutes.multiple_of' => '実退勤は15分単位で指定してください。',
            'records.*.clock_out_offset_minutes.max' => '実退勤は翌10:00までにしてください。',
        ];
    }
}

<?php

namespace App\Http\Requests;

use App\Services\AttendanceTimeService;
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
                'required',
                'integer',
                'min:'.AttendanceTimeService::BUSINESS_DAY_CUTOFF_MINUTES,
                'max:'.AttendanceTimeService::MAX_CLOCK_IN_OFFSET_MINUTES,
                'multiple_of:15',
            ],
            'records.*.clock_out_offset_minutes' => [
                'required',
                'integer',
                'min:'.(AttendanceTimeService::BUSINESS_DAY_CUTOFF_MINUTES + 15),
                'max:'.AttendanceTimeService::MAX_CLOCK_OUT_OFFSET_MINUTES,
                'multiple_of:15',
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
            'records.*.clock_in_offset_minutes.min' => '実出勤は営業日当日12:00以降にしてください。',
            'records.*.clock_in_offset_minutes.max' => '実出勤は営業日翌日11:45までにしてください。',
            'records.*.clock_out_offset_minutes.min' => '実退勤は実出勤より後にしてください。',
            'records.*.clock_out_offset_minutes.max' => '1回の勤務時間は24時間未満にしてください。',
        ];
    }
}

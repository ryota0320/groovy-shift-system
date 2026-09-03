<?php

namespace App\Http\Requests\Master;

use App\Models\Store;
use App\Models\StoreHoliday;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreHolidayRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        $store = $this->route('store');
        $storeId = $store instanceof Store ? $store->id : null;

        return [
            'holiday_date' => [
                'required',
                'date',
                Rule::unique(StoreHoliday::class)->where(
                    fn ($query) => $query->where('store_id', $storeId),
                ),
            ],
            'holiday_month' => ['nullable', 'date_format:Y-m'],
        ];
    }

    /** @return list<callable(Validator): void> */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $month = $this->input('holiday_month');
                $date = $this->input('holiday_date');

                if (is_string($month)
                    && is_string($date)
                    && substr($date, 0, 7) !== $month) {
                    $validator->errors()->add(
                        'holiday_date',
                        '対象年月内の日付を選択してください。',
                    );
                }
            },
        ];
    }
}

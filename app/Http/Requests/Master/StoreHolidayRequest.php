<?php

namespace App\Http\Requests\Master;

use App\Models\Store;
use App\Models\StoreHoliday;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

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
        ];
    }
}

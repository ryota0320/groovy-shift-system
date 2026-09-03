<?php

namespace App\Http\Requests\Master;

use App\Models\Store;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        $store = $this->route('store');

        return [
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique(Store::class)->ignore($store instanceof Store ? $store->id : null),
            ],
            'opening_time' => ['required', 'date_format:H:i'],
            'closing_time' => ['required', 'date_format:H:i'],
            'is_active' => ['required', 'boolean'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'opening_time.required' => '開店時間を入力してください。',
            'opening_time.date_format' => '開店時間は時刻の形式で入力してください。',
            'closing_time.required' => '閉店時間を入力してください。',
            'closing_time.date_format' => '閉店時間は時刻の形式で入力してください。',
        ];
    }
}

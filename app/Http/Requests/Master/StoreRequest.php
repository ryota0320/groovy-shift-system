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
            'is_active' => ['required', 'boolean'],
        ];
    }
}

<?php

namespace App\Http\Requests\Master;

use App\Enums\IncomeTaxCategory;
use App\Http\Requests\Master\Concerns\HasEffectivePeriodRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StaffIncomeTaxSettingRequest extends FormRequest
{
    use HasEffectivePeriodRules;

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'tax_category' => ['required', Rule::enum(IncomeTaxCategory::class)],
            'dependent_count' => ['required', 'integer', 'min:0'],
            ...$this->effectivePeriodRules(),
        ];
    }
}

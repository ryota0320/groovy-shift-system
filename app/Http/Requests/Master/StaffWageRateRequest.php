<?php

namespace App\Http\Requests\Master;

use App\Http\Requests\Master\Concerns\HasEffectivePeriodRules;
use Illuminate\Foundation\Http\FormRequest;

class StaffWageRateRequest extends FormRequest
{
    use HasEffectivePeriodRules;

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'hourly_wage' => ['required', 'integer', 'min:0'],
            ...$this->effectivePeriodRules(),
        ];
    }
}

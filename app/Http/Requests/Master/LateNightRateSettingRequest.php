<?php

namespace App\Http\Requests\Master;

use App\Http\Requests\Master\Concerns\HasEffectivePeriodRules;
use Illuminate\Foundation\Http\FormRequest;

class LateNightRateSettingRequest extends FormRequest
{
    use HasEffectivePeriodRules;

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'amount_per_hour' => ['required', 'integer', 'min:0'],
            ...$this->effectivePeriodRules(),
        ];
    }
}

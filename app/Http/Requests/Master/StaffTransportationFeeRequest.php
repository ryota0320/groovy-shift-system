<?php

namespace App\Http\Requests\Master;

use App\Enums\TransportationTaxType;
use App\Http\Requests\Master\Concerns\HasEffectivePeriodRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StaffTransportationFeeRequest extends FormRequest
{
    use HasEffectivePeriodRules;

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'store_id' => ['required', 'integer', 'exists:stores,id'],
            'amount_per_day' => ['required', 'integer', 'min:0'],
            'tax_type' => ['required', Rule::enum(TransportationTaxType::class)],
            ...$this->effectivePeriodRules(),
        ];
    }
}

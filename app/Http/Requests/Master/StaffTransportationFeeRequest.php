<?php

namespace App\Http\Requests\Master;

use App\Enums\TransportationTaxType;
use App\Http\Requests\Master\Concerns\HasEffectivePeriodRules;
use App\Models\StaffStoreTransportationFee;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StaffTransportationFeeRequest extends FormRequest
{
    use HasEffectivePeriodRules;

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $transportationFee = $this->route('transportationFee');
        $currentStoreId = $transportationFee instanceof StaffStoreTransportationFee
            ? $transportationFee->store_id
            : null;

        return [
            'store_id' => [
                'required',
                'integer',
                Rule::exists('stores', 'id')->where(fn ($query) => $query
                    ->where('is_active', true)
                    ->when($currentStoreId !== null, fn ($query) => $query->orWhere('id', $currentStoreId))),
            ],
            'amount_per_day' => ['required', 'integer', 'min:0'],
            'tax_type' => ['required', Rule::enum(TransportationTaxType::class)],
            ...$this->effectivePeriodRules(),
        ];
    }
}

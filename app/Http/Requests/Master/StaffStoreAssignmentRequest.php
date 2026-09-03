<?php

namespace App\Http\Requests\Master;

use App\Http\Requests\Master\Concerns\HasEffectivePeriodRules;
use Illuminate\Foundation\Http\FormRequest;

class StaffStoreAssignmentRequest extends FormRequest
{
    use HasEffectivePeriodRules;

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'store_id' => ['required', 'integer', 'exists:stores,id'],
            ...$this->effectivePeriodRules(),
        ];
    }
}

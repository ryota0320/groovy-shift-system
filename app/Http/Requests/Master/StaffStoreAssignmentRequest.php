<?php

namespace App\Http\Requests\Master;

use App\Http\Requests\Master\Concerns\HasEffectivePeriodRules;
use App\Models\StaffStoreAssignment;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StaffStoreAssignmentRequest extends FormRequest
{
    use HasEffectivePeriodRules;

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $assignment = $this->route('assignment');
        $currentStoreId = $assignment instanceof StaffStoreAssignment ? $assignment->store_id : null;

        return [
            'store_id' => [
                'required',
                'integer',
                Rule::exists('stores', 'id')->where(fn ($query) => $query
                    ->where('is_active', true)
                    ->when($currentStoreId !== null, fn ($query) => $query->orWhere('id', $currentStoreId))),
            ],
            ...$this->effectivePeriodRules(),
        ];
    }
}

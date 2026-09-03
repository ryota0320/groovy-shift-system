<?php

namespace App\Http\Requests\Master;

use App\Enums\EmploymentType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StaffRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'employment_type' => ['required', Rule::enum(EmploymentType::class)],
            'hired_at' => ['nullable', 'date'],
            'retired_at' => [
                'nullable',
                'date',
                Rule::when($this->filled('hired_at'), 'after_or_equal:hired_at'),
            ],
        ];
    }
}

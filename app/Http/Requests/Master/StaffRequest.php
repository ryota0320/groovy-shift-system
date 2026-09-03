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
            'store_id' => [
                Rule::requiredIf($this->isMethod('post')),
                'nullable',
                'integer',
                Rule::exists('stores', 'id')->where('is_active', true),
            ],
            'assignment_effective_from' => [
                Rule::requiredIf($this->isMethod('post')),
                'nullable',
                'date',
                Rule::when($this->filled('hired_at'), 'after_or_equal:hired_at'),
                Rule::when($this->filled('retired_at'), 'before_or_equal:retired_at'),
            ],
        ];
    }
}

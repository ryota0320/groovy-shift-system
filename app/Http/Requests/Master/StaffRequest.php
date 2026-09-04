<?php

namespace App\Http\Requests\Master;

use App\Enums\EmploymentType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StaffRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $displayName = trim((string) $this->input('display_name', ''));
        $this->merge([
            'last_name' => trim((string) $this->input('last_name', '')),
            'first_name' => trim((string) $this->input('first_name', '')),
            'display_name' => $displayName === '' ? null : $displayName,
        ]);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'last_name' => ['required', 'string', 'max:120'],
            'first_name' => ['required', 'string', 'max:120'],
            'display_name' => ['nullable', 'string', 'max:255'],
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

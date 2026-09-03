<?php

namespace App\Http\Requests\Master\Concerns;

trait HasEffectivePeriodRules
{
    /** @return array<string, list<string>> */
    protected function effectivePeriodRules(): array
    {
        return [
            'effective_from' => ['required', 'date'],
            'effective_to' => ['nullable', 'date', 'after_or_equal:effective_from'],
        ];
    }
}

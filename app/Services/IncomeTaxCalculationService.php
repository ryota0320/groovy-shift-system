<?php

namespace App\Services;

use App\Enums\IncomeTaxCategory;
use App\Models\IncomeTaxRule;
use App\Models\IncomeTaxTableVersion;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class IncomeTaxCalculationService
{
    public function calculate(
        int $taxYear,
        IncomeTaxCategory $category,
        int $dependentCount,
        int $referenceAmount,
    ): int {
        $version = IncomeTaxTableVersion::query()->where('tax_year', $taxYear)->first();
        if (! $version instanceof IncomeTaxTableVersion) {
            throw ValidationException::withMessages([
                'payroll' => "{$taxYear}年分の源泉徴収税額表が登録されていません。",
            ]);
        }

        $tableDependentCount = $category === IncomeTaxCategory::Ko
            ? min($dependentCount, 7)
            : null;
        $rule = IncomeTaxRule::query()
            ->where('table_version_id', $version->id)
            ->where('tax_category', $category->value)
            ->when(
                $tableDependentCount === null,
                fn ($query) => $query->whereNull('dependent_count'),
                fn ($query) => $query->where('dependent_count', $tableDependentCount),
            )
            ->where('min_amount', '<=', max(0, $referenceAmount))
            ->where(fn ($query) => $query
                ->whereNull('max_amount')
                ->orWhere('max_amount', '>', max(0, $referenceAmount)))
            ->orderByDesc('min_amount')
            ->first();
        if (! $rule instanceof IncomeTaxRule) {
            throw ValidationException::withMessages([
                'payroll' => "{$taxYear}年分の源泉徴収税額表に該当する税額区分がありません。",
            ]);
        }

        $tax = $this->evaluate($rule, max(0, $referenceAmount));
        if ($category === IncomeTaxCategory::Ko && $dependentCount > 7) {
            $tax = max(0, $tax - (($dependentCount - 7) * 1610));
        }

        return $tax;
    }

    private function evaluate(IncomeTaxRule $rule, int $amount): int
    {
        if ($rule->calculation_type === 'fixed') {
            return $rule->fixed_tax_amount ?? 0;
        }

        /** @var array<string, int> $parameters */
        $parameters = $rule->parameters ?? [];
        $numerator = $parameters['rate_numerator'] ?? 0;
        $denominator = $parameters['rate_denominator'] ?? 0;
        if ($numerator < 0 || $denominator < 1) {
            throw new RuntimeException('所得税計算ルールの税率パラメータが不正です。');
        }

        if ($rule->calculation_type === 'percentage_floor') {
            return intdiv($amount * $numerator, $denominator);
        }

        $baseAmount = $parameters['base_amount'] ?? 0;
        $baseTax = $parameters['base_tax'] ?? 0;
        $scaledTax = ($baseTax * $denominator) + (($amount - $baseAmount) * $numerator);

        return match ($rule->calculation_type) {
            'marginal_floor' => intdiv($scaledTax, $denominator),
            'marginal_round_10' => intdiv($scaledTax + (5 * $denominator), 10 * $denominator) * 10,
            default => throw new RuntimeException("未対応の所得税計算型です: {$rule->calculation_type}"),
        };
    }
}

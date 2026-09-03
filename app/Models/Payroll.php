<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $staff_id
 * @property int $year
 * @property int $month
 * @property Carbon $payment_date
 * @property int $tax_year
 * @property int $working_minutes
 * @property int $late_night_minutes
 * @property int $base_pay
 * @property int $late_night_pay
 * @property int $transportation_fee_total
 * @property int $transportation_fee_taxable
 * @property int $transportation_fee_non_taxable
 * @property int $commission
 * @property int $gross_pay
 * @property int $taxable_pay
 * @property int $income_tax
 * @property int $total_deductions
 * @property int $net_pay
 * @property bool $needs_recalculation
 * @property Carbon|null $calculated_at
 */
#[Fillable([
    'staff_id', 'year', 'month', 'payment_date', 'tax_year', 'working_minutes',
    'late_night_minutes', 'base_pay', 'late_night_pay', 'transportation_fee_total',
    'transportation_fee_taxable', 'transportation_fee_non_taxable', 'commission',
    'gross_pay', 'taxable_pay', 'social_insurance_deduction',
    'tax_table_reference_amount', 'income_tax', 'other_deductions',
    'total_deductions', 'net_pay', 'needs_recalculation', 'calculated_at',
])]
class Payroll extends Model
{
    /** @return BelongsTo<Staff, $this> */
    public function staff(): BelongsTo
    {
        return $this->belongsTo(Staff::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'payment_date' => 'date',
            'needs_recalculation' => 'boolean',
            'calculated_at' => 'datetime',
        ];
    }
}

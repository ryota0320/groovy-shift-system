<?php

namespace App\Models;

use App\Enums\IncomeTaxCategory;
use App\Models\Concerns\HasEffectivePeriod;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $staff_id
 * @property IncomeTaxCategory $tax_category
 * @property int $dependent_count
 * @property Carbon $effective_from
 * @property Carbon|null $effective_to
 */
#[Fillable(['staff_id', 'tax_category', 'dependent_count', 'effective_from', 'effective_to'])]
class StaffIncomeTaxSetting extends Model
{
    use HasEffectivePeriod;

    /** @return BelongsTo<Staff, $this> */
    public function staff(): BelongsTo
    {
        return $this->belongsTo(Staff::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'tax_category' => IncomeTaxCategory::class,
            'dependent_count' => 'integer',
            'effective_from' => 'date',
            'effective_to' => 'date',
        ];
    }
}

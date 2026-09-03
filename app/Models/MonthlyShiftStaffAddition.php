<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $store_id
 * @property int $staff_id
 * @property Carbon $month
 * @property int $position
 */
#[Fillable(['store_id', 'staff_id', 'month', 'position'])]
class MonthlyShiftStaffAddition extends Model
{
    /** @return BelongsTo<Store, $this> */
    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    /** @return BelongsTo<Staff, $this> */
    public function staff(): BelongsTo
    {
        return $this->belongsTo(Staff::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['month' => 'date'];
    }
}

<?php

namespace App\Models;

use App\Enums\ShiftType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $staff_id
 * @property int|null $store_id
 * @property Carbon $shift_date
 * @property ShiftType $shift_type
 * @property string|null $start_time
 * @property-read Staff $staff
 * @property-read Store|null $store
 */
#[Fillable(['staff_id', 'store_id', 'shift_date', 'shift_type', 'start_time'])]
class Shift extends Model
{
    /** @return BelongsTo<Staff, $this> */
    public function staff(): BelongsTo
    {
        return $this->belongsTo(Staff::class);
    }

    /** @return BelongsTo<Store, $this> */
    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'shift_date' => 'date',
            'shift_type' => ShiftType::class,
        ];
    }
}

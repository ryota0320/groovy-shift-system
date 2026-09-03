<?php

namespace App\Models;

use App\Models\Concerns\HasEffectivePeriod;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $staff_id
 * @property int $store_id
 * @property Carbon $effective_from
 * @property Carbon|null $effective_to
 * @property-read Store $store
 */
#[Fillable(['staff_id', 'store_id', 'effective_from', 'effective_to'])]
class StaffStoreAssignment extends Model
{
    use HasEffectivePeriod;

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
        return ['effective_from' => 'date', 'effective_to' => 'date'];
    }
}

<?php

namespace App\Models;

use App\Enums\TransportationTaxType;
use App\Models\Concerns\HasEffectivePeriod;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $staff_id
 * @property int $store_id
 * @property int $amount_per_day
 * @property TransportationTaxType $tax_type
 * @property Carbon $effective_from
 * @property Carbon|null $effective_to
 * @property-read Store $store
 */
#[Fillable(['staff_id', 'store_id', 'amount_per_day', 'tax_type', 'effective_from', 'effective_to'])]
class StaffStoreTransportationFee extends Model
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
        return [
            'amount_per_day' => 'integer',
            'tax_type' => TransportationTaxType::class,
            'effective_from' => 'date',
            'effective_to' => 'date',
        ];
    }
}

<?php

namespace App\Models;

use App\Models\Concerns\HasEffectivePeriod;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $amount_per_hour
 * @property Carbon $effective_from
 * @property Carbon|null $effective_to
 */
#[Fillable(['amount_per_hour', 'effective_from', 'effective_to'])]
class LateNightRateSetting extends Model
{
    use HasEffectivePeriod;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'amount_per_hour' => 'integer',
            'effective_from' => 'date',
            'effective_to' => 'date',
        ];
    }
}

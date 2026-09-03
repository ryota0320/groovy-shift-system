<?php

namespace App\Models\Concerns;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

trait HasEffectivePeriod
{
    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeEffectiveOn(Builder $query, DateTimeInterface|string $date): Builder
    {
        $targetDate = Carbon::parse($date)->toDateString();

        return $query
            ->whereDate('effective_from', '<=', $targetDate)
            ->where(function (Builder $query) use ($targetDate): void {
                $query
                    ->whereNull('effective_to')
                    ->orWhereDate('effective_to', '>=', $targetDate);
            });
    }

    public function isEffectiveOn(DateTimeInterface|string $date): bool
    {
        $targetDate = Carbon::parse($date)->startOfDay();

        return $this->effective_from->startOfDay()->lessThanOrEqualTo($targetDate)
            && ($this->effective_to === null
                || $this->effective_to->startOfDay()->greaterThanOrEqualTo($targetDate));
    }
}

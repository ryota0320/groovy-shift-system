<?php

namespace App\Services;

use App\Exceptions\MissingEffectiveSettingException;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

class EffectivePeriodService
{
    /**
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     */
    public function ensureNoOverlap(
        Builder $query,
        DateTimeInterface|string $effectiveFrom,
        DateTimeInterface|string|null $effectiveTo,
        ?int $ignoreId = null,
        string $field = 'effective_from',
    ): void {
        $from = Carbon::parse($effectiveFrom)->toDateString();
        $to = $effectiveTo === null
            ? '9999-12-31'
            : Carbon::parse($effectiveTo)->toDateString();

        $overlaps = $query
            ->when($ignoreId !== null, fn (Builder $query) => $query->whereKeyNot($ignoreId))
            ->whereDate('effective_from', '<=', $to)
            ->where(function (Builder $query) use ($from): void {
                $query
                    ->whereNull('effective_to')
                    ->orWhereDate('effective_to', '>=', $from);
            })
            ->lockForUpdate()
            ->exists();

        if ($overlaps) {
            throw ValidationException::withMessages([
                $field => '同じ対象の適用期間が既存の設定と重複しています。',
            ]);
        }
    }

    /**
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @return TModel
     */
    public function resolve(
        Builder $query,
        DateTimeInterface|string $date,
        string $settingName,
    ): Model {
        $targetDate = Carbon::parse($date)->toDateString();
        $setting = $query
            ->whereDate('effective_from', '<=', $targetDate)
            ->where(function (Builder $query) use ($targetDate): void {
                $query
                    ->whereNull('effective_to')
                    ->orWhereDate('effective_to', '>=', $targetDate);
            })
            ->orderByDesc('effective_from')
            ->first();

        if ($setting === null) {
            throw MissingEffectiveSettingException::forDate($settingName, $targetDate);
        }

        return $setting;
    }
}

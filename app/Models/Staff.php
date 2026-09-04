<?php

namespace App\Models;

use App\Enums\EmploymentType;
use Database\Factories\StaffFactory;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $name
 * @property string|null $last_name
 * @property string|null $first_name
 * @property string|null $display_name
 * @property-read string $full_name
 * @property-read string $preferred_name
 * @property EmploymentType $employment_type
 * @property Carbon|null $hired_at
 * @property Carbon|null $retired_at
 */
#[Fillable(['name', 'last_name', 'first_name', 'display_name', 'employment_type', 'hired_at', 'retired_at'])]
class Staff extends Model
{
    /** @use HasFactory<StaffFactory> */
    use HasFactory;

    protected $table = 'staffs';

    protected static function booted(): void
    {
        static::saving(function (Staff $staff): void {
            // Keep the former column as a derived compatibility value while all
            // new code uses the split fields as the source of truth.
            $staff->attributes['name'] = $staff->full_name;
        });
    }

    /** @return Attribute<string, mixed> */
    protected function name(): Attribute
    {
        return Attribute::make(
            get: fn (): string => $this->full_name,
            set: function (mixed $value): array {
                $parts = preg_split('/[\s　]+/u', trim((string) $value), 2) ?: [];

                return [
                    'last_name' => $parts[0] ?? '',
                    'first_name' => $parts[1] ?? '',
                ];
            },
        );
    }

    /** @return Attribute<string, never> */
    protected function fullName(): Attribute
    {
        return Attribute::make(
            get: fn (): string => trim(implode(' ', array_filter([
                trim((string) $this->last_name),
                trim((string) $this->first_name),
            ], fn (string $part): bool => $part !== ''))),
        );
    }

    /** @return Attribute<string, never> */
    protected function preferredName(): Attribute
    {
        return Attribute::make(
            get: fn (): string => trim((string) $this->display_name) !== ''
                ? trim((string) $this->display_name)
                : $this->full_name,
        );
    }

    /** @return HasOne<User, $this> */
    public function user(): HasOne
    {
        return $this->hasOne(User::class);
    }

    /** @return HasMany<StaffStoreAssignment, $this> */
    public function storeAssignments(): HasMany
    {
        return $this->hasMany(StaffStoreAssignment::class);
    }

    /** @return HasMany<StaffStoreDisplayOrder, $this> */
    public function storeDisplayOrders(): HasMany
    {
        return $this->hasMany(StaffStoreDisplayOrder::class);
    }

    /** @return HasMany<MonthlyShiftStaffAddition, $this> */
    public function monthlyShiftAdditions(): HasMany
    {
        return $this->hasMany(MonthlyShiftStaffAddition::class);
    }

    /** @return HasMany<StaffWageRate, $this> */
    public function wageRates(): HasMany
    {
        return $this->hasMany(StaffWageRate::class);
    }

    /** @return HasMany<StaffStoreTransportationFee, $this> */
    public function transportationFees(): HasMany
    {
        return $this->hasMany(StaffStoreTransportationFee::class);
    }

    /** @return HasMany<StaffIncomeTaxSetting, $this> */
    public function incomeTaxSettings(): HasMany
    {
        return $this->hasMany(StaffIncomeTaxSetting::class);
    }

    /** @return HasMany<Commission, $this> */
    public function commissions(): HasMany
    {
        return $this->hasMany(Commission::class);
    }

    /** @return HasMany<Shift, $this> */
    public function shifts(): HasMany
    {
        return $this->hasMany(Shift::class);
    }

    /** @return HasMany<AttendanceRecord, $this> */
    public function attendanceRecords(): HasMany
    {
        return $this->hasMany(AttendanceRecord::class);
    }

    /** @return HasMany<Payroll, $this> */
    public function payrolls(): HasMany
    {
        return $this->hasMany(Payroll::class);
    }

    public function isEmployedOn(DateTimeInterface|string $date): bool
    {
        $targetDate = Carbon::parse($date)->startOfDay();

        return ($this->hired_at === null || $this->hired_at->lessThanOrEqualTo($targetDate))
            && ($this->retired_at === null || $this->retired_at->greaterThanOrEqualTo($targetDate));
    }

    /**
     * Apply the staff-list order shared by master, shift, attendance and payroll screens.
     * A store-specific manual order takes precedence when it has been saved.
     *
     * @param  Builder<Staff>  $query
     * @return Builder<Staff>
     */
    public function scopeInDisplayOrder(Builder $query, ?int $storeId = null): Builder
    {
        if ($storeId !== null) {
            $query
                ->select('staffs.*')
                ->leftJoin('staff_store_display_orders as staff_display_order', function ($join) use ($storeId): void {
                    $join->on('staff_display_order.staff_id', '=', 'staffs.id')
                        ->where('staff_display_order.store_id', $storeId);
                })
                ->orderByRaw('CASE WHEN staff_display_order.position IS NULL THEN 1 ELSE 0 END')
                ->orderBy('staff_display_order.position');
        }

        return $query
            ->orderByRaw(
                'CASE staffs.employment_type WHEN ? THEN 0 ELSE 1 END',
                [EmploymentType::Employee->value],
            )
            ->orderBy('staffs.id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'employment_type' => EmploymentType::class,
            'hired_at' => 'date',
            'retired_at' => 'date',
        ];
    }
}

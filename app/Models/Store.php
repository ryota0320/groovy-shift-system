<?php

namespace App\Models;

use Database\Factories\StoreFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $name
 * @property bool $is_active
 * @property int $holidays_count
 */
#[Fillable(['name', 'is_active'])]
class Store extends Model
{
    /** @use HasFactory<StoreFactory> */
    use HasFactory;

    /** @return HasMany<StoreHoliday, $this> */
    public function holidays(): HasMany
    {
        return $this->hasMany(StoreHoliday::class);
    }

    /** @return HasMany<StaffStoreAssignment, $this> */
    public function assignments(): HasMany
    {
        return $this->hasMany(StaffStoreAssignment::class);
    }

    /** @return HasMany<StaffStoreTransportationFee, $this> */
    public function transportationFees(): HasMany
    {
        return $this->hasMany(StaffStoreTransportationFee::class);
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

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }
}

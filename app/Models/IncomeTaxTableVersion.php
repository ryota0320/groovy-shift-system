<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $tax_year
 * @property string $name
 * @property string $source_url
 * @property string $source_hash
 * @property Carbon $imported_at
 * @property int|null $rules_count
 */
#[Fillable(['tax_year', 'name', 'source_url', 'source_hash', 'imported_at'])]
class IncomeTaxTableVersion extends Model
{
    /** @return HasMany<IncomeTaxRule, $this> */
    public function rules(): HasMany
    {
        return $this->hasMany(IncomeTaxRule::class, 'table_version_id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['imported_at' => 'datetime'];
    }
}

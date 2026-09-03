<?php

namespace App\Models;

use App\Enums\IncomeTaxCategory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'table_version_id', 'tax_category', 'dependent_count', 'min_amount',
    'max_amount', 'calculation_type', 'fixed_tax_amount', 'parameters', 'sort_order',
])]
class IncomeTaxRule extends Model
{
    /** @return BelongsTo<IncomeTaxTableVersion, $this> */
    public function tableVersion(): BelongsTo
    {
        return $this->belongsTo(IncomeTaxTableVersion::class, 'table_version_id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'tax_category' => IncomeTaxCategory::class,
            'dependent_count' => 'integer',
            'min_amount' => 'integer',
            'max_amount' => 'integer',
            'fixed_tax_amount' => 'integer',
            'parameters' => 'array',
        ];
    }
}

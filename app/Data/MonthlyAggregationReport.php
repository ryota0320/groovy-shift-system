<?php

namespace App\Data;

/** The shared aggregation result used by the screen and XLSX output. */
final readonly class MonthlyAggregationReport
{
    /**
     * @param  list<array{id: int, name: string}>  $stores
     * @param  list<array<string, mixed>>  $storeRows
     * @param  array<string, int>  $storeTotals
     * @param  list<array{date: string, rows: list<array<string, mixed>>, totals: array<string, int>}>  $dailyGroups
     * @param  list<array<string, mixed>>  $crossStoreRows
     */
    public function __construct(
        public int $year,
        public int $month,
        public ?int $storeId,
        public array $stores,
        public array $storeRows,
        public array $storeTotals,
        public array $dailyGroups,
        public array $crossStoreRows,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'year' => $this->year,
            'month' => $this->month,
            'store_rows' => $this->storeRows,
            'store_totals' => $this->storeTotals,
            'daily_groups' => $this->dailyGroups,
            'cross_store_rows' => $this->crossStoreRows,
        ];
    }
}

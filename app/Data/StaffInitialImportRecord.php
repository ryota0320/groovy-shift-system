<?php

namespace App\Data;

class StaffInitialImportRecord
{
    /** @var list<array<string, mixed>> */
    public array $assignments = [];

    /** @var list<array<string, mixed>> */
    public array $wageRates = [];

    /** @var list<array<string, mixed>> */
    public array $transportationFees = [];

    /** @var list<array<string, mixed>> */
    public array $incomeTaxSettings = [];

    /** @param array{name: string, employment_type: string, hired_at: string|null, retired_at: string|null} $staff */
    public function __construct(public array $staff) {}
}

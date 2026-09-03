<?php

namespace App\Enums;

enum TransportationTaxType: string
{
    case Taxable = 'taxable';
    case NonTaxable = 'non_taxable';

    public function label(): string
    {
        return match ($this) {
            self::Taxable => '課税',
            self::NonTaxable => '非課税',
        };
    }
}

<?php

namespace App\Enums;

enum IncomeTaxCategory: string
{
    case Ko = 'ko';
    case Otsu = 'otsu';

    public function label(): string
    {
        return match ($this) {
            self::Ko => '甲欄',
            self::Otsu => '乙欄',
        };
    }
}

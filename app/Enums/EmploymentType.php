<?php

namespace App\Enums;

enum EmploymentType: string
{
    case Employee = 'employee';
    case PartTime = 'part_time';

    public function label(): string
    {
        return match ($this) {
            self::Employee => '社員',
            self::PartTime => 'アルバイト',
        };
    }
}

<?php

namespace App\Enums;

enum ShiftType: string
{
    case Time = 'time';
    case Early = 'early';
    case Off = 'off';
    case Absence = 'absence';

    public function label(): string
    {
        return match ($this) {
            self::Time => '時刻指定',
            self::Early => '早番',
            self::Off => '休み',
            self::Absence => '急な休み',
        };
    }
}

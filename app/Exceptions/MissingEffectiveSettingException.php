<?php

namespace App\Exceptions;

use RuntimeException;

class MissingEffectiveSettingException extends RuntimeException
{
    public static function forDate(string $settingName, string $date): self
    {
        return new self("{$date}に有効な{$settingName}がありません。");
    }
}

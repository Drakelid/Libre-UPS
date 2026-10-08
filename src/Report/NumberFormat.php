<?php

declare(strict_types=1);

namespace Drakelid\UpsBattery\Report;

final class NumberFormat
{
    /** Plain decimal with a point, no thousands separator and no trailing zeros: 12.5, 3, -0.25. */
    public static function plain(float $value): string
    {
        $text = rtrim(rtrim(number_format($value, 6, '.', ''), '0'), '.');

        return $text === '' || $text === '-' || $text === '-0' ? '0' : $text;
    }

    /** Same as plain() with a decimal comma: 12,5. */
    public static function decimalComma(float $value): string
    {
        return str_replace('.', ',', self::plain($value));
    }
}

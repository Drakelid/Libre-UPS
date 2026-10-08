<?php

declare(strict_types=1);

namespace Drakelid\UpsBattery\Report;

/**
 * Formats a runtime given in minutes. LibreNMS' own formatter returns an empty string for 0 and is
 * fed fractional minutes, so a UPS with no runtime left would show a blank cell. This one never does.
 */
final class RuntimeFormatter
{
    /** "0 min", "45 min", "1 h 5 min", "3 h", "2 d 3 h". Empty string only when there is no value. */
    public static function format(?float $minutes): string
    {
        if ($minutes === null || is_nan($minutes) || is_infinite($minutes)) {
            return '';
        }

        $total = (int) round($minutes);
        if ($total < 60) {
            return $total.' min';
        }

        $days = intdiv($total, 1440);
        $hours = intdiv($total % 1440, 60);
        $rest = $total % 60;

        if ($days > 0) {
            return $days.' d'.($hours > 0 ? ' '.$hours.' h' : '');
        }

        return $hours.' h'.($rest > 0 ? ' '.$rest.' min' : '');
    }
}

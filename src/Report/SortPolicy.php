<?php

declare(strict_types=1);

namespace Drakelid\UpsBattery\Report;

final class SortPolicy
{
    /** Sensor classes where the highest value is the most interesting. */
    private const DESCENDING = ['load', 'current', 'power', 'temperature', 'state'];

    public static function defaultDirection(string $class): string
    {
        return in_array($class, self::DESCENDING, true) ? 'desc' : 'asc';
    }
}

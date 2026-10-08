<?php

declare(strict_types=1);

namespace Drakelid\UpsBattery\Report;

/** Reduces several sensor rows per device to a single row. */
final class Aggregator
{
    /**
     * @param  ReportRow[]  $rows
     * @param  string  $mode  none|min|max
     * @return ReportRow[]
     */
    public static function reduce(array $rows, string $mode): array
    {
        if ($mode === 'none') {
            return array_values($rows);
        }

        $best = [];
        foreach ($rows as $row) {
            $current = $best[$row->deviceId] ?? null;
            if ($current === null || self::isBetter($row, $current, $mode)) {
                $best[$row->deviceId] = $row;
            }
        }

        return array_values($best);
    }

    /** True if $candidate should replace $current. Null values lose; ties go to the lowest sensor id. */
    public static function isBetter(ReportRow $candidate, ReportRow $current, string $mode): bool
    {
        if ($candidate->value === null && $current->value === null) {
            return $candidate->sensorId < $current->sensorId;
        }

        if ($candidate->value === null) {
            return false;
        }

        if ($current->value === null) {
            return true;
        }

        if ($candidate->value === $current->value) {
            return $candidate->sensorId < $current->sensorId;
        }

        return $mode === 'min'
            ? $candidate->value < $current->value
            : $candidate->value > $current->value;
    }
}

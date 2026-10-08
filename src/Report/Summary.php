<?php

declare(strict_types=1);

namespace Drakelid\UpsBattery\Report;

final class Summary
{
    /**
     * @param  ReportRow[]  $rows
     * @return array{count: int, min: ?float, median: ?float, max: ?float}
     */
    public static function from(array $rows, bool $isState): array
    {
        $count = count($rows);

        if ($isState) {
            return ['count' => $count, 'min' => null, 'median' => null, 'max' => null];
        }

        $values = [];
        foreach ($rows as $row) {
            if ($row->value !== null) {
                $values[] = $row->value;
            }
        }

        if ($values === []) {
            return ['count' => $count, 'min' => null, 'median' => null, 'max' => null];
        }

        sort($values);
        $n = count($values);
        $middle = intdiv($n, 2);
        $median = $n % 2 === 1
            ? $values[$middle]
            : ($values[$middle - 1] + $values[$middle]) / 2;

        return [
            'count' => $count,
            'min' => $values[0],
            'median' => (float) $median,
            'max' => $values[$n - 1],
        ];
    }
}

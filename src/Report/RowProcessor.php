<?php

declare(strict_types=1);

namespace Drakelid\UpsBattery\Report;

final class RowProcessor
{
    /**
     * Aggregates, sorts and limits rows.
     *
     * @param  ReportRow[]  $rows
     * @return array{rows: ReportRow[], total: int, summaryRows: ReportRow[]}
     */
    public function process(array $rows, ReportFilters $filters, bool $isState): array
    {
        $rows = Aggregator::reduce($rows, $filters->aggregate);
        $rows = $this->sort($rows, $filters, $isState);

        $total = count($rows);
        $limited = $filters->limit > 0 ? array_slice($rows, 0, $filters->limit) : $rows;

        return [
            'rows' => $limited,
            'total' => $total,
            'summaryRows' => $rows,
        ];
    }

    /**
     * @param  ReportRow[]  $rows
     * @return ReportRow[]
     */
    private function sort(array $rows, ReportFilters $filters, bool $isState): array
    {
        $ascending = $filters->dir === 'asc';

        $compare = function (ReportRow $a, ReportRow $b) use ($filters, $isState, $ascending): int {
            $primary = match ($filters->sort) {
                'value' => $this->compareValue($a, $b, $ascending, $isState),
                'hostname' => self::compareText($a->hostname, $b->hostname, $ascending),
                'location' => self::compareText($a->location, $b->location, $ascending),
                'descr' => self::compareText($a->sensorDescr, $b->sensorDescr, $ascending),
                'lastupdate' => $this->compareTime($a->lastUpdate, $b->lastUpdate, $ascending),
                default => 0,
            };

            if ($primary !== 0) {
                return $primary;
            }

            $byHost = strnatcasecmp($a->hostname, $b->hostname) <=> 0;
            if ($byHost !== 0) {
                return $byHost;
            }

            return $a->sensorId <=> $b->sensorId;
        };

        $rows = array_values($rows);
        usort($rows, $compare); // usort is stable since PHP 8.0

        return $rows;
    }

    /** Null values always sort last, regardless of direction. */
    private function compareValue(ReportRow $a, ReportRow $b, bool $ascending, bool $isState): int
    {
        if ($a->value === null || $b->value === null) {
            return ($a->value === null ? 1 : 0) <=> ($b->value === null ? 1 : 0);
        }

        $result = $isState ? $a->severity->rank() <=> $b->severity->rank() : 0;
        if ($result === 0) {
            $result = $a->value <=> $b->value;
        }

        return $ascending ? $result : -$result;
    }

    /** Natural, case-insensitive comparison. Null values always sort last, regardless of direction. */
    public static function compareText(?string $a, ?string $b, bool $ascending): int
    {
        if ($a === null || $b === null) {
            return ($a === null ? 1 : 0) <=> ($b === null ? 1 : 0);
        }

        $result = strnatcasecmp($a, $b) <=> 0;

        return $ascending ? $result : -$result;
    }

    /** Null or unparsable timestamps always sort last, regardless of direction. */
    private function compareTime(?string $a, ?string $b, bool $ascending): int
    {
        $timeA = $a === null ? false : strtotime($a);
        $timeB = $b === null ? false : strtotime($b);

        if ($timeA === false || $timeB === false) {
            return ($timeA === false ? 1 : 0) <=> ($timeB === false ? 1 : 0);
        }

        $result = $timeA <=> $timeB;

        return $ascending ? $result : -$result;
    }
}

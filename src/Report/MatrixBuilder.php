<?php

declare(strict_types=1);

namespace Drakelid\UpsBattery\Report;

/** Turns rows per sensor class into one row per device ("compare metrics" view). */
final class MatrixBuilder
{
    /**
     * For every class the worst sensor per device is used: lowest value for classes where low is bad
     * (runtime, charge), highest value for the others (load, temperature, ...), see SortPolicy.
     *
     * @param  array<string, ReportRow[]>  $rowsByClass
     * @return array{rows: MatrixRow[], total: int}
     */
    public function build(array $rowsByClass, MatrixFilters $filters): array
    {
        $devices = [];
        foreach ($filters->classes as $class) {
            $mode = SortPolicy::defaultDirection($class) === 'asc' ? 'min' : 'max';

            foreach (Aggregator::reduce($rowsByClass[$class] ?? [], $mode) as $row) {
                if (! isset($devices[$row->deviceId])) {
                    $devices[$row->deviceId] = ['info' => $row, 'cells' => []];
                }

                $devices[$row->deviceId]['cells'][$class] = $row;
            }
        }

        $rows = [];
        foreach ($devices as $deviceId => $entry) {
            $info = $entry['info'];
            $rows[] = new MatrixRow(
                (int) $deviceId,
                $info->hostname,
                $info->displayName,
                $info->deviceUrl,
                $info->location,
                $info->os,
                $info->deviceUp,
                $entry['cells'],
            );
        }

        $rows = $this->sort($rows, $filters);
        $total = count($rows);

        return [
            'rows' => $filters->limit > 0 ? array_slice($rows, 0, $filters->limit) : $rows,
            'total' => $total,
        ];
    }

    /**
     * @param  MatrixRow[]  $rows
     * @return MatrixRow[]
     */
    private function sort(array $rows, MatrixFilters $filters): array
    {
        $ascending = $filters->dir === 'asc';

        usort($rows, function (MatrixRow $a, MatrixRow $b) use ($filters, $ascending): int {
            $primary = match ($filters->sort) {
                'hostname' => RowProcessor::compareText($a->hostname, $b->hostname, $ascending),
                'location' => RowProcessor::compareText($a->location, $b->location, $ascending),
                default => $this->compareCell($a->cells[$filters->sort] ?? null, $b->cells[$filters->sort] ?? null, $ascending),
            };

            if ($primary !== 0) {
                return $primary;
            }

            $byHost = strnatcasecmp($a->hostname, $b->hostname) <=> 0;

            return $byHost !== 0 ? $byHost : $a->deviceId <=> $b->deviceId;
        });

        return $rows;
    }

    /** Missing cells and null values always sort last, regardless of direction. */
    private function compareCell(?ReportRow $a, ?ReportRow $b, bool $ascending): int
    {
        $valueA = $a?->value;
        $valueB = $b?->value;

        if ($valueA === null || $valueB === null) {
            return ($valueA === null ? 1 : 0) <=> ($valueB === null ? 1 : 0);
        }

        $result = $valueA <=> $valueB;

        return $ascending ? $result : -$result;
    }
}

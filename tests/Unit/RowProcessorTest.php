<?php

declare(strict_types=1);

use Drakelid\UpsBattery\Report\RowProcessor;
use Drakelid\UpsBattery\Report\Severity;

/** @param  array<int, mixed>  $rows */
function hostnames(array $rows): array
{
    return array_map(fn ($row): string => $row->hostname, $rows);
}

it('sorts the value column ascending', function (): void {
    $rows = [
        makeRow(['sensorId' => 1, 'hostname' => 'a', 'deviceId' => 1, 'value' => 30.0]),
        makeRow(['sensorId' => 2, 'hostname' => 'b', 'deviceId' => 2, 'value' => 10.0]),
        makeRow(['sensorId' => 3, 'hostname' => 'c', 'deviceId' => 3, 'value' => 20.0]),
    ];

    $result = (new RowProcessor)->process($rows, makeFilters(['limit' => '0']), false);

    expect(hostnames($result['rows']))->toBe(['b', 'c', 'a']);
});

it('sorts the value column descending', function (): void {
    $rows = [
        makeRow(['sensorId' => 1, 'hostname' => 'a', 'deviceId' => 1, 'value' => 30.0]),
        makeRow(['sensorId' => 2, 'hostname' => 'b', 'deviceId' => 2, 'value' => 10.0]),
        makeRow(['sensorId' => 3, 'hostname' => 'c', 'deviceId' => 3, 'value' => 20.0]),
    ];

    $result = (new RowProcessor)->process($rows, makeFilters(['dir' => 'desc', 'limit' => '0']), false);

    expect(hostnames($result['rows']))->toBe(['a', 'c', 'b']);
});

it('puts null values last in both directions', function (string $dir): void {
    $rows = [
        makeRow(['sensorId' => 1, 'hostname' => 'nul', 'deviceId' => 1, 'value' => null]),
        makeRow(['sensorId' => 2, 'hostname' => 'low', 'deviceId' => 2, 'value' => 1.0]),
        makeRow(['sensorId' => 3, 'hostname' => 'high', 'deviceId' => 3, 'value' => 9.0]),
    ];

    $result = (new RowProcessor)->process($rows, makeFilters(['dir' => $dir, 'limit' => '0']), false);

    expect(array_last_host($result['rows']))->toBe('nul');
})->with(['asc', 'desc']);

function array_last_host(array $rows): string
{
    return $rows[count($rows) - 1]->hostname;
}

it('breaks ties by hostname and then sensor id', function (): void {
    $rows = [
        makeRow(['sensorId' => 5, 'hostname' => 'b', 'deviceId' => 2, 'value' => 10.0]),
        makeRow(['sensorId' => 9, 'hostname' => 'a', 'deviceId' => 1, 'value' => 10.0]),
        makeRow(['sensorId' => 2, 'hostname' => 'a', 'deviceId' => 1, 'value' => 10.0]),
    ];

    $result = (new RowProcessor)->process($rows, makeFilters(['limit' => '0']), false);

    expect(array_map(fn ($r): int => $r->sensorId, $result['rows']))->toBe([2, 9, 5]);
});

it('sorts hostnames naturally and case-insensitively', function (): void {
    $rows = [
        makeRow(['sensorId' => 1, 'hostname' => 'ups-10', 'deviceId' => 1]),
        makeRow(['sensorId' => 2, 'hostname' => 'UPS-2', 'deviceId' => 2]),
        makeRow(['sensorId' => 3, 'hostname' => 'ups-1', 'deviceId' => 3]),
    ];

    $result = (new RowProcessor)->process($rows, makeFilters(['sort' => 'hostname', 'limit' => '0']), false);

    expect(hostnames($result['rows']))->toBe(['ups-1', 'UPS-2', 'ups-10']);
});

it('puts a missing location last in both directions', function (string $dir): void {
    $rows = [
        makeRow(['sensorId' => 1, 'hostname' => 'none', 'deviceId' => 1, 'location' => null]),
        makeRow(['sensorId' => 2, 'hostname' => 'a', 'deviceId' => 2, 'location' => 'Alpha']),
        makeRow(['sensorId' => 3, 'hostname' => 'b', 'deviceId' => 3, 'location' => 'Beta']),
    ];

    $result = (new RowProcessor)->process($rows, makeFilters(['sort' => 'location', 'dir' => $dir, 'limit' => '0']), false);

    expect(array_last_host($result['rows']))->toBe('none');
})->with(['asc', 'desc']);

it('sorts by last update time', function (): void {
    $rows = [
        makeRow(['sensorId' => 1, 'hostname' => 'new', 'deviceId' => 1, 'lastUpdate' => '2026-10-07T10:00:00+02:00']),
        makeRow(['sensorId' => 2, 'hostname' => 'old', 'deviceId' => 2, 'lastUpdate' => '2026-10-07T08:00:00+02:00']),
        makeRow(['sensorId' => 3, 'hostname' => 'never', 'deviceId' => 3, 'lastUpdate' => null]),
    ];

    $result = (new RowProcessor)->process($rows, makeFilters(['sort' => 'lastupdate', 'limit' => '0']), false);

    expect(hostnames($result['rows']))->toBe(['old', 'new', 'never']);
});

it('sorts state sensors by severity first', function (): void {
    $rows = [
        makeRow(['sensorId' => 1, 'hostname' => 'ok', 'deviceId' => 1, 'value' => 1.0, 'severity' => Severity::Ok]),
        makeRow(['sensorId' => 2, 'hostname' => 'crit', 'deviceId' => 2, 'value' => 0.0, 'severity' => Severity::Critical]),
        makeRow(['sensorId' => 3, 'hostname' => 'warn', 'deviceId' => 3, 'value' => 2.0, 'severity' => Severity::Warning]),
    ];

    $result = (new RowProcessor)->process($rows, makeFilters(['class' => 'state', 'limit' => '0']), true);

    expect(hostnames($result['rows']))->toBe(['crit', 'warn', 'ok']);
});

it('aggregates to the lowest value per device', function (): void {
    $rows = [
        makeRow(['sensorId' => 1, 'hostname' => 'a', 'deviceId' => 1, 'value' => 30.0]),
        makeRow(['sensorId' => 2, 'hostname' => 'a', 'deviceId' => 1, 'value' => 10.0]),
        makeRow(['sensorId' => 3, 'hostname' => 'b', 'deviceId' => 2, 'value' => 20.0]),
    ];

    $result = (new RowProcessor)->process($rows, makeFilters(['aggregate' => 'min', 'limit' => '0']), false);

    expect($result['total'])->toBe(2)
        ->and(array_map(fn ($r): int => $r->sensorId, $result['rows']))->toBe([2, 3]);
});

it('aggregates to the highest value per device', function (): void {
    $rows = [
        makeRow(['sensorId' => 1, 'hostname' => 'a', 'deviceId' => 1, 'value' => 30.0]),
        makeRow(['sensorId' => 2, 'hostname' => 'a', 'deviceId' => 1, 'value' => 10.0]),
        makeRow(['sensorId' => 3, 'hostname' => 'b', 'deviceId' => 2, 'value' => 20.0]),
    ];

    $result = (new RowProcessor)->process($rows, makeFilters(['aggregate' => 'max', 'limit' => '0']), false);

    expect(array_map(fn ($r): int => $r->sensorId, $result['rows']))->toBe([3, 1]);
});

it('prefers a real value over null when aggregating', function (): void {
    $rows = [
        makeRow(['sensorId' => 1, 'hostname' => 'a', 'deviceId' => 1, 'value' => null]),
        makeRow(['sensorId' => 2, 'hostname' => 'a', 'deviceId' => 1, 'value' => 10.0]),
    ];

    $result = (new RowProcessor)->process($rows, makeFilters(['aggregate' => 'max', 'limit' => '0']), false);

    expect($result['rows'])->toHaveCount(1)
        ->and($result['rows'][0]->sensorId)->toBe(2);
});

it('picks the lowest sensor id when aggregated values tie', function (): void {
    $rows = [
        makeRow(['sensorId' => 7, 'hostname' => 'a', 'deviceId' => 1, 'value' => 10.0]),
        makeRow(['sensorId' => 3, 'hostname' => 'a', 'deviceId' => 1, 'value' => 10.0]),
    ];

    $result = (new RowProcessor)->process($rows, makeFilters(['aggregate' => 'min', 'limit' => '0']), false);

    expect($result['rows'][0]->sensorId)->toBe(3);
});

it('limits to top N but keeps the full total and summary rows', function (): void {
    $rows = [];
    for ($i = 1; $i <= 30; $i++) {
        $rows[] = makeRow(['sensorId' => $i, 'deviceId' => $i, 'hostname' => "ups-$i", 'value' => (float) $i]);
    }

    $result = (new RowProcessor)->process($rows, makeFilters(['limit' => '10']), false);

    expect($result['rows'])->toHaveCount(10)
        ->and($result['total'])->toBe(30)
        ->and($result['summaryRows'])->toHaveCount(30)
        ->and($result['rows'][0]->value)->toBe(1.0);
});

it('returns every row when the limit is zero', function (): void {
    $rows = [];
    for ($i = 1; $i <= 30; $i++) {
        $rows[] = makeRow(['sensorId' => $i, 'deviceId' => $i, 'hostname' => "ups-$i", 'value' => (float) $i]);
    }

    $result = (new RowProcessor)->process($rows, makeFilters(['limit' => '0']), false);

    expect($result['rows'])->toHaveCount(30);
});

it('handles an empty list', function (): void {
    $result = (new RowProcessor)->process([], makeFilters(), false);

    expect($result)->toBe(['rows' => [], 'total' => 0, 'summaryRows' => []]);
});

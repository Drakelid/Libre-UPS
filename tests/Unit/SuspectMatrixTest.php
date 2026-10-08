<?php

declare(strict_types=1);

use Drakelid\UpsBattery\Report\MatrixBuilder;
use Drakelid\UpsBattery\Report\MatrixRow;
use Drakelid\UpsBattery\Report\ReportRow;
use Drakelid\UpsBattery\Report\SuspectRule;

/**
 * Four devices: a short runtime at low load (suspect), a short runtime at high load (normal),
 * a healthy one, and one without a load sensor (cannot be judged).
 *
 * @return array<string, array<int, ReportRow>>
 */
function suspectFixture(): array
{
    return [
        'runtime' => [
            makeRow(['deviceId' => 1, 'sensorId' => 1, 'hostname' => 'worn', 'value' => 5.0]),
            makeRow(['deviceId' => 2, 'sensorId' => 2, 'hostname' => 'busy', 'value' => 5.0]),
            makeRow(['deviceId' => 3, 'sensorId' => 3, 'hostname' => 'fine', 'value' => 40.0]),
            makeRow(['deviceId' => 4, 'sensorId' => 4, 'hostname' => 'noload', 'value' => 5.0]),
        ],
        'load' => [
            makeRow(['deviceId' => 1, 'sensorId' => 5, 'hostname' => 'worn', 'value' => 10.0]),
            makeRow(['deviceId' => 2, 'sensorId' => 6, 'hostname' => 'busy', 'value' => 90.0]),
            makeRow(['deviceId' => 3, 'sensorId' => 7, 'hostname' => 'fine', 'value' => 10.0]),
        ],
        'charge' => [
            makeRow(['deviceId' => 1, 'sensorId' => 8, 'hostname' => 'worn', 'value' => 100.0]),
            makeRow(['deviceId' => 2, 'sensorId' => 9, 'hostname' => 'busy', 'value' => 100.0]),
            makeRow(['deviceId' => 3, 'sensorId' => 10, 'hostname' => 'fine', 'value' => 100.0]),
        ],
    ];
}

/**
 * @param  MatrixRow[]  $rows
 * @return string[]
 */
function suspectHostnames(array $rows): array
{
    return array_map(fn (MatrixRow $row): string => $row->hostname, $rows);
}

it('judges every device when runtime and load are compared', function (): void {
    $result = (new MatrixBuilder)->build(suspectFixture(), makeMatrixFilters(['classes' => 'runtime,load,charge', 'sort' => 'hostname', 'limit' => '0']), new SuspectRule);

    $verdicts = [];
    foreach ($result['rows'] as $row) {
        $verdicts[$row->hostname] = $row->suspect;
    }

    expect($verdicts)->toBe(['busy' => false, 'fine' => false, 'noload' => null, 'worn' => true]);
});

it('only lists suspect devices when asked to, and counts only those', function (): void {
    $filters = makeMatrixFilters(['classes' => 'runtime,load,charge', 'suspect' => '1', 'limit' => '0']);

    $result = (new MatrixBuilder)->build(suspectFixture(), $filters, new SuspectRule);

    expect(suspectHostnames($result['rows']))->toBe(['worn'])
        ->and($result['total'])->toBe(1);
});

it('does not judge anything without a rule or without the runtime and load metrics', function (): void {
    $withoutRule = (new MatrixBuilder)->build(suspectFixture(), makeMatrixFilters(['classes' => 'runtime,load', 'limit' => '0']));
    $withoutLoad = (new MatrixBuilder)->build(suspectFixture(), makeMatrixFilters(['classes' => 'runtime,charge', 'limit' => '0']), new SuspectRule);

    foreach ([...$withoutRule['rows'], ...$withoutLoad['rows']] as $row) {
        expect($row->suspect)->toBeNull();
    }
});

it('uses the configured rule for the verdict', function (): void {
    $generous = new SuspectRule(maxRuntime: 60.0, maxLoad: 95.0, minCharge: 50.0);

    $result = (new MatrixBuilder)->build(suspectFixture(), makeMatrixFilters(['classes' => 'runtime,load,charge', 'suspect' => '1', 'sort' => 'hostname', 'limit' => '0']), $generous);

    expect(suspectHostnames($result['rows']))->toBe(['busy', 'fine', 'worn']);
});

it('keeps the verdict in the json for each device', function (): void {
    $result = (new MatrixBuilder)->build(suspectFixture(), makeMatrixFilters(['classes' => 'runtime,load,charge', 'suspect' => '1', 'limit' => '0']), new SuspectRule);

    expect($result['rows'][0]->toArray(['runtime', 'load', 'charge'])['suspect'])->toBeTrue();
});

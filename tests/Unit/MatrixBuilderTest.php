<?php

declare(strict_types=1);

use Drakelid\UpsBattery\Report\MatrixBuilder;
use Drakelid\UpsBattery\Report\MatrixRow;

/**
 * Two devices with two sensors per class, plus a third device that only has a runtime sensor.
 *
 * @return array<string, array<int, \Drakelid\UpsBattery\Report\ReportRow>>
 */
function matrixFixture(): array
{
    return [
        'runtime' => [
            makeRow(['deviceId' => 1, 'sensorId' => 1, 'hostname' => 'ups-a', 'value' => 10.0]),
            makeRow(['deviceId' => 1, 'sensorId' => 2, 'hostname' => 'ups-a', 'value' => 5.0]),
            makeRow(['deviceId' => 2, 'sensorId' => 3, 'hostname' => 'ups-b', 'value' => 20.0]),
            makeRow(['deviceId' => 3, 'sensorId' => 7, 'hostname' => 'ups-c', 'value' => 15.0]),
        ],
        'load' => [
            makeRow(['deviceId' => 1, 'sensorId' => 4, 'hostname' => 'ups-a', 'value' => 40.0]),
            makeRow(['deviceId' => 1, 'sensorId' => 5, 'hostname' => 'ups-a', 'value' => 60.0]),
            makeRow(['deviceId' => 2, 'sensorId' => 6, 'hostname' => 'ups-b', 'value' => 30.0]),
        ],
    ];
}

/** @param  MatrixRow[]  $rows */
function matrixHostnames(array $rows): array
{
    return array_map(fn (MatrixRow $row): string => $row->hostname, $rows);
}

it('builds one row per device with the worst sensor per metric', function (): void {
    $result = (new MatrixBuilder())->build(matrixFixture(), makeMatrixFilters(['classes' => 'runtime,load', 'limit' => '0']));

    $a = $result['rows'][0];

    expect($result['total'])->toBe(3)
        ->and($a->hostname)->toBe('ups-a')
        ->and($a->cells['runtime']->value)->toBe(5.0)   // lowest runtime is worst
        ->and($a->cells['load']->value)->toBe(60.0);    // highest load is worst
});

it('leaves out cells for metrics a device has no sensor for', function (): void {
    $result = (new MatrixBuilder())->build(matrixFixture(), makeMatrixFilters(['classes' => 'runtime,load', 'limit' => '0']));

    $c = array_values(array_filter($result['rows'], fn (MatrixRow $r): bool => $r->hostname === 'ups-c'))[0];

    expect(array_keys($c->cells))->toBe(['runtime']);
});

it('sorts by the first metric with its default direction', function (): void {
    $result = (new MatrixBuilder())->build(matrixFixture(), makeMatrixFilters(['classes' => 'runtime,load', 'limit' => '0']));

    expect(matrixHostnames($result['rows']))->toBe(['ups-a', 'ups-c', 'ups-b']); // 5, 15, 20 minutes
});

it('sorts by another metric and puts devices without it last in both directions', function (string $dir, array $expected): void {
    $filters = makeMatrixFilters(['classes' => 'runtime,load', 'sort' => 'load', 'dir' => $dir, 'limit' => '0']);

    expect(matrixHostnames((new MatrixBuilder())->build(matrixFixture(), $filters)['rows']))->toBe($expected);
})->with([
    'descending' => ['desc', ['ups-a', 'ups-b', 'ups-c']],
    'ascending' => ['asc', ['ups-b', 'ups-a', 'ups-c']],
]);

it('sorts by hostname naturally', function (): void {
    $rows = [
        'runtime' => [
            makeRow(['deviceId' => 1, 'sensorId' => 1, 'hostname' => 'ups-10']),
            makeRow(['deviceId' => 2, 'sensorId' => 2, 'hostname' => 'ups-2']),
        ],
    ];

    $result = (new MatrixBuilder())->build($rows, makeMatrixFilters(['classes' => 'runtime', 'sort' => 'hostname', 'limit' => '0']));

    expect(matrixHostnames($result['rows']))->toBe(['ups-2', 'ups-10']);
});

it('puts a missing location last when sorting by location', function (string $dir): void {
    $rows = [
        'runtime' => [
            makeRow(['deviceId' => 1, 'sensorId' => 1, 'hostname' => 'none', 'location' => null]),
            makeRow(['deviceId' => 2, 'sensorId' => 2, 'hostname' => 'a', 'location' => 'Alpha']),
        ],
    ];

    $result = (new MatrixBuilder())->build($rows, makeMatrixFilters(['classes' => 'runtime', 'sort' => 'location', 'dir' => $dir, 'limit' => '0']));

    expect(matrixHostnames($result['rows'])[1])->toBe('none');
})->with(['asc', 'desc']);

it('limits rows but reports the full total', function (): void {
    $result = (new MatrixBuilder())->build(matrixFixture(), makeMatrixFilters(['classes' => 'runtime,load', 'limit' => '10']));
    expect($result['rows'])->toHaveCount(3);

    $rows = ['runtime' => []];
    for ($i = 1; $i <= 30; $i++) {
        $rows['runtime'][] = makeRow(['deviceId' => $i, 'sensorId' => $i, 'hostname' => "ups-$i", 'value' => (float) $i]);
    }

    $limited = (new MatrixBuilder())->build($rows, makeMatrixFilters(['classes' => 'runtime', 'limit' => '10']));

    expect($limited['rows'])->toHaveCount(10)
        ->and($limited['total'])->toBe(30)
        ->and($limited['rows'][0]->cells['runtime']->value)->toBe(1.0);
});

it('handles no rows at all', function (): void {
    expect((new MatrixBuilder())->build([], makeMatrixFilters()))->toBe(['rows' => [], 'total' => 0]);
});

it('exposes cells for every requested metric in toArray', function (): void {
    $result = (new MatrixBuilder())->build(matrixFixture(), makeMatrixFilters(['classes' => 'runtime,load', 'limit' => '0']));

    $c = array_values(array_filter($result['rows'], fn (MatrixRow $r): bool => $r->hostname === 'ups-c'))[0]->toArray(['runtime', 'load']);

    expect(array_keys($c['cells']))->toBe(['runtime', 'load'])
        ->and($c['cells']['load'])->toBeNull()
        ->and($c['cells']['runtime']['value'])->toBe(15.0)
        ->and($c['cells']['runtime']['severity'])->toBe('ok');
});

it('can swap the cells and device url', function (): void {
    $result = (new MatrixBuilder())->build(matrixFixture(), makeMatrixFilters(['classes' => 'runtime', 'limit' => '0']));
    $row = $result['rows'][0];

    $copy = $row->withCells([], '/device/1');

    expect($copy->cells)->toBe([])
        ->and($copy->deviceUrl)->toBe('/device/1')
        ->and($copy->hostname)->toBe($row->hostname);
});

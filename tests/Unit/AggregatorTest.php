<?php

declare(strict_types=1);

use Drakelid\UpsBattery\Report\Aggregator;

it('returns the rows unchanged for mode none', function (): void {
    $rows = [makeRow(['sensorId' => 1]), makeRow(['sensorId' => 2])];

    expect(Aggregator::reduce($rows, 'none'))->toBe($rows);
});

it('keeps the lowest value per device for min', function (): void {
    $rows = [
        makeRow(['deviceId' => 1, 'sensorId' => 1, 'value' => 30.0]),
        makeRow(['deviceId' => 1, 'sensorId' => 2, 'value' => 10.0]),
        makeRow(['deviceId' => 2, 'sensorId' => 3, 'value' => 20.0]),
    ];

    expect(array_map(fn ($r): int => $r->sensorId, Aggregator::reduce($rows, 'min')))->toBe([2, 3]);
});

it('keeps the highest value per device for max', function (): void {
    $rows = [
        makeRow(['deviceId' => 1, 'sensorId' => 1, 'value' => 30.0]),
        makeRow(['deviceId' => 1, 'sensorId' => 2, 'value' => 10.0]),
    ];

    expect(Aggregator::reduce($rows, 'max')[0]->sensorId)->toBe(1);
});

it('prefers a real value over null', function (): void {
    $rows = [
        makeRow(['deviceId' => 1, 'sensorId' => 1, 'value' => null]),
        makeRow(['deviceId' => 1, 'sensorId' => 2, 'value' => 10.0]),
    ];

    expect(Aggregator::reduce($rows, 'min')[0]->sensorId)->toBe(2)
        ->and(Aggregator::reduce(array_reverse($rows), 'min')[0]->sensorId)->toBe(2);
});

it('picks the lowest sensor id on ties, including all-null devices', function (): void {
    $tied = [
        makeRow(['deviceId' => 1, 'sensorId' => 7, 'value' => 10.0]),
        makeRow(['deviceId' => 1, 'sensorId' => 3, 'value' => 10.0]),
    ];
    $nulls = [
        makeRow(['deviceId' => 1, 'sensorId' => 9, 'value' => null]),
        makeRow(['deviceId' => 1, 'sensorId' => 4, 'value' => null]),
    ];

    expect(Aggregator::reduce($tied, 'max')[0]->sensorId)->toBe(3)
        ->and(Aggregator::reduce($nulls, 'min')[0]->sensorId)->toBe(4);
});

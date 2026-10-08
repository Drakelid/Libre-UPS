<?php

declare(strict_types=1);

use Drakelid\UpsBattery\Report\Summary;

/** @param  array<int, float|null>  $values */
function rowsWithValues(array $values): array
{
    $rows = [];
    foreach ($values as $i => $value) {
        $rows[] = makeRow(['sensorId' => $i + 1, 'deviceId' => $i + 1, 'value' => $value]);
    }

    return $rows;
}

it('summarises an empty list', function (): void {
    expect(Summary::from([], false))->toBe(['count' => 0, 'min' => null, 'median' => null, 'max' => null]);
});

it('computes min, median and max for an odd count', function (): void {
    expect(Summary::from(rowsWithValues([3.0, 1.0, 2.0]), false))
        ->toBe(['count' => 3, 'min' => 1.0, 'median' => 2.0, 'max' => 3.0]);
});

it('averages the two middle values for an even count', function (): void {
    expect(Summary::from(rowsWithValues([4.0, 1.0, 3.0, 2.0]), false))
        ->toBe(['count' => 4, 'min' => 1.0, 'median' => 2.5, 'max' => 4.0]);
});

it('ignores null values in the statistics but counts the rows', function (): void {
    expect(Summary::from(rowsWithValues([null, 5.0, 15.0]), false))
        ->toBe(['count' => 3, 'min' => 5.0, 'median' => 10.0, 'max' => 15.0]);
});

it('returns null statistics when every value is null', function (): void {
    expect(Summary::from(rowsWithValues([null, null]), false))
        ->toBe(['count' => 2, 'min' => null, 'median' => null, 'max' => null]);
});

it('only counts for state sensors', function (): void {
    expect(Summary::from(rowsWithValues([1.0, 2.0, 3.0]), true))
        ->toBe(['count' => 3, 'min' => null, 'median' => null, 'max' => null]);
});

it('handles a single row', function (): void {
    expect(Summary::from(rowsWithValues([7.0]), false))
        ->toBe(['count' => 1, 'min' => 7.0, 'median' => 7.0, 'max' => 7.0]);
});

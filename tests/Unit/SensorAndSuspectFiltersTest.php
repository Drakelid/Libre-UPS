<?php

declare(strict_types=1);

use Drakelid\UpsBattery\Report\MatrixFilters;

it('parses the sensor name filter like the search text', function (): void {
    expect(makeFilters()->sensor)->toBeNull()
        ->and(makeFilters(['sensor' => '  Replace battery '])->sensor)->toBe('Replace battery')
        ->and(makeFilters(['sensor' => '   '])->sensor)->toBeNull()
        ->and(mb_strlen(makeFilters(['sensor' => str_repeat('a', 150)])->sensor))->toBe(100)
        ->and(fn () => makeFilters(['sensor' => ['x']]))->toThrow(InvalidArgumentException::class);
});

it('keeps the sensor name filter in the query and the json', function (): void {
    $f = makeFilters(['sensor' => 'battery']);

    expect($f->toQuery()['sensor'])->toBe('battery')
        ->and($f->toArray()['sensor'])->toBe('battery')
        ->and(makeFilters()->toQuery()['sensor'])->toBe('');
});

it('parses the sensor name filter in the compare view too', function (): void {
    expect(makeMatrixFilters()->sensor)->toBeNull()
        ->and(makeMatrixFilters(['sensor' => ' battery '])->sensor)->toBe('battery')
        ->and(makeMatrixFilters(['sensor' => 'battery'])->toQuery()['sensor'])->toBe('battery');
});

it('is not limited to suspect batteries by default', function (): void {
    expect(makeMatrixFilters()->suspect)->toBeFalse()
        ->and(makeMatrixFilters()->toQuery()['suspect'])->toBe('')
        ->and(makeMatrixFilters()->toArray()['suspect'])->toBeFalse();
});

it('can be limited to suspect batteries when runtime and load are compared', function (): void {
    $f = makeMatrixFilters(['suspect' => '1']);

    expect($f->suspect)->toBeTrue()
        ->and($f->toQuery()['suspect'])->toBe('1')
        ->and(makeMatrixFilters(['suspect' => '1', 'classes' => 'load,runtime'])->suspect)->toBeTrue();
});

it('refuses the suspect filter without runtime and load', function (string $classes): void {
    expect(fn () => makeMatrixFilters(['suspect' => '1', 'classes' => $classes]))
        ->toThrow(InvalidArgumentException::class, 'runtime and load');
})->with(['runtime,charge', 'load,charge', 'charge']);

it('ignores a suspect flag that is not switched on', function (): void {
    expect(makeMatrixFilters(['suspect' => '0', 'classes' => 'charge'])->suspect)->toBeFalse()
        ->and(makeMatrixFilters(['suspect' => '', 'classes' => 'charge'])->suspect)->toBeFalse();
});

it('round-trips the suspect filter through toQuery', function (): void {
    $original = makeMatrixFilters(['suspect' => '1', 'sensor' => 'x']);

    expect(MatrixFilters::fromArray($original->toQuery(), []))->toEqual($original);
});

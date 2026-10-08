<?php

declare(strict_types=1);

use Drakelid\UpsBattery\Report\MatrixFilters;

it('defaults to runtime, load and charge sorted by the first metric', function (): void {
    $f = makeMatrixFilters();

    expect($f->type)->toBe('power')
        ->and($f->classes)->toBe(['runtime', 'load', 'charge'])
        ->and($f->sort)->toBe('runtime')
        ->and($f->dir)->toBe('asc')
        ->and($f->limit)->toBe(25)
        ->and($f->os)->toBeNull()
        ->and($f->group)->toBeNull()
        ->and($f->q)->toBeNull();
});

it('parses a comma separated metric list and follows the sort policy of the first metric', function (): void {
    $f = makeMatrixFilters(['classes' => 'load, runtime']);

    expect($f->classes)->toBe(['load', 'runtime'])
        ->and($f->sort)->toBe('load')
        ->and($f->dir)->toBe('desc');
});

it('accepts an array of metrics and removes duplicates', function (): void {
    expect(makeMatrixFilters(['classes' => ['runtime', 'runtime', 'charge']])->classes)->toBe(['runtime', 'charge']);
});

it('falls back to the default metrics when the list is empty', function (): void {
    expect(makeMatrixFilters(['classes' => ' , '])->classes)->toBe(MatrixFilters::DEFAULT_CLASSES);
});

it('uses metrics from the defaults when the input has none', function (): void {
    $f = makeMatrixFilters([], ['type' => 'power', 'limit' => 25, 'classes' => 'temperature,load']);

    expect($f->classes)->toBe(['temperature', 'load']);
});

it('rejects invalid metrics and too many metrics', function (array $input): void {
    expect(fn () => makeMatrixFilters($input))->toThrow(InvalidArgumentException::class);
})->with([
    'bad class' => [['classes' => 'runtime,Bad Class']],
    'sql' => [['classes' => "runtime'; DROP TABLE x;--"]],
    'seven' => [['classes' => 'a,b,c,d,e,f,g']],
]);

it('allows sorting by hostname, location or any selected metric only', function (): void {
    expect(makeMatrixFilters(['sort' => 'hostname'])->dir)->toBe('asc')
        ->and(makeMatrixFilters(['sort' => 'location'])->sort)->toBe('location')
        ->and(makeMatrixFilters(['sort' => 'charge'])->sort)->toBe('charge')
        ->and(fn () => makeMatrixFilters(['sort' => 'temperature']))->toThrow(InvalidArgumentException::class)
        ->and(fn () => makeMatrixFilters(['sort' => 'value']))->toThrow(InvalidArgumentException::class);
});

it('respects an explicit direction and validates it', function (): void {
    expect(makeMatrixFilters(['dir' => 'DESC'])->dir)->toBe('desc')
        ->and(fn () => makeMatrixFilters(['dir' => 'up']))->toThrow(InvalidArgumentException::class);
});

it('shares type, os, group, search and limit parsing with the single view', function (): void {
    $f = makeMatrixFilters(['type' => '', 'os' => 'apc', 'group' => '4', 'q' => ' site ', 'limit' => '0']);

    expect($f->type)->toBeNull()
        ->and($f->os)->toBe('apc')
        ->and($f->group)->toBe(4)
        ->and($f->q)->toBe('site')
        ->and($f->limit)->toBe(0)
        ->and(fn () => makeMatrixFilters(['limit' => '7']))->toThrow(InvalidArgumentException::class)
        ->and(fn () => makeMatrixFilters(['group' => 'x']))->toThrow(InvalidArgumentException::class)
        ->and(fn () => makeMatrixFilters(['os' => 'a b']))->toThrow(InvalidArgumentException::class);
});

it('round-trips through toQuery', function (): void {
    $original = makeMatrixFilters(['type' => 'power', 'classes' => 'load,charge', 'os' => 'apc', 'group' => '2', 'q' => 'x', 'sort' => 'hostname', 'dir' => 'desc', 'limit' => '50']);

    expect(MatrixFilters::fromArray($original->toQuery(), []))->toEqual($original);
});

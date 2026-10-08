<?php

declare(strict_types=1);

use Drakelid\UpsBattery\Report\ReportFilters;

it('uses the defaults when no input is given', function (): void {
    $f = makeFilters();

    expect($f->type)->toBe('power')
        ->and($f->class)->toBe('runtime')
        ->and($f->os)->toBeNull()
        ->and($f->group)->toBeNull()
        ->and($f->q)->toBeNull()
        ->and($f->sort)->toBe('value')
        ->and($f->dir)->toBe('asc')
        ->and($f->limit)->toBe(25)
        ->and($f->aggregate)->toBe('none');
});

it('lets an empty type mean all types', function (): void {
    expect(makeFilters(['type' => ''])->type)->toBeNull();
});

it('treats an empty default type as all types', function (): void {
    expect(makeFilters([], ['type' => '', 'class' => 'runtime', 'limit' => 25])->type)->toBeNull();
});

it('falls back to runtime when neither input nor defaults give a class', function (): void {
    expect(makeFilters([], [])->class)->toBe('runtime');
});

it('follows the sort policy for the value column', function (): void {
    expect(makeFilters(['class' => 'load'])->dir)->toBe('desc')
        ->and(makeFilters(['class' => 'runtime'])->dir)->toBe('asc');
});

it('sorts other columns ascending by default', function (): void {
    expect(makeFilters(['class' => 'load', 'sort' => 'hostname'])->dir)->toBe('asc');
});

it('respects an explicit direction', function (): void {
    expect(makeFilters(['dir' => 'DESC'])->dir)->toBe('desc');
});

it('rejects invalid sort, dir, limit, aggregate and class', function (array $input): void {
    expect(fn () => makeFilters($input))->toThrow(InvalidArgumentException::class);
})->with([
    'sort' => [['sort' => 'bogus']],
    'dir' => [['dir' => 'sideways']],
    'limit' => [['limit' => '7']],
    'limit text' => [['limit' => 'many']],
    'aggregate' => [['aggregate' => 'avg']],
    'class uppercase' => [['class' => 'Runtime']],
    'class sql' => [['class' => "runtime'; DROP TABLE sensors;--"]],
    'type sql' => [['type' => "power' OR '1'='1"]],
    'os spaces' => [['os' => 'a b']],
    'group text' => [['group' => 'x']],
    'group zero' => [['group' => '0']],
    'array value' => [['q' => ['a']]],
]);

it('parses limits including zero for all rows', function (): void {
    expect(makeFilters(['limit' => '0'])->limit)->toBe(0)
        ->and(makeFilters(['limit' => '100'])->limit)->toBe(100);
});

it('ignores an invalid default limit', function (): void {
    expect(makeFilters([], ['type' => 'power', 'class' => 'runtime', 'limit' => '33'])->limit)->toBe(25);
});

it('accepts a default limit given as a string', function (): void {
    expect(makeFilters([], ['type' => 'power', 'class' => 'runtime', 'limit' => '50'])->limit)->toBe(50);
});

it('trims the search text and drops empty search', function (): void {
    expect(makeFilters(['q' => '  foo '])->q)->toBe('foo')
        ->and(makeFilters(['q' => '   '])->q)->toBeNull();
});

it('cuts the search text at 100 characters', function (): void {
    expect(mb_strlen(makeFilters(['q' => str_repeat('a', 150)])->q))->toBe(100);
});

it('parses the group id', function (): void {
    expect(makeFilters(['group' => '3'])->group)->toBe(3)
        ->and(makeFilters(['group' => ''])->group)->toBeNull();
});

it('ignores aggregation for state sensors', function (): void {
    expect(makeFilters(['class' => 'state', 'aggregate' => 'max'])->aggregate)->toBe('none')
        ->and(makeFilters(['class' => 'load', 'aggregate' => 'max'])->aggregate)->toBe('max');
});

it('round-trips through toQuery', function (): void {
    $original = makeFilters([
        'type' => 'power', 'class' => 'load', 'os' => 'apc', 'group' => '3',
        'q' => 'site', 'sort' => 'hostname', 'dir' => 'desc', 'limit' => '50', 'aggregate' => 'max',
    ]);

    $again = ReportFilters::fromArray($original->toQuery(), []);

    expect($again)->toEqual($original);
});

it('exposes null values in toArray and empty strings in toQuery', function (): void {
    $f = makeFilters(['type' => '']);

    expect($f->toArray()['type'])->toBeNull()
        ->and($f->toQuery()['type'])->toBe('');
});

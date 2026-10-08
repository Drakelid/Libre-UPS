<?php

declare(strict_types=1);

use Drakelid\UpsBattery\Report\SortPolicy;

it('sorts runtime and charge ascending', function (): void {
    expect(SortPolicy::defaultDirection('runtime'))->toBe('asc')
        ->and(SortPolicy::defaultDirection('charge'))->toBe('asc');
});

it('sorts load, current, power, temperature and state descending', function (string $class): void {
    expect(SortPolicy::defaultDirection($class))->toBe('desc');
})->with(['load', 'current', 'power', 'temperature', 'state']);

it('falls back to ascending for unknown classes', function (): void {
    expect(SortPolicy::defaultDirection('voltage'))->toBe('asc')
        ->and(SortPolicy::defaultDirection('something_else'))->toBe('asc');
});

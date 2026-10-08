<?php

declare(strict_types=1);

use Drakelid\UpsBattery\Report\RuntimeFormatter;

it('shows zero minutes instead of an empty cell', function (): void {
    expect(RuntimeFormatter::format(0.0))->toBe('0 min')
        ->and(RuntimeFormatter::format(0.4))->toBe('0 min');
});

it('formats minutes below an hour', function (): void {
    expect(RuntimeFormatter::format(1.0))->toBe('1 min')
        ->and(RuntimeFormatter::format(45.0))->toBe('45 min')
        ->and(RuntimeFormatter::format(59.4))->toBe('59 min');
});

it('rounds fractional minutes to the nearest minute', function (): void {
    expect(RuntimeFormatter::format(4.5))->toBe('5 min')
        ->and(RuntimeFormatter::format(4.49))->toBe('4 min')
        ->and(RuntimeFormatter::format(59.6))->toBe('1 h');
});

it('formats hours and minutes', function (): void {
    expect(RuntimeFormatter::format(60.0))->toBe('1 h')
        ->and(RuntimeFormatter::format(65.0))->toBe('1 h 5 min')
        ->and(RuntimeFormatter::format(180.0))->toBe('3 h')
        ->and(RuntimeFormatter::format(1439.0))->toBe('23 h 59 min');
});

it('formats days and hours and leaves out minutes', function (): void {
    expect(RuntimeFormatter::format(1440.0))->toBe('1 d')
        ->and(RuntimeFormatter::format(1440.0 + 3 * 60 + 25))->toBe('1 d 3 h')
        ->and(RuntimeFormatter::format(2.0 * 1440))->toBe('2 d');
});

it('returns an empty string only when there is no usable value', function (): void {
    expect(RuntimeFormatter::format(null))->toBe('')
        ->and(RuntimeFormatter::format(NAN))->toBe('')
        ->and(RuntimeFormatter::format(INF))->toBe('');
});

it('keeps negative values visible instead of hiding a sensor fault', function (): void {
    expect(RuntimeFormatter::format(-1.0))->toBe('-1 min');
});

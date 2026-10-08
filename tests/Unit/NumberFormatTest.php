<?php

declare(strict_types=1);

use Drakelid\UpsBattery\Report\NumberFormat;

it('writes plain numbers without trailing zeros or thousands separators', function (): void {
    expect(NumberFormat::plain(12.5))->toBe('12.5')
        ->and(NumberFormat::plain(3.0))->toBe('3')
        ->and(NumberFormat::plain(-0.25))->toBe('-0.25')
        ->and(NumberFormat::plain(1234567.25))->toBe('1234567.25')
        ->and(NumberFormat::plain(7.50))->toBe('7.5');
});

it('writes zero for zero, negative zero and values that round to zero', function (): void {
    expect(NumberFormat::plain(0.0))->toBe('0')
        ->and(NumberFormat::plain(-0.0))->toBe('0')
        ->and(NumberFormat::plain(0.0000001))->toBe('0')
        ->and(NumberFormat::plain(-0.0000001))->toBe('0');
});

it('can write a decimal comma', function (): void {
    expect(NumberFormat::decimalComma(12.5))->toBe('12,5')
        ->and(NumberFormat::decimalComma(1234567.25))->toBe('1234567,25')
        ->and(NumberFormat::decimalComma(4.0))->toBe('4');
});

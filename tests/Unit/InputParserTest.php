<?php

declare(strict_types=1);

use Drakelid\UpsBattery\Report\InputParser;

it('reads flags the way forms and links send them', function (mixed $value, bool $expected): void {
    expect(InputParser::parseFlag($value))->toBe($expected);
})->with([
    ['1', true],
    ['true', true],
    ['TRUE', true],
    [' yes ', true],
    ['on', true],
    [1, true],
    [true, true],
    ['0', false],
    ['false', false],
    ['', false],
    ['2', false],
    [null, false],
    [0, false],
    [['1'], false],
]);

it('trims search text, treats blank as nothing and cuts it at 100 characters', function (): void {
    expect(InputParser::parseSearch('  battery '))->toBe('battery')
        ->and(InputParser::parseSearch('   '))->toBeNull()
        ->and(InputParser::parseSearch(null))->toBeNull()
        ->and(mb_strlen((string) InputParser::parseSearch(str_repeat('å', 150))))->toBe(100);
});

it('names the offending parameter in the error', function (): void {
    expect(fn () => InputParser::parseSearch(['x'], 'sensor'))->toThrow(InvalidArgumentException::class, 'sensor');
});

it('accepts only known limits', function (): void {
    expect(InputParser::parseLimit('50', null))->toBe(50)
        ->and(InputParser::parseLimit('0', null))->toBe(0)
        ->and(InputParser::parseLimit(null, '100'))->toBe(100)
        ->and(InputParser::parseLimit(null, '33'))->toBe(25)
        ->and(fn () => InputParser::parseLimit('33', null))->toThrow(InvalidArgumentException::class);
});

it('validates identifiers and classes', function (): void {
    InputParser::assertIdentifier('power', 'type');
    InputParser::assertIdentifier(null, 'type');
    InputParser::assertClass('runtime');

    expect(fn () => InputParser::assertIdentifier('a b', 'type'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => InputParser::assertClass('Runtime'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => InputParser::assertClass(''))->toThrow(InvalidArgumentException::class);
});

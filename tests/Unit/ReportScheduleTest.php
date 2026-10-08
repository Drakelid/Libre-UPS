<?php

declare(strict_types=1);

use Drakelid\UpsBattery\Report\ReportSchedule;

it('is off by default', function (): void {
    $s = ReportSchedule::fromArray([]);

    expect($s->enabled)->toBeFalse()
        ->and($s->recipients)->toBe([])
        ->and($s->day)->toBe(1)
        ->and($s->time)->toBe('07:00')
        ->and($s->top)->toBe(10)
        ->and($s->isActive())->toBeFalse();
});

it('is only active when enabled and addressed to someone', function (): void {
    expect(ReportSchedule::fromArray(['report_enabled' => '1', 'report_recipients' => 'a@example.com'])->isActive())->toBeTrue()
        ->and(ReportSchedule::fromArray(['report_enabled' => '1'])->isActive())->toBeFalse()
        ->and(ReportSchedule::fromArray(['report_enabled' => '1', 'report_recipients' => 'not an address'])->isActive())->toBeFalse()
        ->and(ReportSchedule::fromArray(['report_recipients' => 'a@example.com'])->isActive())->toBeFalse();
});

it('reads the checkbox the way a form sends it', function (mixed $value, bool $expected): void {
    expect(ReportSchedule::fromArray(['report_enabled' => $value])->enabled)->toBe($expected);
})->with([
    ['1', true],
    [1, true],
    [true, true],
    ['on', true],
    ['0', false],
    ['', false],
    [null, false],
    [['x'], false],
]);

it('parses recipients separated by commas, semicolons, spaces and line breaks', function (): void {
    expect(ReportSchedule::parseRecipients("a@example.com, b@example.com;c@example.com\nd@example.com e@example.com"))
        ->toBe(['a@example.com', 'b@example.com', 'c@example.com', 'd@example.com', 'e@example.com']);
});

it('drops invalid and duplicate addresses', function (): void {
    expect(ReportSchedule::parseRecipients('a@example.com, nonsense, A@EXAMPLE.COM, @bad, b@example.com'))
        ->toBe(['a@example.com', 'b@example.com']);
});

it('has no recipients for anything but text', function (): void {
    expect(ReportSchedule::parseRecipients(null))->toBe([])
        ->and(ReportSchedule::parseRecipients(['a@example.com']))->toBe([])
        ->and(ReportSchedule::parseRecipients(''))->toBe([]);
});

it('accepts a valid day and time and falls back otherwise', function (): void {
    expect(ReportSchedule::fromArray(['report_day' => '0', 'report_time' => '23:59'])->day)->toBe(0)
        ->and(ReportSchedule::fromArray(['report_day' => '6'])->day)->toBe(6)
        ->and(ReportSchedule::fromArray(['report_day' => '7'])->day)->toBe(1)
        ->and(ReportSchedule::fromArray(['report_day' => '-1'])->day)->toBe(1)
        ->and(ReportSchedule::fromArray(['report_day' => 'monday'])->day)->toBe(1)
        ->and(ReportSchedule::fromArray(['report_time' => '23:59'])->time)->toBe('23:59')
        ->and(ReportSchedule::fromArray(['report_time' => '24:00'])->time)->toBe('07:00')
        ->and(ReportSchedule::fromArray(['report_time' => '7:00'])->time)->toBe('07:00')
        ->and(ReportSchedule::fromArray(['report_time' => ['x']])->time)->toBe('07:00');
});

it('only offers row counts that are valid report limits', function (): void {
    foreach (ReportSchedule::TOP_CHOICES as $top) {
        expect(ReportSchedule::fromArray(['report_top' => (string) $top])->top)->toBe($top)
            ->and(makeFilters(['limit' => (string) $top])->limit)->toBe($top);
    }

    expect(ReportSchedule::fromArray(['report_top' => '7'])->top)->toBe(10)
        ->and(ReportSchedule::fromArray(['report_top' => '0'])->top)->toBe(10);
});

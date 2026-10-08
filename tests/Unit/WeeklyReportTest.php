<?php

declare(strict_types=1);

use Drakelid\UpsBattery\Report\MatrixBuilder;
use Drakelid\UpsBattery\Report\MatrixRow;
use Drakelid\UpsBattery\Report\ReportRow;
use Drakelid\UpsBattery\Report\Severity;
use Drakelid\UpsBattery\Report\SuspectRule;
use Drakelid\UpsBattery\Report\WeeklyReport;

/** @return array<string, string> The English report texts, the same ones the command uses. */
function reportTexts(string $locale = 'en'): array
{
    return (require __DIR__.'/../../lang/'.$locale.'/ups-battery.php')['report_mail'];
}

it('counts devices and severities', function (): void {
    $rows = [
        makeRow(['sensorId' => 1, 'severity' => Severity::Critical]),
        makeRow(['sensorId' => 2, 'severity' => Severity::Warning]),
        makeRow(['sensorId' => 3, 'severity' => Severity::Warning]),
        makeRow(['sensorId' => 4, 'severity' => Severity::Ok]),
        makeRow(['sensorId' => 5, 'severity' => Severity::Unknown]),
    ];

    expect(WeeklyReport::countRuntime($rows))->toBe(['devices' => 5, 'critical' => 1, 'warning' => 2])
        ->and(WeeklyReport::countRuntime([]))->toBe(['devices' => 0, 'critical' => 0, 'warning' => 0]);
});

it('puts the suspect and critical counts in the subject', function (): void {
    $subject = WeeklyReport::subject(reportTexts(), ['devices' => 40, 'critical' => 3, 'warning' => 5, 'suspect' => 2]);

    expect($subject)->toBe('UPS Battery weekly report: 2 suspect, 3 critical');
});

it('translates the subject', function (): void {
    expect(WeeklyReport::subject(reportTexts('nb'), ['devices' => 1, 'critical' => 0, 'warning' => 0, 'suspect' => 4]))
        ->toBe('UPS-batteri ukerapport: 4 mistenkelige, 0 kritiske');
});

/** @return array{0: array<int, ReportRow>, 1: array<int, MatrixRow>} */
function reportFixture(): array
{
    $shortest = [
        makeRow(['sensorId' => 1, 'hostname' => 'ups-a', 'displayName' => 'UPS A', 'deviceUrl' => '/device/1', 'location' => 'Site <1>', 'valueFormatted' => '4 min', 'severity' => Severity::Critical]),
        makeRow(['sensorId' => 2, 'hostname' => 'ups-b', 'displayName' => 'UPS B', 'deviceUrl' => '/device/2', 'location' => null, 'valueFormatted' => '18 min', 'severity' => Severity::Ok]),
    ];

    $suspect = (new MatrixBuilder)->build([
        'runtime' => [makeRow(['deviceId' => 1, 'sensorId' => 3, 'hostname' => 'ups-a', 'displayName' => 'UPS A', 'deviceUrl' => '/device/1', 'value' => 4.0, 'valueFormatted' => '4 min'])],
        'load' => [makeRow(['deviceId' => 1, 'sensorId' => 4, 'hostname' => 'ups-a', 'value' => 12.0, 'valueFormatted' => '12 %'])],
        'charge' => [makeRow(['deviceId' => 1, 'sensorId' => 5, 'hostname' => 'ups-a', 'value' => 100.0, 'valueFormatted' => '100 %'])],
    ], makeMatrixFilters(['classes' => 'runtime,load,charge', 'suspect' => '1', 'limit' => '0']), new SuspectRule)['rows'];

    return [$shortest, $suspect];
}

it('builds a complete html report', function (): void {
    [$shortest, $suspect] = reportFixture();

    $html = WeeklyReport::html(
        reportTexts(),
        'https://librenms.example',
        'https://librenms.example/plugin/ups-battery/report',
        '2026-10-08 07:00',
        ['devices' => 2, 'critical' => 1, 'warning' => 0, 'suspect' => 1],
        $shortest,
        $suspect,
    );

    expect($html)->toContain('UPS Battery weekly report')
        ->and($html)->toContain('Generated 2026-10-08 07:00')
        ->and($html)->toContain('2 UPSes with a runtime sensor')
        ->and($html)->toContain('1 critical')
        ->and($html)->toContain('1 suspect batteries')
        ->and($html)->toContain('href="https://librenms.example/device/1"')
        ->and($html)->toContain('4 min')
        ->and($html)->toContain('12 %')
        ->and($html)->toContain('100 %')
        ->and($html)->toContain('Open the report in LibreNMS')
        ->and($html)->toContain('href="https://librenms.example/plugin/ups-battery/report"');
});

it('escapes everything that comes from the database', function (): void {
    [$shortest, $suspect] = reportFixture();

    $html = WeeklyReport::html(reportTexts(), '', '', 'now', ['devices' => 2, 'critical' => 1, 'warning' => 0, 'suspect' => 1], $shortest, $suspect);

    expect($html)->toContain('Site &lt;1&gt;')
        ->and($html)->not->toContain('Site <1>');
});

it('shows the status of each runtime row in words and colour', function (): void {
    [$shortest] = reportFixture();

    $html = WeeklyReport::html(reportTexts(), '', '', 'now', ['devices' => 2, 'critical' => 1, 'warning' => 0, 'suspect' => 0], $shortest, []);

    expect($html)->toContain('Critical')
        ->and($html)->toContain('OK')
        ->and($html)->toContain('background:#f2dede');
});

it('says so when there is nothing to list', function (): void {
    $html = WeeklyReport::html(reportTexts(), '', '', 'now', ['devices' => 0, 'critical' => 0, 'warning' => 0, 'suspect' => 0], [], []);

    expect(substr_count($html, 'None.'))->toBe(2)
        ->and($html)->not->toContain('<table');
});

it('leaves out the report link when there is none and keeps relative links relative without an origin', function (): void {
    [$shortest] = reportFixture();

    $html = WeeklyReport::html(reportTexts(), '', '', 'now', ['devices' => 2, 'critical' => 0, 'warning' => 0, 'suspect' => 0], $shortest, []);

    expect($html)->not->toContain('Open the report')
        ->and($html)->toContain('href="/device/1"');
});

it('uses the Norwegian texts', function (): void {
    [$shortest, $suspect] = reportFixture();

    $html = WeeklyReport::html(reportTexts('nb'), '', '', 'nå', ['devices' => 2, 'critical' => 1, 'warning' => 0, 'suspect' => 1], $shortest, $suspect);

    expect($html)->toContain('UPS-batteri ukerapport')
        ->and($html)->toContain('Mistenkelige batterier')
        ->and($html)->toContain('Kortest batteritid')
        ->and($html)->toContain('Kritisk');
});

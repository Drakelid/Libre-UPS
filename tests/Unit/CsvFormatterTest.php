<?php

declare(strict_types=1);

use Drakelid\UpsBattery\Report\CsvFormatter;
use Drakelid\UpsBattery\Report\MatrixBuilder;
use Drakelid\UpsBattery\Report\Severity;

it('starts with a UTF-8 BOM and the header line', function (): void {
    $csv = (new CsvFormatter())->toCsv([]);

    expect($csv)->toBe("\xEF\xBB\xBFhostname;display_name;location;os;sensor;value;unit;severity;last_updated\r\n");
});

it('separates cells with semicolons and lines with CRLF', function (): void {
    $csv = (new CsvFormatter())->toCsv([makeRow([
        'hostname' => 'ups-01', 'displayName' => 'UPS 01', 'location' => 'Site A', 'os' => 'apc',
        'sensorDescr' => 'Battery runtime', 'value' => 12.0, 'unit' => 'min', 'severity' => Severity::Warning,
        'lastUpdate' => '2026-10-07T08:55:00+02:00',
    ])]);

    $lines = explode("\r\n", $csv);

    expect($lines[1])->toBe('ups-01;UPS 01;Site A;apc;Battery runtime;12;min;warning;2026-10-07T08:55:00+02:00')
        ->and($lines[2])->toBe('');
});

it('uses a decimal comma and no thousands separator', function (): void {
    $formatter = new CsvFormatter();

    expect($formatter->line(makeRow(['value' => 12.5]))[5])->toBe('12,5')
        ->and($formatter->line(makeRow(['value' => 1234567.25]))[5])->toBe('1234567,25')
        ->and($formatter->line(makeRow(['value' => -3.0]))[5])->toBe('-3')
        ->and($formatter->line(makeRow(['value' => 0.0]))[5])->toBe('0');
});

it('writes an empty cell for a null value', function (): void {
    expect((new CsvFormatter())->line(makeRow(['value' => null]))[5])->toBe('');
});

it('quotes cells that contain separators, quotes or line breaks', function (): void {
    $csv = (new CsvFormatter())->toCsv([makeRow([
        'location' => 'Site; "A"',
        'sensorDescr' => "line1\nline2",
    ])]);

    expect($csv)->toContain('"Site; ""A"""')
        ->and($csv)->toContain("\"line1\nline2\"");
});

it('neutralises spreadsheet formulas', function (string $input): void {
    $line = (new CsvFormatter())->line(makeRow(['hostname' => $input, 'sensorDescr' => $input]));

    expect($line[0])->toBe("'".$input)
        ->and($line[4])->toBe("'".$input);
})->with(['=SUM(A1)', '+cmd', '-evil', '@SUM(1)']);

it('leaves plain negative numbers in text cells alone', function (): void {
    expect((new CsvFormatter())->line(makeRow(['location' => '-12,5']))[2])->toBe('-12,5');
});

it('reports the severity as text', function (): void {
    expect((new CsvFormatter())->line(makeRow(['severity' => Severity::Critical]))[7])->toBe('critical');
});

it('writes the compare-metrics csv with a value and severity column per metric', function (): void {
    $rows = (new MatrixBuilder())->build([
        'runtime' => [makeRow(['deviceId' => 1, 'sensorId' => 1, 'hostname' => 'ups-a', 'value' => 12.5, 'severity' => Severity::Warning])],
        'load' => [],
    ], makeMatrixFilters(['classes' => 'runtime,load', 'limit' => '0']))['rows'];

    $csv = (new CsvFormatter())->matrixToCsv($rows, ['runtime', 'load']);
    $lines = explode("\r\n", $csv);

    expect($csv)->toStartWith("\xEF\xBB\xBF")
        ->and($lines[0])->toContain('hostname;display_name;location;os;runtime;runtime_severity;load;load_severity')
        ->and($lines[1])->toBe('ups-a;ups-01;Site A;apc;12,5;warning;;');
});

it('keeps the row order', function (): void {
    $csv = (new CsvFormatter())->toCsv([
        makeRow(['hostname' => 'first']),
        makeRow(['hostname' => 'second']),
    ]);

    expect(strpos($csv, 'first'))->toBeLessThan(strpos($csv, 'second'));
});

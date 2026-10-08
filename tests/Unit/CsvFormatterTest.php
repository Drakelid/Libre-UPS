<?php

declare(strict_types=1);

use Drakelid\UpsBattery\Report\CsvFormatter;
use Drakelid\UpsBattery\Report\MatrixBuilder;
use Drakelid\UpsBattery\Report\Severity;
use Drakelid\UpsBattery\Report\SuspectRule;

it('starts with a UTF-8 BOM and the header line', function (): void {
    $csv = (new CsvFormatter)->toCsv([]);

    expect($csv)->toBe("\xEF\xBB\xBFhostname;display_name;location;os;sensor;value;unit;severity;last_updated\r\n");
});

it('separates cells with semicolons and lines with CRLF', function (): void {
    $csv = (new CsvFormatter)->toCsv([makeRow([
        'hostname' => 'ups-01', 'displayName' => 'UPS 01', 'location' => 'Site A', 'os' => 'apc',
        'sensorDescr' => 'Battery runtime', 'value' => 12.0, 'unit' => 'min', 'severity' => Severity::Warning,
        'lastUpdate' => '2026-10-07T08:55:00+02:00',
    ])]);

    $lines = explode("\r\n", $csv);

    expect($lines[1])->toBe('ups-01;UPS 01;Site A;apc;Battery runtime;12;min;warning;2026-10-07T08:55:00+02:00')
        ->and($lines[2])->toBe('');
});

it('uses a decimal comma and no thousands separator', function (): void {
    $formatter = new CsvFormatter;

    expect($formatter->line(makeRow(['value' => 12.5]))[5])->toBe('12,5')
        ->and($formatter->line(makeRow(['value' => 1234567.25]))[5])->toBe('1234567,25')
        ->and($formatter->line(makeRow(['value' => -3.0]))[5])->toBe('-3')
        ->and($formatter->line(makeRow(['value' => 0.0]))[5])->toBe('0');
});

it('writes an empty cell for a null value', function (): void {
    expect((new CsvFormatter)->line(makeRow(['value' => null]))[5])->toBe('');
});

it('quotes cells that contain separators, quotes or line breaks', function (): void {
    $csv = (new CsvFormatter)->toCsv([makeRow([
        'location' => 'Site; "A"',
        'sensorDescr' => "line1\nline2",
    ])]);

    expect($csv)->toContain('"Site; ""A"""')
        ->and($csv)->toContain("\"line1\nline2\"");
});

it('neutralises spreadsheet formulas', function (string $input): void {
    $line = (new CsvFormatter)->line(makeRow(['hostname' => $input, 'sensorDescr' => $input]));

    expect($line[0])->toBe("'".$input)
        ->and($line[4])->toBe("'".$input);
})->with(['=SUM(A1)', '+cmd', '-evil', '@SUM(1)']);

it('leaves plain negative numbers in text cells alone', function (): void {
    expect((new CsvFormatter)->line(makeRow(['location' => '-12,5']))[2])->toBe('-12,5');
});

it('reports the severity as text', function (): void {
    expect((new CsvFormatter)->line(makeRow(['severity' => Severity::Critical]))[7])->toBe('critical');
});

it('writes the compare-metrics csv with a value and severity column per metric', function (): void {
    $rows = (new MatrixBuilder)->build([
        'runtime' => [makeRow(['deviceId' => 1, 'sensorId' => 1, 'hostname' => 'ups-a', 'value' => 12.5, 'severity' => Severity::Warning])],
        'load' => [],
    ], makeMatrixFilters(['classes' => 'runtime,load', 'limit' => '0']))['rows'];

    $csv = (new CsvFormatter)->matrixToCsv($rows, ['runtime', 'load']);
    $lines = explode("\r\n", $csv);

    expect($csv)->toStartWith("\xEF\xBB\xBF")
        ->and($lines[0])->toContain('hostname;display_name;location;os;runtime;runtime_severity;load;load_severity;suspect_battery')
        ->and($lines[1])->toBe('ups-a;ups-01;Site A;apc;12,5;warning;;;');
});

it('writes the suspect battery verdict as yes, no or empty', function (): void {
    $rows = [
        'runtime' => [makeRow(['deviceId' => 1, 'sensorId' => 1, 'hostname' => 'bad', 'value' => 5.0]), makeRow(['deviceId' => 2, 'sensorId' => 2, 'hostname' => 'good', 'value' => 40.0]), makeRow(['deviceId' => 3, 'sensorId' => 3, 'hostname' => 'noload', 'value' => 5.0])],
        'load' => [makeRow(['deviceId' => 1, 'sensorId' => 4, 'hostname' => 'bad', 'value' => 10.0]), makeRow(['deviceId' => 2, 'sensorId' => 5, 'hostname' => 'good', 'value' => 10.0])],
    ];
    $matrix = (new MatrixBuilder)->build($rows, makeMatrixFilters(['classes' => 'runtime,load', 'sort' => 'hostname', 'limit' => '0']), new SuspectRule)['rows'];

    $verdicts = [];
    foreach ($matrix as $row) {
        $line = (new CsvFormatter)->matrixLine($row, ['runtime', 'load']);
        $verdicts[$row->hostname] = $line[count($line) - 1];
    }

    expect($verdicts)->toBe(['bad' => 'yes', 'good' => 'no', 'noload' => '']);
});

it('leaves out the suspect column when runtime or load is not selected', function (): void {
    $formatter = new CsvFormatter;

    expect($formatter->matrixHeader(['runtime', 'charge']))->not->toContain('suspect_battery')
        ->and($formatter->matrixHeader(['load']))->not->toContain('suspect_battery')
        ->and($formatter->matrixHeader(['runtime', 'load']))->toContain('suspect_battery');
});

it('keeps the row order', function (): void {
    $csv = (new CsvFormatter)->toCsv([
        makeRow(['hostname' => 'first']),
        makeRow(['hostname' => 'second']),
    ]);

    expect(strpos($csv, 'first'))->toBeLessThan(strpos($csv, 'second'));
});

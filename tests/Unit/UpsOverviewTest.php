<?php

declare(strict_types=1);

use Drakelid\UpsBattery\Report\BatterySwap;
use Drakelid\UpsBattery\Report\CsvFormatter;
use Drakelid\UpsBattery\Report\InputParser;
use Drakelid\UpsBattery\Report\PluginSettings;
use Drakelid\UpsBattery\Report\ReportRow;
use Drakelid\UpsBattery\Report\Severity;
use Drakelid\UpsBattery\Report\SuspectRule;
use Drakelid\UpsBattery\Report\UpsBuilder;
use Drakelid\UpsBattery\Report\UpsFilters;
use Drakelid\UpsBattery\Report\UpsSensorKind;
use Drakelid\UpsBattery\Report\WeeklyReport;

const UPS_NOW = '2026-10-08T12:00:00+00:00';

function upsNow(): DateTimeImmutable
{
    return new DateTimeImmutable(UPS_NOW);
}

/** A sensor of a UPS; $deviceId selects the device. */
function upsSensor(int $deviceId, string $class, ?float $value, array $overrides = []): ReportRow
{
    static $id = 100;

    return makeRow(array_merge([
        'deviceId' => $deviceId,
        'hostname' => 'ups-'.$deviceId,
        'displayName' => 'UPS '.$deviceId,
        'sensorId' => ++$id,
        'sensorClass' => $class,
        'sensorDescr' => ucfirst($class),
        'value' => $value,
        'valueFormatted' => (string) $value,
    ], $overrides));
}

function upsBuilder(int $warnDays = 90): UpsBuilder
{
    return new UpsBuilder(new SuspectRule, 48, $warnDays, upsNow());
}

function upsFilters(array $input = []): UpsFilters
{
    return UpsFilters::fromArray($input, ['type' => null, 'limit' => 25]);
}

// ---- sensor kinds ----

it('recognises the APC battery dates that LibreNMS stores as runtime sensors', function (): void {
    expect(UpsSensorKind::of('runtime', 'apc', 'upsAdvBatteryRecommendedReplaceDate.0', 'Battery Recommended Days Remaining'))->toBe(UpsSensorKind::ReplaceDue)
        ->and(UpsSensorKind::of('runtime', 'apc', 'upsBasicBatteryLastReplaceDate.0', 'Last Battery Replacement'))->toBe(UpsSensorKind::ReplacedAt)
        ->and(UpsSensorKind::of('runtime', 'apc', 'upsAdvBatteryRunTimeRemaining.0', 'Runtime'))->toBe(UpsSensorKind::Runtime)
        ->and(UpsSensorKind::of('count', 'apc', 'upsAdvBatteryNumOfBadBattPacks.0', 'Bad batteries'))->toBe(UpsSensorKind::BadPacks)
        ->and(UpsSensorKind::of('count', 'apc', 'upsAdvBatteryNumOfBattPacks.0', 'Installed batteries'))->toBe(UpsSensorKind::Other);
});

it('recognises the UPS state sensors of APC and the standard UPS-MIB', function (string $type, UpsSensorKind $kind): void {
    expect(UpsSensorKind::of('state', $type, '0', 'x'))->toBe($kind);
})->with([
    ['upsAdvBatteryReplaceIndicator', UpsSensorKind::BatteryState],
    ['upsBatteryStatusState', UpsSensorKind::BatteryState],
    ['upsBasicOutputStatus', UpsSensorKind::OutputState],
    ['upsOutputSourceState', UpsSensorKind::OutputState],
    ['upsAdvTestDiagnosticsResults', UpsSensorKind::SelfTestState],
    ['upsTestResult', UpsSensorKind::SelfTestState],
]);

it('recognises other vendors\' state sensors by their name', function (): void {
    expect(UpsSensorKind::of('state', 'xupsBatteryAbmStatus', '0', 'Battery ABM status'))->toBe(UpsSensorKind::BatteryState)
        ->and(UpsSensorKind::of('state', 'xupsTestBatteryStatus', '0', 'Battery test'))->toBe(UpsSensorKind::SelfTestState)
        ->and(UpsSensorKind::of('state', 'xupsOutputSource', '0', 'Output source'))->toBe(UpsSensorKind::OutputState)
        ->and(UpsSensorKind::of('state', 'fanStatus', '0', 'Fan'))->toBe(UpsSensorKind::Other)
        ->and(UpsSensorKind::of('voltage', 'rfc1628', '1', 'Battery'))->toBe(UpsSensorKind::Other);
});

it('reads "on battery" from the output state text', function (string $text, ?bool $expected): void {
    expect(UpsSensorKind::meansOnBattery($text))->toBe($expected);
})->with([
    ['onBattery', true],
    ['Battery', true],
    ['onLine', false],
    ['Normal', false],
    ['onBatteryTest', false],
    ['unknown', null],
    ['', null],
]);

// ---- battery swap ----

it('takes the swap date from the install date entered by a user', function (): void {
    $swap = BatterySwap::evaluate('2023-01-15', 5000.0, null, UPS_NOW, 48, 90, upsNow());

    expect($swap->source)->toBe('manual')
        ->and($swap->installed)->toBe('2023-01-15')
        ->and($swap->due)->toBe('2027-01-15')
        ->and($swap->daysLeft)->toBe(99)
        ->and($swap->severity)->toBe(Severity::Ok);
});

it('warns inside the warning window and is critical when the swap is due or overdue', function (): void {
    expect(BatterySwap::evaluate('2022-12-01', null, null, null, 48, 90, upsNow())->severity)->toBe(Severity::Warning)
        ->and(BatterySwap::evaluate('2022-10-08', null, null, null, 48, 90, upsNow()))->daysLeft->toBe(0)
        ->and(BatterySwap::evaluate('2022-10-08', null, null, null, 48, 90, upsNow())->severity)->toBe(Severity::Critical)
        ->and(BatterySwap::evaluate('2020-01-01', null, null, null, 48, 90, upsNow())->daysLeft)->toBeLessThan(0);
});

it('uses the recommended replacement date the UPS reports when no install date is entered', function (): void {
    // 30 days from the last poll, the poll was a day ago
    $swap = BatterySwap::evaluate(null, 30 * 1440.0, null, '2026-10-07T12:00:00+00:00', 48, 90, upsNow());
    $overdue = BatterySwap::evaluate(null, -10 * 1440.0, null, UPS_NOW, 48, 90, upsNow());

    expect($swap->source)->toBe('ups')
        ->and($swap->due)->toBe('2026-11-06')
        ->and($swap->daysLeft)->toBe(29)
        ->and($swap->installed)->toBeNull()
        ->and($overdue->daysLeft)->toBe(-10)
        ->and($overdue->severity)->toBe(Severity::Critical);
});

it('falls back to the last replacement date the UPS reports plus the lifetime', function (): void {
    $past = BatterySwap::evaluate(null, null, -365 * 1440.0, UPS_NOW, 48, 90, upsNow());
    $unsigned = BatterySwap::evaluate(null, null, 365 * 1440.0, UPS_NOW, 48, 90, upsNow());

    expect($past->source)->toBe('ups_last')
        ->and($past->installed)->toBe('2025-10-08')
        ->and($past->due)->toBe('2029-10-08')
        ->and($unsigned->installed)->toBe('2025-10-08');
});

it('knows no swap date without any date', function (): void {
    $swap = BatterySwap::evaluate(null, null, null, null, 48, 90, upsNow());

    expect($swap->source)->toBe('none')
        ->and($swap->due)->toBeNull()
        ->and($swap->daysLeft)->toBeNull()
        ->and($swap->severity)->toBe(Severity::Unknown)
        ->and($swap->toArray())->toBe(['installed' => null, 'due' => null, 'days_left' => null, 'source' => 'none', 'severity' => 'unknown', 'life_used' => null]);
});

it('ignores an invalid install date', function (): void {
    expect(BatterySwap::parseDate('2026-02-30'))->toBeNull()
        ->and(BatterySwap::parseDate('8.10.2026'))->toBeNull()
        ->and(BatterySwap::evaluate('garbage', null, null, null, 48, 90, upsNow())->source)->toBe('none');
});

it('validates the install date a user enters', function (): void {
    expect(InputParser::parseInstallDate(' 2024-03-01 ', upsNow()))->toBe('2024-03-01')
        ->and(InputParser::parseInstallDate('', upsNow()))->toBeNull()
        ->and(InputParser::parseInstallDate(null, upsNow()))->toBeNull()
        ->and(fn () => InputParser::parseInstallDate('2024-13-01', upsNow()))->toThrow(InvalidArgumentException::class)
        ->and(fn () => InputParser::parseInstallDate('2026-10-09', upsNow()))->toThrow(InvalidArgumentException::class)
        ->and(fn () => InputParser::parseInstallDate('1980-01-01', upsNow()))->toThrow(InvalidArgumentException::class)
        ->and(fn () => InputParser::parseInstallDate(['x'], upsNow()))->toThrow(InvalidArgumentException::class);
});

it('reads and bounds the battery lifetime and warning settings', function (): void {
    expect(PluginSettings::fromArray([])->batteryLifetimeMonths)->toBe(48)
        ->and(PluginSettings::fromArray([])->swapWarnDays)->toBe(90)
        ->and(PluginSettings::fromArray(['battery_lifetime_months' => '60', 'swap_warn_days' => '30'])->batteryLifetimeMonths)->toBe(60)
        ->and(PluginSettings::fromArray(['swap_warn_days' => '30'])->swapWarnDays)->toBe(30)
        ->and(PluginSettings::fromArray(['battery_lifetime_months' => '1'])->batteryLifetimeMonths)->toBe(48)
        ->and(PluginSettings::fromArray(['swap_warn_days' => 'x'])->swapWarnDays)->toBe(90);
});

// ---- filters ----

it('sorts the UPS overview by status, worst first, unless asked otherwise', function (): void {
    expect(upsFilters()->sort)->toBe('status')
        ->and(upsFilters()->dir)->toBe('desc')
        ->and(upsFilters(['sort' => 'swap'])->dir)->toBe('asc')
        ->and(upsFilters(['sort' => 'load'])->dir)->toBe('desc')
        ->and(upsFilters(['sort' => 'runtime', 'dir' => 'DESC'])->dir)->toBe('desc')
        ->and(upsFilters(['attention' => '1'])->attention)->toBeTrue()
        ->and(fn () => upsFilters(['sort' => 'value']))->toThrow(InvalidArgumentException::class)
        ->and(fn () => upsFilters(['dir' => 'up']))->toThrow(InvalidArgumentException::class);
});

// ---- builder ----

it('builds one row per UPS from all its sensors', function (): void {
    $row = upsBuilder()->row([
        upsSensor(1, 'runtime', 25.0),
        upsSensor(1, 'runtime', 12.0, ['sensorDescr' => 'Runtime pack 2']),
        upsSensor(1, 'runtime', -500000.0, ['sensorIndex' => 'upsBasicBatteryLastReplaceDate.0']),
        upsSensor(1, 'charge', 100.0),
        upsSensor(1, 'load', 40.0),
        upsSensor(1, 'temperature', 24.0),
        upsSensor(1, 'temperature', 31.0, ['sensorDescr' => 'Battery temperature']),
        upsSensor(1, 'voltage', 230.0, ['sensorDescr' => 'Input']),
        upsSensor(1, 'state', 2.0, ['sensorType' => 'upsBasicOutputStatus', 'valueFormatted' => 'onLine']),
        upsSensor(1, 'state', 1.0, ['sensorType' => 'upsAdvBatteryReplaceIndicator', 'valueFormatted' => 'noBatteryNeedsReplacing']),
        upsSensor(1, 'state', 1.0, ['sensorType' => 'upsAdvTestDiagnosticsResults', 'valueFormatted' => 'ok']),
    ], '2024-01-01');

    expect($row)->not->toBeNull()
        ->and($row->runtime->value)->toBe(12.0)
        ->and($row->charge->value)->toBe(100.0)
        ->and($row->load->value)->toBe(40.0)
        ->and($row->temperature->value)->toBe(31.0)
        ->and($row->output->valueFormatted)->toBe('onLine')
        ->and($row->onBattery)->toBeFalse()
        ->and($row->battery->valueFormatted)->toBe('noBatteryNeedsReplacing')
        ->and($row->selfTest->valueFormatted)->toBe('ok')
        ->and($row->suspect)->toBeFalse()
        ->and($row->swap->source)->toBe('manual')
        ->and($row->swap->due)->toBe('2028-01-01')
        ->and($row->severity)->toBe(Severity::Ok)
        ->and($row->sensors)->toHaveCount(11);
});

it('leaves out devices that are not a UPS', function (): void {
    expect(upsBuilder()->row([upsSensor(1, 'temperature', 30.0), upsSensor(1, 'voltage', 230.0)], null))->toBeNull()
        ->and(upsBuilder()->row([], null))->toBeNull();
});

it('makes a UPS on battery critical', function (): void {
    $row = upsBuilder()->row([
        upsSensor(1, 'runtime', 30.0),
        upsSensor(1, 'state', 5.0, ['sensorType' => 'upsOutputSourceState', 'valueFormatted' => 'Battery', 'severity' => Severity::Critical]),
    ], null);

    expect($row->onBattery)->toBeTrue()
        ->and($row->severity)->toBe(Severity::Critical)
        ->and($row->needsAttention())->toBeTrue();
});

it('treats a failed self-test and bad battery packs as critical', function (): void {
    $row = upsBuilder()->row([
        upsSensor(1, 'runtime', 30.0),
        upsSensor(1, 'state', 2.0, ['sensorType' => 'upsAdvTestDiagnosticsResults', 'valueFormatted' => 'failed', 'severity' => Severity::Unknown]),
        upsSensor(1, 'count', 1.0, ['sensorIndex' => 'upsAdvBatteryNumOfBadBattPacks.0', 'severity' => Severity::Ok]),
    ], null);

    expect($row->selfTest->severity)->toBe(Severity::Critical)
        ->and($row->badPacks->severity)->toBe(Severity::Critical)
        ->and($row->severity)->toBe(Severity::Critical);
});

it('flags a suspect battery as a warning and a missing swap date not at all', function (): void {
    $row = upsBuilder()->row([upsSensor(1, 'runtime', 4.0), upsSensor(1, 'load', 10.0), upsSensor(1, 'charge', 100.0)], null);

    expect($row->suspect)->toBeTrue()
        ->and($row->swap->source)->toBe('none')
        ->and($row->severity)->toBe(Severity::Warning);
});

it('counts the summary cards over all UPSs and filters the ones that need attention', function (): void {
    $sensors = [
        1 => [upsSensor(1, 'runtime', 30.0)],
        2 => [upsSensor(2, 'runtime', 8.0), upsSensor(2, 'state', 5.0, ['sensorType' => 'upsOutputSourceState', 'valueFormatted' => 'Battery'])],
        3 => [upsSensor(3, 'runtime', 50.0)],
        4 => [upsSensor(4, 'runtime', 20.0), upsSensor(4, 'state', 2.0, ['sensorType' => 'upsBatteryStatusState', 'valueFormatted' => 'Low', 'severity' => Severity::Critical])],
        5 => [upsSensor(5, 'voltage', 230.0)],
    ];
    $installed = [1 => '2020-01-01', 3 => '2022-11-01'];

    $all = upsBuilder()->build($sensors, $installed, upsFilters());
    $attention = upsBuilder()->build($sensors, $installed, upsFilters(['attention' => '1']));

    expect($all['total'])->toBe(4)
        ->and($all['cards']['devices'])->toBe(4)
        ->and($all['cards']['on_battery'])->toBe(1)
        ->and($all['cards']['swap_overdue'])->toBe(1)
        ->and($all['cards']['swap_due'])->toBe(1)
        ->and($all['cards']['swap_unknown'])->toBe(2)
        ->and($all['cards']['battery_alarm'])->toBe(1)
        ->and($all['cards']['lowest_runtime']['display_name'])->toBe('UPS 2')
        ->and(array_map(fn ($r) => $r->deviceId, $all['rows']))->toBe([1, 2, 4, 3])
        ->and(array_map(fn ($r) => $r->deviceId, $attention['rows']))->toBe([1, 2, 4, 3])
        ->and($attention['cards']['devices'])->toBe(4);
});

it('keeps UPSs without a problem out of the attention filter', function (): void {
    $sensors = [1 => [upsSensor(1, 'runtime', 30.0)], 2 => [upsSensor(2, 'runtime', 30.0)]];

    $result = upsBuilder()->build($sensors, [1 => '2020-01-01', 2 => '2025-01-01'], upsFilters(['attention' => '1']));

    expect(array_map(fn ($r) => $r->deviceId, $result['rows']))->toBe([1])
        ->and($result['total'])->toBe(1);
});

it('sorts by swap date with unknown dates last, and by values', function (): void {
    $sensors = [
        1 => [upsSensor(1, 'runtime', 30.0, ['hostname' => 'b'])],
        2 => [upsSensor(2, 'runtime', 10.0, ['hostname' => 'a'])],
        3 => [upsSensor(3, 'runtime', 20.0, ['hostname' => 'c'])],
    ];
    $installed = [1 => '2024-01-01', 3 => '2023-01-01'];
    $ids = fn (array $input) => array_map(fn ($r) => $r->deviceId, upsBuilder()->build($sensors, $installed, upsFilters($input))['rows']);

    expect($ids(['sort' => 'swap']))->toBe([3, 1, 2])
        ->and($ids(['sort' => 'swap', 'dir' => 'desc']))->toBe([1, 3, 2])
        ->and($ids(['sort' => 'runtime']))->toBe([2, 3, 1])
        ->and($ids(['sort' => 'hostname']))->toBe([2, 1, 3])
        ->and($ids(['sort' => 'runtime', 'limit' => '10']))->toBe([2, 3, 1]);
});

it('turns a UPS row into the JSON the page reads', function (): void {
    $row = upsBuilder()->row([upsSensor(1, 'runtime', 30.0), upsSensor(1, 'voltage', 230.0, ['sensorDescr' => 'Input'])], '2024-01-01');
    $data = $row->toArray(fn (string $class): string => strtoupper($class));

    expect(array_keys($data))->toBe(['device_id', 'hostname', 'display_name', 'device_url', 'location', 'os', 'device_up', 'severity', 'on_battery', 'suspect', 'runtime', 'charge', 'load', 'temperature', 'battery', 'bad_packs', 'output', 'self_test', 'swap', 'issues', 'sensors'])
        ->and($data['runtime']['value'])->toBe(30.0)
        ->and($data['charge'])->toBeNull()
        ->and($data['swap']['due'])->toBe('2028-01-01')
        ->and($data['sensors'][1])->toMatchArray(['class' => 'voltage', 'label' => 'VOLTAGE', 'sensor_descr' => 'Input']);
});

it('changes only the severity of a row', function (): void {
    $row = makeRow(['sensorType' => 't', 'sensorIndex' => 'i']);
    $changed = $row->withSeverity(Severity::Critical);

    expect($changed->severity)->toBe(Severity::Critical)
        ->and($changed->sensorType)->toBe('t')
        ->and($changed->sensorIndex)->toBe('i')
        ->and($changed->hostname)->toBe($row->hostname);
});

// ---- CSV and weekly report ----

it('exports the UPS overview as CSV', function (): void {
    $row = upsBuilder()->row([upsSensor(1, 'runtime', 12.5), upsSensor(1, 'load', 40.0)], '2024-01-01');
    $csv = new CsvFormatter;

    expect($csv->upsHeader())->toContain('swap_due')
        ->and(count($csv->upsLine($row)))->toBe(count($csv->upsHeader()))
        ->and($csv->upsLine($row))->toContain('12,5', '2024-01-01', '2028-01-01', 'manual')
        ->and($csv->upsToCsv([$row]))->toStartWith("\xEF\xBB\xBFhostname;");
});

it('lists the battery swaps that are due in the weekly report', function (): void {
    $rows = upsBuilder()->build([
        1 => [upsSensor(1, 'runtime', 30.0)],
        2 => [upsSensor(2, 'runtime', 30.0)],
        3 => [upsSensor(3, 'runtime', 30.0)],
    ], [1 => '2022-12-01', 2 => '2020-01-01', 3 => '2025-01-01'], upsFilters())['rows'];

    $due = WeeklyReport::swapsDue($rows, 90);
    $t = upsMailTexts();
    $html = WeeklyReport::html($t, 'https://nms', '', '2026-10-08', ['devices' => 3, 'critical' => 0, 'warning' => 0, 'suspect' => 0], [], [], $due, 90);

    expect(array_map(fn ($r) => $r->deviceId, $due))->toBe([2, 1])
        ->and($html)->toContain('Battery swaps due')
        ->and($html)->toContain('Overdue or due within 90 days.')
        ->and($html)->toContain('/device/1');
});

/** @return array<string, string> The English weekly report texts. */
function upsMailTexts(): array
{
    return (require __DIR__.'/../../lang/en/ups-battery.php')['report_mail'];
}

// ---- insight: battery life, issues, card filters, timeline ----

it('works out how much of the battery life has been used', function (): void {
    expect(BatterySwap::evaluate('2024-10-08', null, null, null, 48, 90, upsNow())->lifeUsed)->toBe(50)
        ->and(BatterySwap::evaluate('2026-10-08', null, null, null, 48, 90, upsNow())->lifeUsed)->toBe(0)
        ->and(BatterySwap::evaluate('2020-10-08', null, null, null, 48, 90, upsNow())->lifeUsed)->toBe(150)
        ->and(BatterySwap::evaluate(null, null, -365 * 1440.0, UPS_NOW, 48, 90, upsNow())->lifeUsed)->toBe(25)
        ->and(BatterySwap::evaluate(null, 1440.0, null, UPS_NOW, 48, 90, upsNow())->lifeUsed)->toBeNull();
});

it('lists why a UPS needs attention, most severe first', function (): void {
    $row = upsBuilder()->row([
        upsSensor(1, 'runtime', 4.0, ['severity' => Severity::Warning]),
        upsSensor(1, 'load', 10.0),
        upsSensor(1, 'charge', 100.0),
        upsSensor(1, 'temperature', 45.0, ['severity' => Severity::Critical]),
        upsSensor(1, 'count', 2.0, ['sensorIndex' => 'upsAdvBatteryNumOfBadBattPacks.0']),
        upsSensor(1, 'state', 3.0, ['sensorType' => 'upsBasicOutputStatus', 'valueFormatted' => 'onBattery', 'severity' => Severity::Warning]),
    ], '2020-01-01');

    expect(array_column($row->issues, 'key'))->toBe(['on_battery', 'temperature', 'bad_packs', 'swap_overdue', 'runtime', 'suspect'])
        ->and($row->issues[2]['n'])->toBe(2)
        ->and($row->issues[3]['n'])->toBeGreaterThan(1000)
        ->and($row->issues[4]['severity'])->toBe('warning');
});

it('has no issues for a healthy UPS and flags one that is unreachable', function (): void {
    $healthy = upsBuilder()->row([upsSensor(1, 'runtime', 30.0)], '2025-01-01');
    $down = upsBuilder()->row([upsSensor(1, 'runtime', 30.0, ['deviceUp' => false])], '2025-01-01');

    expect($healthy->issues)->toBe([])
        ->and($down->issues)->toBe([['key' => 'down', 'severity' => 'warning', 'n' => null]])
        ->and($down->severity)->toBe(Severity::Warning)
        ->and($down->needsAttention())->toBeTrue();
});

it('filters on the summary card that was clicked', function (string $focus, array $expected): void {
    $sensors = [
        1 => [upsSensor(1, 'runtime', 30.0)],
        2 => [upsSensor(2, 'runtime', 30.0), upsSensor(2, 'state', 5.0, ['sensorType' => 'upsOutputSourceState', 'valueFormatted' => 'Battery'])],
        3 => [upsSensor(3, 'runtime', 30.0, ['deviceUp' => false])],
        4 => [upsSensor(4, 'runtime', 30.0), upsSensor(4, 'state', 2.0, ['sensorType' => 'upsBatteryStatusState', 'valueFormatted' => 'Low', 'severity' => Severity::Critical])],
        5 => [upsSensor(5, 'runtime', 30.0)],
    ];
    $installed = [1 => '2020-01-01', 3 => '2025-01-01', 4 => '2025-01-01', 5 => '2022-12-01'];

    $result = upsBuilder()->build($sensors, $installed, upsFilters(['focus' => $focus, 'sort' => 'hostname']));

    expect(array_map(fn ($r) => $r->deviceId, $result['rows']))->toBe($expected)
        ->and($result['cards']['devices'])->toBe(5);
})->with([
    ['on_battery', [2]],
    ['overdue', [1]],
    ['due', [5]],
    ['unknown', [2]],
    ['alarm', [4]],
    ['down', [3]],
]);

it('rejects an unknown card filter', function (): void {
    expect(fn () => upsFilters(['focus' => 'everything']))->toThrow(InvalidArgumentException::class)
        ->and(upsFilters(['focus' => ''])->focus)->toBeNull()
        ->and(upsFilters(['focus' => 'due'])->toArray()['focus'])->toBe('due');
});

it('counts unreachable UPSs and the battery swaps per month for the next 12 months', function (): void {
    $sensors = [
        1 => [upsSensor(1, 'runtime', 30.0, ['deviceUp' => false])],
        2 => [upsSensor(2, 'runtime', 30.0)],
        3 => [upsSensor(3, 'runtime', 30.0)],
        4 => [upsSensor(4, 'runtime', 30.0)],
        5 => [upsSensor(5, 'runtime', 30.0)],
    ];
    // due 2026-10-20 (this month), 2026-12-01, 2026-12-15, overdue, and in 2028 (outside the window)
    $installed = [1 => '2022-10-20', 2 => '2022-12-01', 3 => '2022-12-15', 4 => '2020-01-01', 5 => '2024-01-01'];

    $cards = upsBuilder()->build($sensors, $installed, upsFilters())['cards'];
    $timeline = array_column($cards['swap_timeline'], 'count', 'month');

    expect($cards['down'])->toBe(1)
        ->and(count($cards['swap_timeline']))->toBe(12)
        ->and($cards['swap_timeline'][0]['month'])->toBe('2026-10')
        ->and($cards['swap_timeline'][11]['month'])->toBe('2027-09')
        ->and($timeline['2026-10'])->toBe(1)
        ->and($timeline['2026-11'])->toBe(0)
        ->and($timeline['2026-12'])->toBe(2)
        ->and(array_sum($timeline))->toBe(3);
});

it('exports the battery life used and the issues in the CSV', function (): void {
    $row = upsBuilder()->row([upsSensor(1, 'runtime', 30.0)], '2020-01-01');
    $line = array_combine((new CsvFormatter)->upsHeader(), (new CsvFormatter)->upsLine($row));

    expect($line['battery_life_used'])->toBe('169')
        ->and($line['issues'])->toBe('swap_overdue');
});

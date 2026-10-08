<?php

declare(strict_types=1);

use Drakelid\UpsBattery\Report\ReportRow;
use Drakelid\UpsBattery\Report\Severity;
use Drakelid\UpsBattery\Report\SuspectRule;
use Drakelid\UpsBattery\Report\UpsBuilder;
use Drakelid\UpsBattery\Report\UpsRow;
use Drakelid\UpsBattery\Report\UpsSensorKind;
use Drakelid\UpsBattery\Report\WeeklyReport;

/*
 * The UPS overview against the sensors LibreNMS creates for each UPS OS. Sensor class, type (state name), index,
 * description and state texts are copied from the LibreNMS definitions (resources/definitions/os_discovery/*.yaml
 * and includes/discovery/sensors, LibreNMS 26.x). The OSes in the RFC 1628 case use the standard UPS-MIB
 * (rfc1628_compat): abbups, apc-mgeups, dell-ups, deltaups, eaton-mgeups, eatonupsm2, ge-ups, generex-ups,
 * hpe-rtups, huaweiups, liebert, marathonups, netmanplus, orvaldi-ups, poweralert, webpower and others.
 */

/** One sensor: [class, type, index, descr, value, text, severity]. */
function compatSensor(array $s, int $deviceId = 1): ReportRow
{
    static $id = 1000;
    [$class, $type, $index, $descr, $value] = $s;

    return new ReportRow(
        deviceId: $deviceId,
        hostname: 'ups-'.$deviceId,
        displayName: 'UPS '.$deviceId,
        deviceUrl: '/device/'.$deviceId,
        location: null,
        os: 'test',
        deviceUp: true,
        sensorId: ++$id,
        sensorDescr: $descr,
        value: $value,
        valueFormatted: $s[5] ?? (string) $value,
        unit: '',
        severity: $s[6] ?? Severity::Ok,
        limitLow: null,
        limitLowWarn: null,
        limitWarn: null,
        limitHigh: null,
        lastUpdate: '2026-10-08T12:00:00+00:00',
        sensorClass: $class,
        sensorType: $type,
        sensorIndex: $index,
    );
}

/** @param  array<int, array<int, mixed>>  $sensors */
function compatRow(array $sensors, ?string $installed = null): ?UpsRow
{
    $builder = new UpsBuilder(new SuspectRule, 48, 90, new DateTimeImmutable('2026-10-08T12:00:00+00:00'));

    return $builder->row(array_map(fn (array $s): ReportRow => compatSensor($s), $sensors), $installed);
}

it('reads every UPS OS correctly', function (array $sensors, array $expected): void {
    $row = compatRow($sensors);

    expect($row)->not->toBeNull();
    foreach ($expected as $field => $value) {
        $actual = match ($field) {
            'runtime' => $row->runtime?->value,
            'charge' => $row->charge?->value,
            'on_battery' => $row->onBattery,
            'battery' => $row->battery?->valueFormatted,
            'battery_severity' => $row->battery?->severity,
            'self_test' => $row->selfTest?->valueFormatted,
            'self_test_severity' => $row->selfTest?->severity,
            'swap_source' => $row->swap->source,
            'suspect' => $row->suspect,
        };
        expect($actual)->toBe($value, $field);
    }
})->with([
    'apc on mains' => [[
        ['runtime', 'apc', 'upsAdvBatteryRunTimeRemaining.0', 'Runtime', 45.0],
        ['runtime', 'apc', 'upsAdvBatteryRecommendedReplaceDate.0', 'Battery Recommended Days Remaining', 200000.0],
        ['runtime', 'apc', 'upsBasicBatteryLastReplaceDate.0', 'Last Battery Replacement', -500000.0],
        ['charge', 'apc', '0', 'Battery Charge', 100.0],
        ['state', 'upsBasicOutputStatus', '0', 'Output Status', 2.0, 'onLine'],
        ['state', 'upsAdvBatteryReplaceIndicator', '0', 'UPS Battery Replacement Status', 1.0, 'noBatteryNeedsReplacing'],
        ['state', 'upsAdvTestDiagnosticsResults', '0', 'UPS diagnostics status', 1.0, 'ok'],
    ], ['runtime' => 45.0, 'on_battery' => false, 'battery' => 'noBatteryNeedsReplacing', 'self_test' => 'ok', 'swap_source' => 'ups']],
    'apc on battery' => [[
        ['runtime', 'apc', 'upsAdvBatteryRunTimeRemaining.0', 'Runtime', 30.0],
        ['state', 'upsBasicOutputStatus', '0', 'Output Status', 3.0, 'onBattery', Severity::Warning],
    ], ['on_battery' => true]],
    'rfc1628 (UPS-MIB) on mains' => [[
        ['runtime', 'rfc1628', '100', 'Time on battery', 0.0],
        ['runtime', 'rfc1628', '200', 'Estimated battery time remaining', 30.0],
        ['charge', 'rfc1628', '500', 'Battery charge remaining', 100.0],
        ['load', 'rfc1628', '1', 'Output load', 10.0],
        ['state', 'upsOutputSourceState', '0', 'Output Source', 3.0, 'Normal'],
        ['state', 'upsBatteryStatusState', '0', 'Battery Status', 2.0, 'Normal'],
        ['state', 'upsTestResult', '0', 'UPS Test', 6.0, 'noTestInitiated', Severity::Unknown],
    ], ['runtime' => 30.0, 'on_battery' => false, 'battery' => 'Normal', 'suspect' => false]],
    'rfc1628 on battery' => [[
        ['runtime', 'rfc1628', '100', 'Time on battery', 4.0],
        ['runtime', 'rfc1628', '200', 'Estimated battery time remaining', 20.0],
        ['state', 'upsOutputSourceState', '0', 'Output Source', 5.0, 'Battery', Severity::Critical],
    ], ['runtime' => 20.0, 'on_battery' => true]],
    'rfc1628 without an output source: time on battery decides' => [[
        ['runtime', 'rfc1628', '100', 'Time on battery', 2.0],
        ['runtime', 'rfc1628', '200', 'Estimated battery time remaining', 25.0],
    ], ['runtime' => 25.0, 'on_battery' => true]],
    'eaton-mgeups / apc-mgeups' => [[
        ['charge', 'eaton-mgeups', '0', 'Remaining battery capacity', 98.0],
        ['state', 'upsmgOutputOnBattery', '0', 'Input Status', 2.0, 'On Input Power'],
        ['state', 'upsmgOutputOnByPass', '0', 'Bypass Status', 2.0, 'Not Active'],
    ], ['charge' => 98.0, 'on_battery' => false]],
    'eaton-mgeups on battery' => [[
        ['charge', 'eaton-mgeups', '0', 'Remaining battery capacity', 90.0],
        ['state', 'upsmgOutputOnBattery', '0', 'Input Status', 1.0, 'On Battery', Severity::Critical],
    ], ['on_battery' => true]],
    'eatonups (XUPS-MIB) discharging' => [[
        ['runtime', 'eatonups', '0', 'Runtime', 12.0],
        ['charge', 'eatonups', '1', 'Battery #1', 80.0],
        ['state', 'xupsBattery', '1', 'Battery Status 1', 2.0, 'batteryDischarging', Severity::Critical],
        ['state', 'xupsTest', '1', 'Battery Test Status 1', 3.0, 'failed', Severity::Critical],
    ], ['on_battery' => true, 'battery' => 'batteryDischarging', 'self_test' => 'failed', 'self_test_severity' => Severity::Critical]],
    'eatonupsm2 last battery replacement' => [[
        ['runtime', 'rfc1628', '200', 'Estimated battery time remaining', 40.0],
        ['runtime', 'eatonupsm2', 'xupsBatteryLastReplacedDate.0', 'Last battery replacement', -365 * 1440.0],
    ], ['runtime' => 40.0, 'swap_source' => 'ups_last']],
    'cyberpower' => [[
        ['runtime', 'cyberpower', 'upsAdvanceBatteryRunTimeRemaining.0', 'Battery Runtime', 35.0],
        ['charge', 'cyberpower', 'upsAdvanceBatteryCapacity.0', 'Battery Capacity', 100.0],
        ['state', 'upsBaseOutputStatus', '0', 'Output Status', 2.0, 'Online'],
        ['state', 'upsAdvanceBatteryReplaceIndicator', '0', 'Battery Replace Indicator', 2.0, 'Replace', Severity::Critical],
        ['state', 'upsAdvanceInputLineFailCause', '0', 'Input Line Cause', 4.0, 'Self Test', Severity::Warning],
    ], ['on_battery' => false, 'battery' => 'Replace', 'battery_severity' => Severity::Critical, 'self_test' => null]],
    'netagent2' => [[
        ['runtime', 'netagent2', '0', 'Estimated Runtime', 50.0],
        ['state', 'upsBaseBatteryStatus', '0', 'Battery Status', 2.0, 'BatteryNormal'],
        ['state', 'upsSmartBatteryReplaceIndicator', '0', 'Battery Replace Status', 2.0, 'batteryNeedsReplacing', Severity::Critical],
    ], ['battery' => 'batteryNeedsReplacing', 'on_battery' => null]],
    'socomec-ups' => [[
        ['runtime', 'socomec-ups', 'upsEstimatedMinutesRemaining.1', 'Battery #1 Minutes remaining', 20.0],
        ['runtime', 'socomec-ups', 'upsSecondsOnBattery.1', 'Battery #1 Runtime seconds', 0.0],
        ['charge', 'socomec-ups', '1', 'Battery #1 Charge remaining', 100.0],
        ['state', 'upsBatteryStatus', '1', 'Battery #1 Status', 2.0, 'Normal'],
        ['state', 'upsOutputSource', '1', 'Output #1 Source', 3.0, 'On Inverter'],
    ], ['runtime' => 20.0, 'on_battery' => false, 'battery' => 'Normal']],
    'webpower-smart2' => [[
        ['runtime', 'webpower-smart2', 'upsBatteryGroupEstimatedMinutesRemaining.1', 'Battery #1 Minutes remaining', 33.0],
        ['runtime', 'webpower-smart2', 'upsBatteryGroupSecondsOnBattery.1', 'Runtime on Battery #1', 0.0],
        ['state', 'upsBatteryTest', '1', 'UPS Test #1 Result', 1.0, 'OK'],
        ['state', 'upsBatteryGroupStatus', '1', 'UPS Battery #1 Status', 2.0, 'Normal'],
    ], ['runtime' => 33.0, 'on_battery' => false, 'self_test' => 'OK', 'battery' => 'Normal']],
    'powerwalker on battery' => [[
        ['runtime', 'powerwalker', '0', 'Battery time remaining', 15.0],
        ['charge', 'powerwalker', '0', 'Battery charge remaining', 70.0],
        ['state', 'upsESystemStatus', '0', 'System Status', 5.0, 'battery', Severity::Critical],
    ], ['on_battery' => true]],
    'powerwalker battery test' => [[
        ['runtime', 'powerwalker', '0', 'Battery time remaining', 15.0],
        ['state', 'upsESystemStatus', '0', 'System Status', 6.0, 'battery-test', Severity::Warning],
    ], ['on_battery' => false]],
    'vertiv-ita2' => [[
        ['runtime', 'vertiv-ita2', '0', 'Estimated Battery Runtime', 25.0],
        ['state', 'upsOutputSource', '1', 'Output PWR Source 1', 4.0, 'on Utility and Battery'],
    ], ['on_battery' => false]],
    'vertiv-ita2 on battery' => [[
        ['runtime', 'vertiv-ita2', '0', 'Estimated Battery Runtime', 25.0],
        ['state', 'upsOutputSource', '1', 'Output PWR Source 1', 2.0, 'On Battery', Severity::Warning],
    ], ['on_battery' => true]],
    'algcom-dc-ups' => [[
        ['charge', 'algcom-dc-ups', '0', 'Battery charge', 95.0],
        ['state', 'alarmOnBattery', '0', 'Operation Mode', 0.0, 'AC'],
        ['state', 'chargerStatus', '0', 'Charger Status', 5.0, 'CHARGING_FLOAT'],
    ], ['on_battery' => false, 'battery' => 'CHARGING_FLOAT']],
    'imcopower-ls110' => [[
        ['charge', 'imcopower-ls110', '0', 'Battery Charge', 60.0],
        ['state', 'opto3', '0', 'Power state', 2.0, 'Battery', Severity::Critical],
    ], ['on_battery' => true]],
    'eltek-webpower (DC power system)' => [[
        ['runtime', 'eltek-webpower', '0', 'Battery time to disconnect', 161.0],
        ['state', 'batteryBank2Status', '2', 'Battery Bank 2 Status', 1.0, 'error', Severity::Critical],
    ], ['runtime' => 161.0, 'battery' => 'error', 'battery_severity' => Severity::Critical]],
    'NUT (Linux host, SNMP extend) on mains' => [[
        ['runtime', 'ups-nut', '3', 'Time Remaining', 25.0],
        ['charge', 'ups-nut', '1', 'Battery Charge', 100.0],
        ['state', 'UPSOnLine', '0', 'UPS on line', 1.0, 'True'],
        ['state', 'UPSOnBattery', '1', 'UPS on battery', 0.0, 'False'],
        ['state', 'UPSLowBattery', '2', 'UPS low battery', 0.0, 'False'],
    ], ['runtime' => 25.0, 'on_battery' => false, 'battery' => 'OK']],
    'NUT on battery with a low battery' => [[
        ['runtime', 'ups-nut', '3', 'Time Remaining', 3.0],
        ['state', 'UPSOnLine', '0', 'UPS on line', 0.0, 'False', Severity::Warning],
        ['state', 'UPSOnBattery', '1', 'UPS on battery', 1.0, 'True', Severity::Warning],
        ['state', 'UPSLowBattery', '2', 'UPS low battery', 1.0, 'True', Severity::Critical],
    ], ['on_battery' => true, 'battery' => 'UPS low battery', 'battery_severity' => Severity::Critical]],
    'fxm / logmaster: minutes on battery is no runtime' => [[
        ['runtime', 'fxm', 'upsMinutesOnBattery.1', 'Minutes on battery', 0.0],
        ['runtime', 'logmaster', 'upsSecondsOnBattery.0', 'Time running on battery', 0.0],
        ['charge', 'logmaster', '0', 'Battery charge remaining', 100.0],
    ], ['runtime' => null, 'charge' => 100.0, 'on_battery' => false, 'suspect' => null]],
]);

it('does not treat routers, coolers or uptimes as UPSs', function (array $sensors): void {
    expect(compatRow($sensors))->toBeNull();
})->with([
    'rutos router' => [[['runtime', 'rutos-rutx', '1', 'Connection Uptime ', 3000.0]]],
    'apc-inrow cooler' => [[
        ['runtime', 'apc', 'airIRRP100UnitRunHoursAirFilter.0', 'Filter', 1200.0],
        ['runtime', 'apc', 'airIRRP100UnitRunHoursFan1.0', 'Fan 1', 9000.0],
    ]],
    'ericsson radio' => [[['runtime', 'ericsson-tn', '1', 'Errored Seconds: ge-1/1/1', 0.0]]],
    'vrp switch' => [[['runtime', 'vrp', '1', 'Uptime', 99999.0]]],
    'a battery state alone' => [[['state', 'raidBattery', '0', 'Battery Status', 1.0, 'ok']]],
]);

it('classifies the runtime-class sensors that are no remaining battery time', function (string $type, string $index, string $descr, UpsSensorKind $kind): void {
    expect(UpsSensorKind::of('runtime', $type, $index, $descr))->toBe($kind);
})->with([
    ['rfc1628', '100', 'Time on battery', UpsSensorKind::OnBatteryTime],
    ['rfc1628', '200', 'Estimated battery time remaining', UpsSensorKind::Runtime],
    ['socomec-ups', 'upsSecondsOnBattery.1', 'Battery #1 Runtime seconds', UpsSensorKind::OnBatteryTime],
    ['webpower-smart2', 'upsBatteryGroupSecondsOnBattery.1', 'Runtime on Battery #1', UpsSensorKind::OnBatteryTime],
    ['fxm', 'upsMinutesOnBattery.1', 'Minutes on battery', UpsSensorKind::OnBatteryTime],
    ['eatonupsm2', 'xupsBatteryLastReplacedDate.0', 'Last battery replacement', UpsSensorKind::ReplacedAt],
    ['apc', 'upsBasicBatteryLastReplaceDate.0', 'Last Battery Replacement', UpsSensorKind::ReplacedAt],
    ['apc', 'upsAdvBatteryRecommendedReplaceDate.0', 'Battery Recommended Days Remaining', UpsSensorKind::ReplaceDue],
    ['apc', 'airIRRP100UnitRunHoursAirFilter.0', 'Filter', UpsSensorKind::Other],
    ['apc-inrow', '0', 'RuntimeAirFilter', UpsSensorKind::Other],
    ['enexus', '0', 'Battery test duration', UpsSensorKind::Other],
    ['baicells-od04', '0', 'Connection time', UpsSensorKind::Other],
    ['ups-nut', '3', 'Time Remaining', UpsSensorKind::Runtime],
    ['cxc', '0', 'Estimated Battery Runtime', UpsSensorKind::Runtime],
]);

it('keeps the SQL exclusion list in step with the classification', function (): void {
    $like = function (string $pattern, string $value): bool {
        $regex = '/^'.str_replace('%', '.*', preg_quote($pattern, '/')).'$/i';

        return preg_match($regex, $value) === 1;
    };
    $excluded = fn (string $index): bool => array_filter(UpsSensorKind::NOT_RUNTIME_INDEX_PATTERNS, fn (string $p): bool => $like($p, $index)) !== [];

    foreach (['upsAdvBatteryRecommendedReplaceDate.0', 'upsBasicBatteryLastReplaceDate.0', 'xupsBatteryLastReplacedDate.0', 'upsSecondsOnBattery.1', 'upsBatteryGroupSecondsOnBattery.1', 'upsMinutesOnBattery.1', 'airIRRP100UnitRunHoursFan1.0'] as $index) {
        expect($excluded($index))->toBeTrue($index)
            ->and(UpsSensorKind::of('runtime', 'x', $index, ''))->not->toBe(UpsSensorKind::Runtime, $index);
    }

    foreach (['upsAdvBatteryRunTimeRemaining.0', 'upsEstimatedMinutesRemaining.1', '200', '3'] as $index) {
        expect($excluded($index))->toBeFalse($index);
    }
});

it('builds the weekly report lists from the UPS overview', function (): void {
    $builder = new UpsBuilder(new SuspectRule, 48, 90, new DateTimeImmutable('2026-10-08T12:00:00+00:00'));
    $rows = array_filter([
        $builder->row([compatSensor(['runtime', 'apc', 'r', 'Runtime', 40.0], 1)], null),
        $builder->row([compatSensor(['runtime', 'apc', 'r', 'Runtime', 4.0], 2), compatSensor(['load', 'apc', 'l', 'Load', 10.0], 2), compatSensor(['charge', 'apc', 'c', 'Charge', 100.0], 2)], null),
        $builder->row([compatSensor(['charge', 'apc', 'c', 'Charge', 50.0], 3)], null),
    ]);

    $runtimes = WeeklyReport::runtimeRows($rows);
    $suspect = WeeklyReport::suspectRows($rows);

    expect(array_map(fn (ReportRow $r): int => $r->deviceId, $runtimes))->toBe([2, 1])
        ->and($suspect)->toHaveCount(1)
        ->and($suspect[0]->deviceId)->toBe(2)
        ->and(array_keys($suspect[0]->cells))->toBe(['runtime', 'load', 'charge'])
        ->and($suspect[0]->suspect)->toBeTrue();
});

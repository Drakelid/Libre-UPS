<?php

declare(strict_types=1);

use Drakelid\UpsBattery\Report\BatterySwap;
use Drakelid\UpsBattery\Report\Severity;
use Drakelid\UpsBattery\Report\UpsBuilder;
use Drakelid\UpsBattery\Report\UpsLabels;

it('says where the load is powered from in plain words', function (bool $up, ?bool $onBattery, ?string $text, string $expected): void {
    expect(UpsLabels::status($up, $onBattery, $text))->toBe($expected);
})->with([
    [true, true, 'onBattery', 'on_battery'],
    [true, false, 'onLine', 'on_mains'],
    [true, false, 'On Input Power', 'on_mains'],
    [true, false, 'switchedBypass', 'bypass'],
    [true, false, 'by-pass', 'bypass'],
    [true, false, 'onBatteryTest', 'battery_test'],
    [true, false, 'off', 'off'],
    [true, false, 'timedSleeping', 'off'],
    [true, null, null, 'unknown'],
    [true, false, null, 'on_mains'],
    [false, true, 'onBattery', 'unreachable'],
]);

it('translates vendor battery states into plain words', function (?string $text, ?string $expected): void {
    expect(UpsLabels::battery($text))->toBe($expected);
})->with([
    ['noBatteryNeedsReplacing', 'ok'],
    ['batteryNeedsReplacing', 'replace'],
    ['Replace', 'replace'],
    ['No', 'ok'],
    ['BatteryNormal', 'ok'],
    ['Normal', 'ok'],
    ['OK', 'ok'],
    ['batteryFloating', 'ok'],
    ['CHARGING_FLOAT', 'ok'],
    ['batteryDischarging', 'discharging'],
    ['Low', 'low'],
    ['UPS low battery', 'low'],
    ['UPS the battery needs to be replaced', 'replace'],
    ['Depleted', 'depleted'],
    ['BATTERY_DISCONNECTED', 'disconnected'],
    ['Charging', 'charging'],
    ['checkBattery', 'fault'],
    ['error', 'fault'],
    ['WRONG_BATTERY_VOLTAGE', 'fault'],
    ['batteryUnderTest', null],
    ['', null],
    [null, null],
]);

it('translates self-test results into plain words', function (?string $text, ?string $expected): void {
    expect(UpsLabels::selfTest($text))->toBe($expected);
})->with([
    ['ok', 'passed'],
    ['OK', 'passed'],
    ['passed', 'passed'],
    ['failed', 'failed'],
    ['Error', 'failed'],
    ['testInProgress', 'running'],
    ['inProgress', 'running'],
    ['noTestInitiated', 'not_run'],
    ['noData', 'not_run'],
    ['invalidTest', 'not_run'],
    ['Aborted', 'aborted'],
    ['Warning', 'warning'],
    ['unknown', null],
]);

it('tells whether a reading is too low or too high', function (): void {
    expect(UpsLabels::direction(makeRow(['sensorClass' => 'runtime', 'value' => 5.0])))->toBe('low')
        ->and(UpsLabels::direction(makeRow(['sensorClass' => 'load', 'value' => 95.0])))->toBe('high')
        ->and(UpsLabels::direction(makeRow(['sensorClass' => 'temperature', 'value' => 2.0, 'limitLow' => 5.0])))->toBe('low')
        ->and(UpsLabels::direction(makeRow(['sensorClass' => 'temperature', 'value' => 45.0, 'limitWarn' => 40.0])))->toBe('high')
        ->and(UpsLabels::direction(makeRow(['sensorClass' => 'charge', 'value' => 101.0, 'limitHigh' => 100.0])))->toBe('high');
});

it('writes clear issue keys with the value they are about', function (): void {
    $swap = new BatterySwap('2020-01-01', '2024-01-01', 0, 'manual', Severity::Critical);
    $issues = UpsBuilder::issues(true, false, false, $swap, [
        'runtime' => makeRow(['sensorClass' => 'runtime', 'value' => 8.0, 'valueFormatted' => '8 min', 'severity' => Severity::Critical]),
        'charge' => null,
        'load' => makeRow(['sensorClass' => 'load', 'value' => 92.0, 'valueFormatted' => '92 %', 'severity' => Severity::Warning]),
        'temperature' => null,
        'battery' => makeRow(['sensorClass' => 'state', 'valueFormatted' => 'batteryNeedsReplacing', 'severity' => Severity::Critical]),
        'self_test' => makeRow(['sensorClass' => 'state', 'valueFormatted' => 'failed', 'severity' => Severity::Critical]),
    ], null);
    $byKey = array_column($issues, null, 'key');

    expect(array_keys($byKey))->toBe(['runtime_low', 'battery_replace', 'self_test_failed', 'swap_today', 'load_high'])
        ->and($byKey['runtime_low']['value'])->toBe('8 min')
        ->and($byKey['load_high']['value'])->toBe('92 %')
        ->and($byKey['battery_replace']['value'])->toBe('batteryNeedsReplacing');
});

it('falls back to the vendor text for states it does not know', function (): void {
    $none = new BatterySwap(null, null, null, 'none', Severity::Unknown);
    $issues = UpsBuilder::issues(true, null, null, $none, [
        'battery' => makeRow(['sensorClass' => 'state', 'valueFormatted' => 'batteryUnderTest', 'severity' => Severity::Warning]),
        'self_test' => makeRow(['sensorClass' => 'state', 'valueFormatted' => 'invalidTest', 'severity' => Severity::Warning]),
    ], null);

    expect(array_column($issues, 'key'))->toBe(['battery', 'self_test'])
        ->and(array_column($issues, 'value'))->toBe(['batteryUnderTest', 'invalidTest']);
});

it('gives every issue, status and label key a text in both languages', function (): void {
    foreach (['en', 'nb'] as $locale) {
        $texts = require __DIR__.'/../../lang/'.$locale.'/ups-battery.php';
        $issueKeys = ['on_battery', 'down', 'runtime_low', 'runtime_high', 'charge_low', 'charge_high', 'load_high', 'load_low',
            'temperature_high', 'temperature_low', 'battery_replace', 'battery_low', 'battery_depleted', 'battery_discharging',
            'battery_disconnected', 'battery_fault', 'battery', 'self_test_failed', 'self_test', 'bad_packs', 'suspect',
            'swap_overdue', 'swap_today', 'swap_due', 'more'];

        expect(array_diff($issueKeys, array_keys($texts['issues'])))->toBe([], "issues in $locale")
            ->and(array_diff(UpsLabels::STATUSES, array_keys($texts['status'])))->toBe([], "status in $locale")
            ->and(array_diff(UpsLabels::STATUSES, array_keys($texts['status_help'])))->toBe([], "status_help in $locale")
            ->and(array_diff(UpsLabels::BATTERY, array_keys($texts['battery_state'])))->toBe([], "battery_state in $locale")
            ->and(array_diff(UpsLabels::SELF_TEST, array_keys($texts['self_test_state'])))->toBe([], "self_test_state in $locale");
    }
});

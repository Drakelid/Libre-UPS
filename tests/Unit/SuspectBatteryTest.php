<?php

declare(strict_types=1);

use Drakelid\UpsBattery\Report\SuspectBattery;
use Drakelid\UpsBattery\Report\SuspectRule;

it('flags a short runtime at low load and full charge', function (): void {
    expect(SuspectBattery::evaluate(new SuspectRule, 6.0, 20.0, 100.0))->toBeTrue();
});

it('does not flag a short runtime at high load', function (): void {
    expect(SuspectBattery::evaluate(new SuspectRule, 6.0, 85.0, 100.0))->toBeFalse();
});

it('does not flag a short runtime while the battery is still charging', function (): void {
    expect(SuspectBattery::evaluate(new SuspectRule, 6.0, 20.0, 60.0))->toBeFalse();
});

it('does not flag a healthy runtime', function (): void {
    expect(SuspectBattery::evaluate(new SuspectRule, 25.0, 20.0, 100.0))->toBeFalse();
});

it('uses the limits as documented: runtime strictly below, load and charge inclusive', function (): void {
    $rule = new SuspectRule(10.0, 30.0, 95.0);

    expect(SuspectBattery::evaluate($rule, 9.99, 30.0, 95.0))->toBeTrue()
        ->and(SuspectBattery::evaluate($rule, 10.0, 30.0, 95.0))->toBeFalse()
        ->and(SuspectBattery::evaluate($rule, 5.0, 30.01, 95.0))->toBeFalse()
        ->and(SuspectBattery::evaluate($rule, 5.0, 30.0, 94.99))->toBeFalse();
});

it('flags zero runtime', function (): void {
    expect(SuspectBattery::evaluate(new SuspectRule, 0.0, 10.0, 100.0))->toBeTrue();
});

it('cannot judge without runtime or load', function (): void {
    expect(SuspectBattery::evaluate(new SuspectRule, null, 20.0, 100.0))->toBeNull()
        ->and(SuspectBattery::evaluate(new SuspectRule, 5.0, null, 100.0))->toBeNull();
});

it('does not let a missing charge value block the verdict', function (): void {
    expect(SuspectBattery::evaluate(new SuspectRule, 5.0, 10.0, null))->toBeTrue();
});

it('uses the configured rule', function (): void {
    $strict = new SuspectRule(30.0, 50.0, 80.0);

    expect(SuspectBattery::evaluate($strict, 25.0, 45.0, 85.0))->toBeTrue()
        ->and(SuspectBattery::evaluate(new SuspectRule, 25.0, 45.0, 85.0))->toBeFalse();
});

it('judges a device from its shortest runtime, highest load and lowest charge', function (): void {
    $rows = [
        makeRow(['sensorId' => 1, 'sensorClass' => 'runtime', 'value' => 30.0]),
        makeRow(['sensorId' => 2, 'sensorClass' => 'runtime', 'value' => 6.0]),
        makeRow(['sensorId' => 3, 'sensorClass' => 'load', 'value' => 10.0]),
        makeRow(['sensorId' => 4, 'sensorClass' => 'load', 'value' => 20.0]),
        makeRow(['sensorId' => 5, 'sensorClass' => 'charge', 'value' => 100.0]),
        makeRow(['sensorId' => 6, 'sensorClass' => 'temperature', 'value' => 99.0]),
    ];

    expect(SuspectBattery::evaluateRows(new SuspectRule, $rows))->toBeTrue();
});

it('lets the highest load on a device decide', function (): void {
    $rows = [
        makeRow(['sensorId' => 1, 'sensorClass' => 'runtime', 'value' => 6.0]),
        makeRow(['sensorId' => 2, 'sensorClass' => 'load', 'value' => 10.0]),
        makeRow(['sensorId' => 3, 'sensorClass' => 'load', 'value' => 90.0]),
    ];

    expect(SuspectBattery::evaluateRows(new SuspectRule, $rows))->toBeFalse();
});

it('cannot judge a device without runtime or load sensors', function (): void {
    $rows = [makeRow(['sensorId' => 1, 'sensorClass' => 'charge', 'value' => 100.0])];

    expect(SuspectBattery::evaluateRows(new SuspectRule, $rows))->toBeNull()
        ->and(SuspectBattery::evaluateRows(new SuspectRule, []))->toBeNull();
});

it('ignores sensors without a value', function (): void {
    $rows = [
        makeRow(['sensorId' => 1, 'sensorClass' => 'runtime', 'value' => null]),
        makeRow(['sensorId' => 2, 'sensorClass' => 'load', 'value' => 10.0]),
    ];

    expect(SuspectBattery::evaluateRows(new SuspectRule, $rows))->toBeNull();
});

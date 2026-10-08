<?php

declare(strict_types=1);

use Drakelid\UpsBattery\Report\AlertRuleHint;
use Drakelid\UpsBattery\Report\Thresholds;

it('has nothing to show without thresholds', function (): void {
    expect(AlertRuleHint::forThresholds(Thresholds::empty()))->toBe([]);
});

it('turns a critical-only rule into one alert rule', function (): void {
    $hints = AlertRuleHint::forThresholds(Thresholds::parse('charge <20'));

    expect($hints)->toBe([[
        'name' => 'UPS Battery: charge critical',
        'severity' => 'critical',
        'rule' => 'macros.device_up = 1 AND sensors.sensor_class = "charge" AND sensors.sensor_current < 20',
    ]]);
});

it('keeps the warning rule out of the critical range when low values are bad', function (): void {
    $hints = AlertRuleHint::forThresholds(Thresholds::parse('runtime <10 <20'));

    expect($hints)->toHaveCount(2)
        ->and($hints[0]['rule'])->toBe('macros.device_up = 1 AND sensors.sensor_class = "runtime" AND sensors.sensor_current < 10')
        ->and($hints[1]['name'])->toBe('UPS Battery: runtime warning')
        ->and($hints[1]['severity'])->toBe('warning')
        ->and($hints[1]['rule'])->toBe('macros.device_up = 1 AND sensors.sensor_class = "runtime" AND sensors.sensor_current < 20 AND sensors.sensor_current >= 10');
});

it('keeps the warning rule out of the critical range when high values are bad', function (): void {
    $hints = AlertRuleHint::forThresholds(Thresholds::parse('load >90 >75'));

    expect($hints[0]['rule'])->toEndWith('sensors.sensor_current > 90')
        ->and($hints[1]['rule'])->toEndWith('sensors.sensor_current > 75 AND sensors.sensor_current <= 90');
});

it('writes numbers without trailing zeros', function (): void {
    $hints = AlertRuleHint::forThresholds(Thresholds::parse('runtime <7.50 <12.0'));

    expect($hints[0]['rule'])->toEndWith('< 7.5')
        ->and($hints[1]['rule'])->toEndWith('< 12 AND sensors.sensor_current >= 7.5');
});

it('lists every metric in the order of the settings', function (): void {
    $hints = AlertRuleHint::forThresholds(Thresholds::parse("runtime <10\nload >90 >75"));

    expect(array_column($hints, 'name'))->toBe([
        'UPS Battery: runtime critical',
        'UPS Battery: load critical',
        'UPS Battery: load warning',
    ]);
});

it('matches how the page colours rows: the warning rule never overlaps the critical rule', function (): void {
    // runtime <10 <20: 10 is a warning (not critical), 9.99 critical, 20 ok.
    $rules = Thresholds::parse('runtime <10 <20');
    $hints = AlertRuleHint::forThresholds($rules);

    $cases = [[9.99, true, false], [10.0, false, true], [19.99, false, true], [20.0, false, false]];

    foreach ($cases as [$value, $critical, $warning]) {
        // The two alert rules above, evaluated by hand for this value:
        $criticalHit = $value < 10;
        $warningHit = $value < 20 && $value >= 10;

        expect([$criticalHit, $warningHit])->toBe([$critical, $warning])
            ->and($rules->severity('runtime', $value)?->value)->toBe($critical ? 'critical' : ($warning ? 'warning' : 'ok'))
            ->and($hints)->toHaveCount(2);
    }
});

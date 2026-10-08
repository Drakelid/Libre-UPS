<?php

declare(strict_types=1);

use Drakelid\UpsBattery\Report\PluginSettings;
use Drakelid\UpsBattery\Report\SavedViews;

it('has the suspect battery limits from the rule by default', function (): void {
    $rule = PluginSettings::fromArray([])->suspectRule;

    expect($rule->maxRuntime)->toBe(10.0)
        ->and($rule->maxLoad)->toBe(30.0)
        ->and($rule->minCharge)->toBe(95.0);
});

it('reads and bounds the suspect battery limits', function (): void {
    $rule = PluginSettings::fromArray(['suspect_runtime' => '15', 'suspect_max_load' => '40,5', 'suspect_min_charge' => '90'])->suspectRule;

    expect($rule->maxRuntime)->toBe(15.0)
        ->and($rule->maxLoad)->toBe(40.5)
        ->and($rule->minCharge)->toBe(90.0);

    $bad = PluginSettings::fromArray(['suspect_runtime' => '0', 'suspect_max_load' => '101', 'suspect_min_charge' => 'x'])->suspectRule;

    expect($bad->maxRuntime)->toBe(10.0)
        ->and($bad->maxLoad)->toBe(30.0)
        ->and($bad->minCharge)->toBe(95.0)
        ->and(PluginSettings::fromArray(['suspect_runtime' => ['1']])->suspectRule->maxRuntime)->toBe(10.0);
});

it('reads the weekly report settings', function (): void {
    $s = PluginSettings::fromArray([
        'report_enabled' => '1',
        'report_recipients' => 'ops@example.com, boss@example.com',
        'report_day' => '5',
        'report_time' => '16:30',
        'report_top' => '25',
    ]);

    expect($s->report->isActive())->toBeTrue()
        ->and($s->report->recipients)->toBe(['ops@example.com', 'boss@example.com'])
        ->and($s->report->day)->toBe(5)
        ->and($s->report->time)->toBe('16:30')
        ->and($s->report->top)->toBe(25)
        ->and(PluginSettings::fromArray([])->report->isActive())->toBeFalse();
});

it('saves the sensor name and suspect filters in a view but not the kiosk flag', function (): void {
    $views = SavedViews::add([], 'Worn', ['view' => 'matrix', 'sensor' => 'battery', 'suspect' => '1', 'kiosk' => '1', 'classes' => 'runtime,load']);

    expect($views[0]['query'])->toBe(['view' => 'matrix', 'classes' => 'runtime,load', 'sensor' => 'battery', 'suspect' => '1']);
});

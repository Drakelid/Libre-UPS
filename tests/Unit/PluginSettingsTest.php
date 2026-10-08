<?php

declare(strict_types=1);

use Drakelid\UpsBattery\Report\PluginSettings;

it('uses safe defaults when nothing has been saved', function (): void {
    $s = PluginSettings::fromArray([]);

    expect($s->defaultType)->toBeNull()
        ->and($s->topNav)->toBeTrue()
        ->and($s->defaultClass)->toBe('runtime')
        ->and($s->defaultLimit)->toBe(25)
        ->and($s->refreshSeconds)->toBe(300)
        ->and($s->staleMinutes)->toBe(30)
        ->and($s->language)->toBe('en')
        ->and($s->thresholds->has('runtime'))->toBeFalse();
});

it('keeps an emptied device type as "all types"', function (mixed $saved): void {
    expect(PluginSettings::fromArray(['default_type' => $saved])->defaultType)->toBeNull();
})->with([null, '', '   ']);

it('falls back to all device types for an invalid device type', function (): void {
    expect(PluginSettings::fromArray(['default_type' => 'bad type!'])->defaultType)->toBeNull()
        ->and(PluginSettings::fromArray(['default_type' => ['x']])->defaultType)->toBeNull()
        ->and(PluginSettings::fromArray(['default_type' => ' power '])->defaultType)->toBe('power');
});

it('shows the top navigation entry unless it is turned off', function (): void {
    expect(PluginSettings::fromArray(['top_nav' => '0'])->topNav)->toBeFalse()
        ->and(PluginSettings::fromArray(['top_nav' => 0])->topNav)->toBeFalse()
        ->and(PluginSettings::fromArray(['top_nav' => '1'])->topNav)->toBeTrue()
        ->and(PluginSettings::fromArray(['top_nav' => null])->topNav)->toBeTrue();
});

it('normalises and validates the default class', function (): void {
    expect(PluginSettings::fromArray(['default_class' => ' Load '])->defaultClass)->toBe('load')
        ->and(PluginSettings::fromArray(['default_class' => 'bad class!'])->defaultClass)->toBe('runtime')
        ->and(PluginSettings::fromArray(['default_class' => null])->defaultClass)->toBe('runtime')
        ->and(PluginSettings::fromArray(['default_class' => ''])->defaultClass)->toBe('runtime');
});

it('validates the default limit', function (): void {
    expect(PluginSettings::fromArray(['default_limit' => '50'])->defaultLimit)->toBe(50)
        ->and(PluginSettings::fromArray(['default_limit' => 0])->defaultLimit)->toBe(0)
        ->and(PluginSettings::fromArray(['default_limit' => '33'])->defaultLimit)->toBe(25)
        ->and(PluginSettings::fromArray(['default_limit' => 'many'])->defaultLimit)->toBe(25);
});

it('bounds the refresh interval and allows turning it off', function (): void {
    expect(PluginSettings::fromArray(['refresh_seconds' => '0'])->refreshSeconds)->toBe(0)
        ->and(PluginSettings::fromArray(['refresh_seconds' => -5])->refreshSeconds)->toBe(0)
        ->and(PluginSettings::fromArray(['refresh_seconds' => '10'])->refreshSeconds)->toBe(30)
        ->and(PluginSettings::fromArray(['refresh_seconds' => '120'])->refreshSeconds)->toBe(120)
        ->and(PluginSettings::fromArray(['refresh_seconds' => '99999'])->refreshSeconds)->toBe(3600)
        ->and(PluginSettings::fromArray(['refresh_seconds' => 'abc'])->refreshSeconds)->toBe(300)
        ->and(PluginSettings::fromArray(['refresh_seconds' => null])->refreshSeconds)->toBe(300);
});

it('bounds the stale minutes', function (): void {
    expect(PluginSettings::fromArray(['stale_minutes' => '60'])->staleMinutes)->toBe(60)
        ->and(PluginSettings::fromArray(['stale_minutes' => '0'])->staleMinutes)->toBe(30)
        ->and(PluginSettings::fromArray(['stale_minutes' => '2000'])->staleMinutes)->toBe(30)
        ->and(PluginSettings::fromArray(['stale_minutes' => 'x'])->staleMinutes)->toBe(30);
});

it('only accepts known languages', function (): void {
    expect(PluginSettings::fromArray(['language' => 'nb'])->language)->toBe('nb')
        ->and(PluginSettings::fromArray(['language' => 'xx'])->language)->toBe('en')
        ->and(PluginSettings::fromArray(['language' => ['nb']])->language)->toBe('en');
});

it('parses thresholds and ignores non-string values', function (): void {
    expect(PluginSettings::fromArray(['thresholds' => 'runtime <10 <20'])->thresholds->has('runtime'))->toBeTrue()
        ->and(PluginSettings::fromArray(['thresholds' => ['x']])->thresholds->has('x'))->toBeFalse()
        ->and(PluginSettings::fromArray(['thresholds' => null])->thresholds->errors())->toBe([]);
});

it('exposes the filter defaults', function (): void {
    $s = PluginSettings::fromArray(['default_type' => null, 'default_class' => 'load', 'default_limit' => '10']);

    expect($s->filterDefaults())->toBe(['type' => null, 'class' => 'load', 'limit' => 10]);
});

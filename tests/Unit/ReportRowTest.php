<?php

declare(strict_types=1);

use Drakelid\UpsBattery\Report\Severity;

it('fills presentation fields without touching the data', function (): void {
    $light = makeRow(['valueFormatted' => '', 'unit' => '', 'deviceUrl' => '', 'value' => 12.5, 'severity' => Severity::Warning, 'sensorClass' => 'runtime', 'hydrated' => false]);

    $full = $light->withDisplay('12m', 'min', '/device/1', '/device/1/health/runtime', '/graph?id=1', '/graphs/type=sensor_runtime/id=1/from=-1y');

    expect($full->valueFormatted)->toBe('12m')
        ->and($full->unit)->toBe('min')
        ->and($full->deviceUrl)->toBe('/device/1')
        ->and($full->sensorUrl)->toBe('/device/1/health/runtime')
        ->and($full->graphUrl)->toBe('/graph?id=1')
        ->and($full->trendUrl)->toBe('/graphs/type=sensor_runtime/id=1/from=-1y')
        ->and($full->value)->toBe(12.5)
        ->and($full->severity)->toBe(Severity::Warning)
        ->and($full->sensorId)->toBe($light->sensorId)
        ->and($full->sensorClass)->toBe('runtime')
        ->and($light->valueFormatted)->toBe('');
});

it('marks a row as hydrated only through withDisplay, so an empty formatted value is not mistaken for "not loaded"', function (): void {
    $light = makeRow(['valueFormatted' => '', 'hydrated' => false]);
    $full = $light->withDisplay('', '', '', '', '', '');

    expect($light->hydrated)->toBeFalse()
        ->and($full->hydrated)->toBeTrue()
        ->and($full->valueFormatted)->toBe('');
});

it('is hydrated by default', function (): void {
    expect(makeRow()->hydrated)->toBeTrue();
});

it('serialises every field for the JSON api', function (): void {
    $array = makeRow(['sensorUrl' => '/s', 'graphUrl' => '/g', 'trendUrl' => '/t'])->toArray();

    expect(array_keys($array))->toBe([
        'device_id', 'hostname', 'display_name', 'device_url', 'location', 'os', 'device_up',
        'sensor_id', 'sensor_descr', 'sensor_url', 'graph_url', 'trend_url', 'value', 'value_formatted', 'unit',
        'severity', 'limits', 'last_updated',
    ])
        ->and($array['sensor_url'])->toBe('/s')
        ->and($array['graph_url'])->toBe('/g')
        ->and($array['trend_url'])->toBe('/t')
        ->and(array_keys($array['limits']))->toBe(['low', 'low_warn', 'warn', 'high']);
});

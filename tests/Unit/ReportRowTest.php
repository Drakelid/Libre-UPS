<?php

declare(strict_types=1);

use Drakelid\UpsBattery\Report\Severity;

it('fills presentation fields without touching the data', function (): void {
    $light = makeRow(['valueFormatted' => '', 'unit' => '', 'deviceUrl' => '', 'value' => 12.5, 'severity' => Severity::Warning]);

    $full = $light->withDisplay('12m', 'min', '/device/1', '/device/1/health/runtime', '/graph?id=1');

    expect($full->valueFormatted)->toBe('12m')
        ->and($full->unit)->toBe('min')
        ->and($full->deviceUrl)->toBe('/device/1')
        ->and($full->sensorUrl)->toBe('/device/1/health/runtime')
        ->and($full->graphUrl)->toBe('/graph?id=1')
        ->and($full->value)->toBe(12.5)
        ->and($full->severity)->toBe(Severity::Warning)
        ->and($full->sensorId)->toBe($light->sensorId)
        ->and($light->valueFormatted)->toBe('');
});

it('serialises every field for the JSON api', function (): void {
    $array = makeRow(['sensorUrl' => '/s', 'graphUrl' => '/g'])->toArray();

    expect(array_keys($array))->toBe([
        'device_id', 'hostname', 'display_name', 'device_url', 'location', 'os', 'device_up',
        'sensor_id', 'sensor_descr', 'sensor_url', 'graph_url', 'value', 'value_formatted', 'unit',
        'severity', 'limits', 'last_updated',
    ])
        ->and($array['sensor_url'])->toBe('/s')
        ->and($array['graph_url'])->toBe('/g')
        ->and(array_keys($array['limits']))->toBe(['low', 'low_warn', 'warn', 'high']);
});

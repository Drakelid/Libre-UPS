<?php

declare(strict_types=1);

use Drakelid\UpsBattery\Report\Urls;

it('drops scheme and host but keeps path and query', function (): void {
    expect(Urls::relative('https://librenms.example/device/12?tab=health'))->toBe('/device/12?tab=health')
        ->and(Urls::relative('http://localhost:8000/graph?type=sensor_runtime&id=5'))->toBe('/graph?type=sensor_runtime&id=5');
});

it('keeps a sub-directory install prefix', function (): void {
    expect(Urls::relative('https://example.com/librenms/device/3/health/metric=runtime/'))
        ->toBe('/librenms/device/3/health/metric=runtime/');
});

it('leaves relative urls alone', function (): void {
    expect(Urls::relative('/device/12'))->toBe('/device/12')
        ->and(Urls::relative('/device/12?x=1'))->toBe('/device/12?x=1');
});

it('returns the input when there is no path', function (): void {
    expect(Urls::relative('https://librenms.example'))->toBe('https://librenms.example');
});

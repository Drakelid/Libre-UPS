<?php

declare(strict_types=1);

use Drakelid\UpsBattery\Report\CsvFormatter;
use Drakelid\UpsBattery\Report\SuspectRule;
use Drakelid\UpsBattery\Report\UpsBuilder;
use Drakelid\UpsBattery\Report\UpsFilters;
use Drakelid\UpsBattery\Report\UpsVendor;

it('names the manufacturer of every UPS OS', function (string $os, ?string $icon, ?string $text, ?string $expected): void {
    expect(UpsVendor::name($os, $icon, $text))->toBe($expected);
})->with([
    ['apc', 'images/os/apc.svg', 'APC Management Module', 'APC'],
    ['eatonups', 'images/os/eaton.svg', 'Eaton UPS', 'Eaton'],
    ['eatonupsm2', 'images/os/eaton.svg', 'Eaton UPS', 'Eaton'],
    ['eaton-mgeups', 'images/os/eaton.svg', 'Eaton UPS', 'Eaton'],
    ['cyberpower', 'images/os/cyberpower.svg', 'Cyberpower', 'CyberPower'],
    ['liebert', 'images/os/vertiv.svg', 'Liebert', 'Vertiv'],
    ['netmanplus', 'images/os/riello.png', 'NetMan Plus', 'Riello'],
    ['poweralert', 'images/os/tripplite.svg', 'Tripp Lite PowerAlert', 'Tripp Lite'],
    ['socomec-ups', 'images/os/socomecpdu.svg', 'Socomec UPS', 'Socomec'],
    ['eltek-webpower', 'images/os/eltek.png', 'Eltek WebPower', 'Eltek'],
    ['powerwalker', 'images/os/powerwalker.svg', 'PowerWalker UPS', 'PowerWalker'],
    'unknown OS with a known brand icon' => ['newvendor-ups', 'images/os/eaton.svg', 'Something', 'Eaton'],
    'unknown OS: its display text' => ['acme-ups', 'images/os/generic.svg', 'Acme UPS', 'Acme UPS'],
    'unknown OS without text' => ['acme-ups', null, null, 'Acme-ups'],
    'NUT on a Linux host: the UPS vendor is unknown' => ['linux', 'images/os/linux.svg', 'Linux', null],
]);

it('takes the model from the hardware LibreNMS reports', function (): void {
    expect(UpsVendor::model(' Smart-UPS SRT 3000 '))->toBe('Smart-UPS SRT 3000')
        ->and(UpsVendor::model(''))->toBeNull()
        ->and(UpsVendor::model(null))->toBeNull();
});

it('carries manufacturer, model and logo into the rows and sorts by them', function (): void {
    $builder = new UpsBuilder(new SuspectRule, 48, 90, new DateTimeImmutable('2026-10-08T12:00:00+00:00'));
    $sensors = [
        1 => [makeRow(['deviceId' => 1, 'hostname' => 'a', 'sensorClass' => 'runtime', 'value' => 30.0])],
        2 => [makeRow(['deviceId' => 2, 'hostname' => 'b', 'sensorClass' => 'runtime', 'value' => 30.0])],
        3 => [makeRow(['deviceId' => 3, 'hostname' => 'c', 'sensorClass' => 'runtime', 'value' => 30.0])],
    ];
    $devices = [
        1 => ['manufacturer' => 'Eaton', 'model' => '9PX 3000', 'logo' => '/images/os/eaton.svg'],
        2 => ['manufacturer' => 'APC', 'model' => 'Smart-UPS 1500', 'logo' => '/images/logos/apc.svg'],
        3 => [],
    ];

    $result = $builder->build($sensors, [], UpsFilters::fromArray(['sort' => 'manufacturer'], []), $devices);
    $first = $result['rows'][0];
    $data = $first->toArray(fn (string $c): string => $c);

    expect(array_map(fn ($r) => $r->deviceId, $result['rows']))->toBe([2, 1, 3])
        ->and($data['manufacturer'])->toBe('APC')
        ->and($data['model'])->toBe('Smart-UPS 1500')
        ->and($data['logo_url'])->toBe('/images/logos/apc.svg')
        ->and($result['rows'][2]->manufacturer)->toBeNull()
        ->and(array_map(fn ($r) => $r->deviceId, $builder->build($sensors, [], UpsFilters::fromArray(['sort' => 'model'], []), $devices)['rows']))->toBe([1, 2, 3]);

    $line = array_combine((new CsvFormatter)->upsHeader(), (new CsvFormatter)->upsLine($first));
    expect($line['manufacturer'])->toBe('APC')
        ->and($line['model'])->toBe('Smart-UPS 1500');
});

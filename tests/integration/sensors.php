<?php

declare(strict_types=1);

use App\Models\Device;
use Drakelid\UpsBattery\Report\PluginSettings;
use Drakelid\UpsBattery\Report\ReportFilters;
use Drakelid\UpsBattery\Report\SensorReportService;
use Drakelid\UpsBattery\Report\UpsFilters;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager;
use Illuminate\Events\Dispatcher;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use Illuminate\Routing\UrlGenerator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Facade;
use Illuminate\Translation\ArrayLoader;
use Illuminate\Translation\Translator;

require __DIR__.'/bootstrap.php';

// Exercise the public service with actual LibreNMS models and an isolated database, never the installation's DB.
$app = new Container;
Container::setInstance($app);
Facade::setFacadeApplication($app);
$app->instance('config', new Repository);
$app->instance('translator', new Translator(new ArrayLoader, 'en'));
$app->instance('librenms-config', new class
{
    public function get(string $key, mixed $default = null): mixed
    {
        return $key === 'base_url' ? '' : $default;
    }
});
$router = new Router(new Dispatcher($app), $app);
$router->get('device/{id}')->name('device');
$router->get('graph')->name('graph');
$router->get('graphs/{path?}')->name('graphs');
$router->getRoutes()->refreshNameLookups();
$app->instance('url', new UrlGenerator($router->getRoutes(), Request::create('https://librenms.test')));
$app->alias('url', Illuminate\Contracts\Routing\UrlGenerator::class);

$db = new Manager($app);
$db->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
$db->bootEloquent();
$connection = $db->getConnection();
foreach ([
    'CREATE TABLE devices (device_id INTEGER PRIMARY KEY, hostname TEXT, sysName TEXT, display TEXT, os TEXT, type TEXT, status INTEGER, disabled INTEGER, location_id INTEGER, hardware TEXT)',
    'CREATE TABLE locations (id INTEGER PRIMARY KEY, location TEXT)',
    'CREATE TABLE devices_attribs (attrib_id INTEGER PRIMARY KEY, device_id INTEGER, attrib_type TEXT, attrib_value TEXT)',
    'CREATE TABLE sensors (sensor_id INTEGER PRIMARY KEY, device_id INTEGER, sensor_deleted INTEGER, sensor_class TEXT, sensor_type TEXT, sensor_index TEXT, sensor_descr TEXT, sensor_current REAL, sensor_limit REAL, sensor_limit_warn REAL, sensor_limit_low REAL, sensor_limit_low_warn REAL, lastupdate TEXT, rrd_type TEXT)',
    'CREATE TABLE sensors_to_state_indexes (sensor_id INTEGER, state_index_id INTEGER)',
    'CREATE TABLE state_translations (state_translation_id INTEGER PRIMARY KEY, state_index_id INTEGER)',
] as $sql) {
    $connection->statement($sql);
}
foreach ([1 => 'date-only-ups', 2 => 'router', 3 => 'runtime-ups', 4 => 'disabled-ups'] as $id => $hostname) {
    $connection->table('devices')->insert(['device_id' => $id, 'hostname' => $hostname, 'os' => 'apc', 'type' => 'power', 'status' => 1, 'disabled' => $id === 4 ? 1 : 0]);
}
$sensor = ['sensor_deleted' => 0, 'sensor_class' => 'runtime', 'sensor_type' => 'apc', 'sensor_descr' => 'Runtime', 'lastupdate' => '2026-10-08 12:00:00'];
$connection->table('sensors')->insert($sensor + ['sensor_id' => 1, 'device_id' => 1, 'sensor_index' => 'upsBasicBatteryLastReplaceDate.0', 'sensor_current' => -365 * 24 * 60]);
$connection->table('sensors')->insert(array_replace($sensor, ['sensor_id' => 2, 'device_id' => 1, 'sensor_index' => 'upsAdvBatteryRecommendedReplaceDate.0', 'sensor_current' => null, 'lastupdate' => '2025-10-08 12:00:00']));
$connection->table('sensors')->insert($sensor + ['sensor_id' => 3, 'device_id' => 2, 'sensor_index' => 'sysUpTime.0', 'sensor_current' => 600]);
$connection->table('sensors')->insert($sensor + ['sensor_id' => 4, 'device_id' => 3, 'sensor_index' => 'upsAdvBatteryRunTimeRemaining.0', 'sensor_current' => 30]);
$connection->table('sensors')->insert($sensor + ['sensor_id' => 5, 'device_id' => 4, 'sensor_index' => 'upsBasicBatteryLastReplaceDate.0', 'sensor_current' => -1440]);

Carbon::setTestNow('2026-10-08 12:00:00');
$settings = PluginSettings::fromArray([]);
$service = new SensorReportService;

integrationCheck('A9 UPS overview includes battery-date-only devices', function () use ($service, $settings): void {
    $result = $service->ups(null, UpsFilters::fromArray([], []), $settings);
    $rows = array_column($result['rows'], null, 'deviceId');
    integrationExpect(isset($rows[1]), 'The UPS with only battery-date sensors was omitted.');
    integrationExpect($rows[1]->runtime === null && $rows[1]->swap->source === 'ups_last', 'A battery date was used as remaining runtime.');
    integrationExpect(isset($rows[3]) && ! isset($rows[2]) && ! isset($rows[4]) && $result['total'] === 2, 'The overview included an uptime-only or disabled device.');
});

integrationCheck('A9 runtime reports still exclude battery dates', function () use ($service, $settings): void {
    $result = $service->report(null, ReportFilters::fromArray(['class' => 'runtime', 'q' => 'date-only-ups'], []), $settings->thresholds);
    integrationExpect($result['total'] === 0, 'Battery dates leaked into remaining runtime.');
});

integrationCheck('A5 device card uses the valid replacement sensor timestamp', function () use ($service, $settings): void {
    $swap = $service->deviceSwap(Device::findOrFail(1), $settings);
    integrationExpect($swap->installed === '2025-10-08' && $swap->due === '2029-10-08', 'The null due-date sensor shifted the replacement date: '.json_encode($swap->toArray()));
});

integrationFinish();

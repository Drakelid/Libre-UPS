<?php

declare(strict_types=1);

use App\Models\Device;
use App\Models\Sensor;
use App\Models\User;
use App\Policies\DevicePolicy;
use App\Policies\SensorPolicy;
use Drakelid\UpsBattery\Hooks\DeviceOverview;
use Drakelid\UpsBattery\Hooks\MenuEntry;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\Access\Gate;
use Illuminate\Auth\Middleware\Authorize;
use Illuminate\Auth\RequestGuard;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Contracts\Auth\Access\Gate as GateContract;
use Illuminate\Contracts\Auth\Factory;
use Illuminate\Database\Capsule\Manager;
use Illuminate\Events\Dispatcher;
use Illuminate\Http\Request;
use Illuminate\Routing\CallableDispatcher;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Facade;
use LibreNMS\Interfaces\Plugins\PluginManagerInterface;
use Symfony\Component\HttpFoundation\Response;

require __DIR__.'/bootstrap.php';

// Role assignments and device grants are fixtures; the actual LibreNMS policies and plugin routes run below.
class AccessUser extends User
{
    public array $testPermissions = [];

    public array $testRoles = [];

    public function hasRole($roles, ?string $guard = null): bool
    {
        return in_array($roles, $this->testRoles, true);
    }

    public function hasPermissionTo($permission, $guardName = null): bool
    {
        return in_array($permission, $this->testPermissions, true);
    }
}

$app = new Container;
Container::setInstance($app);
Facade::setFacadeApplication($app);
$app->instance('config', new Repository);
$app->instance(PluginManagerInterface::class, new IntegrationPluginManager(true));
$user = new AccessUser;
$user->testPermissions = ['device.view'];
$gate = new Gate($app, fn () => $user);
$gate->policy(Device::class, DevicePolicy::class);
$gate->policy(Sensor::class, SensorPolicy::class);
$gate->before(fn (AccessUser $user, string $ability): ?bool => $user->hasRole('admin')
    || ($user->hasRole('global-read') && preg_match('/^(\S+\.)?view(All|Any)?$/', $ability)) ? true : null);
$app->instance(GateContract::class, $gate);
$app->instance('permissions', new class
{
    public function canAccessDevice(Device|int $device, ?User $user = null): bool
    {
        return ($device instanceof Device ? (int) $device->device_id : $device) === 1;
    }
});
$app->instance(Factory::class, new RequestGuard(fn () => $user, Request::create('/')));

$db = new Manager($app);
$db->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
$db->bootEloquent();
$db->getConnection()->statement('CREATE TABLE sensors (sensor_id INTEGER PRIMARY KEY, device_id INTEGER, sensor_deleted INTEGER, sensor_class TEXT, sensor_type TEXT, sensor_index TEXT, sensor_descr TEXT)');
foreach ([1, 2] as $id) {
    $db->getConnection()->table('sensors')->insert(['sensor_id' => $id, 'device_id' => $id, 'sensor_deleted' => 0, 'sensor_class' => 'runtime', 'sensor_type' => 'apc', 'sensor_index' => 'runtime.0', 'sensor_descr' => 'Runtime']);
}

$router = new Router(new Dispatcher($app), $app);
$app->instance('router', $router);
$app->bind(Illuminate\Routing\Contracts\CallableDispatcher::class, CallableDispatcher::class);
// Requests represent authenticated sessions. Session/CSRF behavior belongs to LibreNMS's web middleware.
$router->middlewareGroup('web', []);
$router->middlewareGroup('auth', []);
$router->aliasMiddleware('can', Authorize::class);
require dirname(__DIR__, 2).'/routes/web.php';

foreach ($router->getRoutes() as $route) {
    // Keep the real route middleware while isolating authorization from rendering and persistence.
    $action = $route->getAction();
    unset($action['controller']);
    $action['uses'] = fn (): Response => new Response('authorized');
    $route->setAction($action);
}

foreach (['GET report', 'GET options', 'GET data', 'GET data?format=csv', 'GET matrix', 'GET matrix?format=csv', 'GET ups', 'GET ups?format=csv', 'GET views', 'GET assets/report.js', 'POST views', 'POST views/delete', 'POST battery'] as $endpoint) {
    [$method, $path] = explode(' ', $endpoint);
    integrationCheck('A1 denied sensor permission: '.$endpoint, function () use ($router, $method, $path): void {
        try {
            $router->dispatch(Request::create('/plugin/ups-battery/'.$path, $method));
        } catch (AuthorizationException) {
            return;
        }
        throw new RuntimeException('A user without sensor permission reached the endpoint.');
    });
}

$device = new Device;
$device->device_id = 1;
$otherDevice = new Device;
$otherDevice->device_id = 2;
integrationCheck('A1 device card denies sensor access', fn () => integrationExpect(! (new DeviceOverview)->authorize($user, $device), 'The card disclosed sensors.'));
integrationCheck('A1 menu hides a forbidden report', fn () => integrationExpect(! (new MenuEntry)->authorize(), 'The menu still advertises a forbidden report.'));

foreach (['sensor.view', 'sensor.update'] as $permission) {
    $user->testPermissions = ['device.view', $permission];
    integrationCheck('A1 allowed '.$permission, fn () => integrationExpect($router->dispatch(Request::create('/plugin/ups-battery/data'))->getStatusCode() === 200, 'A permitted user was rejected.'));
}

$user->testRoles = ['user'];
integrationCheck('A1 ordinary user retains access', fn () => integrationExpect($router->dispatch(Request::create('/plugin/ups-battery/data'))->getStatusCode() === 200, 'The standard user role was rejected.'));
integrationCheck('A1 device card respects the device grant', fn () => integrationExpect(! (new DeviceOverview)->authorize($user, $otherDevice), 'The card ignored the device grant.'));
integrationCheck('A1 device card allows the assigned UPS', fn () => integrationExpect((new DeviceOverview)->authorize($user, $device), 'The assigned UPS card was rejected.'));

foreach (['admin', 'global-read'] as $role) {
    $user->testPermissions = [];
    $user->testRoles = [$role];
    integrationCheck('A1 '.$role.' retains access', fn () => integrationExpect($router->dispatch(Request::create('/plugin/ups-battery/data'))->getStatusCode() === 200, 'The global role was rejected.'));
}

integrationFinish();

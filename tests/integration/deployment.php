<?php

declare(strict_types=1);

// Uses a LibreNMS checkout's dependencies without booting it or connecting to its database.
// LIBRENMS_DIR=/opt/librenms php tests/integration/deployment.php
use Drakelid\UpsBattery\Http\Middleware\EnsurePluginEnabled;
use Drakelid\UpsBattery\UpsBatteryProvider;
use Illuminate\Config\Repository;
use Illuminate\Events\Dispatcher;
use Illuminate\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Pipeline\Pipeline;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Facade;
use LibreNMS\Interfaces\Plugins\PluginManagerInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

$librenms = getenv('LIBRENMS_DIR') ?: '/opt/librenms';
if (! is_file($librenms.'/vendor/autoload.php')) {
    fwrite(STDERR, "Set LIBRENMS_DIR to a LibreNMS checkout with Composer dependencies.\n");
    exit(2);
}

$loader = require $librenms.'/vendor/autoload.php';
$loader->addPsr4('Drakelid\\UpsBattery\\', dirname(__DIR__, 2).'/src', true);

final class DeploymentPluginManager implements PluginManagerInterface
{
    public function __construct(public bool $enabled, public array $settings = []) {}

    public function publishHook(string $pluginName, string $hookType, string $implementationClass): bool
    {
        return $this->enabled;
    }

    public function hasHooks(string $hookType, array $args = [], ?string $plugin = null): bool
    {
        return false;
    }

    public function call(string $hookType, array $args = [], ?string $plugin = null): array
    {
        return [];
    }

    public function getSettings(string $pluginName): array
    {
        return $this->settings;
    }

    public function setSettings(string $pluginName, array $settings): bool
    {
        $this->settings = $settings;

        return true;
    }

    public function pluginExists(string $pluginName): bool
    {
        return true;
    }

    public function pluginEnabled(string $pluginName): bool
    {
        return $this->enabled;
    }

    public function cleanupPlugins(): void {}
}

function deploymentApp(DeploymentPluginManager $plugins, ?array $compiled = null): Application
{
    $app = new class(dirname(__DIR__, 2)) extends Application
    {
        public bool $cached = false;

        public function routesAreCached(): bool
        {
            return $this->cached;
        }
    };
    $app->cached = $compiled !== null;
    $app->instance('config', new Repository(['app' => ['env' => 'testing', 'timezone' => 'UTC']]));
    $app->instance('events', new Dispatcher($app));
    $app->instance('router', new Router($app['events'], $app));
    $app->instance(PluginManagerInterface::class, $plugins);
    Facade::clearResolvedInstances();
    Facade::setFacadeApplication($app);

    if ($compiled !== null) {
        $app['router']->setCompiledRoutes($compiled);
    }
    (new UpsBatteryProvider($app))->boot($plugins);
    if ($compiled === null) {
        $app['router']->getRoutes()->refreshNameLookups();
    }

    return $app;
}

function deploymentCheck(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
}

try {
    $checks = 0;
    // A cache built in either state must support both subsequent enable/disable transitions.
    foreach ([true, false] as $enabledWhenCached) {
        $initial = deploymentApp(new DeploymentPluginManager($enabledWhenCached));
        $compiled = $initial['router']->getRoutes()->compile();
        foreach ([$compiled, null] as $cache) {
            foreach ([false, true] as $enabled) {
                $app = deploymentApp(new DeploymentPluginManager($enabled), $cache);
                $routes = $app['router']->getRoutes();
                foreach (['report', 'data', 'matrix', 'ups', 'battery', 'options', 'script', 'views', 'views.save', 'views.delete'] as $name) {
                    $route = $routes->getByName('ups-battery.'.$name);
                    deploymentCheck($route !== null, "Missing $name route after enable/disable transition.");
                    $request = Request::create('/'.$route->uri(), $route->methods()[0]);
                    $reached = false;
                    // Authentication and device authorization have their own integration tests.
                    $middleware = array_values(array_intersect($route->gatherMiddleware(), [EnsurePluginEnabled::class]));
                    try {
                        $response = (new Pipeline($app))->send($request)->through($middleware)->then(function () use (&$reached): Response {
                            $reached = true;

                            return new Response('controller reached', 200);
                        });
                        $status = $response->getStatusCode();
                    } catch (HttpExceptionInterface $e) {
                        $status = $e->getStatusCode();
                    }
                    deploymentCheck($status === ($enabled ? 200 : 404) && $reached === $enabled,
                        "$name: enabled=".(int) $enabled.', cached='.(int) ($cache !== null)." returned $status.");
                    $checks++;
                }
            }
        }
    }
    echo "PASS: $checks enabled/disabled route checks, including compiled route caches.\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'FAIL: '.$e->getMessage()."\n".$e->getTraceAsString()."\n");
    exit(1);
}

<?php

declare(strict_types=1);

// Uses a LibreNMS checkout's dependencies without booting it or connecting to its database.
// LIBRENMS_DIR=/opt/librenms php tests/integration/deployment.php
use Drakelid\UpsBattery\Http\Middleware\EnsurePluginEnabled;
use Drakelid\UpsBattery\UpsBatteryProvider;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository as CacheRepository;
use Illuminate\Config\Repository;
use Illuminate\Console\OutputStyle;
use Illuminate\Console\Scheduling\CacheEventMutex;
use Illuminate\Console\Scheduling\CacheSchedulingMutex;
use Illuminate\Console\Scheduling\EventMutex;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Console\Scheduling\ScheduleRunCommand;
use Illuminate\Console\Scheduling\SchedulingMutex;
use Illuminate\Console\View\Components\Factory as ConsoleComponents;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Events\Dispatcher;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Exceptions\Handler;
use Illuminate\Http\Request;
use Illuminate\Pipeline\Pipeline;
use Illuminate\Routing\Router;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Facade;
use LibreNMS\Interfaces\Plugins\PluginManagerInterface;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;
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

        public function isDownForMaintenance(): bool
        {
            return false;
        }
    };
    $app->cached = $compiled !== null;
    $app->instance('config', new Repository(['app' => ['env' => 'testing', 'timezone' => 'UTC']]));
    $app->instance('env', 'testing');
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

function scheduledDeliveries(CacheFactory $cache, bool $enabled, array $settings): int
{
    $app = deploymentApp(new DeploymentPluginManager($enabled, $settings));
    $app->instance(EventMutex::class, new CacheEventMutex($cache));
    $app->instance(SchedulingMutex::class, new CacheSchedulingMutex($cache));
    $schedule = $app->make(Schedule::class);
    $command = new class extends ScheduleRunCommand
    {
        public int $deliveries = 0;

        public function prepare(Application $app): void
        {
            $this->setLaravel($app);
            $input = new ArrayInput([], $this->getDefinition());
            $this->setInput($input);
            $this->setOutput(new OutputStyle($input, new NullOutput));
            $this->components = new ConsoleComponents($this->output);
        }

        protected function runEvent($event): void
        {
            // Replace only the final subprocess/mail boundary; Laravel decides which server may run it.
            deploymentCheck(str_contains($event->command, 'ups-battery:report'), 'Unexpected scheduled command.');
            $this->deliveries++;
        }
    };
    $command->prepare($app);
    $command->handle($schedule, $app['events'], $cache->store(), new Handler($app));

    return $command->deliveries;
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

    // Independent scheduler instances use Laravel's real mutex and the same shared cache store.
    $cache = new class implements CacheFactory
    {
        private CacheRepository $repository;

        public function __construct()
        {
            $this->repository = new CacheRepository(new ArrayStore);
        }

        public function store($name = null): CacheRepository
        {
            return $this->repository;
        }
    };
    $settings = ['report_enabled' => '1', 'report_recipients' => 'noc@example.com'];
    Carbon::setTestNow(Carbon::parse('2026-10-19 07:00:00', 'UTC'));
    try {
        $first = scheduledDeliveries($cache, true, $settings);
        $second = scheduledDeliveries($cache, true, $settings);
        deploymentCheck($first === 1 && $second === 0, "Two schedulers delivered $first + $second reports; expected 1 + 0.");
        deploymentCheck(scheduledDeliveries($cache, false, $settings) === 0, 'A disabled plugin scheduled mail.');
        deploymentCheck(scheduledDeliveries($cache, true, ['report_enabled' => '0'] + $settings) === 0, 'A disabled report scheduled mail.');
        deploymentCheck(scheduledDeliveries($cache, true, ['report_recipients' => ''] + $settings) === 0, 'A report without recipients scheduled mail.');
        Carbon::setTestNow(Carbon::parse('2026-10-26 07:00:00', 'UTC'));
        deploymentCheck(scheduledDeliveries($cache, true, $settings) === 1, 'The next weekly report was suppressed.');
    } finally {
        Carbon::setTestNow();
    }
    echo "PASS: shared scheduler mutex permits one delivery and the next weekly report; disabled reports stay off.\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'FAIL: '.$e->getMessage()."\n".$e->getTraceAsString()."\n");
    exit(1);
}

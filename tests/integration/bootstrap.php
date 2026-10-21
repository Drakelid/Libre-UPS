<?php

declare(strict_types=1);
use LibreNMS\Interfaces\Plugins\PluginManagerInterface;

// Load the installed framework and models without booting LibreNMS or reading its database configuration.
$librenms = rtrim((string) getenv('LIBRENMS_DIR'), '/\\');
if (! is_file($librenms.'/vendor/autoload.php')) {
    fwrite(STDERR, "Set LIBRENMS_DIR to a LibreNMS checkout with Composer dependencies.\n");
    exit(2);
}

$loader = require $librenms.'/vendor/autoload.php';
$loader->addPsr4('Drakelid\\UpsBattery\\', dirname(__DIR__, 2).'/src/', true);

final class IntegrationPluginManager implements PluginManagerInterface
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

$integrationPassed = 0;
$integrationFailed = 0;

function integrationCheck(string $name, callable $test): void
{
    global $integrationPassed, $integrationFailed;

    try {
        $test();
        $integrationPassed++;
        echo "PASS $name\n";
    } catch (Throwable $e) {
        $integrationFailed++;
        fwrite(STDERR, "FAIL $name: ".$e->getMessage()."\n");
    }
}

function integrationExpect(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
}

function integrationFinish(): never
{
    global $integrationPassed, $integrationFailed;

    echo "$integrationPassed passed, $integrationFailed failed\n";
    exit($integrationFailed === 0 ? 0 : 1);
}

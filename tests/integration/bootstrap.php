<?php

declare(strict_types=1);

// Load the installed framework and models without booting LibreNMS or reading its database configuration.
$librenms = rtrim((string) getenv('LIBRENMS_DIR'), '/\\');
if (! is_file($librenms.'/vendor/autoload.php')) {
    fwrite(STDERR, "Set LIBRENMS_DIR to a LibreNMS checkout with Composer dependencies.\n");
    exit(2);
}

$loader = require $librenms.'/vendor/autoload.php';
$loader->addPsr4('Drakelid\\UpsBattery\\', dirname(__DIR__, 2).'/src/', true);

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

<?php

declare(strict_types=1);

use Illuminate\Filesystem\Filesystem;
use Illuminate\View\Compilers\BladeCompiler;

/** @return string[] */
function bladeViews(): array
{
    return glob(__DIR__.'/../../resources/views/*.blade.php') ?: [];
}

it('compiles every Blade view into valid PHP', function (): void {
    expect(bladeViews())->not->toBeEmpty();

    foreach (bladeViews() as $view) {
        $php = (new BladeCompiler(new Filesystem, sys_get_temp_dir()))->compileString((string) file_get_contents($view));

        try {
            token_get_all($php, TOKEN_PARSE);
        } catch (ParseError $e) {
            throw new RuntimeException(basename($view).': '.$e->getMessage().' on line '.$e->getLine(), 0, $e);
        }
    }

    expect(true)->toBeTrue();
});

it('has a view file for every view the plugin renders', function (): void {
    foreach (['report', 'menu', 'settings', 'device-overview'] as $name) {
        expect(file_exists(__DIR__.'/../../resources/views/'.$name.'.blade.php'))->toBeTrue("missing view $name");
    }

    // The code only renders views from that list.
    $source = (string) file_get_contents(__DIR__.'/../../src/Http/Controllers/ReportController.php')
        .(string) file_get_contents(__DIR__.'/../../src/Hooks/DeviceOverview.php');
    preg_match_all('/ups-battery::([a-z-]+)/', $source, $m);

    expect(array_values(array_diff(array_unique($m[1]), ['report', 'menu', 'settings', 'device-overview'])))->toBe([]);
});

it('only uses route names that routes/web.php defines', function (): void {
    $routes = (string) file_get_contents(__DIR__.'/../../routes/web.php');
    preg_match_all("/->name\('(ups-battery\.[a-z.]+)'\)/", $routes, $defined);

    $used = [];
    $files = array_merge(
        bladeViews(),
        glob(__DIR__.'/../../src/*.php') ?: [],
        glob(__DIR__.'/../../src/*/*.php') ?: [],
        glob(__DIR__.'/../../src/*/*/*.php') ?: [],
    );
    foreach ($files as $file) {
        preg_match_all("/route\('(ups-battery\.[a-z.]+)'/", (string) file_get_contents($file), $m);
        array_push($used, ...$m[1]);
    }

    expect($defined[1])->not->toBeEmpty()
        ->and(array_values(array_diff(array_unique($used), $defined[1])))->toBe([]);
});

it('routes every controller method that exists', function (): void {
    $routes = (string) file_get_contents(__DIR__.'/../../routes/web.php');
    $controller = (string) file_get_contents(__DIR__.'/../../src/Http/Controllers/ReportController.php');

    preg_match_all("/\[ReportController::class, '([a-zA-Z]+)'\]/", $routes, $m);

    expect($m[1])->not->toBeEmpty();

    foreach ($m[1] as $method) {
        expect($controller)->toContain('function '.$method.'(');
    }
});

it('ships the page script the controller serves', function (): void {
    expect(file_exists(__DIR__.'/../../resources/js/report.js'))->toBeTrue()
        ->and((string) file_get_contents(__DIR__.'/../../src/Http/Controllers/ReportController.php'))->toContain('resources/js/report.js');
});

it('gives every element id the script looks up a matching element in the page', function (): void {
    $script = (string) file_get_contents(__DIR__.'/../../resources/js/report.js');
    $page = (string) file_get_contents(__DIR__.'/../../resources/views/report.blade.php');

    preg_match_all("/\\bel\\('([a-z-]+)'\\)/", $script, $m);
    $ids = array_unique($m[1]);

    expect($ids)->not->toBeEmpty();

    $missing = [];
    foreach ($ids as $id) {
        if (! str_contains($page, 'id="'.$id.'"')) {
            $missing[] = $id;
        }
    }

    // Also the ids passed as strings to helper functions, e.g. onText('ub-q', 'q')
    preg_match_all("/onText\\('([a-z-]+)'/", $script, $m2);
    foreach (array_unique($m2[1]) as $id) {
        if (! str_contains($page, 'id="'.$id.'"')) {
            $missing[] = $id;
        }
    }

    expect($missing)->toBe([]);
});

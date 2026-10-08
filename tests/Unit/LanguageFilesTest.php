<?php

declare(strict_types=1);

/**
 * Flattens a translation array into "group.key.subkey" => "text".
 *
 * @param  array<string|int, mixed>  $array
 * @return array<string, string>
 */
function flattenTranslations(array $array, string $prefix = ''): array
{
    $flat = [];
    foreach ($array as $key => $value) {
        $path = $prefix === '' ? (string) $key : $prefix.'.'.$key;
        if (is_array($value)) {
            $flat += flattenTranslations($value, $path);
        } else {
            $flat[$path] = (string) $value;
        }
    }

    return $flat;
}

/** @return string[] The ":placeholders" used in a text, sorted. */
function placeholdersIn(string $text): array
{
    preg_match_all('/:[a-z_]+/i', $text, $matches);
    $found = array_unique($matches[0]);
    sort($found);

    return $found;
}

it('has the same keys in English and Norwegian', function (): void {
    $en = flattenTranslations(require __DIR__.'/../../lang/en/ups-battery.php');
    $nb = flattenTranslations(require __DIR__.'/../../lang/nb/ups-battery.php');

    expect(array_diff_key($en, $nb))->toBe([], 'keys missing in lang/nb')
        ->and(array_diff_key($nb, $en))->toBe([], 'keys missing in lang/en');
});

it('uses the same :placeholders in both languages', function (): void {
    $en = flattenTranslations(require __DIR__.'/../../lang/en/ups-battery.php');
    $nb = flattenTranslations(require __DIR__.'/../../lang/nb/ups-battery.php');

    $mismatches = [];
    foreach ($en as $key => $text) {
        if (isset($nb[$key]) && placeholdersIn($text) !== placeholdersIn($nb[$key])) {
            $mismatches[$key] = [placeholdersIn($text), placeholdersIn($nb[$key])];
        }
    }

    expect($mismatches)->toBe([]);
});

it('has no empty texts', function (): void {
    foreach (['en', 'nb'] as $locale) {
        $texts = flattenTranslations(require __DIR__.'/../../lang/'.$locale.'/ups-battery.php');

        expect(array_keys(array_filter($texts, fn (string $text): bool => trim($text) === '')))->toBe([], "empty texts in lang/$locale");
    }
});

it('provides every key the views and the page script use', function (): void {
    $en = flattenTranslations(require __DIR__.'/../../lang/en/ups-battery.php');

    // $t('key') in the Blade views, trans('ups-battery::ups-battery.key') and T.group.key in report.js
    $used = [];
    foreach (glob(__DIR__.'/../../resources/views/*.blade.php') ?: [] as $view) {
        $source = (string) file_get_contents($view);
        $prefix = str_contains($view, 'settings.blade') ? 'settings.' : '';
        if (preg_match_all('/\$t\(\'([a-z_.]+)\'/', $source, $m)) {
            foreach ($m[1] as $key) {
                $used[] = $prefix.$key;
            }
        }
        if (preg_match_all('/ups-battery::ups-battery\.([a-z_.]+)/', $source, $m)) {
            // "settings." followed by a concatenated key is a prefix, not a key of its own
            array_push($used, ...array_filter($m[1], fn (string $key): bool => ! str_ends_with($key, '.')));
        }
    }

    $missing = [];
    foreach (array_unique($used) as $key) {
        $exists = isset($en[$key]) || count(array_filter(array_keys($en), fn (string $k): bool => str_starts_with($k, $key.'.'))) > 0;
        if (! $exists) {
            $missing[] = $key;
        }
    }

    expect($missing)->toBe([]);
});

it('provides every T.group.key text the page script reads', function (): void {
    $en = flattenTranslations(require __DIR__.'/../../lang/en/ups-battery.php');
    $script = (string) file_get_contents(__DIR__.'/../../resources/js/report.js');

    preg_match_all('/\bT\.([a-z_]+)(?:\.([a-z_]+))?/', $script, $matches, PREG_SET_ORDER);

    $missing = [];
    foreach ($matches as $match) {
        $key = $match[1].(isset($match[2]) && $match[2] !== '' ? '.'.$match[2] : '');
        $exists = isset($en[$key]) || count(array_filter(array_keys($en), fn (string $k): bool => str_starts_with($k, $key.'.'))) > 0;
        // T.aggregate[v] and T.all style lookups are covered by the group check above
        if (! $exists && ! isset($en[$match[1]])) {
            $missing[] = $key;
        }
    }

    expect(array_values(array_unique($missing)))->toBe([]);
});

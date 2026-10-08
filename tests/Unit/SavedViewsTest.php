<?php

declare(strict_types=1);

use Drakelid\UpsBattery\Report\SavedViews;

it('decodes nothing from missing or broken data', function (mixed $stored): void {
    expect(SavedViews::decode($stored))->toBe([]);
})->with([null, '', 'not json', '"a string"', 42]);

it('adds a view and keeps only known query keys with scalar values', function (): void {
    $views = SavedViews::add([], 'Low runtime', [
        'type' => 'power', 'class' => 'runtime', 'limit' => '10',
        'evil' => 'x', 'q' => ['array'], 'sort' => 'value',
    ]);

    expect($views)->toBe([[
        'name' => 'Low runtime',
        'query' => ['type' => 'power', 'class' => 'runtime', 'sort' => 'value', 'limit' => '10'],
    ]]);
});

it('cuts very long query values', function (): void {
    $views = SavedViews::add([], 'x', ['q' => str_repeat('a', 500)]);

    expect(mb_strlen($views[0]['query']['q']))->toBe(200);
});

it('replaces a view with the same name regardless of case', function (): void {
    $views = SavedViews::add([], 'Night', ['type' => 'power']);
    $views = SavedViews::add($views, 'NIGHT', ['type' => 'network']);

    expect($views)->toHaveCount(1)
        ->and($views[0]['name'])->toBe('NIGHT')
        ->and($views[0]['query']['type'])->toBe('network');
});

it('keeps views sorted by name', function (): void {
    $views = SavedViews::add([], 'beta', []);
    $views = SavedViews::add($views, 'Alpha', []);
    $views = SavedViews::add($views, 'gamma', []);

    expect(array_column($views, 'name'))->toBe(['Alpha', 'beta', 'gamma']);
});

it('collapses whitespace and trims the name', function (): void {
    expect(SavedViews::cleanName("  My   view \n"))->toBe('My view');
});

it('rejects empty and too long names', function (): void {
    expect(fn () => SavedViews::add([], '   ', []))->toThrow(InvalidArgumentException::class)
        ->and(fn () => SavedViews::add([], str_repeat('a', 41), []))->toThrow(InvalidArgumentException::class);
});

it('limits the number of views but still allows replacing at the limit', function (): void {
    $views = [];
    for ($i = 1; $i <= SavedViews::MAX_VIEWS; $i++) {
        $views = SavedViews::add($views, "view $i", []);
    }

    expect(fn () => SavedViews::add($views, 'one too many', []))->toThrow(InvalidArgumentException::class)
        ->and(SavedViews::add($views, 'view 3', ['type' => 'x']))->toHaveCount(SavedViews::MAX_VIEWS);
});

it('removes a view regardless of case and ignores unknown names', function (): void {
    $views = SavedViews::add(SavedViews::add([], 'One', []), 'Two', []);

    expect(array_column(SavedViews::remove($views, 'one'), 'name'))->toBe(['Two'])
        ->and(SavedViews::remove($views, 'nope'))->toHaveCount(2);
});

it('round-trips through encode and decode, including numeric names', function (): void {
    $views = SavedViews::add(SavedViews::add([], '1', ['type' => 'power']), '2', ['classes' => 'runtime,load']);

    $decoded = SavedViews::decode(SavedViews::encode($views));

    expect($decoded)->toBe($views)
        ->and(array_column($decoded, 'name'))->toBe(['1', '2']);
});

it('accepts an already decoded array from the preference store', function (): void {
    $views = SavedViews::add([], 'A', ['type' => 'power']);

    expect(SavedViews::decode($views))->toBe($views);
});

it('skips malformed stored items', function (): void {
    $stored = json_encode([
        ['name' => 'ok', 'query' => ['type' => 'power']],
        ['name' => 5, 'query' => []],
        ['query' => []],
        ['name' => 'no query'],
        'string',
    ]);

    expect(array_column(SavedViews::decode($stored), 'name'))->toBe(['ok']);
});

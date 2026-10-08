<?php

declare(strict_types=1);

namespace Drakelid\UpsBattery\Report;

use InvalidArgumentException;

/**
 * Per-user named filter presets. A view is {name, query} where query holds the page's query-string parameters.
 * Stored as a JSON list so numeric-looking names cannot be turned into list keys.
 */
final class SavedViews
{
    public const MAX_VIEWS = 20;

    public const MAX_NAME_LENGTH = 40;

    private const MAX_VALUE_LENGTH = 200;

    /** Query parameters a saved view may contain. */
    private const ALLOWED_KEYS = ['view', 'type', 'class', 'classes', 'os', 'group', 'q', 'sort', 'dir', 'limit', 'aggregate'];

    /**
     * @param  mixed  $stored  JSON string, decoded array or null as returned by the preference store
     * @return array<int, array{name: string, query: array<string, string>}>
     */
    public static function decode(mixed $stored): array
    {
        if (is_string($stored)) {
            $stored = json_decode($stored, true);
        }

        if (! is_array($stored)) {
            return [];
        }

        $views = [];
        foreach ($stored as $item) {
            if (! is_array($item) || ! isset($item['name']) || ! is_string($item['name']) || ! is_array($item['query'] ?? null)) {
                continue;
            }

            $views[] = ['name' => $item['name'], 'query' => self::cleanQuery($item['query'])];
        }

        return $views;
    }

    /** @param  array<int, array{name: string, query: array<string, string>}>  $views */
    public static function encode(array $views): string
    {
        return (string) json_encode(array_values($views), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * Adds a view, or replaces the view with the same name (case-insensitive).
     *
     * @param  array<int, array{name: string, query: array<string, string>}>  $views
     * @param  array<string, mixed>  $query
     * @return array<int, array{name: string, query: array<string, string>}>
     *
     * @throws InvalidArgumentException
     */
    public static function add(array $views, string $name, array $query): array
    {
        $name = self::cleanName($name);
        $cleaned = self::cleanQuery($query);

        $replaced = false;
        foreach ($views as $index => $view) {
            if (mb_strtolower($view['name']) === mb_strtolower($name)) {
                $views[$index] = ['name' => $name, 'query' => $cleaned];
                $replaced = true;
            }
        }

        if (! $replaced) {
            if (count($views) >= self::MAX_VIEWS) {
                throw new InvalidArgumentException('You can save at most '.self::MAX_VIEWS.' views. Delete one first.');
            }

            $views[] = ['name' => $name, 'query' => $cleaned];
        }

        usort($views, fn (array $a, array $b): int => strnatcasecmp($a['name'], $b['name']));

        return array_values($views);
    }

    /**
     * @param  array<int, array{name: string, query: array<string, string>}>  $views
     * @return array<int, array{name: string, query: array<string, string>}>
     */
    public static function remove(array $views, string $name): array
    {
        $needle = mb_strtolower(trim($name));

        return array_values(array_filter(
            $views,
            fn (array $view): bool => mb_strtolower($view['name']) !== $needle,
        ));
    }

    /** @throws InvalidArgumentException */
    public static function cleanName(string $name): string
    {
        $name = trim((string) preg_replace('/\s+/u', ' ', $name));

        if ($name === '') {
            throw new InvalidArgumentException('A view needs a name.');
        }

        if (mb_strlen($name) > self::MAX_NAME_LENGTH) {
            throw new InvalidArgumentException('The view name can be at most '.self::MAX_NAME_LENGTH.' characters.');
        }

        return $name;
    }

    /**
     * Keeps only known keys with short scalar values.
     *
     * @param  array<mixed>  $query
     * @return array<string, string>
     */
    private static function cleanQuery(array $query): array
    {
        $clean = [];
        foreach (self::ALLOWED_KEYS as $key) {
            if (! array_key_exists($key, $query) || ! is_scalar($query[$key])) {
                continue;
            }

            $value = (string) $query[$key];
            $clean[$key] = mb_strlen($value) > self::MAX_VALUE_LENGTH ? mb_substr($value, 0, self::MAX_VALUE_LENGTH) : $value;
        }

        return $clean;
    }
}

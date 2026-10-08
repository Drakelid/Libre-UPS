<?php

declare(strict_types=1);

namespace Drakelid\UpsBattery\Report;

use InvalidArgumentException;

/** Filters for the "compare metrics" view: one row per device, one column per sensor class. */
final readonly class MatrixFilters
{
    public const MAX_CLASSES = 6;

    public const DEFAULT_CLASSES = ['runtime', 'load', 'charge'];

    /**
     * @param  string[]  $classes
     */
    public function __construct(
        public ?string $type,
        public ?string $os,
        public ?int $group,
        public ?string $q,
        public array $classes,
        public string $sort,
        public string $dir,
        public int $limit,
    ) {
    }

    /**
     * @param  array<string, mixed>  $input  Raw query parameters.
     * @param  array<string, mixed>  $defaults  Keys: type, limit, classes (array or comma separated string).
     *
     * @throws InvalidArgumentException
     */
    public static function fromArray(array $input, array $defaults): self
    {
        $type = array_key_exists('type', $input)
            ? InputParser::nullableString($input['type'], 'type')
            : InputParser::nullableString($defaults['type'] ?? null, 'type');
        InputParser::assertIdentifier($type, 'device type');

        $os = InputParser::nullableString($input['os'] ?? null, 'os');
        InputParser::assertIdentifier($os, 'OS');

        $group = InputParser::parseGroup($input['group'] ?? null);
        $q = InputParser::parseSearch($input['q'] ?? null);

        $classes = self::parseClasses($input['classes'] ?? null)
            ?? self::parseClasses($defaults['classes'] ?? null)
            ?? self::DEFAULT_CLASSES;

        $sort = InputParser::nullableString($input['sort'] ?? null, 'sort') ?? $classes[0];
        if (! in_array($sort, ['hostname', 'location'], true) && ! in_array($sort, $classes, true)) {
            throw new InvalidArgumentException("Invalid sort column \"$sort\".");
        }

        $dir = InputParser::nullableString($input['dir'] ?? null, 'dir');
        if ($dir === null) {
            $dir = in_array($sort, $classes, true) ? SortPolicy::defaultDirection($sort) : 'asc';
        } else {
            $dir = strtolower($dir);
            if (! in_array($dir, ReportFilters::DIRECTIONS, true)) {
                throw new InvalidArgumentException("Invalid sort direction \"$dir\".");
            }
        }

        $limit = InputParser::parseLimit($input['limit'] ?? null, $defaults['limit'] ?? null);

        return new self($type, $os, $group, $q, $classes, $sort, $dir, $limit);
    }

    /** @return array<string, string|int> */
    public function toQuery(): array
    {
        return [
            'type' => $this->type ?? '',
            'classes' => implode(',', $this->classes),
            'os' => $this->os ?? '',
            'group' => $this->group ?? '',
            'q' => $this->q ?? '',
            'sort' => $this->sort,
            'dir' => $this->dir,
            'limit' => $this->limit,
        ];
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'type' => $this->type,
            'os' => $this->os,
            'group' => $this->group,
            'q' => $this->q,
            'classes' => $this->classes,
            'sort' => $this->sort,
            'dir' => $this->dir,
            'limit' => $this->limit,
        ];
    }

    /**
     * @return string[]|null null when nothing was given
     *
     * @throws InvalidArgumentException
     */
    private static function parseClasses(mixed $raw): ?array
    {
        if ($raw === null) {
            return null;
        }

        $items = is_array($raw) ? $raw : explode(',', (string) (is_scalar($raw) ? $raw : ''));

        $classes = [];
        foreach ($items as $item) {
            $class = InputParser::nullableString($item, 'classes');
            if ($class === null) {
                continue;
            }

            InputParser::assertClass($class);
            if (! in_array($class, $classes, true)) {
                $classes[] = $class;
            }
        }

        if ($classes === []) {
            return null;
        }

        if (count($classes) > self::MAX_CLASSES) {
            throw new InvalidArgumentException('Too many metrics, choose at most '.self::MAX_CLASSES.'.');
        }

        return $classes;
    }
}

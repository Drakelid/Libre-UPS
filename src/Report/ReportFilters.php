<?php

declare(strict_types=1);

namespace Drakelid\UpsBattery\Report;

use InvalidArgumentException;

final readonly class ReportFilters
{
    public const SORTS = ['hostname', 'location', 'descr', 'value', 'lastupdate'];

    public const DIRECTIONS = ['asc', 'desc'];

    /** 0 means "all rows". */
    public const LIMITS = InputParser::LIMITS;

    public const AGGREGATES = ['none', 'min', 'max'];

    private const DEFAULT_CLASS = 'runtime';

    public function __construct(
        public ?string $type,
        public string $class,
        public ?string $os,
        public ?int $group,
        public ?string $q,
        public string $sort,
        public string $dir,
        public int $limit,
        public string $aggregate,
    ) {
    }

    /**
     * @param  array<string, mixed>  $input  Raw query parameters.
     * @param  array<string, mixed>  $defaults  Keys: type, class, limit.
     *
     * @throws InvalidArgumentException
     */
    public static function fromArray(array $input, array $defaults): self
    {
        $type = array_key_exists('type', $input)
            ? InputParser::nullableString($input['type'], 'type')
            : InputParser::nullableString($defaults['type'] ?? null, 'type');
        InputParser::assertIdentifier($type, 'device type');

        $class = InputParser::nullableString($input['class'] ?? null, 'class')
            ?? InputParser::nullableString($defaults['class'] ?? null, 'class')
            ?? self::DEFAULT_CLASS;
        InputParser::assertClass($class);

        $os = InputParser::nullableString($input['os'] ?? null, 'os');
        InputParser::assertIdentifier($os, 'OS');

        $group = InputParser::parseGroup($input['group'] ?? null);
        $q = InputParser::parseSearch($input['q'] ?? null);

        $sort = InputParser::nullableString($input['sort'] ?? null, 'sort') ?? 'value';
        if (! in_array($sort, self::SORTS, true)) {
            throw new InvalidArgumentException("Invalid sort column \"$sort\".");
        }

        $dir = InputParser::nullableString($input['dir'] ?? null, 'dir');
        if ($dir === null) {
            $dir = $sort === 'value' ? SortPolicy::defaultDirection($class) : 'asc';
        } else {
            $dir = strtolower($dir);
            if (! in_array($dir, self::DIRECTIONS, true)) {
                throw new InvalidArgumentException("Invalid sort direction \"$dir\".");
            }
        }

        $limit = InputParser::parseLimit($input['limit'] ?? null, $defaults['limit'] ?? null);

        $aggregate = InputParser::nullableString($input['aggregate'] ?? null, 'aggregate') ?? 'none';
        if (! in_array($aggregate, self::AGGREGATES, true)) {
            throw new InvalidArgumentException("Invalid aggregate \"$aggregate\".");
        }

        // Aggregating state sensors by min/max is meaningless, so it is ignored.
        if ($class === 'state') {
            $aggregate = 'none';
        }

        return new self($type, $class, $os, $group, $q, $sort, $dir, $limit, $aggregate);
    }

    /** @return array<string, string|int> Values suitable for a query string (null becomes an empty string). */
    public function toQuery(): array
    {
        return [
            'type' => $this->type ?? '',
            'class' => $this->class,
            'os' => $this->os ?? '',
            'group' => $this->group ?? '',
            'q' => $this->q ?? '',
            'sort' => $this->sort,
            'dir' => $this->dir,
            'limit' => $this->limit,
            'aggregate' => $this->aggregate,
        ];
    }

    /** @return array<string, string|int|null> */
    public function toArray(): array
    {
        return [
            'type' => $this->type,
            'class' => $this->class,
            'os' => $this->os,
            'group' => $this->group,
            'q' => $this->q,
            'sort' => $this->sort,
            'dir' => $this->dir,
            'limit' => $this->limit,
            'aggregate' => $this->aggregate,
        ];
    }
}

<?php

declare(strict_types=1);

namespace Drakelid\UpsBattery\Report;

use InvalidArgumentException;

/** Filters for the UPS overview: one row per UPS with its battery, power and swap data. */
final readonly class UpsFilters
{
    /** "status" sorts by the worst problem of the UPS, "swap" by the days left until the battery swap. */
    public const SORTS = ['status', 'hostname', 'location', 'manufacturer', 'model', 'runtime', 'charge', 'load', 'temperature', 'swap'];

    /** Summary cards that can be clicked to show only those UPSs. */
    public const FOCUS = ['on_battery', 'overdue', 'due', 'alarm', 'unknown', 'down'];

    /** Direction used when only the column is given: the most urgent rows first. */
    private const DEFAULT_DIRECTIONS = ['status' => 'desc', 'load' => 'desc', 'temperature' => 'desc'];

    /**
     * @param  bool  $attention  Only UPSs with a problem (any warning or critical value, on battery, swap due).
     * @param  string|null  $focus  Only the UPSs one summary card counts, see FOCUS.
     */
    public function __construct(
        public ?string $type,
        public ?string $os,
        public ?int $group,
        public ?string $q,
        public string $sort,
        public string $dir,
        public int $limit,
        public bool $attention = false,
        public ?string $focus = null,
    ) {}

    /**
     * @param  array<string, mixed>  $input  Raw query parameters.
     * @param  array<string, mixed>  $defaults  Keys: type, limit.
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

        $sort = InputParser::nullableString($input['sort'] ?? null, 'sort') ?? 'status';
        if (! in_array($sort, self::SORTS, true)) {
            throw new InvalidArgumentException("Invalid sort column \"$sort\".");
        }

        $dir = InputParser::nullableString($input['dir'] ?? null, 'dir');
        if ($dir === null) {
            $dir = self::DEFAULT_DIRECTIONS[$sort] ?? 'asc';
        } else {
            $dir = strtolower($dir);
            if (! in_array($dir, ReportFilters::DIRECTIONS, true)) {
                throw new InvalidArgumentException("Invalid sort direction \"$dir\".");
            }
        }

        $focus = InputParser::nullableString($input['focus'] ?? null, 'focus');
        if ($focus !== null && ! in_array($focus, self::FOCUS, true)) {
            throw new InvalidArgumentException("Invalid focus \"$focus\".");
        }

        return new self(
            $type,
            $os,
            InputParser::parseGroup($input['group'] ?? null),
            InputParser::parseSearch($input['q'] ?? null),
            $sort,
            $dir,
            InputParser::parseLimit($input['limit'] ?? null, $defaults['limit'] ?? null),
            InputParser::parseFlag($input['attention'] ?? null),
            $focus,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'type' => $this->type,
            'os' => $this->os,
            'group' => $this->group,
            'q' => $this->q,
            'sort' => $this->sort,
            'dir' => $this->dir,
            'limit' => $this->limit,
            'attention' => $this->attention,
            'focus' => $this->focus,
        ];
    }
}

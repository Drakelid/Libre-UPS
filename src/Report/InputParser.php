<?php

declare(strict_types=1);

namespace Drakelid\UpsBattery\Report;

use InvalidArgumentException;

/** Shared, strict parsing of raw query-string values. */
final class InputParser
{
    /** 0 means "all rows". */
    public const LIMITS = [10, 25, 50, 100, 0];

    public const DEFAULT_LIMIT = 25;

    public const MAX_QUERY_LENGTH = 100;

    /**
     * Trims scalars; null, empty and whitespace-only values become null.
     *
     * @throws InvalidArgumentException when the value is not a scalar
     */
    public static function nullableString(mixed $value, string $name): ?string
    {
        if ($value === null) {
            return null;
        }

        if (! is_scalar($value)) {
            throw new InvalidArgumentException("Invalid value for \"$name\".");
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    /** @throws InvalidArgumentException */
    public static function assertIdentifier(?string $value, string $label): void
    {
        if ($value !== null && preg_match('/^[A-Za-z0-9_.\-]{1,64}$/', $value) !== 1) {
            throw new InvalidArgumentException("Invalid $label \"$value\".");
        }
    }

    /** @throws InvalidArgumentException */
    public static function assertClass(string $class): void
    {
        if (preg_match('/^[a-z0-9_]{1,64}$/', $class) !== 1) {
            throw new InvalidArgumentException("Invalid sensor class \"$class\".");
        }
    }

    /** Checkbox-like flag: "1", "true", "yes" and "on" are true, everything else (including empty) is false. */
    public static function parseFlag(mixed $raw): bool
    {
        if (is_bool($raw)) {
            return $raw;
        }

        if (! is_scalar($raw)) {
            return false;
        }

        return in_array(strtolower(trim((string) $raw)), ['1', 'true', 'yes', 'on'], true);
    }

    /** @throws InvalidArgumentException */
    public static function parseGroup(mixed $raw): ?int
    {
        $value = self::nullableString($raw, 'group');
        if ($value === null) {
            return null;
        }

        $group = filter_var($value, FILTER_VALIDATE_INT);
        if ($group === false || $group < 1) {
            throw new InvalidArgumentException("Invalid device group \"$value\".");
        }

        return $group;
    }

    /** @throws InvalidArgumentException */
    public static function parseLimit(mixed $raw, mixed $default): int
    {
        $fallback = filter_var($default ?? self::DEFAULT_LIMIT, FILTER_VALIDATE_INT);
        if ($fallback === false || ! in_array($fallback, self::LIMITS, true)) {
            $fallback = self::DEFAULT_LIMIT;
        }

        $value = self::nullableString($raw, 'limit');
        if ($value === null) {
            return $fallback;
        }

        $limit = filter_var($value, FILTER_VALIDATE_INT);
        if ($limit === false || ! in_array($limit, self::LIMITS, true)) {
            throw new InvalidArgumentException("Invalid limit \"$value\".");
        }

        return $limit;
    }

    /** Free-text search: trimmed, empty becomes null, cut at MAX_QUERY_LENGTH characters. */
    public static function parseSearch(mixed $raw, string $name = 'q'): ?string
    {
        $q = self::nullableString($raw, $name);
        if ($q !== null && mb_strlen($q) > self::MAX_QUERY_LENGTH) {
            $q = mb_substr($q, 0, self::MAX_QUERY_LENGTH);
        }

        return $q;
    }
}

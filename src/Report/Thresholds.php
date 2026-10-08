<?php

declare(strict_types=1);

namespace Drakelid\UpsBattery\Report;

/**
 * Plugin-level severity rules per sensor class, for sensors where LibreNMS has no useful limits.
 *
 * One rule set per line: "<class> <critical-rule> [<warning-rule>]", where a rule is "<" or ">"
 * followed by a number, for example:
 *
 *     runtime <10 <20     # critical below 10 minutes, warning below 20
 *     load    >90 >75
 *
 * Text after "#" is a comment. Invalid lines are skipped and reported through errors().
 */
final class Thresholds
{
    /**
     * @param  array<string, array{critical: array{0: string, 1: float}, warning: ?array{0: string, 1: float}}>  $rules
     * @param  string[]  $errors
     */
    private function __construct(private readonly array $rules, private readonly array $errors) {}

    public static function empty(): self
    {
        return new self([], []);
    }

    public static function parse(?string $text): self
    {
        $rules = [];
        $errors = [];

        $lines = preg_split('/\R/', (string) $text) ?: [];
        foreach ($lines as $index => $line) {
            $line = trim((string) preg_replace('/#.*$/', '', $line));
            if ($line === '') {
                continue;
            }

            $number = $index + 1;
            $parts = preg_split('/\s+/', $line) ?: [];
            $class = (string) array_shift($parts);

            if (preg_match('/^[a-z0-9_]{1,64}$/', $class) !== 1 || count($parts) < 1 || count($parts) > 2) {
                $errors[] = "Line $number: expected \"<class> <critical-rule> [<warning-rule>]\".";

                continue;
            }

            $parsed = [];
            foreach ($parts as $part) {
                if (preg_match('/^([<>])(-?\d+(?:[.,]\d+)?)$/', $part, $m) !== 1) {
                    $errors[] = "Line $number: \"$part\" is not a rule like <10 or >90.";

                    continue 2;
                }

                $parsed[] = [$m[1], (float) str_replace(',', '.', $m[2])];
            }

            $rules[$class] = ['critical' => $parsed[0], 'warning' => $parsed[1] ?? null];
        }

        return new self($rules, $errors);
    }

    public function has(string $class): bool
    {
        return isset($this->rules[$class]);
    }

    /**
     * The parsed rules per class. A rule is [operator, number]; the warning rule is null when the line had none.
     *
     * @return array<string, array{critical: array{0: string, 1: float}, warning: ?array{0: string, 1: float}}>
     */
    public function rules(): array
    {
        return $this->rules;
    }

    /** @return string[] */
    public function errors(): array
    {
        return $this->errors;
    }

    /** Severity from the plugin rules, or null when no rule is defined for the class. */
    public function severity(string $class, ?float $value): ?Severity
    {
        if (! isset($this->rules[$class])) {
            return null;
        }

        if ($value === null) {
            return Severity::Unknown;
        }

        $set = $this->rules[$class];
        if (self::matches($set['critical'], $value)) {
            return Severity::Critical;
        }

        if ($set['warning'] !== null && self::matches($set['warning'], $value)) {
            return Severity::Warning;
        }

        return Severity::Ok;
    }

    /** @param  array{0: string, 1: float}  $rule */
    private static function matches(array $rule, float $value): bool
    {
        return $rule[0] === '<' ? $value < $rule[1] : $value > $rule[1];
    }
}

<?php

declare(strict_types=1);

namespace Drakelid\UpsBattery\Report;

/** Settings of the weekly email report. */
final readonly class ReportSchedule
{
    public const DEFAULT_DAY = 1; // Monday, 0 = Sunday

    public const DEFAULT_TIME = '07:00';

    public const DEFAULT_TOP = 10;

    /** Allowed numbers of rows in the "shortest runtime" table (all are valid report limits). */
    public const TOP_CHOICES = [10, 25, 50, 100];

    /** @param  string[]  $recipients */
    public function __construct(
        public bool $enabled,
        public array $recipients,
        public int $day,
        public string $time,
        public int $top,
    ) {}

    /** @param  array<string, mixed>  $raw  The settings array stored by LibreNMS. */
    public static function fromArray(array $raw): self
    {
        $day = filter_var($raw['report_day'] ?? null, FILTER_VALIDATE_INT);
        if ($day === false || $day < 0 || $day > 6) {
            $day = self::DEFAULT_DAY;
        }

        $time = is_scalar($raw['report_time'] ?? null) ? trim((string) $raw['report_time']) : '';
        if (preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $time) !== 1) {
            $time = self::DEFAULT_TIME;
        }

        $top = filter_var($raw['report_top'] ?? null, FILTER_VALIDATE_INT);
        if ($top === false || ! in_array($top, self::TOP_CHOICES, true)) {
            $top = self::DEFAULT_TOP;
        }

        return new self(
            InputParser::parseFlag($raw['report_enabled'] ?? null),
            self::parseRecipients($raw['report_recipients'] ?? null),
            $day,
            $time,
            $top,
        );
    }

    /** The report is only scheduled when it is switched on and has someone to send to. */
    public function isActive(): bool
    {
        return $this->enabled && $this->recipients !== [];
    }

    /**
     * Valid, distinct email addresses from a comma, semicolon or line separated list. Invalid entries are dropped.
     *
     * @return string[]
     */
    public static function parseRecipients(mixed $raw): array
    {
        if (! is_string($raw)) {
            return [];
        }

        $recipients = [];
        foreach (preg_split('/[\s,;]+/', $raw, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $candidate) {
            if (filter_var($candidate, FILTER_VALIDATE_EMAIL) !== false && ! in_array(strtolower($candidate), array_map('strtolower', $recipients), true)) {
                $recipients[] = $candidate;
            }
        }

        return $recipients;
    }
}

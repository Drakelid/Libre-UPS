<?php

declare(strict_types=1);

namespace Drakelid\UpsBattery\Report;

use DateTimeImmutable;
use DateTimeZone;
use Exception;

/**
 * When a UPS battery is due for replacement, worked out from (in this order):
 *   1. the install date entered on the UPS overview + the battery lifetime setting ("manual"),
 *   2. the recommended replacement date the UPS reports ("ups", APC),
 *   3. the last replacement date the UPS reports + the battery lifetime ("ups_last", APC).
 * Without any of them the swap date is unknown ("none").
 */
final readonly class BatterySwap
{
    public const DEFAULT_LIFETIME_MONTHS = 48;

    public const DEFAULT_WARN_DAYS = 90;

    public const SOURCES = ['manual', 'ups', 'ups_last', 'none'];

    public function __construct(
        public ?string $installed,
        public ?string $due,
        public ?int $daysLeft,
        public string $source,
        public Severity $severity,
        public ?int $lifeUsed = null,
    ) {}

    /**
     * @param  string|null  $installed  Install date entered by a user (Y-m-d).
     * @param  float|null  $dueMinutes  Value of the APC "recommended replacement" sensor: minutes from $measuredAt.
     * @param  float|null  $replacedMinutes  Value of the APC "last replacement" sensor: minutes from $measuredAt (in the past).
     * @param  string|null  $measuredAt  When LibreNMS last updated those sensors (ISO 8601); now when unknown.
     */
    public static function evaluate(
        ?string $installed,
        ?float $dueMinutes,
        ?float $replacedMinutes,
        ?string $measuredAt,
        int $lifetimeMonths,
        int $warnDays,
        DateTimeImmutable $now,
    ): self {
        $today = $now->setTime(0, 0);
        $measured = self::parseInstant($measuredAt, $now) ?? $now;

        $installedDate = self::parseDate($installed);
        if ($installedDate !== null) {
            return self::make($installedDate, $installedDate->modify("+$lifetimeMonths months"), 'manual', $today, $warnDays);
        }

        if ($dueMinutes !== null) {
            $due = $measured->modify(sprintf('%+d minutes', (int) round($dueMinutes)));

            return self::make(null, $due, 'ups', $today, $warnDays);
        }

        if ($replacedMinutes !== null) {
            // The date lies in the past; take the size, whatever sign the poller stored.
            $replaced = $measured->modify(sprintf('-%d minutes', (int) round(abs($replacedMinutes))))->setTime(0, 0);

            return self::make($replaced, $replaced->modify("+$lifetimeMonths months"), 'ups_last', $today, $warnDays);
        }

        return new self(null, null, null, 'none', Severity::Unknown);
    }

    /** A valid calendar date in Y-m-d form, or null. */
    public static function parseDate(?string $value): ?DateTimeImmutable
    {
        if ($value === null || preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            return null;
        }

        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value, new DateTimeZone('UTC'));

        return $date !== false && $date->format('Y-m-d') === $value ? $date : null;
    }

    /** @return array{installed: ?string, due: ?string, days_left: ?int, source: string, severity: string, life_used: ?int} */
    public function toArray(): array
    {
        return [
            'installed' => $this->installed,
            'due' => $this->due,
            'days_left' => $this->daysLeft,
            'source' => $this->source,
            'severity' => $this->severity->value,
            'life_used' => $this->lifeUsed,
        ];
    }

    private static function make(?DateTimeImmutable $installed, DateTimeImmutable $due, string $source, DateTimeImmutable $today, int $warnDays): self
    {
        $dueDay = new DateTimeImmutable($due->format('Y-m-d'), $today->getTimezone());
        $todayDay = new DateTimeImmutable($today->format('Y-m-d'), $today->getTimezone());
        $days = (int) $todayDay->diff($dueDay)->format('%r%a');

        $severity = match (true) {
            $days <= 0 => Severity::Critical,
            $days <= $warnDays => Severity::Warning,
            default => Severity::Ok,
        };

        // Share of the battery's life that has passed, known when the install date is known (100 = due today).
        $lifeUsed = null;
        if ($installed !== null) {
            $installedDay = new DateTimeImmutable($installed->format('Y-m-d'), $today->getTimezone());
            $total = (int) $installedDay->diff($dueDay)->format('%r%a');
            $elapsed = (int) $installedDay->diff($todayDay)->format('%r%a');
            $lifeUsed = $total > 0 ? max(0, (int) round($elapsed / $total * 100)) : 100;
        }

        return new self($installed?->format('Y-m-d'), $due->format('Y-m-d'), $days, $source, $severity, $lifeUsed);
    }

    private static function parseInstant(?string $iso, DateTimeImmutable $now): ?DateTimeImmutable
    {
        if ($iso === null || $iso === '') {
            return null;
        }

        try {
            return (new DateTimeImmutable($iso))->setTimezone($now->getTimezone());
        } catch (Exception) {
            return null;
        }
    }
}

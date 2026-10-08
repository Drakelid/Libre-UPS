<?php

declare(strict_types=1);

namespace Drakelid\UpsBattery\Report;

use DateTimeImmutable;

/**
 * Builds the UPS overview from all sensors of the candidate devices: one row per UPS, the summary cards,
 * sorting, the "needs attention" filter and the row limit.
 */
final class UpsBuilder
{
    public function __construct(
        private readonly SuspectRule $rule,
        private readonly int $lifetimeMonths,
        private readonly int $warnDays,
        private readonly DateTimeImmutable $now,
    ) {}

    /**
     * @param  array<int, ReportRow[]>  $sensorsByDevice  All sensors per device id.
     * @param  array<int, string>  $installed  Battery install dates (Y-m-d) entered by users, per device id.
     * @return array{rows: UpsRow[], total: int, cards: array<string, mixed>}
     */
    public function build(array $sensorsByDevice, array $installed, UpsFilters $filters): array
    {
        $rows = [];
        foreach ($sensorsByDevice as $deviceId => $sensors) {
            $row = $this->row($sensors, $installed[$deviceId] ?? null);
            if ($row !== null) {
                $rows[] = $row;
            }
        }

        $cards = $this->cards($rows);

        if ($filters->attention) {
            $rows = array_values(array_filter($rows, fn (UpsRow $row): bool => $row->needsAttention()));
        }

        $rows = $this->sort($rows, $filters);
        $total = count($rows);

        return [
            'rows' => $filters->limit > 0 ? array_slice($rows, 0, $filters->limit) : $rows,
            'total' => $total,
            'cards' => $cards,
        ];
    }

    /**
     * One UPS, or null when the device has nothing that makes it a UPS (no runtime, charge, battery state or
     * battery date).
     *
     * @param  ReportRow[]  $sensors
     */
    public function row(array $sensors, ?string $installed): ?UpsRow
    {
        if ($sensors === []) {
            return null;
        }

        $byKind = [];
        foreach ($sensors as $sensor) {
            $kind = UpsSensorKind::of($sensor->sensorClass, $sensor->sensorType, $sensor->sensorIndex, $sensor->sensorDescr);
            $byKind[$kind->value][] = $this->adjust($kind, $sensor);
        }

        $pick = fn (UpsSensorKind $kind, string $mode): ?ReportRow => self::pick($byKind[$kind->value] ?? [], $mode);

        $runtime = $pick(UpsSensorKind::Runtime, 'min');
        $charge = $pick(UpsSensorKind::Charge, 'min');
        $battery = $pick(UpsSensorKind::BatteryState, 'worst');
        $replaceDue = $pick(UpsSensorKind::ReplaceDue, 'min');
        $replacedAt = $pick(UpsSensorKind::ReplacedAt, 'first');

        if ($runtime === null && $charge === null && $battery === null && $replaceDue === null && $replacedAt === null) {
            return null;
        }

        $load = $pick(UpsSensorKind::Load, 'max');
        $temperature = $pick(UpsSensorKind::Temperature, 'max');
        $badPacks = $pick(UpsSensorKind::BadPacks, 'max');
        $output = $pick(UpsSensorKind::OutputState, 'worst');
        $selfTest = $pick(UpsSensorKind::SelfTestState, 'worst');

        $onBattery = $output === null ? null : UpsSensorKind::meansOnBattery($output->valueFormatted);
        $suspect = SuspectBattery::evaluate($this->rule, $runtime?->value, $load?->value, $charge?->value);
        $swap = BatterySwap::evaluate(
            $installed,
            $replaceDue?->value,
            $replacedAt?->value,
            ($replaceDue ?? $replacedAt)?->lastUpdate,
            $this->lifetimeMonths,
            $this->warnDays,
            $this->now,
        );

        $severity = Severity::Ok;
        foreach ([$runtime, $charge, $load, $temperature, $battery, $badPacks, $output, $selfTest] as $cell) {
            $severity = self::worse($severity, $cell?->severity);
        }
        $severity = self::worse($severity, $swap->severity);
        if ($suspect === true) {
            $severity = self::worse($severity, Severity::Warning);
        }
        if ($onBattery === true) {
            $severity = Severity::Critical;
        }

        $info = $sensors[0];

        return new UpsRow(
            $info->deviceId,
            $info->hostname,
            $info->displayName,
            $info->deviceUrl,
            $info->location,
            $info->os,
            $info->deviceUp,
            $severity,
            $runtime,
            $charge,
            $load,
            $temperature,
            $battery,
            $badPacks,
            $output,
            $selfTest,
            $onBattery,
            $suspect,
            $swap,
            self::sortedSensors($sensors),
        );
    }

    /**
     * Summary over all UPSs that match the filters (before the "needs attention" filter and the row limit).
     *
     * @param  UpsRow[]  $rows
     * @return array{devices: int, on_battery: int, swap_overdue: int, swap_due: int, swap_unknown: int, battery_alarm: int, lowest_runtime: array{display_name: string, device_url: string, value_formatted: string}|null}
     */
    public function cards(array $rows): array
    {
        $cards = ['devices' => count($rows), 'on_battery' => 0, 'swap_overdue' => 0, 'swap_due' => 0, 'swap_unknown' => 0, 'battery_alarm' => 0, 'lowest_runtime' => null];
        $lowest = null;

        foreach ($rows as $row) {
            if ($row->onBattery === true) {
                $cards['on_battery']++;
            }

            if ($row->swap->daysLeft === null) {
                $cards['swap_unknown']++;
            } elseif ($row->swap->daysLeft <= 0) {
                $cards['swap_overdue']++;
            } elseif ($row->swap->daysLeft <= $this->warnDays) {
                $cards['swap_due']++;
            }

            if ($this->hasBatteryAlarm($row)) {
                $cards['battery_alarm']++;
            }

            if ($row->runtime?->value !== null && ($lowest === null || $row->runtime->value < $lowest->runtime?->value)) {
                $lowest = $row;
            }
        }

        if ($lowest !== null && $lowest->runtime !== null) {
            $cards['lowest_runtime'] = [
                'display_name' => $lowest->displayName,
                'device_url' => $lowest->deviceUrl,
                'value_formatted' => $lowest->runtime->valueFormatted,
            ];
        }

        return $cards;
    }

    /** Battery status or self-test in warning/critical, bad battery packs, or a suspect battery. */
    private function hasBatteryAlarm(UpsRow $row): bool
    {
        foreach ([$row->battery, $row->selfTest, $row->badPacks] as $cell) {
            if ($cell !== null && ($cell->severity === Severity::Warning || $cell->severity === Severity::Critical)) {
                return true;
            }
        }

        return ($row->badPacks?->value ?? 0) > 0 || $row->suspect === true;
    }

    /**
     * LibreNMS maps a failed APC self-test to "unknown"; a failed test is a battery problem.
     * Bad battery packs above zero count as critical even when LibreNMS has no limit for them.
     */
    private function adjust(UpsSensorKind $kind, ReportRow $sensor): ReportRow
    {
        if ($kind === UpsSensorKind::SelfTestState && $sensor->severity === Severity::Unknown
            && str_contains(strtolower($sensor->valueFormatted), 'fail')) {
            return $sensor->withSeverity(Severity::Critical);
        }

        if ($kind === UpsSensorKind::BadPacks && ($sensor->value ?? 0) > 0 && $sensor->severity !== Severity::Critical) {
            return $sensor->withSeverity(Severity::Critical);
        }

        return $sensor;
    }

    /** @param  ReportRow[]  $rows */
    private static function pick(array $rows, string $mode): ?ReportRow
    {
        $best = null;
        foreach ($rows as $row) {
            if ($best === null) {
                $best = $row;

                continue;
            }

            $better = match ($mode) {
                'min' => $row->value !== null && ($best->value === null || $row->value < $best->value),
                'max' => $row->value !== null && ($best->value === null || $row->value > $best->value),
                'worst' => $row->severity->rank() > $best->severity->rank(),
                default => false,
            };

            if ($better) {
                $best = $row;
            }
        }

        return $best;
    }

    private static function worse(Severity $current, ?Severity $other): Severity
    {
        // "unknown" (no limits, no data) does not make a UPS need attention
        if ($other === null || $other === Severity::Unknown) {
            return $current;
        }

        return $other->rank() > $current->rank() ? $other : $current;
    }

    /**
     * @param  ReportRow[]  $sensors
     * @return ReportRow[]
     */
    private static function sortedSensors(array $sensors): array
    {
        usort($sensors, fn (ReportRow $a, ReportRow $b): int => [$a->sensorClass, strtolower($a->sensorDescr)] <=> [$b->sensorClass, strtolower($b->sensorDescr)]);

        return $sensors;
    }

    /**
     * @param  UpsRow[]  $rows
     * @return UpsRow[]
     */
    private function sort(array $rows, UpsFilters $filters): array
    {
        $ascending = $filters->dir === 'asc';

        usort($rows, function (UpsRow $a, UpsRow $b) use ($filters, $ascending): int {
            $primary = match ($filters->sort) {
                'hostname' => RowProcessor::compareText($a->hostname, $b->hostname, $ascending),
                'location' => RowProcessor::compareText($a->location, $b->location, $ascending),
                'swap' => self::compareNumber($a->swap->daysLeft, $b->swap->daysLeft, $ascending),
                'status' => ($ascending ? 1 : -1) * ($a->severity->rank() <=> $b->severity->rank())
                    ?: self::compareNumber($a->swap->daysLeft, $b->swap->daysLeft, true),
                default => self::compareNumber($a->{$filters->sort}?->value, $b->{$filters->sort}?->value, $ascending),
            };

            if ($primary !== 0) {
                return $primary;
            }

            $byHost = strnatcasecmp($a->hostname, $b->hostname) <=> 0;

            return $byHost !== 0 ? $byHost : $a->deviceId <=> $b->deviceId;
        });

        return $rows;
    }

    /** Missing values always sort last, regardless of direction. */
    private static function compareNumber(int|float|null $a, int|float|null $b, bool $ascending): int
    {
        if ($a === null || $b === null) {
            return ($a === null ? 1 : 0) <=> ($b === null ? 1 : 0);
        }

        $result = $a <=> $b;

        return $ascending ? $result : -$result;
    }
}

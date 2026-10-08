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

        if ($filters->focus !== null) {
            $rows = array_values(array_filter($rows, fn (UpsRow $row): bool => $this->inFocus($row, $filters->focus)));
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

        // A UPS has a battery charge or a remaining battery time (or reports its battery dates). Uptimes, run hours
        // and "battery" states alone (routers, coolers, servers) do not make a device a UPS.
        if ($runtime === null && $charge === null && $replaceDue === null && $replacedAt === null) {
            return null;
        }

        $load = $pick(UpsSensorKind::Load, 'max');
        $temperature = $pick(UpsSensorKind::Temperature, 'max');
        $badPacks = $pick(UpsSensorKind::BadPacks, 'max');
        $output = $pick(UpsSensorKind::OutputState, 'worst');
        $selfTest = $pick(UpsSensorKind::SelfTestState, 'worst');

        $onBattery = self::onBattery($output, $pick(UpsSensorKind::OnBatteryTime, 'max'), $battery);
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

        // An unreachable UPS is no longer monitored; its values are as old as the last poll.
        if (! $info->deviceUp) {
            $severity = self::worse($severity, Severity::Warning);
        }

        $issues = self::issues($info->deviceUp, $onBattery, $suspect, $swap, [
            'runtime' => $runtime,
            'charge' => $charge,
            'load' => $load,
            'temperature' => $temperature,
            'battery' => $battery,
            'self_test' => $selfTest,
        ], $badPacks);

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
            $issues,
        );
    }

    /**
     * Why a UPS needs attention, most severe first, as keys the page turns into a sentence ("issues.<key>").
     * "n" is a number for the sentence (days, bad packs), "value" the reading or vendor text it is about.
     *
     * @param  array<string, ReportRow|null>  $cells  runtime, charge, load, temperature, battery, self_test.
     * @return array<int, array{key: string, severity: string, n: int|null, value: string|null}>
     */
    public static function issues(bool $deviceUp, ?bool $onBattery, ?bool $suspect, BatterySwap $swap, array $cells, ?ReportRow $badPacks): array
    {
        $issues = [];
        $add = function (string $key, Severity $severity, ?int $n = null, ?string $value = null) use (&$issues): void {
            $issues[] = ['key' => $key, 'severity' => $severity->value, 'n' => $n, 'value' => $value];
        };
        $problem = fn (?ReportRow $cell): bool => $cell !== null && ($cell->severity === Severity::Warning || $cell->severity === Severity::Critical);

        if ($onBattery === true) {
            $add('on_battery', Severity::Critical);
        }

        if (! $deviceUp) {
            $add('down', Severity::Warning);
        }

        // Readings: "Runtime low: 8 min", "Load high: 92 %"
        foreach (['runtime', 'charge', 'load', 'temperature'] as $metric) {
            $cell = $cells[$metric] ?? null;
            if ($problem($cell)) {
                $add($metric.'_'.UpsLabels::direction($cell), $cell->severity, null, $cell->valueFormatted);
            }
        }

        // States: a plain sentence where the wording is known, else the vendor text
        $battery = $cells['battery'] ?? null;
        if ($problem($battery)) {
            $label = UpsLabels::battery($battery->valueFormatted);
            $known = in_array($label, ['replace', 'low', 'depleted', 'discharging', 'disconnected', 'fault'], true);
            $add($known ? 'battery_'.$label : 'battery', $battery->severity, null, $battery->valueFormatted);
        }

        $selfTest = $cells['self_test'] ?? null;
        if ($problem($selfTest)) {
            $add(UpsLabels::selfTest($selfTest->valueFormatted) === 'failed' ? 'self_test_failed' : 'self_test', $selfTest->severity, null, $selfTest->valueFormatted);
        }

        if (($badPacks?->value ?? 0) > 0) {
            $add('bad_packs', Severity::Critical, (int) $badPacks->value);
        }

        if ($suspect === true) {
            $add('suspect', Severity::Warning);
        }

        if ($swap->daysLeft !== null && $swap->severity === Severity::Critical) {
            $swap->daysLeft === 0
                ? $add('swap_today', Severity::Critical)
                : $add('swap_overdue', Severity::Critical, -$swap->daysLeft);
        } elseif ($swap->daysLeft !== null && $swap->severity === Severity::Warning) {
            $add('swap_due', Severity::Warning, $swap->daysLeft);
        }

        // usort is stable, so issues of the same severity keep the order above
        usort($issues, fn (array $a, array $b): int => Severity::from($b['severity'])->rank() <=> Severity::from($a['severity'])->rank());

        return $issues;
    }

    /**
     * Whether the UPS runs on battery, from (in this order) the output state, the time-on-battery counter
     * (above 0 only while on battery) or a battery state that says the battery is discharging (Eaton XUPS, NUT).
     */
    public static function onBattery(?ReportRow $output, ?ReportRow $onBatteryTime, ?ReportRow $battery): ?bool
    {
        if ($output !== null) {
            $verdict = UpsSensorKind::meansOnBattery($output->valueFormatted, $output->sensorType.' '.$output->sensorDescr);
            if ($verdict !== null) {
                return $verdict;
            }
        }

        if ($onBatteryTime?->value !== null) {
            return $onBatteryTime->value > 0;
        }

        if ($battery !== null && str_contains(strtolower($battery->valueFormatted), 'discharg')) {
            return true;
        }

        return null;
    }

    /** Whether a summary card counts the UPS; the same test filters the table when the card is clicked. */
    public function inFocus(UpsRow $row, string $focus): bool
    {
        $days = $row->swap->daysLeft;

        return match ($focus) {
            'on_battery' => $row->onBattery === true,
            'overdue' => $days !== null && $days <= 0,
            'due' => $days !== null && $days > 0 && $days <= $this->warnDays,
            'unknown' => $days === null,
            'alarm' => $this->hasBatteryAlarm($row),
            'down' => ! $row->deviceUp,
            default => true,
        };
    }

    /**
     * Summary over all UPSs that match the filters (before the "needs attention" filter and the row limit).
     *
     * @param  UpsRow[]  $rows
     *                          The swap timeline counts the battery swaps due in each of the next 12 months (this month first).
     * @return array{devices: int, on_battery: int, swap_overdue: int, swap_due: int, swap_unknown: int, battery_alarm: int, down: int, lowest_runtime: array{display_name: string, device_url: string, value_formatted: string}|null, swap_timeline: array<int, array{month: string, count: int}>}
     */
    public function cards(array $rows): array
    {
        $counted = ['on_battery' => 'on_battery', 'swap_overdue' => 'overdue', 'swap_due' => 'due', 'swap_unknown' => 'unknown', 'battery_alarm' => 'alarm', 'down' => 'down'];
        $cards = ['devices' => count($rows)] + array_fill_keys(array_keys($counted), 0) + ['lowest_runtime' => null];
        $lowest = null;

        $months = [];
        $month = $this->now->modify('first day of this month');
        for ($i = 0; $i < 12; $i++) {
            $months[$month->format('Y-m')] = 0;
            $month = $month->modify('+1 month');
        }

        foreach ($rows as $row) {
            foreach ($counted as $card => $focus) {
                if ($this->inFocus($row, $focus)) {
                    $cards[$card]++;
                }
            }

            $dueMonth = $row->swap->due === null ? null : substr($row->swap->due, 0, 7);
            if ($dueMonth !== null && ($row->swap->daysLeft ?? 0) > 0 && isset($months[$dueMonth])) {
                $months[$dueMonth]++;
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

        $cards['swap_timeline'] = [];
        foreach ($months as $key => $count) {
            $cards['swap_timeline'][] = ['month' => $key, 'count' => $count];
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
        // NUT reports flags such as "UPS low battery: True"; the cell shows the flag's name when it is set.
        if (($kind === UpsSensorKind::BatteryState || $kind === UpsSensorKind::SelfTestState) && UpsSensorKind::isFlagText($sensor->valueFormatted)) {
            $sensor = $sensor->with(['valueFormatted' => strtolower(trim($sensor->valueFormatted)) === 'true' ? $sensor->sensorDescr : 'OK']);
        }

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

<?php

declare(strict_types=1);

namespace Drakelid\UpsBattery\Report;

/**
 * Turns the vendor wording of UPS states ("onLine", "noBatteryNeedsReplacing", "batteryDischarging", "UPS low
 * battery", "noTestInitiated", ...) into a few plain keys the page translates. Unknown wording gives null, and
 * the page then shows the vendor text as it is.
 */
final class UpsLabels
{
    public const STATUSES = ['on_battery', 'on_mains', 'bypass', 'battery_test', 'off', 'unreachable', 'unknown'];

    public const BATTERY = ['ok', 'replace', 'low', 'depleted', 'discharging', 'charging', 'disconnected', 'fault'];

    public const SELF_TEST = ['passed', 'failed', 'running', 'not_run', 'aborted', 'warning'];

    /** Where the load is powered from. */
    public static function status(bool $deviceUp, ?bool $onBattery, ?string $outputText): string
    {
        if (! $deviceUp) {
            return 'unreachable';
        }

        if ($onBattery === true) {
            return 'on_battery';
        }

        $text = strtolower((string) $outputText);

        return match (true) {
            str_contains($text, 'bypass') || str_contains($text, 'by-pass') => 'bypass',
            str_contains($text, 'test') || str_contains($text, 'calibrat') => 'battery_test',
            preg_match('/^off$|^no output|sleep|shutdown|inverter off/', $text) === 1 => 'off',
            $onBattery === false => 'on_mains',
            default => 'unknown',
        };
    }

    /** Battery status in plain words, or null for wording that is not recognised. */
    public static function battery(?string $text): ?string
    {
        if ($text === null || trim($text) === '') {
            return null;
        }

        $t = strtolower($text);

        return match (true) {
            preg_match('/^no.?battery.?needs|^no$|normal|^ok$|good|float|resting|fully|^charged/', $t) === 1 => 'ok',
            str_contains($t, 'replac') => 'replace',
            str_contains($t, 'deplet') => 'depleted',
            str_contains($t, 'discharg') => 'discharging',
            str_contains($t, 'disconnect') => 'disconnected',
            str_contains($t, 'low') => 'low',
            str_contains($t, 'charg') => 'charging',
            preg_match('/fail|fault|error|bad|alarm|check|wrong/', $t) === 1 => 'fault',
            default => null,
        };
    }

    /** Last self-test result in plain words, or null for wording that is not recognised. */
    public static function selfTest(?string $text): ?string
    {
        if ($text === null || trim($text) === '') {
            return null;
        }

        $t = strtolower($text);

        return match (true) {
            preg_match('/no.?test|not.?(run|initiated|done)|no.?data|none|invalid/', $t) === 1 => 'not_run',
            preg_match('/fail|error/', $t) === 1 => 'failed',
            preg_match('/progress|running|testing/', $t) === 1 => 'running',
            str_contains($t, 'abort') => 'aborted',
            str_contains($t, 'warn') => 'warning',
            preg_match('/pass|^ok$|success|done|good/', $t) === 1 => 'passed',
            default => null,
        };
    }

    /**
     * Whether a value with this severity is too low or too high, from the LibreNMS limits; without limits that
     * explain it, the usual direction for the metric (runtime and charge low, the rest high).
     */
    public static function direction(ReportRow $cell): string
    {
        $value = $cell->value;
        if ($value !== null) {
            if (($cell->limitLow !== null && $value < $cell->limitLow) || ($cell->limitLowWarn !== null && $value < $cell->limitLowWarn)) {
                return 'low';
            }

            if (($cell->limitHigh !== null && $value > $cell->limitHigh) || ($cell->limitWarn !== null && $value > $cell->limitWarn)) {
                return 'high';
            }
        }

        return in_array($cell->sensorClass, ['runtime', 'charge'], true) ? 'low' : 'high';
    }
}

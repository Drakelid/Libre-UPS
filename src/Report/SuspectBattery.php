<?php

declare(strict_types=1);

namespace Drakelid\UpsBattery\Report;

/**
 * Spots batteries that give less runtime than their load and charge say they should.
 * A short runtime alone is normal at high load or when the battery is still charging.
 */
final class SuspectBattery
{
    /**
     * True or false when runtime and load are known, null when the battery cannot be judged.
     * A missing charge value does not block the verdict (not every UPS reports it).
     */
    public static function evaluate(SuspectRule $rule, ?float $runtime, ?float $load, ?float $charge): ?bool
    {
        if ($runtime === null || $load === null) {
            return null;
        }

        return $runtime < $rule->maxRuntime
            && $load <= $rule->maxLoad
            && ($charge === null || $charge >= $rule->minCharge);
    }

    /**
     * Judges one device from all its sensors: shortest runtime, highest load and lowest charge decide.
     *
     * @param  ReportRow[]  $rows  Sensors of one device; classes other than runtime, load and charge are ignored.
     */
    public static function evaluateRows(SuspectRule $rule, array $rows): ?bool
    {
        $runtime = null;
        $load = null;
        $charge = null;

        foreach ($rows as $row) {
            if ($row->value === null) {
                continue;
            }

            if ($row->sensorClass === 'runtime') {
                $runtime = $runtime === null ? $row->value : min($runtime, $row->value);
            } elseif ($row->sensorClass === 'load') {
                $load = $load === null ? $row->value : max($load, $row->value);
            } elseif ($row->sensorClass === 'charge') {
                $charge = $charge === null ? $row->value : min($charge, $row->value);
            }
        }

        return self::evaluate($rule, $runtime, $load, $charge);
    }
}

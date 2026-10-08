<?php

declare(strict_types=1);

namespace Drakelid\UpsBattery\Report;

/**
 * Turns plugin thresholds into LibreNMS alert rules (Alerts > Alert Rules > Create rule > Advanced > Query),
 * so the colours on the page and the alerts you get stay in step. The plugin itself never creates alerts.
 *
 * Thresholds compare against the value LibreNMS stores (minutes for runtime, % for load and charge,
 * degrees Celsius for temperature), which is also what the alert rule sees.
 */
final class AlertRuleHint
{
    /**
     * One entry per critical and warning rule. The warning rule leaves out what the critical rule already
     * covers, so a sensor never triggers both.
     *
     * @return array<int, array{name: string, severity: string, rule: string}>
     */
    public static function forThresholds(Thresholds $thresholds): array
    {
        $hints = [];

        foreach ($thresholds->rules() as $class => $set) {
            [$criticalOp, $criticalValue] = $set['critical'];
            $base = 'macros.device_up = 1 AND sensors.sensor_class = "'.$class.'"';

            $hints[] = [
                'name' => 'UPS Battery: '.$class.' critical',
                'severity' => 'critical',
                'rule' => $base.' AND sensors.sensor_current '.$criticalOp.' '.NumberFormat::plain($criticalValue),
            ];

            if ($set['warning'] !== null) {
                [$warningOp, $warningValue] = $set['warning'];

                // Strictly beyond the warning value, but not beyond the critical value (the opposite comparison).
                $notCritical = $criticalOp === '<' ? '>=' : '<=';

                $hints[] = [
                    'name' => 'UPS Battery: '.$class.' warning',
                    'severity' => 'warning',
                    'rule' => $base
                        .' AND sensors.sensor_current '.$warningOp.' '.NumberFormat::plain($warningValue)
                        .' AND sensors.sensor_current '.$notCritical.' '.NumberFormat::plain($criticalValue),
                ];
            }
        }

        return $hints;
    }
}

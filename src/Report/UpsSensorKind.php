<?php

declare(strict_types=1);

namespace Drakelid\UpsBattery\Report;

/**
 * What a UPS sensor measures, for the UPS overview. LibreNMS stores some battery dates as runtime sensors
 * and reports battery, output and self-test status as state sensors whose names differ per vendor, so the
 * sensor class alone is not enough.
 */
enum UpsSensorKind: string
{
    case Runtime = 'runtime';
    case Charge = 'charge';
    case Load = 'load';
    case Temperature = 'temperature';
    /** APC: minutes until the recommended battery replacement date (negative when it has passed). */
    case ReplaceDue = 'replace_due';
    /** APC: minutes since the last battery replacement (LibreNMS stores the date as minutes from now). */
    case ReplacedAt = 'replaced_at';
    case BadPacks = 'bad_packs';
    case BatteryState = 'battery_state';
    case OutputState = 'output_state';
    case SelfTestState = 'selftest_state';
    case Other = 'other';

    /** sensor_index prefixes of the APC battery dates that LibreNMS discovers as runtime sensors. */
    public const REPLACE_DUE_INDEX = 'upsAdvBatteryRecommendedReplaceDate';

    public const REPLACED_AT_INDEX = 'upsBasicBatteryLastReplaceDate';

    /** Runtime sensors whose value is a date, not a runtime; they are left out of every runtime report. */
    public const DATE_RUNTIME_INDEXES = [self::REPLACE_DUE_INDEX, self::REPLACED_AT_INDEX];

    /** State sensors LibreNMS creates for APC (PowerNet-MIB) and the standard UPS-MIB (RFC 1628), by sensor_type. */
    private const STATES = [
        'upsAdvBatteryReplaceIndicator' => self::BatteryState,
        'upsBatteryStatusState' => self::BatteryState,
        'upsBasicBatteryStatus' => self::BatteryState,
        'upsBasicOutputStatus' => self::OutputState,
        'upsOutputSourceState' => self::OutputState,
        'upsAdvTestDiagnosticsResults' => self::SelfTestState,
        'upsTestResult' => self::SelfTestState,
    ];

    public static function of(string $class, string $type, string $index, string $descr): self
    {
        return match ($class) {
            'runtime' => match (true) {
                str_starts_with($index, self::REPLACE_DUE_INDEX) => self::ReplaceDue,
                str_starts_with($index, self::REPLACED_AT_INDEX) => self::ReplacedAt,
                default => self::Runtime,
            },
            'charge' => self::Charge,
            'load' => self::Load,
            'temperature' => self::Temperature,
            'count' => str_starts_with($index, 'upsAdvBatteryNumOfBadBattPacks') ? self::BadPacks : self::Other,
            'state' => self::STATES[$type] ?? self::stateByName($type.' '.$descr),
            default => self::Other,
        };
    }

    /** Other vendors: recognised by the words in the state name and description. */
    private static function stateByName(string $name): self
    {
        $name = strtolower($name);

        return match (true) {
            preg_match('/self.?test|diagnos|test.?result|battery.?test/', $name) === 1 => self::SelfTestState,
            preg_match('/output.?(source|status|state)|on.?battery|power.?source/', $name) === 1 => self::OutputState,
            preg_match('/batt|replace/', $name) === 1 => self::BatteryState,
            default => self::Other,
        };
    }

    /**
     * Whether an output state text means the UPS runs on its battery ("onBattery", "Battery").
     * A battery test is not counted. Null when the text says nothing either way.
     */
    public static function meansOnBattery(string $stateText): ?bool
    {
        $text = strtolower(trim($stateText));
        if ($text === '' || $text === '-' || str_contains($text, 'unknown')) {
            return null;
        }

        return str_contains($text, 'batt') && ! str_contains($text, 'test');
    }
}

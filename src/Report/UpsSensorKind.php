<?php

declare(strict_types=1);

namespace Drakelid\UpsBattery\Report;

/**
 * What a UPS sensor measures, for the UPS overview. The sensor class alone is not enough:
 *   - LibreNMS stores battery dates (APC, Eaton) and "time on battery" counters (UPS-MIB and several vendor MIBs)
 *     as runtime sensors, and other devices use the runtime class for uptimes and run hours;
 *   - battery, output and self-test status are state sensors whose names differ per vendor, and NUT reports them
 *     as True/False flags.
 * The names below were checked against the LibreNMS sensor definitions of every UPS OS (os_discovery YAML and
 * includes/discovery/sensors, LibreNMS 26.x).
 */
enum UpsSensorKind: string
{
    case Runtime = 'runtime';
    case Charge = 'charge';
    case Load = 'load';
    case Temperature = 'temperature';
    /** APC: minutes until the recommended battery replacement date (negative when it has passed). */
    case ReplaceDue = 'replace_due';
    /** APC, Eaton: minutes since the last battery replacement (LibreNMS stores the date as minutes from now). */
    case ReplacedAt = 'replaced_at';
    /** Time the UPS has been on battery so far (0 on mains), not the time it has left. */
    case OnBatteryTime = 'on_battery_time';
    case BadPacks = 'bad_packs';
    case BatteryState = 'battery_state';
    case OutputState = 'output_state';
    case SelfTestState = 'selftest_state';
    /** Mains / input / utility status (state sensor). */
    case InputState = 'input_state';
    /** Mains / input voltage or frequency reading. */
    case InputVoltage = 'input_voltage';
    case InputFrequency = 'input_frequency';
    case Other = 'other';

    /** sensor_index prefix of the APC recommended battery replacement date. */
    public const REPLACE_DUE_INDEX = 'upsAdvBatteryRecommendedReplaceDate';

    /** sensor_index prefixes of the last battery replacement date (APC PowerNet-MIB, Eaton XUPS-MIB). */
    public const REPLACED_AT_INDEXES = ['upsBasicBatteryLastReplaceDate', 'xupsBatteryLastReplacedDate'];

    /**
     * SQL LIKE patterns on sensor_index of runtime sensors that are no remaining battery time: battery dates,
     * time on battery (UPS-MIB upsSecondsOnBattery, Argus upsMinutesOnBattery) and APC InRow run hours.
     * They are left out of every runtime report.
     */
    public const NOT_RUNTIME_INDEX_PATTERNS = [
        self::REPLACE_DUE_INDEX.'%',
        'upsBasicBatteryLastReplaceDate%',
        'xupsBatteryLastReplacedDate%',
        '%SecondsOnBattery%',
        '%MinutesOnBattery%',
        'airIRRP%',
    ];

    /** UPS-MIB (RFC 1628) "Time on battery": LibreNMS discovers it with sensor_type "rfc1628" and index 100. */
    public const RFC1628_ON_BATTERY = ['type' => 'rfc1628', 'index' => '100'];

    /** Known state sensors by sensor_type (state name), per vendor MIB. */
    private const STATES = [
        // APC PowerNet-MIB
        'upsAdvBatteryReplaceIndicator' => self::BatteryState,
        'upsBasicBatteryStatus' => self::BatteryState,
        'upsBasicOutputStatus' => self::OutputState,
        'upsAdvTestDiagnosticsResults' => self::SelfTestState,
        // UPS-MIB (RFC 1628)
        'upsBatteryStatusState' => self::BatteryState,
        'upsOutputSourceState' => self::OutputState,
        'upsTestResult' => self::SelfTestState,
        // MGE / Eaton (MG-SNMP-UPS-MIB)
        'upsmgOutputOnBattery' => self::OutputState,
        // CyberPower, NetAgent
        'upsBaseBatteryStatus' => self::BatteryState,
        'upsAdvanceBatteryReplaceIndicator' => self::BatteryState,
        'upsSmartBatteryReplaceIndicator' => self::BatteryState,
        'upsBaseOutputStatus' => self::OutputState,
        // Socomec, Vertiv
        'upsBatteryStatus' => self::BatteryState,
        'upsOutputSource' => self::OutputState,
        // PowerWalker
        'upsESystemStatus' => self::OutputState,
        // Webpower / USHA
        'upsBatteryTest' => self::SelfTestState,
        'upsBatteryGroupStatus' => self::BatteryState,
        // Eaton XUPS-MIB
        'xupsBatteryLowCapacity' => self::BatteryState,
        // NUT through SNMP extend (True/False flags)
        'UPSOnBattery' => self::OutputState,
        'UPSOnLine' => self::OutputState,
        'UPSLowBattery' => self::BatteryState,
        'UPSBatteryReplace' => self::BatteryState,
    ];

    public static function of(string $class, string $type, string $index, string $descr): self
    {
        return match ($class) {
            'runtime' => self::runtimeKind($type, $index, $descr),
            'charge' => self::Charge,
            'load' => self::Load,
            'temperature' => self::Temperature,
            'count' => str_starts_with($index, 'upsAdvBatteryNumOfBadBattPacks') ? self::BadPacks : self::Other,
            'voltage' => self::isInput($type, $index, $descr) ? self::InputVoltage : self::Other,
            'frequency' => self::isInput($type, $index, $descr) ? self::InputFrequency : self::Other,
            'state' => self::STATES[$type] ?? self::stateByName($type.' '.$descr),
            default => self::Other,
        };
    }

    /** Whether a device with this sensor is a UPS (or another battery-backed device) for the UPS overview. */
    public function makesUps(): bool
    {
        return in_array($this, [self::Runtime, self::Charge, self::ReplaceDue, self::ReplacedAt], true);
    }

    private static function runtimeKind(string $type, string $index, string $descr): self
    {
        if (str_starts_with($index, self::REPLACE_DUE_INDEX)) {
            return self::ReplaceDue;
        }

        foreach (self::REPLACED_AT_INDEXES as $prefix) {
            if (str_starts_with($index, $prefix)) {
                return self::ReplacedAt;
            }
        }

        $text = strtolower($index.' '.$descr);
        if (($type === self::RFC1628_ON_BATTERY['type'] && $index === self::RFC1628_ON_BATTERY['index'])
            || preg_match('/(seconds|minutes|time|runtime|running) ?on ?batt/', $text) === 1) {
            return self::OnBatteryTime;
        }

        // Uptimes, error counters, run hours of filters and fans, test durations: runtime class, but no battery time.
        if (preg_match('/uptime|connection|online time|showtime|link up|stream|error|unavailable|period|filter|fan ?\d|run ?hours|condensate|pump|test/', $text) === 1) {
            return self::Other;
        }

        return self::Runtime;
    }

    /** Other vendors: recognised by the words in the state name and description. */
    private static function stateByName(string $name): self
    {
        $name = strtolower($name);

        return match (true) {
            preg_match('/self.?test|diagnos|test.?result|battery.?test/', $name) === 1 => self::SelfTestState,
            preg_match('/output.?(source|status|state)|on.?battery|power.?(source|state)/', $name) === 1 => self::OutputState,
            // "Input Line Cause" is the reason of the last transfer, not the present state.
            preg_match('/mains|utility|input|\bac\b|line.?(status|fail)/', $name) === 1 && ! str_contains($name, 'cause') => self::InputState,
            preg_match('/batt|replace|charger/', $name) === 1 => self::BatteryState,
            default => self::Other,
        };
    }

    /**
     * Whether an output state means the UPS runs on its battery, from its text ("onBattery", "On Battery",
     * "battery") or, for True/False flags (NUT), from its name ("UPSOnBattery", "UPSOnLine").
     * Battery tests, calibration and "on utility and battery" are not counted. Null when it says nothing either way.
     */
    public static function meansOnBattery(string $stateText, string $name = ''): ?bool
    {
        $text = strtolower(trim($stateText));
        if ($text === '' || $text === '-' || str_contains($text, 'unknown')) {
            return null;
        }

        if (in_array($text, ['true', 'false', 'yes', 'no'], true)) {
            $flag = $text === 'true' || $text === 'yes';
            $name = strtolower($name);

            return match (true) {
                preg_match('/on.?batt/', $name) === 1 => $flag,
                preg_match('/on.?line|mains|utility|input.?power/', $name) === 1 => ! $flag,
                default => null,
            };
        }

        if (preg_match('/test|calibrat|utility|mains|line|input power/', $text) === 1) {
            return false;
        }

        return str_contains($text, 'batt');
    }

    /** A voltage or frequency reading of the mains / input side ("Input", "MainsVolt 1", "Input #1 Voltage", "L1"). */
    private static function isInput(string $type, string $index, string $descr): bool
    {
        $text = strtolower($descr.' '.$index);

        return preg_match('/input|mains|utility|\bline\b|\bac\b|\bl[123]\b|phase/', $text) === 1
            && preg_match('/output|batt|bypass|\bdc\b|load|rectifier.?output|nominal|rating/', $text) !== 1;
    }

    /**
     * What a mains / input status text says about the mains: false = mains present ("normal", "Output OK"),
     * true = mains gone ("No Voltage", "Blackout", "fail"), null = cannot tell (over / under voltage, unknown).
     */
    public static function inputMeansOnBattery(string $stateText): ?bool
    {
        $text = strtolower(trim($stateText));

        return match (true) {
            preg_match('/fail|lost|outage|no.?voltage|black.?out|absent|not.?present|power.?off/', $text) === 1 => true,
            preg_match('/^normal$|^ok$|output ok|present|^good$|^on$|available|^true$/', $text) === 1 => false,
            default => null,
        };
    }

    /** True/False state texts (NUT flags) say nothing on their own; the cell shows the sensor name instead. */
    public static function isFlagText(string $stateText): bool
    {
        return in_array(strtolower(trim($stateText)), ['true', 'false'], true);
    }
}

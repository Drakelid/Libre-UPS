<?php

declare(strict_types=1);

namespace Drakelid\UpsBattery\Report;

/** One UPS in the UPS overview. Every cell is the sensor that decides it, or null when the UPS has none. */
final readonly class UpsRow
{
    /**
     * @param  ReportRow[]  $sensors  All sensors of the UPS, for the details.
     * @param  bool|null  $onBattery  From the output state sensor; null without one.
     * @param  bool|null  $suspect  Suspect battery verdict; null without runtime or load.
     * @param  array<int, array{key: string, severity: string, n: int|null}>  $issues  Why the UPS needs attention,
     *                                                                                 most severe first (see UpsBuilder::issues).
     */
    public function __construct(
        public int $deviceId,
        public string $hostname,
        public string $displayName,
        public string $deviceUrl,
        public ?string $location,
        public string $os,
        public bool $deviceUp,
        public Severity $severity,
        public ?ReportRow $runtime,
        public ?ReportRow $charge,
        public ?ReportRow $load,
        public ?ReportRow $temperature,
        public ?ReportRow $battery,
        public ?ReportRow $badPacks,
        public ?ReportRow $output,
        public ?ReportRow $selfTest,
        public ?bool $onBattery,
        public ?bool $suspect,
        public BatterySwap $swap,
        public array $sensors,
        public array $issues = [],
    ) {}

    /** True when anything about the UPS needs a look. */
    public function needsAttention(): bool
    {
        return $this->severity === Severity::Warning || $this->severity === Severity::Critical;
    }

    /**
     * @param  callable(string): string  $classLabel  Label of a sensor class, e.g. "voltage" => "Voltage".
     * @return array<string, mixed>
     */
    public function toArray(callable $classLabel): array
    {
        return [
            'device_id' => $this->deviceId,
            'hostname' => $this->hostname,
            'display_name' => $this->displayName,
            'device_url' => $this->deviceUrl,
            'location' => $this->location,
            'os' => $this->os,
            'device_up' => $this->deviceUp,
            'severity' => $this->severity->value,
            'on_battery' => $this->onBattery,
            'suspect' => $this->suspect,
            'runtime' => self::cell($this->runtime),
            'charge' => self::cell($this->charge),
            'load' => self::cell($this->load),
            'temperature' => self::cell($this->temperature),
            'battery' => self::cell($this->battery),
            'bad_packs' => self::cell($this->badPacks),
            'output' => self::cell($this->output),
            'self_test' => self::cell($this->selfTest),
            'swap' => $this->swap->toArray(),
            'issues' => $this->issues,
            'sensors' => array_map(fn (ReportRow $sensor): array => [
                'class' => $sensor->sensorClass,
                'label' => $classLabel($sensor->sensorClass),
                'sensor_descr' => self::name($sensor),
                'value_formatted' => $sensor->valueFormatted,
                'severity' => $sensor->severity->value,
                'sensor_url' => $sensor->sensorUrl,
                'graph_url' => $sensor->graphUrl,
            ], $this->sensors),
        ];
    }

    /** Sensors without a description get their state name or sensor id, so the details never show a bare value. */
    private static function name(ReportRow $sensor): string
    {
        if (trim($sensor->sensorDescr) !== '') {
            return $sensor->sensorDescr;
        }

        return trim($sensor->sensorType) !== '' && $sensor->sensorType !== $sensor->sensorClass
            ? $sensor->sensorType
            : 'Sensor '.$sensor->sensorId;
    }

    /** @return array<string, mixed>|null */
    private static function cell(?ReportRow $row): ?array
    {
        return $row === null ? null : [
            'sensor_id' => $row->sensorId,
            'sensor_descr' => self::name($row),
            'sensor_url' => $row->sensorUrl,
            'graph_url' => $row->graphUrl,
            'trend_url' => $row->trendUrl,
            'value' => $row->value,
            'value_formatted' => $row->valueFormatted,
            'severity' => $row->severity->value,
            'last_updated' => $row->lastUpdate,
        ];
    }
}

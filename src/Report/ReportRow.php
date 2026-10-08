<?php

declare(strict_types=1);

namespace Drakelid\UpsBattery\Report;

final readonly class ReportRow
{
    /**
     * @param  bool  $hydrated  False for rows from the light query: formatted value, unit and links are still
     *                          missing and are filled in (withDisplay) for the rows that are actually shown.
     *                          This is an explicit flag because a formatted value can legitimately be empty.
     */
    public function __construct(
        public int $deviceId,
        public string $hostname,
        public string $displayName,
        public string $deviceUrl,
        public ?string $location,
        public string $os,
        public bool $deviceUp,
        public int $sensorId,
        public string $sensorDescr,
        public ?float $value,
        public string $valueFormatted,
        public string $unit,
        public Severity $severity,
        public ?float $limitLow,
        public ?float $limitLowWarn,
        public ?float $limitWarn,
        public ?float $limitHigh,
        public ?string $lastUpdate,
        public string $sensorUrl = '',
        public string $graphUrl = '',
        public string $trendUrl = '',
        public string $sensorClass = '',
        public bool $hydrated = true,
        public string $sensorType = '',
        public string $sensorIndex = '',
    ) {}

    /** Copy with the presentation fields that are only needed for the rows that are actually shown. */
    public function withDisplay(string $valueFormatted, string $unit, string $deviceUrl, string $sensorUrl, string $graphUrl, string $trendUrl): self
    {
        return new self(
            $this->deviceId,
            $this->hostname,
            $this->displayName,
            $deviceUrl,
            $this->location,
            $this->os,
            $this->deviceUp,
            $this->sensorId,
            $this->sensorDescr,
            $this->value,
            $valueFormatted,
            $unit,
            $this->severity,
            $this->limitLow,
            $this->limitLowWarn,
            $this->limitWarn,
            $this->limitHigh,
            $this->lastUpdate,
            $sensorUrl,
            $graphUrl,
            $trendUrl,
            $this->sensorClass,
            true,
            $this->sensorType,
            $this->sensorIndex,
        );
    }

    public function withSeverity(Severity $severity): self
    {
        return $this->with(['severity' => $severity]);
    }

    /** @param  array<string, mixed>  $changes  Constructor arguments by name. */
    public function with(array $changes): self
    {
        return new self(...array_merge(get_object_vars($this), $changes));
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'device_id' => $this->deviceId,
            'hostname' => $this->hostname,
            'display_name' => $this->displayName,
            'device_url' => $this->deviceUrl,
            'location' => $this->location,
            'os' => $this->os,
            'device_up' => $this->deviceUp,
            'sensor_id' => $this->sensorId,
            'sensor_descr' => $this->sensorDescr,
            'sensor_url' => $this->sensorUrl,
            'graph_url' => $this->graphUrl,
            'trend_url' => $this->trendUrl,
            'value' => $this->value,
            'value_formatted' => $this->valueFormatted,
            'unit' => $this->unit,
            'severity' => $this->severity->value,
            'limits' => [
                'low' => $this->limitLow,
                'low_warn' => $this->limitLowWarn,
                'warn' => $this->limitWarn,
                'high' => $this->limitHigh,
            ],
            'last_updated' => $this->lastUpdate,
        ];
    }
}

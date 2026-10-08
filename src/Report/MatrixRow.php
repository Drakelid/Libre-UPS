<?php

declare(strict_types=1);

namespace Drakelid\UpsBattery\Report;

/** One device with one cell (the worst sensor) per selected sensor class. */
final readonly class MatrixRow
{
    /**
     * @param  array<string, ReportRow>  $cells  Keyed by sensor class; classes without sensors are absent.
     */
    public function __construct(
        public int $deviceId,
        public string $hostname,
        public string $displayName,
        public string $deviceUrl,
        public ?string $location,
        public string $os,
        public bool $deviceUp,
        public array $cells,
    ) {
    }

    /** @param  array<string, ReportRow>  $cells */
    public function withCells(array $cells, string $deviceUrl): self
    {
        return new self($this->deviceId, $this->hostname, $this->displayName, $deviceUrl, $this->location, $this->os, $this->deviceUp, $cells);
    }

    /**
     * @param  string[]  $classes
     * @return array<string, mixed>
     */
    public function toArray(array $classes): array
    {
        $cells = [];
        foreach ($classes as $class) {
            $cell = $this->cells[$class] ?? null;
            $cells[$class] = $cell === null ? null : [
                'sensor_id' => $cell->sensorId,
                'sensor_descr' => $cell->sensorDescr,
                'sensor_url' => $cell->sensorUrl,
                'graph_url' => $cell->graphUrl,
                'value' => $cell->value,
                'value_formatted' => $cell->valueFormatted,
                'unit' => $cell->unit,
                'severity' => $cell->severity->value,
                'last_updated' => $cell->lastUpdate,
            ];
        }

        return [
            'device_id' => $this->deviceId,
            'hostname' => $this->hostname,
            'display_name' => $this->displayName,
            'device_url' => $this->deviceUrl,
            'location' => $this->location,
            'os' => $this->os,
            'device_up' => $this->deviceUp,
            'cells' => $cells,
        ];
    }
}

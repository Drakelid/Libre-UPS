<?php

declare(strict_types=1);

use Drakelid\UpsBattery\Report\MatrixFilters;
use Drakelid\UpsBattery\Report\ReportFilters;
use Drakelid\UpsBattery\Report\ReportRow;
use Drakelid\UpsBattery\Report\Severity;

/**
 * Builds a ReportRow with sensible defaults; override any constructor argument by name.
 *
 * @param  array<string, mixed>  $overrides
 */
function makeRow(array $overrides = []): ReportRow
{
    $defaults = [
        'deviceId' => 1,
        'hostname' => 'ups-01',
        'displayName' => 'ups-01',
        'deviceUrl' => 'https://librenms.example/device/1',
        'location' => 'Site A',
        'os' => 'apc',
        'deviceUp' => true,
        'sensorId' => 1,
        'sensorDescr' => 'Battery runtime',
        'value' => 10.0,
        'valueFormatted' => '10m',
        'unit' => 'min',
        'severity' => Severity::Ok,
        'limitLow' => null,
        'limitLowWarn' => null,
        'limitWarn' => null,
        'limitHigh' => null,
        'lastUpdate' => '2026-10-07T08:55:00+02:00',
    ];

    return new ReportRow(...array_merge($defaults, $overrides));
}

/**
 * Builds ReportFilters from raw query input using the plugin's standard defaults.
 *
 * @param  array<string, mixed>  $input
 * @param  array<string, mixed>  $defaults
 */
function makeFilters(array $input = [], array $defaults = ['type' => 'power', 'class' => 'runtime', 'limit' => 25]): ReportFilters
{
    return ReportFilters::fromArray($input, $defaults);
}

/**
 * Builds MatrixFilters from raw query input using the plugin's standard defaults.
 *
 * @param  array<string, mixed>  $input
 * @param  array<string, mixed>  $defaults
 */
function makeMatrixFilters(array $input = [], array $defaults = ['type' => 'power', 'limit' => 25]): MatrixFilters
{
    return MatrixFilters::fromArray($input, $defaults);
}

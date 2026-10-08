<?php

declare(strict_types=1);

namespace Drakelid\UpsBattery\Report;

use App\Models\Device;
use App\Models\DeviceGroup;
use App\Models\Sensor;
use App\Models\User;
use App\Models\UserPref;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use LibreNMS\Util\Url;
use stdClass;
use Throwable;

/**
 * Reads sensor data from the LibreNMS database. Depends on LibreNMS models,
 * so it is only exercised inside a LibreNMS installation.
 *
 * Rows are loaded in two phases: a cheap query without Eloquent hydration finds, sorts and summarises
 * all matching sensors; only the rows that are actually shown are then loaded as full models for formatting.
 *
 * Methods that take a nullable user run without access filtering when it is null. That is meant for
 * system jobs such as the weekly email; every request made by a person passes the logged-in user.
 */
final class SensorReportService
{
    public const MAX_ROWS = 50000;

    /** Seconds the filter options are cached per user. */
    private const OPTIONS_TTL = 60;

    /** Sensor classes shown on the device overview card. */
    private const DEVICE_CARD_CLASSES = ['runtime', 'charge', 'load'];

    private const LIGHT_COLUMNS = [
        'sensors.sensor_id',
        'sensors.device_id',
        'sensors.sensor_descr',
        'sensors.sensor_current',
        'sensors.sensor_limit',
        'sensors.sensor_limit_warn',
        'sensors.sensor_limit_low',
        'sensors.sensor_limit_low_warn',
        'sensors.lastupdate',
        'devices.hostname',
        'devices.display',
        'devices.os',
        'devices.status',
        'locations.location as location_name',
    ];

    // ---- Filter options ----

    /**
     * All filter options for a user, cached for a short time.
     *
     * @return array{
     *     types: array<int, array{value: string, count: int}>,
     *     os: array<int, array{value: string, count: int}>,
     *     groups: array<int, array{id: int, name: string}>,
     *     classes: array<int, array{value: string, label: string, unit: string, count: int}>
     * }
     */
    public function options(User $user, ?string $type): array
    {
        $key = sprintf('ups-battery.options.%d.%s', (int) $user->user_id, $type ?? '*');

        return Cache::remember($key, self::OPTIONS_TTL, fn (): array => [
            'types' => $this->availableTypes($user),
            'os' => $this->availableOs($user, $type),
            'groups' => $this->availableGroups($user),
            'classes' => $this->availableClasses($user, $type),
        ]);
    }

    /** @return array<int, array{value: string, count: int}> */
    public function availableTypes(User $user): array
    {
        return Device::query()
            ->hasAccess($user)
            ->where('disabled', 0)
            ->whereNotNull('type')
            ->where('type', '!=', '')
            ->selectRaw('type as item, COUNT(*) as total')
            ->groupBy('type')
            ->orderBy('type')
            ->get()
            ->map(fn ($row): array => ['value' => (string) $row->item, 'count' => (int) $row->total])
            ->all();
    }

    /** @return array<int, array{value: string, count: int}> */
    public function availableOs(User $user, ?string $type): array
    {
        return Device::query()
            ->hasAccess($user)
            ->where('disabled', 0)
            ->whereNotNull('os')
            ->where('os', '!=', '')
            ->when($type, fn ($q) => $q->where('type', $type))
            ->selectRaw('os as item, COUNT(*) as total')
            ->groupBy('os')
            ->orderBy('os')
            ->get()
            ->map(fn ($row): array => ['value' => (string) $row->item, 'count' => (int) $row->total])
            ->all();
    }

    /** @return array<int, array{id: int, name: string}> Only groups the user may see. */
    public function availableGroups(User $user): array
    {
        return DeviceGroup::query()
            ->hasAccess($user)
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn ($group): array => ['id' => (int) $group->id, 'name' => (string) $group->name])
            ->all();
    }

    /** @return array<int, array{value: string, label: string, unit: string, count: int}> */
    public function availableClasses(User $user, ?string $type): array
    {
        $fahrenheit = $this->usesFahrenheit($user);

        $classes = Sensor::query()
            ->hasAccess($user)
            ->join('devices', 'devices.device_id', '=', 'sensors.device_id')
            ->where('sensors.sensor_deleted', 0)
            ->where('devices.disabled', 0)
            ->when($type, fn ($q) => $q->where('devices.type', $type))
            ->selectRaw('sensors.sensor_class as item, COUNT(*) as total')
            ->groupBy('sensors.sensor_class')
            ->get()
            ->map(function ($row) use ($fahrenheit): array {
                $class = (string) $row->item;

                return [
                    'value' => $class,
                    'label' => $this->classLabel($class),
                    'unit' => $this->classUnit($class, $fahrenheit),
                    'count' => (int) $row->total,
                ];
            })
            ->all();

        usort($classes, fn (array $a, array $b): int => strcasecmp($a['label'], $b['label']));

        return $classes;
    }

    // ---- Reports ----

    /**
     * Single-metric report.
     *
     * @return array{rows: ReportRow[], total: int, summaryRows: ReportRow[], unit: string}
     *
     * @throws TooManyRowsException
     */
    public function report(?User $user, ReportFilters $filters, Thresholds $thresholds): array
    {
        $rows = $this->loadRows($user, $filters->class, $filters->type, $filters->os, $filters->group, $filters->q, $filters->sensor, $thresholds);
        $processed = (new RowProcessor)->process($rows, $filters, $filters->class === 'state');
        $processed['rows'] = $this->hydrate($processed['rows']);
        $processed['unit'] = $this->classUnit($filters->class, $this->usesFahrenheit($user));

        return $processed;
    }

    /**
     * "Compare metrics" report: one row per device, one cell per class.
     *
     * @return array{rows: MatrixRow[], total: int, classes: array<int, array{value: string, label: string, unit: string}>}
     *
     * @throws TooManyRowsException
     */
    public function matrix(?User $user, MatrixFilters $filters, Thresholds $thresholds, SuspectRule $rule): array
    {
        $rowsByClass = [];
        foreach ($filters->classes as $class) {
            $rowsByClass[$class] = $this->loadRows($user, $class, $filters->type, $filters->os, $filters->group, $filters->q, $filters->sensor, $thresholds);
        }

        $built = (new MatrixBuilder)->build($rowsByClass, $filters, $rule);

        $cells = [];
        foreach ($built['rows'] as $row) {
            foreach ($row->cells as $cell) {
                $cells[] = $cell;
            }
        }

        $hydrated = [];
        foreach ($this->hydrate($cells) as $cell) {
            $hydrated[$cell->sensorId] = $cell;
        }

        $rows = array_map(
            fn (MatrixRow $row): MatrixRow => $row->withCells(
                array_map(fn (ReportRow $cell): ReportRow => $hydrated[$cell->sensorId] ?? $cell, $row->cells),
                $this->deviceUrl($row->deviceId),
            ),
            $built['rows'],
        );

        $fahrenheit = $this->usesFahrenheit($user);
        $classes = array_map(fn (string $class): array => [
            'value' => $class,
            'label' => $this->classLabel($class),
            'unit' => $this->classUnit($class, $fahrenheit),
        ], $filters->classes);

        return ['rows' => $rows, 'total' => $built['total'], 'classes' => $classes];
    }

    /**
     * Battery-related sensors of one device (device overview card).
     *
     * @return ReportRow[]
     */
    public function forDevice(Device $device, User $user, Thresholds $thresholds): array
    {
        $fahrenheit = $this->usesFahrenheit($user);

        return $this->deviceCardQuery($device)
            ->orderBy('sensor_class')
            ->orderBy('sensor_descr')
            ->get()
            ->map(function (Sensor $sensor) use ($device, $fahrenheit, $thresholds): ReportRow {
                $sensor->setRelation('device', $device);

                return $this->toRow($sensor, $fahrenheit, $thresholds);
            })
            ->all();
    }

    public function hasDeviceSensors(Device $device): bool
    {
        return $this->deviceCardQuery($device)->exists();
    }

    // ---- Internals ----

    private function deviceCardQuery(Device $device): Builder
    {
        return Sensor::query()
            ->where('device_id', $device->device_id)
            ->where('sensor_deleted', 0)
            ->whereIn('sensor_class', self::DEVICE_CARD_CLASSES);
    }

    /**
     * Rows for one sensor class. State sensors are loaded as full models (their severity needs the state
     * translations); every other class uses the cheap query and is hydrated later, only for the rows shown.
     *
     * @return ReportRow[]
     *
     * @throws TooManyRowsException
     */
    private function loadRows(?User $user, string $class, ?string $type, ?string $os, ?int $group, ?string $q, ?string $sensorName, Thresholds $thresholds): array
    {
        $fahrenheit = $this->usesFahrenheit($user);
        $query = $this->baseQuery($user, $class, $type, $os, $group, $q, $sensorName)->limit(self::MAX_ROWS + 1);

        if ($class === 'state') {
            $sensors = $query
                ->select('sensors.*', 'locations.location as location_name')
                ->with(['device', 'translations'])
                ->get();
            $this->guardCount($sensors->count());

            $rows = [];
            foreach ($sensors as $sensor) {
                if ($sensor->device !== null) {
                    $rows[] = $this->toRow($sensor, $fahrenheit, $thresholds);
                }
            }

            return $rows;
        }

        $items = $query->select(self::LIGHT_COLUMNS)->toBase()->get();
        $this->guardCount($items->count());

        $rows = [];
        foreach ($items as $item) {
            $rows[] = $this->toLightRow($item, $class, $fahrenheit, $thresholds);
        }

        return $rows;
    }

    private function baseQuery(?User $user, string $class, ?string $type, ?string $os, ?int $group, ?string $q, ?string $sensorName): Builder
    {
        $like = $q === null ? null : '%'.addcslashes($q, '%_\\').'%';
        $sensorLike = $sensorName === null ? null : '%'.addcslashes($sensorName, '%_\\').'%';

        return Sensor::query()
            ->when($user, fn ($query) => $query->hasAccess($user))
            ->join('devices', 'devices.device_id', '=', 'sensors.device_id')
            ->leftJoin('locations', 'locations.id', '=', 'devices.location_id')
            ->where('sensors.sensor_class', $class)
            ->where('sensors.sensor_deleted', 0)
            ->where('devices.disabled', 0)
            ->when($type, fn ($query) => $query->where('devices.type', $type))
            ->when($os, fn ($query) => $query->where('devices.os', $os))
            ->when($group, fn ($query) => $query->inDeviceGroup($group))
            ->when($sensorLike, fn ($query) => $query->where('sensors.sensor_descr', 'like', $sensorLike))
            ->when($like, fn ($query) => $query->where(fn ($w) => $w
                ->where('devices.hostname', 'like', $like)
                ->orWhere('devices.sysName', 'like', $like)
                ->orWhere('devices.display', 'like', $like)
                ->orWhere('locations.location', 'like', $like)));
    }

    /** @throws TooManyRowsException */
    private function guardCount(int $count): void
    {
        if ($count > self::MAX_ROWS) {
            throw new TooManyRowsException(self::MAX_ROWS);
        }
    }

    private function toLightRow(stdClass $item, string $class, bool $fahrenheit, Thresholds $thresholds): ReportRow
    {
        $stored = $this->floatOrNull($item->sensor_current);
        $value = $this->convert($stored, $class, $fahrenheit);
        $low = $this->convert($this->floatOrNull($item->sensor_limit_low), $class, $fahrenheit);
        $lowWarn = $this->convert($this->floatOrNull($item->sensor_limit_low_warn), $class, $fahrenheit);
        $warn = $this->convert($this->floatOrNull($item->sensor_limit_warn), $class, $fahrenheit);
        $high = $this->convert($this->floatOrNull($item->sensor_limit), $class, $fahrenheit);

        $hostname = (string) $item->hostname;

        return new ReportRow(
            deviceId: (int) $item->device_id,
            hostname: $hostname,
            displayName: (string) ($item->display ?: $hostname),
            deviceUrl: '',
            location: $item->location_name === null ? null : (string) $item->location_name,
            os: (string) $item->os,
            deviceUp: (bool) $item->status,
            sensorId: (int) $item->sensor_id,
            sensorDescr: (string) $item->sensor_descr,
            value: $value,
            valueFormatted: '',
            unit: '',
            // Plugin thresholds compare the stored value (°C for temperature), not the value shown to the user.
            severity: $thresholds->severity($class, $stored) ?? Severity::fromLimits($value, $low, $lowWarn, $warn, $high),
            limitLow: $low,
            limitLowWarn: $lowWarn,
            limitWarn: $warn,
            limitHigh: $high,
            lastUpdate: $this->iso($item->lastupdate),
            sensorClass: $class,
            hydrated: false,
        );
    }

    /** Full row from an Eloquent sensor whose device relation is loaded. */
    private function toRow(Sensor $sensor, bool $fahrenheit, Thresholds $thresholds): ReportRow
    {
        $device = $sensor->device;
        $class = (string) $sensor->sensor_class;

        $stored = $this->floatOrNull($sensor->sensor_current);
        $value = $this->convert($stored, $class, $fahrenheit);
        $low = $this->convert($this->floatOrNull($sensor->sensor_limit_low), $class, $fahrenheit);
        $lowWarn = $this->convert($this->floatOrNull($sensor->sensor_limit_low_warn), $class, $fahrenheit);
        $warn = $this->convert($this->floatOrNull($sensor->sensor_limit_warn), $class, $fahrenheit);
        $high = $this->convert($this->floatOrNull($sensor->sensor_limit), $class, $fahrenheit);

        if ($class === 'state') {
            $generic = $sensor->currentTranslation()?->state_generic_value;
            $severity = Severity::fromStateGeneric($generic === null ? null : (int) $generic);
        } else {
            $severity = $thresholds->severity($class, $stored) ?? Severity::fromLimits($value, $low, $lowWarn, $warn, $high);
        }

        $location = $sensor->getAttributes()['location_name'] ?? null;

        return new ReportRow(
            deviceId: (int) $device->device_id,
            hostname: (string) $device->hostname,
            displayName: (string) $device->displayName(),
            deviceUrl: $this->deviceUrl((int) $device->device_id),
            location: $location === null ? null : (string) $location,
            os: (string) $device->os,
            deviceUp: (bool) $device->status,
            sensorId: (int) $sensor->sensor_id,
            sensorDescr: (string) $sensor->sensor_descr,
            value: $value,
            valueFormatted: $this->formatValue($sensor, $stored),
            unit: (string) $sensor->unit(),
            severity: $severity,
            limitLow: $low,
            limitLowWarn: $lowWarn,
            limitWarn: $warn,
            limitHigh: $high,
            lastUpdate: $this->iso($sensor->lastupdate),
            sensorUrl: Urls::relative((string) Url::sensorUrl($sensor)),
            graphUrl: $this->graphUrl($sensor),
            trendUrl: $this->trendUrl($sensor),
            sensorClass: $class,
            hydrated: true,
        );
    }

    /**
     * Fills in the presentation fields (formatted value, unit, links) for light rows.
     * Rows that are already complete are returned unchanged.
     *
     * @param  ReportRow[]  $rows
     * @return ReportRow[]
     */
    private function hydrate(array $rows): array
    {
        $ids = [];
        foreach ($rows as $row) {
            if (! $row->hydrated) {
                $ids[] = $row->sensorId;
            }
        }

        if ($ids === []) {
            return $rows;
        }

        $sensors = Sensor::query()->whereIntegerInRaw('sensor_id', $ids)->get()->keyBy('sensor_id');

        return array_map(function (ReportRow $row) use ($sensors): ReportRow {
            if ($row->hydrated) {
                return $row;
            }

            /** @var Sensor|null $sensor */
            $sensor = $sensors->get($row->sensorId);
            if ($sensor === null) {
                return $row; // deleted between the two queries
            }

            return $row->withDisplay(
                $this->formatValue($sensor, $this->floatOrNull($sensor->sensor_current)),
                (string) $sensor->unit(),
                $this->deviceUrl($row->deviceId),
                Urls::relative((string) Url::sensorUrl($sensor)),
                $this->graphUrl($sensor),
                $this->trendUrl($sensor),
            );
        }, $rows);
    }

    /**
     * Text for a value. Runtime is formatted here because LibreNMS' formatter returns an empty string for 0
     * (the most important value for a UPS) and is fed fractional minutes.
     */
    private function formatValue(Sensor $sensor, ?float $stored): string
    {
        if ((string) $sensor->sensor_class === 'runtime') {
            $text = RuntimeFormatter::format($stored);

            return $text === '' ? '-' : $text;
        }

        return (string) $sensor->formatValue();
    }

    private function deviceUrl(int $deviceId): string
    {
        return Urls::relative((string) Url::deviceUrl($deviceId));
    }

    private function graphUrl(Sensor $sensor): string
    {
        return Urls::relative(route('graph', [
            'type' => $sensor->getGraphType(),
            'id' => $sensor->sensor_id,
            'from' => '-1d',
            'width' => 340,
            'height' => 100,
            'legend' => 'no',
        ]));
    }

    /** The LibreNMS graph page for the sensor over the last year, to see slow changes such as an ageing battery. */
    private function trendUrl(Sensor $sensor): string
    {
        return Urls::relative(route('graphs', [
            'path' => 'type='.$sensor->getGraphType().'/id='.$sensor->sensor_id.'/from=-1y',
        ]));
    }

    private function usesFahrenheit(?User $user): bool
    {
        return $user !== null && UserPref::getPref($user, 'temp_units') === 'f';
    }

    /** Temperatures are converted so value, limits, sorting, summary and CSV match what the user sees. */
    private function convert(?float $value, string $class, bool $fahrenheit): ?float
    {
        return $value !== null && $fahrenheit && $class === 'temperature' ? $value * 9 / 5 + 32 : $value;
    }

    private function classLabel(string $class): string
    {
        return $this->translate("sensors.$class.short", ucfirst(str_replace('_', ' ', $class)));
    }

    private function classUnit(string $class, bool $fahrenheit): string
    {
        if ($class === 'temperature') {
            return $fahrenheit ? '°F' : '°C';
        }

        return $this->translate("sensors.$class.unit", '');
    }

    private function floatOrNull(mixed $value): ?float
    {
        return $value === null || $value === '' ? null : (float) $value;
    }

    private function iso(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            return Carbon::parse((string) $value)->toIso8601String();
        } catch (Throwable) {
            return null;
        }
    }

    /** Looks up a LibreNMS translation and falls back when the key is missing. */
    private function translate(string $key, string $fallback): string
    {
        $text = __($key);

        return is_string($text) && $text !== $key ? $text : $fallback;
    }
}

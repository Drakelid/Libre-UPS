<?php

declare(strict_types=1);

namespace Drakelid\UpsBattery\Hooks;

use App\Models\Device;
use App\Models\User;
use App\Plugins\Hooks\DeviceOverviewHook;
use Drakelid\UpsBattery\Report\PluginSettings;
use Drakelid\UpsBattery\Report\SensorReportService;
use Drakelid\UpsBattery\Report\SuspectBattery;

/** Battery card on the device overview page, shown only for devices that have runtime, charge or load sensors. */
class DeviceOverview extends DeviceOverviewHook
{
    /** Resolved as "ups-battery::device-overview" by the LibreNMS base class. */
    public string $view = 'device-overview';

    public function authorize(User $user, Device $device): bool
    {
        return app(SensorReportService::class)->hasDeviceSensors($device);
    }

    /**
     * Extra parameters must be optional to stay compatible with the LibreNMS base class;
     * LibreNMS passes "settings" by name.
     *
     * @param  array<string, mixed>  $settings
     * @return array<string, mixed>
     */
    public function data(Device $device, array $settings = []): array
    {
        $service = app(SensorReportService::class);
        $pluginSettings = PluginSettings::fromArray($settings);

        /** @var User $user */
        $user = auth()->user();

        $rows = $service->forDevice($device, $user, $pluginSettings->thresholds);

        return [
            'title' => 'UPS Battery',
            'device' => $device,
            'locale' => $pluginSettings->language,
            'staleMinutes' => $pluginSettings->staleMinutes,
            'rows' => $rows,
            'suspect' => SuspectBattery::evaluateRows($pluginSettings->suspectRule, $rows),
        ];
    }
}

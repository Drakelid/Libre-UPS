<?php

declare(strict_types=1);

namespace Drakelid\UpsBattery;

use Drakelid\UpsBattery\Console\SendReport;
use Drakelid\UpsBattery\Hooks\DeviceOverview;
use Drakelid\UpsBattery\Hooks\MenuEntry;
use Drakelid\UpsBattery\Hooks\Settings;
use Drakelid\UpsBattery\Report\PluginSettings;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\ServiceProvider;
use LibreNMS\Interfaces\Plugins\Hooks\DeviceOverviewHook;
use LibreNMS\Interfaces\Plugins\Hooks\MenuEntryHook;
use LibreNMS\Interfaces\Plugins\Hooks\SettingsHook;
use LibreNMS\Interfaces\Plugins\PluginManagerInterface;

class UpsBatteryProvider extends ServiceProvider
{
    public const PLUGIN = 'ups-battery';

    public function boot(PluginManagerInterface $pluginManager): void
    {
        // Hooks must always be published, otherwise LibreNMS may remove the plugin from the UI.
        $pluginManager->publishHook(self::PLUGIN, MenuEntryHook::class, MenuEntry::class);
        $pluginManager->publishHook(self::PLUGIN, SettingsHook::class, Settings::class);
        $pluginManager->publishHook(self::PLUGIN, DeviceOverviewHook::class, DeviceOverview::class);

        if (! $pluginManager->pluginEnabled(self::PLUGIN)) {
            return;
        }

        $this->loadRoutesFrom(__DIR__.'/../routes/web.php');
        $this->loadViewsFrom(__DIR__.'/../resources/views', self::PLUGIN);
        $this->loadTranslationsFrom(__DIR__.'/../lang', self::PLUGIN);

        if ($this->app->runningInConsole()) {
            $this->commands([SendReport::class]);
        }

        // The weekly email runs from the Laravel scheduler that LibreNMS already runs every minute.
        $settings = PluginSettings::fromArray($pluginManager->getSettings(self::PLUGIN));
        if ($settings->report->isActive()) {
            $this->callAfterResolving(Schedule::class, function (Schedule $schedule) use ($settings): void {
                $schedule->command(SendReport::class)->weeklyOn($settings->report->day, $settings->report->time);
            });
        }
    }
}

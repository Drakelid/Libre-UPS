<?php

declare(strict_types=1);

namespace Drakelid\UpsBattery\Hooks;

use Drakelid\UpsBattery\Report\PluginSettings;
use LibreNMS\Interfaces\Plugins\Hooks\MenuEntryHook;

class MenuEntry implements MenuEntryHook
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @param  array<string, mixed>  $settings
     * @return array{0: string, 1: array<string, mixed>}
     */
    public function handle(string $pluginName, array $settings): array
    {
        return ["$pluginName::menu", ['locale' => PluginSettings::fromArray($settings)->language]];
    }
}

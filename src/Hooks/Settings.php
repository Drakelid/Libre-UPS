<?php

declare(strict_types=1);

namespace Drakelid\UpsBattery\Hooks;

use Illuminate\Foundation\Auth\User;
use LibreNMS\Interfaces\Plugins\Hooks\SettingsHook;

class Settings implements SettingsHook
{
    public function authorize(User $user): bool
    {
        return true; // LibreNMS already requires plugin.admin for the settings page
    }

    /**
     * @param  array<string, mixed>  $settings
     * @return array<string, mixed>
     */
    public function handle(string $pluginName, array $settings): array
    {
        return [
            'content_view' => "$pluginName::settings",
            'settings' => $settings,
        ];
    }
}

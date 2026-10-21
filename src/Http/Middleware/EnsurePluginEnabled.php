<?php

declare(strict_types=1);

namespace Drakelid\UpsBattery\Http\Middleware;

use Closure;
use Drakelid\UpsBattery\UpsBatteryProvider;
use Illuminate\Http\Request;
use LibreNMS\Interfaces\Plugins\PluginManagerInterface;
use Symfony\Component\HttpFoundation\Response;

class EnsurePluginEnabled
{
    public function __construct(private PluginManagerInterface $plugins) {}

    public function handle(Request $request, Closure $next): Response
    {
        abort_unless($this->plugins->pluginEnabled(UpsBatteryProvider::PLUGIN), 404);

        return $next($request);
    }
}

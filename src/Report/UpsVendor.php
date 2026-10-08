<?php

declare(strict_types=1);

namespace Drakelid\UpsBattery\Report;

/**
 * Manufacturer name of a UPS. LibreNMS has no vendor column for devices, so it is taken from the LibreNMS OS
 * (one per vendor MIB), then from the OS icon (the brand), then from the OS display text.
 */
final class UpsVendor
{
    /** LibreNMS OS => manufacturer, for the UPS and DC power OSes LibreNMS knows. */
    private const BY_OS = [
        'abbups' => 'ABB',
        'algcom-dc-ups' => 'ALGcom',
        'apc' => 'APC',
        'apc-inrow' => 'APC',
        'apc-mgeups' => 'APC',
        'cxc' => 'Alpha Technologies',
        'cxrc' => 'Alpha Technologies',
        'cyberpower' => 'CyberPower',
        'dell-ups' => 'Dell',
        'deltaups' => 'Delta',
        'eaton-mgeups' => 'Eaton',
        'eatonups' => 'Eaton',
        'eatonupsm2' => 'Eaton',
        'eltek-webpower' => 'Eltek',
        'enexus' => 'Eltek',
        'fxm' => 'Alpha Technologies',
        'gamatronicups' => 'Gamatronic',
        'ge-ups' => 'GE',
        'generex-ups' => 'Generex',
        'hpe-rtups' => 'HPE',
        'huawei-smu' => 'Huawei',
        'huaweiups' => 'Huawei',
        'ict-mps' => 'ICT',
        'imcopower-big' => 'Imcopower',
        'imcopower-ls110' => 'Imcopower',
        'liebert' => 'Vertiv',
        'marathonups' => 'Marathon',
        'netagent2' => 'Megatec',
        'netmanplus' => 'Riello',
        'orvaldi-ups' => 'Orvaldi',
        'poweralert' => 'Tripp Lite',
        'powerwalker' => 'PowerWalker',
        'sinetica' => 'Sinetica',
        'socomec-ups' => 'Socomec',
        'vertiv-dcs' => 'Vertiv',
        'vertiv-ita2' => 'Vertiv',
    ];

    /** OS icon (brand) => manufacturer, for OSes not in the list above. */
    private const BY_ICON = [
        'apc' => 'APC',
        'cyberpower' => 'CyberPower',
        'delta' => 'Delta',
        'eaton' => 'Eaton',
        'eltek' => 'Eltek',
        'huawei' => 'Huawei',
        'riello' => 'Riello',
        'socomec' => 'Socomec',
        'socomecpdu' => 'Socomec',
        'tripplite' => 'Tripp Lite',
        'vertiv' => 'Vertiv',
    ];

    /** General-purpose OSes: a UPS behind them (NUT) has its own manufacturer, which LibreNMS does not know. */
    private const HOST_OS = ['linux', 'unix', 'freebsd', 'openbsd', 'netbsd', 'dsm', 'qnap', 'truenas', 'proxmox', 'windows', 'generic'];

    /**
     * @param  string  $os  devices.os
     * @param  string|null  $icon  The OS icon file name, e.g. "images/os/eaton.svg".
     * @param  string|null  $osText  The OS display text from the LibreNMS OS definition, e.g. "Eaton UPS".
     */
    public static function name(string $os, ?string $icon, ?string $osText): ?string
    {
        if (isset(self::BY_OS[$os])) {
            return self::BY_OS[$os];
        }

        if (in_array($os, self::HOST_OS, true)) {
            return null;
        }

        $brand = $icon === null ? '' : strtolower(pathinfo($icon, PATHINFO_FILENAME));
        if (isset(self::BY_ICON[$brand])) {
            return self::BY_ICON[$brand];
        }

        $text = trim((string) $osText);

        return $text !== '' ? $text : ($os !== '' ? ucfirst($os) : null);
    }

    /** The model as LibreNMS stores it (devices.hardware), or null when it is empty. */
    public static function model(?string $hardware): ?string
    {
        $model = trim((string) $hardware);

        return $model === '' ? null : $model;
    }
}

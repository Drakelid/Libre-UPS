# LibreNMS UPS Battery plugin

A LibreNMS plugin that adds one report page: pick a device type and a metric (sensor class) and get a ranked list of hostnames with their current value. Typical uses:

- Which UPSes have the **shortest battery runtime**?
- Which UPSes carry the **highest load**?
- All of runtime, load and charge **side by side** for every UPS.

It works for any sensor class LibreNMS already collects (`runtime`, `load`, `charge`, `voltage`, `temperature`, `state`, ...), and any device type. The plugin only reads data LibreNMS has already stored; it adds no tables, no migrations and no polling.

## Features

- **Single metric view:** ranked table of hostname, location, sensor, value and last update, with filters for device type, metric, OS, device group and free-text search (hostname, sysName, display name, location).
- **Compare metrics view:** one row per device and one column per metric (up to six). Each cell shows the *worst* sensor of the device for that metric (lowest runtime/charge, highest load/temperature).
- Sorting on every column, with a sensible default direction per metric (runtime/charge ascending, load/power/temperature descending), Top N (10, 25, 50, 100, all) and per-device aggregation (lowest/highest value).
- Summary line: shown / total rows, min, median and max.
- **Configurable thresholds** per metric, for UPSes where LibreNMS has no useful sensor limits (see below). Without a rule the LibreNMS sensor limits are used. Rows are coloured red (critical) and yellow (warning); devices that are down are dimmed.
- **Auto-refresh** matching the polling interval, relative "last updated" times that tick, and a warning icon for stale data.
- **Hover graph:** hover a value to see the sensor's last 24 hours (LibreNMS graph); click a value to open the sensor on the device page.
- **Saved views:** name and save the current filters per user; shareable URLs also mirror every filter.
- **Device page card:** a "UPS Battery" panel on the device overview for devices with runtime, charge or load sensors.
- **CSV export** of the current view (semicolon separated, decimal comma, UTF-8 with BOM, opens correctly in Norwegian Excel).
- Respects LibreNMS device and group permissions; temperature follows each user's °C/°F preference.
- English and Norwegian (bokmål) interface, selectable in the plugin settings.

## Requirements

- LibreNMS 26.x (developed against 26.9.1.1) with the package plugin system.
- PHP ^8.2.

## Installation

### From Packagist (recommended)

The plugin is published on [Packagist](https://packagist.org/packages/drakelid/librenms-ups-battery). `scripts/install.sh` installs it with LibreNMS' own plugin installer (`lnms plugin:add`), so it is registered in `composer.json` and `composer.plugins.json` and survives LibreNMS updates.

```bash
# download the script and run it as root (it switches to the owner of /opt/librenms) or as the librenms user
curl -fsSLo install.sh https://raw.githubusercontent.com/Drakelid/Libre-UpsDash/main/scripts/install.sh
sudo bash install.sh              # install the latest release (asks before changing anything)
sudo bash install.sh --dry-run    # only check and show the commands
sudo bash install.sh --update     # update later
```

The script checks PHP and write access, picks the newest tagged release from Packagist (or `dev-main` while no release has been tagged), runs `lnms plugin:add`, enables the plugin, refreshes the route and view caches and verifies the result. Options: `--dir`, `--version`, `--no-enable`, `--yes`, see `bash install.sh --help`. Keep the file outside `/root` so the LibreNMS user can read it.

The same by hand:

```bash
cd /opt/librenms
sudo -u librenms ./lnms plugin:add drakelid/librenms-ups-battery            # latest tagged release
sudo -u librenms ./lnms plugin:add drakelid/librenms-ups-battery dev-main   # until a release has been tagged
sudo -u librenms ./lnms plugin:remove drakelid/librenms-ups-battery         # uninstall
```

Packagist only has a `dev-main` version until a release is tagged on GitHub (for example `git tag v1.1.0 && git push --tags`); a plain `plugin:add` without a version fails until then.

### From a local checkout (development)

```bash
sudo -u librenms composer config repositories.ups-battery '{"type":"path","url":"/path/to/Libre-UPS","options":{"symlink":true}}'
sudo -u librenms ./lnms plugin:add drakelid/librenms-ups-battery @dev
```

Then open *Overview -> Plugins -> UPS Battery*. The plugin is enabled when it is installed; it can be disabled and configured under *Overview -> Plugins -> Plugin Admin*.

### Verify the installation

`scripts/verify.sh` checks the plugin on the server: PHP syntax of every file, route registration, Blade compilation (including the settings page and device card), the unit tests (if `composer install` has been run in the plugin checkout), and a data smoke test that runs the whole service -> sorting -> matrix -> CSV pipeline against your real LibreNMS database.

```bash
# run as the LibreNMS user, with a LibreNMS user that has global read access
sudo -u librenms ./scripts/verify.sh <librenms-username> runtime
```

The script prints the lowest-runtime UPSes it finds, so you can compare them with the device pages in LibreNMS.

### Troubleshooting

| Symptom | Fix |
| --- | --- |
| "UPS Battery" is missing in the *Plugins* menu | Enable `ups-battery` under *Plugin Admin*, then reload the page. |
| 404 on `/plugin/ups-battery/report` | `sudo -u librenms php artisan route:clear` (and `./lnms cache:clear` if routes are cached). |
| Plugin disabled itself and a notification appeared | A hook threw an error; read `logs/librenms.log`, fix, and re-enable under *Plugin Admin*. |
| Empty metric list | No sensors of that device type exist, or the user has no device access. Filter options are cached for 60 seconds. |
| HTTP 422 from a data endpoint | The request contained an unknown device type, class, OS or group; the response body says which. |
| "Too many sensors" | More than 50,000 sensors match; narrow the filters (device type, OS, group). |
| Colours never show for runtime | LibreNMS has no limits for those sensors. Add a threshold rule in the settings (below). |

## Settings

Under *Plugin Admin* -> UPS Battery:

| Setting | Default | Meaning |
| --- | --- | --- |
| Default device type | `power` | Value of `devices.type`; empty means all types |
| Default metric | `runtime` | Sensor class shown when the page opens |
| Default number of rows | 25 | One of 10, 25, 50, 100, all |
| Thresholds | empty | Severity rules per metric, see below |
| Auto-refresh interval | 300 s | 0 turns it off, otherwise 30 to 3600 seconds |
| Stale after | 30 min | Rows whose sensor value is older get a warning icon |
| Language | English | English or Norwegian (bokmål) |

Invalid values never break the page: they are replaced by the defaults.

### Thresholds

One rule set per line: `<metric> <critical-rule> [<warning-rule>]`, where a rule is `<` or `>` followed by a number. Text after `#` is a comment.

```text
runtime <10 <20     # critical below 10 minutes, warning below 20
load    >90 >75
charge  <20 <50
```

Rules are in the unit shown on the page (minutes for runtime, % for load and charge, the user's °C/°F for temperature) and replace the LibreNMS limits for that metric. Invalid lines are ignored and listed under the text box.

## Endpoints

All routes require a logged-in user.

| Route | Purpose |
| --- | --- |
| `GET /plugin/ups-battery/report` | The report page |
| `GET /plugin/ups-battery/options?type=` | Filter options (device types, OS, groups, metrics) as JSON |
| `GET /plugin/ups-battery/data?type=&class=&os=&group=&q=&sort=&dir=&limit=&aggregate=&format=json\|csv` | Single-metric rows as JSON or CSV |
| `GET /plugin/ups-battery/matrix?type=&classes=runtime,load,charge&os=&group=&q=&sort=&dir=&limit=&format=json\|csv` | Compare-metrics rows as JSON or CSV |
| `GET/POST /plugin/ups-battery/views`, `POST .../views/delete` | Saved views of the current user |

Invalid parameters return HTTP 422 with `{"message": "..."}`.

## Development

```bash
composer install
composer test   # Pest unit tests for the pure classes
composer lint   # Laravel Pint, preset "laravel" (composer fix applies the fixes)
```

GitHub Actions (`.github/workflows/ci.yml`) runs syntax check, Pint and Pest on PHP 8.2, 8.3 and 8.4. The code was written without a PHP runtime at hand, so run `composer fix` once and commit before the first release.

The classes under `src/Report` (except `SensorReportService`) are plain PHP and are unit-tested without a LibreNMS installation. `SensorReportService`, the controller, hooks and views need a LibreNMS runtime and are verified with `scripts/verify.sh` and the acceptance checklist in the project specification.

Not planned: a LibreNMS dashboard widget. Dashboard widgets are core controllers and the plugin API has no widget hook, so it would need an upstream change in LibreNMS.

## Project specification

The full specification (in Norwegian) is in [Prosjektspesifikasjon-UPS-Battery.md](Prosjektspesifikasjon-UPS-Battery.md).

## License

MIT, see [LICENSE](LICENSE).
#   L i b r e - U P S  
 
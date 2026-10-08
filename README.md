# LibreNMS UPS Battery

[![CI](https://github.com/Drakelid/Libre-UPS/actions/workflows/ci.yml/badge.svg)](https://github.com/Drakelid/Libre-UPS/actions/workflows/ci.yml)
[![License: MIT](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)
![PHP 8.2+](https://img.shields.io/badge/PHP-8.2%2B-777bb4.svg)
![LibreNMS 26.x](https://img.shields.io/badge/LibreNMS-26.x-2f6db5.svg)

**Rank your UPSes by battery runtime, load or any other sensor, on one page in LibreNMS.**

Pick a device type and a metric and get a sorted list of hostnames with their current value. Typical questions it answers:

- Which UPSes have the **shortest battery runtime**?
- Which UPSes carry the **highest load**?
- How do runtime, load and charge compare **side by side** for every UPS?

The plugin only reads sensor data LibreNMS has already collected. It adds no tables, no migrations and no polling, and it works for any sensor class and device type, not just UPSes.

```text
Device type: power   Metric: runtime   OS: All   Group: All   Show: 25        [Export CSV]
Showing 5 of 38 · min 4 Min · median 22 Min · max 61 Min

Hostname         Location    Sensor           Runtime (Min) ▲   Last updated
ups-stasjon-01   Stasjon A   Battery runtime  4 minutes         3 min ago        <- red
ups-stasjon-02   Stasjon B   Battery runtime  9 minutes         3 min ago        <- yellow
ups-stasjon-03   Stasjon C   Battery runtime  18 minutes        4 min ago
```

*Illustrative sketch with example values.*

## Features

**Reports**

- **Single metric view:** ranked table of hostname, location, sensor, value and last update. Filter on device type, metric, OS, device group, sensor name and free text (hostname, sysName, display name, location).
- **Compare metrics view:** one row per device and one column per metric (up to six). Each cell shows the device's *worst* sensor for that metric, for example the shortest runtime or the highest load.
- Sort on any column, show the top 10, 25, 50, 100 or all rows, and optionally reduce each device to its lowest or highest sensor.
- Summary line with shown and total rows plus min, median and max (runtime as a duration such as "1 h 5 min").

**Battery health**

- **Suspect batteries:** a battery is flagged when the runtime is short *although* the load is low and the battery is charged, which points to a worn battery rather than a busy UPS. Shown as a "Battery" column in the compare view, as a filter, as a warning on the device page and in the weekly report (see [Suspect batteries](#suspect-batteries)).
- **Weekly email report** with the UPSes that have the shortest runtime and the suspect batteries (see [Weekly report](#weekly-email-report)).
- **Trend link:** every sensor links to the LibreNMS graph for the last year, to see a battery slowly losing runtime.

**Severity and freshness**

- **Configurable thresholds** per metric for UPSes where LibreNMS has no useful sensor limits (see [Thresholds](#thresholds)). Without a rule the LibreNMS sensor limits are used. Rows turn red (critical) or yellow (warning); devices that are down are dimmed. The settings page shows the matching LibreNMS alert rules, so alerts and colours agree.
- **Auto-refresh** matching the polling interval, relative "last updated" times that keep ticking, and a warning icon for stale data.

**Convenience**

- **Hover graph and links:** hover a value for the sensor's last 24 hours, click it to open the sensor on the device page.
- **Saved views:** name and save the current filters per user. Every filter is also in the URL, so a view can be shared by copying the link.
- **Kiosk view** for a wall screen: add `kiosk=1` to the address (or press "Kiosk view") for a large table without filters that refreshes by itself. Esc leaves it.
- **CSV export** of the current view or of all rows: semicolon separated, decimal comma, UTF-8 with BOM, so it opens correctly in Norwegian Excel. Cells that look like spreadsheet formulas are neutralised.
- **Device page card:** a "UPS Battery" panel on the device overview for devices with runtime, charge or load sensors.
- English and Norwegian (bokmål) interface, selectable in the plugin settings.

**Permissions**

- Every logged-in user can open the page and sees only the devices and device groups LibreNMS lets them see.
- Temperatures follow each user's °C/°F preference.
- The only data the plugin writes is each user's saved views (in the LibreNMS user preferences) and its settings.

## Requirements

- LibreNMS 26.x with the package plugin system (developed against 26.9.1.1).
- PHP 8.2 or newer.
- For the install script: shell access to the LibreNMS server and access to packagist.org.

## Installation

### Install script (recommended)

[`scripts/install.sh`](scripts/install.sh) installs the plugin from [Packagist](https://packagist.org/packages/drakelid/librenms-ups-battery) with LibreNMS' own plugin installer (`lnms plugin:add`). The plugin is then registered in `composer.json` and `composer.plugins.json`, shows up in *Plugin Admin* and survives LibreNMS updates.

```bash
# run as root (the script switches to the owner of /opt/librenms) or as the librenms user
curl -fsSLo install.sh https://raw.githubusercontent.com/Drakelid/Libre-UPS/main/scripts/install.sh
sudo bash install.sh --dry-run    # only check the server and show the commands
sudo bash install.sh              # install the latest release (asks before changing anything)
```

The script checks PHP and write access, picks the newest tagged release (or `dev-main` while no release has been tagged), runs `lnms plugin:add`, enables the plugin, refreshes the route and view caches and verifies the result.

| Option | Meaning |
| --- | --- |
| `-d`, `--dir DIR` | LibreNMS directory (default `$LIBRENMS_DIR` or `/opt/librenms`) |
| `-v`, `--version VER` | Install or switch to a version or constraint, for example `1.0.0`, `^1.0` or `dev-main` |
| `-u`, `--update` | Update an existing installation |
| `--no-enable` | Leave the plugin disabled after a fresh install |
| `-n`, `--dry-run` | Run the checks and show the commands, change nothing |
| `-y`, `--yes` | Do not ask for confirmation |

Keep the script outside `/root` so the LibreNMS user can read it. `bash install.sh --help` lists everything.

### Manual installation

```bash
cd /opt/librenms
sudo -u librenms ./lnms plugin:add drakelid/librenms-ups-battery            # latest tagged release
sudo -u librenms ./lnms plugin:add drakelid/librenms-ups-battery dev-main   # if no release has been tagged yet
```

Then open **UPS Battery** in the top navigation bar (or *Overview > Plugins > UPS Battery*). The plugin is enabled as soon as it is installed; disable and configure it under *Overview > Plugins > Plugin Admin*.

### Update and remove

```bash
sudo bash install.sh --update                                         # update with the script
sudo -u librenms ./lnms plugin:remove drakelid/librenms-ups-battery   # uninstall
```

### Development checkout

To try a local copy, point composer at the folder (a symlink, so edits show up immediately):

```bash
sudo -u librenms composer config repositories.ups-battery '{"type":"path","url":"/path/to/Libre-UPS","options":{"symlink":true}}'
sudo -u librenms ./lnms plugin:add drakelid/librenms-ups-battery @dev
```

### Verify the installation

[`scripts/verify.sh`](scripts/verify.sh) checks the plugin against your real LibreNMS data: PHP syntax, route registration, Blade compilation (including the settings page and device card), the unit tests if `composer install` has been run in the plugin folder, and a smoke test of the whole pipeline (query, sorting, compare view, CSV).

```bash
# run as the LibreNMS user, with a LibreNMS user that has global read access
sudo -u librenms bash /opt/librenms/vendor/drakelid/librenms-ups-battery/scripts/verify.sh <librenms-username> runtime
```

It prints the UPSes with the lowest runtime, so you can compare them with the device pages in LibreNMS. From a development checkout, run `scripts/verify.sh` in that folder instead.

## Configuration

Open *Overview > Plugins > Plugin Admin* and choose **ups-battery**.

| Setting | Default | Meaning |
| --- | --- | --- |
| Default device type | empty (all types) | Value of `devices.type` selected when the page opens, for example `power`. UPSs are not always typed `power`, so leave it empty unless you need it |
| Default metric | `runtime` | Sensor class shown when the page opens |
| Default number of rows | 25 | One of 10, 25, 50, 100 or all |
| Thresholds | empty | Severity rules per metric, see below |
| Auto-refresh interval | 300 s | `0` turns it off, otherwise 30 to 3600 seconds |
| Stale after | 30 min | Rows whose sensor value is older than this get a warning icon |
| Language | English | English or Norwegian (bokmål) |
| Top navigation | on | Also show "UPS Battery" as a top-level item in the navigation bar |
| Suspect battery: runtime below | 10 min | See [Suspect batteries](#suspect-batteries) |
| Suspect battery: load at most | 30 % | |
| Suspect battery: charge at least | 95 % | |
| Weekly report | off | Switch, recipients, day, time and number of rows, see [Weekly email report](#weekly-email-report) |

Invalid values never break the page; they are replaced by the defaults.

### Thresholds

One rule set per line: `<metric> <critical-rule> [<warning-rule>]`, where a rule is `<` or `>` followed by a number. Text after `#` is a comment.

```text
runtime <10 <20     # critical below 10 minutes, warning below 20
load    >90 >75
charge  <20 <50
```

Rules use the unit LibreNMS stores (minutes for runtime, % for load and charge, degrees Celsius for temperature, whatever unit a user has chosen) and replace the LibreNMS limits for that metric. Invalid lines are ignored and listed under the text box.

**Matching alert rules.** The page only colours rows. Under the form the settings page lists, for the saved thresholds, the equivalent LibreNMS alert rules, for example `macros.device_up = 1 AND sensors.sensor_class = "runtime" AND sensors.sensor_current < 10`. Paste them on the *Advanced* tab under *Alerts > Alert Rules > Create rule* to get alerts that agree with the colours. The warning rule leaves out the critical range, so a sensor never triggers both.

### Suspect batteries

A short runtime is normal at high load or while the battery is still charging. It is suspicious when the UPS is lightly loaded and fully charged, because then the battery itself is the likely cause. A battery is flagged when

> runtime < *limit*, **and** load ≤ *limit*, **and** (charge is unknown **or** charge ≥ *limit*)

with the limits from the settings (10 minutes, 30 % and 95 % by default). For a device the shortest runtime, the highest load and the lowest charge decide. A battery cannot be judged without a runtime and a load sensor.

Where you see it: the **Battery** column in the compare view (select runtime and load), the **Suspect batteries only** filter next to it, a warning on the device page and a table in the weekly report. The CSV export has a `suspect_battery` column.

### Weekly email report

Switch it on in the settings and enter one or more recipients. Every week, on the day and time you choose, LibreNMS' scheduler sends an HTML email with the number of UPSes, critical and warning counts, the UPSes with the shortest runtime (top 10, 25, 50 or 100) and the suspect batteries.

- It uses the email settings of LibreNMS (*Global Settings > Alerting*) and the scheduler LibreNMS already runs every minute, so nothing needs to be added to cron.
- It covers all devices of the selected default device type, regardless of who is logged in.
- The links in the email use `APP_URL` from the LibreNMS `.env`; set it if they point to `localhost`.
- Try it without sending anything: `sudo -u librenms php artisan ups-battery:report --dry-run`. Send to someone else once with `--to=name@example.com`.

### Common UPS metrics

| Metric (sensor class) | Unit | Default order |
| --- | --- | --- |
| `runtime` | minutes | shortest first |
| `charge` | % | lowest first |
| `load` | % | highest first |
| `temperature` | °C or °F, per user | highest first |
| `power`, `current` | W, A | highest first |
| `voltage`, `frequency` | V, Hz | ascending (use thresholds to flag deviations) |
| `state` | text | most severe first |

Which classes exist depends on what each UPS exposes over SNMP; the metric list only shows classes that exist for the selected device type.

## API

The page is backed by JSON endpoints that can also be used directly. All routes require a logged-in user (session cookie).

| Route | Purpose |
| --- | --- |
| `GET /plugin/ups-battery/report` | The report page |
| `GET /plugin/ups-battery/options?type=` | Filter options (device types, OS, groups, metrics) as JSON |
| `GET /plugin/ups-battery/data?type=&class=&os=&group=&q=&sensor=&sort=&dir=&limit=&aggregate=&format=json\|csv` | Single-metric rows as JSON or CSV |
| `GET /plugin/ups-battery/matrix?type=&classes=runtime,load,charge&os=&group=&q=&sensor=&suspect=1&sort=&dir=&limit=&format=json\|csv` | Compare-metrics rows as JSON or CSV |
| `GET`/`POST /plugin/ups-battery/views`, `POST .../views/delete` | Saved views of the current user |
| `GET /plugin/ups-battery/assets/report.js` | The page script |

`limit=0` returns every row. `sensor` filters on the sensor name, `suspect=1` keeps only suspect batteries (needs `runtime` and `load` in `classes`). Invalid parameters return HTTP 422 with `{"message": "..."}`. The full request and response shapes are in chapters 12 to 14 of the [specification](Prosjektspesifikasjon-UPS-Battery.md).

## Troubleshooting

| Symptom | Fix |
| --- | --- |
| "UPS Battery" is missing in the *Plugins* menu | Enable `ups-battery` under *Plugin Admin* and reload the page. If it is enabled, reload PHP-FPM (for example `systemctl reload php8.3-fpm`). |
| 404 on `/plugin/ups-battery/report` | `sudo -u librenms php artisan route:clear`, then `route:cache` again if you cache routes. |
| The plugin disabled itself and a notification appeared | A hook threw an error. Read `logs/librenms.log`, fix the cause and re-enable it under *Plugin Admin*. |
| Empty metric list | No sensors of that device type exist, or your user has no device access. Filter options are cached for 60 seconds. |
| HTTP 422 from a data endpoint | The request contained an unknown device type, class, OS or group; the response says which. |
| "Too many sensors" | More than 50,000 sensors match. Narrow the filters (device type, OS, group). |
| Rows are never coloured | LibreNMS has no limits for those sensors. Add a [threshold rule](#thresholds). |
| The weekly report does not arrive | Run `php artisan ups-battery:report --dry-run` to see the report, then `php artisan ups-battery:report --to=you@example.com` to see the error. Check the email settings of LibreNMS and that the scheduler runs (`php artisan schedule:list` should show the report; the settings must have the report switched on and a valid recipient). |
| Links in the report email point to `localhost` | Set `APP_URL` in the LibreNMS `.env` to the address people use. |
| "Suspect batteries only" is greyed out | Select both the runtime and the load metric in the compare view. |
| `plugin:add` cannot find a version | No release is tagged yet. Use `dev-main` (the install script does this automatically). |

## Development

```bash
composer install
composer test   # Pest: plain PHP classes, language files, Blade views compile, routes and page ids
composer lint   # Laravel Pint, preset "laravel"; composer fix applies the fixes

npm ci
npm run check   # syntax check of resources/js/report.js
npm test        # the page script against the real page markup in jsdom, fetch mocked
```

GitHub Actions ([`ci.yml`](.github/workflows/ci.yml)) runs the PHP checks on PHP 8.2, 8.3 and 8.4 and the JavaScript checks on every push. [`integration.yml`](.github/workflows/integration.yml) installs the plugin into a real LibreNMS (run it by hand or wait for the weekly run) and checks that it boots, registers its routes and command and compiles its views. It has not been run yet, so treat the first run as a test of the workflow itself.

| Folder | Contents |
| --- | --- |
| `src/Report/` | Filters, sorting, aggregation, thresholds, suspect batteries, saved views, CSV and the weekly report as plain PHP (unit tested without LibreNMS), plus `SensorReportService`, the only class that talks to the LibreNMS database |
| `src/Http/`, `src/Hooks/`, `src/Console/` | Controller, the menu, settings and device overview hooks, and the `ups-battery:report` command |
| `resources/views/` | Blade views: report, settings, menu entry and device card |
| `resources/js/` | `report.js`, the page script (no build step, no dependencies) |
| `lang/` | English and Norwegian (bokmål) strings, kept at the same keys |
| `scripts/` | `install.sh` and `verify.sh` |
| `tests/` | Pest tests (`Unit/`) and jsdom tests for the page script (`js/`) |

`SensorReportService`, the controller, the hooks and the rendered pages need a LibreNMS with data. They are checked with `scripts/verify.sh` and the acceptance checklist in the specification.

Not planned: a LibreNMS dashboard widget. Widgets are core controllers and the plugin API has no widget hook, so it would need an upstream change in LibreNMS.

## Documentation and license

- [Project specification](Prosjektspesifikasjon-UPS-Battery.md) (Norwegian): requirements, design decisions, API shapes and acceptance criteria.
- [Changelog](CHANGELOG.md).
- MIT license, see [LICENSE](LICENSE).

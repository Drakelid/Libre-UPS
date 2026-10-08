# Changelog

All notable changes to this project are documented here. The format follows [Keep a Changelog](https://keepachangelog.com/) and the project uses [Semantic Versioning](https://semver.org/).

## [1.3.5] - 2026-10-08

### Fixed

- UPSs without an output status sensor showed the status "Unknown". The power source is now also worked out from a mains / input / utility status ("Mains Status: normal", "Utility Status: No Voltage", "Input Status: Blackout"), from the input voltage (any phase above 50 V means mains, all phases below 20 V means battery) or input frequency, and from charger states (float or charging means mains, discharging means battery). The status tooltip names the sensor it was read from, for example "MainsVolt 1: 230 V".
- The "UPS Battery" item in the top navigation bar had a permanently dark background on the plugin's pages, as if the mouse were over it. It was marked "active", which Bootstrap draws that way and LibreNMS does not use for its own menus.

## [1.3.4] - 2026-10-08

### Fixed

Checked against the sensor definitions of every UPS OS in LibreNMS:

- UPSs on the standard UPS-MIB (RFC 1628: ABB, Delta, Eaton Network-M2, GE, HPE, Huawei, Liebert, Riello NetMan, Tripp Lite, Webpower and others) showed a runtime of 0 minutes and a suspect battery: LibreNMS' "Time on battery" counter (0 on mains) was taken as the runtime. The same for Socomec, Webpower, Argus and LogMaster ("seconds/minutes on battery"). These counters are no longer a runtime anywhere in the plugin; above 0 they mean the UPS is on battery.
- Devices with uptimes, error counters or filter and fan run hours in the runtime class (routers, APC InRow cooling units) appeared as UPSs in the UPS overview, on their device page and in the weekly email. A device now needs a battery charge or a remaining battery time.
- "On battery" was not recognised for NUT (True/False flags), PowerWalker (system status) and Imcopower (power state), and Vertiv's "on Utility and Battery" was wrongly read as on battery. Eaton XUPS UPSs, which have no output source sensor, count as on battery while the battery is discharging.
- Battery and self-test states of CyberPower, NetAgent, Socomec, Webpower, Eaton XUPS, ALGcom and NUT are recognised; NUT flags show their name ("UPS low battery") instead of "True".
- Eaton Network-M2's "Last battery replacement" is used for the battery swap and left out of the runtimes.

### Changed

- The weekly email takes its runtime, suspect battery and swap tables from the UPS overview, so it lists the same UPSs as the page.
- Clearer wording in the UPS overview:
  - *Status* is always one plain label (On mains, On battery, On bypass, Battery test, Output off, Unreachable, Unknown) with a tooltip that explains it and shows the UPS's own wording.
  - *Attention* shows sentences with the value, for example "Runtime low: 8 min", "Load high: 92 %", "Battery needs replacing", "Last self-test failed", "Battery swap overdue by 30 days" and "Battery may be worn: short runtime although the load is low". Issue keys in the JSON and CSV are more specific (`runtime_low`, `load_high`, `battery_replace`, `self_test_failed`, `swap_today`, ...) and carry the `value`.
  - *Battery* and *Self-test* show plain words (Replace battery, Low, Discharging, Fault, Passed, Failed, Not run, ...) instead of vendor codes such as `noBatteryNeedsReplacing`; the vendor text stays in the tooltip and in the JSON (`battery_label`, `self_test_label`).
  - Column headers have a tooltip that explains what they show.

## [1.3.3] - 2026-10-08

### Changed

- Visual polish of the page, for LibreNMS' light and dark themes alike: colours are defined once as theme-neutral tints instead of fixed greys and blues.
  - Summary cards have a coloured accent and tinted background when they count something, larger numbers, small uppercase labels and lift on hover.
  - Status and attention labels are rounded pills; "On battery" has a pulsing dot (still for users who prefer reduced motion), attention badges and the swap countdown are tinted instead of solid.
  - The table has small uppercase headers, right-aligned numbers with even digit widths and slightly taller bars; unreachable UPSs are dimmed.
  - Timeline months inside the warning window are orange, empty months faint, with a baseline under the bars.
  - The details and the "sensors with a problem" box use the same card style.

## [1.3.2] - 2026-10-08

### Changed

- The view switch (UPS overview, single metric, compare metrics) is a button group in the panel heading, next to the export and kiosk buttons; the filter row only holds filters, starting with the search box.
- The UPS overview table is two columns narrower: the location is shown under the hostname and the install date (with the pencil to change it) under the next battery swap.
- Summary cards have icons and sit next to the battery swap timeline on wide screens.
- When a summary card filters the table, the summary line names the filter and has a button to clear it.

## [1.3.1] - 2026-10-08

### Changed

- The details of a UPS are laid out as one card per sensor class (runtime, charge, load, voltage, current, power, frequency, temperature, state, count, then the rest) with one "name … value" line per sensor, instead of one long line per class. Sensors with a warning or error are listed on top and come first in their card; cards with more than six sensors show the rest on request.
- Rows in the UPS overview get a coloured stripe on the left (red, yellow, green) instead of a fully coloured row, so the cells that have the problem stay readable, also in a dark theme.

### Fixed

- Sensors without a description showed as ": 0 V" in the details; they are now named after their state name or sensor id.

## [1.3.0] - 2026-10-08

### Added

- **Attention column** in the UPS overview: why a UPS is yellow or red, as badges ("On battery", "Swap overdue 30 d", "2 bad packs", "Self-test", "Unreachable", ...), most severe first, with the full list in the tooltip. The JSON rows carry `issues` (`key`, `severity`, `n`), the CSV an `issues` column.
- **Clickable summary cards:** a click on "On battery", "Battery swap overdue", "Swap within … days", "Battery problems", "Unreachable" or "No swap date" shows only those UPSs (`focus` parameter, kept in the address and in saved views); a second click or the "UPSs" card shows all again.
- **Unreachable** card: UPSs whose device is down in LibreNMS. Such a UPS now counts as a warning, because its values are as old as the last poll.
- **Battery swap timeline:** bars for the battery swaps due in each of the next 12 months (`cards.swap_timeline`), to plan battery orders.
- **Bars** for charge and load, and for the share of the battery life that has passed (`swap.life_used`, also in the CSV as `battery_life_used`).

## [1.2.0] - 2026-10-08

### Added

- **UPS overview**, the new default view of the page (`view=ups`, endpoint `plugin/ups-battery/ups`, JSON and CSV): one row per UPS (a device with a runtime or charge sensor) with
  - power status (on battery / on mains, from the output state sensor), runtime, charge, load and temperature,
  - battery status (replace-battery indicator, battery status), bad battery packs, the suspect battery verdict and the last self-test result,
  - the battery install date and the **next battery swap** with the days left,
  - all other sensors of the UPS (input/output voltage, frequency, current, ...) in a row that opens below it.
  
  Above the table, summary cards count the UPSs, those on battery, overdue swaps, swaps due within the warning window, UPSs without a swap date and battery problems, and show the lowest runtime. A "Needs attention only" filter keeps the UPSs with any warning or critical value. Rows are sorted worst first.
- **Next battery swap:** the install date entered per UPS (stored as the LibreNMS device attribute `ups-battery.battery_installed`, editable by users who may update devices, endpoint `POST plugin/ups-battery/battery`) plus the battery lifetime setting (48 months by default). Without an install date, the replacement date the UPS reports is used (APC: recommended replacement date, or the last replacement date plus the lifetime). New settings: battery lifetime and how many days before the swap to warn (90 by default).
- The device page card shows the next battery swap.
- The weekly email lists the battery swaps that are overdue or due within the warning window.

### Fixed

- LibreNMS stores the APC battery dates ("Battery Recommended Days Remaining", "Last Battery Replacement") as runtime sensors. They were listed as runtimes, could be the "shortest runtime" of a UPS and made the suspect battery check misfire. They are now left out of every runtime report and used for the battery swap instead.
- Links on the device page card were host-relative and could point to the `base_url` host; they are now absolute on the current host.

### Changed

- The page opens in the UPS overview. Links and saved views without a `view` parameter still open the single metric view.

### Known limits

- The date of the last self-test is not shown: LibreNMS does not store it as a sensor. Only the result is shown, where the UPS reports one.
- Which state sensors mean "battery status", "output source" and "self-test" is known for APC (PowerNet-MIB) and the standard UPS-MIB (RFC 1628); for other vendors it is guessed from the sensor name.

## [1.1.2] - 2026-10-08

### Fixed

- LibreNMS pages carry `<base href="(base_url)">`, and the browser resolves every host-relative URL (`/plugin/...`) against it. When `base_url` names another host than the one in the address bar, the top navigation link led to a page that did not work, and the page script, its data requests and the links in the table went to that host as well, so no rows were shown. The top navigation item now copies the absolute link of the *Plugins* entry, the page script is loaded from the page's own origin, and the script resolves every server path against `window.location.origin`. The JSON and CSV responses still carry host-relative URLs.

## [1.1.1] - 2026-10-08

### Fixed

- The report no longer filters on device type `power` by default. UPSs that LibreNMS types otherwise (for example a UPS behind a NUT server on a Linux host) did not show up; when the type `power` existed but had no runtime sensors, the page fell back to another metric. The default is now all device types, and an invalid saved type also means all types. An explicitly saved *Default device type* is still used.

### Added

- **Top navigation entry:** "UPS Battery" is shown as a top-level item in the LibreNMS navigation bar (setting *Top navigation*, on by default), in addition to *Overview > Plugins*.

### Known deviations from the specification

- LibreNMS renders plugin menu hooks only inside *Overview > Plugins* and has no hook for the navigation bar. The top-level entry is therefore added by a small script in the menu hook view that copies the link into `#navHeaderCollapse > ul.navbar-nav`. If a LibreNMS update changes that markup, the entry silently disappears and the *Plugins* entry remains.
- The default device type is now "all types" instead of `power` (specification chapter 11).

## [1.1.0] - 2026-10-08

### Fixed

- A runtime of 0 showed a blank value. LibreNMS' own formatter returns an empty string for 0, and the page used an empty formatted value to mean "not loaded yet". Runtime is now formatted by the plugin (`0 min`, `45 min`, `1 h 5 min`, `2 d 3 h`), and whether a row is loaded is tracked by an explicit `hydrated` flag.
- Plugin thresholds are compared with the value LibreNMS stores (minutes, %, °C) instead of the value converted to the user's temperature unit, so the same rule gives the same colour for every user. (Temperature thresholds written in °F against the previous build need converting to °C.)
- The summary line shows runtime as a duration instead of "4 Min".
- CI ran Pest only when Pint passed, so a style problem hid the test results; the code style now passes Pint and both always run.

### Added

- **Suspect batteries:** runtime below a limit although the load is low and the battery is charged (limits in the settings, 10 min / 30 % / 95 % by default). Shown as a "Battery" column and a "Suspect batteries only" filter in the compare view, as a warning on the device page, in the weekly report and as a `suspect_battery` CSV column.
- **Sensor name filter** (`sensor`) in both views.
- **Alert rule hints:** the settings page lists the LibreNMS alert rules that match the saved thresholds, ready to paste into *Alerts > Alert Rules*.
- **Weekly email report** and the `ups-battery:report` command (`--dry-run`, `--to`), scheduled through LibreNMS' scheduler on a day and time chosen in the settings; settings for recipients and number of rows.
- **Kiosk view** (`kiosk=1` or a button) for wall screens, and an "Export all rows" link that ignores the row limit.
- **Trend link** from every sensor to the LibreNMS graph for the last year (`trend_url` in the JSON).
- `resources/js/report.js`: the page script is now a file of its own, served by the plugin (`plugin/ups-battery/assets/report.js`) instead of 500 lines inside the Blade view.
- Tests: Pest tests for the new classes, the language files (same keys and placeholders in both languages, every text that is used exists), the Blade views (every view is compiled to valid PHP with `illuminate/view`), route names, controller methods and page element ids; and 20 jsdom tests (`npm test`) that run the page script against the real page markup with `fetch` mocked.
- CI: a JavaScript job (syntax check and jsdom tests) next to the PHP job, and `integration.yml`, a manual and weekly workflow that installs the plugin into a real LibreNMS.
- `scripts/verify.sh` also checks the suspect battery logic, the sensor name filter, empty formatted values and the weekly report dry run.

### Changed

- `scripts/install.sh` decides whether the plugin is installed by reading `composer.plugins.json` instead of searching it as text, and no longer prints "package installed" in a dry run.
- `SensorReportService::report()` and `matrix()` accept a null user for system jobs such as the weekly email (no per-user access filtering); `matrix()` takes the suspect rule.

### Known limits

- The decline of a battery over time ("runtime 30 and 90 days ago against now") is not calculated. It would mean reading the RRD files directly, which depends on rrdcached, distributed pollers and other storage backends and cannot be verified here. The trend link opens LibreNMS' own graph instead.
- `SensorReportService`, the controller, the hooks and the rendered pages are still only checked against a real LibreNMS by `scripts/verify.sh`. `integration.yml` has not been run yet.

## [1.0.0] - 2026-10-08

First release.

### Added

- **Report page** `plugin/ups-battery/report` with filters for device type, metric (sensor class), OS, device group and free-text search, sorting on every column, top N, per-device aggregation (lowest / highest value) and a summary line (shown / total, min, median, max).
- **Compare metrics view** and endpoint `plugin/ups-battery/matrix` (JSON and CSV): one row per device with the worst sensor per metric, up to six metrics.
- **Severity colouring** from the LibreNMS sensor limits, or from configurable **thresholds** per metric (`runtime <10 <20`) where LibreNMS has no useful limits.
- **Auto-refresh**, ticking relative times and a stale-data warning icon; both configurable.
- **Hover graph** (last 24 hours) and links from values to the sensor on the device page (`sensor_url`, `graph_url` in the JSON).
- **Saved views** per user (stored in the LibreNMS user preferences) and shareable URLs that carry every filter.
- **CSV export** of the current view (semicolon separator, decimal comma, UTF-8 BOM, spreadsheet formula protection).
- **Device overview card** (`DeviceOverviewHook`) for devices with runtime, charge or load sensors.
- Menu entry under *Plugins* and a settings page: default device type, metric and row count, thresholds, refresh interval, stale limit and language.
- **English and Norwegian (bokmål)** interface; the language is a plugin setting because LibreNMS itself has no Norwegian locale to select.
- Data endpoint `plugin/ups-battery/data` (JSON and CSV), options endpoint `plugin/ups-battery/options` and saved-view endpoints.
- Respects LibreNMS device and group permissions; temperature values, limits, sorting, summary and CSV follow each user's °C/°F preference.
- `scripts/install.sh`: installs or updates the plugin from Packagist through `lnms plugin:add` (checks, latest-release lookup with `dev-main` fallback, cache refresh, verification, `--dry-run`).
- `scripts/verify.sh`: server-side verification (syntax, routes, Blade compilation, tests, data smoke test).
- Pest unit tests for the plain PHP classes and a GitHub Actions workflow (syntax check, Pint, Pest on PHP 8.2-8.4).

### Design notes

- Reports load in two phases: a light query (no Eloquent hydration) finds, sorts and summarises all matching sensors, and only the rows that are shown are loaded as full models. The row cap is 50,000 sensors.
- Filter options are cached for 60 seconds per user and reused to validate input.
- All plugin settings go through `PluginSettings`, which replaces invalid values with safe defaults, so a bad setting never breaks the page.
- Page, endpoint and link URLs are relative, so they work behind a proxy and in a sub-directory install.
- An empty "Default device type" setting means all device types (Laravel stores empty form fields as `null`, so the code checks whether the key exists instead of using `??`).

### Known deviations from the specification

- Added `src/Report/TooManyRowsException.php` (specification §12.7 requires an own exception class but does not list the file in §12.3).
- Added `.gitignore` and a `phpunit.xml` that excludes the LibreNMS-dependent classes from coverage.
- SQL aliases in `SensorReportService` are named `item` (not `value`) to avoid the MySQL keyword.
- `device_url` in the JSON response is a relative URL (path and query only) instead of an absolute one.
- The plugin was written without a PHP runtime at hand. Run `composer test` and `composer lint` before relying on it.
- The acceptance checklists in chapters 9 and 13 of the specification have not been run; they need a LibreNMS instance.

### Not implemented

- A LibreNMS dashboard widget (specification FK-13): the plugin API has no widget hook.

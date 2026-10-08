# Changelog

All notable changes to this project are documented here. The format follows [Keep a Changelog](https://keepachangelog.com/) and the project uses [Semantic Versioning](https://semver.org/).

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

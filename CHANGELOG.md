# Changelog

All notable changes to this project are documented here. The format follows [Keep a Changelog](https://keepachangelog.com/) and the project uses [Semantic Versioning](https://semver.org/).

## [1.1.0] - 2026-10-07

### Added

- **Compare metrics view** and endpoint `plugin/ups-battery/matrix` (JSON and CSV): one row per device with the worst sensor per metric, up to six metrics.
- **Thresholds:** configurable severity rules per metric (`runtime <10 <20`), used instead of LibreNMS sensor limits where set.
- **Auto-refresh**, ticking relative times and a stale-data warning icon; both configurable.
- **Hover graph** (last 24 hours) and links from values to the sensor on the device page (`sensor_url`, `graph_url` in the JSON).
- **Saved views** per user (stored in the LibreNMS user preferences).
- **Device overview card** (`DeviceOverviewHook`) for devices with runtime, charge or load sensors.
- **Norwegian (bokmål) translation** and a plugin-level language setting (LibreNMS itself has no Norwegian locale to select).
- `scripts/install.sh`: installs or updates the plugin from Packagist through `lnms plugin:add` (checks, latest-release lookup with `dev-main` fallback, cache refresh, verification, `--dry-run`).
- GitHub Actions workflow (syntax check, Pint, Pest on PHP 8.2-8.4).
- Pest tests for `Thresholds`, `PluginSettings`, `SavedViews`, `MatrixFilters`, `MatrixBuilder`, `Aggregator`, `Urls` and `ReportRow`.

### Changed

- Reports load in two phases: a light query (no Eloquent hydration) finds, sorts and summarises all matching sensors; only the rows that are shown are loaded as full models. The row cap is now 50,000 (was 20,000).
- Filter options are cached for 60 seconds per user and reused for input validation, removing four queries from every data request.
- Aggregation ("per device") is ignored for `state` sensors, and the control is disabled in the UI.
- All plugin settings go through `PluginSettings`, which replaces invalid values with safe defaults.
- Shared input parsing moved to `InputParser`; `RowProcessor` uses `Aggregator`.

### Fixed

- Invalid saved settings (for example a default metric with a capital letter) no longer make the whole page return HTTP 422.
- Device group names are only listed for users who have access to the group.
- Temperature values, limits, sorting, summary and CSV now follow the user's °C/°F preference consistently.
- Page, endpoint and link URLs are relative, so they work behind a proxy and in a sub-directory install.

## [1.0.0] - 2026-10-07

### Added

- Report page `plugin/ups-battery/report` with filters for device type, metric (sensor class), OS, device group and search.
- Data endpoint `plugin/ups-battery/data` (JSON and CSV) and options endpoint `plugin/ups-battery/options`.
- Sorting, top N, per-device aggregation (lowest / highest value), summary line, severity colouring from LibreNMS sensor limits.
- Shareable URLs (all filters in the query string).
- CSV export (semicolon separator, decimal comma, UTF-8 BOM, spreadsheet formula protection).
- Menu entry under *Plugins* and a settings page for default device type, metric and row count.
- Pest unit tests for `ReportFilters`, `SortPolicy`, `Severity`, `RowProcessor`, `Summary` and `CsvFormatter`.

### Known deviations

- Added `src/Report/TooManyRowsException.php` (specification §12.7 requires an own exception class but does not list the file in §12.3).
- Added `.gitignore` and a `phpunit.xml` that excludes the LibreNMS-dependent classes from coverage.
- SQL aliases in `SensorReportService` are named `item` (not `value`) to avoid the MySQL keyword.
- `device_url` in the JSON response is a relative URL (path and query only) instead of an absolute one.
- An empty "Default device type" setting means all device types (Laravel stores empty form fields as `null`, so the code checks whether the key exists instead of using `??`).
- Added `scripts/verify.sh`, a server-side verification script (syntax, routes, Blade compilation, tests, data smoke test).
- The test suite and Pint could not be run while the plugin was written (the development machine had no PHP or Composer). Run `composer test` and `composer lint` before the first release.
- The acceptance checklist in chapter 9 of the specification has not been run; it needs a LibreNMS instance.

### Not implemented

- A LibreNMS dashboard widget (specification FK-13): the plugin API has no widget hook.

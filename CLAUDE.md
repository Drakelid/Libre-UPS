# CLAUDE.md

This repository is the LibreNMS plugin `drakelid/librenms-ups-battery`.

## Source of truth

- The full specification is [Prosjektspesifikasjon-UPS-Battery.md](Prosjektspesifikasjon-UPS-Battery.md) (Norwegian). Read all of it before writing code.
- Chapter 12 (Implementeringsguide) is binding: file layout, class names, method signatures, API JSON shapes, CSV format and tests. Where chapter 12 conflicts with chapters 1–10, chapter 12 wins.
- Chapters 13 and 14 describe the changes made after the first and second code review (thresholds, compare-metrics view, saved views, device card, suspect batteries, weekly email report, kiosk view, own JavaScript file, ...). Chapter 15 adds the UPS overview and the battery swap date. They override chapters 1–12 where they disagree, and a later chapter overrides an earlier one.
- Chapter 11 (Beslutninger) locks every earlier open question. Do not reopen them; do not add features outside the spec.
- If something is genuinely unspecified, choose the simplest option consistent with the spec and record it in `CHANGELOG.md` under "Known deviations".

## Conventions

- Code, identifiers, comments, README and CHANGELOG in English. UI strings go through the lang files (`lang/en/ups-battery.php`, with `lang/nb/ups-battery.php` kept at the same keys and the same `:placeholders`, a test checks it); views translate with an explicit locale from the plugin's `language` setting (`trans(..., [], $locale)`).
- PHP ^8.2, `declare(strict_types=1);` in every PHP file under `src/` and `tests/`, Laravel Pint preset `laravel` (`composer fix`).
- Classes marked "ren PHP" in §12.3 and chapter 14 must not import `Illuminate\*`, `App\*` or `LibreNMS\*`; they are unit-tested with Pest. Put decision logic there, not in the service, controller or views.
- `App\Models\*` and `LibreNMS\Util\*` exist only inside a LibreNMS installation. Never add `librenms/librenms` or `laravel/framework` to `require`. `illuminate/view` is a dev dependency only, used to compile the Blade views in a test.
- The page script is `resources/js/report.js`: no build step, no dependencies, server data only through `textContent`/`createElement`, page data only through the `ub-config` JSON element. Every element id it looks up must exist in `resources/views/report.blade.php` (a test checks it).
- Plugin thresholds compare the value LibreNMS stores (minutes, %, °C); only LibreNMS' own sensor limits are converted to the user's temperature unit.

## Checks

```bash
composer install && composer test && composer lint   # PHP: Pest and Pint
npm ci && npm run check && npm test                   # JavaScript: syntax and jsdom tests
```

Run all of them after every change. Blade views are compiled and checked by `tests/Unit/ViewsAndRoutesTest.php`, the page script is exercised in `tests/js/report.test.mjs`.

## Environment

- The workstation may have no PHP, Node or git on the PATH. A portable PHP (windows.php.net), Composer and Node can be unpacked in a scratch folder outside the project and used from there; do not install `vendor/` or `node_modules/` inside the OneDrive folder (they are git-ignored, but OneDrive would sync them).
- `SensorReportService`, the controller, hooks and the rendered pages need a real LibreNMS and cannot be tested here. They are checked by `scripts/verify.sh` on a server; `.github/workflows/integration.yml` installs the plugin into a real LibreNMS in CI.
- Do not invent LibreNMS APIs. The ones used were checked against LibreNMS 26.9.1.1 and `librenms/plugin-interfaces` 1.x.

## Done means

Section 12.12 (Definition of Done) of the spec, with the Pest, Pint and npm checks above passing.

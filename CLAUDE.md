# CLAUDE.md

This repository is the LibreNMS plugin `drakelid/librenms-ups-battery`.

## Source of truth

- The full specification is [Prosjektspesifikasjon-UPS-Battery.md](Prosjektspesifikasjon-UPS-Battery.md) (Norwegian). Read all of it before writing code.
- Chapter 12 (Implementeringsguide) is binding: file layout, class names, method signatures, API JSON shapes, CSV format and tests. Where chapter 12 conflicts with chapters 1–10, chapter 12 wins.
- Chapter 13 (Versjon 1.1) describes the changes made after the first code review (thresholds, compare-metrics view, saved views, device card, two-phase loading, language setting). It overrides chapters 1–12 where they disagree.
- Chapter 11 (Beslutninger) locks every earlier open question. Do not reopen them; do not add features outside the spec.
- If something is genuinely unspecified, choose the simplest option consistent with the spec and record it in `CHANGELOG.md` under "Known deviations".

## Conventions

- Code, identifiers, comments, README and CHANGELOG in English. UI strings go through the lang files (`lang/en/ups-battery.php`, with `lang/nb/ups-battery.php` kept at the same keys); views translate with an explicit locale from the plugin's `language` setting (`trans(..., [], $locale)`).
- PHP ^8.2, `declare(strict_types=1);` in every PHP file under `src/` and `tests/`, Laravel Pint preset `laravel`.
- Classes marked "ren PHP" in §12.3 must not import `Illuminate\*`, `App\*` or `LibreNMS\*`; they are unit-tested with Pest.
- `App\Models\*` and `LibreNMS\Util\*` exist only inside a LibreNMS installation. Never add `librenms/librenms` or `laravel/framework` to `require`.

## Environment

- This workstation has no PHP, Composer or git. You cannot run `composer test` here. Write the tests anyway; they run on the LibreNMS server or in CI.
- Do not invent LibreNMS APIs. The ones used by the spec were checked against LibreNMS 26.9.1.1 and `librenms/plugin-interfaces` 1.x.

## Done means

Section 12.12 (Definition of Done) of the spec.

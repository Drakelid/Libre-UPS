# Prosjektspesifikasjon: «UPS Battery»-dashboard (LibreNMS-plugin)

7. oktober 2026 · Fredrik · Versjon 1.1 (implementeringsklar)

> **Til utvikler / AI-agent:** Denne spesifikasjonen er komplett for versjon 1. Kapittel 1–10 beskriver *hva* som skal bygges; kapittel 11 (Beslutninger) låser alle valg som tidligere var åpne; kapittel 12 (Implementeringsguide) beskriver *nøyaktig hvordan*, med filer, klasser, signaturer og API-kontrakter. Ved konflikt gjelder kapittel 12 foran kapittel 1–10. Ikke legg til funksjonalitet utover det som står her.

## 1. Bakgrunn og mål

Vi skal bygge en LibreNMS-plugin, «UPS Battery», som gir én side der man velger enhetstype og måleverdi og får en rangert liste over hostname og verdi. Formålet er å se på tvers av hele UPS-parken hvilke enheter som skiller seg ut, uten å klikke seg gjennom hver enhet.

Opprinnelig ønske:

> «Et dashboard/rapport der jeg dynamisk kan velge en type enhet og poller og da få opp en liste med "hostname" og "value" på den valgte polleren. Da kan jeg for eksempel kunne se hvilke UPSer har kortest batterilevetid, eller hvilke UPSer har mest "Load" etc.»

Mål for versjon 1:

- Finne de UPS-ene med kortest gjenværende batteritid (runtime) på under 10 sekunder fra siden åpnes.
- Finne de UPS-ene med høyest last (load) på samme måte.
- Fungere for alle sensorklasser LibreNMS allerede samler inn, ikke bare UPS, slik at samme side kan brukes til andre enhetstyper senere.
- Ingen ny datainnsamling: pluginen leser bare data LibreNMS allerede har.

## 2. Omfang

Versjon 1 er en lesende rapportside basert på siste innsamlede sensorverdi per sensor.

| Innenfor versjon 1 | Utenfor versjon 1 |
| --- | --- |
| Ny menyoppføring og side i LibreNMS via plugin-systemet | Endringer i LibreNMS-kjernen |
| Filter på enhetstype, OS og enhetsgruppe | Ny SNMP-polling eller nye MIB-er |
| Valg av måleverdi (sensorklasse), f.eks. runtime, load, charge | Historikk og trender over tid (grafer) |
| Rangert tabell: hostname, lokasjon, sensor, verdi, sist oppdatert | Varsling (dekkes av LibreNMS alert rules) |
| Sortering, topp N og søk | Redigering av terskler fra siden |
| CSV-eksport av gjeldende visning | Planlagt utsendelse av rapport på e-post |
| Respekt for brukerens enhetstilganger | Dashboard-widget (versjon 2) |
| Innstillingsside for standardverdier | Trådløse sensorer (`wireless_sensors`-tabellen) |

## 3. Brukerhistorier

| ID | Som … | ønsker jeg å … | slik at … |
| --- | --- | --- | --- |
| US-1 | driftstekniker | se UPS-ene sortert etter kortest gjenværende batteritid | jeg kan prioritere batteribytte |
| US-2 | driftstekniker | se UPS-ene sortert etter høyest last | jeg ser hvilke som er nær kapasitetsgrensen |
| US-3 | driftstekniker | bytte måleverdi uten å laste siden på nytt | jeg raskt kan sammenligne runtime, load og charge |
| US-4 | driftstekniker | begrense listen til en enhetsgruppe eller søke på lokasjon | jeg kan se på én stasjon eller ett område |
| US-5 | leder/planlegger | eksportere listen til CSV | jeg kan bruke den i budsjett og vedlikeholdsplan |
| US-6 | driftstekniker | se verdier over terskel tydelig markert | avvik fanges opp uten å lese hvert tall |
| US-7 | driftstekniker | dele en visning via lenke | kolleger åpner samme filtrering |

## 4. Funksjonelle krav

Alle krav under er med i versjon 1 med mindre de er merket «v2».

| ID | Krav | Detaljer |
| --- | --- | --- |
| FK-1 | Filter for enhetstype (`devices.type`) | Standard fra innstilling `default_type` (`power`). Valget «Alle» tillatt. §12.7 |
| FK-2 | Filter for måleverdi (sensorklasse) | Fylles dynamisk med klasser som finnes for valgt enhetstype, med antall i parentes. §12.7 |
| FK-3 | Filter for OS og enhetsgruppe | Begge valgfrie, standard «Alle». |
| FK-4 | Resultattabell | Kolonner: Hostname (lenke), Lokasjon, Sensor, Verdi, Sist oppdatert. §12.9 |
| FK-5 | Sortering på alle kolonner | Standardretning per klasse, se §12.6 (SortPolicy). Klikk på kolonneoverskrift bytter. |
| FK-6 | Topp N | 10, 25, 50, 100, Alle. Standard fra innstilling `default_limit` (25). |
| FK-7 | Fritekstsøk | Treff i hostname, sysName, display og lokasjonsnavn. |
| FK-8 | Aggregering per enhet | `Ingen` (én rad per sensor, standard), `Min` eller `Maks` (én rad per enhet). |
| FK-9 | Fargekoding | Ut fra LibreNMS-terskler, se §12.6 (Severity). Enheter som er nede vises dempet. |
| FK-10 | Delbar URL | Alle filtre speiles i query-strengen og leses ved sidelast. |
| FK-11 | CSV-eksport | Samme rader og rekkefølge som tabellen, se §12.8. |
| FK-12 | Sammendragslinje | Antall, min, median, maks for valgt verdi (før topp N-begrensning). |
| FK-13 | Dashboard-widget | Ikke mulig som plugin: LibreNMS har ingen widget-hook (kun meny, side, innstillinger, enhetsoversikt og port-fane). Utgår. Se kapittel 13. |

## 5. Datagrunnlag i LibreNMS

All data finnes allerede i tabellen `sensors`, som LibreNMS oppdaterer ved hver polling (standard hvert 5. minutt). «Poller» i det opprinnelige ønsket betyr sensorklasse (`sensors.sensor_class`), se beslutning B-1.

Aktuelle sensorklasser for UPS:

| Sensorklasse | Betydning | Lagret enhet | Standard sortering |
| --- | --- | --- | --- |
| `runtime` | Gjenværende batteritid | minutter | stigende (kortest først) |
| `charge` | Batterikapasitet | % | stigende |
| `load` | Last på utgang | % | synkende |
| `voltage` | Inn-/ut-/batterispenning | V | stigende |
| `current` | Strøm | A | synkende |
| `power` | Effekt | W | synkende |
| `frequency` | Frekvens | Hz | stigende |
| `temperature` | Batteri-/romtemperatur | °C | synkende |
| `state` | Tilstand, f.eks. «battery needs replacing» | kode → tekst | synkende på alvorlighet |

Tabeller og kolonner som brukes (verifisert mot LibreNMS 26.9):

| Tabell | Kolonner |
| --- | --- |
| `sensors` | `sensor_id`, `device_id`, `sensor_class`, `sensor_descr`, `sensor_current`, `sensor_limit`, `sensor_limit_warn`, `sensor_limit_low`, `sensor_limit_low_warn`, `sensor_deleted`, `lastupdate` |
| `devices` | `device_id`, `hostname`, `sysName`, `display`, `type`, `os`, `status`, `disabled`, `location_id` |
| `locations` | `id`, `location` |
| `device_groups` | `id`, `name` |
| `device_group_device` | `device_group_id`, `device_id` |

Merk: hvilke klasser som finnes, avhenger av OS-definisjonen og hva UPS-ens SNMP-agent eksponerer. LibreNMS lagrer runtime i minutter (`Sensor::formatValue()` ganger med 60 før visning).

## 6. Teknisk løsning og arkitektur

Pluginen er en Composer-pakke etter LibreNMS sitt plugin-system (Laravel-pakke som registrerer hooks via `PluginManagerInterface`). Den installeres med `lnms plugin:add drakelid/librenms-ups-battery`, leser kun fra databasen og har ingen egne tabeller eller migreringer.

Dataflyt – pluginen leser bare det LibreNMS allerede har lagret (`[ ]` = nytt i dette prosjektet):

```text
                 ┌──────────── [ UPS Battery-plugin ] ────────────┐
┌────────────┐   │ ┌──────────────────┐   ┌─────────────────────┐ │   ┌───────────────────┐
│ Nettleser  │──────▶│ ReportController │──▶│ SensorReportService │─────▶│ LibreNMS-DB       │
│ Filtervalg │   │ │ /plugin/         │   │ Eloquent-spørring   │ │   │ sensors, devices  │
│ Tabell/CSV │   │ │ ups-battery/*    │   │ + brukertilgang     │ │   │ lokasjon, grupper │
└────────────┘   │ │ HTML, JSON, CSV  │   └─────────────────────┘ │   └─────────▲─────────┘
                 │ └──────────────────┘                           │             │ skriver
                 └────────────────────────────────────────────────┘             │
                                          ┌─────────────────────┐ SNMP ┌────────┴──────────┐
                                          │ UPS-er              │◀─────│ LibreNMS-poller   │
                                          │ SNMP-agent / NMC    │      │ hvert 5. minutt   │
                                          │ runtime, load, ...  │      │ (eksisterende)    │
                                          └─────────────────────┘      └───────────────────┘
```

Komponenter (detaljer i kapittel 12):

1. **UpsBatteryProvider** – ServiceProvider: publiserer hooks, laster ruter, views og oversettelser.
2. **MenuEntry** (`MenuEntryHook`) – legger «UPS Battery» i LibreNMS-menyen under *Plugins*.
3. **Settings** (`SettingsHook`) – innstillingsside for standardverdier.
4. **ReportController** – tre ruter: HTML-side, JSON/CSV-data og filteralternativer.
5. **SensorReportService** – henter rader med Eloquent og brukerens tilgang; ren PHP-logikk (sortering, aggregering, sammendrag, alvorlighet) ligger i egne, enhetstestbare klasser.
6. **Frontend** – én Blade-view som utvider LibreNMS sin layout, Bootstrap 3-markup og inline JavaScript (jQuery er tilgjengelig fra layouten).

Designvalg:

- Sensorklasser, enhetstyper og OS hentes dynamisk fra databasen, ikke hardkodet.
- Tilgangsstyring med LibreNMS sitt scope `Sensor::hasAccess($user)` (arvet fra `DeviceRelatedModel`). Brukere med global lesetilgang ser alt; andre ser kun egne enheter.
- Ingen RRD-lesing; kun siste verdi fra `sensor_current`.
- Filtrering skjer i SQL; sortering, aggregering, sammendrag og topp N skjer i PHP i rene klasser uten avhengighet til Laravel, slik at de kan enhetstestes uten en LibreNMS-installasjon.

## 7. Brukergrensesnitt

Siden består av én filterlinje, én sammendragslinje og én tabell; alt skjer på samme side uten sideskift.

Skisse av rapportsiden (eksempelverdier):

```text
[ Device type: power ▾ ] [ Metric: runtime (38) ▾ ] [ OS: All ▾ ] [ Group: All ▾ ]
[ Per device: none ▾ ]   [ Show: 25 ▾ ]   [ Search…            ]   [ Export CSV ]

Showing 5 of 38 · min 4 min · median 22 min · max 1 h 1 min

  Hostname ▾        Location    Sensor            Value ▲         Last updated
  ───────────────────────────────────────────────────────────────────────────
  ups-stasjon-01    Stasjon A   Battery runtime   4 min           3 min ago     (rød rad)
  ups-stasjon-02    Stasjon B   Battery runtime   9 min           3 min ago     (gul rad)
  ups-stasjon-03    Stasjon C   Battery runtime   18 min          4 min ago
  ups-stasjon-04    Stasjon D   Battery runtime   27 min          2 min ago
  ups-stasjon-05    Stasjon E   Battery runtime   41 min          4 min ago
```

- Endring i et filter henter data på nytt (AJAX, 300 ms debounce på søkefeltet) og oppdaterer URL-en med `history.replaceState`.
- Bytte av enhetstype laster måleverdi-listen på nytt; valgt klasse beholdes hvis den fortsatt finnes.
- Fargekoding: Bootstrap 3-klassene `danger` (kritisk) og `warning` (advarsel) på raden; enheter som er nede får klassen `text-muted`.
- Ved tom liste vises «No sensors match the selected filters.»; ved feil vises en `alert alert-danger` med feilmeldingen.
- Siden følger LibreNMS sitt lyse og mørke tema ved kun å bruke Bootstrap-klasser, ingen egne farger.

## 8. Ikke-funksjonelle krav

| ID | Område | Krav |
| --- | --- | --- |
| IFK-1 | Ytelse | Data-endepunktet svarer på under 2 sekunder for 1 000 enheter og 20 000 sensorer. Maks 20 000 rader hentes per forespørsel (§12.7). |
| IFK-2 | Tilgang | Rutene krever innlogging (`web`, `auth`). Data filtreres med `hasAccess($user)`. |
| IFK-3 | Sikkerhet | All input valideres mot hvitelister fra databasen; ingen rå SQL med brukerinput; LIKE-søk escaper `%`, `_` og `\`. Data settes inn i DOM med `textContent`, aldri `innerHTML`. |
| IFK-4 | Kompatibilitet | LibreNMS 26.x (testet mot 26.9.1.1), PHP ^8.2, Laravel 12. |
| IFK-5 | Drift | Ingen migreringer, ingen påvirkning på polling. Når pluginen er deaktivert i *Plugin Admin*, registreres ingen ruter. |
| IFK-6 | Vedlikehold | README, CHANGELOG, semantisk versjonering, Laravel Pint (preset `laravel`). |
| IFK-7 | Språk | Grensesnitt på engelsk via oversettelsesfil `lang/en/ups-battery.php`. Norsk (`lang/nb`) er v2. |

## 9. Test og akseptansekriterier

Automatiserte tester (Pest, kjører uten LibreNMS, se §12.10) må passere. Deretter verifiseres punktene under manuelt i en LibreNMS-instans.

- [ ] `php artisan route:list --path=ups-battery` viser tre ruter.
- [ ] Menyen *Plugins* viser «UPS Battery», og lenken åpner siden.
- [ ] Med enhetstype «power» og klasse «runtime» vises alle UPS-er med runtime-sensor, kortest først.
- [ ] Verdiene er identiske med enhetssiden i LibreNMS for de samme sensorene (stikkprøve på 5 enheter).
- [ ] Bytte til «load» gir liste sortert høyest først uten at siden lastes på nytt.
- [ ] Måleverdi-listen inneholder bare klasser som finnes for valgt enhetstype.
- [ ] En bruker uten global lesetilgang ser bare enhetene brukeren har tilgang til.
- [ ] Deaktiverte enheter og slettede sensorer vises ikke; enheter som er nede vises dempet.
- [ ] CSV-eksporten inneholder samme rader og rekkefølge som tabellen, og åpnes riktig i norsk Excel.
- [ ] Kopiert URL gjenskaper samme filtrering i en annen nettleser.
- [ ] Ukjent klasse eller type gir HTTP 422 med feilmelding, aldri HTTP 500.
- [ ] Innstillinger lagret i *Plugin Admin* brukes som standardverdier.

## 10. Leveranseplan

1. **MVP** – Pakkeskjelett, provider, meny, side, data-endepunkt med type- og klassefilter, sortering, tilgang, rene klasser med tester.
2. **Komplett v1** – OS-, gruppe- og søkefilter, topp N, aggregering, fargekoding, sammendrag, URL-tilstand, CSV, innstillinger.
3. **Verifisering** – Akseptansetest (kapittel 9) i testinstans, deretter produksjon.

Versjon 2 (kandidater): dashboard-widget, norsk oversettelse, historikk/trend, planlagt e-postrapport, flere verdier per UPS i samme rad.

## 11. Beslutninger

Disse var åpne spørsmål og er låst for versjon 1. Endringer krever ny versjon av spesifikasjonen.

| ID | Spørsmål | Beslutning |
| --- | --- | --- |
| B-1 | Hva betyr «poller»? | Sensorklasse (`sensors.sensor_class`). |
| B-2 | Hva er «type enhet»? | `devices.type` som hovedfilter; OS og enhetsgruppe som tilleggsfiltre. |
| B-3 | Hvem har tilgang? | Alle innloggede brukere, begrenset til enheter de har tilgang til. Innstillinger kun for `plugin.admin`. |
| B-4 | Eksportformat? | CSV med semikolon og desimalkomma (norsk Excel). |
| B-5 | LibreNMS-versjon? | 26.x, testet mot 26.9.1.1. |
| B-6 | Pakkenavn og navnerom? | `drakelid/librenms-ups-battery`, `Drakelid\UpsBattery`. Kan byttes før første publisering. |
| B-7 | Enheter som er nede / deaktiverte? | Deaktiverte enheter (`disabled = 1`) og slettede sensorer (`sensor_deleted = 1`) utelates. Enheter som er nede vises dempet. |
| B-8 | Trådløse sensorer? | Utenfor v1. |

Kjent risiko: LibreNMS sin innebygde *Health*-side (Overview → Health → f.eks. Runtime) dekker deler av behovet. Pluginen tilfører type-, OS- og gruppefilter, topp N, aggregering, sammendrag og CSV.

## 12. Implementeringsguide

### 12.1 Plattform og avhengigheter

- Kjører inne i LibreNMS 26.x. Klassene `App\Models\Sensor`, `App\Models\Device`, `App\Models\DeviceGroup`, `LibreNMS\Util\Url` m.fl. finnes kun i LibreNMS-runtime. **De skal ikke legges i `require`.**
- Eneste runtime-avhengighet: `librenms/plugin-interfaces: ^1.0`.
- Utviklingsavhengigheter: `pestphp/pest: ^3.0`, `laravel/pint: ^1.13`.
- Plugin-API-et er verifisert mot `librenms/plugin-interfaces` (main) og LibreNMS sitt eksempel [murrant/librenms-example-plugin](https://github.com/murrant/librenms-example-plugin). Hook-metodene `authorize()` og `handle()` kalles med dependency injection; `$pluginName` og `$settings` kan injiseres i `handle()`.

### 12.2 Identifikatorer

| Ting | Verdi |
| --- | --- |
| Composer-pakke | `drakelid/librenms-ups-battery` |
| PSR-4 | `Drakelid\UpsBattery\` → `src/`, tester `Drakelid\UpsBattery\Tests\` → `tests/` |
| Plugin-navn (hooks, view- og lang-namespace) | `ups-battery` (konstant `UpsBatteryProvider::PLUGIN`) |
| Ruter | `plugin/ups-battery/report`, `plugin/ups-battery/data`, `plugin/ups-battery/options` |
| Rutenavn | `ups-battery.report`, `ups-battery.data`, `ups-battery.options` |

Merk: LibreNMS har ruten `plugin/{plugin:plugin_name}` (ett segment). Våre ruter har derfor alltid to segmenter etter `plugin/` for å unngå kollisjon.

### 12.3 Filstruktur

```text
composer.json
README.md
CHANGELOG.md
LICENSE                              (MIT)
pint.json                            {"preset": "laravel"}
phpunit.xml
routes/web.php
lang/en/ups-battery.php
resources/views/menu.blade.php
resources/views/report.blade.php
resources/views/settings.blade.php
src/UpsBatteryProvider.php
src/Hooks/MenuEntry.php
src/Hooks/Settings.php
src/Http/Controllers/ReportController.php
src/Report/ReportFilters.php         ren PHP
src/Report/ReportRow.php             ren PHP
src/Report/SortPolicy.php            ren PHP
src/Report/Severity.php              ren PHP (enum)
src/Report/RowProcessor.php          ren PHP: aggregering, sortering, topp N
src/Report/Summary.php               ren PHP
src/Report/CsvFormatter.php          ren PHP
src/Report/SensorReportService.php   avhenger av LibreNMS-modeller
tests/Pest.php
tests/Unit/ReportFiltersTest.php
tests/Unit/SortPolicyTest.php
tests/Unit/SeverityTest.php
tests/Unit/RowProcessorTest.php
tests/Unit/SummaryTest.php
tests/Unit/CsvFormatterTest.php
```

«Ren PHP» betyr: ingen `use` av `Illuminate\*`, `App\*` eller `LibreNMS\*`. Disse klassene testes i §12.10.

### 12.4 composer.json

```json
{
    "name": "drakelid/librenms-ups-battery",
    "description": "LibreNMS plugin: rank devices by a selected sensor class (UPS runtime, load, charge ...)",
    "type": "package",
    "license": "MIT",
    "keywords": ["librenms", "plugin", "ups", "sensors"],
    "require": {
        "php": "^8.2",
        "librenms/plugin-interfaces": "^1.0"
    },
    "require-dev": {
        "laravel/pint": "^1.13",
        "pestphp/pest": "^3.0"
    },
    "autoload": {
        "psr-4": { "Drakelid\\UpsBattery\\": "src/" }
    },
    "autoload-dev": {
        "psr-4": { "Drakelid\\UpsBattery\\Tests\\": "tests/" }
    },
    "extra": {
        "laravel": {
            "providers": ["Drakelid\\UpsBattery\\UpsBatteryProvider"]
        }
    },
    "scripts": {
        "test": "pest",
        "lint": "pint --test",
        "fix": "pint"
    },
    "config": {
        "sort-packages": true,
        "allow-plugins": { "pestphp/pest-plugin": true }
    },
    "minimum-stability": "stable"
}
```

### 12.5 Provider, hooks, ruter og views

`src/UpsBatteryProvider.php`:

```php
<?php

namespace Drakelid\UpsBattery;

use Drakelid\UpsBattery\Hooks\MenuEntry;
use Drakelid\UpsBattery\Hooks\Settings;
use Illuminate\Support\ServiceProvider;
use LibreNMS\Interfaces\Plugins\Hooks\MenuEntryHook;
use LibreNMS\Interfaces\Plugins\Hooks\SettingsHook;
use LibreNMS\Interfaces\Plugins\PluginManagerInterface;

class UpsBatteryProvider extends ServiceProvider
{
    public const PLUGIN = 'ups-battery';

    public function boot(PluginManagerInterface $pluginManager): void
    {
        // Hooks must always be published, otherwise LibreNMS may remove the plugin from the UI.
        $pluginManager->publishHook(self::PLUGIN, MenuEntryHook::class, MenuEntry::class);
        $pluginManager->publishHook(self::PLUGIN, SettingsHook::class, Settings::class);

        if (! $pluginManager->pluginEnabled(self::PLUGIN)) {
            return;
        }

        $this->loadRoutesFrom(__DIR__.'/../routes/web.php');
        $this->loadViewsFrom(__DIR__.'/../resources/views', self::PLUGIN);
        $this->loadTranslationsFrom(__DIR__.'/../lang', self::PLUGIN);
    }
}
```

`src/Hooks/MenuEntry.php` – menyen inkluderer viewen inne i et `<li>` under *Plugins*:

```php
class MenuEntry implements \LibreNMS\Interfaces\Plugins\Hooks\MenuEntryHook
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array{0: string, 1: array<string, mixed>} */
    public function handle(string $pluginName): array
    {
        return ["$pluginName::menu", []];
    }
}
```

`resources/views/menu.blade.php`:

```blade
<a href="{{ route('ups-battery.report') }}">
    <i class="fa fa-battery-half fa-fw fa-lg" aria-hidden="true"></i> {{ __('ups-battery::ups-battery.title') }}
</a>
```

`src/Hooks/Settings.php`:

```php
class Settings implements \LibreNMS\Interfaces\Plugins\Hooks\SettingsHook
{
    public function authorize(\Illuminate\Foundation\Auth\User $user): bool
    {
        return true; // LibreNMS already requires plugin.admin for the settings page
    }

    public function handle(string $pluginName, array $settings): array
    {
        return [
            'content_view' => "$pluginName::settings",
            'settings' => $settings,
        ];
    }
}
```

`resources/views/settings.blade.php` – LibreNMS rendrer den inne i `layouts.librenmsv1` med variablene `$settings` og `$plugin_name`. Lagring skjer via LibreNMS sin egen rute `plugin.update`, som erstatter hele `settings`-arrayet, så alle tre feltene må alltid sendes:

```blade
<div class="container-fluid">
<form method="post" action="{{ route('plugin.update', ['plugin' => $plugin_name]) }}" class="form-horizontal">
    @csrf
    {{-- Three fields: settings[default_type] (text, default "power"),
         settings[default_class] (text, default "runtime"),
         settings[default_limit] (select 10|25|50|100|0, default 25).
         Prefill with $settings[...] ?? default. Submit button "Save". --}}
</form>
</div>
```

`routes/web.php`:

```php
<?php

use Drakelid\UpsBattery\Http\Controllers\ReportController;
use Illuminate\Support\Facades\Route;

Route::middleware(['web', 'auth'])->prefix('plugin/ups-battery')->group(function (): void {
    Route::get('report', [ReportController::class, 'page'])->name('ups-battery.report');
    Route::get('data', [ReportController::class, 'data'])->name('ups-battery.data');
    Route::get('options', [ReportController::class, 'options'])->name('ups-battery.options');
});
```

Standardverdier leses i controlleren med `app(PluginManagerInterface::class)->getSettings(UpsBatteryProvider::PLUGIN)` og faller tilbake til `power` / `runtime` / `25`.

### 12.6 Rene klasser (forretningslogikk)

**`ReportFilters`** – `final readonly class` med feltene:

| Felt | Type | Standard | Regel |
| --- | --- | --- | --- |
| `type` | `?string` | innstilling `default_type` | `null` = alle typer |
| `class` | `string` | innstilling `default_class` | påkrevd, `^[a-z0-9_]+$` |
| `os` | `?string` | `null` | `null` = alle |
| `group` | `?int` | `null` | `null` = alle |
| `q` | `?string` | `null` | trimmes, tom streng → `null`, maks 100 tegn |
| `sort` | `string` | `value` | én av `hostname`, `location`, `descr`, `value`, `lastupdate` |
| `dir` | `string` | `SortPolicy::defaultDirection(class)` når `sort = value`, ellers `asc` | `asc` / `desc` |
| `limit` | `int` | innstilling `default_limit` | én av `10, 25, 50, 100, 0`; `0` = alle |
| `aggregate` | `string` | `none` | `none` / `min` / `max` |

`public static function fromArray(array $input, array $defaults): self` normaliserer og kaster `InvalidArgumentException` med en lesbar melding ved ugyldig verdi. `toQuery(): array` gir verdiene som query-parametere (brukes i URL og CSV-filnavn). Validering mot databasen (finnes typen/klassen?) skjer i controlleren, ikke her.

**`SortPolicy::defaultDirection(string $class): string`** – `asc` for `runtime`, `charge` og alle klasser som ikke står i listen; `desc` for `load`, `current`, `power`, `temperature`, `state`.

**`Severity`** – `enum Severity: string { case Ok = 'ok'; case Warning = 'warning'; case Critical = 'critical'; case Unknown = 'unknown'; }` med:

- `static fromLimits(?float $value, ?float $low, ?float $lowWarn, ?float $warn, ?float $high): self` – `Unknown` hvis `$value === null`; `Critical` hvis `$low !== null && $value < $low` eller `$high !== null && $value > $high`; ellers `Warning` hvis `$lowWarn !== null && $value < $lowWarn` eller `$warn !== null && $value > $warn`; ellers `Ok`.
- `static fromStateGeneric(?int $generic): self` – `0` → Ok, `1` → Warning, `2` → Critical, alt annet → Unknown. Brukes for klassen `state`.
- `rank(): int` – Critical 3, Warning 2, Unknown 1, Ok 0 (brukes til sortering av `state`).

**`ReportRow`** – `final readonly class` med: `int deviceId`, `string hostname`, `string displayName`, `string deviceUrl`, `?string location`, `string os`, `bool deviceUp`, `int sensorId`, `string sensorDescr`, `?float value`, `string valueFormatted`, `string unit`, `Severity severity`, `?float limitLow`, `?float limitLowWarn`, `?float limitWarn`, `?float limitHigh`, `?string lastUpdate` (ISO 8601). Metode `toArray(): array` med nøklene i §12.7.

**`RowProcessor::process(ReportRow[] $rows, ReportFilters $f, bool $isState): array{rows: ReportRow[], total: int, summaryRows: ReportRow[]}`** – i denne rekkefølgen:

1. **Aggregering**: `none` → uendret. `min`/`max` → én rad per `deviceId`: raden med lavest/høyest `value` (rader med `null` velges bare hvis enheten ikke har andre). Ved likhet: lavest `sensorId`.
2. **Sortering** (stabil): `value` sorterer på `value` (for `state`: på `severity->rank()`, deretter `value`); `null`-verdier havner **alltid sist** uansett retning. `hostname`, `location`, `descr` sorterer case-insensitivt med `strnatcasecmp` (`null`-lokasjon sist). `lastupdate` sorterer på tidsstempel. Sekundær nøkkel alltid `hostname` stigende, deretter `sensorId`.
3. `total` = antall rader etter aggregering; `summaryRows` = alle rader etter aggregering.
4. **Topp N**: `limit > 0` → de første N radene.

**`Summary::from(ReportRow[] $rows, bool $isState): array`** – `['count' => int, 'min' => ?float, 'median' => ?float, 'max' => ?float]` beregnet over ikke-`null`-verdier. Median: gjennomsnitt av de to midterste ved partall. For `state` er `min`, `median`, `max` alltid `null`. Tom liste → `count 0` og resten `null`.

**`CsvFormatter`** – `header(): array` og `line(ReportRow $row): array` og `toCsv(ReportRow[] $rows): string`:

- Separator `;`, linjeskift `\r\n`, UTF-8 med BOM (`\xEF\xBB\xBF`) først.
- Kolonner: `hostname;display_name;location;os;sensor;value;unit;severity;last_updated`.
- `value` med desimalkomma og uten tusenskille (`12,5`); `null` → tom celle.
- Felt som inneholder `;`, `"` eller linjeskift settes i anførselstegn med `"` doblet. Felt som starter med `=`, `+`, `-`, `@` (unntatt tall) får prefikset `'` for å hindre formelinjeksjon i Excel.

### 12.7 SensorReportService og API-kontrakter

**`SensorReportService`** (avhenger av LibreNMS):

- `availableTypes(User $user): array` – `[['value' => 'power', 'count' => 38], …]`: `DISTINCT devices.type` med antall enheter, kun enheter brukeren har tilgang til og `disabled = 0`, sortert alfabetisk, tomme typer utelatt.
- `availableOs(User $user, ?string $type): array` – samme form for `devices.os`.
- `availableGroups(): array` – `[['id' => 3, 'name' => 'UPS Haugesund'], …]` fra `DeviceGroup`, sortert på navn.
- `availableClasses(User $user, ?string $type): array` – `[['value' => 'runtime', 'label' => 'Runtime', 'unit' => 'min', 'count' => 38], …]` for sensorer med `sensor_deleted = 0` på tilgjengelige, ikke-deaktiverte enheter av valgt type. `label`/`unit` hentes med `__("sensors.$class.short")` / `__("sensors.$class.unit")` (LibreNMS sine egne oversettelser, slik `Sensor::classDescr()` og `unit()` gjør). Sortert på `label`.
- `rows(User $user, ReportFilters $f): ReportRow[]` – spørring:

```php
Sensor::query()
    ->hasAccess($user)
    ->join('devices', 'devices.device_id', '=', 'sensors.device_id')
    ->leftJoin('locations', 'locations.id', '=', 'devices.location_id')
    ->where('sensors.sensor_class', $f->class)
    ->where('sensors.sensor_deleted', 0)
    ->where('devices.disabled', 0)
    ->when($f->type, fn ($q) => $q->where('devices.type', $f->type))
    ->when($f->os, fn ($q) => $q->where('devices.os', $f->os))
    ->when($f->group, fn ($q) => $q->inDeviceGroup($f->group))
    ->when($f->q, fn ($q) => $q->where(fn ($w) => $w
        ->where('devices.hostname', 'like', $like)
        ->orWhere('devices.sysName', 'like', $like)
        ->orWhere('devices.display', 'like', $like)
        ->orWhere('locations.location', 'like', $like)))
    ->select('sensors.*', 'locations.location as location_name')
    ->with($isState ? ['device', 'translations'] : ['device']) // translations only for state
    ->limit(20001)
    ->get();
```

`$like = '%'.addcslashes($f->q, '%_\\').'%'`. Hvis resultatet har 20 001 rader: kast `TooManyRowsException` (egen klasse) → HTTP 422 «Too many sensors, narrow the filters.». Hver modell mappes til `ReportRow`: `hostname` = `$device->hostname`, `displayName` = `$device->displayName()`, `deviceUrl` = `\LibreNMS\Util\Url::deviceUrl($device)`, `deviceUp` = `(bool) $device->status`, `valueFormatted` = `$sensor->formatValue()`, `unit` = `$sensor->unit()`, `severity` = `Severity::fromStateGeneric($sensor->currentTranslation()?->state_generic_value)` for `state`, ellers `Severity::fromLimits(...)` med de fire grenseverdiene.

**`ReportController`** (alle metoder har tilgang til `$request->user()`):

`GET plugin/ups-battery/report` → `view('ups-battery::report', ['defaults' => …, 'initial' => $request->query()])`.

`GET plugin/ups-battery/options?type=power` → JSON:

```json
{
  "types":   [{"value": "power", "count": 38}],
  "os":      [{"value": "apc", "count": 30}],
  "groups":  [{"id": 3, "name": "UPS Haugesund"}],
  "classes": [{"value": "runtime", "label": "Runtime", "unit": "min", "count": 38}]
}
```

`GET plugin/ups-battery/data?type=&class=&os=&group=&q=&sort=&dir=&limit=&aggregate=&format=json|csv`

1. Bygg `ReportFilters::fromArray($request->query(), $defaults)`; `InvalidArgumentException` → 422.
2. Valider mot databasen: `class` må finnes i `availableClasses()`, `type` i `availableTypes()`, `os` i `availableOs()`, `group` i `availableGroups()`; ellers 422.
3. `rows()` → `RowProcessor::process()` → `Summary::from(summaryRows)`.
4. `format=csv` → `response()->streamDownload(fn () => print($csv->toCsv($rows)), $filename, ['Content-Type' => 'text/csv; charset=UTF-8'])` med filnavn `ups-battery-{class}-{YYYYmmdd-HHii}.csv`.
5. Ellers JSON:

```json
{
  "filters": {"type": "power", "class": "runtime", "os": null, "group": null, "q": null,
              "sort": "value", "dir": "asc", "limit": 25, "aggregate": "none"},
  "summary": {"count": 38, "min": 4.0, "median": 22.0, "max": 61.0, "unit": "min"},
  "total": 38,
  "rows": [{
    "device_id": 12, "hostname": "ups-stasjon-01", "display_name": "ups-stasjon-01",
    "device_url": "https://librenms.example/device/12", "location": "Stasjon A", "os": "apc",
    "device_up": true, "sensor_id": 345, "sensor_descr": "Battery runtime",
    "value": 4.0, "value_formatted": "4m", "unit": "min", "severity": "critical",
    "limits": {"low": 5.0, "low_warn": 10.0, "warn": null, "high": null},
    "last_updated": "2026-10-07T08:55:00+02:00"
  }]
}
```

Feilformat for 422: `{"message": "Unknown sensor class \"foo\"."}`.

### 12.8 CSV

Se `CsvFormatter` i §12.6. Eksporten bruker nøyaktig samme filtre og rekkefølge som JSON-svaret (inkludert topp N), slik at fil og skjerm alltid er like.

### 12.9 Frontend (`resources/views/report.blade.php`)

- `@extends('layouts.librenmsv1')`, `@section('title', __('ups-battery::ups-battery.title'))`, markup i `@section('content')`, JavaScript i `@push('scripts')`. jQuery og Bootstrap 3 er allerede lastet av layouten. Ingen eksterne CDN-er og ingen byggesteg.
- Filterlinje i et `panel panel-default` med `form-inline`: `select` for type, klasse, OS, gruppe, aggregering og topp N, `input type="search"` for søk, og en `a.btn.btn-default` for CSV hvis `href` alltid oppdateres til `data`-URL-en med `format=csv`.
- Tabell `table table-condensed table-hover` med kolonnene Hostname, Location, Sensor, Value, Last updated. Overskriftene er klikkbare (`role="button"`, viser ▲/▼ på aktiv kolonne). Klikk på aktiv kolonne bytter retning; klikk på ny kolonne setter `asc`, eller standardretning for `value`.
- Hostname-cellen er en `<a href="device_url">` med `display_name` som tekst og `hostname` i `title`. Verdi-cellen viser `value_formatted`. Sist oppdatert vises relativt («3 min ago») med absolutt tid i `title`.
- Rad-klasser: `danger` for `critical`, `warning` for `warning`, i tillegg `text-muted` hvis `device_up` er `false`.
- Sammendrag over tabellen: «Showing {rows.length} of {total} · min … · median … · max …» med enhet; for `state` kun antall.
- Oppstart: les filtre fra URL (`initial`), ellers `defaults`; hent `options`, fyll nedtrekkslistene, hent `data`. Ved endring av type: hent `options` på nytt før `data`.
- Alle verdier fra serveren settes med `textContent` / `document.createElement`. Bruk `fetch` med `headers: {'Accept': 'application/json'}` og `credentials: 'same-origin'`.
- Avbryt pågående forespørsel med `AbortController` når en ny startes, slik at gamle svar aldri overskriver nye.

### 12.10 Tester

- Pest-enhetstester for alle rene klasser. Minimumsdekning:
  - `ReportFiltersTest`: standardverdier fra `$defaults`; ugyldig `sort`, `dir`, `limit`, `aggregate`, `class` gir unntak; `q` trimmes og kuttes; `dir` følger `SortPolicy` når den mangler.
  - `SortPolicyTest`: `runtime` → `asc`, `load` → `desc`, ukjent klasse → `asc`.
  - `SeverityTest`: alle grener i `fromLimits` inkludert `null`-grenser, og alle verdier i `fromStateGeneric`.
  - `RowProcessorTest`: `null` sist i begge retninger; stabil sekundærsortering; `min`/`max`-aggregering; topp N og `total`.
  - `SummaryTest`: tom liste, oddetall, partall, `null`-verdier, `state`.
  - `CsvFormatterTest`: BOM, separator, desimalkomma, anførselstegn, formelinjeksjon.
- `SensorReportService`, controller og views testes manuelt etter kapittel 9 i en LibreNMS-instans (de krever LibreNMS-runtime).
- `composer test` og `composer lint` skal passere.

### 12.11 Installasjon (README)

README skal dokumentere:

```bash
# Production (after publishing to Packagist or a VCS repository)
cd /opt/librenms
sudo -u librenms ./lnms plugin:add drakelid/librenms-ups-battery

# Local development
sudo -u librenms composer config repositories.ups-battery '{"type":"path","url":"/path/to/Libre-UPS","options":{"symlink":true}}'
sudo -u librenms ./lnms plugin:add drakelid/librenms-ups-battery @dev
```

Deretter: aktiver «ups-battery» under *Overview → Plugins → Plugin Admin*, og åpne *Overview → Plugins → UPS Battery*.

### 12.12 Definition of Done

- [ ] Alle filer i §12.3 finnes, og koden følger signaturene og kontraktene i §12.5–12.9.
- [ ] `composer test` og `composer lint` passerer.
- [ ] README (§12.11) og CHANGELOG (`1.0.0`) er skrevet.
- [ ] Ingen funksjonalitet utover denne spesifikasjonen; avvik er notert i CHANGELOG under «Known deviations».
- [ ] Akseptansekriteriene i kapittel 9 er gjennomgått i en LibreNMS-instans (gjøres av driftsteamet).

## 13. Versjon 1.1: utvidelser og endringer

Dette kapittelet gjelder fra versjon 1.1 og går foran kapittel 1–12 der de er uenige. Det er resultatet av en kodegjennomgang av versjon 1.0.

### 13.1 Rettelser

| ID | Endring |
| --- | --- |
| R-1 | Ugyldige innstillinger (f.eks. standardmåleverdi med stor bokstav) skal aldri gjøre siden ubrukelig. Alle innstillinger går gjennom `PluginSettings`, som erstatter ugyldige verdier med standardverdier. |
| R-2 | Navn på enhetsgrupper vises bare for brukere som har tilgang til gruppen (`DeviceGroup::hasAccess`). |
| R-3 | Temperatur følger brukerens °C/°F-valg overalt: verdi, grenser, sortering, sammendrag, CSV og enhetsetikett. |
| R-4 | «Per enhet»-aggregering ignoreres for `state`-sensorer, og kontrollen er deaktivert i grensesnittet. |

### 13.2 Ytelse

- Rapporter hentes i to faser: en lett spørring uten Eloquent-hydrering finner, sorterer og oppsummerer alle treff; kun radene som vises lastes som fulle modeller (formatering, lenker). `state`-sensorer lastes alltid som fulle modeller fordi alvorlighet krever tilstandsoversettelser.
- Grensen for antall rader er 50 000 (tidligere 20 000), se §12.7.
- Filteralternativer (typer, OS, grupper, måleverdier) caches i 60 sekunder per bruker og gjenbrukes til validering av input. Hver dataforespørsel gjør dermed ingen ekstra spørringer for validering ved treff i cachen.
- Alle lenker og endepunkt-URL-er sendes som relative stier (inkludert eventuelt underkatalog-prefiks).

### 13.3 Nye funksjoner

| ID | Funksjon | Beskrivelse |
| --- | --- | --- |
| FK-14 | Terskler | Innstilling `thresholds`: én linje per måleverdi, `<klasse> <kritisk-regel> [<advarsel-regel>]`, regel = `<` eller `>` + tall (desimalpunkt eller -komma). `#` starter kommentar. Reglene bruker visningsenheten (minutter for runtime, % for load/charge, brukerens °C/°F) og **erstatter** LibreNMS-grensene for den klassen. Ugyldige linjer ignoreres og vises under tekstfeltet i innstillingene. Eksempel: `runtime <10 <20`. |
| FK-15 | Sammenlign måleverdier | Visning «Compare metrics» med endepunkt `GET plugin/ups-battery/matrix` (JSON og CSV). Én rad per enhet, én kolonne per måleverdi (1–6, standard runtime, load, charge). Hver celle viser enhetens **dårligste** sensor for måleverdien: laveste verdi der `SortPolicy` er stigende (runtime, charge), høyeste ellers. Sortering på hostname, lokasjon eller en valgt måleverdi (manglende celler sist). |
| FK-16 | Automatisk oppdatering | Innstilling `refresh_seconds` (standard 300, 0 = av, ellers 30–3600). Avkrysningsboks på siden. Relative tidspunkter oppdateres hvert 30. sekund uten ny henting. |
| FK-17 | Gamle data | Innstilling `stale_minutes` (standard 30, 1–1440). Verdier eldre enn dette får et varselikon. |
| FK-18 | Sensorlenke og graf | Verdi lenker til sensoren på enhetssiden (`sensor_url`). Hover viser LibreNMS-grafen for siste 24 timer (`graph_url`). |
| FK-19 | Lagrede visninger | Navngitte filtre per bruker, lagret i LibreNMS brukerpreferanse `ups-battery.views` (maks 20, navn maks 40 tegn). Endepunkt: `GET views`, `POST views` (`{name, query}`), `POST views/delete` (`{name}`). |
| FK-20 | Enhetskort | `DeviceOverviewHook`: panelet «UPS Battery» på enhetens oversiktsside for enheter med `runtime`-, `charge`- eller `load`-sensor. |
| FK-21 | Språk | Innstilling `language` (`en` eller `nb`). LibreNMS har ingen norsk språkkode, så språket velges i pluginen. Oversettelser i `lang/en` og `lang/nb`. |

### 13.4 Endrede og nye API-felt

- Rad i `data`-svaret har to nye felt: `sensor_url` og `graph_url`.
- Svaret fra `data` har `summary.unit` fra brukerens visningsenhet (°F for temperatur når brukeren har valgt °F).
- `matrix`-svaret: `{"filters": {...}, "classes": [{"value","label","unit"}], "total": n, "rows": [{"device_id","hostname","display_name","device_url","location","os","device_up","cells": {"runtime": {"sensor_id","sensor_descr","sensor_url","graph_url","value","value_formatted","unit","severity","last_updated"} | null}}]}`.
- CSV for `matrix`: kolonnene `hostname;display_name;location;os` og deretter `<klasse>;<klasse>_severity` for hver valgt måleverdi.

### 13.5 Nye filer

`src/Report/`: `InputParser`, `Aggregator`, `MatrixFilters`, `MatrixRow`, `MatrixBuilder`, `Thresholds`, `PluginSettings`, `SavedViews`, `Urls`. `src/Hooks/DeviceOverview.php`. `resources/views/device-overview.blade.php`. `lang/nb/ups-battery.php`. `.github/workflows/ci.yml`. Alle klasser i `src/Report` unntatt `SensorReportService` er rene PHP-klasser med enhetstester.

### 13.6 Utgått

FK-13 (dashboard-widget) utgår: pluginsystemet har ingen widget-hook, og widgets er kjerne-kontrollere i LibreNMS. Skal det gjøres må det skje som en endring oppstrøms i LibreNMS.

### 13.7 Tilleggskriterier for akseptanse

- [ ] Ugyldig «Default metric» i innstillingene (f.eks. `Runtime!`) gjør ikke at siden slutter å virke.
- [ ] En bruker uten tilgang til en enhetsgruppe ser ikke gruppen i filteret.
- [ ] En bruker med °F ser samme tall i tabell, sammendrag og CSV for temperatur.
- [ ] Med terskelregelen `runtime <10 <20` er rader under 10 minutter røde og under 20 minutter gule, også når LibreNMS ikke har sensorgrenser.
- [ ] «Compare metrics» viser runtime, load og charge for hver UPS, og sortering på load setter UPS-er uten load-sensor sist.
- [ ] Hover over en verdi viser grafen; klikk åpner sensoren på enhetssiden.
- [ ] Lagret visning kan åpnes, og slettes, og er ikke synlig for andre brukere.
- [ ] Enhetssiden for en UPS viser panelet «UPS Battery».
- [ ] Med språk «Norsk (bokmål)» er menyvalg, side og innstillinger oversatt.
- [ ] Automatisk oppdatering henter nye data uten å miste filtre, og kan skrus av i innstillingene (0).

## 14. Forbedringer etter andre kodegjennomgang

Dette kapittelet gjelder foran kapittel 1–13 der de er uenige. Det beskriver rettelser og utvidelser etter en gjennomgang av den første versjonen.

### 14.1 Rettelser

| ID | Endring |
| --- | --- |
| R-5 | Runtime 0 vises som «0 min». LibreNMS sin egen formatering gir tom tekst for 0, så en UPS uten batteritid igjen fikk en blank celle. Pluginen formaterer runtime selv (`RuntimeFormatter`): «0 min», «45 min», «1 h 5 min», «2 d 3 h». |
| R-6 | Om en rad er ferdig lastet avgjøres av feltet `ReportRow::$hydrated`, ikke av om verdien er en tom tekst. |
| R-7 | Pluginens terskler sammenlignes med verdien LibreNMS lagrer (minutter, %, °C), ikke med verdien som vises. Reglene gir da samme resultat for alle brukere, også de som har valgt °F. LibreNMS sine egne sensorgrenser konverteres fortsatt til brukerens enhet. |
| R-8 | Oppsummeringslinjen viser runtime som varighet («min 4 min · median 22 min · maks 1 h 1 min»). |
| R-9 | CI kjører Pest selv om Pint feiler, og kodestilen er rettet slik at Pint passerer. |

### 14.2 Nye funksjoner

| ID | Funksjon | Beskrivelse |
| --- | --- | --- |
| FK-22 | Mistenkelige batterier | Et batteri er mistenkelig når runtime er under grensen samtidig som lasten er lav og batteriet er ladet. Regel (`SuspectRule`, `SuspectBattery`): `runtime < suspect_runtime` OG `load <= suspect_max_load` OG (`charge` ukjent ELLER `charge >= suspect_min_charge`). Standard 10 min, 30 %, 95 %. Kan ikke avgjøres uten runtime og load (verdien er da `null`). Vises som kolonnen «Battery» i sammenligningsvisningen (`suspect` i JSON, `suspect_battery` i CSV), som filteret «Suspect batteries only» (`suspect=1`, krever runtime og load blant måleverdiene), som varsel på enhetskortet og i ukerapporten. Enhetens verdier er kortest runtime, høyest load og lavest charge. |
| FK-23 | Filter på sensornavn | Parameteren `sensor` filtrerer på `sensors.sensor_descr` (inneholder, maks 100 tegn) i begge visninger. I sammenligningsvisningen gjelder filteret alle valgte måleverdier. |
| FK-24 | Varslingsregler fra terskler | Innstillingssiden viser for lagrede terskler tilsvarende LibreNMS-regel (`AlertRuleHint`) som kan limes inn under Alerts > Alert Rules > Create rule > Advanced. Kritisk regel: `macros.device_up = 1 AND sensors.sensor_class = "runtime" AND sensors.sensor_current < 10`. Advarselsregelen utelater det kritiske området, slik at en sensor aldri utløser begge. Pluginen oppretter ingen varsler selv. |
| FK-25 | Ukentlig e-postrapport | Artisan-kommandoen `ups-battery:report` (`--to=`, `--dry-run`) sender en HTML-e-post med antall enheter og kritiske, advarsler og mistenkelige, en tabell med kortest runtime (topp 10, 25, 50 eller 100) og tabell over mistenkelige batterier. Kommandoen kjøres av Laravel-planleggeren i LibreNMS (`weeklyOn`) når innstillingene `report_enabled` og minst én gyldig mottaker i `report_recipients` finnes; dag (`report_day`, 0 = søndag), klokkeslett (`report_time`, TT:MM) og antall rader (`report_top`) er innstillinger. E-post sendes med `LibreNMS\Util\Mail::send` og LibreNMS sine e-postinnstillinger. Rapporten leser alle enheter (uten brukertilgang), siden den ikke kjøres av en bruker. |
| FK-26 | Kioskvisning | `kiosk=1` i adressen, eller knappen «Kiosk view», skjuler filtre og forstørrer tabellen, slår på automatisk oppdatering (300 s hvis innstillingen er 0) og kan avsluttes med Esc eller knappen. Flagget lagres ikke i lagrede visninger. |
| FK-27 | Eksporter alle rader | Egen lenke «Export all rows» laster ned CSV uten begrensning på antall rader (`limit=0`). |
| FK-28 | Trendlenke | Hver sensor i enkeltvisningen har et ikon som åpner LibreNMS sin grafside for sensoren siste år (`trend_url`). Selve nedgangen over tid beregnes ikke av pluginen. |
| FK-29 | Eget JavaScript | Sidens skript ligger i `resources/js/report.js` og leveres av ruten `plugin/ups-battery/assets/report.js`. Sidens data (adresser, tekster, standardverdier) ligger i `<script type="application/json" id="ub-config">`. |

### 14.3 Nye eller endrede API-felt

- `data` og `matrix` godtar `sensor`. `matrix` godtar `suspect` (`1`).
- Rader i `data` har `trend_url`. Celler i `matrix` har `trend_url`, og hver enhet har `suspect` (`true`, `false` eller `null`).
- `filters` i svarene har `sensor` (og `suspect` for `matrix`).
- CSV for `matrix` har kolonnen `suspect_battery` (`yes`, `no` eller tom) når runtime og load er valgt.
- Nye innstillinger: `suspect_runtime`, `suspect_max_load`, `suspect_min_charge`, `report_enabled`, `report_recipients`, `report_day`, `report_time`, `report_top`.
- Nye lagrede nøkler i en visning: `sensor`, `suspect`.

### 14.4 Nye filer

`src/Report/`: `NumberFormat`, `RuntimeFormatter`, `SuspectRule`, `SuspectBattery`, `AlertRuleHint`, `ReportSchedule`, `WeeklyReport`. `src/Console/SendReport.php`. `resources/js/report.js`. `package.json`, `package-lock.json`, `tests/js/report.test.mjs`. `.github/workflows/integration.yml`. Alle klasser i `src/Report` unntatt `SensorReportService` er rene PHP-klasser med enhetstester.

### 14.5 Testing

- Pest (PHP): enhetstester for alle rene klasser, språkfilene (samme nøkler og `:plassholdere` på engelsk og norsk, og at alle tekster som visninger og skript bruker finnes) og visningene (hver Blade-fil kompileres til gyldig PHP med `illuminate/view`, rutenavn og kontrollermetoder finnes, og hver `id` skriptet slår opp finnes i siden).
- JavaScript: `npm test` laster sidens egen markup i jsdom med `fetch` erstattet, og kjører `report.js` gjennom enkeltvisning, sammenligningsvisning, mistenkelig-filter, sensornavn, eksportlenker, kiosk, lagrede visninger, feilmeldinger og at tekst fra serveren ikke tolkes som HTML.
- CI (`ci.yml`): PHP 8.2–8.4 (syntaks, Pint, Pest) og JavaScript (syntaks, jsdom-tester).
- `integration.yml` (manuell og ukentlig): installerer pluginen i en ekte LibreNMS og sjekker at den er registrert og aktiv, at rutene og kommandoen finnes, at alle visninger kompileres, og at ukerapporten kjører mot tom database. Ikke kjørt ennå.
- Det som fortsatt krever en ekte LibreNMS med data er spørringene i `SensorReportService`, kontrolleren, hookene og den ferdig rendrede siden. De sjekkes med `scripts/verify.sh` og kriteriene under.

### 14.6 Utgått eller ikke gjort

- Beregning av batteriets nedgang over tid («runtime for 30 og 90 dager siden mot nå») er ikke gjort. Den ville kreve at pluginen leser RRD-filene direkte (`rrdtool fetch`), noe som avhenger av oppsett med rrdcached, distribuerte pollere og andre lagringsmotorer og ikke lar seg verifisere her. Trendlenken til LibreNMS sin graf dekker behovet foreløpig.
- Dashboard-widget (FK-13) er fortsatt ikke mulig som plugin.

### 14.7 Tilleggskriterier for akseptanse

- [ ] En UPS med runtime 0 viser «0 min» i tabellen, og raden er rød når grensene tilsier det.
- [ ] I sammenligningsvisningen med runtime, load og charge får en UPS med kort runtime, lav last og full lading merket «Suspect»; en UPS med kort runtime og høy last får det ikke.
- [ ] Avkrysningen «Suspect batteries only» viser kun mistenkelige UPS-er og er utilgjengelig uten runtime og load.
- [ ] Filteret «Sensor name» på «replace» eller lignende finner de sensorene det skal, i begge visninger.
- [ ] Innstillingssiden viser tilsvarende varslingsregler for lagrede terskler, og reglene kan limes inn i Alert Rules.
- [ ] `php artisan ups-battery:report --dry-run` skriver emne og HTML uten å sende e-post; uten `--dry-run` kommer e-posten til mottakerne.
- [ ] Med ukerapporten slått på og planleggeren kjørende sendes e-posten på valgt dag og tidspunkt.
- [ ] `?kiosk=1` åpner siden uten filtre med forstørret tabell; Esc avslutter.
- [ ] «Export all rows» gir alle rader uavhengig av «Show»-valget.
- [ ] Trendikonet åpner LibreNMS sin grafside for riktig sensor.
- [ ] En bruker med °F ser samme alvorlighetsfarge som en bruker med °C for samme terskelregel på temperatur.

## 15. UPS-oversikt og batteribytte

Etter ønske om mer UPS-data på siden, slik at det er lett å se hvor lenge det er til neste batteribytte. Dette kapitlet går foran kapittel 1–14 der de er uenige.

### 15.1 UPS-oversikten

- Ny visning `view=ups`, standardvisning når siden åpnes uten parametre. Adresser og lagrede visninger uten `view` åpner fortsatt «Single metric».
- Endepunkt `GET plugin/ups-battery/ups` (JSON og CSV) med filtrene `type`, `os`, `group`, `q`, `attention`, `sort` (`status`, `hostname`, `location`, `runtime`, `charge`, `load`, `temperature`, `swap`), `dir` og `limit`. Standard er `status` synkende (verst først, deretter nærmeste bytte).
- En UPS er en enhet med minst én runtime- eller charge-sensor. Én rad per UPS: status (på batteri / på nett fra utgangsstatus), runtime (lavest), charge (lavest), load (høyest), temperatur (høyest), batteristatus (verste tilstandssensor), defekte batteripakker, mistenkelig batteri (kapittel 14), siste selvtestresultat, monteringsdato og neste batteribytte. Alle øvrige sensorer vises i en rad som kan åpnes under UPS-en.
- Oppsummeringskort over alle UPS-er som passer filtrene: antall, på batteri, bytte på overtid, bytte innen varselvinduet, uten byttedato, batteriproblemer og lavest runtime.
- «Needs attention only» (`attention=1`) viser bare UPS-er med varsel eller kritisk verdi.
- Samlet alvorlighet: verste av cellene og byttet; «unknown» teller ikke, mistenkelig batteri gir minst varsel, på batteri gir kritisk.

### 15.2 Klassifisering av sensorer (ren PHP: `UpsSensorKind`)

- APC lagrer «Battery Recommended Days Remaining» og «Last Battery Replacement» som runtime-sensorer (`sensor_index` begynner med `upsAdvBatteryRecommendedReplaceDate` / `upsBasicBatteryLastReplaceDate`, verdi i minutter fra nå). De utelates fra alle runtime-rapporter og brukes kun til batteribytte.
- Tilstandssensorer etter `sensor_type`: batteristatus `upsAdvBatteryReplaceIndicator`, `upsBatteryStatusState`; utgangsstatus `upsBasicOutputStatus`, `upsOutputSourceState`; selvtest `upsAdvTestDiagnosticsResults`, `upsTestResult`. Andre leverandører gjenkjennes på navnet.
- En mislykket APC-selvtest (LibreNMS: «unknown») og defekte batteripakker over null regnes som kritisk.

### 15.3 Neste batteribytte (ren PHP: `BatterySwap`)

1. Monteringsdato registrert i UPS-oversikten + batterilevetid (innstilling, standard 48 måneder) – kilde `manual`.
2. Anbefalt byttedato fra UPS-en – kilde `ups`.
3. Siste byttedato fra UPS-en + batterilevetid – kilde `ups_last`.
4. Ellers ukjent – kilde `none`.

Dager igjen ≤ 0 er kritisk, ≤ varselvinduet (innstilling, standard 90 dager) er varsel. Monteringsdatoen lagres som enhetsattributtet `ups-battery.battery_installed` (Y-m-d) via `POST plugin/ups-battery/battery` og kan settes av brukere med LibreNMS-tillatelsen til å oppdatere enheter. Datoen kan ikke ligge i fremtiden eller før 1990.

### 15.4 Øvrig

- Enhetskortet viser neste batteribytte. Ukerapporten får tabellen «Battery swaps due».
- Lenker på enhetskortet gjøres absolutte (LibreNMS-sidene har `<base href>`).
- Datoen for siste selvtest vises ikke; LibreNMS lagrer den ikke som sensor.

### 15.5 Tilleggskriterier for akseptanse

- [ ] Siden åpner i UPS-oversikten og viser én rad per UPS med kort over tabellen.
- [ ] En APC-UPS viser byttedatoen UPS-en rapporterer, og «Last Battery Replacement» vises ikke lenger som runtime i «Single metric».
- [ ] Monteringsdato satt med blyanten gir byttedato = dato + levetid; tomt felt fjerner den.
- [ ] En bruker uten tillatelse til å oppdatere enheter ser ikke blyanten og får 403 fra endepunktet.
- [ ] En UPS på batteri er rød og telles i kortet «On battery».
- [ ] Raden åpnes og viser inn-/utgangsspenning, frekvens o.l. gruppert per sensorklasse.

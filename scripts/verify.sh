#!/usr/bin/env bash
#
# Verifies the UPS Battery plugin on a LibreNMS server.
#
#   sudo -u librenms ./scripts/verify.sh <librenms-username> [sensor-class] [librenms-dir]
#
# - <librenms-username>  a LibreNMS user with global read access (e.g. an admin); used for the data smoke test
# - [sensor-class]       defaults to "runtime"
# - [librenms-dir]       defaults to $LIBRENMS_DIR or /opt/librenms
#
# Run it as the LibreNMS user so file permissions and caches match production.
# Exit code 0 = every check passed.

set -u

USERNAME="${1:-}"
SENSOR_CLASS="${2:-runtime}"
LIBRENMS_DIR="${3:-${LIBRENMS_DIR:-/opt/librenms}}"
PLUGIN_DIR="$(cd "$(dirname "$0")/.." && pwd)"
failures=0

if [ -z "$USERNAME" ]; then
    echo "usage: $0 <librenms-username> [sensor-class] [librenms-dir]" >&2
    exit 2
fi

ok()   { printf '  [ OK ] %s\n' "$1"; }
bad()  { printf '  [FAIL] %s\n' "$1"; failures=$((failures + 1)); }
skip() { printf '  [SKIP] %s\n' "$1"; }
step() { printf '\n== %s\n' "$1"; }

step "Environment"
command -v php >/dev/null 2>&1 && ok "php $(php -r 'echo PHP_VERSION;')" || { bad "php not found"; exit 1; }
php -r 'exit(version_compare(PHP_VERSION, "8.2.0", ">=") ? 0 : 1);' && ok "PHP >= 8.2" || bad "PHP >= 8.2 required"
[ -f "$LIBRENMS_DIR/artisan" ] && ok "LibreNMS found in $LIBRENMS_DIR" || { bad "no artisan in $LIBRENMS_DIR (set LIBRENMS_DIR or pass it as 3rd argument)"; exit 1; }

step "PHP syntax (php -l)"
lint_failed=0
while IFS= read -r file; do
    if ! out="$(php -l "$file" 2>&1)"; then
        bad "$file: $out"
        lint_failed=1
    fi
done < <(find "$PLUGIN_DIR/src" "$PLUGIN_DIR/routes" "$PLUGIN_DIR/lang" "$PLUGIN_DIR/tests" -name '*.php' -type f)
[ "$lint_failed" -eq 0 ] && ok "all PHP files parse"

cd "$LIBRENMS_DIR" || exit 1

step "Plugin registration"
if ! command -v composer >/dev/null 2>&1; then
    skip "composer not in PATH, cannot check the package"
elif composer show drakelid/librenms-ups-battery >/dev/null 2>&1; then
    ok "composer package drakelid/librenms-ups-battery is installed"
else
    bad "package is not installed (run: ./lnms plugin:add drakelid/librenms-ups-battery)"
fi

if php artisan route:list --path=ups-battery 2>/dev/null | grep -q 'ups-battery.report'; then
    ok "routes registered"
    php artisan route:list --path=ups-battery 2>/dev/null | grep 'ups-battery' | sed 's/^/         /'
else
    bad "no ups-battery routes. Is the plugin enabled under Overview > Plugins > Plugin Admin? (try: php artisan route:clear)"
fi

step "Blade views compile"
if php artisan view:cache >/tmp/ups-battery-view-cache.log 2>&1; then
    ok "views compiled (including ups-battery::report, ::menu, ::settings, ::device-overview)"
else
    bad "view compilation failed: $(tail -n 5 /tmp/ups-battery-view-cache.log)"
fi
php artisan view:clear >/dev/null 2>&1

step "Unit tests"
if [ -x "$PLUGIN_DIR/vendor/bin/pest" ]; then
    if pest_out="$(cd "$PLUGIN_DIR" && vendor/bin/pest --colors=never 2>&1)"; then
        ok "pest passed"
        printf '%s\n' "$pest_out" | tail -n 5 | sed 's/^/         /'
    else
        bad "pest reported failures"
        printf '%s\n' "$pest_out" | tail -n 40 | sed 's/^/         /'
    fi
else
    skip "vendor/bin/pest missing. In the plugin checkout run: composer install && composer test"
fi

step "Data smoke test (service -> processor -> CSV) as user '$USERNAME', class '$SENSOR_CLASS'"
UB_USER="$USERNAME" UB_CLASS="$SENSOR_CLASS" php artisan tinker --execute='
$user = App\Models\User::where("username", getenv("UB_USER"))->first();
if (! $user) { echo "NO_SUCH_USER".PHP_EOL; return; }
$svc = app(Drakelid\UpsBattery\Report\SensorReportService::class);
$settings = Drakelid\UpsBattery\Report\PluginSettings::fromArray(app(LibreNMS\Interfaces\Plugins\PluginManagerInterface::class)->getSettings("ups-battery"));
echo "settings: type=".json_encode($settings->defaultType)." class=".$settings->defaultClass." limit=".$settings->defaultLimit." refresh=".$settings->refreshSeconds."s stale=".$settings->staleMinutes."min lang=".$settings->language.PHP_EOL;
$opts = $svc->options($user, null);
echo "types: ".json_encode($opts["types"]).PHP_EOL;
echo "classes: ".implode(", ", array_map(fn ($c) => $c["value"]." (".$c["count"].")", $opts["classes"])).PHP_EOL;
echo "os: ".count($opts["os"])." groups: ".count($opts["groups"]).PHP_EOL;
$f = Drakelid\UpsBattery\Report\ReportFilters::fromArray(["type" => "", "class" => getenv("UB_CLASS"), "limit" => "10"], []);
$p = $svc->report($user, $f, $settings->thresholds);
$s = Drakelid\UpsBattery\Report\Summary::from($p["summaryRows"], $f->class === "state");
echo "TOTAL ".$p["total"]." SHOWN ".count($p["rows"])." UNIT ".$p["unit"]." SUMMARY ".json_encode($s).PHP_EOL;
foreach ($p["rows"] as $r) { echo "  ".$r->hostname."\t".$r->sensorDescr."\t".$r->valueFormatted."\t".$r->severity->value."\t".$r->deviceUrl."\t".$r->sensorUrl.PHP_EOL; }
echo "graph: ".($p["rows"][0]->graphUrl ?? "-").PHP_EOL;
echo "--- csv ---".PHP_EOL.substr((new Drakelid\UpsBattery\Report\CsvFormatter())->toCsv($p["rows"]), 0, 500).PHP_EOL;
$empty = 0; foreach ($p["rows"] as $r) { if ($r->valueFormatted === "") { $empty++; } }
echo "rows with an empty formatted value: ".$empty." (must be 0)".PHP_EOL;
echo "trend: ".($p["rows"][0]->trendUrl ?? "-").PHP_EOL;
$m = $svc->matrix($user, Drakelid\UpsBattery\Report\MatrixFilters::fromArray(["type" => "", "classes" => "runtime,load,charge", "limit" => "5"], []), $settings->thresholds, $settings->suspectRule);
echo "--- matrix (runtime, load, charge) --- total ".$m["total"].PHP_EOL;
foreach ($m["rows"] as $row) { $cells = []; foreach (["runtime", "load", "charge"] as $c) { $cells[] = $c."=".(isset($row->cells[$c]) ? $row->cells[$c]->valueFormatted."/".$row->cells[$c]->severity->value : "-"); } echo "  ".$row->hostname."\t".implode("\t", $cells)."\tsuspect=".json_encode($row->suspect).PHP_EOL; }
$sus = $svc->matrix($user, Drakelid\UpsBattery\Report\MatrixFilters::fromArray(["type" => "", "classes" => "runtime,load,charge", "suspect" => "1", "limit" => "0"], []), $settings->thresholds, $settings->suspectRule);
echo "suspect batteries (runtime < ".$settings->suspectRule->maxRuntime." min, load <= ".$settings->suspectRule->maxLoad." %, charge >= ".$settings->suspectRule->minCharge." %): ".$sus["total"].PHP_EOL;
foreach ($sus["rows"] as $row) { echo "  ".$row->hostname.PHP_EOL; }
$named = $svc->report($user, Drakelid\UpsBattery\Report\ReportFilters::fromArray(["type" => "", "class" => getenv("UB_CLASS"), "sensor" => "a", "limit" => "10"], []), $settings->thresholds);
echo "sensor name filter (contains a): ".$named["total"]." of ".$p["total"].PHP_EOL;
$first = $p["rows"][0] ?? null;
if ($first) { $dev = App\Models\Device::find($first->deviceId); echo "device card rows for ".$first->hostname.": ".count($svc->forDevice($dev, $user, $settings->thresholds))." next swap: ".json_encode($svc->deviceSwap($dev, $settings)->toArray()).PHP_EOL; }
$ups = $svc->ups($user, Drakelid\UpsBattery\Report\UpsFilters::fromArray(["type" => "", "limit" => "0"], []), $settings);
echo "--- UPS overview --- total ".$ups["total"]." cards ".json_encode($ups["cards"]).PHP_EOL;
foreach (array_slice($ups["rows"], 0, 10) as $u) { echo "  ".$u->hostname."\t".($u->manufacturer ?? "-")." ".($u->model ?? "-")."\t".$u->severity->value."\truntime=".($u->runtime?->valueFormatted ?? "-")."\tbattery=".($u->battery?->valueFormatted ?? "-")."\toutput=".($u->output?->valueFormatted ?? "-")."\tself-test=".($u->selfTest?->valueFormatted ?? "-")."\tswap=".($u->swap->due ?? "-")." (".$u->swap->source.")\tsensors=".count($u->sensors).PHP_EOL; }
echo "SMOKE_DONE".PHP_EOL;
' >/tmp/ups-battery-smoke.log 2>&1
cat /tmp/ups-battery-smoke.log | sed 's/^/    /'
if grep -q 'SMOKE_DONE' /tmp/ups-battery-smoke.log; then
    ok "pipeline ran against the real database"
elif grep -q 'NO_SUCH_USER' /tmp/ups-battery-smoke.log; then
    bad "no LibreNMS user named '$USERNAME'"
else
    bad "smoke test failed, see output above"
fi

step "Weekly report (dry run, nothing is sent)"
if report_out="$(php artisan ups-battery:report --dry-run 2>&1)"; then
    ok "ups-battery:report runs"
    printf '%s\n' "$report_out" | head -n 1 | sed 's/^/         subject: /'
else
    bad "ups-battery:report failed: $(printf '%s\n' "$report_out" | tail -n 5)"
fi

step "Result"
if [ "$failures" -eq 0 ]; then
    echo "All checks passed."
    echo "Last manual step: open Overview > Plugins > UPS Battery in the browser and go through chapter 9 of the specification."
else
    echo "$failures check(s) failed."
fi
exit $((failures > 0 ? 1 : 0))

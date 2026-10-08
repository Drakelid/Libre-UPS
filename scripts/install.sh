#!/usr/bin/env bash
#
# Installs or updates the "UPS Battery" plugin from Packagist as a LibreNMS package plugin.
#
#   sudo bash install.sh [options]        (see --help)
#
# It uses LibreNMS' own plugin installer ("lnms plugin:add"), which adds the package to composer.json and
# composer.plugins.json, so the plugin shows up under Overview > Plugins > Plugin Admin and survives
# LibreNMS updates (daily.sh).
#
#   1. checks the LibreNMS directory, PHP version, write access and package plugin support
#   2. looks up the newest tagged release on Packagist (falls back to dev-main while none exists)
#   3. runs "lnms plugin:add drakelid/librenms-ups-battery [version]"
#   4. enables the plugin (fresh installs only), refreshes route/view caches and verifies the result
#
# Run it as root (it switches to the owner of the LibreNMS directory) or as that user directly.
# Exit codes: 0 success, 1 failure, 2 usage error.

set -uo pipefail

PACKAGE="drakelid/librenms-ups-battery"
PLUGIN_NAME="ups-battery"
PACKAGIST_PAGE="https://packagist.org/packages/$PACKAGE"
PACKAGIST_API="https://repo.packagist.org/p2"
MIN_PHP="8.2"

LIBRENMS_DIR="${LIBRENMS_DIR:-/opt/librenms}"
VERSION=""
UPDATE=0
DRY_RUN=0
ENABLE=1
ASSUME_YES=0
ORIG_ARGS=("$@")

if [ -t 1 ]; then
    C_RED=$'\033[31m'; C_GREEN=$'\033[32m'; C_YELLOW=$'\033[33m'; C_BOLD=$'\033[1m'; C_OFF=$'\033[0m'
else
    C_RED=''; C_GREEN=''; C_YELLOW=''; C_BOLD=''; C_OFF=''
fi

step() { printf '\n%s== %s%s\n' "$C_BOLD" "$1" "$C_OFF"; }
info() { printf '  %s\n' "$1"; }
ok()   { printf '  %s[ OK ]%s %s\n' "$C_GREEN" "$C_OFF" "$1"; }
warn() { printf '  %s[WARN]%s %s\n' "$C_YELLOW" "$C_OFF" "$1"; }
bad()  { printf '  %s[FAIL]%s %s\n' "$C_RED" "$C_OFF" "$1"; }
die()  { printf '%sError:%s %s\n' "$C_RED" "$C_OFF" "$1" >&2; exit 1; }

usage() {
    cat <<EOF
Install or update the UPS Battery plugin ($PACKAGE) in LibreNMS.

Usage: install.sh [options]

Options:
  -d, --dir DIR       LibreNMS directory (default: \$LIBRENMS_DIR or /opt/librenms)
  -v, --version VER   Package version or constraint, e.g. 1.1.0, ^1.1 or dev-main.
                      Default: the latest tagged release, or dev-main while no release exists.
                      Giving a version also updates an existing installation.
  -u, --update        Update the plugin when it is already installed
      --no-enable     Leave the plugin disabled after a fresh install (LibreNMS enables new plugins by default)
  -n, --dry-run       Run the checks and show the commands, change nothing
  -y, --yes           Do not ask for confirmation
  -h, --help          Show this help

Run as root (the script switches to the owner of the LibreNMS directory) or as the LibreNMS user.
The script file must be readable by that user, so do not keep it in /root.

Examples:
  sudo bash install.sh                    # install the latest release
  sudo bash install.sh --update           # update to the latest release
  sudo bash install.sh -v dev-main -y     # follow the main branch, no questions
  sudo bash install.sh -d /srv/librenms -n
EOF
}

usage_error() {
    printf 'install.sh: %s\n\n' "$1" >&2
    usage >&2
    exit 2
}

while [ $# -gt 0 ]; do
    case "$1" in
        -d|--dir)
            [ $# -ge 2 ] || usage_error "$1 needs a value"
            LIBRENMS_DIR="$2"; shift 2 ;;
        --dir=*)
            LIBRENMS_DIR="${1#*=}"; shift ;;
        -v|--version)
            [ $# -ge 2 ] || usage_error "$1 needs a value"
            VERSION="$2"; shift 2 ;;
        --version=*)
            VERSION="${1#*=}"; shift ;;
        -u|--update)  UPDATE=1; shift ;;
        --no-enable)  ENABLE=0; shift ;;
        -n|--dry-run) DRY_RUN=1; shift ;;
        -y|--yes)     ASSUME_YES=1; shift ;;
        -h|--help)    usage; exit 0 ;;
        *)            usage_error "unknown option: $1" ;;
    esac
done

if [ -n "$VERSION" ]; then
    if ! printf '%s' "$VERSION" | grep -Eq '^[A-Za-z0-9._@^~*:+-]+$'; then
        usage_error "invalid version \"$VERSION\""
    fi
    UPDATE=1
fi

# ---- helpers ----------------------------------------------------------------------------------------

run() {
    printf '  $ %s\n' "$*"
    if [ "$DRY_RUN" -eq 1 ]; then
        return 0
    fi
    "$@"
}

# Like run, but hides the command's own output.
run_quiet() {
    printf '  $ %s\n' "$*"
    if [ "$DRY_RUN" -eq 1 ]; then
        return 0
    fi
    "$@" >/dev/null 2>&1
}

http_get() {
    if command -v curl >/dev/null 2>&1; then
        curl -fsSL --max-time 20 "$1"
    elif command -v wget >/dev/null 2>&1; then
        wget -qO- --timeout=20 "$1"
    else
        return 127
    fi
}

confirm() {
    if [ "$ASSUME_YES" -eq 1 ] || [ "$DRY_RUN" -eq 1 ] || [ ! -t 0 ]; then
        return 0
    fi

    local answer
    read -r -p "Continue? [y/N] " answer
    case "$answer" in
        y|Y|yes|YES) return 0 ;;
        *) return 1 ;;
    esac
}

# Picks the best version from a Packagist "p2" response: the highest tagged release ("stable") or a dev branch ("dev").
read -r -d '' PHP_PICK <<'PHP' || true
$data = json_decode($argv[1], true);
$list = is_array($data) ? ($data['packages'][$argv[2]] ?? []) : [];
$mode = $argv[3];
$best = '';
foreach ($list as $entry) {
    $version = (string) ($entry['version'] ?? '');
    if ($mode === 'stable') {
        if (preg_match('/^v?\d+(\.\d+){1,3}$/', $version)
            && ($best === '' || version_compare(ltrim($version, 'v'), ltrim($best, 'v'), '>'))) {
            $best = $version;
        }
    } elseif (strpos($version, 'dev-') === 0) {
        if ($best === '' || $version === 'dev-main' || ($version === 'dev-master' && $best !== 'dev-main')) {
            $best = $version;
        }
    }
}
echo $best;
PHP

# Prints the installed version of the package, e.g. "1.1.0" or "dev-main @ 3f2a9c1".
read -r -d '' PHP_INSTALLED <<'PHP' || true
$file = $argv[1] . '/vendor/composer/installed.json';
if (!is_file($file)) {
    exit;
}
$data = json_decode((string) file_get_contents($file), true);
$packages = is_array($data) ? ($data['packages'] ?? $data) : [];
foreach ($packages as $package) {
    if (($package['name'] ?? '') !== $argv[2]) {
        continue;
    }
    $version = (string) ($package['version'] ?? '');
    $reference = (string) ($package['source']['reference'] ?? ($package['dist']['reference'] ?? ''));
    if (strpos($version, 'dev-') === 0 && $reference !== '') {
        $version .= ' @ ' . substr($reference, 0, 7);
    }
    echo $version;
    break;
}
PHP

installed_version() {
    php -r "$PHP_INSTALLED" "$LIBRENMS_DIR" "$PACKAGE" 2>/dev/null
}

# True when LibreNMS lists the package as a plugin (composer.plugins.json, written by lnms plugin:add).
is_registered() {
    [ -f "$LIBRENMS_DIR/composer.plugins.json" ] || return 1
    php -r '$data = json_decode((string) file_get_contents($argv[1]), true); exit(isset($data["require"][$argv[2]]) ? 0 : 1);' \
        "$LIBRENMS_DIR/composer.plugins.json" "$PACKAGE" 2>/dev/null
}

# Sets RESOLVED_KIND (stable|dev) and RESOLVED. Returns 1 when Packagist cannot be reached, 2 when no version exists.
resolve_version() {
    local json picked

    json="$(http_get "$PACKAGIST_API/$PACKAGE.json")" || return 1
    picked="$(php -r "$PHP_PICK" "$json" "$PACKAGE" stable)"
    if [ -n "$picked" ]; then
        RESOLVED_KIND="stable"; RESOLVED="$picked"
        return 0
    fi

    json="$(http_get "$PACKAGIST_API/$PACKAGE~dev.json" 2>/dev/null)" || json=""
    if [ -n "$json" ]; then
        picked="$(php -r "$PHP_PICK" "$json" "$PACKAGE" dev)"
        if [ -n "$picked" ]; then
            RESOLVED_KIND="dev"; RESOLVED="$picked"
            return 0
        fi
    fi

    return 2
}

# ---- LibreNMS directory and user -----------------------------------------------------------------------

[ -d "$LIBRENMS_DIR" ] || die "LibreNMS directory not found: $LIBRENMS_DIR (use --dir)"
LIBRENMS_DIR="$(cd "$LIBRENMS_DIR" && pwd -P)"
for required in artisan lnms composer.json; do
    [ -e "$LIBRENMS_DIR/$required" ] || die "$LIBRENMS_DIR/$required is missing, this is not a LibreNMS directory (use --dir)"
done
grep -Fq '"librenms/librenms"' "$LIBRENMS_DIR/composer.json" || die "$LIBRENMS_DIR/composer.json is not LibreNMS' composer.json"

if [ "$(id -u)" -eq 0 ]; then
    owner="$(stat -c '%U' "$LIBRENMS_DIR" 2>/dev/null || echo root)"
    [ "$owner" != "root" ] || die "$LIBRENMS_DIR is owned by root. Run this script as the LibreNMS user instead (e.g. sudo -u librenms bash install.sh)"

    SELF="$(readlink -f "${BASH_SOURCE[0]}" 2>/dev/null || echo "${BASH_SOURCE[0]}")"
    [ -f "$SELF" ] || die "Save the script to a file first (it cannot switch user when piped into bash)"

    # composer keeps its cache and config under $HOME, which must belong to the user that runs it
    owner_home="$(getent passwd "$owner" 2>/dev/null | cut -d: -f6)"
    [ -d "$owner_home" ] || owner_home="$HOME"

    printf 'Running as root, continuing as %s\n' "$owner"
    if command -v runuser >/dev/null 2>&1; then
        exec runuser -u "$owner" -- env LIBRENMS_DIR="$LIBRENMS_DIR" HOME="$owner_home" bash "$SELF" ${ORIG_ARGS[@]+"${ORIG_ARGS[@]}"}
    elif command -v sudo >/dev/null 2>&1; then
        exec sudo -u "$owner" env LIBRENMS_DIR="$LIBRENMS_DIR" HOME="$owner_home" bash "$SELF" ${ORIG_ARGS[@]+"${ORIG_ARGS[@]}"}
    fi
    die "Neither runuser nor sudo found. Run the script as $owner"
fi

step "Checking the LibreNMS installation"
ok "LibreNMS found in $LIBRENMS_DIR"

cd "$LIBRENMS_DIR" || die "Cannot enter $LIBRENMS_DIR"
CURRENT_USER="$(id -un)"
LNMS=(php "$LIBRENMS_DIR/lnms")
ARTISAN=(php "$LIBRENMS_DIR/artisan")

command -v php >/dev/null 2>&1 || die "php not found in PATH"
PHP_VERSION="$(php -r 'echo PHP_VERSION;')"
php -r 'exit(version_compare(PHP_VERSION, $argv[1], ">=") ? 0 : 1);' "$MIN_PHP" \
    || die "PHP $MIN_PHP or newer is required (found $PHP_VERSION)"
ok "PHP $PHP_VERSION"

for path in composer.json vendor; do
    [ -w "$LIBRENMS_DIR/$path" ] || die "$LIBRENMS_DIR/$path is not writable by $CURRENT_USER. Run as the LibreNMS user (sudo -u <user> bash install.sh)"
done
if [ -e "$LIBRENMS_DIR/composer.plugins.json" ]; then
    [ -w "$LIBRENMS_DIR/composer.plugins.json" ] || die "$LIBRENMS_DIR/composer.plugins.json is not writable by $CURRENT_USER"
else
    [ -w "$LIBRENMS_DIR" ] || die "$LIBRENMS_DIR is not writable by $CURRENT_USER (composer.plugins.json must be created)"
fi
ok "write access as $CURRENT_USER"

"${LNMS[@]}" list --raw 2>/dev/null | grep -q '^plugin:add' \
    || die "This LibreNMS has no package plugin support (lnms plugin:add is missing). Update LibreNMS first"
ok "package plugin system available"

# ---- current state and version -------------------------------------------------------------------------

step "Looking up the plugin"

INSTALLED_VERSION="$(installed_version)"
REGISTERED=0
if is_registered; then
    REGISTERED=1
fi

if [ "$REGISTERED" -eq 1 ]; then
    ok "already installed: ${INSTALLED_VERSION:-unknown version}"
else
    info "not installed yet"
fi

PLUGIN_ARG=""        # version passed to plugin:add, empty lets composer choose the newest stable release
VERSION_NOTE=""

if [ -n "$VERSION" ]; then
    PLUGIN_ARG="$VERSION"
    VERSION_NOTE="$VERSION (requested)"
elif [ "$REGISTERED" -eq 1 ] && [ "$UPDATE" -eq 0 ]; then
    VERSION_NOTE="unchanged"
else
    RESOLVED_KIND=""; RESOLVED=""
    resolve_version
    case $? in
        0)
            if [ "$RESOLVED_KIND" = "stable" ]; then
                VERSION_NOTE="latest release ($RESOLVED)"
                ok "latest release on Packagist: $RESOLVED"
            else
                PLUGIN_ARG="$RESOLVED"
                VERSION_NOTE="$RESOLVED (no tagged release on Packagist yet)"
                warn "no tagged release on Packagist yet, using $RESOLVED (it follows the main branch)"
                info "Tip: tag a release on GitHub (for example v1.0.0) to get stable, pinned installs."
                if ! command -v git >/dev/null 2>&1 && ! command -v unzip >/dev/null 2>&1; then
                    warn "neither git nor unzip is installed; composer may be unable to download $RESOLVED"
                fi
            fi ;;
        2)
            die "$PACKAGIST_PAGE has no installable version. Push a tag or the main branch and let Packagist update" ;;
        *)
            die "Cannot read $PACKAGIST_API (no network, curl/wget missing, or the package is unknown). Check $PACKAGIST_PAGE, or skip the lookup with --version" ;;
    esac
fi

if [ "$REGISTERED" -eq 1 ] && [ "$UPDATE" -eq 0 ]; then
    ACTION="skip"
elif [ "$REGISTERED" -eq 1 ]; then
    ACTION="update"
else
    ACTION="install"
fi

step "Plan"
info "LibreNMS directory : $LIBRENMS_DIR"
info "Running as         : $CURRENT_USER"
info "Package            : $PACKAGE"
info "Version            : $VERSION_NOTE"
info "Installed          : ${INSTALLED_VERSION:-none}"
case "$ACTION" in
    install)
        if [ "$ENABLE" -eq 1 ]; then
            info "Action             : install and enable"
        else
            info "Action             : install and leave disabled (--no-enable)"
        fi ;;
    update)  info "Action             : update (the enabled/disabled state is kept)" ;;
    skip)    info "Action             : nothing to install (use --update to upgrade), refresh caches and verify" ;;
esac
[ "$DRY_RUN" -eq 1 ] && info "Dry run            : nothing will be changed"
echo

confirm || { echo "Aborted."; exit 1; }

# ---- install -------------------------------------------------------------------------------------------

had_route_cache=0
if compgen -G "$LIBRENMS_DIR/bootstrap/cache/routes-*.php" >/dev/null; then
    had_route_cache=1
fi

if [ "$ACTION" != "skip" ]; then
    step "Installing $PACKAGE"
    if [ -n "$PLUGIN_ARG" ]; then
        run "${LNMS[@]}" plugin:add "$PACKAGE" "$PLUGIN_ARG"
    else
        run "${LNMS[@]}" plugin:add "$PACKAGE"
    fi
    if [ $? -ne 0 ]; then
        bad "plugin:add failed, see the composer output above"
        info "Common causes: no access to packagist.org / github.com, too little memory for composer"
        info "(try: COMPOSER_MEMORY_LIMIT=-1 bash install.sh), or a version that does not exist on Packagist."
        info "composer.json and composer.plugins.json are restored by composer when it fails."
        exit 1
    fi
    [ "$DRY_RUN" -eq 1 ] || ok "package installed"

    # LibreNMS registers a new plugin as enabled the first time it boots, so a fresh install only needs
    # an explicit step when the user asked to leave it disabled.
    if [ "$ACTION" = "install" ]; then
        if [ "$ENABLE" -eq 1 ]; then
            step "Enabling the plugin"
            run "${LNMS[@]}" plugin:enable "$PLUGIN_NAME" || warn "plugin:enable failed, enable it under Overview > Plugins > Plugin Admin"
        else
            step "Leaving the plugin disabled"
            run "${LNMS[@]}" plugin:disable "$PLUGIN_NAME" || warn "plugin:disable failed, disable it under Overview > Plugins > Plugin Admin"
        fi
    fi
fi

step "Refreshing caches"
run_quiet "${ARTISAN[@]}" route:clear || warn "route:clear failed"
run_quiet "${ARTISAN[@]}" view:clear || warn "view:clear failed"
if [ "$had_route_cache" -eq 1 ]; then
    run_quiet "${ARTISAN[@]}" route:cache || warn "route:cache failed (routes are served uncached)"
fi

# ---- verify --------------------------------------------------------------------------------------------

if [ "$DRY_RUN" -eq 1 ]; then
    step "Dry run finished, nothing was changed"
    exit 0
fi

step "Verifying"
failures=0

if is_registered; then
    ok "listed in composer.plugins.json (survives LibreNMS updates)"
else
    bad "not listed in composer.plugins.json"
    failures=$((failures + 1))
fi

NOW_VERSION="$(installed_version)"
if [ -n "$NOW_VERSION" ]; then
    ok "installed version: $NOW_VERSION"
else
    bad "package not found in vendor/composer/installed.json"
    failures=$((failures + 1))
fi

state_out="$("${ARTISAN[@]}" tinker --execute='echo "UB_ACTIVE=".(int) (App\Models\Plugin::where("plugin_name", "'"$PLUGIN_NAME"'")->value("plugin_active") ?? -1).PHP_EOL;' 2>&1)"
active="$(printf '%s\n' "$state_out" | sed -n 's/.*UB_ACTIVE=\(-\{0,1\}[0-9][0-9]*\).*/\1/p' | tail -n 1)"
case "$active" in
    1)
        ok "plugin '$PLUGIN_NAME' is enabled"
        if "${ARTISAN[@]}" route:list --json --path="plugin/$PLUGIN_NAME" 2>/dev/null | grep -Fq "$PLUGIN_NAME.report"; then
            ok "routes registered (plugin/$PLUGIN_NAME/report, data, matrix, options, views)"
        else
            bad "enabled, but the routes are not registered. Try: php artisan route:clear (and reload php-fpm)"
            failures=$((failures + 1))
        fi ;;
    0)
        warn "plugin '$PLUGIN_NAME' is installed but disabled. Enable it under Overview > Plugins > Plugin Admin or with: lnms plugin:enable $PLUGIN_NAME" ;;
    -1)
        bad "LibreNMS does not know the plugin '$PLUGIN_NAME' (service provider not discovered). Try: composer dump-autoload, then re-run"
        failures=$((failures + 1)) ;;
    *)
        warn "could not read the plugin state from the database:"
        printf '%s\n' "$state_out" | tail -n 5 | sed 's/^/         /' ;;
esac

# ---- summary -------------------------------------------------------------------------------------------

step "Result"
if [ "$failures" -gt 0 ]; then
    bad "$failures check(s) failed, see above"
    exit 1
fi

printf '  %sThe UPS Battery plugin is installed.%s\n\n' "$C_GREEN" "$C_OFF"
info "Open it:     Overview > Plugins > UPS Battery   (/plugin/$PLUGIN_NAME/report)"
info "Configure:   Overview > Plugins > Plugin Admin > $PLUGIN_NAME  (default metric, thresholds, language)"
VERIFY="$LIBRENMS_DIR/vendor/$PACKAGE/scripts/verify.sh"
[ -f "$VERIFY" ] && info "Check data:  bash $VERIFY <librenms-username> runtime"
info "Update:      bash install.sh --update"
info "Remove:      php $LIBRENMS_DIR/lnms plugin:remove $PACKAGE"
echo
info "If the menu entry does not appear, reload PHP-FPM (for example: systemctl reload php8.3-fpm) and refresh the page."
exit 0

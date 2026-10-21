#!/usr/bin/env bash

# Exercise the installer with a disposable fixture and stubbed PHP; never run LibreNMS or Composer.
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
TEMP_ROOT="$(cd "${TMPDIR:-/tmp}" && pwd -P)"
FIXTURE="$(mktemp -d "$TEMP_ROOT/ups-battery-install-test.XXXXXXXX")"
cleanup() {
    case "$FIXTURE" in
        "$TEMP_ROOT"/ups-battery-install-test.*) rm -rf -- "$FIXTURE" ;;
        *) printf 'Refusing to remove unexpected fixture path: %s\n' "$FIXTURE" >&2 ;;
    esac
}
trap cleanup EXIT
mkdir -p "$FIXTURE/bin" "$FIXTURE/librenms/vendor/composer"
touch "$FIXTURE/librenms/artisan" "$FIXTURE/librenms/lnms"
printf '%s\n' '{"name":"librenms/librenms"}' >"$FIXTURE/librenms/composer.json"
printf '%s\n' '{"require":{"drakelid/librenms-ups-battery":"1.0.0"}}' >"$FIXTURE/librenms/composer.plugins.json"

cat >"$FIXTURE/bin/id" <<'STUB'
#!/usr/bin/env bash
case "$1" in
    -u) printf '1000\n' ;;
    -un) printf 'installer-test\n' ;;
    *) exit 99 ;;
esac
STUB

cat >"$FIXTURE/bin/php" <<'STUB'
#!/usr/bin/env bash
set -euo pipefail
if [ "$1" = '-r' ]; then
    case "$2" in
        'echo PHP_VERSION;') printf '8.3.0' ;;
        *'version_compare(PHP_VERSION'*) exit 0 ;;
        *'vendor/composer/installed.json'*) printf '1.0.0' ;;
        *'isset($data["require"]'*) exit 0 ;;
        *) printf 'Unexpected PHP code\n' >&2; exit 99 ;;
    esac
elif [[ "$1" == */lnms ]] && [ "$2" = 'list' ]; then
    printf 'plugin:add\n'
elif [[ "$1" == */artisan ]]; then
    case "$2" in
        route:clear|view:clear) exit 0 ;;
        route:list) printf '[{"name":"ups-battery.report"}]\n' ;;
        tinker)
            case "$INSTALL_TEST_STATE" in
                failed) printf 'Database connection failed\n' >&2; exit 1 ;;
                malformed) printf 'Unexpected application output\n' ;;
                failed-after-marker) printf 'UB_ACTIVE=1\n'; exit 1 ;;
                *) printf 'UB_ACTIVE=%s\n' "$INSTALL_TEST_STATE" ;;
            esac ;;
        *) printf 'Unexpected artisan command: %s\n' "$2" >&2; exit 99 ;;
    esac
else
    printf 'Unexpected PHP invocation (installation is forbidden)\n' >&2
    exit 99
fi
STUB
chmod +x "$FIXTURE/bin/id" "$FIXTURE/bin/php"
for command in curl wget composer; do
    printf '#!/usr/bin/env bash\nprintf "Network/package operations are forbidden in this test\\n" >&2\nexit 99\n' >"$FIXTURE/bin/$command"
    chmod +x "$FIXTURE/bin/$command"
done

check_state() {
    local state="$1" expected="$2" status=0
    PATH="$FIXTURE/bin:$PATH" INSTALL_TEST_STATE="$state" \
        bash "$ROOT/scripts/install.sh" --dir "$FIXTURE/librenms" --yes >"$FIXTURE/output" 2>&1 || status=$?
    if [ "$status" -ne "$expected" ]; then
        cat "$FIXTURE/output" >&2
        printf 'FAIL: state %s exited %s, expected %s\n' "$state" "$status" "$expected" >&2
        exit 1
    fi
    if [ "$expected" -ne 0 ] && grep -Fq 'The UPS Battery plugin is installed.' "$FIXTURE/output"; then
        printf 'FAIL: state %s falsely announced installation success\n' "$state" >&2
        exit 1
    fi
    printf 'PASS: installer state %s exits %s\n' "$state" "$status"
}

check_state failed 1
check_state malformed 1
check_state failed-after-marker 1
check_state -1 1
check_state 1 0
check_state 0 0

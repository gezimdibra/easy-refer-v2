#!/usr/bin/env bash
#
# Scripted .env fetch check (deploy plan Section 3.3 item 1; spec
# Sections 11.4 and 13 name this check a deliverable that runs
# after every deploy; added for Feedbacker Sprint 1 sign-off
# condition 2). It requests the sensitive paths below against a
# deployed mount and proves none of them returns file content:
# the application tree lives above the web root, so these paths
# may only ever answer 401 (the staging gate), 403, 404, or a
# generic framework error page carrying no file values.
#
# The battery is exactly the deploy plan's list. The plan's one
# free choice, the traversal variant, is the encoded form
# /%2e%2e/.env: a literal /../ is normalized away by many
# clients and proxies before it reaches the application, while
# the encoded form tests whether the server decodes the dots
# and then traverses. curl is told --path-as-is so neither form
# is normalized on our side either.
#
# Verdicts: a path FAILS when the status is 200 and the body
# carries content evidence (a marker from the real file), or
# when the mount does not answer at all. A 200 with a generic
# error page and no marker is a PASS and is noted as such. For
# .env.example the plan says any 200 is a FAIL; a served copy
# carries the same KEY= lines as .env, so the marker rule below
# is what enforces that. Response bodies are never printed;
# only the matched marker name is reported.
#
# Usage: check-env-fetch.sh <mount-base-url>
#        check-env-fetch.sh --self-test
#
#   <mount-base-url>   for example https://easy-refer.com/new
#
# Environment:
#   GATE_USER and GATE_PASSWORD   staging gate credentials, or
#   GATE_CREDENTIALS              the same as one "user:pass".
#                                 Credentials travel in a
#                                 temporary curl config file,
#                                 never on the curl command
#                                 line, and are never printed.
#                                 (Keep double quotes and
#                                 backslashes out of the gate
#                                 password; the config file
#                                 cannot escape them.)
#   EXPECT_GATE=1                 first assert the mount
#                                 answers 401 without
#                                 credentials (the gate is on),
#                                 then run the battery.
#
# Exit codes: 0 every path passed, 1 any failure (including a
# failed gate assertion), 2 usage error.
set -euo pipefail

# path|kind pairs. The kind selects the content markers below.
BATTERY=(
    '/.env|env'
    '/.env.example|env'
    '/composer.json|composer'
    '/composer.lock|composer'
    '/artisan|artisan'
    '/.git/config|gitconfig'
    '/vendor/autoload.php|vendor'
    '/storage/logs/laravel.log|log'
    '/../.env|env'
    '/%2e%2e/.env|env'
)

WORKDIR=""
CURLCFG=""

cleanup() {
    if [ -n "$WORKDIR" ] && [ -d "$WORKDIR" ]; then
        rm -rf "$WORKDIR"
    fi
}
trap cleanup EXIT

usage() {
    echo "Usage: check-env-fetch.sh <mount-base-url>" >&2
    echo "       check-env-fetch.sh --self-test" >&2
}

# fetch <url> <bodyfile> [nocreds]
# Prints the HTTP status on stdout. The return code is curl's:
# nonzero means a transport error and the mount did not answer.
fetch() {
    local url="$1" body="$2" mode="${3:-creds}"
    local -a args=(-s -o "$body" -w '%{http_code}'
        --max-time 20 --connect-timeout 10 --path-as-is)
    if [ "$mode" != "nocreds" ] && [ -n "$CURLCFG" ]; then
        args+=(-K "$CURLCFG")
    fi
    curl "${args[@]}" "$url"
}

# find_marker <kind> <bodyfile>
# Echoes the name of the first content marker found in the body
# and returns 0; returns 1 when the body carries no marker.
find_marker() {
    local kind="$1" body="$2"
    local -a markers=()
    case "$kind" in
        env) markers=('APP_KEY=' 'DB_PASSWORD=' 'APP_ENV=') ;;
        composer) markers=('"require"') ;;
        gitconfig) markers=('[core]') ;;
        artisan) markers=('bootstrap/app.php') ;;
        vendor) markers=('ComposerAutoloaderInit') ;;
        log)
            if grep -aEq '\[[0-9]{4}-[0-9]{2}-[0-9]{2}[ T][0-9]{2}:[0-9]{2}:[0-9]{2}' "$body"; then
                echo 'timestamped log line'
                return 0
            fi
            return 1
            ;;
        *) return 1 ;;
    esac
    local m
    for m in "${markers[@]}"; do
        if grep -aFq -- "$m" "$body"; then
            echo "$m"
            return 0
        fi
    done
    return 1
}

HAVE_CREDS=0

# run_path <base> <path> <kind>
# Prints one verdict line; returns 0 on PASS, 1 on FAIL.
run_path() {
    local base="$1" path="$2" kind="$3"
    local body="$WORKDIR/body"
    : >"$body"
    local rc=0 status
    status="$(fetch "$base$path" "$body")" || rc=$?
    if [ "$rc" -ne 0 ]; then
        echo "$path status=000 FAIL (connection error: the mount did not answer)"
        return 1
    fi
    local marker=""
    if marker="$(find_marker "$kind" "$body")"; then
        :
    else
        marker=""
    fi
    if [ "$status" = "200" ] && [ -n "$marker" ]; then
        echo "$path status=200 FAIL (file content served, marker: $marker)"
        return 1
    fi
    if [ "$status" = "401" ] && [ "$HAVE_CREDS" -eq 1 ]; then
        echo "$path status=401 FAIL (gate rejected the supplied credentials; path not verified)"
        return 1
    fi
    if [ "$status" = "200" ]; then
        echo "$path status=200 PASS (200 with no content evidence)"
        return 0
    fi
    echo "$path status=$status PASS"
    return 0
}

main() {
    if [ "$#" -ne 1 ]; then
        usage
        return 2
    fi
    case "$1" in
        http://* | https://*) ;;
        *)
            usage
            return 2
            ;;
    esac
    local base="${1%/}"

    local creds=""
    if [ -n "${GATE_CREDENTIALS:-}" ]; then
        creds="$GATE_CREDENTIALS"
    elif [ -n "${GATE_USER:-}" ] || [ -n "${GATE_PASSWORD:-}" ]; then
        if [ -z "${GATE_USER:-}" ] || [ -z "${GATE_PASSWORD:-}" ]; then
            echo "GATE_USER and GATE_PASSWORD must be set together." >&2
            return 2
        fi
        creds="${GATE_USER}:${GATE_PASSWORD}"
    fi

    WORKDIR="$(mktemp -d)"
    if [ -n "$creds" ]; then
        HAVE_CREDS=1
        CURLCFG="$WORKDIR/curlrc"
        printf 'user = "%s"\n' "$creds" >"$CURLCFG"
        chmod 600 "$CURLCFG"
    fi

    if [ "${EXPECT_GATE:-}" = "1" ]; then
        local grc=0 gstatus
        gstatus="$(fetch "$base/.env" "$WORKDIR/body" nocreds)" || grc=$?
        if [ "$grc" -ne 0 ]; then
            echo "GATE CHECK /.env status=000 FAIL (connection error: the mount did not answer)"
            return 1
        fi
        if [ "$gstatus" != "401" ]; then
            echo "GATE CHECK /.env status=$gstatus FAIL (expected 401 without credentials; the gate is not on)"
            return 1
        fi
        echo "GATE CHECK /.env status=401 PASS (gate answers 401 without credentials)"
    fi

    local failures=0 entry path kind
    for entry in "${BATTERY[@]}"; do
        path="${entry%%|*}"
        kind="${entry##*|}"
        if ! run_path "$base" "$path" "$kind"; then
            failures=$((failures + 1))
        fi
    done

    if [ "$failures" -ne 0 ]; then
        echo "env fetch check FAILED: $failures of ${#BATTERY[@]} paths failed."
        return 1
    fi
    echo "env fetch check passed: no sensitive path returned file content."
    return 0
}

# wait_up <url>: poll until the test server answers anything.
wait_up() {
    local i
    for i in $(seq 1 50); do
        if curl -s -o /dev/null --max-time 2 "$1"; then
            return 0
        fi
        sleep 0.2
    done
    return 1
}

# self_test: prove the verdict logic without a real server.
# Two fixture trees under one temp dir, each in its own parent
# so the traversal paths cannot leak from one tree into the
# other: a planted tree whose .env (and a copy one level up,
# where the traversal paths land) carries marker lines that
# must be caught, and a clean tree that must pass outright.
self_test() {
    if ! command -v php >/dev/null 2>&1; then
        echo "self-test: php is not available, cannot start the built-in server" >&2
        return 1
    fi
    local tmp bad_root clean_root
    tmp="$(mktemp -d)"
    bad_root="$tmp/bad"
    clean_root="$tmp/clean"
    mkdir -p "$bad_root/site" "$clean_root/site"
    printf 'APP_ENV=production\nAPP_KEY=base64:PLANTEDSELFTEST\nDB_PASSWORD=planted\n' >"$bad_root/site/.env"
    cp "$bad_root/site/.env" "$bad_root/.env"
    printf '<h1>Coming soon</h1>\n' >"$clean_root/site/index.html"

    local bad_port=$(((RANDOM % 20000) + 20000))
    local clean_port=$((bad_port + 1))
    php -S "127.0.0.1:$bad_port" -t "$bad_root/site" >"$tmp/bad-server.log" 2>&1 &
    local bad_pid=$!
    php -S "127.0.0.1:$clean_port" -t "$clean_root/site" >"$tmp/clean-server.log" 2>&1 &
    local clean_pid=$!

    local self fails=0 out rc
    self="$(cd "$(dirname "$0")" && pwd)/$(basename "$0")"

    if ! wait_up "http://127.0.0.1:$bad_port/"; then
        echo "self-test FAIL: planted-tree server did not start"
        fails=1
    fi
    if ! wait_up "http://127.0.0.1:$clean_port/"; then
        echo "self-test FAIL: clean-tree server did not start"
        fails=1
    fi

    if [ "$fails" -eq 0 ]; then
        echo "self-test: battery against the planted tree (must exit 1):"
        out="$(bash "$self" "http://127.0.0.1:$bad_port" 2>&1)" && rc=0 || rc=$?
        echo "$out"
        if [ "$rc" -ne 1 ]; then
            echo "self-test FAIL: planted tree must exit 1, got $rc"
            fails=1
        fi
        if ! printf '%s' "$out" | grep -q 'APP_KEY='; then
            echo "self-test FAIL: the planted .env marker APP_KEY= was not reported"
            fails=1
        fi

        echo "self-test: battery against the clean tree (must exit 0):"
        out="$(bash "$self" "http://127.0.0.1:$clean_port" 2>&1)" && rc=0 || rc=$?
        echo "$out"
        if [ "$rc" -ne 0 ]; then
            echo "self-test FAIL: clean tree must exit 0, got $rc"
            fails=1
        fi

        out="$(bash "$self" 2>&1)" && rc=0 || rc=$?
        if [ "$rc" -ne 2 ]; then
            echo "self-test FAIL: a missing argument must exit 2, got $rc"
            fails=1
        fi
    fi

    kill "$bad_pid" "$clean_pid" 2>/dev/null || true
    wait "$bad_pid" 2>/dev/null || true
    wait "$clean_pid" 2>/dev/null || true
    rm -rf "$tmp"

    if [ "$fails" -ne 0 ]; then
        echo "self-test FAILED"
        return 1
    fi
    echo "self-test passed: planted .env caught (exit 1), clean tree passed (exit 0), usage error exits 2."
    return 0
}

if [ "${1:-}" = "--self-test" ]; then
    if self_test; then
        exit 0
    fi
    exit 1
fi

main "$@"

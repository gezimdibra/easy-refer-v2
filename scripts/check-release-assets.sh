#!/usr/bin/env bash
# check-release-assets.sh - release preflight for the compiled
# front-end assets (deploy defect 3, release 02).
#
# A fresh clone of the repository has no public/build/ directory:
# the Vite output is gitignored and is produced by an explicit
# build step at release assembly time (deploy-plan.md Section
# 2.2). Shipping a release without it makes every page that
# renders a layout fail with "Vite manifest not found", which is
# exactly what staging release 02 hit before the deploy agent
# built the assets on the server by hand.
#
# This check fails a release assembly that lacks the assets:
#
#   scripts/check-release-assets.sh [ROOT]
#
# ROOT defaults to the repository root (the parent of this
# script's directory). Point it at the assembled release
# directory (or at the clone before the flip) at deploy time.
# Every file the Vite manifest references (entry files plus
# their css and asset lists) must exist and be non-empty.
#
# Exit codes: 0 = assets complete, 1 = missing or incomplete,
# 2 = usage or tooling error. `--self-test` runs the checker
# against synthetic fixtures and exits 0 only if a complete
# tree passes and both broken shapes fail.

set -u

fail_count=0

note_fail() {
    echo "FAIL: $1"
    fail_count=$((fail_count + 1))
}

check_tree() {
    local root="$1"
    local build_dir="$root/public/build"
    local manifest="$build_dir/manifest.json"

    if [ ! -f "$manifest" ]; then
        note_fail "$root: public/build/manifest.json is missing (front-end assets were not built; run npm ci and npm run build before assembling the release)"
        return
    fi

    if [ ! -s "$manifest" ]; then
        note_fail "$root: public/build/manifest.json is empty"
        return
    fi

    if ! command -v php >/dev/null 2>&1; then
        echo "ERROR: php is required to parse the Vite manifest" >&2
        exit 2
    fi

    # Print one line per manifest-referenced file that is missing
    # or empty, and a final count line "entries:<n>". All manifest
    # shapes Vite emits are covered: "file" on every entry, plus
    # optional "css" and "assets" file lists.
    local php_out
    php_out=$(php -r '
        $build = $argv[1];
        $data = json_decode((string) file_get_contents($build . "/manifest.json"), true);
        if (!is_array($data) || $data === []) {
            echo "entries:0\n";
            exit(0);
        }
        $refs = [];
        foreach ($data as $entry) {
            if (!is_array($entry)) { continue; }
            if (isset($entry["file"])) { $refs[] = $entry["file"]; }
            foreach (["css", "assets"] as $key) {
                if (isset($entry[$key]) && is_array($entry[$key])) {
                    foreach ($entry[$key] as $f) { $refs[] = $f; }
                }
            }
        }
        $refs = array_unique($refs);
        foreach ($refs as $rel) {
            $path = $build . "/" . $rel;
            if (!is_file($path) || filesize($path) === 0) {
                echo "missing:" . $rel . "\n";
            }
        }
        echo "entries:" . count($data) . " refs:" . count($refs) . "\n";
    ' "$build_dir")

    local missing
    missing=$(printf '%s\n' "$php_out" | grep -c '^missing:' || true)
    if [ "$missing" -gt 0 ]; then
        printf '%s\n' "$php_out" | grep '^missing:' | while IFS= read -r line; do
            echo "FAIL: $root: public/build/${line#missing:} is referenced by the manifest but missing or empty"
        done
        fail_count=$((fail_count + missing))
    fi

    if printf '%s\n' "$php_out" | grep -q '^entries:0'; then
        note_fail "$root: public/build/manifest.json has no entries"
    fi
}

self_test() {
    local tmp
    tmp=$(mktemp -d)
    trap "rm -rf -- '$tmp'" EXIT

    # Fixture 1: complete tree, must pass.
    mkdir -p "$tmp/good/public/build/assets"
    printf 'x' > "$tmp/good/public/build/assets/app-abc123.js"
    printf 'x' > "$tmp/good/public/build/assets/app-abc123.css"
    cat > "$tmp/good/public/build/manifest.json" <<'JSON'
{"resources/js/app.js":{"file":"assets/app-abc123.js","css":["assets/app-abc123.css"],"isEntry":true}}
JSON

    # Fixture 2: no build output at all (the fresh-clone shape).
    mkdir -p "$tmp/bare/public"

    # Fixture 3: manifest present, one referenced asset missing.
    mkdir -p "$tmp/holey/public/build/assets"
    printf 'x' > "$tmp/holey/public/build/assets/app-abc123.js"
    cp "$tmp/good/public/build/manifest.json" "$tmp/holey/public/build/manifest.json"

    local outcomes=""
    fail_count=0; check_tree "$tmp/good";  [ "$fail_count" -eq 0 ] && outcomes="${outcomes}good:pass " || outcomes="${outcomes}good:FAIL "
    fail_count=0; check_tree "$tmp/bare";  [ "$fail_count" -gt 0 ] && outcomes="${outcomes}bare:fail " || outcomes="${outcomes}bare:FAIL "
    fail_count=0; check_tree "$tmp/holey"; [ "$fail_count" -gt 0 ] && outcomes="${outcomes}holey:fail" || outcomes="${outcomes}holey:FAIL"

    echo "self-test outcomes: $outcomes"
    case "$outcomes" in
        "good:pass bare:fail holey:fail") echo "SELF-TEST PASS"; return 0 ;;
        *) echo "SELF-TEST FAIL"; return 1 ;;
    esac
}

if [ "${1:-}" = "--self-test" ]; then
    self_test
    exit $?
fi

root="${1:-$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)}"

if [ ! -d "$root" ]; then
    echo "ERROR: root directory not found: $root" >&2
    exit 2
fi

check_tree "$root"

if [ "$fail_count" -gt 0 ]; then
    echo "RELEASE ASSETS: FAIL ($fail_count problem(s) under $root)"
    exit 1
fi

echo "RELEASE ASSETS: PASS ($root/public/build complete)"
exit 0

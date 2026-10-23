#!/usr/bin/env bash
# Base-path tripwire (spec Section 11.4).
#
# Fails if any application code, template, config, route, or public asset
# contains a hardcoded mount path, the production domain, or a hand-written
# root-absolute URL. APP_URL in .env is the only place the mount may live.
# Scanned trees: app/ config/ routes/ resources/ public/ bootstrap/
# Whitelisted: this script, documentation (README, *.md), .env files.

set -u
cd "$(dirname "$0")/.."

SCAN_DIRS="app config routes resources public bootstrap"
PATTERNS=(
    'easy-refer\.com'
    '/new/'
    '"/new"'
    "'/new'"
    'href="/'
    "href='/"
    'src="/'
    "src='/"
    'action="/'
    'url(/'
)

fail=0
for pattern in "${PATTERNS[@]}"; do
    hits=$(grep -rnI --exclude='*.md' --exclude='check-base-path.sh' -e "$pattern" $SCAN_DIRS 2>/dev/null || true)
    if [ -n "$hits" ]; then
        echo "TRIPWIRE HIT for pattern: $pattern"
        echo "$hits"
        fail=1
    fi
done

if [ "$fail" -ne 0 ]; then
    echo "BASE-PATH TRIPWIRE: FAILED"
    exit 1
fi

echo "BASE-PATH TRIPWIRE: OK (no hardcoded mount paths, domains, or absolute app URLs)"
exit 0

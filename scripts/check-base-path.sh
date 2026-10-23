#!/usr/bin/env bash
#
# Base-path tripwire (spec Section 11.4, REV-M1), rescoped by the
# Phase 1 fix wave (finding FB-04). The rule it enforces is about
# URL contexts only:
#
#   1. No code, config, or view may contain the production host in
#      URL form ("://easy-refer.com"): absolute production URLs are
#      hand-written mounts waiting to break.
#   2. No URL context may name a mount path: the mount prefix must
#      come from configuration (APP_URL and the framework's URL
#      generation), never from a literal such as "/new/auth/login"
#      in an href, src, action, url(), or quoted path.
#   3. Root-relative URL literals (href="/...", src="/...",
#      action="/...") are forbidden for the same reason: they assume
#      the root mount.
#
# Explicitly permitted (spec Sections 1.3 and 11.4): the bare domain
# and email addresses in display copy and contact strings (for
# example support@easy-refer.com in a footer), which are not URLs
# and name no mount.
#
# Usage: check-base-path.sh [project-root]   (default: the repo root
# containing this script). The argument exists so the gate test can
# run the gate against fixture trees.
set -euo pipefail

ROOT="${1:-$(cd "$(dirname "$0")/.." && pwd)}"

DIRS=(app bootstrap config database resources routes public)
EXISTING=()
for d in "${DIRS[@]}"; do
    [ -d "$ROOT/$d" ] && EXISTING+=("$ROOT/$d")
done

# The scan set is code and templates: PHP, Blade, and CSS under the
# application directories. Markdown and documentation are not code.
mapfile -t FILES < <(find "${EXISTING[@]}" -type f \( -name '*.php' -o -name '*.css' \) 2>/dev/null)

PATTERNS=(
    '://easy-refer\.com'
    '["'"'"'=(]/new/'
    'url\(/new'
    'href="/'
    'src="/'
    'action="/'
)

FAIL=0
for pattern in "${PATTERNS[@]}"; do
    if hits=$(grep -REn -- "$pattern" "${FILES[@]}" 2>/dev/null); then
        echo "Base-path tripwire FAILED for pattern: $pattern"
        echo "$hits"
        FAIL=1
    fi
done

if [ "$FAIL" -ne 0 ]; then
    exit 1
fi

echo "Base-path tripwire passed (REV-M1 scope: URL contexts only)."

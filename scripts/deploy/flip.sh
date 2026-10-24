#!/bin/bash
# flip.sh — cPanel Git VC deployment task 4, the FINAL task (see
# .cpanel.yml). Flips the staging `current` symlink to the release that
# release-assemble.sh assembled and gate1.php verified.
#
# This script is the second lock on the flip, independent of cPanel's
# task-abort semantics:
#   - It refuses unless shared/deploy-state/READY exists and names the
#     same REL and HEAD as shared/deploy-state/LATEST. gate1.php writes
#     READY only after every probe path returns its designed 200, so a
#     failed (or never-run) Gate 1 makes the flip unreachable even if a
#     failed task somehow did not stop the task list.
#   - It refuses if `current` no longer points at the PREV release the
#     assembly recorded (something else flipped in between).
# The swap itself is the proven classic pattern (flip08a.sh): build the
# new symlink beside the live one and rename over it, so `current` never
# points nowhere. Rollback stays manual and unchanged: point `current`
# back at the previous release directory.
set -euo pipefail

STAGE=/home/easyrefe/er2-staging
STATE=$STAGE/shared/deploy-state

if [ ! -f "$STATE/LATEST" ]; then echo "ABORT: no assembly state ($STATE/LATEST)"; exit 1; fi
if [ ! -f "$STATE/READY" ]; then echo "ABORT: no Gate 1 READY marker; the probe did not pass for this assembly"; exit 1; fi

# shellcheck disable=SC1091
. "$STATE/LATEST"
REL_LATEST=$REL; PREV_LATEST=$PREV; HEAD_LATEST=$HEAD
# shellcheck disable=SC1091
. "$STATE/READY"
if [ "$REL" != "$REL_LATEST" ] || [ "$HEAD" != "$HEAD_LATEST" ]; then
  echo "ABORT: READY marker (REL=$REL HEAD=$HEAD) does not match assembly (REL=$REL_LATEST HEAD=$HEAD_LATEST)"
  exit 1
fi

D=$STAGE/releases/$REL
if [ ! -d "$D" ]; then echo "ABORT: release dir missing: $D"; exit 1; fi

cd "$STAGE"
BEFORE=$(readlink current || true)
if [ "$BEFORE" != "releases/$PREV_LATEST" ]; then
  echo "ABORT: current drifted since assembly (expected releases/$PREV_LATEST, found $BEFORE)"
  exit 1
fi

echo "== FLIP $BEFORE -> releases/$REL =="
ln -s "releases/$REL" current.tmp-cpanel
mv -Tf current.tmp-cpanel current
echo "AFTER=$(readlink current)"
echo "FLIP_UTC=$(date -u +%Y-%m-%dT%H:%M:%SZ) FLIP_EDT=$(TZ=America/New_York date +%Y-%m-%dT%H:%M:%S%Z)"
echo "ENV_LINK=$(readlink "$D/.env" 2>&1)"
echo "STORAGE_LINK=$(readlink "$D/storage" 2>&1)"
echo "PUBLIC_STORAGE_LINK=$(readlink "$D/public/storage" 2>&1)"
# Consume the marker: a stale READY must never authorize a later flip.
rm -f "$STATE/READY"
echo "FLIP_DONE"

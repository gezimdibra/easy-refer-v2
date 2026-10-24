#!/bin/bash
# release-assemble.sh — cPanel Git VC deployment task 2 (see .cpanel.yml).
#
# Assembles a NEW staging release directory from the clone's checked-out
# HEAD, mirroring the proven classic/fast-path recipe (deploy07 s2-s5,
# deploy08a assemble08a.sh): allowlist copy, warm vendor, Vite build,
# binary assets carried from the previous release, shared/ links, cache
# builds, release manifest, assets check.
#
# Contract:
#   - Runs with cwd = the clone root (/home/easyrefe/repos/easy-refer-v2),
#     AFTER the panel's "Update from Remote" moved the clone to the
#     pushed HEAD. It never fetches, resets, stashes, or cleans.
#   - The clone tree must be CLEAN (cPanel's own deployment requirement);
#     a dirty tree aborts before anything is written. Build outputs are
#     gitignored (node_modules/, public/build/), so building in the clone
#     keeps the tree clean.
#   - Release directories are content-immutable once assembled (protocol
#     v2 (e)): this script never reuses or overwrites a release dir; a
#     name collision gets a numeric suffix.
#   - It NEVER flips the symlink. On success it records the assembly in
#     shared/deploy-state/LATEST (REL, PREV, HEAD, log baseline) for the
#     Gate 1 and flip tasks; the READY marker is gate1.php's job alone.
#   - No migrations run here, by design (see .cpanel.yml header).
set -euo pipefail

PHP=/usr/local/bin/ea-php82
COMPOSER=/usr/local/bin/composer
CLONE=/home/easyrefe/repos/easy-refer-v2
STAGE=/home/easyrefe/er2-staging
STATE=$STAGE/shared/deploy-state
T0=$(date +%s)
mark(){ echo "T_MARK $1 elapsed=$(( $(date +%s) - T0 ))s"; }

cd "$CLONE"

echo "== PRECHECK =="
# Clean tree: the panel requires it to offer deployment at all; verify on
# the server rather than trusting the UI state. Any line of output aborts.
DIRTY=$(git status --porcelain)
if [ -n "$DIRTY" ]; then
  echo "ABORT: clone working tree is not clean:"
  echo "$DIRTY" | head -20
  exit 1
fi
HEAD=$(git rev-parse HEAD)
SHORT=$(git rev-parse --short=7 HEAD)
echo "CLONE_HEAD=$HEAD"

PREV=$(readlink "$STAGE/current" || true)
case "$PREV" in
  releases/*) PREVREL=${PREV#releases/} ;;
  *) echo "ABORT: current symlink unreadable or unexpected: '$PREV'"; exit 1 ;;
esac
echo "PREV_RELEASE=$PREVREL"
if [ ! -d "$STAGE/releases/$PREVREL" ]; then
  echo "ABORT: previous release dir missing: $STAGE/releases/$PREVREL"
  exit 1
fi

# Release id: date + the commit it assembles, so the manifest, the state
# file, and the deployed object all name the same thing. Suffix on
# collision (same-day redeploy of a new HEAD, or a repeat deployment).
REL="v2-$(date +%Y-%m-%d)-g$SHORT"
D=$STAGE/releases/$REL
n=2
while [ -e "$D" ]; do
  REL="v2-$(date +%Y-%m-%d)-g$SHORT-$n"
  D=$STAGE/releases/$REL
  n=$((n+1))
done
echo "RELEASE=$REL"
mark precheck_done

LOGF=$STAGE/shared/storage/logs/laravel.log
echo "LOG_BASELINE=$(stat -c '%s %y' "$LOGF" 2>/dev/null || echo MISSING)"

echo "== ASSEMBLE $REL =="
mkdir -p "$D"
# Allowlist copy: exactly the paths the classic recipe ships. vendor/,
# node_modules/, public/build/ and the binary assets are NOT in this list;
# they are produced or carried below.
for p in app bootstrap config database public resources routes artisan composer.json composer.lock .env.example; do
  cp -a "$CLONE/$p" "$D/"
  echo "copied $p"
done
mark copy_done

# Vendor, warm (protocol v2 (e)): a retained template keyed by the
# composer.lock hash, hardlink-copied (cp -al) so the copy is near-free.
# A lock change falls back to a full composer install in the release dir
# and seeds the template for the next release.
LOCKHASH=$(sha256sum "$CLONE/composer.lock" | cut -d' ' -f1)
VT=$STAGE/shared/vendor-templates/$LOCKHASH
if [ -d "$VT/vendor" ]; then
  cp -al "$VT/vendor" "$D/vendor"
  echo "VENDOR_REUSED=YES template=$LOCKHASH files=$(find "$D/vendor" -type f | wc -l)"
else
  echo "VENDOR_REUSED=NO (no template for lock hash; full composer install)"
  $PHP $COMPOSER install --no-dev --optimize-autoloader --no-interaction --working-dir="$D" 2>&1 | tail -5
  mkdir -p "$VT"
  cp -al "$D/vendor" "$VT/vendor"
  echo "VENDOR_TEMPLATE_SEEDED=$LOCKHASH"
fi
mark vendor_done

# Front-end assets: build in the clone (outputs are gitignored, the tree
# stays clean). npm ci is skipped when package-lock.json still matches
# the stamp left by the last install (protocol v2 (e) warm node_modules).
export PATH=/opt/alt/alt-nodejs20/root/usr/bin:$PATH
echo "NODE=$(node --version)"
PKG_HASH=$(sha256sum "$CLONE/package-lock.json" | cut -d' ' -f1)
STAMP=$CLONE/node_modules/.er2-lock-sha
if [ -f "$STAMP" ] && [ "$(cat "$STAMP")" = "$PKG_HASH" ] && [ -d "$CLONE/node_modules" ]; then
  echo "NPM_CI_SKIPPED=YES (package-lock unchanged)"
else
  npm ci --no-audit --no-fund
  echo "$PKG_HASH" > "$STAMP"
  echo "NPM_CI_SKIPPED=NO"
fi
npm run build
rm -rf "$D/public/build"
cp -a "$CLONE/public/build" "$D/public/build"
echo "BUILD_MANIFEST_BYTES=$(stat -c %s "$D/public/build/manifest.json")"
mark assets_done

# Binary assets ride outside the snapshot pushes (public/favicon.ico and
# public/images/ are standing exclusions), so the clone does not carry
# them: take them from the previous release, the proven 08a pattern.
cp -a "$STAGE/releases/$PREVREL/public/images" "$D/public/images"
cp -a "$STAGE/releases/$PREVREL/public/favicon.ico" "$D/public/favicon.ico"
cp -a "$STAGE/releases/$PREVREL/public/apple-touch-icon.png" "$D/public/apple-touch-icon.png"
echo "IMAGES_DIR_FILES=$(find "$D/public/images" -type f | wc -l)"
echo "FAVICON_ICO=$(test -f "$D/public/favicon.ico" && echo yes || echo NO)"
echo "APPLE_TOUCH=$(test -f "$D/public/apple-touch-icon.png" && echo yes || echo NO)"
# The release 06 nesting defect shape: assets extracted one level deep.
if [ -e "$D/public/public" ]; then echo "ABORT: nesting defect ($D/public/public exists)"; exit 1; fi
echo "NESTING=OK"

# Shared links (the release's .env and storage live in shared/, per the
# staging layout), then the cache sequence from the proven recipe.
cd "$D"
ln -s ../../shared/.env .env
ln -s ../../shared/storage storage
$PHP artisan storage:link
rm -f "$D"/bootstrap/cache/*.php
mark caches_start
$PHP artisan optimize:clear
$PHP artisan config:cache
$PHP artisan route:cache
$PHP artisan view:cache
$PHP artisan event:cache
mark caches_done

# Release manifest into shared/manifests/ (same shape as the per-release
# gen-manifest scripts): every file with size + sha256, symlinks skipped.
$PHP -r '
$base = $argv[1]; $rel = $argv[2]; $head = $argv[3];
$out = "/home/easyrefe/er2-staging/shared/manifests/manifest-$rel.json";
$files = [];
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
foreach ($it as $f) {
    if ($f->isLink() || !$f->isFile()) { continue; }
    $path = $f->getPathname();
    $files[] = ["path" => substr($path, strlen($base) + 1), "size" => $f->getSize(), "sha256" => hash_file("sha256", $path)];
}
usort($files, fn ($a, $b) => strcmp($a["path"], $b["path"]));
file_put_contents($out, json_encode(["build_id" => $rel, "source_commit" => $head, "files" => $files], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
echo "MANIFEST files=".count($files)." bytes=".filesize($out)."\n";
' "$D" "$REL" "$HEAD"

# Assets preflight (deploy-protocol Gate 1): every file the Vite manifest
# references must exist and be non-empty in the assembled release.
bash "$CLONE/scripts/check-release-assets.sh" "$D"
echo "ASSETS_EXIT=$?"
mark manifest_done

# Record the assembly for the Gate 1 and flip tasks. LATEST is data, not
# a pass: gate1.php alone writes READY, and flip.sh requires the pair to
# agree (see .cpanel.yml, failure design).
mkdir -p "$STATE"
{
  echo "REL=$REL"
  echo "PREV=$PREVREL"
  echo "HEAD=$HEAD"
  echo "ASSEMBLED_UTC=$(date -u +%Y-%m-%dT%H:%M:%SZ)"
} > "$STATE/LATEST"
rm -f "$STATE/READY"
echo "STATE_RECORDED=$STATE/LATEST"
echo "ASSEMBLE_DONE"
mark assemble_done

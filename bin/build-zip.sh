#!/usr/bin/env bash
#
# Build a WP.org-ready zip of the plugin (AR-17, TB-18).
#
# Output: semantic-posts-<version>.zip in the repo root, byte-identical
# across runs (deterministic ordering, no timestamps in the zip).
#
# Includes:
#   semantic-posts.php, uninstall.php, readme.txt, LICENSE
#   src/, templates/, assets/, languages/, includes/
#   vendor/ (no-dev, optimized)
#
# Excludes:
#   tests/, docker/, tools/, .github/, docs/, _bmad-output/
#   composer.* (lockfile + json), phpcs.xml*, phpunit.xml*, .editorconfig,
#   .wp-env.json, .git*, *.md (except readme.txt)
#
# Usage: bin/build-zip.sh
set -euo pipefail

cd "$(dirname "$0")/.."
ROOT="$(pwd)"

# Extract version from main plugin file (single source of truth).
VERSION="$(grep -E '^\s*\* Version:' semantic-posts.php | head -1 | awk '{print $3}')"
if [ -z "$VERSION" ]; then
  echo "::error::could not read Version from semantic-posts.php" >&2
  exit 1
fi
echo "==> Building semantic-posts $VERSION"

# Idempotent staging directory.
STAGE="$(mktemp -d)"
trap 'rm -rf "$STAGE"' EXIT

PLUGIN_DIR="$STAGE/semantic-posts"
mkdir -p "$PLUGIN_DIR"

echo "==> Copying source..."
# Top-level files.
for f in semantic-posts.php uninstall.php readme.txt LICENSE; do
  if [ -e "$f" ]; then
    cp "$f" "$PLUGIN_DIR/"
  fi
done

# Code + assets + i18n.
for d in src templates assets languages includes; do
  if [ -d "$d" ]; then
    cp -R "$d" "$PLUGIN_DIR/"
  fi
done

echo "==> Installing production composer dependencies..."
cp composer.json "$PLUGIN_DIR/"
if [ -f composer.lock ]; then
  cp composer.lock "$PLUGIN_DIR/"
fi
(cd "$PLUGIN_DIR" && composer install --no-dev --optimize-autoloader --no-interaction --quiet)
rm -f "$PLUGIN_DIR/composer.json" "$PLUGIN_DIR/composer.lock"

# Prune vendor cruft that has no business in a production plugin.
find "$PLUGIN_DIR/vendor" -type d \( -name tests -o -name test -o -name docs -o -name examples \) -prune -exec rm -rf {} +
find "$PLUGIN_DIR/vendor" -type f \( -name "*.md" -o -name "*.txt" -o -name ".gitignore" -o -name ".gitattributes" -o -name "phpunit.xml*" -o -name ".editorconfig" \) -delete

echo "==> Stripping local artifacts..."
find "$PLUGIN_DIR" -name ".DS_Store" -delete
find "$PLUGIN_DIR" -name "*.swp" -delete

# Normalise timestamps so the zip is byte-identical across runs.
TS_EPOCH="$(git log -1 --format=%ct 2>/dev/null || echo 1700000000)"
find "$PLUGIN_DIR" -exec touch -d "@$TS_EPOCH" {} +

ZIP_PATH="$ROOT/semantic-posts-$VERSION.zip"
rm -f "$ZIP_PATH"

echo "==> Zipping..."
if command -v zip >/dev/null 2>&1; then
  (cd "$STAGE" && find semantic-posts -print0 | LC_ALL=C sort -z | xargs -0 zip -X -q "$ZIP_PATH")
else
  # Portable fallback: Python's zipfile with deterministic ordering + fixed timestamp.
  python3 - "$STAGE" "$ZIP_PATH" "$TS_EPOCH" <<'PY'
import os, sys, zipfile
stage, out, ts = sys.argv[1], sys.argv[2], int(sys.argv[3])
ts_tuple = (1980, 1, 1, 0, 0, 0)
files = []
for root, dirs, fnames in os.walk(stage):
    dirs.sort()
    for f in sorted(fnames):
        full = os.path.join(root, f)
        rel  = os.path.relpath(full, stage)
        files.append((full, rel))
with zipfile.ZipFile(out, 'w', zipfile.ZIP_DEFLATED) as z:
    for full, rel in files:
        zi = zipfile.ZipInfo(rel, date_time=ts_tuple)
        zi.external_attr = (0o644 & 0xFFFF) << 16
        with open(full, 'rb') as fp:
            z.writestr(zi, fp.read(), zipfile.ZIP_DEFLATED)
PY
fi

SHA="$(sha256sum "$ZIP_PATH" | awk '{print $1}')"
SIZE="$(stat -c '%s' "$ZIP_PATH")"

echo "==> Done: $ZIP_PATH"
echo "    sha256: $SHA"
echo "    size:   ${SIZE} bytes"

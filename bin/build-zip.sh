#!/usr/bin/env bash
#
# Build a distributable zip of the FOSSE plugin at build/fosse.zip.
#
# The zip unpacks to a single fosse/ directory containing fosse.php, src/,
# and a production vendor/ (no dev dependencies, no composer artifacts),
# ready to drop into wp-content/plugins/.
#
# Usage:
#   bin/build-zip.sh
#   composer build-zip

set -euo pipefail

SCRIPT_DIR=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)
ROOT=$(cd "$SCRIPT_DIR/.." && pwd)

BUILD_DIR="$ROOT/build"
STAGE_DIR="$BUILD_DIR/fosse"
ZIP_PATH="$BUILD_DIR/fosse.zip"

rm -rf "$BUILD_DIR"
mkdir -p "$STAGE_DIR"

cp "$ROOT/fosse.php" "$STAGE_DIR/"
cp -R "$ROOT/src" "$STAGE_DIR/"
cp "$ROOT/composer.json" "$STAGE_DIR/"

# --no-scripts avoids WorDBless's post-install drop-in hook (dev-only).
(
	cd "$STAGE_DIR"
	composer install \
		--no-dev \
		--no-scripts \
		--optimize-autoloader \
		--no-interaction \
		--no-progress
)

rm -f "$STAGE_DIR/composer.json" "$STAGE_DIR/composer.lock"

(
	cd "$BUILD_DIR"
	zip -rq fosse.zip fosse
)

echo "Built: $ZIP_PATH"

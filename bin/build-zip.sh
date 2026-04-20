#!/usr/bin/env bash
#
# Build a distributable zip of the FOSSE plugin at build/fosse.zip.
#
# The zip unpacks to a single fosse/ directory containing every tracked
# file not marked `export-ignore` in .gitattributes, plus a production
# vendor/ (no dev dependencies, no composer.json/lock), ready to drop
# into wp-content/plugins/.
#
# Environment:
#   FOSSE_VERSION  If set, overrides the `Version:` header in the staged
#                  fosse.php (e.g. "0.1.0", "0.0.1-dev+abc1234"). Leave
#                  unset to keep whatever is committed in fosse.php.
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

# Stage via `git archive`: the tree at HEAD, minus anything marked
# `export-ignore` in .gitattributes. --worktree-attributes lets local
# edits to .gitattributes take effect without a commit, which matches
# how everything else in this script reads from the worktree.
git -C "$ROOT" archive --format=tar --worktree-attributes HEAD | tar -x -C "$STAGE_DIR"

if [ -n "${FOSSE_VERSION:-}" ]; then
	# sed -i with a backup suffix is portable across GNU and BSD sed.
	sed -i.bak -E "s|^([[:space:]]*\*[[:space:]]*Version:[[:space:]]*).+$|\1${FOSSE_VERSION}|" "$STAGE_DIR/fosse.php"
	rm -f "$STAGE_DIR/fosse.php.bak"
fi

# --no-scripts skips WorDBless's post-install drop-in hook, which is
# a dev-only concern and would fail here anyway (WorDBless is a dev dep).
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

# Sanity-check the built zip so a silently-broken bundle never reaches a release.
contents=$(unzip -Z1 "$ZIP_PATH")
for required in \
	fosse/fosse.php \
	fosse/vendor/autoload.php \
	fosse/bundled/activitypub/activitypub.php \
	fosse/bundled/atmosphere/atmosphere.php; do
	if ! grep -qx "$required" <<<"$contents"; then
		echo "error: $required missing from $ZIP_PATH" >&2
		exit 1
	fi
done

echo "Built: $ZIP_PATH"

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
#   FOSSE_VERSION  If set, overrides BOTH the `Version:` plugin header
#                  AND the `define( 'FOSSE_VERSION', '...' )` runtime
#                  constant in the staged fosse.php (e.g. "0.1.0",
#                  "0.1.0-dev+abc1234"). The two must stay aligned —
#                  the constant is what runtime code reads, the header
#                  is what the WP Plugins screen shows. Leave unset to
#                  keep whatever is committed in fosse.php.
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
	# Rewrite both the plugin header `Version:` line and the
	# `define( 'FOSSE_VERSION', '...' )` constant so the WP Plugins
	# screen and runtime constant agree.
	sed -i.bak -E \
		-e "s|^([[:space:]]*\*[[:space:]]*Version:[[:space:]]*).+$|\1${FOSSE_VERSION}|" \
		-e "s|(define\([[:space:]]*'FOSSE_VERSION',[[:space:]]*')[^']*(')|\1${FOSSE_VERSION}\2|" \
		"$STAGE_DIR/fosse.php"
	rm -f "$STAGE_DIR/fosse.php.bak"
fi

# Fail fast if composer.lock drifted from composer.json. Otherwise
# `composer install` just warns and installs from the stale lock — the
# warning is easy to miss in CI logs and a mismatched bundle can ship
# without anyone noticing.
(
	cd "$STAGE_DIR"
	composer validate --no-check-all --no-check-publish
)

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
	fosse/vendor/autoload_packages.php \
	fosse/bundled/activitypub/activitypub.php \
	fosse/bundled/atmosphere/atmosphere.php; do
	if ! grep -qx "$required" <<<"$contents"; then
		echo "error: $required missing from $ZIP_PATH" >&2
		exit 1
	fi
done

echo "Built: $ZIP_PATH"

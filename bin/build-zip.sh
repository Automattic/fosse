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
#   FOSSE_VERSION    If set, stamps BOTH the `Version:` plugin header
#                    AND the `define( 'FOSSE_VERSION', '...' )` runtime
#                    constant in the staged fosse.php (e.g. "0.1.0",
#                    "0.1.0-dev+abc1234"). The two must stay aligned —
#                    the constant is what runtime code reads, the header
#                    is what the WP Plugins screen shows.
#   FOSSE_BUILD_DEV  If "1" and FOSSE_VERSION is unset, derive a dev
#                    version "<base>-dev+<sha7>" from the committed
#                    `Version:` header in fosse.php plus the short HEAD
#                    SHA (GITHUB_SHA in CI, otherwise `git rev-parse`),
#                    then stamp via the same path as FOSSE_VERSION.
#   Neither set:     Leave the staged fosse.php as committed.
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

# Derive a dev-flavored FOSSE_VERSION when the caller asked for one
# (FOSSE_BUILD_DEV=1) and did not pass an explicit FOSSE_VERSION.
# The base comes from the committed `Version:` header so future bumps
# don't need a parallel edit anywhere.
if [ -z "${FOSSE_VERSION:-}" ] && [ "${FOSSE_BUILD_DEV:-}" = "1" ]; then
	base=$(awk '/^[[:space:]]*\*[[:space:]]*Version:/ { print $NF; exit }' "$ROOT/fosse.php")
	if [ -z "$base" ]; then
		echo "error: could not extract Version: header from $ROOT/fosse.php" >&2
		exit 1
	fi
	sha7="${GITHUB_SHA:-}"
	sha7="${sha7:0:7}"
	if [ -z "$sha7" ]; then
		sha7=$(git -C "$ROOT" rev-parse --short=7 HEAD 2>/dev/null || echo "unknown")
	fi
	FOSSE_VERSION="${base}-dev+${sha7}"
	echo "Derived dev version: $FOSSE_VERSION"
fi

if [ -n "${FOSSE_VERSION:-}" ]; then
	# Reject any value that isn't a plain plugin-version literal before
	# it touches the staged fosse.php. The value gets stamped into a
	# PHP single-quoted string, so a quote or backslash in it would
	# either terminate the literal (turning the rest of the tag into
	# executable PHP in the release zip) or smuggle in an escape that
	# the WP Plugins screen renders weirdly. Tags come from
	# github.event.release.tag_name in CI — git allows tags like
	# `v1');die();#` — so this is the right gate.
	if [[ ! "$FOSSE_VERSION" =~ ^[A-Za-z0-9][A-Za-z0-9._+-]*$ ]]; then
		echo "error: FOSSE_VERSION='${FOSSE_VERSION}' is not a safe plugin-version literal (allowed: [A-Za-z0-9._+-], must start with alphanumeric, single line)" >&2
		exit 1
	fi

	# Escape characters special to sed's replacement text so a tag
	# containing \, &, or the | delimiter can't corrupt (or break) the
	# substitution. The replacement only ever interpolates the version,
	# so escaping the RHS is sufficient.
	version_escaped=$(printf '%s' "$FOSSE_VERSION" | sed -e 's/[\\&|]/\\&/g')

	# sed -i with a backup suffix is portable across GNU and BSD sed.
	# Rewrite both the plugin header `Version:` line and the
	# `define( 'FOSSE_VERSION', '...' )` constant so the WP Plugins
	# screen and runtime constant agree.
	sed -i.bak -E \
		-e "s|^([[:space:]]*\*[[:space:]]*Version:[[:space:]]*).+$|\1${version_escaped}|" \
		-e "s|(define\([[:space:]]*'FOSSE_VERSION',[[:space:]]*')[^']*(')|\1${version_escaped}\2|" \
		"$STAGE_DIR/fosse.php"
	rm -f "$STAGE_DIR/fosse.php.bak"

	# Belt-and-braces: assert the sed actually rewrote both spots.
	# Catches silent no-ops if fosse.php is ever reformatted (e.g.
	# `define()` split across lines, double-quoted FOSSE_VERSION).
	header_value=$(awk '/^[[:space:]]*\*[[:space:]]*Version:/ { print $NF; exit }' "$STAGE_DIR/fosse.php")
	if [ "$header_value" != "$FOSSE_VERSION" ]; then
		echo "error: Version: header in staged fosse.php is '${header_value}', expected '${FOSSE_VERSION}'" >&2
		exit 1
	fi
	if ! grep -qF "'FOSSE_VERSION', '${FOSSE_VERSION}'" "$STAGE_DIR/fosse.php"; then
		echo "error: FOSSE_VERSION constant in staged fosse.php did not stamp to '${FOSSE_VERSION}'" >&2
		exit 1
	fi
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

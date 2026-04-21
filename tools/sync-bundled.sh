#!/usr/bin/env bash
#
# Sync bundled copies of wordpress-activitypub and wordpress-atmosphere
# into FOSSE's bundled/ directory.
#
# Usage:
#   ./tools/sync-bundled.sh
#
# Environment variables (with defaults):
#   FOSSE_AP_SOURCE    Path to wordpress-activitypub checkout
#                      (default: ~/code/wordpress-activitypub)
#   FOSSE_ATMO_SOURCE  Path to wordpress-atmosphere checkout
#                      (default: ~/code/wordpress-atmosphere)
#
# This is a short-term bootstrap: we vendor release-build copies of both
# plugins so FOSSE ships with Mastodon + Bluesky federation working out of
# the box. Excludes follow each upstream plugin's .gitattributes
# (export-ignore patterns). Atmosphere's production vendor/ is built and
# rsync'd separately because it must be present at plugin load time.

set -euo pipefail

SCRIPT_DIR="$( cd "$( dirname "${BASH_SOURCE[0]}" )" &> /dev/null && pwd )"
REPO_ROOT="$( cd "$SCRIPT_DIR/.." &> /dev/null && pwd )"
EXCLUDES="$SCRIPT_DIR/bundled-excludes.txt"

AP_SOURCE="${FOSSE_AP_SOURCE:-$HOME/code/wordpress-activitypub}"
ATMO_SOURCE="${FOSSE_ATMO_SOURCE:-$HOME/code/wordpress-atmosphere}"

AP_DEST="$REPO_ROOT/bundled/activitypub"
ATMO_DEST="$REPO_ROOT/bundled/atmosphere"

log() { printf '\033[1;34m[sync-bundled]\033[0m %s\n' "$*"; }
die() { printf '\033[1;31m[sync-bundled]\033[0m %s\n' "$*" >&2; exit 1; }

[[ -f "$EXCLUDES" ]] || die "exclude list not found: $EXCLUDES"
[[ -d "$AP_SOURCE" ]] || die "FOSSE_AP_SOURCE does not exist: $AP_SOURCE"
[[ -f "$AP_SOURCE/activitypub.php" ]] || die "$AP_SOURCE does not look like wordpress-activitypub (no activitypub.php)"
[[ -d "$ATMO_SOURCE" ]] || die "FOSSE_ATMO_SOURCE does not exist: $ATMO_SOURCE"
[[ -f "$ATMO_SOURCE/atmosphere.php" ]] || die "$ATMO_SOURCE does not look like wordpress-atmosphere (no atmosphere.php)"

log "Vendoring ActivityPub from $AP_SOURCE → $AP_DEST"
mkdir -p "$AP_DEST"
rsync -a --delete --exclude-from="$EXCLUDES" "$AP_SOURCE/" "$AP_DEST/"

log "Building Atmosphere production vendor/ in $ATMO_SOURCE"
( cd "$ATMO_SOURCE" && composer install --no-dev --optimize-autoloader --no-interaction --no-progress )

log "Vendoring Atmosphere from $ATMO_SOURCE → $ATMO_DEST (excluding vendor/)"
mkdir -p "$ATMO_DEST"
rsync -a --delete --exclude-from="$EXCLUDES" "$ATMO_SOURCE/" "$ATMO_DEST/"

log "Copying Atmosphere production vendor/"
rsync -a --delete "$ATMO_SOURCE/vendor/" "$ATMO_DEST/vendor/"

AP_SHA="$( git -C "$AP_SOURCE" rev-parse --short HEAD 2>/dev/null || echo 'unknown' )"
ATMO_SHA="$( git -C "$ATMO_SOURCE" rev-parse --short HEAD 2>/dev/null || echo 'unknown' )"

log "Done."
log "  ActivityPub upstream SHA: $AP_SHA"
log "  Atmosphere upstream SHA:  $ATMO_SHA"

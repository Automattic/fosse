# Deactivation Lifecycle - Implementation Notes

Records deviations from `sdd/deactivation-lifecycle/spec.md` and
`sdd/deactivation-lifecycle/plan.md` as they happened during implementation.

## Spec list refreshed to match shipped FOSSE state

When implementation started, three FOSSE-owned options and one user meta key
had shipped since the spec was written and were not yet in the uninstall
cleanup list:

- `fosse_onboarding_destination` — added by the destination-first onboarding
  wizard SDD.
- `fosse_canonical_options_migrated` — added by canonical-upstream-options as
  the migration completion flag.
- `_fosse_wizard_started_emitted` (user meta) — added by the wizard metrics
  call sites for per-user emission dedup.

All three were appended to both `spec.md`'s deletion list and Data Ownership
table, and to `Lifecycle::FOSSE_OWNED_OPTIONS` / `FOSSE_OWNED_USER_META` and
`uninstall.php`'s procedural fallback.

The metrics options the spec listed (`fosse_metrics_consent`,
`fosse_metrics_last_observed_at`, `fosse_metrics_first_observed_at`,
`fosse_metrics_funnel`) do not yet exist on trunk — the metrics SDD is
phasing and those keys are forward-looking. They remain in the cleanup list
since `delete_option()` on a missing key is a no-op and this future-proofs
the next metrics phase.

`_fosse_metrics_ap_dispatch_state` post meta is intentionally **not** part of
v1 cleanup. It is per-post telemetry state that scales with N posts (a
different cost class than option deletes) and is harmless once its readers
stop loading. Re-visit if a future SDD adds a network-wide retention
requirement.

## Deactivation notice replaced by a pre-deactivation Plugins-screen row

The original spec proposed a post-deactivation `admin_notices` confirmation
("FOSSE deactivated. Federation will continue via the standalone
ActivityPub/Atmosphere plugin.") backed by a transient set in
`register_deactivation_hook`. That design cannot be implemented from FOSSE
alone:

- During the deactivation request, the FOSSE callback runs but the response
  ends in `wp_safe_redirect`, so no HTML — including notices — is rendered.
- On the next request, FOSSE is no longer in `active_plugins`, so no FOSSE
  code (including the notice class) loads to read the transient.

The realized design renders a small descriptive row **beneath the FOSSE
plugin row on the Plugins screen** via `after_plugin_row_<FOSSE basename>`.
The row is conditional on at least one standalone backend
(`activitypub/activitypub.php` or `atmosphere/atmosphere.php`) being active
and on the viewer having the `activate_plugins` capability. Singular and
plural ("ActivityPub plugin" / "Atmosphere plugin" / "ActivityPub and
Atmosphere plugins") are picked from the active set.

This trades "confirmation after the click" for "advance warning before the
click", but it is the only variant FOSSE can render reliably while
deactivated. Decision recorded in `spec.md` Decision 5 and the lifecycle
matrix; the test class name (`Standalone_Handoff_NoticeTest`) is preserved
for continuity with the plan.

The `register_deactivation_hook` callback and the
`fosse_deactivation_handoff_pending` transient were therefore not added.
`Lifecycle` still recognizes the transient name in its delete list so a
defensively-set value from a future iteration would be cleaned up.

## Wildcard transient cleanup uses two passes

`Lifecycle::delete_prefixed_transients()` runs both an autoloaded-options
walk routed through `delete_transient()` (which invalidates object-cache
layers) and a raw `LIKE` `DELETE FROM wp_options` (which catches the
non-autoloaded production case). The two-pass design also makes the cleanup
observable under the WorDBless dbless engine, whose `Db_Less_Wpdb::query()`
short-circuits raw SQL.

The matching test (`test_uninstall_clears_wildcard_oauth_return_transients`)
asserts behavior via `get_transient()` rather than direct DB inspection, for
the same dbless reason.

### Persistent object cache limitation

When `wp_using_ext_object_cache()` is true (any persistent cache drop-in like
Redis or Memcached), WordPress short-circuits the options table for transient
storage entirely. `set_transient()` writes to the cache only, `get_transient()`
reads from the cache only, and the `_transient_*` / `_transient_timeout_*`
rows the two-pass cleanup looks for never exist in the options table. Both
passes are no-ops on those sites for cache-only OAuth return transients.

Mitigation is bounded: per-user `fosse_bluesky_oauth_return_<id>` transients
have a short TTL (set at write time in `Bluesky_Provider`), so untouched
cache-only values expire on their own within the OAuth round-trip window.
Sites with a persistent cache will see those transients age out rather than
get explicitly invalidated on FOSSE uninstall — acceptable for v1 because the
stored value is OAuth return-context (not credentials), and WordPress has no
prefix-scoped cache-group flush API to do better without nuking the entire
`transient` cache group.

If this becomes a retention concern, the right next step is enumerating users
via `get_users( [ 'fields' => 'ID' ] )` and calling `delete_transient()` per
user — expensive on large sites, and out of scope for v1.

## Multisite handoff row scope

`Standalone_Handoff_Notice::active_plugins()` merges per-site `active_plugins`
with multisite's `active_sitewide_plugins` (keyed by plugin path), matching
the dual source that WordPress core's `is_plugin_active()` consults. Without
this merge, a network-activated standalone backend would be invisible to the
handoff row even though FOSSE deactivation would still leave federation
running.

The hook callback (`Standalone_Handoff_Notice::render()`) bails on Network
Admin (`is_network_admin()`). The Plugins list there represents network-wide
state, but accurately answering "if FOSSE is network-deactivated, what
federation continues?" requires walking every site in the network — the same
multisite scope deferred to [DOTCOM-17177](https://linear.app/a8c/issue/DOTCOM-17177).
On a per-site Plugins screen the merge above is sufficient, which covers the
common case (FOSSE per-site-active with standalone backends either per-site-
or network-active).

## Defensive `wp_load_alloptions()` guard

The `alloptions` filter has no return-type enforcement. A misbehaving third-
party filter that returns `null`, `false`, or any non-array would TypeError
under PHP 8 when piped into `array_keys()`, aborting uninstall mid-flight
(pass 2's SQL DELETE never runs, and on most hosts the FOSSE-owned options
are also already gone — partial state). Both `Lifecycle::delete_prefixed_transients()`
and the procedural mirror in `uninstall.php` now `is_array()`-guard the
result and degrade to an empty iteration; the raw SQL pass still runs and
finishes the cleanup.

## Dynamic colspan on the handoff row

The handoff row's `<td colspan="...">` is computed from the current screen's
column count via `get_column_headers( get_current_screen() )` rather than
hardcoded to `4`. The Plugins screen renders with a variable number of
columns (the auto-updates column toggles via the `plugins_auto_update_enabled`
filter, and third-party plugins can add columns through `manage_plugins_columns`).
A hardcoded value would leave the handoff row narrower or wider than the
surrounding table on any non-standard configuration. The fallback to `4`
covers contexts where `get_current_screen()` is unavailable.

## Drift detection between Lifecycle and uninstall.php

`uninstall.php`'s procedural fallback runs in exactly the case where the
autoloader has failed and the `Lifecycle` class cannot be loaded — by design
it cannot reference the canonical constants. To prevent silent drift between
the canonical list in `Lifecycle::FOSSE_OWNED_*` and the literal arrays in
`uninstall.php`, `tests/php/Uninstall_DriftTest.php` parses `uninstall.php`
source and asserts the literals match the canonical constants. Adding a new
FOSSE-owned key to `Lifecycle` without updating the fallback is now a hard
test failure at PR time.

## E2E coverage scope

The e2e blueprint provisions only FOSSE (bundled AP/Atmo load internally, no
separately-active standalone). The Plugins-screen handoff row therefore stays
silent in e2e, and the new specs cover the no-fatal Plugins screen and the
no-handoff-row case. A "standalone active" e2e would require a blueprint that
installs a second AP/Atmo plugin entry on disk; intentionally out of scope.

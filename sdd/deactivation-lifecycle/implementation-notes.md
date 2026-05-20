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

## E2E coverage scope

The e2e blueprint provisions only FOSSE (bundled AP/Atmo load internally, no
separately-active standalone). The Plugins-screen handoff row therefore stays
silent in e2e, and the new specs cover the no-fatal Plugins screen and the
no-handoff-row case. A "standalone active" e2e would require a blueprint that
installs a second AP/Atmo plugin entry on disk; intentionally out of scope.

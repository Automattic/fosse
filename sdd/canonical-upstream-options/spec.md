# Spec: Canonical Upstream Options

## Problem

The 2026-05-06 deep audit (`audits/2026-05-06-fosse-plugin-audit-report.md`, findings P2: object-type desync and P2: long-form override) flagged two related defects:

1. FOSSE stored its own `fosse_object_type` option that overrode `activitypub_object_type` via filter. A power user setting `activitypub_object_type=note` directly through ActivityPub's settings UI would cause AP to publish `Note` while Atmosphere's transform path still treated the post as long-form (because Atmosphere's `atmosphere_is_short_form_post` filter was only forced when the FOSSE-side option was set). Cross-network shape disagreement on the same post.
2. FOSSE stored its own `fosse_long_form_strategy` option that unconditionally overrode `atmosphere_long_form_composition`. Atmosphere's native settings UI still rendered, took user input, and wrote to its own option — but FOSSE silently ignored that value and used its hidden default. The visible control was a dead stub.

Both problems share a root cause: FOSSE kept a parallel option per axis and projected it onto upstream filters. Whenever a user touched the upstream-owned UI directly, FOSSE's projector silently overrode their choice (long-form) or failed to mirror it (object-type), creating a class of "I changed it but nothing happened" bugs.

## Decision

Drop the FOSSE-side options and write the canonical upstream options directly. Both `activitypub_object_type` and `atmosphere_long_form_composition` are already registered settings with sanitize callbacks and admin UIs; FOSSE consuming them eliminates the desync class entirely.

This collides with the existing "Upstream contribution policy" worked examples in `AGENTS.md`, which described the projector + parallel-option pattern as the right shape. The 2026-05-06 audit shows that pattern produces stale UIs; the policy text is updated alongside the code change.

### What stays

The cross-network short-form coordination: when AP is set to `note`, Atmosphere should also short-form. The mechanism changes — `Object_Type::filter_atmosphere` now reads `activitypub_object_type` instead of `fosse_object_type` — but the bridge itself remains, because Atmosphere has no equivalent native option.

### What goes

- `fosse_object_type` option (existing sites: migrated to `activitypub_object_type` on first admin load, then deleted).
- `fosse_long_form_strategy` option (migrated to `atmosphere_long_form_composition`, then deleted).
- `Object_Type::filter_ap` callback (AP reads its own option directly; FOSSE registering a no-op projector would only re-create the desync the canonicalization eliminated).
- `Long_Form_Strategy` class, file, and tests (Atmosphere reads its own option directly).

### Migration

A one-time `init` priority 5 migrator (`Canonical_Options_Migrator`) gated on a `fosse_canonical_options_migrated` flag option. On first run after the upgrade lands, it:

1. If `fosse_object_type === 'note'` AND `activitypub_object_type` is unset, sets `activitypub_object_type=note`. If the canonical option is already set, preserves it — the canonical value is what the user can see and edit in ActivityPub's settings UI, so trusting the legacy FOSSE option to overwrite it would silently change the publishing shape away from what the visible UI claims. The legacy value pre-canonicalization may have been an implicit default rather than an explicit user choice; respecting the canonical option keeps the user's most recent explicit choice authoritative. Deletes `fosse_object_type` regardless. Other stored legacy values were pass-throughs and need no migration.
2. If `fosse_long_form_strategy` is set AND `atmosphere_long_form_composition` is unset, copies the legacy value to the canonical option (with coercion per `resolve_legacy_long_form_strategy()` — empty / unknown / non-string values coerce to `'teaser-thread'`, `'document-card'` maps to `'link-card'`, recognized strategies pass through). If both are set, preserves the canonical value (same UI-trust rationale as object-type). Deletes `fosse_long_form_strategy` regardless.
3. If neither legacy option is set AND `atmosphere_long_form_composition` is also unset, seeds the canonical option with `'teaser-thread'` so fresh installs preserve FOSSE's preferred default (per `sdd/long-form-bluesky-strategy/`). Atmosphere's own default is `'link-card'`; without this seeding, a fresh FOSSE install would silently shift behavior on day 1.
4. Sets the flag option so subsequent loads short-circuit.

#### Why preserve canonical over legacy on conflict

The deleted projectors silently overrode the canonical option from the FOSSE option; the visible Atmosphere/AP settings UI was effectively decorative. After canonicalization, the visible UI is the source of truth. If we treated legacy as authoritative on conflict, a user who clicked "link-card" in Atmosphere's UI (which the deleted projector ignored) would migrate to a teaser thread post-deploy with no visible cause, and the legacy evidence would be deleted. That's a worse outcome than respecting whatever the user can see right now. Coercion rules for the legacy-only path still preserve the deleted projector's effective behavior; the conflict path picks UI fidelity over historical effective behavior.

The trade-off: a site whose canonical option is stale (e.g. an Atmosphere default the user never explicitly chose) still gets the canonical value preserved, even though the legacy value was the one driving production publishes. We accept this because: (a) the conflict population is small (sites that ran both FOSSE and the bundled Atmosphere settings UI before this PR), (b) the canonical value is recoverable through the same UI the user can see, and (c) flagging the migration unflagged-and-pending until the user resolves it (the alternative codex suggested) adds significant UX scope for a one-time issue.

Operators that want a record of which sites tripped the conflict path can hook the `fosse_canonical_migration_conflict` action — fired once during migration with `$key`, `$legacy`, and `$existing` arguments. The action is silent when the legacy and canonical values match (no real disagreement to report).

The `'document-card'` mapping to `'link-card'` (instead of `'teaser-thread'`) preserves effective behavior because the deleted projector passed `'document-card'` through to Atmosphere, which doesn't recognize it and falls back to `'link-card'` — so a `'document-card'` site was already publishing single link cards, not multi-post threads. (The forward-compat slot itself is for the Atmosphere v2 renderer; once Atmosphere accepts `'document-card'`, this mapping can be revisited.)

#### Why `init` priority 5 (not `admin_init`)

`admin_init` was the original instinct because it avoids frontend writes. But the deleted projectors ran on every request — including REST, cron, XML-RPC, CLI, and frontend publishes — so removing them and waiting for an admin page load before the migration runs would create a window where existing `fosse_object_type=note` and the previous `'teaser-thread'` default are silently ignored. A site whose first post-deploy publish lands via cron or REST would federate with the wrong shape. Once sent to the networks, that's not a transient display issue.

Hooking on `init` priority 5 closes the window: the migration completes before the bridge filter (priority 10) and before any post-publish path queries the canonical option. The flag gate keeps the cost to a single cached option-read per request after the first run, and the migration itself is idempotent so concurrent requests on the first hit converge to the same state.

#### Why register from `plugins_loaded` (not from `init`)

`Canonical_Options_Migrator::register()` calls `add_action( 'init', ..., 5 )`. If we registered it from inside an `init`-default-priority callback (matching `fosse.php`'s pattern for `Object_Type::register()` and `Post_Types::register()`), priority 5 of the active `init` iteration has already fired by the time we land — WordPress doesn't rewind. The action would only run on a *second* `init` call, which never comes in normal request flow. The migration would silently never execute, the legacy options would never be deleted, and existing `fosse_object_type=note` sites would publish AP as the upstream default while Atmosphere's bridge fallback still forced short-form.

Registering from `plugins_loaded` (which fires before `init`) lands the priority-5 hook before the iteration starts. Per the existing pattern, the registration is wrapped in a `class_exists` guard so a bare clone without `vendor/` degrades cleanly.

#### Belt-and-suspenders fallback

`Object_Type::filter_atmosphere` falls back to the legacy `fosse_object_type` value when the migration flag is unset. The migrator runs at priority 5 so this branch should be unreachable in practice — it covers the autoloader edge case where `Object_Type` loaded but `Canonical_Options_Migrator` did not, and protects against any future re-ordering that might delay the migrator past the bridge.

The long-form path needs no equivalent fallback: Atmosphere reads its own option directly, so as long as the migration runs before any publish path (which it does at `init` priority 5), the canonical option is always the value Atmosphere sees.

### Deferred

- **Long-form composition control in FOSSE Settings.** The audit also flagged that FOSSE Settings doesn't surface the effective `fosse_long_form_strategy` (now `atmosphere_long_form_composition`). Adding a duplicate control to FOSSE Settings is in scope for `sdd/admin-ux-polish/` (PR 5), not this change. Atmosphere's native UI keeps working in the meantime.
- **Status visibility for the effective strategy and source.** Same — handled in PR 5 alongside the other Status surfacing gaps.

## Out of scope

- Renaming or restructuring the actual upstream filters / option keys.
- Any change to how Atmosphere or ActivityPub themselves consume their options.
- The cross-network privacy policy gap raised by the audit.

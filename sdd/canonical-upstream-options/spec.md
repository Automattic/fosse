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

A one-time `admin_init`-hooked migrator (`Canonical_Options_Migrator`) gated on a `fosse_canonical_options_migrated` flag option. On first run after the upgrade lands, it:

1. If `fosse_object_type === 'note'`, sets `activitypub_object_type=note` (the only FOSSE-set value that materially differed from upstream pass-through). Other stored values were pass-throughs and need no migration. Deletes `fosse_object_type` regardless.
2. If `fosse_long_form_strategy` is set to a known strategy, copies to `atmosphere_long_form_composition` (overwriting whatever Atmosphere had, since the FOSSE option had been silently winning anyway). Drops unknown values without copying. Deletes `fosse_long_form_strategy` regardless.
3. If neither legacy option is set AND `atmosphere_long_form_composition` is also unset, seeds the canonical option with `'teaser-thread'` so fresh installs preserve FOSSE's preferred default (per `sdd/long-form-bluesky-strategy/`). Atmosphere's own default is `'link-card'`; without this seeding, a fresh FOSSE install would silently shift behavior on day 1.
4. Sets the flag option so subsequent admin loads short-circuit.

`admin_init` (not `init`) so frontend traffic — including bots and uncached anonymous pageviews — never trips into option writes on an unmigrated site.

### Deferred

- **Long-form composition control in FOSSE Settings.** The audit also flagged that FOSSE Settings doesn't surface the effective `fosse_long_form_strategy` (now `atmosphere_long_form_composition`). Adding a duplicate control to FOSSE Settings is in scope for `sdd/admin-ux-polish/` (PR 5), not this change. Atmosphere's native UI keeps working in the meantime.
- **Status visibility for the effective strategy and source.** Same — handled in PR 5 alongside the other Status surfacing gaps.

## Out of scope

- Renaming or restructuring the actual upstream filters / option keys.
- Any change to how Atmosphere or ActivityPub themselves consume their options.
- The cross-network privacy policy gap raised by the audit.

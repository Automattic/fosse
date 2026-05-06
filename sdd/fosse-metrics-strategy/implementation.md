# Implementation: FOSSE Metrics

Companion to `spec.md` (strategy) and `plan.md` (phased tasks).
Linear: DOTCOM-16879. Sub-issues filed per phase.

This doc covers architecture and event taxonomy. Strategy-level reviewer concerns track in `spec.md`.

## What this covers

- Recorder + per-channel registration (Tracks channel + MC stat-bump channel as separate, independently-registered transports).
- wp.com Tracks channel via wpcom-loader, mirroring 215299-ghe-Automattic/wpcom.
- Jetpack-connected Tracks channel via `Automattic\Jetpack\Tracking` (FOSSE never bundles Tracks).
- MC stat-bump channel as a small, confirmed counter set — not a zero-cost auto-companion to every Tracks event.
- v1 event taxonomy mapped tightly to the strategy's 7-step funnel, with two diagnostic add-ons.
- Privacy contract enforcement, including handling sink-decorated properties Jetpack adds out-of-band.
- Consent inheritance, user-vs-site identity for funnel events.

## v1 scope: cut from the original strawman

An adversarial pass cut these from v1; they return as Phase 2 candidates only if early data motivates them:

- `fosse_wizard_step_viewed` / `_step_completed` / `_step_skipped` — too granular for v1; the funnel cares about wizard completion, not per-step abandonment, until we have N to read.
- `fosse_bluesky_handle_dns_fallback_shown` / `fosse_bluesky_handle_verification_check` — internal feature analytics, not funnel.
- `fosse_actor_mode_changed`, `fosse_handle_username_changed`, `fosse_auto_publish_toggled`, `fosse_post_types_changed` — settings-tweak telemetry.

Added to v1 (server-side observable, hits Reviewer Concern 3 in `spec.md`):

- `fosse_search_indexing_disabled_post_active` — site flipped `blog_public` from 1 → 0 while FOSSE was active. The "users escaping FOSSE via the gate" anti-pattern.

## Architecture

### Recorder

FOSSE owns the event taxonomy and a context-enrichment step. Two transport channels are registered independently. Either, both, or neither may be present.

```php
namespace Automattic\Fosse\Metrics;

final class Recorder {
    public static function record( string $event, array $properties = [] ): void {
        $properties = self::enrich( $event, $properties );

        if ( ! Schema::is_valid( $event, $properties ) ) {
            return; // hard fail in WP_DEBUG; silent in production
        }

        foreach ( self::tracks_channels() as $channel ) {
            $channel->record( $event, $properties );
        }
    }

    public static function bump( string $name ): void {
        foreach ( self::mc_channels() as $channel ) {
            $channel->bump( $name );
        }
    }

    private static function enrich( string $event, array $properties ): array {
        return apply_filters( 'fosse_metrics_event_context', $properties, $event );
    }

    private static function tracks_channels(): array { /* apply_filters( 'fosse_metrics_tracks_channels', [] ) */ }
    private static function mc_channels(): array { /* apply_filters( 'fosse_metrics_mc_channels', [] ) */ }
}
```

Three properties are non-negotiable:

1. **Calls are fire-and-forget.** Recorder catches channel exceptions; user paths never see them.
2. **Default is silence.** No channel registered → no events. The pure-self-host posture.
3. **Channels are independent.** Tracks and MC have different consent, transport cost, and dashboard surfaces. Treating them as one composite hides those differences.

### Why two channel registries (not one composite sink)

The original strawman had a single `Sink` with optional `bump()`. Codex rightly pushed back: at A8C, Tracks and MC are separate transports with separate consent and separate failure modes. A single sink couples them and makes it impossible to register Tracks-without-MC or MC-without-Tracks (e.g. a host that has Tracks consent but doesn't want to write into the MC dashboards). Two registries solve that and make the call sites explicit: `Recorder::record()` for the funnel; `Recorder::bump()` for aggregate counters.

### Channel interfaces

```php
interface Tracks_Channel {
    public function record( string $event, array $properties ): void;
}

interface Mc_Channel {
    public function bump( string $name ): void;
}
```

### Channels shipped at v1

| Cohort | Tracks channel | MC channel | Lives in |
|--------|----------------|------------|----------|
| wp.com Simple (A, B, C) | `Wpcom_Tracks_Channel` (`tracks_record_event`) | `Wpcom_Mc_Channel` (`bump_stats_extras`) | wp-content/mu-plugins/fosse-loader.php |
| Jetpack-connected self-hosted | `Jetpack_Tracks_Channel` (lower-level Jetpack `Tracking::tracks_record_event` to preserve the `fosse_` prefix — see Open Question 1) | `Jetpack_Mc_Channel` (Jetpack stats bump path) | FOSSE plugin, gated on `class_exists( '\Automattic\Jetpack\Tracking' )` |
| Pure self-hosted (no Jetpack) | none | none | n/a |
| Tests | `In_Memory_Tracks_Channel` | `In_Memory_Mc_Channel` | FOSSE plugin (test-only autoload) |

## Channels: Tracks vs MC

### Tracks (identified, rich properties)

For funnel-conversion analysis: who completed which step, with what destination/actor-mode/network. Required for Tier 1 (early-signal funnel) and Tier 2 (sustained-activity per-user reads).

Properties on every event respect the privacy contract in `spec.md`. No content, no DIDs, no handles, no URLs. See "Privacy contract enforcement" below for what makes that contract structurally hold.

### MC stat bumps (anonymous, low-cardinality counters)

Codex correctly pushed back on the original "free signal" framing: MC bumps are **not** zero-cost. Server-side bumps `wp_remote_get` to `pixel.wp.com` and deduplicate identical names within a group per request. We treat them as a separate operational channel — independent registration, distinct call sites — but they don't need an external sign-off to ship. Group is `fosse`; names just need to read clearly on `mc.wordpress.com/?v=fosse`.

**v1 MC bumps — bounded list:**

- `wizard-completed`
- `connection-completed-mastodon`
- `connection-completed-bluesky`
- `publish-success-mastodon`
- `publish-success-bluesky`
- `handle-setup-active`

Tracks events fire independently and aren't gated on this list shipping.

### Context enrichment

Cohort/population is added at the enrichment layer, not at the channel. The wpcom-loader filters `fosse_metrics_event_context` to add `cohort: 'A'|'B'|'C'`. The Jetpack channel's host filter adds `population: 'jetpack-connected'`. Pure self-host (no channel registered, no enrichment fires) adds nothing.

This means cohort/population is part of the event schema (subject to allowlist validation) and visible in tests. It does not silently differ across hosts.

## Event taxonomy

Names use the `fosse_` prefix; properties use snake_case. The wp.com data team owns the final schema (Open Question 1 in `spec.md`); the names below are the strawman to take into that review, not the locked schema.

Step 1 of the spec funnel (site created) is wp.com's existing event, not FOSSE-emitted. Step 2 (FOSSE active) is wpcom-loader's `wpcom_fosse_activate`, already shipping in 215299-ghe-Automattic/wpcom — FOSSE plugin does not duplicate it.

### Wizard

| Event | Properties (allowed) | Notes |
|-------|----------------------|-------|
| `fosse_wizard_started` | `entry: 'auto'\|'admin_notice'\|'menu'`, `cohort`/`population` | First view of step 1 in a session |
| `fosse_wizard_completed` | `destination: 'fediverse_bluesky'\|'fediverse_only'`, `actor_mode: 'blog'\|'actor'\|'actor_blog'`, `post_types_count_bucket`, `bluesky_state: 'connected'\|'skipped'\|'unavailable'`, `cohort`/`population` | One per wizard run |
| MC bump | `fosse-wizard-completed` | Aggregate counter |

Per-step events deferred. Abandonment is computed dashboard-side from the absence of `fosse_wizard_completed` after `fosse_wizard_started` within N days.

### Network connection funnel

The strategy spec's step 3 (`fosse_connection_completed`) blown out into attempt + success/fail so we can debug the step-2-to-step-3 collapse Reviewer Concern 10 calls out.

| Event | Properties | Notes |
|-------|------------|-------|
| `fosse_connection_attempt` | `network: 'mastodon'\|'bluesky'`, `source: 'wizard'\|'settings'`, `cohort`/`population` | User clicked Connect / submitted handle |
| `fosse_connection_completed` | `network`, `source`, `cohort`/`population` | OAuth or DID resolution succeeded |
| `fosse_connection_failed` | `network`, `source`, `error_category: 'auth_failed'\|'rate_limited'\|'network_timeout'\|'invalid_handle'\|'other'`, `cohort`/`population` | Pre-categorized; never raw error |
| MC bump | `fosse-connection-completed-$network` | Aggregate counter |

### Bluesky domain handle

Trimmed to head-and-tail of the funnel. Internal verification details (DNS fallback shown, verification check result) cut from v1.

| Event | Properties | Notes |
|-------|------------|-------|
| `fosse_bluesky_handle_setup_started` | `eligibility: 'eligible'\|'path_bound'`, `cohort`/`population` | "Use your domain as your Bluesky handle" CTA tap |
| `fosse_bluesky_handle_active` | `cohort`/`population` | First detected transition: bsky.app `getProfile.handle` === site domain. Persists `fosse_bluesky_handle_active_recorded` flag in connection option to prevent duplicate emits |
| MC bump | `fosse-handle-setup-active` | Aggregate counter |

### Publishing

`fosse_post_published` is **not** wired to the synchronous `publish_post` hook (codex flagged this). The bundled Atmosphere plugin schedules publishing via `transition_post_status` and runs `Publisher::publish()` from cron — that's where the actual success/failure result exists. Both publishing events fire from the async publish path.

| Event | Properties | Notes |
|-------|------------|-------|
| `fosse_post_published` | `post_format`, `has_image: bool`, `cohort`/`population` | Once per post when the async publish path begins |
| `fosse_publish_result` | `network`, `status: 'success'\|'failure'`, `strategy: 'long-form-teaser-thread'\|'short-form-note'\|'link-card-fallback'`, `error_category`, `cohort`/`population` | One per network when the async result is known |
| MC bump | `fosse-publish-success-$network` (only on success) | Aggregate counter |

`strategy` is already produced by the projector code; this taxonomy routes it to a Tracks event. Cron-context emits use site-owner identity (see "Consent inheritance — user vs site identity" below) to avoid falling into anonymous identity fallback.

### Engagement

Trimmed: `clicked-through` cut for v1. Codex flagged that there's no defined click surface today — Atmosphere stores source URLs on synced comments but no outbound-click instrumentation exists. v1 captures `replied` only, sourced from existing comment-publish flow.

| Event | Properties | Notes |
|-------|------------|-------|
| `fosse_inbound_interaction` | `network`, `kind: 'like'\|'reply'\|'repost'`, `days_since_publish_bucket: '0-1'\|'1-7'\|'7-14'\|'14+'`, `cohort`/`population` | Bundled AP/Atmosphere round-trip surfaces these |
| `fosse_author_engaged` | `network`, `kind: 'replied'`, `days_since_interaction_bucket`, `cohort`/`population` | Per Reviewer Concern 13, scoped to replied-only for v1 |

### Negative / opt-out signals

`fosse_search_indexing_disabled_post_active` is in v1 — server-side observable, hooks `update_option_blog_public` and emits when transition is `1 → 0` and FOSSE is active. Hits Reviewer Concern 3 in `spec.md`. No user-facing instrumentation.

`fosse_disable_clicked` deferred until the v2 disable UI exists.

## Privacy contract enforcement

### Schema (allowlist) is the first line

Every documented event has a `Schema::ALLOWED[ $event ]` entry listing allowed property names. `Recorder::record()` checks against that before forwarding. Properties not on the allowlist are dropped.

In `WP_DEBUG`, schema violations are a hard error (`trigger_error` at `E_USER_WARNING`); in production, they're silently dropped. This catches schema drift in CI and dev without creating a user-visible failure mode in production.

PHPUnit asserts every documented event has an allowlist entry.

### Channel-decorated properties

The static allowlist is **not sufficient** by itself. Codex correctly pointed out that Jetpack's `record_user_event()` adds `_via_ua`, `_via_ip`, `_lg`, `blog_url`, and `blog_id` after the caller's properties pass through. Those decorations bypass the allowlist and collide with the strategy's "no URLs / no IPs" contract.

To prevent that:

- The Jetpack channel uses the lower-level `Tracking::tracks_record_event()` (not `record_user_event()`), which does not add the IP/UA/URL decorations.
- The wp.com channel uses `tracks_record_event()` from `tracks/client` directly — the same lib PR 215299-ghe-Automattic/wpcom uses — and verifies the request payload in tests doesn't include scrubbed-by-contract fields.
- Both channels have a post-decoration "outgoing" property assertion in the test channel that traps any added property whose name starts with `_` or matches `blog_url|*_ip|*_ua` against the contract.

Sink-decorated property handling is in scope for v1; this is the codex pushback that goes from "design issue" to "must enforce, structurally."

## Consent inheritance

### wp.com Simple

Platform-managed. The wpcom-loader's `Wpcom_Tracks_Channel::record()` calls `tracks_record_event()` directly; the platform handles consent and identity at the Tracks layer. FOSSE adds no consent surface on Simple.

### Jetpack-connected self-hosted — user vs site identity

Codex flagged a real gap: `Manager->is_connected()` is site-level. Per-user funnel events tied to a UI action need `Manager::is_user_connected( get_current_user_id() )` — Jetpack Forms uses this exact gate before recording self-hosted Tracks events because Tracking falls back to anonymous identity for unlinked users.

Two cases for FOSSE:

1. **User-context events** (wizard, connection, settings, handle setup): require a connected `wp_get_current_user()`. Without it, drop the event. We'd rather lose a Tracks event than emit anonymously-identified data into a funnel that compares per-user conversion.
2. **Cron / async-context events** (publish result, search-indexing flip, inbound interaction): no current user. Resolve to the site owner / master user via `( new Manager )->get_connection_owner_id()` and use that as the event user. Document this explicitly in the channel so it doesn't accidentally fall through to anonymous.

The Jetpack channel registration gate is therefore:

1. `class_exists( 'Automattic\Jetpack\Tracking' )` (the API exists), AND
2. The Tracks call's own ToS/offline-mode check passes inside `tracks_record_event` (we don't pre-call `should_enable_tracking()` ourselves; that requires constructing `Terms_Of_Service` + `Status` and Tracking already does it internally).

That's two gates, not three — the previous draft had three and the third one duplicated logic Jetpack already runs.

### Pure self-hosted

No channel. Recorder calls are no-ops. `spec.md` already designates the optional aggregate pingback as deferred (and possibly never).

## Failure modes

- **Channel raises an exception.** Recorder catches and `error_log` at debug level. User flow continues.
- **Channel registered but transport (Tracks endpoint, MC bump endpoint) is down.** Channel swallows the failure. No retry queue.
- **Recorder called before plugins_loaded.** Filter resolves to empty channel list, becomes a no-op. Safe.
- **Schema (allowlist) violation in production.** Drop the offending property. Don't drop the whole event.
- **Schema violation in `WP_DEBUG`.** Hard error. Caught by CI.
- **Cron-context event with no resolvable connection owner.** Drop the event rather than emit anonymously.

## Testing

- `In_Memory_Tracks_Channel` and `In_Memory_Mc_Channel` registered via the channel filters in PHPUnit `setUp`.
- Helper trait `Asserts_Metrics` with methods like `assertEventRecorded( $event, $properties_subset )` and `assertMcBumped( $name )`.
- Privacy contract tests: feed disallowed property names (including ones that mimic `_via_ip`-style sink decorations) and assert they're dropped or trigger the WP_DEBUG hard fail.
- Per-flow integration tests: wizard completes, connection succeeds, handle goes active — each asserts the documented events fire in order with the right cohort/population enrichment.

## Open implementation questions

1. **Exact Jetpack Tracks API method that preserves the `fosse_` prefix.** `record_user_event()` auto-prefixes events with the Tracking product name, producing `jetpack_fosse_*` or similar. The Jetpack channel needs to use a lower-level method that takes the event name as-is. Codex flagged this; the channel implementation must pin and verify before the schema review with the data team.
2. **Composite cohort vs population property.** wp.com sites carry `cohort: A|B|C`; Jetpack-connected sites carry `population: 'jetpack-connected'`. Pre-launch decision: do downstream queries union on a single `segment` property, or filter by host-specific properties? If the data team prefers a single field, normalize at enrichment.
3. **MC bump stat names.** Group is `fosse`; pick concrete names that read clearly on `mc.wordpress.com/?v=fosse`. No external sign-off needed; this is an internal-legibility review during Phase 7.
4. **`fosse_search_indexing_disabled_post_active` cardinality.** This fires once per site per `1 → 0` transition (debounced via a transient). Confirm with the data team that low-volume server-side observations are acceptable in the Tracks pipeline, or route to MC only.
5. **Async publish-path event timing.** Atmosphere's `Publisher::publish()` is the success/failure call site. v1 instrumentation needs an explicit hook (filter or action) inside Publisher we can subscribe to. If Atmosphere doesn't expose one yet, that hook is itself a sub-task — coordinate with Matthias upstream as the strategy spec calls out.

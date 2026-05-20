# Plan: FOSSE Metrics — phased tasks

Companion to `spec.md` (strategy) and `implementation.md` (architecture + taxonomy).
Linear: DOTCOM-16879 parent epic. Each phase below is a Linear sub-issue under that epic.

Sub-issue branch names end with the issue ID (Linear auto-association). Sub-issue titles use the `Metrics:` prefix.

## Phase ordering rationale

Phases 0 through 4 unblock the Tier 1 funnel read on wp.com. Phase 5 closes the round-trip (Reviewer Concern 8 in `spec.md`'s "site displays reactions" gap). Phases 6 and 7 extend coverage to Jetpack-connected self-hosted and add the MC aggregate channel. Phase 8 names dashboards and owners (Reviewer Concern 6).

Phase 0 must START as soon as the strategy spec is agreed — Cohort C-pre needs ≥4 weeks of data before FOSSE-on rolls out broadly, or Tier 3 differential liveness is degraded to a delayed read (per Reviewer Concern 7).

## Phase 0 — Cohort C-pre baseline (wpcom-side, best-effort)

**Why:** A clean Cohort C baseline reading captured *before* FOSSE-on broad rollout is what makes Tier 3 differential liveness causally defensible. Radical Speed Month timing makes a guaranteed ≥4-week window unrealistic, so this phase is best-effort: instrument as soon as possible in parallel with Phase 1, take whatever weeks accumulate. Tier 3 reads later than ideal if we end up with <4 weeks; that's an accepted trade-off, not a blocker.

**Tasks:**
- Identify the matching-Simple-sites query (FOSSE-eligible: launched + public + indexable, FOSSE-not-active).
- Instrument a recurring read of: posts per site per 90-day window, comments per post, sessions / pageviews per period.
- Land in wpcom as a scheduled query / Looker dashboard, ideally before FOSSE-on broad rollout but not gating it.

**Out of scope for this repo.** Tracked here so the dependency is visible. Owner: kraft + data team. Linear sub-issue captures the cross-repo dependency.

**Exit criterion:** Cohort C-pre baseline instrumentation is running in wpcom and has accumulated as much pre-launch data as the rollout schedule allowed. If <4 weeks at FOSSE-on broad rollout, document the actual window in the Tier 3 dashboard so future readers know how much of the differential is "delayed read" vs "clean before/after."

## Phase 1 — Recorder, schema, channel interfaces, test channels

**Why:** Builds the FOSSE-side spine. No production events fire until a channel is registered (Phase 2+).

**Tasks:**
- `src/Metrics/Recorder.php` — `record()`, `bump()`, `enrich()` per `implementation.md`.
- `src/Metrics/Schema.php` — `ALLOWED` map for every documented event; `is_valid()` check that hard-fails in `WP_DEBUG`, drops silently in production.
- Channel interfaces: `Tracks_Channel`, `Mc_Channel`.
- In-memory test channels: `In_Memory_Tracks_Channel`, `In_Memory_Mc_Channel`.
- `Asserts_Metrics` PHPUnit trait.
- Tests: every documented event has a Schema entry; allowlist drops disallowed properties; WP_DEBUG hard-fails on schema violation; recorder catches channel exceptions.

**Exit criterion:** Recorder lands on trunk, no channel registered, no production events emit. Tests green.

## Phase 2 — wp.com Tracks channel + cohort enrichment ✅ landed

**Status:** Landed in 215409-ghe-Automattic/wpcom (merged 2026-05-08). Linear: DOTCOM-17029.

**Why:** The strategy's primary cohort. Lands the funnel-event path for Cohort A/B before instrumenting the call sites in Phase 3.

**Tasks (wpcom side):**
- Add the wpcom Tracks channel in `wp-content/mu-plugins/fosse-loader.php` (anonymous class declared inside the `fosse_metrics_tracks_channels` filter callback so a request where FOSSE never loaded never tries to resolve the interface). `record()` calls `tracks_record_event()` from `tracks/client` lib (mirrors the activation-event pattern PR 215299-ghe-Automattic/wpcom established for `wpcom_fosse_activate`).
- Register via `add_filter( 'fosse_metrics_tracks_channels', ... )`.
- Filter `fosse_metrics_event_context` to attach `cohort: 'A' | 'C-post'`. Cohort A determined by `enable-fosse` + `fosse-auto-on` blog stickers (auto-on launch path; sticker not yet set anywhere, so A reads as null until that ships). Cohort C-post: Simple-eligible blog without FOSSE active. Cohort B (manually opted-in: `enable-fosse` sticker without auto-on, or Blurt theme via wordpress.com/social) deferred — TODO in `wpcom_fosse_determine_cohort()`.
- Test (sandbox or unit): verify the outgoing Tracks payload doesn't include scrubbed-by-contract fields (no `_via_ip`, no `blog_url` post-decoration).

**Cross-repo coordination:** Phase 1 lands first in fosse plugin; the wpcom-side Phase 2 PR depends on it.

**Exit criterion:** wp.com sandbox emits a `fosse_*` Tracks event when Phase 1's recorder is called from a synthetic test endpoint.

**Follow-up:** Cohort B detection (Blurt theme + sticker-without-auto-on signal) tracked alongside Phase 3.

## Phase 3 — Wizard / connection / handle / search-indexing instrumentation

**Why:** First real funnel data. Wires the call sites whose events Phases 1 and 2 made possible.

**Tasks:**
- `fosse_wizard_started` from wizard step 1 first view.
- `fosse_wizard_completed` from wizard Review submit.
- `fosse_connection_attempt` / `_completed` / `_failed` from the OAuth + Bluesky DID resolution paths.
- `fosse_bluesky_handle_setup_started` from the "Set up domain handle" CTA.
- `fosse_bluesky_handle_active` from the lazy `getProfile.handle` comparison; persist `fosse_bluesky_handle_active_recorded` flag in connection option.
- `fosse_search_indexing_disabled_post_active` server-side: hook `update_option_blog_public`, emit on `1 → 0` transition while FOSSE active. Debounce via short transient.
- Per-flow integration tests asserting events fire in the right order with cohort enrichment.

**Exit criterion:** Sandbox wizard run end-to-end emits the expected events, visible in the Tracks event log with cohort attached. PHPUnit + Playwright tests green.

## Phase 4 — Async publish-path instrumentation

**Why:** `fosse_post_published` and `fosse_publish_result` need the bundled Atmosphere async publish path, not the synchronous `publish_post` hook. Codex flagged this as a v1 correctness blocker.

**Tasks:**
- Audit bundled Atmosphere `Publisher::publish()` for an existing extension point (filter, action). If absent, add one upstream (coordinate with Matthias per the strategy spec).
- Subscribe FOSSE recorder calls from inside the Publisher invocation, not from `transition_post_status`.
- Cron-context identity: resolve site owner via `( new Manager )->get_connection_owner_id()` for Jetpack; on wp.com the Tracks lib handles this via the loader.
- Test: synthetic publish through the async path emits `fosse_post_published` once and `fosse_publish_result` once per network with the right `strategy` enum.

**Exit criterion:** Async publish path emits result events matching the documented schema. Tests green.

## Phase 5 — Engagement events

**Why:** Closes the round-trip — Tier 1 funnel steps 6 and 7 from the strategy spec.

**Tasks:**
- `fosse_inbound_interaction` from bundled Atmosphere reaction-sync entry points + bundled AP's inbound-interaction observation hook (verify both surfaces produce the event).
- `fosse_author_engaged` (kind: replied) from the comment-publish flow when the parent comment has a federated source.
- `clicked-through` deferred — no instrumented click surface today; revisit when one ships.
- `days_since_*` bucketing in the recorder, not the call site.
- Tests: synthetic inbound reaction → author reply emits both events with bucketed day deltas.

**Exit criterion:** Round-trip flow emits both engagement events. Tests green.

## Phase 6 — Jetpack-connected Tracks channel

**Why:** Extends the funnel to Jetpack-connected self-hosted (Reviewer Concern 6 in `spec.md`'s scope; the natural Tier 3 extension Ryan called out in the RFC).

**Tasks:**
- `src/Metrics/Channels/Jetpack_Tracks_Channel.php`. Gated on `class_exists( 'Automattic\Jetpack\Tracking' )`.
- Use the lower-level Jetpack Tracks call that preserves the `fosse_` event-name prefix (Open Question 1 in `implementation.md` — pin the exact method during this phase).
- Filter `fosse_metrics_event_context` to attach `population: 'jetpack-connected'`.
- User-context events: gate on `Manager::is_user_connected( get_current_user_id() )`. Drop event if false (don't emit anonymously).
- Cron-context events: resolve to connection owner via `get_connection_owner_id()`. Drop if unresolvable.
- Tests: simulated Jetpack-connected environment emits events with `population` attached and the correct event-name prefix.

**Exit criterion:** Jetpack-connected dev environment emits `fosse_*` Tracks events visible to Jetpack's Tracks pipeline.

## Phase 7 — MC stat-bump channels

**Why:** Cheap aggregate counters surfaced on `mc.wordpress.com`. Independent of Tracks events.

**Tasks:**
- Pick stat names that make sense at a glance (group `fosse`, names like `wizard-completed`, `connection-completed-bluesky`). No external sign-off required, just internal review for legibility.
- `Wpcom_Mc_Channel` in fosse-loader (calls `bump_stats_extras`).
- `Jetpack_Mc_Channel` in FOSSE plugin (calls Jetpack stats bump path).
- Wire `Recorder::bump()` calls at the same call sites that emit Tracks events for the v1 bump set: wizard_completed, connection_completed_*, publish_success_*, handle_setup_active.
- Tests: bump set fires the expected counter names; in-memory channel asserts.

**Exit criterion:** Bump set visible on `mc.wordpress.com/?v=fosse` within 24 hours of first events. Tracks events unaffected.

## Phase 8 — Dashboards, owners, cadence

**Why:** Reviewer Concern 6 in `spec.md`. Without named owners and cadence, the metrics ship and nobody reads them.

**Tasks:**
- Tier 1 funnel dashboard: cohort A + B + C, week-over-week conversion. Owner: kraft initially, transition to data team.
- Tier 2 sustained-activity dashboard: 90-day rolling per-user reads on cohorts A/B that completed Tier 1 step 5. Owner: kraft.
- Tier 3 differential-liveness dashboard: A vs C-pre + A vs C-post comparison. Owner: kraft.
- Define escalation triggers per `spec.md` Reviewer Concern 1 (kill criteria — needs concrete thresholds before Phase 8 closes).
- Quarterly readout cadence into the FOSSE P2.

**Exit criterion:** Three dashboards exist, named owners, escalation triggers documented, first quarterly readout posted.

## Out of scope for this implementation

- Pure-self-hosted opt-in pingback. `spec.md` already designates this as deferred (and possibly never).
- Cohort B (fosse.wordpress.com) marketing site / landing page work. Multi-team. Reviewer Concern overflow item.
- Per-step wizard analytics, settings-tweak telemetry, click-through engagement. All cut from v1 by the codex pass; revisit if early data motivates.
- Any randomized hold-out within Cohort A. Reviewer Concern overflow item; needs a separate experiments-design conversation.

## Linear sub-issues

One sub-issue per phase under DOTCOM-16879. Branch naming convention: `metrics/<short-name>-<issue-id>`. Each sub-issue links back to PR 41 in its description and lists the exit criterion above as the "Definition of done."

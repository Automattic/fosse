# Requirements: Usage Metrics & Adoption Tracking

Linear: [DOTCOM-16879](https://linear.app/a8c/issue/DOTCOM-16879)

## Goal

Know whether FOSSE is working with privacy-respecting adoption and success metrics. Without a shared measurement layer, every discussion about success is anecdotal: we can say a feature shipped, but not whether sites adopt it, publish through it, receive reactions, or get stuck at a specific setup step.

## Existing Material

A prior docs-only commit added `sdd/fosse-metrics-strategy/spec.md` under the title "FOSSE Metrics - measuring whether regular people use it". That file is not present in the current tree, but git history contains it in commit `d7d3a0a`. This SDD keeps the useful strategic framing from that document:

- WordPress.com Simple is the strongest leading-indicator population for "regular people use it".
- Self-hosted plugin metrics are important, but should not be treated as equivalent evidence to a managed wp.com rollout.
- The early signal is a funnel; the durable signal is sustained publishing and inbound engagement over time.
- Search-indexed/public wp.com sites may be a natural rollout gate, but that belongs to the wp.com integration plan, not the self-hosted plugin contract.

This SDD narrows the implementation strategy so FOSSE core does not require Automattic-only infrastructure.

## Functional Requirements

1. **Adoption by environment and backend source.** Report onboarded sites by FOSSE version, WordPress environment, network availability, and whether ActivityPub / Atmosphere came from FOSSE's bundled copy or a standalone plugin.
2. **Connection funnel.** Measure completion of key setup states: wizard completed, Bluesky OAuth started, Bluesky OAuth completed, first network connected, both networks available/connected, first publishable post created, first successful federated post, and time-to-first-post.
3. **Publishing activity.** Count posts published per network, split by short-form vs long-form, ActivityPub object type mode, long-form strategy, and backend source.
4. **Federation reliability.** Count publish attempts, successes, partial successes, retries, and failures by network and normalized error category.
5. **Inbound interaction activity.** Count inbound replies/comments, likes, and reposts/announces by source network and local post shape. Count follows separately by source network because they are not tied to a local post shape. Do not store remote handles, DIDs, content, URLs, or raw activity payloads in metrics events.
6. **Queryable dashboards.** Define event names, dimensions, and dashboard-ready derived metrics so product, engineering, and data teams can answer the same questions without ad hoc log spelunking.
7. **Upstream coordination.** If instrumentation describes generic ActivityPub or Atmosphere publish/reaction behavior, add hooks upstream and consume them from FOSSE. FOSSE-specific funnel and cross-network classifications stay in FOSSE.

## Privacy Requirements

- Metrics events MUST NOT include post content, post titles, excerpts, remote actor DIDs, handles, profile URLs, raw inbox activities, raw PDS responses, or raw error messages.
- Event properties should be low-cardinality enums, booleans, counts, durations, version strings, and coarse environment labels.
- WordPress.com may use its existing first-party analytics pipeline and site identifiers under the wp.com privacy model. This must be documented as a wp.com adapter, not as a hard dependency for FOSSE core.
- Self-hosted FOSSE must default to no external telemetry in v1. If standalone telemetry ships, it must be explicitly disclosed in onboarding/settings and require an opt-in before sending aggregate events.
- Aggregate self-host events may include a generated install bucket only if product/legal approve the privacy tradeoff. The recommended v1 is no stable standalone site identifier; use WP.org active installs and GitHub download counts for distinct-install estimates.
- Metrics collection must degrade to a no-op when no sink is registered.

## Minimal V1 Questions

The v1 event set must answer:

- How many sites have FOSSE active, and on which FOSSE versions?
- How many sites run bundled backends versus standalone ActivityPub / Atmosphere?
- How many sites complete onboarding?
- How many complete Bluesky OAuth?
- How many have ActivityPub available and Bluesky connected at the same time?
- How long does it take from activation/onboarding to first federated post?
- How many publish attempts happen per network?
- Which publish attempts succeed or fail, and why at a normalized category level?
- Are posts being sent as short-form or long-form, and which FOSSE projector setting drove that shape?
- Are inbound interactions arriving, and from which network?

## Non-Goals

- No content analytics, sentiment analysis, recommendation ranking, or per-post performance leaderboard.
- No user-facing dashboard in v1 beyond disclosure/consent. Internal queryability is enough.
- No hand edits inside `bundled/`. Upstream hook changes land in `wordpress-activitypub` or `wordpress-atmosphere`, then FOSSE refreshes bundles.
- No attempt to prove causality for wp.com Simple adoption inside this plugin SDD. Cohort analysis belongs to the wp.com data/dashboard layer.

## Open Questions

- Should wp.com Simple use an opt-out disclosure tied to existing Tracks/privacy notices, or an explicit FOSSE-specific disclosure?
- Does Automattic want standalone self-hosted telemetry to remain opt-in permanently, or move to opt-out once the payload is externally reviewed?
- Should the self-hosted aggregate endpoint live in Automattic infrastructure, WordPress.org infrastructure, or remain unimplemented until there is a clear consumer?
- Who owns the canonical dashboards and SQL/query definitions?
- Should bundled-vs-standalone reporting be based only on FOSSE's bootstrap booleans, or also record detected standalone versions?
- Which generic publish/reaction hooks should land upstream before FOSSE records reliability metrics?
- What volume target makes the v1 funnel actionable for DOTCOM-16879?

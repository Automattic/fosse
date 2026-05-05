# Spec: Usage Metrics & Adoption Tracking

## Goal

Give FOSSE a privacy-respecting measurement layer that can answer whether sites adopt the product, complete setup, publish successfully to ActivityPub and Bluesky, and receive inbound interactions. FOSSE core owns event taxonomy and instrumentation points; each host decides where compliant aggregate events are sent.

Full requirements at [`requirements.md`](./requirements.md).

## Recommendation

Use a **sink-based metrics recorder** in FOSSE core:

1. FOSSE emits normalized, low-cardinality events through a local recorder.
2. The default sink is no-op.
3. WordPress.com registers a Tracks sink in its integration layer.
4. Self-hosted installs stay no-op unless the site owner opts into aggregate telemetry.

This separates the product question from the infrastructure question. The event taxonomy is shared, but the transport differs:

- **wp.com**: first-party Tracks adapter, governed by existing wp.com privacy/disclosure rules and queryable through internal dashboards.
- **self-hosted**: no external telemetry by default; optional aggregate sink only after explicit disclosure and consent.
- **tests/local**: in-memory sink for assertions; no network.

## Privacy Posture

Recommended v1 posture:

- **Aggregate by default, no content identifiers.** Events may include `network`, `backend_source`, `result`, `error_category`, `post_shape`, `object_type_mode`, `long_form_strategy`, `fosse_version`, and coarse environment labels. They must not include post IDs in external sinks, titles, content, permalinks, DIDs, handles, raw URLs, raw errors, or raw remote payloads.
- **Differentiate wp.com from standalone.** wp.com can use site/user identifiers already covered by wp.com analytics policy, but dashboard outputs for this SDD should be aggregate funnels and cohorts. FOSSE core must not require Tracks.
- **Self-hosted opt-in.** The standalone plugin should not send external telemetry until the site owner enables it. The disclosure belongs in the first-run wizard and Setup page, with a FOSSE-core filter for hosts to force-disable telemetry. FOSSE core does not provide a force-enable path for standalone installs.
- **Normalize before sending.** Error messages and remote responses are mapped locally into enums like `auth`, `configuration`, `validation`, `rate_limit`, `transport`, `remote_4xx`, `remote_5xx`, `partial_rollback`, and `unknown`.

## Minimal V1 Event Set

Event names use the `fosse_` prefix and intentionally avoid IDs:

| Event | When | Required Properties |
|---|---|---|
| `fosse_site_observed` | FOSSE boots, sampled at most daily per site for enabled sinks | `fosse_version`, `wp_version`, `php_version`, `environment`, `ap_backend_source`, `atmo_backend_source`, `ap_available`, `bluesky_available` |
| `fosse_onboarding_completed` | Wizard completion or skip | `completion_type`, `ap_available`, `bluesky_connected`, `both_networks_ready`, `elapsed_bucket` |
| `fosse_bluesky_oauth_started` | Bluesky connect form passes nonce/capability and redirects to auth | `origin`, `handle_supplied`, `atmo_backend_source` |
| `fosse_bluesky_oauth_completed` | OAuth callback returns to FOSSE | `result`, `error_category`, `origin`, `elapsed_bucket` |
| `fosse_connection_state_changed` | Provider state changes materially | `network`, `connected`, `backend_source`, `both_networks_ready` |
| `fosse_first_post_funnel` | First eligible post, first attempted federation, first success | `milestone`, `network`, `post_shape`, `elapsed_bucket` |
| `fosse_publish_attempt` | Outbound publish/update/delete attempt starts | `network`, `operation`, `post_shape`, `post_type_public`, `object_type_mode`, `long_form_strategy`, `backend_source` |
| `fosse_publish_result` | Outbound attempt ends | all attempt fields plus `result`, `error_category`, `record_count_bucket`, `retry_count_bucket` |
| `fosse_inbound_interaction_synced` | Remote interaction is stored as a local comment/reaction | `source_network`, `interaction_type`, `post_shape`, `local_comment_type` |
| `fosse_follow_synced` | Remote follow is accepted or stored by an inbound backend | `source_network`, `result`, `backend_source` |

## Derived Metrics

Dashboards should compute:

- **Onboarded sites** by FOSSE version, environment, backend source, and network readiness.
- **Adoption funnel**: observed site -> onboarding complete -> Bluesky OAuth complete -> both networks ready -> first publish attempt -> first publish success.
- **Time-to-first-post** from activation or first observation to first successful federated post, bucketed to `<1h`, `1-24h`, `1-7d`, `7-30d`, `>30d`.
- **Publishing volume** by network, short vs long, object type mode, and long-form strategy.
- **Federation success rate** by network, operation, backend source, and error category.
- **Inbound interaction volume** by network and interaction type.
- **Bundled-vs-standalone health**: publish success and OAuth completion rates split by backend source.

## Technical Design

### FOSSE Core

Add a metrics namespace under `src/Metrics/`:

| File | Responsibility |
|---|---|
| `src/Metrics/interface-sink.php` | Defines `record( string $event, array $properties ): void`. |
| `src/Metrics/class-null-sink.php` | Default sink; drops events. |
| `src/Metrics/class-recorder.php` | Public facade. Normalizes event names/properties, strips forbidden fields, applies `fosse_metrics_sink`, and records. |
| `src/Metrics/class-event-normalizer.php` | Low-cardinality allowlists, duration buckets, backend source detection, error categorization. |
| `src/Metrics/class-lifecycle-listener.php` | Daily site observation and activation/onboarding milestones. |
| `src/Metrics/class-connection-listener.php` | Bluesky OAuth and provider connection-state events. |
| `src/Metrics/class-publish-listener.php` | Publish attempt/result classification and first-post milestones. |
| `src/Metrics/class-interaction-listener.php` | Inbound interaction counters from ActivityPub and Atmosphere hooks. |
| `src/Metrics/class-consent.php` | Self-host telemetry consent option, host override filter, disclosure state. |

`fosse.php` registers the listeners when the classes exist, matching the existing degradation pattern for projectors and admin providers.

### Sink Registration

FOSSE core exposes:

```php
apply_filters( 'fosse_metrics_sink', new Null_Sink() );
do_action( 'fosse_metric_recorded', $event, $properties );
```

wp.com can register a Tracks-backed sink outside this repo. A standalone aggregate sink can be added later behind `Consent::is_enabled()`. Tests can inject an in-memory sink.

### Backend Source Detection

FOSSE already computes `$fosse_loaded_bundled_ap` and `$fosse_loaded_bundled_atmo` in `fosse.php`. Persist those request-local facts into the site-observation event as:

- `ap_backend_source`: `bundled`, `standalone`, or `unavailable`
- `atmo_backend_source`: `bundled`, `standalone`, or `unavailable`

Do not infer source from class names alone; standalone plugins can define the same classes and constants.

### Publish Instrumentation

FOSSE can classify post shape locally through existing projectors:

- `src/class-object-type.php` for `object_type_mode`
- `src/class-long-form-strategy.php` for `long_form_strategy`
- Atmosphere's `is_short_form_post()` / `build_long_form_records()` result, once exposed through upstream hooks, for actual `post_shape`

ActivityPub already has useful hooks:

- `post_activitypub_add_to_outbox`
- `activitypub_add_to_outbox_failed`
- `activitypub_sent_to_inbox`

Atmosphere needs upstream hooks in `Automattic/wordpress-atmosphere` because publish result and error category live inside `includes/class-publisher.php`. Add hooks there instead of parsing post meta from FOSSE.

Recommended upstream Atmosphere hook:

```php
do_action(
	'atmosphere_publish_result',
	$object,
	$operation,
	$result,
	array(
		'record_count' => $record_count,
		'strategy'     => $strategy,
		'is_short'     => $is_short,
	)
);
```

### Inbound Interaction Instrumentation

Use existing hooks where possible:

- ActivityPub: `activitypub_handled_create`, `activitypub_handled_like`, `activitypub_handled_announce`, `activitypub_handled_follow`, and related `activitypub_handled_*` actions.
- Atmosphere: `atmosphere_reaction_synced` in `bundled/atmosphere/includes/class-reaction-sync.php`.

FOSSE records comment/reaction hooks as `fosse_inbound_interaction_synced` with only the network, interaction type, local comment type, and post-shape context. Follow hooks emit `fosse_follow_synced` instead because follows are not tied to a local post/comment shape. No inbound event may send comment content or remote author metadata.

## Disclosure UX

Self-hosted disclosure belongs in both setup surfaces:

- `src/Admin/class-onboarding-wizard.php`: final/complete step includes a checkbox to enable aggregate usage metrics for standalone installs.
- `src/Admin/templates/setup-page.php`: persistent setting under a "Privacy" or "Usage metrics" section.

Copy must say that FOSSE sends aggregate counts only and does not send post content, profile handles, DIDs, or URLs. On wp.com, this UI can be hidden or replaced by wp.com's standard analytics disclosure.

## Out of Scope

- Implementing the wp.com Tracks adapter inside this repository.
- Building a public analytics dashboard.
- Backfilling historical metrics before instrumentation ships.
- Measuring consumption/read-side experiences owned by other teams.
- Recording post-level engagement details.

## Review Notes

The key product call is self-hosted telemetry consent. The prior metrics strategy floated opt-out aggregate pingback for standalone sites. This spec recommends opt-in for v1 because FOSSE is a new plugin handling social identities, and trust matters more than perfect self-hosted funnel math.

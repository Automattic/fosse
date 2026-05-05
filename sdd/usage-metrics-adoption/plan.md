# Implementation Plan: Usage Metrics & Adoption Tracking

Based on: [`requirements.md`](./requirements.md) and [`spec.md`](./spec.md)

## Progress

- [ ] Task 1: Add metrics recorder and normalization core
- [ ] Task 2: Add privacy consent and disclosure surfaces
- [ ] Task 3: Record site observation and onboarding funnel events
- [ ] Task 4: Record Bluesky OAuth and connection-state events
- [ ] Task 5: Coordinate upstream publish-result hooks
- [ ] Task 6: Record publish attempts, results, and first-post milestones
- [ ] Task 7: Record inbound interaction aggregate events
- [ ] Task 8: Add sink adapters and test helpers
- [ ] Task 9: Add dashboard/query documentation
- [ ] Task 10: End-to-end verification and SDD closeout

## Tasks

### Task 1: Add metrics recorder and normalization core
- **Status**: Not started
- **Files**:
  - Create: `src/Metrics/interface-sink.php`
  - Create: `src/Metrics/class-null-sink.php`
  - Create: `src/Metrics/class-recorder.php`
  - Create: `src/Metrics/class-event-normalizer.php`
  - Modify: `fosse.php`
  - Test: `tests/php/Metrics/RecorderTest.php`
  - Test: `tests/php/Metrics/Event_NormalizerTest.php`
- **Do**:
  1. Add `Automattic\Fosse\Metrics\Sink` with `record( string $event, array $properties ): void`.
  2. Add `Null_Sink` as the default implementation.
  3. Add `Recorder::record( string $event, array $properties = array() ): void`.
  4. Add `Event_Normalizer` allowlists for v1 event names and properties from `spec.md`.
  5. Strip forbidden keys: `post_id`, `comment_id`, `title`, `content`, `excerpt`, `permalink`, `url`, `did`, `handle`, `actor`, `author`, `raw_error`, `response`, `payload`.
  6. Add duration buckets: `<1h`, `1-24h`, `1-7d`, `7-30d`, `>30d`, `unknown`.
  7. Add error categories: `auth`, `configuration`, `validation`, `rate_limit`, `transport`, `remote_4xx`, `remote_5xx`, `partial_rollback`, `unknown`.
  8. Register no global sink in `fosse.php`; only ensure classes load and later listeners can call `Recorder`.
- **Verify**:
  - `composer run-script test-php -- --filter 'Metrics\\\\(Recorder|Event_Normalizer)'` passes.
  - `composer run-script lint-php` passes.
- **Depends on**: none

### Task 2: Add privacy consent and disclosure surfaces
- **Status**: Not started
- **Files**:
  - Create: `src/Metrics/class-consent.php`
  - Modify: `src/Admin/class-onboarding-wizard.php`
  - Modify: `src/Admin/class-setup-page.php`
  - Modify: `src/Admin/templates/setup-page.php`
  - Modify: `src/Admin/assets/css/admin.css`
  - Test: `tests/php/Metrics/ConsentTest.php`
  - Test: `tests/php/Admin/Onboarding_WizardTest.php`
  - Test: `tests/php/Admin/Setup_PageTest.php`
  - Test: `tests/e2e/onboarding-wizard.spec.ts`
- **Do**:
  1. Add `Consent::is_enabled()` backed by `fosse_metrics_consent`, default `false`.
  2. Add a FOSSE-core force-disable filter, such as `fosse_metrics_disabled`, so hosts can prevent telemetry even when the option is enabled. Do not add a force-enable path in FOSSE core; wp.com can register its own first-party sink outside this repository under platform privacy rules.
  3. Add a standalone-only disclosure checkbox to the wizard completion step.
  4. Add a persistent Setup page checkbox for aggregate usage metrics.
  5. Hide or replace the standalone consent UI when a host filter marks the environment as `wpcom`.
  6. Disclosure copy: "Send aggregate usage metrics to help improve FOSSE. FOSSE does not send post content, profile handles, DIDs, URLs, or raw federation payloads."
- **Verify**:
  - Unit tests cover default disabled, enabled option, and host-forced disabled states.
  - Wizard and Setup tests cover saving the option.
  - `pnpm exec playwright test tests/e2e/onboarding-wizard.spec.ts` verifies the checkbox is visible on standalone and can be saved.
  - `composer run-script lint-php`, `composer run-script test-php`, `pnpm run format:check`, and `pnpm run lint` pass.
- **Depends on**: Task 1

### Task 3: Record site observation and onboarding funnel events
- **Status**: Not started
- **Files**:
  - Create: `src/Metrics/class-lifecycle-listener.php`
  - Modify: `fosse.php`
  - Modify: `src/Admin/class-onboarding-wizard.php`
  - Test: `tests/php/Metrics/Lifecycle_ListenerTest.php`
  - Test: `tests/php/Admin/Onboarding_WizardTest.php`
- **Do**:
  1. Register `Lifecycle_Listener::register()` from `fosse.php`.
  2. Emit `fosse_site_observed` at most once daily per site using `fosse_metrics_last_observed_at`.
  3. Include `fosse_version`, `wp_version`, `php_version`, `environment`, `ap_backend_source`, `atmo_backend_source`, `ap_available`, and `bluesky_available`.
  4. Derive backend source from FOSSE bootstrap booleans and standalone sentinel checks.
  5. Emit `fosse_onboarding_completed` from wizard complete and skip handlers with `completion_type`.
  6. Persist first-observed timestamp in `fosse_metrics_first_observed_at` with autoload `false`.
- **Verify**:
  - Tests assert daily sampling, first-observed persistence, bundled-vs-standalone source values, complete-vs-skip events, and no forbidden fields.
  - `composer run-script test-php -- --filter 'Metrics\\\\Lifecycle_Listener|Onboarding_Wizard'` passes.
  - `composer run-script lint-php` passes.
- **Depends on**: Task 1

### Task 4: Record Bluesky OAuth and connection-state events
- **Status**: Not started
- **Files**:
  - Create: `src/Metrics/class-connection-listener.php`
  - Modify: `fosse.php`
  - Modify: `src/Admin/class-bluesky-provider.php`
  - Test: `tests/php/Metrics/Connection_ListenerTest.php`
  - Test: `tests/php/Admin/Bluesky_ProviderTest.php`
- **Do**:
  1. Emit `fosse_bluesky_oauth_started` after `Bluesky_Provider::handle_connect()` passes nonce/capability and before redirecting to the auth server.
  2. Emit `fosse_bluesky_oauth_completed` from `handle_oauth_callback()` for success, warning, and failure branches.
  3. Emit `fosse_connection_state_changed` when Bluesky connects or disconnects.
  4. Treat ActivityPub as connected when `AP_Provider::is_available()` is true.
  5. Include `origin` (`setup`, `wizard`, `unknown`) and `both_networks_ready`.
  6. Do not send the submitted handle, stored DID, PDS endpoint, or token error text.
- **Verify**:
  - Tests assert event presence and sanitized properties for OAuth start, callback success, callback failure, disconnect, and both-networks-ready state.
  - `composer run-script test-php -- --filter 'Metrics\\\\Connection_Listener|Bluesky_Provider'` passes.
  - `composer run-script lint-php` passes.
- **Depends on**: Task 1

### Task 5: Coordinate upstream publish-result hooks
- **Status**: Not started
- **Files**:
  - Upstream modify: `Automattic/wordpress-atmosphere/includes/class-publisher.php`
  - Upstream test: `Automattic/wordpress-atmosphere/tests/phpunit/tests/class-test-publisher.php`
  - Upstream modify if needed: `Automattic/wordpress-activitypub/includes/class-dispatcher.php`
  - Upstream test if needed: `Automattic/wordpress-activitypub/tests/test-class-dispatcher.php`
  - FOSSE refresh via sync after merge: `bundled/atmosphere/**`
  - FOSSE refresh via sync after merge if needed: `bundled/activitypub/**`
- **Do**:
  1. Open an Atmosphere PR adding `atmosphere_publish_attempt` and `atmosphere_publish_result` around `Publisher::publish_post()`, `update_post()`, `delete_post()`, `publish_comment()`, `update_comment()`, and `delete_comment()`.
  2. Include operation, object type (`post` or `comment`), short/long classification, long-form strategy, record count, result state, and `WP_Error` object for local categorization.
  3. Confirm ActivityPub's existing `post_activitypub_add_to_outbox`, `activitypub_add_to_outbox_failed`, and `activitypub_sent_to_inbox` hooks are sufficient. If not, add one focused upstream hook in `includes/class-dispatcher.php`.
  4. After upstream merges, refresh bundles with `./tools/sync-bundled.sh`.
- **Verify**:
  - Upstream unit tests prove hooks fire on success and failure without exposing payload content.
  - FOSSE bundle refresh contains the merged hook changes.
  - `composer run-script lint-php` passes after bundle refresh.
- **Depends on**: Task 1

### Task 6: Record publish attempts, results, and first-post milestones
- **Status**: Not started
- **Files**:
  - Create: `src/Metrics/class-publish-listener.php`
  - Modify: `fosse.php`
  - Test: `tests/php/Metrics/Publish_ListenerTest.php`
  - Test: `tests/e2e/short-form-facets.spec.ts`
  - Test: `tests/e2e/long-form-link-card.spec.ts`
  - Test: `tests/e2e/mu-plugins/fosse-bsky-capture.php`
- **Do**:
  1. Hook ActivityPub and Atmosphere publish attempt/result actions.
  2. Emit `fosse_publish_attempt` and `fosse_publish_result`.
  3. Classify `network` as `activitypub` or `bluesky`.
  4. Classify `post_shape` as `short-form`, `long-form`, `reply`, or `unknown`.
  5. Include `object_type_mode` from `fosse_object_type` and `long_form_strategy` from `fosse_long_form_strategy`.
  6. Emit `fosse_first_post_funnel` for first eligible post, first publish attempt, and first success. Store milestone timestamps in a single `fosse_metrics_funnel` option with autoload `false`.
  7. Map all `WP_Error` and HTTP failures to normalized `error_category` values.
- **Verify**:
  - Unit tests cover successful AP outbox, failed AP outbox, successful Atmosphere publish, Atmosphere `WP_Error`, short/long classification, and first-milestone idempotency.
  - E2E fixtures can inject a test sink and assert short-form/long-form publish events without contacting real networks.
  - `composer run-script test-php -- --filter 'Metrics\\\\Publish_Listener'` passes.
  - `pnpm exec playwright test tests/e2e/short-form-facets.spec.ts tests/e2e/long-form-link-card.spec.ts` passes.
- **Depends on**: Task 5

### Task 7: Record inbound interaction aggregate events
- **Status**: Not started
- **Files**:
  - Create: `src/Metrics/class-interaction-listener.php`
  - Modify: `fosse.php`
  - Test: `tests/php/Metrics/Interaction_ListenerTest.php`
  - Test: `tests/e2e/reactions-display.spec.ts`
- **Do**:
  1. Hook ActivityPub handled interaction actions: `activitypub_handled_create`, `activitypub_handled_like`, and `activitypub_handled_announce`.
  2. Hook ActivityPub follow handling separately through `activitypub_handled_follow`.
  3. Hook Atmosphere's `atmosphere_reaction_synced`.
  4. Emit `fosse_inbound_interaction_synced` for replies/comments, likes, and reposts with `source_network`, `interaction_type`, `post_shape`, and `local_comment_type`.
  5. Emit `fosse_follow_synced` for follows with `source_network`, `result`, and `backend_source`.
  6. Skip failed inbound handlers where the upstream hook reports failure.
  7. Do not send comment IDs, author names, handles, URLs, DIDs, or content.
- **Verify**:
  - Unit tests cover ActivityPub reply/like/announce, ActivityPub follow, and Atmosphere comment/like/repost.
  - Tests assert forbidden fields are stripped even if accidentally passed.
  - `composer run-script test-php -- --filter 'Metrics\\\\Interaction_Listener'` passes.
  - `pnpm exec playwright test tests/e2e/reactions-display.spec.ts` passes.
- **Depends on**: Task 1

### Task 8: Add sink adapters and test helpers
- **Status**: Not started
- **Files**:
  - Create: `src/Metrics/class-memory-sink.php`
  - Create: `src/Metrics/class-self-host-aggregate-sink.php`
  - Test: `tests/php/Metrics/Memory_SinkTest.php`
  - Test: `tests/php/Metrics/Self_Host_Aggregate_SinkTest.php`
  - Modify: `tests/php/bootstrap.php`
- **Do**:
  1. Add `Memory_Sink` for unit/e2e injection.
  2. Add `Self_Host_Aggregate_Sink`, gated by `Consent::is_enabled()`.
  3. Send only aggregate event payloads to a filterable endpoint, defaulting to disabled unless an endpoint is explicitly configured by host code.
  4. Add `fosse_metrics_endpoint` filter for hosts that want to operate a standalone aggregate endpoint.
  5. In tests, expose helper methods to reset and inspect captured events.
- **Verify**:
  - Tests assert self-host sink sends nothing when consent is disabled or endpoint is empty.
  - Tests assert payloads contain no forbidden keys.
  - `composer run-script test-php -- --filter 'Metrics\\\\(Memory_Sink|Self_Host_Aggregate_Sink)'` passes.
  - `composer run-script lint-php` passes.
- **Depends on**: Task 2

### Task 9: Add dashboard/query documentation
- **Status**: Not started
- **Files**:
  - Create: `sdd/usage-metrics-adoption/dashboard.md`
  - Modify: `sdd/usage-metrics-adoption/spec.md`
  - Modify: `sdd/usage-metrics-adoption/requirements.md`
- **Do**:
  1. Document canonical funnel definitions.
  2. Document required dashboard slices: version, environment, backend source, network, post shape, long-form strategy, object type mode, error category.
  3. Add wp.com Tracks event mapping in a clearly marked wp.com-only section.
  4. Add standalone self-host reporting caveats: WP.org active installs estimate distinct installs; opt-in aggregate events estimate behavior among participating installs only.
- **Verify**:
  - Docs define every event in `spec.md`.
  - Docs do not require Automattic-only infrastructure for self-hosted installs.
  - Markdown links resolve locally.
- **Depends on**: Task 8

### Task 10: End-to-end verification and SDD closeout
- **Status**: Not started
- **Files**:
  - Modify: `sdd/usage-metrics-adoption/requirements.md`
  - Modify: `sdd/usage-metrics-adoption/spec.md`
  - Modify: `sdd/usage-metrics-adoption/plan.md`
  - Create: `sdd/usage-metrics-adoption/implementation-notes.md`
- **Do**:
  1. Run the local verification suite: `composer run-script lint-php`, `composer run-script test-php`, `pnpm run format:check`, `pnpm run lint`, and the touched Playwright specs.
  2. Update task statuses with PR refs after each implementation PR lands.
  3. Capture deviations, privacy decisions, and upstream hook decisions in `implementation-notes.md`.
  4. Confirm no event payload includes content, DIDs, handles, URLs, raw errors, or raw federation payloads.
- **Verify**:
  - Verification commands pass.
  - SDD status fields match the top Progress checklist.
  - Implementation notes include final self-host consent posture and upstream PR links.
- **Depends on**: Tasks 1-9

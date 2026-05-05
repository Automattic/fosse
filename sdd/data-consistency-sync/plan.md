# Implementation Plan: Data Consistency & Sync

Based on: sdd/data-consistency-sync/spec.md

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

## Progress

- [ ] Task 1: Verify ActivityPub delete/update behavior and record upstream gaps
- [ ] Task 2: Add FOSSE queue/backoff data model tests
- [ ] Task 3: Implement FOSSE publish queue and send-status read model
- [ ] Task 4: Add admin-visible persistent failure reporting
- [ ] Task 5: Land upstream Atmosphere delete/update retry hooks
- [ ] Task 6: Land upstream Atmosphere image sanitization and size selection
- [ ] Task 7: Sync bundled upstream changes into FOSSE
- [ ] Task 8: Add lifecycle e2e coverage and status feed checks
- [ ] Task 9: Run verification and update SDD statuses

## Tasks

### Task 1: Verify ActivityPub delete/update behavior and record upstream gaps

- **Status**: Not started
- **Files**:
  - Read: `bundled/activitypub/includes/scheduler/class-post.php`
  - Read: `bundled/activitypub/includes/class-activitypub.php`
  - Read: `bundled/activitypub/includes/collection/class-outbox.php`
  - Create upstream tests in `Automattic/wordpress-activitypub` if a gap is found
  - Do not modify `bundled/activitypub/` by hand
- **Do**:
  1. Inspect ActivityPub's post scheduler for publish to update to trash/private/draft to permanent delete transitions.
  2. Confirm Delete supersedes pending Create/Update outbox items for the same object.
  3. Confirm hard delete has access to object identity before WordPress deletes post meta.
  4. If behavior is already correct, document the exact upstream files/methods in the implementation notes for this SDD.
  5. If behavior is not correct, open an upstream ActivityPub PR with failing tests first, then implementation.
- **Verify**:
  - `composer run-script test-php` in FOSSE still passes after any bundled refresh.
  - Upstream ActivityPub tests pass in the upstream repo if a PR is needed.
- **Depends on**: none

### Task 2: Add FOSSE queue/backoff data model tests

- **Status**: Not started
- **Files**:
  - Create: `src/class-publish-queue.php`
  - Create: `src/class-send-status.php`
  - Create: `tests/php/Publish_QueueTest.php`
  - Create: `tests/php/Send_StatusTest.php`
- **Do**:
  1. Write failing tests for `Publish_Queue` entry normalization: required keys, stable `id`, supported `network`/`operation`, compact `remote` data, autoload `false` option storage.
  2. Write failing tests for backoff: attempt 0 schedules `+1s`, attempt 1 schedules `+5s`, attempt 2 schedules `+30s`, attempt 3 becomes `failed`.
  3. Write failing tests for failure classification: retryable network/429/5xx; persistent auth/rate/schema/media errors.
  4. Write failing tests for `Send_Status` update/read shape, including `atproto.remote_uri` from `_fosse_atproto_uri`, fallback to Atmosphere meta, `for_post()` UI normalization, and the `fosse_send_status_for_post` filter seam.
  5. Run `composer dump-autoload && composer run-script test-php -- --filter 'Publish_Queue|Send_Status'` and confirm RED.
- **Verify**:
  - New tests fail because the classes do not exist yet.
- **Depends on**: Task 1

### Task 3: Implement FOSSE publish queue and send-status read model

- **Status**: Not started
- **Files**:
  - Modify: `src/class-publish-queue.php`
  - Modify: `src/class-send-status.php`
  - Modify: `fosse.php`
  - Modify: `tests/php/Publish_QueueTest.php`
  - Modify: `tests/php/Send_StatusTest.php`
- **Do**:
  1. Implement `Automattic\Fosse\Publish_Queue` with constants for `OPTION = 'fosse_publish_queue'` and `CRON_HOOK = 'fosse_process_publish_queue'`.
  2. Store the option with autoload `false`; keep entries keyed by stable job ID.
  3. Implement enqueue, mark retrying, mark sent, mark failed, supersede, due-entry selection, and pruning helpers.
  4. Implement a cron registration method that processes due entries behind a short lock.
  5. Implement `Automattic\Fosse\Send_Status` with `_fosse_send_status` post meta writes, reads, and `for_post()` normalization for UI consumers.
  6. Register `fosse_retry_publication` handling so failed `activitypub` or `atproto` statuses can enqueue durable retry work.
  7. Register queue cron hooks in `fosse.php` with the same `class_exists` guard pattern as existing projectors.
  8. Run `composer dump-autoload && composer run-script test-php -- --filter 'Publish_Queue|Send_Status'` and confirm GREEN.
  9. Run `composer run-script lint-php -- src/class-publish-queue.php src/class-send-status.php tests/php/Publish_QueueTest.php tests/php/Send_StatusTest.php fosse.php`.
- **Verify**:
  - Queue/status PHPUnit tests pass.
  - PHPCS passes on touched files.
- **Depends on**: Task 2

### Task 4: Add admin-visible persistent failure reporting

- **Status**: Not started
- **Files**:
  - Modify: `src/Admin/class-status-page.php`
  - Modify: `src/Admin/templates/status-page.php`
  - Modify: `src/Admin/class-status-formatter.php`
  - Modify: `tests/php/Admin/Status_FormatterTest.php`
  - Modify or create focused Status_Page tests under `tests/php/Admin/`
- **Do**:
  1. Add tests for rendering persistent queue failures without exposing raw tokens or full request payloads.
  2. Add formatter coverage for auth, rate-limit, schema, media, and permanent delete failure messages.
  3. Surface a compact "needs attention" state on the FOSSE Status page using `Publish_Queue` / `Send_Status` read methods.
  4. Keep composer-specific UI out of scope; only backend/admin status surfaces belong here.
  5. Run `composer run-script test-php -- --filter 'Status_Formatter|Status_Page|Publish_Queue|Send_Status'`.
  6. Run `composer run-script lint-php -- src/Admin/class-status-page.php src/Admin/templates/status-page.php src/Admin/class-status-formatter.php`.
- **Verify**:
  - Persistent failure messages are visible in admin status tests.
  - No secrets or raw payloads appear in rendered output.
- **Depends on**: Task 3

### Task 5: Land upstream Atmosphere delete/update retry hooks

- **Status**: Not started
- **Files**:
  - Upstream modify: `wordpress-atmosphere/includes/class-atmosphere.php`
  - Upstream modify: `wordpress-atmosphere/includes/class-publisher.php`
  - Upstream modify: `wordpress-atmosphere/includes/transformer/class-post.php`
  - Upstream tests in `wordpress-atmosphere/tests/` or the upstream equivalent
  - FOSSE does not modify `bundled/atmosphere/` until Task 7
- **Do**:
  1. Add upstream tests for `transition_post_status` / `wp_trash_post` scheduling delete, permanent delete capturing identifiers before meta removal, and delete failures leaving enough state for retry.
  2. Add upstream tests for hybrid update behavior: in-place `putRecord` for stable shape, delete/republish when topology changes, thread cardinality changes handled deterministically.
  3. Ensure delete accepts current Atmosphere meta and a fallback AT-URI parser compatible with `_fosse_atproto_uri` if FOSSE mirrors that state.
  4. Add retry/error hooks or return values FOSSE can consume without duplicating PDS calls.
  5. Open/land upstream PR(s), then record PR URLs in this plan when complete.
- **Verify**:
  - Upstream tests pass.
  - FOSSE has a clear integration seam for queue/status updates.
- **Depends on**: Task 3

### Task 6: Land upstream Atmosphere image sanitization and size selection

- **Status**: Not started
- **Files**:
  - Upstream modify: `wordpress-atmosphere/includes/transformer/class-post.php`
  - Upstream modify if needed: `wordpress-atmosphere/includes/class-api.php`
  - Upstream tests for image selection/sanitization
  - FOSSE does not modify `bundled/atmosphere/` until Task 7
- **Do**:
  1. Add upstream tests proving images over 1 MB choose the largest available derivative under cap in order: `large`, `medium_large`, `medium`.
  2. Add tests proving EXIF/GPS metadata is stripped by re-encoding before upload.
  3. Add tests proving no more than 4 images are uploaded for a post.
  4. Add tests proving cached blob refs are invalidated when source file mtime/checksum changes.
  5. Implement with `WP_Image_Editor` and a sanitized temporary/cache file; do not upload the original binary.
  6. Add filter hooks only if needed for site-specific image policy, keeping the secure default on.
- **Verify**:
  - Upstream image tests pass.
  - Oversized or unprocessable media produces a classified warning/error FOSSE can surface.
- **Depends on**: Task 3

### Task 7: Sync bundled upstream changes into FOSSE

- **Status**: Not started
- **Files**:
  - Modify via script only: `bundled/activitypub/` if Task 1 required upstream AP changes
  - Modify via script only: `bundled/atmosphere/`
  - Possibly modify: `composer.lock` only if the sync/build process changes root dependencies
- **Do**:
  1. Ensure upstream PRs needed by Tasks 5 and 6 are merged.
  2. Run `./tools/sync-bundled.sh` with source checkouts pointing at the merged upstream branches/tags.
  3. Do not hand-edit files under `bundled/`.
  4. Run `composer dump-autoload`.
  5. Run `composer run-script test-php`.
- **Verify**:
  - Bundled copies reflect upstream commits.
  - FOSSE PHPUnit passes after sync.
- **Depends on**: Task 5, Task 6

### Task 8: Add lifecycle e2e coverage and status feed checks

- **Status**: Not started
- **Files**:
  - Create: `tests/e2e/data-consistency-sync.spec.ts`
  - Possibly modify: `tests/e2e/mu-plugins/fosse-bsky-capture.php`
  - Possibly create: `tests/e2e/mu-plugins/fosse-sync-state.php`
  - Possibly modify: `tests/e2e/blueprint.json`
- **Do**:
  1. Extend the e2e mu-plugin harness to capture intended Bluesky XRPC calls without a live network dependency.
  2. Add a Playwright spec that publishes a post, edits it, trashes it, restores/republishes it, and permanently deletes it.
  3. Assert the captured sequence matches the hybrid policy: create, put/update or republish based on shape, delete on trash/delete.
  4. Assert `_fosse_send_status` or a REST/debug test endpoint exposes provider states the future composer can consume.
  5. Assert persistent failures become visible on the FOSSE Status page.
  6. Run `pnpm run test:e2e -- data-consistency-sync`.
- **Verify**:
  - New e2e spec passes locally.
  - The spec does not require real Bluesky credentials.
- **Depends on**: Task 7

### Task 9: Run verification and update SDD statuses

- **Status**: Not started
- **Files**:
  - Modify: `sdd/data-consistency-sync/plan.md`
  - Create later if allowed by the implementing worker: `sdd/data-consistency-sync/implementation.md`
- **Do**:
  1. Run required local checks: `composer run-script lint-php`, `composer run-script test-php`, `pnpm run format:check`, `pnpm run lint`.
  2. Run focused e2e: `pnpm run test:e2e -- data-consistency-sync`.
  3. Update the Progress checklist and each task's `**Status**` with the AGENTS.md Done status value and a commit or PR reference only after verification passes.
  4. If an implementation notes file is permitted for the implementing worker, capture upstream PR links, verification output, and deviations from this SDD.
- **Verify**:
  - Progress checklist mirrors per-task statuses.
  - All verification commands and upstream refs are recorded.
- **Depends on**: Task 8

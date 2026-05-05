# Data Consistency & Sync - Requirements

## Goal

Define how FOSSE keeps WordPress canonical while synchronizing outward to ActivityPub and Bluesky. This covers [DOTCOM-16798](https://linear.app/a8c/issue/DOTCOM-16798) and child issues [DOTCOM-16821](https://linear.app/a8c/issue/DOTCOM-16821), [DOTCOM-16822](https://linear.app/a8c/issue/DOTCOM-16822), [DOTCOM-16823](https://linear.app/a8c/issue/DOTCOM-16823), and [DOTCOM-16824](https://linear.app/a8c/issue/DOTCOM-16824).

WordPress is the source of truth. When a user publishes, edits, trashes, restores, permanently deletes, or changes media in WordPress, the federated copies should converge to the WordPress state. If a network-side copy diverges, v1 does not attempt multi-network conflict resolution; WordPress wins on the next outbound operation.

## Requirements

1. **WordPress-canonical model.** FOSSE treats WordPress post state as canonical and networks as projections. Backend status must describe each network projection without changing where canonical content lives.
2. **Delete sync for Bluesky.** When a published WordPress post transitions to `trash`, is permanently deleted, or is deleted after already leaving `publish`, Bluesky records created for that post must be deleted from the PDS. The delete path must use stored record identity, including `_fosse_atproto_uri` when present and the current Atmosphere record meta (`_atmosphere_bsky_uri`, `_atmosphere_bsky_tid`, `_atmosphere_bsky_thread_records`) as compatibility/current-source data.
3. **Permanent delete coverage.** Hard deletes must capture remote identifiers before WordPress removes post meta and child comments. `before_delete_post` is the likely hook; the SDD should also call out `post_delete`/`deleted_post` as too late for meta-dependent cleanup except as a defensive fallback.
4. **ActivityPub behavior confirmed.** The implementation must verify that bundled ActivityPub already emits/schedules Delete activities for WordPress trash/unpublish/delete transitions. If ActivityPub has a real gap, the fix goes upstream to `wordpress-activitypub`, not into `bundled/`.
5. **Edit-sync policy chosen.** The spec must pick a v1 policy among append-only, delete-and-republish, and hybrid. The policy must explain how short posts, long-form thread posts, media changes, object-type changes, and unsupported in-place mutations behave.
6. **Failure handling and retry queue.** Failed outbound operations must be recorded with enough state to retry. Backoff is `1s`, `5s`, `30s`; after those attempts, failures remain visible and retryable. The queue may use `fosse_publish_queue`, but the shape must be justified.
7. **Cron retry.** A WP-Cron worker retries due queue entries and updates per-network send state. It must avoid duplicate concurrent processing and must not publish/delete stale state that WordPress has since superseded.
8. **Admin-visible persistent failures.** Persistent authentication, rate-limit, schema/validation, and permanent deletion failures must surface in wp-admin notices or provider status surfaces. Notices must be actionable without exposing tokens or raw payload secrets.
9. **Image size handling for Bluesky.** Bluesky uploads are capped at 1 MB per image and 4 images per post. The implementation must choose an appropriate WordPress derivative under the cap (`large`, `medium_large`, then `medium`, falling back to a re-encoded derivative if needed) and skip extra images deterministically.
10. **EXIF stripping.** Images uploaded to Bluesky must be stripped of EXIF/GPS metadata for privacy and to reduce file size. The sanitized file, not the original upload, is what gets uploaded/cached.
11. **ActivityPub media coordination.** The SDD must confirm whether ActivityPub sends original media URLs, cached files, or transformed attachments. If EXIF/size behavior is post-type-agnostic and useful to standalone ActivityPub, coordinate upstream rather than adding a FOSSE-only workaround.
12. **Composer send-status feed.** Backend state from publish/update/delete/retry must be queryable by the future composer send-status UI ([DOTCOM-16805](https://linear.app/a8c/issue/DOTCOM-16805)). This SDD does not implement that UI.
13. **No edits to `bundled/`.** Upstream changes land in `wordpress-atmosphere` or `wordpress-activitypub`, then FOSSE consumes them through `tools/sync-bundled.sh`.

## Constraints

- FOSSE-specific policy belongs in FOSSE: canonical WordPress-wins status aggregation, queue presentation, and composer-facing status shape.
- Post-type-agnostic correctness belongs upstream: Atmosphere delete/update/retry/image-upload behavior and ActivityPub delete/media behavior should be fixed in their upstream repositories.
- Existing projector patterns should be followed for small FOSSE classes (`Object_Type`, `Post_Types`, `Long_Form_Strategy`, `Reactions_Label`).
- PHP code follows the Jetpack/WPCS ruleset, tabs, Yoda conditions, PHPDoc on public/protected methods, and text domain `fosse`.
- Tests should start in PHPUnit for pure queue/status policy, then use e2e/Playground only where WordPress lifecycle behavior needs a real boot.

## Out of Scope

- Multi-network conflict resolution beyond WordPress wins.
- Pulling edits from Bluesky, Mastodon, or any other network back into WordPress.
- A composer UI or block-editor UI for send status. This SDD only defines backend state the future UI can consume.
- Manual conflict merge tools.
- Rewriting bundled plugin code directly under `bundled/`.
- Changing the long-form Bluesky composition strategy itself; this SDD consumes whatever thread/single-record shape exists.
- Guaranteeing deletion of third-party replies, likes, or reposts on remote networks. FOSSE deletes records it created in the user's repository; remote reactions are controlled by their authors and networks.

## Relevant Code / Patterns Found

### FOSSE

- `fosse.php` registers FOSSE projectors and provider bootstrap.
- `src/class-object-type.php`, `src/class-post-types.php`, `src/class-long-form-strategy.php`, and `src/class-reactions-label.php` are the local pattern for small cross-network policy projectors.
- `src/Admin/class-bluesky-provider.php` reads Atmosphere connection state and renders FOSSE-owned Bluesky setup/status surfaces.
- `src/Admin/class-status-page.php` and `src/Admin/templates/status-page.php` are the likely home for persistent provider failure summaries until DOTCOM-16805 consumes the same data in the composer.

### Atmosphere / Bluesky

- `bundled/atmosphere/includes/class-atmosphere.php` wires `transition_post_status`, `before_delete_post`, async cron hooks such as `atmosphere_publish_post`, `atmosphere_update_post`, `atmosphere_delete_post`, and `atmosphere_delete_records`.
- `bundled/atmosphere/includes/class-publisher.php` owns `publish_post()`, `update_post()`, `delete_post()`, `delete_post_by_tids()`, comment delete cascades, and record-meta cleanup.
- `bundled/atmosphere/includes/transformer/class-post.php` defines `_atmosphere_bsky_uri`, `_atmosphere_bsky_tid`, `_atmosphere_bsky_cid`, `_atmosphere_bsky_thread_records`, and `upload_thumbnail()`.
- Current `upload_thumbnail()` checks the original file and falls back to `large` when the original exceeds 1 MB; it does not by itself prove EXIF stripping or best-derivative selection across `large` / `medium_large` / `medium`.

### ActivityPub

- `bundled/activitypub/includes/scheduler/class-post.php` schedules ActivityPub Create/Update/Delete activities on post status transitions.
- `bundled/activitypub/includes/class-activitypub.php` hooks `wp_trash_post` / `untrash_post` to preserve and clear canonical URL state around trash.
- `bundled/activitypub/includes/collection/class-outbox.php` models Delete as superseding pending Create/Update activities for the same object.
- Any confirmed gap in these behaviors should be patched upstream in `wordpress-activitypub` and pulled into FOSSE later.

## Open Questions To Resolve In Spec

- Should FOSSE introduce `_fosse_atproto_uri` as a canonical mirror, or treat it as compatibility/future composer state while Atmosphere remains the source of record identifiers?
- Should retry queue state live in a single `fosse_publish_queue` option, per-post meta, or a custom post type/table?
- Which failures are retryable vs immediately persistent: network timeout, 429, 5xx, expired token, invalid grant, schema validation, missing record, and oversized image?
- How much image handling should be upstream Atmosphere now vs FOSSE-specific policy for the composer?

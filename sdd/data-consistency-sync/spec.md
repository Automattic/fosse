# Spec: Data Consistency & Sync

## Goal

Make WordPress the canonical state machine for outbound federation. A WordPress post's publish/edit/trash/delete/media state drives ActivityPub and Bluesky projections; backend status records what happened and what still needs retry. v1 explicitly does not resolve conflicts between networks. If WordPress and a remote copy disagree, the next outbound WordPress action overwrites or deletes the remote projection.

## Requirements Summary

- Delete Bluesky records on trash/unpublish/permanent delete using stored record identity.
- Confirm ActivityPub already sends equivalent Delete activities; fix upstream if not.
- Use a chosen edit policy, not a menu of options.
- Persist failed operations into a retry queue with `1s`, `5s`, `30s` backoff and admin-visible persistent failures.
- Strip EXIF and keep Bluesky image uploads under 1 MB, max 4 images per post.
- Expose backend send state for the future DOTCOM-16805 composer send-status UI, without building that UI here.
- Keep upstream-generic correctness upstream; keep FOSSE policy/status aggregation in FOSSE.

## Chosen Edit Strategy

**Recommendation: hybrid edit sync.**

Append-only is rejected because it leaves stale copies in remote feeds and breaks the WordPress-canonical promise. Delete-and-republish for every edit is also rejected because it unnecessarily resets Bluesky recency, loses reply continuity, and creates noisy ActivityPub behavior for simple typo fixes.

The v1 policy:

- **Stable single-record Bluesky edits:** update in place with `com.atproto.repo.putRecord` when the record collection, rkey, and shape can remain stable.
- **Stable thread edits:** update existing thread records in place when the thread cardinality and ordering remain compatible with the newly composed output. Extra old records are deleted if the new thread is shorter; missing new records are created as replies when the new thread is longer.
- **Topology-changing edits:** delete and republish when the WordPress edit changes the outbound topology in a way that cannot be safely mapped in place. Examples: object type changes short-form to long-form, long-form strategy changes single-record to thread, media/embed shape changes beyond what a record can update safely, or the stored thread metadata is incomplete.
- **ActivityPub edits:** rely on ActivityPub's normal Update/Delete outbox pipeline. If confirmation shows AP lacks a specific status transition, fix that upstream.
- **User-facing semantics:** in wp-admin and the future composer status UI, call topology-changing edits "republish" rather than "edit" because they can orphan Bluesky reply chains and reset feed recency.

This keeps common edits quiet while preserving a deterministic fallback when the remote record graph no longer matches WordPress.

## Delete Sync

### Bluesky

Bluesky delete sync belongs primarily upstream in `wordpress-atmosphere` because standalone Atmosphere sites have the same correctness requirement. FOSSE should not implement a parallel PDS delete shim if Atmosphere can own it.

The delete worker must support both current Atmosphere meta and FOSSE compatibility state:

| State | Purpose |
|-------|---------|
| `_fosse_atproto_uri` | FOSSE/composer-facing canonical AT-URI mirror when present. Used to display and recover send state. |
| `_atmosphere_bsky_uri` / `_atmosphere_bsky_tid` / `_atmosphere_bsky_cid` | Current Atmosphere root record identity. |
| `_atmosphere_bsky_thread_records` | Ordered list of Bluesky records for thread strategies. |
| `_atmosphere_doc_uri` / `_atmosphere_doc_tid` / `_atmosphere_doc_cid` | `site.standard.document` identity. |

For a trashed post, `transition_post_status` from `publish` to `trash` and/or `wp_trash_post` should schedule a delete job that calls the same underlying delete path as permanent deletion. For a hard delete, `before_delete_post` captures TIDs/URIs before WordPress removes post meta and comments, then schedules a cron job that can delete after the row is gone. `post_delete` / `deleted_post` are too late for primary cleanup because meta may already be unavailable; they are acceptable only as defensive logging or to mark a previously captured queue entry as no longer resolvable.

Implementation should prefer deleting by rkey/TID where available because `com.atproto.repo.deleteRecord` and `applyWrites#delete` require `collection` + `rkey`. If only `_fosse_atproto_uri` exists, parse the AT-URI to recover repository DID, collection, and rkey, then call `com.atproto.repo.deleteRecord` for that stored record; reject malformed URIs into a persistent schema failure rather than guessing.

### ActivityPub

The expected AP behavior is:

- publish to Create/Announce-style outbound activity as configured by ActivityPub.
- edit while publish remains publish to Update activity.
- publish to trash/draft/private to Delete activity when the object was previously federated.
- permanent delete to Delete activity or pre-delete outbox item while object identity is still available.
- pending Create/Update items for the same object should be superseded by Delete.

The plan includes a verification task against bundled ActivityPub. If ActivityPub misses one of these states, the fix is upstream in `wordpress-activitypub`; FOSSE only documents the required behavior and refreshes `bundled/` after upstream lands.

## Retry Queue And Failure State

### Queue Shape

Use a FOSSE-owned option named `fosse_publish_queue`, stored with autoload `false`, for v1. A custom table is premature for the current plugin scale, and per-post meta alone cannot represent hard-delete work after the post is gone. The option is acceptable if entries are compact, keyed, and pruned. If queue volume becomes a problem, the same entry shape can move to a custom table later.

Entry shape:

| Field | Meaning |
|-------|---------|
| `id` | Stable hash of network + operation + object type + object ID or captured URI. |
| `network` | `activitypub` or `atproto`. |
| `operation` | `publish`, `update`, `republish`, or `delete`. |
| `object_type` | `post`, `comment`, `document`, or provider-specific subtype. |
| `object_id` | WordPress ID when the object still exists; `0` for captured hard-delete-only jobs. |
| `remote` | Captured AT-URI/TID/outbox ID values needed when the object no longer exists. |
| `attempts` | Number of attempts already made. |
| `next_run_gmt` | UTC timestamp when cron may retry. |
| `last_error` | Sanitized `code`, `message`, `classification`, and timestamp. |
| `status` | `queued`, `retrying`, `failed`, `sent`, or `superseded`. |
| `state_version` | Monotonic version/hash of the WordPress source state that created the job. |

The queue stores post IDs as requested by DOTCOM-16823, but it does not store only post IDs. It also stores operation, network, remote identifiers, error classification, and source-state version so cron can avoid replaying stale work.

### Backoff And Cron

Backoff schedule is exactly `1s`, `5s`, `30s`. After the third failed attempt, the entry remains in `failed` status until a later WordPress action supersedes it or an admin/user triggers a manual retry. Transient network failures, 429 rate limits with no `Retry-After`, and 5xx failures are retryable. `Retry-After` may push `next_run_gmt` later than the fixed sequence, but the attempt labels still use the fixed schedule.

Authentication failures, invalid grants, permission failures, malformed AT-URIs, schema validation errors, and persistent oversized media are not blindly retried after classification. They become persistent failures with an admin-visible notice because repeating them will not converge without user or code action.

Cron hook: `fosse_process_publish_queue`.

The worker:

1. Acquires a short lock option/transient before processing.
2. Loads due entries from `fosse_publish_queue`.
3. Re-reads WordPress state when `object_id` still exists and skips/supersedes work if the source state has changed.
4. Delegates network-specific work to upstream provider APIs where possible.
5. Updates queue entries and per-post send state after each attempt.
6. Prunes `sent` and `superseded` entries after a short retention window, while keeping failed entries visible.

## Send State For DOTCOM-16805

Add a read model separate from the queue: `_fosse_send_status` post meta for existing posts, plus captured queue entries for hard-deleted objects. The composer UI can later read this data without needing to understand Atmosphere internals.

Suggested per-post shape:

| Field | Meaning |
|-------|---------|
| `activitypub.status` | `not_configured`, `pending`, `sent`, `retrying`, `failed`, `deleted`, or `skipped`. |
| `activitypub.message` | Sanitized human-readable summary. |
| `activitypub.updated_gmt` | Last state change. |
| `atproto.status` | Same status enum for Bluesky. |
| `atproto.remote_uri` | Root AT-URI, usually mirrored from `_fosse_atproto_uri` / Atmosphere meta. |
| `atproto.remote_url` | Optional bsky.app URL derived from AT-URI. |
| `atproto.next_retry_gmt` | Present only while queued/retrying. |
| `atproto.error_code` | Sanitized last error code when failed. |

This SDD does not create the composer UI. It only ensures publish/update/delete/retry code writes a stable backend state that DOTCOM-16805 can consume.

Add a UI-facing facade on top of the post meta shape:

```php
$statuses = Automattic\Fosse\Send_Status::for_post( $post_id );
$statuses = apply_filters( 'fosse_send_status_for_post', $statuses, $post_id );
```

The facade returns a normalized array keyed by `activitypub` and `atproto`, using the status enum above. UI consumers should treat `atproto` as the Bluesky row label, but must not create a second status taxonomy for Bluesky. A retry request uses `do_action( 'fosse_retry_publication', $post_id, $network )`, where `$network` is `activitypub` or `atproto`; the queue layer decides whether the retry is allowed and schedules durable work.

## Image Upload Policy

Bluesky limits embedded images to 4 images per post and 1 MB per image. v1 behavior:

- Select at most 4 images in deterministic WordPress order: featured image first for link-card/document cover contexts, then attached/embedded images if the upstream composition supports image embeds.
- For each image, choose the largest available derivative under 1 MB in this order: `large`, `medium_large`, `medium`.
- If no existing derivative is under 1 MB, create a temporary sanitized derivative with `WP_Image_Editor`, reducing dimensions/quality until under cap or until a lower bound is reached.
- Always strip EXIF/GPS metadata by re-encoding through the image editor before upload, even if the selected derivative is already under 1 MB.
- Cache blob references against a key that includes attachment ID, source file modification time, selected size, and sanitized-file checksum. A cached blob for an old source image must not be reused after media replacement.
- If an image cannot be made valid under 1 MB, skip that image, record a send-status warning, and keep publishing the post without that image unless the schema requires it.

This image behavior belongs upstream in `wordpress-atmosphere` because any Atmosphere site uploading images to Bluesky needs the privacy and size guarantee. FOSSE's role is to consume the upstream behavior, surface warnings in `_fosse_send_status`, and coordinate ActivityPub behavior.

ActivityPub coordination requirement: inspect the ActivityPub media pipeline before implementation. If ActivityPub exposes original media URLs rather than uploading binaries, the EXIF risk is site-media-publication policy rather than AP transport. If ActivityPub transforms/uploads media and can leak EXIF, file an upstream ActivityPub issue/PR; do not add a FOSSE-only fork of AP media handling.

## Ownership

| Concern | Owner |
|---------|-------|
| Atmosphere delete/update correctness, thread delete, image upload sanitization, retryable PDS errors | Upstream `wordpress-atmosphere` |
| ActivityPub Delete/Update scheduler gaps, AP media EXIF/size behavior | Upstream `wordpress-activitypub` |
| FOSSE WordPress-wins policy, `_fosse_send_status`, `fosse_publish_queue`, admin notices/status aggregation | FOSSE |
| Bundled refresh after upstream merges | FOSSE via `tools/sync-bundled.sh` |

## Verification Strategy

- PHPUnit for queue shape, backoff, failure classification, send-status updates, AT-URI parsing, and image-size selection helpers.
- PHPUnit or upstream unit tests for Atmosphere delete/update/image behavior where the logic can be isolated.
- FOSSE PHP tests for projector/status/queue classes under `tests/php/`.
- Playwright e2e only for lifecycle smoke coverage that requires a real WordPress boot: publish, edit, trash, delete, and status visibility.
- No network-dependent live Bluesky tests in CI. Use stubs/mu-plugins or upstream API seams to capture intended XRPC calls.

## Known Limitations

- v1 cannot delete third-party replies to a Bluesky thread; it only deletes records created in the connected repo.
- Hybrid edit sync can still republish in topology-changing cases, which can orphan old Bluesky replies. The status UI must be honest about this.
- `fosse_publish_queue` as an option is not an infinite queue. It is suitable for bounded v1 state and should be revisited if entries grow large.
- Persistent auth/schema/media failures require user/admin action; cron will not make them converge by retrying forever.

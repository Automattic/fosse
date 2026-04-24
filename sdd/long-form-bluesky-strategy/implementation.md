# Long-Form Bluesky Strategy — Implementation Notes

Living record of what was actually shipped while executing [`plan.md`](./plan.md). Captures PR links, deviations from the plan with rationale, and any implementation-time discoveries that should inform the remaining tasks.

## Status snapshot (2026-04-23)

| Task | Status | PR |
|------|--------|----|
| Task 1 [UPSTREAM-AT] | Code + tests pushed; PR kept as draft | [Automattic/wordpress-atmosphere#34](https://github.com/Automattic/wordpress-atmosphere/pull/34) |
| Task 2 [FOSSE] | Blocked on Task 1 merge | — |
| Task 3 [FOSSE] | Blocked on Task 2 | — |
| Task 4 [FOSSE] | Blocked on Task 3 | — |
| Task 5 [FOSSE] | AGENTS.md worked example shipped; readme.txt step skipped (see below) | [Automattic/fosse#28](https://github.com/Automattic/fosse/pull/28) |

Both FOSSE PRs (this SDD at [#24](https://github.com/Automattic/fosse/pull/24) and the docs branch at [#28](https://github.com/Automattic/fosse/pull/28)) and the upstream PR are deliberately staying as drafts — no external reviewers pinged.

## Task 1 — upstream PR shape as shipped

Five commits on [`add/long-form-teaser-thread`](https://github.com/Automattic/wordpress-atmosphere/tree/add/long-form-teaser-thread):

1. `fa9408d` — Composition methods + `atmosphere_long_form_composition` filter + `META_THREAD_RECORDS` constant + `atmosphere_pre_apply_writes` test seam.
2. `5c9d20a` — 16 composition unit tests + tiny `atmosphere_long_form_strategy_downgraded` observability action for the empty-body fallback path.
3. `6581a0f` — Publisher rewrite: sequential-writes-with-rollback, partial-meta anchoring, legacy fallback on update/delete.
4. `dd2cc53` — 10 Publisher tests exercising the thread, rollback, rollback-of-rollback, update-in-place vs. delete-and-republish, and delete paths via the new test seam.
5. `821f38f` — jetpack-changelogger entry.

`composer lint` is clean. `composer test` requires a live WP test DB that the local environment doesn't have stood up; full PHPUnit runs land on upstream CI when the PR is moved out of draft.

## Deviations from the plan

Applied while implementing; each is minor and documented in the relevant commit message.

### 1. Test seam filter added on `API::apply_writes` (new)

**Plan said:** Publisher tests intercept via `add_filter( 'pre_http_request', … )`.

**Problem:** `pre_http_request` fires inside `wp_remote_request`, which is reached only after `API::request()` has (a) decrypted the access token, (b) read the DPoP JWK from options, and (c) built a DPoP proof. The existing test bootstrap seeds `'encrypted-token'` / `'encrypted-jwk'` — literal strings that `Encryption::decrypt()` rejects, so the call returns `WP_Error` long before `pre_http_request` ever runs. The existing `test_update_sends_update_writes` documents this with an `if null !== captured_body / else assertWPError` fork — useful for smoke-testing but not for exercising the thread sequential-write flow.

**Fix:** Added `atmosphere_pre_apply_writes` filter at the top of `API::apply_writes`. Returning a non-null value short-circuits the PDS round-trip. Used by the new Publisher tests and usable by the FOSSE e2e harness (Task 4) as a cleaner alternative to `pre_http_request` there too.

**Net addition:** one filter, ~20 lines. Documented in Commit 1's message.

### 2. New public method `Post::is_short_form_post()`

**Plan said:** `Publisher` branches on short vs. long via `apply_filters( 'atmosphere_is_short_form_post', Post::is_short_form( $post ), $post )`.

**Problem:** `Post::is_short_form()` is `private` upstream. Exposing it to Publisher either requires making it public (changes encapsulation for a discriminator that's really a transformer-internal concern) or duplicating the filter call inside Publisher (creates two places where the filter gets applied, easy to drift).

**Fix:** Added `Post::is_short_form_post(): bool` as a thin instance method that wraps the private discriminator plus the `atmosphere_is_short_form_post` filter. Publisher calls `$transformer->is_short_form_post()` to branch. `transform()` is unchanged (still inlines the filter call) to match the plan's "leave transform() alone" directive.

### 3. `Facet::extract()` signature correction

**Plan said:** `Facet::extract( $text, $this->object )` — two args.

**Reality:** Upstream `Facet::extract( string $text ): array` takes one. The plan's second arg doesn't exist.

**Fix:** Dropped the phantom second arg. The intent ("extract facets against each record's own text") is preserved.

### 4. Extended existing `class-test-publisher.php` instead of creating one

**Plan said:** `tests/phpunit/tests/class-test-publisher.php — new file.`

**Reality:** The file already exists upstream (227 lines, 6 tests). Creating a new one would overwrite; creating a parallel file would duplicate the class name.

**Fix:** Extended the existing file. Added 10 new tests + shared helpers (`mock_response`, `register_capture`, `$captured_calls`, `$fail_call_indexes`) alongside the existing 6. Tear-down extended to clear the new filters.

### 5. Changelog via jetpack-changelogger, not `readme.txt`

**Plan said:** "In `readme.txt` Changelog (unreleased), describe the new filter + methods."

**Reality:** Upstream switched to the jetpack-changelogger convention — one file per PR in `.github/changelog/<slug>` with `Significance:` / `Type:` / message. The `readme.txt` changelog section is rebuilt from those files by the `changelog:write` composer script, not hand-edited.

**Fix:** Created `.github/changelog/long-form-teaser-thread` instead. Same content, correct shape for the CI workflow.

### 6. FOSSE-side `readme.txt` step skipped

**Plan said:** Add a `readme.txt` changelog entry in FOSSE describing `fosse_long_form_strategy` and the `teaser-thread` side effects.

**Reality:** FOSSE does not have a `readme.txt`. Nothing in `bin/build-zip.sh`, `composer.json`, or `.github/workflows/` references one. The plan's assumption ("upstream convention") was extrapolated from wordpress-atmosphere, which is distributed via WP.org; FOSSE isn't.

**Fix:** Shipped only the AGENTS.md worked example for Task 5 (Automattic/fosse#28). Creating a `readme.txt` from scratch would be a separate decision about FOSSE's own distribution shape — it can happen later if and when FOSSE starts shipping through the WP.org plugin directory.

### 7. Added observability action `atmosphere_long_form_strategy_downgraded`

**Plan said:** Empty-body guard emits an `error_log` notice.

**Addition:** Plus a `do_action( 'atmosphere_long_form_strategy_downgraded', $post, $requested, $effective )` in the same branch. Tests assert the fallback via `did_action`-style counting (cleaner than parsing `error_log` output), and ops teams get a hookable signal for dashboards.

**Net addition:** one-line `do_action`. Filed as part of Commit 2 (the tests commit) rather than a separate commit.

## Gaps intentionally left for follow-up

These are real seams that the plan didn't specify; calling them out explicitly so they don't get lost.

### `on_before_delete` / `atmosphere_delete_records` cron still single-TID

`class-atmosphere.php`'s `on_before_delete` captures exactly one bsky TID + one doc TID before scheduling the `atmosphere_delete_records` cron, which calls `Publisher::delete_by_tids(string $bsky_tid, string $doc_tid)`. For a thread-strategy post that is **force-deleted** (permanent delete that bypasses trash → untrash flow), this captures only the root TID — thread replies remain on the PDS as orphans.

The `Publisher::delete()` path (triggered by `transition_post_status` → trash) handles threads correctly because it reads `META_THREAD_RECORDS` directly. Only the force-delete-bypass-trash path is affected.

**Fix shape** (follow-up, not in scope for the current Task 1 PR): extend `on_before_delete` to read `META_THREAD_RECORDS` and schedule a new `atmosphere_delete_thread_records` cron hook with `(array $bsky_tids, string $doc_tid)`. Keep the legacy `atmosphere_delete_records` hook registered so any queued events from before the upgrade still drain.

### `createdAt` behavior change for `'link-card'` single-record long-form

**Old behavior:** `Transformer\Post::transform()` set `createdAt` to `to_iso8601( $post->post_date_gmt )` — i.e. the WP post's published date.

**New behavior (Commit 3):** The long-form path uses `build_long_form_records()`, which intentionally omits `createdAt`. Publisher stamps `wp_date( 'c' )` (current time) in `publish_single()` when the record doesn't already carry one.

Net effect: a link-card publish's `createdAt` is now the sync time, not the WP post's date. Matters for backfill — bulk-syncing old posts will re-date them to "now" on Bluesky.

**Scope:** Short-form (via `transform()`'s output) is unchanged because `transform()` still sets `createdAt` itself — `publish_single()` only fills when empty. Thread root (overwritten to `wp_date('c')` by `publish_thread()` explicitly) matches the plan's intent. Only the single-record long-form case changed.

If preserving `post_date_gmt` for link-card matters, the fix is a 1-line addition in `Post::record_for_link_card()` to set `createdAt` from `post_date_gmt`, matching `transform()`'s behavior. Flagging here so it can be decided during upstream review.

### Tests not runnable locally

Task 1's PHPUnit tests run against a WP test environment (`bin/install-wp-tests.sh`) that requires a local MySQL with a pre-seeded database. Stood up only in CI. **All assertions are derived by reasoning about the code, not executed.** Upstream CI on [#34](https://github.com/Automattic/wordpress-atmosphere/pull/34) is the first real validation.

`composer lint` + `php -l` are clean across every changed file.

## Notes for the remaining tasks

### Task 2 (bundled refresh)

Nothing special — the upstream PR added files that will flow through `tools/sync-bundled.sh` cleanly. Sanity-check greps in the plan (`atmosphere_long_form_composition`, `build_long_form_records`, `build_teaser_thread`, `META_THREAD_RECORDS`, `atmosphere_bsky_thread_records`) all apply. Add one more: `grep -n atmosphere_pre_apply_writes bundled/atmosphere/includes/class-api.php` — the new test seam should survive the refresh too, since the FOSSE e2e rewrite (Task 4) will want it.

### Task 3 (`Long_Form_Strategy` projector)

No deviation needed. Pattern from `src/class-object-type.php` maps directly. Plan's enum (`teaser-thread`, `truncate-link`, `link-card`, `document-card`) matches what the upstream filter accepts; unknown values fall through to link-card on the upstream side, so the projector's coerce-to-default is FOSSE opinion only, not a correctness constraint.

### Task 4 (e2e rewrite)

**Good news:** Because Task 1 added `atmosphere_pre_apply_writes`, the mu-plugin rewrite is cleaner than the plan described. The plan said hook `pre_http_request`, identify `applyWrites`-bound calls, parse the body, and mock a response. With the new filter, the mu-plugin can hook one place (`atmosphere_pre_apply_writes`) and receive the `$writes` array already-parsed, no URL matching or body JSON-decoding needed.

**Revised shape:**
```php
add_filter(
    'atmosphere_pre_apply_writes',
    function ( $short_circuit, array $writes ) {
        // ... accumulate $writes into the capture file, synthesize a
        // mock response (see Test_Publisher::mock_response() in
        // wordpress-atmosphere for the canonical shape).
    },
    10,
    2
);
```

The existing `long-form-link-card.spec.ts` still works against a `calls[0].writes` shape — only the mu-plugin changes, not the spec contract.

### Task 5 (docs)

Already shipped (AGENTS.md worked example, PR #28). If `readme.txt` is revived as a deliverable, that's a separate decision — see deviation #6.

## Appendix: artifacts to look at on the next session

- Upstream PR: [Automattic/wordpress-atmosphere#34](https://github.com/Automattic/wordpress-atmosphere/pull/34)
- FOSSE docs PR: [Automattic/fosse#28](https://github.com/Automattic/fosse/pull/28)
- FOSSE SDD PR (this branch): [Automattic/fosse#24](https://github.com/Automattic/fosse/pull/24)
- Linear umbrella: [DOTCOM-16810](https://linear.app/a8c/issue/DOTCOM-16810) (Todo; not yet moved to In Progress)

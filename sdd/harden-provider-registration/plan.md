---
status: planning
---

# Harden Third-Party Provider Registration

**Linear:** [DOTCOM-17104](https://linear.app/a8c/issue/DOTCOM-17104/harden-third-party-provider-registration-for-future-standalone)
**GitHub:** [Automattic/fosse issue 127](https://github.com/Automattic/fosse/issues/127)

**Goal:** Make `fosse_register_providers` a stable extension point for standalone provider plugins. Today, FOSSE calls `Provider_Loader::boot()` synchronously during `fosse.php` load, so any plugin loaded alphabetically after FOSSE misses the registration window.

**Out of scope:** Shipping a real standalone provider; adding helper APIs (`fosse_register_provider()` wrapper, auto-discovery from a folder, etc.); any UI changes. We only harden the seam.

## Progress

- [x] Task 1: Failing test coverage
- [x] Task 2: Defer boot to `plugins_loaded`
- [x] Task 3: Idempotent boot + docs
- [x] Task 4: Connection_Provider interface docblock
- [ ] Task 5: Lint, full test pass, commit (rolling — this branch)

---

## Multi-angle analysis

### Angle 1 — Mechanical (what's the minimum change?)

Today (`fosse.php:328-333`):

```php
if ( class_exists( \Automattic\Fosse\Provider_Loader::class ) ) {
    \Automattic\Fosse\Admin\AP_Provider::init();
    \Automattic\Fosse\Admin\Bluesky_Provider::init();
    \Automattic\Fosse\Provider_Loader::boot();
}
```

This runs at *file include* time. Any plugin whose main file hasn't been executed yet — i.e. anything WordPress will load after `fosse/fosse.php` in `active_plugins` order — cannot have hooked `fosse_register_providers` in time.

Smallest mechanical fix: wrap the block in `add_action( 'plugins_loaded', … )`. After all plugin main files have run, fire the action, then drive the registry.

### Angle 2 — Timing (why `plugins_loaded`, not `init`?)

The user's hunch was "hooked at `init` instead of on load." The codebase argues against `init`:

- `Bluesky_Provider::register_hooks()` calls `add_action( 'init', …, 1 )` to serve the `/.well-known/atproto-did` route. If we boot the registry *on* `init` (at any priority ≥ 1), that priority-1 callback never registers — `init` priority 1 has already passed by the time the boot callback runs.
- `Bluesky_Provider::register_hooks()` also calls `add_action( 'admin_init', … )` for the OAuth callback handler. `admin_init` fires inside `init`, so booting on `init` priority ≥ 10 risks the same class of issue if other providers register lower priorities.
- REST routes register on `rest_api_init`, which fires from `init` as well. A future REST-route provider would have the same constraint.

`plugins_loaded` is the right hook:

- All plugin main files have run by the time `plugins_loaded` fires (including third-party provider plugins, which would `add_action( 'fosse_register_providers', … )` from their own main file).
- It still runs before `init`, `admin_init`, and `rest_api_init`, so providers can register hooks at any of those.
- It is the WordPress-conventional "all plugins loaded; safe to wire cross-plugin things" hook.

**Priority:** 20. Initially chose default (10) but bumped after PR review (codex + ce-adversarial) flagged that a third-party add-on deferring to `plugins_loaded` *without* specifying a priority would land at the same priority (10) as FOSSE's boot and lose the same-priority race if WP loads it after FOSSE. Priority 20 gives third parties a 19-priority window above `plugins_loaded`'s `add_action` default. FOSSE itself is loaded by `wp-content/mu-plugins/fosse-loader.php` on `plugins_loaded` priority 8 on wpcom Simple; booting at priority 20 still runs in the same `plugins_loaded` iteration, after the loader, and well before `init`.

### Angle 3 — wpcom Simple load contract

`fosse.php` documents that on wpcom Simple, FOSSE is loaded inside `plugins_loaded` priority 8 by `fosse-loader.php`. Booting providers on `plugins_loaded` priority 20 fires inside the same action loop, 12 priorities later. Third-party providers in mu-plugins or regular plugins have already run their main files and can have hooked `fosse_register_providers`. No new wpcom-specific work needed.

### Angle 4 — Idempotency

Acceptance criterion: "`Provider_Loader::boot()` is safe to call more than once in a request."

Two failure modes:

1. A defensively-coded third-party plugin calls `Provider_Loader::boot()` itself (mirroring FOSSE's own behavior). Without guarding, every provider gets `register_hooks()` called twice → every `add_action`/`add_filter` registers twice → hooks fire twice.
2. Some integration test resets WP state and re-bootstraps the plugin in the same PHP process.

Simplest guard — a one-shot static flag on `Provider_Loader`:

```php
private static bool $booted = false;

public static function boot(): void {
    if ( self::$booted ) {
        return;
    }
    self::$booted = true;

    do_action( 'fosse_register_providers' );

    foreach ( Connection_Provider_Registry::get_providers() as $provider ) {
        if ( $provider->is_available() ) {
            $provider->register_hooks();
        }
    }
}

public static function reset(): void {
    self::$booted = false;
}
```

`reset()` is for test fixtures only. The existing `Connection_Provider_Registry::reset()` already follows this pattern.

`Connection_Provider_Registry::register()` is already idempotent on duplicate slugs (`onboarding-setup-ux/spec.md` design: "first wins"), so providers self-registering twice on a duplicate `fosse_register_providers` fire is also safe. The flag is belt-and-suspenders.

### Angle 5 — Discoverability / docs

The issue calls out: "Document `fosse_register_providers` as the provider registration hook for add-ons."

Two surfaces to touch:

- `class-provider-loader.php`'s docblock on `boot()` already names the hook for in-tree readers. Expand it to a short third-party recipe (one paragraph) showing the `add_action( 'fosse_register_providers', … )` call site and pointing at `Connection_Provider_Registry::register()` plus the `Connection_Provider` interface.
- `interface-connection-provider.php` should get a class-level docblock pointing add-on authors at the hook. This is the file a third-party developer reading the codebase will land on first.

No README changes — that file is product-facing, not developer-facing. The internal docblocks are the right surface for an extension point this niche.

### Angle 6 — CEO / scope-expansion angle

Should this issue grow? Candidates considered and **rejected**:

- **A helper function `fosse_register_provider( Connection_Provider $p )`**. A one-line convenience over `Connection_Provider_Registry::register( $p )`. Premature: we don't yet have *any* external provider to know what the ergonomic pain point is. Add when n=1 standalone exists.
- **Auto-discovery from a `fosse-providers/` directory or composer plugin type**. Nice-sounding, but no one has asked for it and it widens FOSSE's surface area (security review, ordering rules, etc.) for a hypothetical use case.
- **Promoting `Connection_Provider` to a SemVer-stable public API**. The interface is small and stable, but committing to BC guarantees costs us future flexibility on Setup/Status page rendering signatures. Document the hook; defer the BC commitment.

The issue itself records this is "not urgent … records a platform-hardening gap." Resist scope expansion. Land the smallest correct change.

### Angle 7 — Simplicity / YAGNI

What can be cut from the smallest correct version?

- `reset()` on `Provider_Loader` exists only for tests. Keep it — without it the idempotency test can't reliably re-run.
- Docs in `interface-connection-provider.php` is a few lines and is the natural landing spot.
- The `plugins_loaded` wrap is unavoidable — that's the whole point.

Cuttable: README-level docs (we already publish enough of an entry point in the interface docblock). Skipped.

### Angle 8 — Failure-mode review

What could a third-party plugin still get wrong after this lands?

1. Hook `fosse_register_providers` from a callback that itself runs *after* `plugins_loaded` priority 20. E.g. registering inside `init` priority 5. → Document that the hook must be registered from the plugin's main file or no later than `plugins_loaded` priority 19.
2. Register a provider whose `is_available()` returns true but whose dependencies aren't actually loaded. → That's the third-party's problem, and `is_available()` is already the contract for it.
3. Register a provider that depends on a class autoloaded only on `init`. → Same as (1); document the timing contract.

The docblock recipe should call out (1) explicitly.

---

## Chosen approach

1. **Defer the existing boot block to `plugins_loaded` priority 20.** Wrap the `AP_Provider::init() / Bluesky_Provider::init() / Provider_Loader::boot()` trio in `add_action( 'plugins_loaded', …, 20 )`. Initial implementation used priority 10; PR review (codex + ce-adversarial) bumped it to 20 to clear WP's default `add_action` priority.
2. **Make `Provider_Loader::boot()` idempotent** with a `$booted` flag and a `reset()` method for tests.
3. **Document the hook** in `Provider_Loader::boot()`'s docblock and on the `Connection_Provider` interface — including the timing contract (register the callback from the plugin's main file or no later than `plugins_loaded` priority 19).
4. **Add two tests** to `Provider_LoaderTest`: external-provider-via-hook registers and is bootable; double `boot()` does not double-register hooks.

The existing `Connection_Provider_Registry::register()` "first-wins" behavior is unchanged. `AP_Provider` and `Bluesky_Provider` keep their `init()` methods, which still register on `fosse_register_providers` — they just fire one tick later in the request lifecycle.

### Why the user's hypothesis (move to `init`) doesn't work

Bluesky's `init` priority-1 well-known-route hook would silently fail to register if boot moved to `init`. `plugins_loaded` is the next-earliest hook that solves the load-order problem without breaking existing provider hooks.

---

## Tasks

### Task 1 — Add failing test coverage

- **Status**: ✅ Done (3011461)
- **Files**:
  - Modify: `tests/php/Provider_LoaderTest.php`
- **Do**:
  1. Add `test_external_provider_registered_via_hook_is_available_after_boot()`. Define a small in-test class implementing `Connection_Provider` (slug `'test-external'`, `is_available()` → true, `register_hooks()` no-op, etc.). Hook `fosse_register_providers` with a closure that calls `Connection_Provider_Registry::register()` on a new instance. Call `Provider_Loader::boot()`. Assert `Connection_Provider_Registry::get_provider( 'test-external' )` is not null.
  2. Add `test_boot_is_idempotent()`. Define a provider whose `register_hooks()` increments a per-instance counter (or asserts on a hook count via `did_action`/`has_action`). Register it through the hook, call `boot()` twice, assert `register_hooks()` ran exactly once.
  3. Add an `@after` cleanup that calls a new `Provider_Loader::reset()` so other tests see a fresh state.
- **Verify**: `composer run-script test-php` fails on both new tests (red).

### Task 2 — Defer boot to `plugins_loaded`

- **Status**: ✅ Done (f430fb1)
- **Files**:
  - Modify: `fosse.php`
- **Do**:
  1. Wrap the existing `if ( class_exists( … Provider_Loader … ) ) { … }` block in `add_action( 'plugins_loaded', 'fosse_boot_providers', 20 );` with `fosse_boot_providers()` defined as a global function in `fosse.php` (named, not inline closure, so tests can drive the production code path).
  2. Update the surrounding docblock to explain: providers self-register on `fosse_register_providers`, fired from `Provider_Loader::boot()` on `plugins_loaded` priority 20. Third parties hook from their plugin main file or no later than `plugins_loaded` priority 19.
- **Verify**: New tests still red (boot still not idempotent yet), but the external-provider test passes if you stub the idempotency assertion.

### Task 3 — Make `boot()` idempotent + add `reset()`

- **Status**: ✅ Done (6390dfc)
- **Files**:
  - Modify: `src/class-provider-loader.php`
- **Do**:
  1. Add `private static bool $booted = false;`.
  2. Top of `boot()`: early-return if `$booted`. Otherwise set `$booted = true` before firing the action.
  3. Add `public static function reset(): void { self::$booted = false; }` with a docblock noting it's for tests.
  4. Expand the existing class-level docblock and `boot()` docblock to document `fosse_register_providers` for third-party providers. The canonical recipe (code snippet) lives on `Connection_Provider` after PR review (single source of truth); `Provider_Loader` carries a short `@see` pointer. Callout that the callback must be registered from the plugin main file or no later than `plugins_loaded` priority 19.
- **Verify**: Both new tests green. Existing `test_providers_registered_after_boot` still green.

### Task 4 — Document the extension contract on the interface

- **Status**: ✅ Done (af136bc)
- **Files**:
  - Modify: `src/Admin/interface-connection-provider.php`
- **Do**:
  1. Expand the interface's class-level docblock with a short "implementing a standalone provider" recipe: implement this interface, register an instance on `fosse_register_providers` via `Connection_Provider_Registry::register()`, return false from `is_available()` when the provider's dependencies aren't loaded so FOSSE skips `register_hooks()`.
  2. No code changes — docs only.
- **Verify**: `composer run-script lint-php` clean.

### Task 5 — Lint, full test pass, commit

- **Status**: In progress (verified locally on branch `harden-provider-registration-DOTCOM-17104`; awaiting PR)
- **Do**:
  1. `composer run-script lint-php`
  2. `composer run-script test-php`
  3. `pnpm run lint && pnpm run format:check` (touched files are PHP-only, but cheap to run).
  4. Commit prefixed `add:` per the conventional-prefix hook, message body cross-linking Linear and GH issue.

---

## Review feedback applied

Post-PR-review changes folded back into the plan (PR #132 review by `/ce-code-review`, `/codex` challenge mode, and GitHub Copilot):

- **Boot priority 10 → 20.** Codex + ce-adversarial: a third-party that deferred to `plugins_loaded` *without* specifying a priority would land at 10 (WP's `add_action` default) and lose the same-priority race if loaded after FOSSE. Priority 20 gives a 19-priority headroom for the documented "register no later than `plugins_loaded` priority 19" contract.
- **Closure → named function `fosse_boot_providers()`.** ce-testing + ce-adversarial + codex (CONFIRMED) flagged that the previous test suite could not regress the headline change — reverting the `plugins_loaded` wrap would have left every test green. Extracting to a global named function in `fosse.php` lets tests drive the exact production code path and assert the action binding.
- **Test fixture leak in `Bluesky_ProviderTest`.** ce-correctness + ce-adversarial: the suite called `Provider_Loader::boot()` without resetting the new `$booted` static. Added `Provider_Loader::reset()` to its `#[Before]`.
- **`is_available()` false branch coverage.** ce-testing: the new docblock contract was untested. Added `test_unavailable_provider_does_not_register_hooks`.
- **Idempotency `$booted` flag flipped before completion.** ce-reliability + ce-adversarial + codex: a `Throwable` mid-loop would have left `$booted = true` with only partial hook registration. Switched to try/finally so the flag only flips on successful completion.
- **Extension-recipe docblock duplicated three places.** ce-maintainability: collapsed to a single canonical recipe on `Connection_Provider`; `Provider_Loader` and `fosse.php` carry short `@see` pointers.

Explicitly *not* applied:
- Move `reset()` to reflection-based access (codex suggestion) — disagrees with the existing `Connection_Provider_Registry::reset()` convention; staying consistent.
- AP/Bluesky `init()` outside the idempotency guard (adversarial) — only matters under contrived test replay; static-method callbacks dedupe on `add_action`.
- Multisite `switch_to_blog` (reliability) — pre-existing behavior, not a regression in this PR.

## Risks & follow-ups

- **Hooks now fire one phase later.** AP's `activitypub_default_blog_username` filter and Bluesky's `init` priority-1 / `admin_init` / `admin_post_*` callbacks all register on `plugins_loaded` 20 instead of file-include time. None of those consumers fire before `plugins_loaded` 20, so no observed regression — but worth specifically eyeballing during PR review.
- **`wpcom-simple-rollout`** (untracked SDD directory in the working copy) may have load-ordering assumptions worth a glance before merging. Not a blocker.
- **Follow-up if a standalone provider ships:** revisit whether the docblock recipe is enough or whether we want a `fosse_register_provider()` helper. Defer until n=1.

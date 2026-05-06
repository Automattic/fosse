# Spec: wp.com Simple Rollout

> **Distribution note:** This SDD is **not** committed to the public `Automattic/fosse` repo. wp.com Simple deployment details stay local. Once this spec + plan are settled, the work converts to a **Linear epic with sub-issues** as the durable record. Do not `git add` `sdd/wpcom-simple-rollout/`.

## Goal

Get FOSSE running on wp.com Simple for an allowlisted beta cohort (internal blogs + a small external tester set), on a manual maintainer-triggered cadence, without breaking ActivityPub for any non-flagged blog on the platform. Coexistence is achieved by load-order: on flagged blogs, FOSSE's bundled ActivityPub boots before wp.com's `wpcom-activitypub-load.php` mu-plugin runs, defines `ACTIVITYPUB_PLUGIN_DIR`, and the existing `wpcom_activitypub_is_loaded()` early-bail in that mu-plugin self-skips the platform's AP load. On non-flagged blogs nothing about AP loading changes.

## Requirements Summary

- Maintainer-triggered, manual deploy (no auto-deploy in v1).
- Allowlisted beta cohort, ops-controlled via a blog sticker.
- Selective AP load: bundled AP on flagged blogs, platform AP everywhere else.
- Atmosphere bundled copy loads on flagged blogs only (platform has no Atmosphere today — confirmed).
- Documented runbook + rollback / kill switch as part of the deliverable.
- Local-environment behavior in `fosse.php:42-122` is untouched.
- Final deliverable: a Linear epic with sub-issues per work stream.

## Chosen Approach

**Sticker-gated mu-plugin shim + load-order sentinel suppression.**

A small wp.com-side mu-plugin shim runs on every request. It hooks `plugins_loaded` at **priority 8**, one earlier than `wpcom-activitypub-load.php`'s priority 9. The shim checks for the FOSSE blog sticker. On a flagged blog, the shim includes `fosse.php` from the FOSSE artifact location. FOSSE's normal bootstrap runs as it does locally: it sees no standalone AP/Atmosphere constants defined, no AP/Atmosphere plugin file at the canonical paths, and includes the bundled copies. Bundled AP, as part of its own boot, defines `ACTIVITYPUB_PLUGIN_DIR` (along with `ACTIVITYPUB_PLUGIN_VERSION` and the rest of its constants). When `wpcom_maybe_load_activitypub_plugin()` runs at priority 9, it eventually calls `wpcom_activitypub_is_loaded()` (which checks `defined( 'ACTIVITYPUB_PLUGIN_DIR' )`) and self-skips. On non-flagged blogs the shim is inert: it doesn't include FOSSE and doesn't touch any constants, and platform AP loads exactly as today.

The lever is **load-order, not manual constant-setting**. The shim never defines AP constants itself; it just ensures bundled AP boots first, and bundled AP defines its own constants as part of normal startup.

### Why this approach

- Reuses FOSSE's existing local-environment skip-when-standalone pattern (`fosse.php:42-58`) untouched. Same code path, different environment.
- **No wp.com-side change to `wpcom-activitypub-load.php`** — the existing `wpcom_activitypub_is_loaded()` early-bail (`wpcom-activitypub-load.php:52-54`) does exactly what we need.
- Smallest surface area on the FOSSE side: zero behavioral changes in `fosse.php`. (One small comment block added to flag the wp.com load contract for future contributors.)
- Reversible: removing the FOSSE sticker on a blog returns it to platform-AP behavior on the next request. Removing the shim mu-plugin entirely returns the platform to its pre-FOSSE state.
- wp.com's existing constant defines (`ACTIVITYPUB_REST_NAMESPACE = 'wpcom/activitypub-1.0'`, `ACTIVITYPUB_SINGLE_USER_MODE = true`, `ACTIVITYPUB_DISABLE_SIDELOADING = true`, `ACTIVITYPUB_DISABLE_REMOTE_CACHE = true`) are at the top of `wpcom-activitypub-load.php` and run unconditionally on every wp.com request, before bundled AP boots — so bundled AP inherits the wp.com REST namespace, single-user-mode, and sideloading/remote-cache flags. This matches wp.com infrastructure expectations (request routing, primary-domain-aware actor resolution, no remote image caching).

### Alternatives Considered

- **Explicit wp.com loader-level suppression** (e.g. `option_active_plugins` style filter): rejected — wp.com Simple does not load plugins from a per-site option list. Plugin loading is mu-plugin-driven. There's no analogous filter to hook.
- **FOSSE-as-platform-AP** (have FOSSE replace the platform's standalone AP for everyone): out of scope — this is the long-term north star, not a v1 rollout.
- **Discovery-only spec, implementation SDD later**: rejected — wp.com discovery already confirmed the suppression mechanism. Remaining unknowns are small and resolvable inside the plan's first wave of tasks.

## Technical Details

### Architecture

Two delivery surfaces:

1. **wp.com-side `fosse-loader.php` mu-plugin.** Lives at `wp-content/mu-plugins/fosse-loader.php` (alongside `wpcom-activitypub-load.php`). Responsibilities:
    - Define a `FOSSE_BLOG_STICKER` constant for the sticker name (proposed: `enable-fosse`, mirroring the `enable-activitypub` pattern from `wpcom-activitypub-load.php:11`).
    - Honor a global `FOSSE_DISABLED` constant for kill-switch.
    - On `plugins_loaded` priority 8 (one earlier than wpcom-activitypub-load's priority 9), check the FOSSE sticker for the current blog (using the same `wpcom_activitypub_get_blog_id()` REST-aware blog-id resolution pattern from `wpcom-activitypub-load.php:109-121`).
    - On a flagged blog: `require_once` the FOSSE plugin entry point.
    - Provide helpers `wpcom_fosse_is_active( $blog_id )` and `wpcom_fosse_is_loaded()` mirroring the AP equivalents, for use by other wp.com code that needs to ask "is this a FOSSE blog?".
2. **FOSSE artifact location on wp.com.** Two candidates, picked in plan:
    - **Candidate A (versioned tree):** vendor FOSSE into `WP_PLUGIN_DIR/fosse/<version>/fosse.php` matching the `WP_PLUGIN_DIR/activitypub/<version>/activitypub.php` pattern. Lets us run multiple versions side-by-side for edge-cohort testing later (mirrors the `activitypub-edge` sticker pattern).
    - **Candidate B (mu-plugin tree):** vendor FOSSE under `wp-content/mu-plugins/fosse/` and have the shim include from there. Simpler, no version selection, but loses the parallel-versions affordance.
    - Recommendation: Candidate A. Even if v1 only ever has one version, the shape matches existing AP infra and gives us free runway for an `enable-fosse-edge` sticker later.

### Data Flow

Per-request, on wp.com Simple:

1. wp.com's mu-plugins synchronously include their entry files (top-level code in `fosse-loader.php` and `wpcom-activitypub-load.php` runs here).
2. `wpcom-activitypub-load.php` defines its top-level constants (`ACTIVITYPUB_REST_NAMESPACE`, `ACTIVITYPUB_BLOG_STICKER`, `ACTIVITYPUB_SINGLE_USER_MODE`, etc.). `fosse-loader.php` defines its top-level constants (`FOSSE_BLOG_STICKER`, optionally `FOSSE_PLUGIN_DIR_BASE`).
3. `plugins_loaded` priority 8 fires: FOSSE shim callback runs.
4. **If `FOSSE_DISABLED` is defined:** shim returns immediately. Continue to step 6 with platform AP behavior.
5. **Else, sticker check:**
    - **Sticker present:**
        - Shim `require_once`s `fosse.php` from the artifact location.
        - FOSSE's bundled-backends bootstrap (`fosse.php:23-58`) runs. Disk check for `WP_PLUGIN_DIR/activitypub/activitypub.php` returns false (wpcom has versioned-dir layout, not flat file). Constant check for `ACTIVITYPUB_PLUGIN_VERSION` returns false (priority 9 hasn't run yet). Bundled AP at `bundled/activitypub/activitypub.php` is included.
        - Bundled AP boots, defines `ACTIVITYPUB_PLUGIN_DIR`, `ACTIVITYPUB_PLUGIN_VERSION`, etc. as part of its own startup.
        - Same path runs for Atmosphere (no platform Atmosphere today, so the constant and disk checks both return false; bundled Atmosphere loads).
        - First-load activation shim defers to `init` priority 20 (existing `fosse.php:73-122`) and seeds any missing options/rewrites on first FOSSE load for the blog.
    - **Sticker absent:** shim returns. FOSSE not loaded.
6. `plugins_loaded` priority 9 fires: `wpcom_maybe_load_activitypub_plugin()` runs.
    - Calls `wpcom_activitypub_is_active( $blog_id )` (`wpcom-activitypub-load.php:129`). Checks `enable-activitypub` sticker.
    - **`enable-activitypub` sticker absent:** loads opt-in admin UI, returns. Same as today.
    - **`enable-activitypub` sticker present:** calls `wpcom_load_the_activitypub_plugin()`.
        - Inside: `wpcom_activitypub_is_loaded()` returns true (because step 5 set `ACTIVITYPUB_PLUGIN_DIR`). Function returns at line 52-54. Platform AP load is skipped.
7. `plugins_loaded` priority 10 fires: AP plugin's own hooks normally register here. On a FOSSE-flagged blog, bundled AP has already registered its hooks during step 5; on a non-FOSSE blog, platform AP just loaded in step 6 and registers as today.
8. Request continues normally.

### Key Components

| Component                                | Owner               | Responsibility                                                                                                                  |
| ---------------------------------------- | ------------------- | ------------------------------------------------------------------------------------------------------------------------------- |
| `fosse-loader.php` mu-plugin             | wp.com source tree  | Sticker check, kill-switch check, FOSSE inclusion at `plugins_loaded` priority 8, public helpers (`wpcom_fosse_is_active`, etc.). |
| FOSSE artifact at `WP_PLUGIN_DIR/fosse/<v>/` | wp.com source tree  | The actual FOSSE plugin tree. Vendored manually from a `composer build-zip` artifact.                                            |
| FOSSE bundled-backends loader            | `fosse.php:23-122`  | Existing logic. Includes bundled AP/Atmosphere when their constants aren't already defined. Untouched in v1.                    |
| `enable-fosse` blog sticker (proposed)   | wp.com ops tooling  | Per-blog opt-in. Set/cleared via existing `add_blog_sticker` / `remove_blog_sticker` ops pattern.                                |
| `FOSSE_DISABLED` global constant         | wp.com config       | Global short-circuit. Lets a maintainer disable FOSSE platform-wide without revoking stickers or redeploying.                   |
| `disable-fosse` per-blog kill sticker    | wp.com ops tooling  | Optional second-tier kill — overrides `enable-fosse` for one blog. Recommend including in v1; cheap.                            |
| Deploy runbook                           | This SDD + Linear   | Documented process for shipping a new FOSSE build to wp.com Simple. Mirrored into the Linear epic; not committed to public repo. |

### File Changes

| File / Location                                                | Change Type    | Description                                                                                                                                |
| -------------------------------------------------------------- | -------------- | ------------------------------------------------------------------------------------------------------------------------------------------ |
| wp.com: `wp-content/mu-plugins/fosse-loader.php`               | new (wp.com)   | Sticker-gated FOSSE includer with kill-switch. Hooks `plugins_loaded` priority 8.                                                          |
| wp.com: `WP_PLUGIN_DIR/fosse/<version>/`                       | new (wp.com)   | The vendored FOSSE plugin tree for the active version.                                                                                     |
| wp.com: `bin/fosse/` (optional)                                | new (wp.com)   | Any wp.com-side scripts paralleling `bin/activitypub/` (e.g. cleanup, schema-update queueing). Probably out of scope for v1.               |
| `fosse.php`                                                    | modify (FOSSE) | Add a brief comment block describing the wp.com Simple load contract so the existing skip-when-standalone logic isn't accidentally regressed. No behavioral change. |
| `sdd/wpcom-simple-rollout/runbook.md` (local only)             | new (local)    | Step-by-step deploy + rollback runbook. Mirrored into the Linear epic; not committed to the public repo.                                   |

### Tracks / observability (informational)

`wpcom-activitypub-load.php` already records Tracks events on activate (`wpcom_activitypub_activate`, `wpcom_activitypub_activate_free`) and posts to `#fediverse-announce` on activate/deactivate. FOSSE-specific equivalents are out of v1 scope but worth noting as natural follow-on work once the rollout is live and we want signal on cohort growth.

## Phase 0 — Remaining unknowns (now small)

Most discovery is resolved by reading `wpcom-activitypub-load.php`. What's left:

1. **Sticker name confirmation.** Proposed: `enable-fosse`. Confirm against existing wp.com sticker namespace conventions; check there's no collision.
2. **Artifact destination confirmation.** Confirm Candidate A (`WP_PLUGIN_DIR/fosse/<version>/`) is allowed/preferred over Candidate B (`wp-content/mu-plugins/fosse/`). Check with whoever owns wp.com plugin sourcing today.
3. **`jetpack_sync_remote_action` handler safety.** `wpcom-activitypub-load.php:313/339/365/394` register Jetpack-sync handlers at the loader's top level that call `WPCom_Activitypub::get_instance()`. That class only loads inside `wpcom_load_the_activitypub_plugin()` — which we cause to bail on FOSSE-flagged blogs. Verify these handlers never fire in a FOSSE-flagged-blog context (they likely target Jetpack-mirror blogs, not Simple blogs). If they can fire, gate them on `! wpcom_fosse_is_active( $blog_id )`.
4. **Per-blog kill switch shape.** Confirm `disable-fosse` sticker is the right shape vs. relying solely on the global `FOSSE_DISABLED` constant. Recommend including both.
5. **Runbook ownership and access.** Who can trigger a deploy (FOSSE maintainers + wp.com systems team?). Document required roles.
6. **Coexistence smoke-test on a sandbox blog.** Stand up the shim on a sandboxed wp.com blog with `enable-fosse` set, verify bundled AP loads and platform AP self-skips. Verify a sibling non-flagged blog still loads platform AP normally.

## Out of Scope

- Auto-deploy / CI-driven continuous deployment.
- Self-serve user-facing opt-in (no `Settings > FOSSE` toggle in v1; ops sets the sticker).
- Long-term single-AP-on-wp.com / FOSSE-drops-bundling path.
- wp.com Atomic and Jetpack-connected sites.
- Reader-side / inbound consumption changes.
- FOSSE-specific Tracks events, Slack announce hooks, async-job seeding (wp.com AP equivalents at `wpcom-activitypub-load.php:170-218` are nice-to-have, not v1).
- FOSSE-side override of `ACTIVITYPUB_REST_NAMESPACE` for flagged blogs. wp.com defines it as `wpcom/activitypub-1.0` for routing reasons; bundled AP inherits that. Revisit if/when FOSSE wants its own namespace.
- Migration of existing wp.com AP user data when a blog flips from `enable-activitypub` to `enable-fosse` (and back). Bundled AP and platform AP share option keys/schema by construction; explicit migration becomes scope only if a divergence shows up.

## Open Questions Resolved

- **How does wp.com load AP today, and what's the suppression hook?** Resolved → `wp-content/mu-plugins/wpcom-activitypub-load.php` on `plugins_loaded` priority 9. Existing `wpcom_activitypub_is_loaded()` early-bail (`defined( 'ACTIVITYPUB_PLUGIN_DIR' )`) does exactly the suppression we need. No wp.com-side code change required.
- **Site option vs blog sticker for the per-blog flag**: Resolved → blog sticker. Matches the existing `enable-activitypub` / `activitypub-edge` / `enable-mastodon-apps` pattern.
- **Atmosphere status on wp.com Simple**: Resolved → not present. Reader-side AT consumption code only. Bundled Atmosphere just loads on flagged blogs.
- **PR / repo distribution**: Resolved → SDD stays uncommitted in the public repo. Linear epic + sub-issues are the durable record.
- **Manual constant-setting risk in the shim**: Resolved → shim must NOT define `ACTIVITYPUB_PLUGIN_VERSION` / `ACTIVITYPUB_PLUGIN_DIR` itself. If it did, FOSSE's `fosse.php:42-46` would see the constant and skip its own bundle, defeating the purpose. Bundled AP defines those constants when it boots. Load-order is the lever.
- **wp.com top-level constant pre-defines**: Resolved → `ACTIVITYPUB_REST_NAMESPACE`, `ACTIVITYPUB_SINGLE_USER_MODE`, `ACTIVITYPUB_DISABLE_SIDELOADING`, `ACTIVITYPUB_DISABLE_REMOTE_CACHE` are defined at `wpcom-activitypub-load.php:9-21`. Bundled AP inherits these on flagged blogs. Acceptable for v1 — matches wp.com routing/single-user-mode/no-remote-cache infra.

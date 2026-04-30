# Unified Reactions Display — Requirements

## Goal

Step 1 of [DOTCOM-16894](https://linear.app/a8c/issue/DOTCOM-16894): make ActivityPub and Bluesky reactions show up together on a single WordPress post, so the "reactions come home" promise of FOSSE is visible to readers and site owners. This SDD is a **verify-and-fill** pass — confirm what the bundled `activitypub/reactions` block already does for both networks, fix only real gaps, defer a FOSSE-owned block until we know whether one is needed.

## Requirements

1. **Source-agnostic v1 display.** Likes, reposts, and AP-only quotes from both networks fold into shared counts and lists. No per-source ("via Bluesky" / "via Mastodon") badges in v1; that's deferred.
2. **Verification on a real-shaped install.** Confirm via end-to-end test that AP's `activitypub/reactions` block on a single post displays both `protocol='activitypub'` and `protocol='atproto'` comment rows of types `like` and `repost`. The protocol-agnostic query in `bundled/activitypub/build/reactions/render.php` (lines 77–86) makes this likely-already-working; the requirement is to prove it on a real install rather than infer it from the code.
3. **Relabel "Fediverse Reactions" → "Social Reactions"** anywhere user-visible (block-inserter title, block-inserter description, any fallback rendered title). The bundled AP block is filterable; the spec picks the cleanest hook (`gettext` on the AP textdomain, `register_block_type_args`, or `block_type_metadata`).
4. **Playwright e2e spec** under `tests/e2e/` that seeds `wp_comments` rows for both protocols and asserts the unified block renders both. Locks the behavior so a future `tools/sync-bundled.sh` refresh of either bundled plugin can't silently regress it.
5. **Shim stays small.** No new block, no new JS/Gutenberg build infra in this repo. PHP-only FOSSE-side code, following the existing projector pattern (`Object_Type`, `Post_Types`, `Long_Form_Strategy`).
6. **Lint and tests stay clean.** `composer run-script lint-php`, `pnpm run lint`, and `pnpm run format:check` pass. New PHPUnit coverage if the relabel shim warrants it (likely yes — locks the filter contract).

## Constraints

- **Upstream-first per `AGENTS.md`.** Any post-type-agnostic improvement (e.g. registering Atmosphere comment types in a way AP can pick up) lands in `wordpress-activitypub` or `wordpress-atmosphere`, not in FOSSE. FOSSE-side code is the relabel/projection only.
- **No edits to `bundled/`** (per AGENTS.md "Common Pitfalls" #7). All FOSSE-side code lives under `src/` and `tests/`.
- **No JS/Gutenberg build infra in this SDD.** If the spec discovers the relabel cannot be done in PHP and requires a JS-side override, that's a scope-pivot signal — re-open the build-infra discussion explicitly rather than tucking it in here.
- **PHP 8.2+ / WP 6.9+** floor unchanged. Same coding standards as the rest of FOSSE: tabs, Yoda, Jetpack PHPCS ruleset, text-domain `fosse`, namespace `Automattic\Fosse\…`.
- **`bundled/` refresh independence.** The shim and the e2e must survive any future `tools/sync-bundled.sh` run that bumps either upstream plugin. The e2e is part of how that's enforced.

## Out of Scope

- **Replies handling and the `posts-and-replies` block.** Replies from both networks already become standard `comment_type='comment'` rows with `comment_parent` linkage; how they display in WP's native comment UI is a separate concern and a future SDD.
- **A FOSSE-owned reactions block.** Stays a possibility for v2 but is not built or stubbed in v1. The deciding evidence is what the v1 verification surfaces.
- **JS/Gutenberg build infra in `package.json` / `eslint.config.mjs` / etc.** Deferred with the FOSSE block.
- **Per-source visual distinction** ("via Bluesky" / "via Mastodon" badges, separate per-network counts, etc.). Future possibility; not v1.
- **Quote-post handling on the Bluesky side.** AT Protocol has no native quote primitive; AP's `quote` comment_type stays AP-only by definition.
- **Changing AP's reaction-handling internals or Atmosphere's `Reaction_Sync` internals.** If those need changes (e.g., Atmosphere registering its types with AP's registry), it's an upstream PR, tracked separately from this SDD.
- **Atmosphere's `Reaction_Sync` polling cadence, watermark logic, or REST surface.** Out of scope; this SDD is purely display-side.
- **Admin diagnostic UI** ("show me the per-source reaction counts on this post" in `wp-admin`). Not in v1.

## Open Questions

These get resolved in `spec.md` or via a follow-up conversation with the user:

- **Filter choice for the relabel.** `gettext` on the AP textdomain catches the most surfaces but is loosely targeted (could affect other AP strings if any future translations collide). `register_block_type_args` is precise but only covers the inserter metadata. `block_type_metadata` runs earlier in the lifecycle. Spec picks one and documents the tradeoff.
- **Verification environment.** Atmosphere's `Reaction_Sync` polls live Bluesky over the network; Playwright + Playground likely can't reach a real Bluesky account during CI. The e2e probably needs to seed `wp_comments` directly via a mu-plugin in the test scaffold (mirroring `tests/e2e/mu-plugins/fosse-bsky-capture.php`) rather than perform a real round-trip.
- **AP's inserter description: in or out?** The block.json `description` field reads `"Display Fediverse likes and reposts for your posts."` — same "Fediverse" wording as the title. Confirm whether the relabel covers description too (likely yes, but spec should be explicit).
- **Naming alternatives.** "Social Reactions" is the working name. "Reactions" alone is even shorter and source-agnostic; "Federated Reactions" still leaks jargon. Confirm with user during spec if the working name needs revisiting.
- **What if verification fails.** If on a real install the unified display does NOT work as predicted (e.g., Atmosphere's `comment_type='like'` rows are filtered out by something subtle in WP's comment query), the spec will need a fallback path — likely an upstream PR rather than a FOSSE-side workaround. Treat as a contingent scope-expansion.

## Related Code / Patterns Found

### Bundled — ActivityPub

- `bundled/activitypub/build/reactions/block.json` — `activitypub/reactions` block. Title and description carry the "Fediverse Reactions" wording.
- `bundled/activitypub/build/reactions/render.php` — server-side render. Lines 77–86 iterate `Comment::get_comment_types()` and call `get_comments(['type' => $type, 'post_id' => X, 'parent' => 0, 'status' => 'approve'])`. The query is protocol-agnostic — the central insight that makes verify-and-fill viable.
- `bundled/activitypub/includes/class-comment.php` — `Comment::get_comment_types()`. AP registers `like`, `repost`, `quote`. The block iterates this set; Atmosphere does not register here, but its rows still match by `comment_type` value.
- `bundled/activitypub/includes/collection/class-interactions.php:426` — canonical AP reaction insert. Writes `protocol='activitypub'`, `source_id=<HTTPS URL>`. Reference shape Atmosphere claims to mirror.
- `bundled/activitypub/build/posts-and-replies/`, `build/reply/`, `build/remote-reply/` — out-of-scope for v1, noted for completeness.

### Bundled — Atmosphere

- `bundled/atmosphere/includes/class-reaction-sync.php` — Bluesky reaction sync. Header docblock: "Matches the key used by wordpress-activitypub." Writes `protocol='atproto'`, `source_id=<AT-URI>`, `comment_type` ∈ {`like`, `repost`, `comment`}, always auto-approved (`comment_approved=1`). Registers `get_avatar_comment_types` for avatar rendering parity.
- No blocks ship in Atmosphere — confirmed via `find bundled/atmosphere -name block.json`. Reaction display today depends entirely on AP's block existing or WP's native comment UI for replies.

### FOSSE — projector pattern precedent

- `src/class-object-type.php` — `fosse_object_type` option projected onto two upstream filters. Established the FOSSE projector shape.
- `src/class-post-types.php` — `activitypub_support_post_types` option projected onto Atmosphere's `atmosphere_syncable_post_types` filter.
- `src/class-long-form-strategy.php` — `fosse_long_form_strategy` option projected onto Atmosphere's `atmosphere_long_form_composition` filter (currently a no-op pending upstream).
- `fosse.php` — registration pattern: `add_action('init', ...)` with a `class_exists` guard. The relabel shim follows this shape unless the spec finds a reason to diverge.

### FOSSE — e2e pattern precedent

- `tests/e2e/mu-plugins/fosse-bsky-capture.php` — example of mocking Bluesky-side state inside the Playwright + Playground harness via a test-scoped mu-plugin. The unified-reactions e2e spec will follow this pattern for seeding `wp_comments` rows.
- `tests/e2e/blueprint.json` — Playground blueprint. May need a small extension (or a sibling mu-plugin) to register the seed rows; spec decides whether to extend the existing capture mu-plugin or add a dedicated one.

### Cross-protocol reaction-shape mapping (reference)

| Reaction | AP `comment_type` | Atmo `comment_type` | AP `protocol` meta | Atmo `protocol` meta |
|----------|--------------------|---------------------|---------------------|------------------------|
| Like | `like` | `like` | `activitypub` | `atproto` |
| Repost / Announce | `repost` | `repost` | `activitypub` | `atproto` |
| Quote | `quote` | — (AT has no quote primitive) | `activitypub` | — |
| Reply | `comment` | `comment` | `activitypub` | `atproto` |

Replies (`comment_type='comment'`) are out of v1 scope; included for future-SDD reference.

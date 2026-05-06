# Federation Surface Audit

Agent D audit of FOSSE federation, network semantics, and extension surface at `origin/trunk` / `1437a14f3b3022050ba6625d31497828f581c07c`.

Bundled `activitypub` and `atmosphere` files were inspected only as reference APIs/behavior for FOSSE integration claims.

## Confirmed Integration Defects

### 1. ActivityPub's native object-type setting can desynchronize AP and Bluesky

**Classification:** Defect.

FOSSE intends `fosse_object_type` to be the single cross-network object-shape option, but the current pass-through behavior leaves ActivityPub's native `activitypub_object_type` setting active when `fosse_object_type` is unset.

Evidence:

-   `src/class-object-type.php:50-52` registers FOSSE on both `atmosphere_is_short_form_post` and `activitypub_post_object_type`.
-   `src/class-object-type.php:66-93` only forces both networks when `get_option( 'fosse_object_type' ) === 'note'`; otherwise it returns the upstream-computed values unchanged.
-   `bundled/activitypub/includes/transformer/class-post.php:453-483` computes the AP type from `activitypub_object_type` before applying FOSSE's `activitypub_post_object_type` filter. If that AP option is `note`, AP returns `Note`.
-   `bundled/atmosphere/includes/transformer/class-post.php:339-345` decides Bluesky short/long from title support, empty title, and post format; it does not read `activitypub_object_type`.
-   FOSSE hides bundled menus but deliberately leaves direct URL access to native settings available for power users (`src/Admin/class-menu.php:117-128`) and links users to advanced ActivityPub settings (`src/Admin/class-ap-provider.php:181-184`), where the AP object-type checkbox exists (`bundled/activitypub/includes/wp-admin/class-advanced-settings-fields.php:277-288`).

Impact:

On a site upgraded from standalone ActivityPub, or a power-user visit to the advanced AP screen, `activitypub_object_type=note` makes AP publish titled normal posts as `Note` while Bluesky still treats the same post as long-form unless the separate hidden `fosse_object_type` option is set. That breaks FOSSE's "publish once, reach everywhere" shape alignment.

Ownership recommendation:

-   **FOSSE-owned.** Pick one canonical source of truth under FOSSE.
-   The least surprising path is to have FOSSE either mirror/repair the AP option into `fosse_object_type`, or treat the AP option as an input when `fosse_object_type` is unset.
-   If `fosse_object_type` remains canonical, FOSSE should make the AP native object-type control unreachable or clearly subordinate, and expose a FOSSE-owned UI/status row for the canonical value.
-   Add regression coverage for `activitypub_object_type=note` with `fosse_object_type` unset so AP and Atmosphere cannot diverge silently.

### 2. FOSSE overrides Atmosphere's long-form setting while leaving the native Atmosphere UI reachable

**Classification:** Defect if direct native settings are supported; otherwise a product gap caused by an invisible FOSSE-owned option.

FOSSE's `Long_Form_Strategy` projector discards the incoming upstream/default strategy and defaults to `teaser-thread`. That means Atmosphere's own `atmosphere_long_form_composition` option, which is still rendered by Atmosphere's native settings page, does not actually control FOSSE behavior.

Evidence:

-   Atmosphere seeds `atmosphere_long_form_composition` from its option at priority 1 (`bundled/atmosphere/includes/class-atmosphere.php:70-75`, `bundled/atmosphere/includes/class-atmosphere.php:683-701`).
-   FOSSE registers a later filter at priority 10 (`src/class-long-form-strategy.php:75-76`).
-   FOSSE discards both `$strategy` and `$post`, then returns `fosse_long_form_strategy` or the FOSSE default `teaser-thread` (`src/class-long-form-strategy.php:96-105`).
-   Atmosphere still renders its native long-form radio group and describes it as the long-form publishing control (`bundled/atmosphere/includes/wp-admin/class-admin.php:304-333`).
-   FOSSE leaves direct URL access to bundled pages available (`src/Admin/class-menu.php:117-128`) but FOSSE's own setup UI has no long-form strategy field (`src/Admin/templates/setup-page.php:52-170`; save handler only persists `activitypub_support_post_types` at `src/Admin/class-setup-page.php:134-148`).
-   The SDD explicitly documents FOSSE's hidden default/option (`sdd/long-form-bluesky-strategy/plan.md:183-211`) while the upstream plan text says users can pin either `fosse_long_form_strategy` or `atmosphere_long_form_composition` to avoid teaser-thread update side effects (`sdd/long-form-bluesky-strategy/plan.md:143-147`). In FOSSE, pinning only the Atmosphere option does not work because the FOSSE filter runs later and ignores it.

Impact:

A user can set Atmosphere's native long-form option to `link-card` or `truncate-link`, see that choice saved in the Atmosphere UI, and still have FOSSE publish long-form posts as teaser threads. The control becomes a lie under FOSSE unless the user knows to set the hidden `fosse_long_form_strategy` option instead.

Ownership recommendation:

-   **FOSSE-owned.** Either make FOSSE write/reuse Atmosphere's option as the canonical storage key, or make the FOSSE-owned option visible and suppress/redirect the native Atmosphere control under FOSSE.
-   Add a FOSSE setup/status row for the active long-form strategy.
-   Correct the SDD/operator docs so `atmosphere_long_form_composition` is not described as a working FOSSE-side pin unless the projector changes.
-   If code-only configuration is intentional for now, add a documented FOSSE filter after option resolution so per-post extensions do not need to race FOSSE's priority.

## Missing Extension Points And Product Gaps

### 3. Post-type projection erases Atmosphere's native opt-in extension surfaces

**Classification:** Missing extension point / intentional FOSSE policy gap, not a confirmed defect.

FOSSE deliberately makes ActivityPub's post-type option the source of truth, but the implementation replaces Atmosphere's incoming list after Atmosphere has merged its own setting and `add_post_type_support( 'atmosphere' )` opt-ins.

Evidence:

-   Atmosphere merges `atmosphere_support_post_types` with native `add_post_type_support( $post_type, 'atmosphere' )` support, then applies `atmosphere_syncable_post_types` (`bundled/atmosphere/includes/class-post-types.php:25-47`).
-   FOSSE receives that filtered list but immediately unsets it and returns `activitypub_support_post_types` or `array( 'post' )` (`src/class-post-types.php:69-75`).
-   The SDD records this as a deliberate AP-option ownership pivot (`sdd/post-type-sync/notes.md:14-30`).

Impact:

Extensions that were written for standalone Atmosphere and opt a custom type into Bluesky via `add_post_type_support( 'atmosphere' )` or the native Atmosphere option stop having effect under FOSSE unless they also modify ActivityPub's option path. That is coherent with the FOSSE single-source model, but it is a compatibility break for extension authors.

Ownership recommendation:

-   **FOSSE-owned.** Document that `activitypub_support_post_types` is the only supported post-type source under FOSSE.
-   Add a FOSSE-specific extension filter after AP-option projection, for example `fosse_syncable_post_types`, so extensions can modify the unified list without depending on AP option filters or lost Atmosphere-native hooks.
-   Do not change upstream Atmosphere for this; its standalone extension model is already coherent.

### 4. Canonical object-shape and long-form policy are invisible in FOSSE UI/status

**Classification:** Product gap.

The two options that most directly affect cross-network shape, `fosse_object_type` and `fosse_long_form_strategy`, are not surfaced in FOSSE setup/status.

Evidence:

-   FOSSE setup renders post types and actor mode only in the General section (`src/Admin/templates/setup-page.php:52-170`).
-   FOSSE setup saves only `activitypub_support_post_types` (`src/Admin/class-setup-page.php:134-148`).
-   `AP_Provider::get_status()` reports actor mode, post types, and AP addresses, but not object shape (`src/Admin/class-ap-provider.php:88-107`).
-   `Bluesky_Provider::get_status()` reports connection metadata and token errors only (`src/Admin/class-bluesky-provider.php:105-123`).
-   The native-publishing SDD explicitly left `fosse_object_type` UI out of scope (`sdd/bluesky-native-publishing/spec.md:171-181`, `sdd/bluesky-native-publishing/spec.md:185-199`).
-   The long-form SDD likewise treats `fosse_long_form_strategy` as a site-wide option/configuration concern, with FOSSE-side e2e UI work still incomplete (`sdd/long-form-bluesky-strategy/plan.md:183-232`).

Impact:

Operators cannot tell from FOSSE whether a post should federate as AP `Note` vs `Article`, Bluesky native text vs card/thread, or why a hidden setting is overriding an upstream screen. This also makes support/debugging harder when AP and Bluesky outputs differ.

Ownership recommendation:

-   **FOSSE-owned.** Add visible setup and status controls for active object-shape policy and long-form strategy.
-   At minimum, status should show the effective values and where they came from: FOSSE option, AP legacy option fallback, default, or filter override.

### 5. Media, alt text, galleries, and Pixelfed-like photo semantics are AP-only today

**Classification:** Product gap, already partly documented as out of scope.

ActivityPub has a media attachment pipeline, while Atmosphere currently only builds external link cards with optional featured-image thumbnail blobs. FOSSE does not bridge those semantics.

Evidence:

-   ActivityPub collects featured images, enclosures, block attachments, HTML images, media limits, and attachment filters (`bundled/activitypub/includes/transformer/class-post.php:371-440`).
-   Atmosphere's post embed path only builds `app.bsky.embed.external` with optional featured-image `thumb` (`bundled/atmosphere/includes/transformer/class-post.php:255-278`).
-   Atmosphere's blob upload helper only supports the link-card thumbnail path and does not build `app.bsky.embed.images` records or alt text (`bundled/atmosphere/includes/transformer/class-post.php:286-323`).
-   The Bluesky native-publishing SDD explicitly excludes `app.bsky.embed.images` and image attachments from the epic (`sdd/bluesky-native-publishing/requirements.md:32-40`, `sdd/bluesky-native-publishing/requirements.md:46-49`; `sdd/bluesky-native-publishing/spec.md:171-180`).

Impact:

Photo posts and galleries can carry media semantics to AP/Pixelfed-compatible consumers but publish to Bluesky as text-only short-form posts, or as long-form external cards with at most a thumbnail. Alt text and gallery ordering are not unified across networks.

Ownership recommendation:

-   **Upstream Atmosphere-owned first.** Native `app.bsky.embed.images` support, alt text mapping, gallery/image selection, and blob-size behavior are broadly useful to any Atmosphere site.
-   **FOSSE-owned policy second.** FOSSE should decide how AP attachment selection and Bluesky image selection should align, especially for Pixelfed/photo-first posts, once upstream exposes the primitives.

### 6. Cross-network privacy semantics are not unified

**Classification:** Product gap.

ActivityPub has an explicit content-visibility/audience model, but the Bluesky publishing path is driven by WordPress `publish` status and connection/auto-publish gates. FOSSE has no policy layer explaining or preventing privacy mismatches.

Evidence:

-   ActivityPub's transformer defaults to public visibility but supports public, quiet public, and private audience mapping (`bundled/activitypub/includes/transformer/class-base.php:154-215`).
-   Atmosphere schedules publish/update/delete from WordPress status transitions and only gates on connection, `atmosphere_auto_publish`, and supported post types (`bundled/atmosphere/includes/class-atmosphere.php:291-349`).
-   Atmosphere's Bluesky record construction has text, facets, tags, embed, and timestamps, but no ActivityPub-style audience/visibility field (`bundled/atmosphere/includes/transformer/class-post.php:969-1004`).

Impact:

If a site uses AP visibility controls or expects AP quiet/private semantics, FOSSE does not provide a cross-network guarantee. A post that is not publicly distributed in AP terms may still be eligible for public Bluesky publication if it is a WordPress `publish` post and the Bluesky gates pass.

Ownership recommendation:

-   **FOSSE-owned product policy.** Decide whether AP private/quiet visibility should suppress Bluesky, warn the user, or remain an explicit "Bluesky is public" carveout.
-   **Upstream ownership is limited.** Atmosphere can expose filters/gates if needed, but the cross-protocol privacy decision belongs in FOSSE.

### 7. Bluesky publish/update failure state is not visible in FOSSE status

**Classification:** Product gap / missing status integration.

FOSSE's Bluesky status reports connection and token health, but does not surface publish/update/delete failures, thread rewrite orphan records, or pending document-reference repair state.

Evidence:

-   `Bluesky_Provider::get_status()` returns `connected`, `handle`, `did`, `pds_endpoint`, and `token_error` only (`src/Admin/class-bluesky-provider.php:105-123`).
-   Atmosphere's publish/update cron callbacks invoke `Publisher::publish_post()` and `Publisher::update_post()` without logging or surfacing returned `WP_Error` values (`bundled/atmosphere/includes/class-atmosphere.php:716-733`). The comment paths do log errors, showing a stronger pattern exists there (`bundled/atmosphere/includes/class-atmosphere.php:796-850`).
-   Atmosphere persists orphan/thread rewrite failures and doc-ref pending state in post meta (`bundled/atmosphere/includes/transformer/class-post.php:86-105`, `bundled/atmosphere/includes/class-publisher.php:843-868`, `bundled/atmosphere/includes/class-publisher.php:1462-1485`).

Impact:

The FOSSE Status page can be green while posts are failing to publish/update, thread rewrites have orphaned remote records, or document references are pending repair. Operators get no central signal unless they inspect logs or post meta directly.

Ownership recommendation:

-   **Upstream Atmosphere-owned first.** Provide durable error/status APIs for last publish/update/delete attempt, queued/retry state, orphan records, and pending doc-ref repair.
-   **FOSSE-owned integration.** Consume those APIs in the FOSSE Status page and per-provider status cards. Show actionable state, not just OAuth connection health.

### 8. Bluesky replies are standard comments but FOSSE has no cross-protocol reply policy or UI distinction

**Classification:** Product gap, not a defect in the reactions label shim.

Atproto replies sync into WordPress as normal `comment_type='comment'` rows. The current reactions work intentionally leaves reply display to WordPress comments, but FOSSE does not explain the behavioral differences between AP and Bluesky replies.

Evidence:

-   Atmosphere maps Bluesky replies into normal WordPress comments via `process_reply()` and `insert_reaction()` (`bundled/atmosphere/includes/class-reaction-sync.php:365-420`, `bundled/atmosphere/includes/class-reaction-sync.php:468-530`).
-   Those rows are stamped with `protocol=atproto` (`bundled/atmosphere/includes/class-reaction-sync.php:507-529`).
-   ActivityPub's federated-comment detection returns true only for `protocol=activitypub` (`bundled/activitypub/includes/class-comment.php:207-221`).
-   Atmosphere republishes local comments to Bluesky only when they are logged-in user comments, approved, not atproto-sourced, and attached to a post with Bluesky URI/CID (`bundled/atmosphere/includes/class-atmosphere.php:559-600`).
-   The unified-reactions implementation notes explicitly leave replies and per-source distinction out of v1 (`sdd/unified-reactions-display/implementation.md:55-61`).

Impact:

Site visitors can see Bluesky replies as ordinary WordPress comments, but reply-back behavior is not equivalent across networks or user types. FOSSE does not badge, explain, or gate that difference.

Ownership recommendation:

-   **FOSSE-owned UX/policy.** Decide whether comment templates/admin lists should mark source protocol, whether atproto replies need a remote-reply affordance, and whether anonymous/local replies should explain that they will not publish back to Bluesky.
-   **Upstream Atmosphere-owned mechanics.** Keep reply publish/update/delete mechanics and protocol meta in Atmosphere.

### 9. Domain-handle setup is only partially implemented

**Classification:** Product gap, not a defect in the shipped route.

FOSSE serves the `/.well-known/atproto-did` route, but the fuller setup/verification UX remains planned.

Evidence:

-   `Bluesky_Provider` registers the well-known route and suppression hooks (`src/Admin/class-bluesky-provider.php:342-357`) and serves a DID only when connected and valid (`src/Admin/class-bluesky-provider.php:417-469`).
-   The SDD implementation notes state that only Task 1 is implemented and that resolver, UI, DNS fallback, admin-post handlers, and broader tests remain planned (`sdd/bluesky-handle-setup/implementation-notes.md:3-6`).

Impact:

The technical route exists, but users do not yet get a complete FOSSE-native flow for verifying or troubleshooting domain handles.

Ownership recommendation:

-   **FOSSE-owned.** This is part of FOSSE's unified setup experience.
-   Keep upstream Atmosphere involvement limited to generic well-known hooks or OAuth APIs that are useful outside FOSSE.

## Checks With No Confirmed FOSSE Defect

-   **Reactions label:** The FOSSE shim only relabels ActivityPub's reactions block metadata (`src/class-reactions-label.php:69-83`). The bundled render path queries approved top-level comments by `comment_type` without filtering by protocol (`bundled/activitypub/build/reactions/render.php:77-117`), matching the unified reactions SDD. No direct integration defect found here.
-   **Mentions, links, and tags:** Atmosphere builds Bluesky facets/tags on generated records and exposes `atmosphere_transform_bsky_post` for final record mutation (`bundled/atmosphere/includes/transformer/class-post.php:969-1004`). No FOSSE-specific defect found in the inspected projector/provider layer.
-   **Status formatting:** `Status_Formatter` escapes display tokens before inserting allowed `<wbr>` markers (`src/Admin/class-status-formatter.php:33-153`). No federation semantics defect found in the formatting helper.

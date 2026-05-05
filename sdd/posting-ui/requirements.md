# FOSSE-Native Posting UI - Requirements

## Goal

Create a minimal, clean FOSSE-owned composer for the "post once, reach everywhere" workflow. This is not a replacement for the WordPress block editor. The product demo should be: open the FOSSE posting UI, type a sentence, attach a photo, add alt text, hit Post, and see whether ActivityPub and Bluesky accepted the send.

Source: DOTCOM-16794 "2. FOSSE-native Posting UI".

## Linear Issues

- **DOTCOM-16794** - 2. FOSSE-native Posting UI
- **DOTCOM-16804** - Minimal note composer
- **DOTCOM-16805** - Cross-network send status + retry UI
- **DOTCOM-16806** - Featured-image alt-text UX
- **DOTCOM-16807** - Quick-entry / Press This-style posting path

## Requirements

1. **A FOSSE-owned posting entry point in wp-admin.** Add a focused "Post" page under the existing top-level FOSSE menu. The first screen is the composer itself, not a landing page, explanation page, or block-editor wrapper.
2. **Minimal note composer.** The core form has a text field and an optional photo. It creates normal WordPress `post` records with no custom post type. Short notes use `post_format = status` so the existing ActivityPub and Atmosphere short-form discriminators treat them as native social posts.
3. **Short-note length discipline.** The UI and server both enforce a 300-grapheme note limit for the FOSSE quick composer. Longer writing belongs in the normal WordPress editor and the existing long-form strategy. This avoids silent Bluesky truncation on the happy path.
4. **Photo attachment with visible alt text.** When a photo is attached, the composer shows an editable alt-text field before publish. Server-side validation requires alt text for attached photos, stores it in `_wp_attachment_image_alt`, and keeps the attachment associated with the post in standard WordPress places.
5. **Photo storage is WordPress-native.** The attached image is uploaded through WordPress media APIs, parented to the created post, set as the featured image, and inserted into post content in a way themes can render. FOSSE does not invent a parallel media store.
6. **Cross-network send status after publish.** After posting, the UI shows per-network status rows for ActivityPub and Bluesky using the data-consistency status enum: not configured, pending, sent, retrying, failed, deleted, skipped, or a development-only unknown fallback. Failed rows expose a retry action when the backend says retry is available.
7. **Backend status dependency is explicit.** DOTCOM-16805 depends on `sdd/data-consistency-sync/` for durable send-state and retry semantics. This SDD consumes that backend contract and does not define database tables, post-meta persistence, queue internals, or retry scheduling beyond the UI-facing integration points.
8. **Quick-entry path follows core composer.** DOTCOM-16807 is a follow-on phase after the core composer, media/alt-text flow, and send-status UI. It should reuse the same validation and post-creation service rather than creating a second posting path.
9. **Quick-entry accepts shared context.** The follow-on path supports Press This-style query parameters for selected text, source URL, and title, and provides a bookmarklet/mobile-friendly admin URL that pre-fills the composer for logged-in users.
10. **No separate CPT.** FOSSE posts remain normal WordPress posts so the homepage stream, feeds, ActivityPub, Atmosphere, existing themes, and the normal editor all see one canonical object.
11. **No full block editor dependency.** The composer may link to the full editor after creation, but it should not embed or replicate the block editor. Use compact native admin UI and small vanilla JavaScript where needed for character count, image preview, and alt-text affordances.
12. **Accessible, repeatable admin UI.** The page should be dense enough for repeated use, keyboard-accessible, screen-reader labeled, and consistent with the existing FOSSE Settings/Status pages. Avoid marketing copy and explanatory text beyond necessary labels, validation messages, and status details.

## Constraints

- Self-hosted WordPress plugin first. No wp.com-only APIs or Calypso dependencies.
- PHP 8.2+, WordPress 6.9+, existing Composer classmap from `src/`.
- Existing FOSSE admin UI is PHP-rendered with native WordPress admin patterns. Stay with that unless a specific interaction requires JavaScript.
- Bundled code in `bundled/` must not be edited by hand. If image federation requires upstream changes in ActivityPub or Atmosphere, land those upstream and consume through `tools/sync-bundled.sh`.
- The composer must work when JavaScript is unavailable: the form can still submit text and a file input. JavaScript improves preview, counter, and alt-text visibility.
- Keep CI green: PHP unit tests, PHPCS, JS formatting/linting, Jest where JavaScript behavior is added, and Playwright E2E coverage.

## Out of Scope

- A replacement for the WordPress post editor.
- Draft management, scheduling, categories, tags, title editing, template selection, or reusable blocks in the FOSSE composer.
- Multiple images, galleries, video, audio, polls, quote-post composition, or thread composition.
- New backend persistence for send-state/retry. That belongs to `sdd/data-consistency-sync/`.
- Backend image embed support for Bluesky if Atmosphere does not already support it. This SDD stores image and alt data correctly in WordPress; backend federation support remains upstream/backend work.
- Reader/inbox features and inbound reactions.
- Multi-author editorial workflow beyond the current logged-in author creating a normal post.

## Existing Shipped Pieces

- `src/Admin/class-menu.php` registers the top-level FOSSE admin menu, Settings page, Status page, wizard, assets, and bundled-menu suppression.
- `src/Admin/class-setup-page.php` and `src/Admin/templates/setup-page.php` show the established PHP-template pattern for FOSSE admin pages.
- `src/Admin/class-status-page.php` and `src/Admin/templates/status-page.php` show provider-card rendering and status-oriented layout.
- `src/Admin/interface-connection-provider.php`, `class-ap-provider.php`, and `class-bluesky-provider.php` expose the provider registry/status pattern that the posting UI should reuse for labels and availability.
- `src/Admin/assets/css/admin.css` is the existing admin stylesheet. New composer styles should be added there under a `fosse-composer` prefix.
- `src/Admin/assets/js/wizard-appearance.js` is the current pattern for small, page-specific vanilla JavaScript.
- `src/class-object-type.php` projects FOSSE's short-form option across ActivityPub and Atmosphere.
- `src/class-long-form-strategy.php` projects FOSSE's long-form Bluesky strategy into Atmosphere.
- `src/class-post-types.php` keeps ActivityPub post-type selection aligned with Atmosphere syncable post types.
- Existing E2E tests under `tests/e2e/` use WordPress Playground, helper mu-plugins, and direct wp-admin navigation.

## Open Questions Resolved

- **Should this create a new content type?** No. Use normal WordPress posts and `post_format = status` for short notes.
- **Should the FOSSE composer allow long posts?** No for v1. The FOSSE-native composer is optimized for quick social notes. Longer content belongs in the full WordPress editor and the existing long-form publishing strategy.
- **Should alt text be optional?** No when a photo is attached. The composer should require explicit alt text for the attached photo before publishing.
- **Should quick-entry ship first?** No. Build the core composer first, then add quick-entry as a reuse layer over the same post-creation service.
- **Can send-status UI ship without durable backend state?** It can render an `unknown` state for development, but DOTCOM-16805 is only complete once `sdd/data-consistency-sync/` supplies durable per-provider status and retry behavior.

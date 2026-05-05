# Spec: FOSSE-Native Posting UI

## Goal

Ship a focused FOSSE composer for short social posts: text, optional photo, required photo alt text, one Post button, and immediate per-network delivery status. The implementation creates ordinary WordPress posts with `post_format = status`; FOSSE stays a better social posting surface on top of WordPress, not a new content silo.

## Requirements Summary

- FOSSE > Post admin page with the composer as the primary screen.
- Text-only or text-plus-one-photo posts.
- Normal `post` records, empty `post_title`, `post_status = publish`, current user as author, and `post_format = status`.
- Optional image upload stored through WordPress media APIs, with required alt text.
- Post-publish result screen with ActivityPub and Bluesky send states plus retry actions.
- Send-state and retry persistence are provided by `sdd/data-consistency-sync/`.
- Follow-on quick-entry path reuses the same composer service and validation.

## Chosen Approach

**PHP-rendered admin page with a small vanilla JavaScript enhancement layer.**

The existing FOSSE admin is PHP-rendered and already uses native WordPress admin pages, templates, `admin-post.php` handlers, and a single CSS file. The posting UI should fit that stack. React or the block editor would add a build/runtime dependency and pull the user into the editing model this feature is explicitly trying to avoid.

JavaScript is still useful for three direct interactions:

- live 300-grapheme counter;
- image preview when a file is selected;
- showing and focusing the alt-text input as soon as a photo is attached.

The server remains authoritative: it enforces capability, nonce, 300-grapheme limit, image mime/type validation, and alt-text requirement.

### Alternatives Considered

- **Embed the block editor.** Rejected. The goal is a minimal social composer, not a second entry point to the full editor.
- **Create a `fosse_note` CPT.** Rejected. Existing shipped work intentionally uses normal WordPress posts and `post_format = status` as the short-form signal.
- **Reuse `post-new.php` with prefilled status format.** Rejected for the core flow. It still exposes the full editor surface and fails the product demo of "type a sentence, attach a photo, hit post."
- **Build a React admin app.** Rejected for v1. The composer needs one form, one upload, and one status panel. Vanilla JavaScript keeps the repo's current toolchain.
- **Make quick-entry the main surface.** Rejected. Quick-entry is valuable but should reuse a proven core composer rather than drive the first implementation.

## Technical Details

### Architecture

```
fosse.php
  -> is_admin()
        -> Menu::register()
              -> add_menu_page('fosse')
              -> add_submenu_page('fosse', 'Post', 'fosse-post')
              -> Posting_Page::register_hooks()
              -> enqueue composer assets on FOSSE Post screens

src/Admin/class-posting-page.php
  -> render()                      -> templates/posting-page.php
  -> handle_publish()              -> admin_post_fosse_publish_note
  -> handle_retry_publication()    -> admin_post_fosse_retry_publication
  -> render_send_status_panel()

src/class-posting-service.php
  -> validate_payload()
  -> create_status_post()
  -> attach_photo()
  -> build_post_content()

Data-consistency-sync backend
  -> provides per-post provider statuses
  -> performs durable retry scheduling
```

### UI Layout

FOSSE > Post renders a compact work surface:

1. A textarea labeled "Post" with a visible remaining-count indicator.
2. A native image file input labeled "Photo" with an enhanced preview when JavaScript is available.
3. An alt-text input that is always visible when a photo is selected and included in the non-JavaScript form markup.
4. A primary "Post" submit button.
5. After publish, a result panel for the created post with provider send statuses and retry buttons.

The page should use existing WordPress admin controls and FOSSE admin panels:

- `.wrap`
- `.fosse-settings-panel` for the composer shell or a new `.fosse-composer-panel` if the layout needs tighter control;
- `.notice` for validation failures;
- concise headings and labels;
- no hero, onboarding copy, or marketing-style cards.

### Post Creation Data Flow

1. User submits `admin-post.php?action=fosse_publish_note`.
2. `Posting_Page::handle_publish()` verifies `manage_options` or the selected publishing capability, verifies nonce, and passes raw input to `Posting_Service`.
3. `Posting_Service` validates:
   - note text is non-empty after trimming;
   - note text is at most 300 graphemes;
   - uploaded file, when present, is an image WordPress accepts;
   - alt text is non-empty when a photo is present.
4. Service inserts a normal post:
   - `post_type = post`;
   - `post_status = publish`;
   - `post_author = get_current_user_id()`;
   - `post_title = ''`;
  - `post_content` initially contains sanitized paragraph/block content for the note text; escaping happens on output.
5. Service calls `set_post_format( $post_id, 'status' )`.
6. If a photo was uploaded, service uses WordPress media upload APIs, stores `_wp_attachment_image_alt`, parents the attachment to the post, sets `_thumbnail_id`, and updates `post_content` to include the image block after the text.
7. Handler redirects to `admin.php?page=fosse-post&posted=<post_id>`.
8. Result screen shows the published post link/edit link and the send-status panel.

### Image and Alt Text

The composer supports one image in v1. The image is stored in standard WordPress data so themes and federation backends can inspect it without FOSSE-specific knowledge:

- attachment post is parented to the created post;
- `_wp_attachment_image_alt` contains the submitted alt text;
- `_thumbnail_id` points at the attachment;
- post content includes the image block for front-end visibility.

If downstream Bluesky image publishing needs additional Atmosphere support, that work belongs upstream or in the backend SDD. The posting UI's responsibility is to make the image and alt text present in canonical WordPress fields.

### Send Status UI Contract

`sdd/data-consistency-sync/` owns the durable data model, retry scheduler, status taxonomy, and retry action. The posting UI consumes that contract rather than defining a parallel one.

```php
/**
 * Return normalized provider send statuses for a WordPress post.
 */
$statuses = Automattic\Fosse\Send_Status::for_post( $post_id );
$statuses = apply_filters( 'fosse_send_status_for_post', $statuses, $post_id );
```

The returned array is keyed by `activitypub` and `atproto`. The UI labels `atproto` as Bluesky, but keeps the backend key unchanged so retry/status logic stays shared with `sdd/data-consistency-sync/`.

Each provider status should provide:

```php
array(
	'provider_name'  => 'ActivityPub',
	'state'          => 'pending', // not_configured|pending|sent|retrying|failed|deleted|skipped.
	'message'        => '',
	'updated_gmt'    => '',
	'remote_url'     => '',
	'next_retry_gmt' => '',
	'error_code'     => '',
	'can_retry'      => false,
)
```

Retry is requested through the backend action:

```php
/**
 * Request retry for a provider publication. Network is activitypub or atproto.
 */
do_action( 'fosse_retry_publication', $post_id, $network );
```

The posting UI owns the form, nonce, capability check, redirect, and rendering. The backend owns deciding whether retry is allowed, recording the retry attempt, scheduling the send, and updating state.

If the backend class is unavailable during development, the status panel may render both providers as `unknown` with no retry buttons. DOTCOM-16805 is not complete until durable status and retry are wired through `sdd/data-consistency-sync/`.

### Quick-Entry Path

Quick-entry is a follow-on after the core composer. It reuses `Posting_Service` and the same validation rules.

Initial shape:

- `admin.php?page=fosse-post&quick=1` renders the same composer in compact mode.
- Query parameters prefill fields:
  - `text` - selected text or shared body;
  - `url` - source URL;
  - `title` - source title.
- If `url` is present, the composer appends it to the text in an editable way before submit.
- A bookmarklet link can be rendered from the FOSSE Post page for logged-in users. It opens the quick composer with the current page URL/title/selection.
- Mobile viewport E2E coverage verifies the compact composer does not overflow and can submit prefilled text.

A native mobile share-sheet target can be evaluated later if FOSSE adds a manifest/service-worker path. It is not required for the first quick-entry phase.

### File Changes

| File | Change Type | Description |
|------|-------------|-------------|
| `src/Admin/class-menu.php` | modify | Add FOSSE "Post" submenu, call `Posting_Page::register_hooks()`, and enqueue composer JS/CSS only on `fosse-post`. |
| `src/Admin/class-posting-page.php` | new | Render composer/result page, handle publish POST, handle retry POST, prepare template data. |
| `src/Admin/templates/posting-page.php` | new | Composer HTML and post-publish status panel shell. |
| `src/class-posting-service.php` | new | WordPress post creation, validation, image upload, alt text storage, post-content construction. |
| `src/Admin/assets/js/composer.js` | new | Grapheme counter, image preview, alt-text visibility/focus. No build step. |
| `src/Admin/assets/css/admin.css` | modify | Add `fosse-composer` prefixed layout, counter, preview, status-row, and mobile styles. |
| `tests/php/Posting_ServiceTest.php` | new | Unit tests for post creation, status format, validation, image/alt behavior. |
| `tests/php/Admin/Posting_PageTest.php` | new | Render/handler tests for nonce, capability, redirects, notices, and retry form. |
| `tests/php/Admin/MenuTest.php` | modify | Verify FOSSE Post submenu registration and asset enqueue targeting. |
| `tests/js/posting-composer.test.js` | new | JS unit tests for counter and preview/alt field behavior. |
| `tests/e2e/posting-ui.spec.ts` | new | Playground coverage for composer render, publish, image alt validation, result status panel, and quick-entry prefill. |
| `tests/e2e/mu-plugins/fosse-publication-status-seed.php` | new | Test-only provider status fixture for send-state and retry UI before the durable backend exists. |

## Dependency on Data Consistency / Sync

DOTCOM-16805 should not be considered complete until `sdd/data-consistency-sync/` provides durable statuses for ActivityPub and Bluesky/AT Protocol and a real retry path. The posting UI can be built in parallel against `Send_Status::for_post()` and `fosse_retry_publication`, but the final task must keep this SDD aligned with the data-consistency contract.

## Security and Capability Rules

- Rendering requires a logged-in user who can publish posts. If FOSSE keeps its admin pages restricted to `manage_options` for v1 consistency, document that as an MVP constraint in implementation notes.
- Publishing checks nonce and capability server-side.
- Retry checks nonce and capability server-side.
- File uploads use WordPress media APIs and mime validation.
- Redirects use `wp_safe_redirect`.
- User text, alt text, filenames, and status messages are escaped on output.

## Testing Strategy

- PHPUnit for post creation and handler behavior.
- Jest/jsdom for small JavaScript functions.
- Playwright for real wp-admin composer flows in Playground.
- Manual smoke test with JavaScript disabled for text-only and image upload fallback.

Required commands before PR:

```bash
composer run-script lint-php
composer run-script test-php
pnpm run format:check
pnpm run lint
pnpm test
pnpm exec playwright test tests/e2e/posting-ui.spec.ts
```

## Out of Scope

- Full editor replacement.
- Drafts, scheduling, tags/categories, titles, reusable blocks.
- Multiple images or non-image attachments.
- Backend retry persistence and queue internals.
- Upstream image embed implementation for Bluesky or ActivityPub.
- Public unauthenticated posting endpoints.

# Spec: Posting UI

## Goal

Three discoverable paths into a focused composer for short notes and federated replies. AP reply infrastructure already exists in bundled code and works today; FOSSE owns the composer page, the admin-bar entry, the bookmarklet generator, and the URL-construction logic that hands off to the existing AP reply-intent machinery. Bluesky outbound-reply support is intentionally not in v1 — the composer falls back gracefully for unrecognized sources.

## Decisions

### 1. Three quick-note entry points

| Surface | Trigger | Lands on |
|---------|---------|----------|
| Admin bar button | "FOSSE: Post" item in the WP admin bar, present on every wp-admin screen | `wp-admin/admin.php?page=fosse-post` |
| FOSSE → Post submenu | Click in the FOSSE menu sidebar | same URL |
| Press-This bookmarklet | Click the bookmark while on any third-party page | same URL with `?source_url=<url>&selection=<text>` |

All three land on the same composer page. The composer reads URL params (`source_url`, `selection`) to decide whether to show as "fresh note" mode or "reply to source" mode.

### 2. Composer shape

`FOSSE → Post` is a focused composer page registered as a FOSSE submenu. Visual grammar: looks like a tweet box, not the WordPress editor.

Fields:

- **Text** — multi-line textarea, autofocus on load. No character limit enforced in the UI (the long-form strategy projector handles oversized posts at publish time).
- **Source URL** (optional) — auto-populated when arriving via bookmarklet. If present and recognized as AP-detectable at submit time, the composer redirects to the standard editor with `?in_reply_to=<url>` instead of creating a quick-note post directly. This is the federated-reply path. Bluesky URLs and other unrecognized sources fall back to the quick-note path with a notice.
- **Photo** (optional) — single image upload via standard `wp_handle_upload`.
- **Alt text** (required when a photo is attached) — non-empty validation before publish.
- **Publish button** — single primary action. No drafts, no scheduling, no preview.

The composer renders inside `wp-admin` chrome (admin header, no sidebar collapse) so the user has full session context. Width is constrained (~600px) to feel composed rather than expansive.

### 3. Federated-reply flow

When the composer is submitted with a non-empty Source URL, FOSSE classifies the URL into one of two buckets:

- **AP-detectable** (Mastodon profile/post URL, ActivityPub-aware blog URL): redirect to `wp-admin/post-new.php?post_format=status&in_reply_to=<source-url>`. Bundled AP's `handle_in_reply_to_get_param` hook (`class-blocks.php:75`) and reply-intent JS plugin (`build/reply-intent/plugin.js`) take over: they pre-populate the standard block editor with an `activitypub/reply` block referencing the source. The user finishes writing in the standard editor, with full access to AP's pre-publish panel, content-warning controls, visibility settings, and the rest of the bundled federation UX.

- **Anything else** (Bluesky URL, news article, generic web page, malformed URL, empty): surface a notice — "Source URL not recognized as a federated post. Posting as a quick note instead." — and proceed with the quick-note path. The URL is not auto-inserted into the post body. The user can manually edit the URL out of the source field and re-submit if they want a clean quick-note.

The reply path therefore intentionally diverts the user to the standard editor when AP source is detected. The standard editor has the reply block, AP preview, federation panel — everything FOSSE would otherwise have to rebuild. FOSSE's contribution is just the URL routing and the focused composer surface.

### 4. Bookmarklet

A `~200-character` JavaScript snippet that runs in the user's browser on any page. Function:

```js
javascript:(function(){
  var u = encodeURIComponent(location.href);
  var s = encodeURIComponent(window.getSelection().toString().slice(0,500));
  window.open('https://EXAMPLE.com/wp-admin/admin.php?page=fosse-post&source_url=' + u + '&selection=' + s, 'fosse_post', 'width=620,height=580');
})();
```

Generated per-site (the `EXAMPLE.com` is interpolated to the current site URL) on the FOSSE Settings page. Surfaced as a "Drag this to your bookmarks bar" affordance with a copy-to-clipboard fallback.

The bookmarklet does NOT scrape or fetch the third-party page. It only captures `location.href` and the user's text selection. Source classification and rendering is server-side.

### 5. Image upload + alt text

Single image per quick-note. Alt-text required (non-empty validation before publish). Uses `media_handle_upload()` with standard WP attachment flow. Attached as featured image.

If the user wants multiple images, captions, or a gallery, they go to the standard editor. The bookmarklet result also lands in the standard editor (via the reply-intent path), so multi-image cases naturally route there.

### 6. Post lifecycle on publish

On submit (no source URL or unrecognized source):

1. Validate text is non-empty OR photo is attached.
2. If photo: validate alt-text is non-empty.
3. `wp_insert_post()` with `post_status='publish'`, `post_format='status'`, post content = the textarea text wrapped in a paragraph block.
4. If photo: `media_handle_upload()`, `set_post_thumbnail()`.
5. Standard `transition_post_status` fires → bundled AP and Atmosphere federate per their normal paths.
6. Redirect to `get_permalink( $post_id )`.

On submit failure: re-render the composer with `add_settings_error()` notice and the user's input preserved in the form fields.

## Lifecycle Matrix

| Scenario | V1 Behavior |
| --- | --- |
| User clicks admin-bar button, writes text, clicks Publish | New `post_format=status` post published; redirect to permalink. |
| User clicks bookmarklet on a Mastodon post page | FOSSE → Post opens with source_url filled. On submit, redirected to standard editor with `?in_reply_to=<mastodon-url>`. AP's reply-intent JS inserts the reply block. User finishes in editor. |
| User clicks bookmarklet on a Bluesky post page | FOSSE → Post opens with source_url filled. On submit, composer notice surfaces: "Source URL not recognized as a federated post. Posting as a quick note." User can edit URL out and proceed as a quick-note, or paste it into the body. |
| User clicks bookmarklet on a generic URL (e.g., a news article) | Same as the Bluesky case — unrecognized source → quick-note fallback. |
| Image attached without alt-text | Validation error on submit: "Alt-text is required for accessibility." Form re-renders with text + image preserved. |
| Empty text AND no photo | Validation error: "Add some text or a photo." |

## Out of Scope

- Replacing the standard block editor for any case other than quick-note posting. Long-form, scheduled posts, drafts, multi-image, custom blocks → standard editor.
- Post-publish unified send-status surface (DOTCOM-16805 — canceled).
- Per-post long-form Bluesky strategy override (the site-wide `fosse_long_form_strategy` from DOTCOM-16810 stays the only knob).
- Bluesky outbound-reply support. The Atmosphere-side work (resolve URL → URI+CID, thread into publisher) has no current driver. If users ask for it, file a separate FOSSE issue then.
- Front-end (theme-side) display of federated replies. That's the unified-homepage-display family of work — canceled per the related triage.
- Filing or implementing upstream Atmosphere work in this SDD. (A pre-publish federation sidebar panel for Bluesky is being proposed upstream as a standalone Atmosphere UX improvement — tracked separately, independent of this SDD.)

## Open Questions

- **Bookmarklet behavior on sites with strict Content Security Policy.** Many news sites + some Mastodon instances block javascript URIs in bookmarks via CSP. Document the CSP fallback (drag-and-drop fails silently; user copy-pastes the URL into FOSSE → Post manually).
- **Selection length cap.** `slice(0,500)` is arbitrary. Tune after seeing real bookmarklet usage.

## Tests

Required test coverage:

- `tests/php/Admin/Post_PageTest.php`: composer renders without fatal at `?page=fosse-post`; pre-fills `source_url` and `selection` from URL params.
- `tests/php/Admin/Post_PageTest.php`: submit with text only creates a `post_format=status` post and redirects to permalink.
- `tests/php/Admin/Post_PageTest.php`: submit with text + image (with alt-text) attaches the image as featured.
- `tests/php/Admin/Post_PageTest.php`: submit with image but no alt-text returns a validation error and re-renders.
- `tests/php/Admin/Post_PageTest.php`: submit with an AP source URL redirects to `post-new.php?post_format=status&in_reply_to=<url>`.
- `tests/php/Admin/Post_PageTest.php`: submit with an unrecognized URL (Bluesky URL, news URL, garbage) falls back to the quick-note notice and proceeds as quick-note.
- `tests/e2e/posting-ui.spec.ts`: e2e smoke — admin-bar entry visible, FOSSE → Post page reachable, simple text-only publish round-trips.
- `tests/e2e/posting-ui.spec.ts`: bookmarklet snippet on the Settings page contains the current site URL (regression guard).

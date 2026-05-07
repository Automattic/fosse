# Implementation Plan: Admin UX & A11y Polish

Based on: [sdd/admin-ux-polish/spec.md](./spec.md)

Ships in a single PR (`audit/admin-ux-polish`). All seven changes are independent of each other and small enough that splitting would create more review overhead than benefit.

## Progress

- [x] Task 1 [FOSSE]: Wrap wizard radio/checkbox groups in `fieldset` + `legend`
- [x] Task 2 [FOSSE]: Use `<th scope="row">` on Status card label cells; restyle in `admin.css`
- [x] Task 3 [FOSSE]: Add `aria-hidden="true"` to decorative wizard Dashicons
- [x] Task 4 [FOSSE]: Bump 12px contrast colors to WCAG AA
- [x] Task 5 [FOSSE]: Move inline form style to `admin.css`
- [x] Task 6 [FOSSE]: Rewrite "no providers available" copy to reflect bundling
- [x] Task 7 [FOSSE]: Memoize `get_status()` on Bluesky_Provider + AP_Provider
- [x] Task 8 [FOSSE]: Verify (`composer run-script test-php`, `composer run-script lint-php`, `pnpm run lint`, `pnpm run format:check`)

## Tasks

### Task 1: Wizard radio/checkbox groups

- **Status**: ✅ Done (this PR)
- **File**: `src/Admin/class-onboarding-wizard.php`

Wrap each group in `<fieldset class="...">` with a `<legend>`. Destination cards and mode cards use `class="screen-reader-text"` legends because the surrounding `<h1>` already names the section visually. Post-types groups upgrade the existing `<div class="fosse-post-types__group-label">` to a real `<legend>` so the visible label is also the semantic group label.

### Task 2: Status table row headers

- **Status**: ✅ Done (this PR)
- **Files**: `src/Admin/class-bluesky-provider.php`, `src/Admin/assets/css/admin.css`

Five label cells in `render_status_card()` change from `<td>` to `<th scope="row">`. CSS adds `font-weight: normal; text-align: left` on `.fosse-status-card__label` so the visual rendering doesn't change. The semantic upgrade is for assistive tech only.

### Task 3: aria-hidden on decorative Dashicons

- **Status**: ✅ Done (this PR)
- **File**: `src/Admin/class-onboarding-wizard.php`

Three Dashicons get `aria-hidden="true"`: the destination-card check, the mode-card icon, the mode-card check. Each sits next to text that already conveys the meaning; the icons are decorative.

### Task 4: Contrast fixes

- **Status**: ✅ Done (this PR)
- **File**: `src/Admin/assets/css/admin.css`

Three color changes, all on 12px wizard text:

- `.fosse-wizard__progress-step` (and `__progress-line`): `#949494` → `#707070` (4.74:1)
- `.fosse-wizard__progress-step.is-complete` (and `__progress-line.is-complete` / `__progress-dot.is-complete`): `#4ab866` → `#287340` (5.81:1 on white, 5.34:1 on `#edf8f1` soft background)
- `.fosse-wizard__hint p`: `#757575` on `#f0f0f0` → `#555` on `#f0f0f0` (7.46:1)

All three pass WCAG AA for normal text. Inline comments document the contrast ratios so a future tweak knows the floor.

### Task 5: Inline form style

- **Status**: ✅ Done (this PR)
- **Files**: `src/Admin/class-bluesky-provider.php`, `src/Admin/assets/css/admin.css`

`style="margin-bottom: 6px;"` on the auto-publish-recover form moves to `.fosse-auto-publish-recover__form { margin-bottom: 6px; }` in `admin.css`.

### Task 6: Bundled-backend copy

- **Status**: ✅ Done (this PR)
- **File**: `src/Admin/templates/status-page.php`

Replaces "Ensure ActivityPub and Atmosphere are installed" with operator-oriented copy that points at the bundled-backend failure modes (autoload, class conflicts, host-level disable).

### Task 7: Memoize get_status()

- **Status**: ✅ Done (this PR)
- **Files**: `src/Admin/class-bluesky-provider.php`, `src/Admin/class-ap-provider.php`

Per-instance `?array $status_cache` populated on the first call and returned thereafter. Bluesky's call decrypts the access token (cheap individually but worth caching on a render path that calls it twice per provider). AP's call dispatches `activitypub_construct_model_actor`, which third-party code can hang work off of. The cache lives across the entire request because the only mutators (`handle_connect`, `handle_disconnect`, `handle_oauth_callback`, `handle_enable_auto_publish`) all end in `wp_safe_redirect(); exit;` — no intra-request post-mutation reads.

### Task 8: Verify

- **Status**: ✅ Done (this PR)

```
composer run-script test-php       # full suite green (312 tests, 754 assertions)
composer run-script lint-php       # PHPCS clean
pnpm run lint                      # ESLint clean
pnpm run format:check              # Prettier clean
```

CI runs the full matrix on push.

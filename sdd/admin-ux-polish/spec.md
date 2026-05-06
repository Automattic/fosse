# Spec: Admin UX & A11y Polish

## Problem

The 2026-05-06 deep audit (`audits/2026-05-06-fosse-plugin-audit-report.md`) flagged a cluster of accessibility and admin-UX gaps under "WordPress Coding, Accessibility, And Admin UX". None of them are bugs that break a flow today; together they're the difference between "looks like a polished WordPress admin screen" and "looks like a passable plugin from 2018."

## Decisions

Take the audit's a11y and UX gaps that have a concrete file/line citation and fix them in one PR. Skip items that need product-direction input (token error recovery copy, GET-as-mutation refactor of wizard actions) and defer them to a follow-up.

### In scope

- **Semantic grouping for radio/checkbox groups.** The wizard's destination-cards (`class-onboarding-wizard.php:862-882`), mode-cards (lines 998-1020), and post-types-by-group (lines 1143-1188) wrap in `<fieldset>` with a `<legend>` (visible for the post-types group, screen-reader-only for the others where the surrounding heading already names the group). Matches the pattern in `templates/setup-page.php:61-77,100-165`.
- **Row-header semantics on Status tables.** Replace `<td class="fosse-status-card__label">` with `<th scope="row" class="fosse-status-card__label">` so screen readers announce the row label as a header. Visual style preserved via `font-weight: normal; text-align: left` in `admin.css`.
- **`aria-hidden` on decorative Dashicons.** Add `aria-hidden="true"` to the wizard card check / mode icon / destination check icons. They sit alongside text labels that already convey meaning; without `aria-hidden`, screen readers announce them as ignored elements anyway, but the explicit attribute makes intent obvious and aligns with WCAG H67.
- **Contrast fixes for 12px wizard text.** `#949494` on white (progress label) → `#707070` (4.74:1, WCAG AA). `#4ab866` on white (is-complete) → `#2c8049` (4.61:1, WCAG AA, stays in the green family). `#757575` on `#f0f0f0` (hint) → `#555` (7.46:1, WCAG AAA).
- **Move inline form style to `admin.css`.** `class-bluesky-provider.php:672` had `style="margin-bottom: 6px;"` on a form. Replace with class `fosse-auto-publish-recover__form` and put the rule in the already-enqueued `admin.css`.
- **Bundled-backend unavailable copy.** `templates/status-page.php` told users to "Ensure ActivityPub and Atmosphere are installed." FOSSE bundles them, so the message is misleading. Replace with copy that points operators at the bundled-backend bootstrap layer (autoload, class conflicts, host-level disable).
- **Memoize `get_status()`.** The Status page renders each provider's status twice per request — once when filtering on `connected`, once inside `render_status_card()`. Cache the result on the provider instance so the second call is a free array return. Touches both `Bluesky_Provider` and `AP_Provider`. The cache lives on the provider instance, which the registry holds for the request.

### Deferred

- **Long-form composition / object-type controls in FOSSE Settings.** Audit asked for this; a clean implementation depends on canonicalization (`sdd/canonical-upstream-options/`) landing first. Worth a separate PR once that ships and we have stable upstream option keys.
- **Effective-strategy + token-health visibility on Status.** Same — wants to follow the canonicalization PR, not block on it here.
- **Token error recovery parity between Settings and Status.** Audit notes Status has clearer recovery copy than Settings; the right fix is a copy decision, not a code change.
- **Wizard GET-as-mutation refactor.** Audit explicitly notes "this is not a CSRF finding" — moving wizard skip / reset / complete from nonced GET links to POST forms is a UX preference that touches several call sites, breaks current e2e specs, and adds no security. Deferred until there's a stronger reason.

## Out of scope

- Any CSS tokenization / design-system work. The contrast fixes touch the specific colors the audit flagged; broader color refactor is its own conversation.
- Any change to the wizard's overall flow / step layout / copy beyond the bundled-backend message above.

# Post-type sync + AP-option ownership pivot

Linear: [DOTCOM-16875](https://linear.app/a8c/issue/DOTCOM-16875)
Related: closed AP PR [Automattic/wordpress-activitypub#3218](https://github.com/Automattic/wordpress-activitypub/pull/3218); onboarding SDD at `sdd/onboarding-setup-ux/` (PR #25).

## Problem

Two separate but connected gaps:

1. **Post-type selection isn't projected into Atmosphere.** AP exposes a UI + stored option (`activitypub_support_post_types`, default `['post']`). AT exposes only a filter (`atmosphere_syncable_post_types`, default `['post']`). A user who wants `book` federated to Mastodon AND posted natively to Bluesky has to tick a checkbox in AP *and* write a PHP snippet for AT. The "publish once, reach everywhere" promise breaks as soon as CPTs enter the picture.

2. **The onboarding-setup-ux SDD (unimplemented) plans the wrong option-ownership model for AP settings.** It introduces `fosse_ap_actor_mode` and `fosse_ap_support_post_types` projected into AP via `pre_option_*` filters. That silently overrides AP's admin UI on read — user toggles a checkbox, FOSSE returns something else, admin becomes a lie. Two sources of truth, worst version. This was the "obvious" projection path, but the upstream PR thread on #3218 forced a rethink (AP maintainer pointed out the stock `option_activitypub_support_post_types` filter exists, which is what `pre_option_*` is really doing — and that's exactly the divergence problem).

## Approach

**AP's option is the source of truth.** No FOSSE-owned mirror options for AP settings. FOSSE's onboarding UI writes directly to `activitypub_actor_mode` and `activitypub_support_post_types`. AP's admin UI (until it's suppressed per [DOTCOM-16808](https://linear.app/a8c/issue/DOTCOM-16808)) stays canonical; both surfaces edit the same option, so neither lies.

**Cross-network projection lives in a new `Automattic\Fosse\Post_Types` class**, mirroring the shape of `src/class-object-type.php` but with one filter instead of two:

```php
namespace Automattic\Fosse;

class Post_Types {
    public static function register(): void {
        add_filter( 'atmosphere_syncable_post_types', array( self::class, 'filter_atmosphere' ) );
    }

    public static function filter_atmosphere( array $types ): array {
        $stored = get_option( 'activitypub_support_post_types', array( 'post' ) );
        return is_array( $stored ) ? $stored : array( 'post' );
    }
}
```

Registered from `fosse.php` via the same `init`-time `class_exists` guard used for `Object_Type`.

**Divergence from `Object_Type` is deliberate.** Object-type semantics ("force short-form everywhere" vs "defer to each network") are FOSSE-specific — so `Object_Type` owns its option. Post-type selection is not FOSSE-specific; it's "which post types federate," which is exactly what AP already stores. Same-shape data, reuse the store. Noted in the class docblock so future maintainers don't reflexively "fix" the asymmetry.

**Onboarding SDD amendment.** Replace `fosse_ap_*` + `pre_option_*` projection with direct writes to AP option keys. Applies to both post-types *and* actor_mode (user confirmed extending the pivot — same reasoning).

## Tasks

- [ ] Create `src/class-post-types.php` — `Automattic\Fosse\Post_Types` projector with `register()` and `filter_atmosphere()`. Docblock explains the deliberate asymmetry with `Object_Type`.
- [ ] Register it in `fosse.php` alongside `Object_Type::register()` inside the existing `init` callback (same `class_exists` guard pattern).
- [ ] Write `tests/php/Post_TypesTest.php` — cover: default (option unset → `['post']`), option set → value returned, empty array preserved (don't resurrect disabled federation), upstream default discarded, non-array option falls back to `['post']`, `register()` safe to call twice.
- [ ] Amend `sdd/onboarding-setup-ux/spec.md` — rewrite the "Option projection pattern" section to describe direct writes to AP option keys. Remove the `pre_option_*` mechanism.
- [ ] Amend `sdd/onboarding-setup-ux/plan.md` Task 3 — change `update_option()` target keys from `fosse_ap_*` to `activitypub_*`; delete the step that registers `pre_option_*` filters. Update Task 8's AP_Provider test description likewise.
- [ ] Amend `sdd/onboarding-setup-ux/requirements.md` — update the "AP settings ownership" open-question record.
- [ ] Amend `sdd/onboarding-setup-ux/planned-decisions.md` — replace the "FOSSE stores its own options" decision with the new "AP option is source of truth" direction, citing this SDD and DOTCOM-16875.
- [ ] Run `composer run-script lint-php` and `composer run-script test-php`. Both clean.
- [ ] Run `pnpm run format:check` (no JS changes expected, but required before push per AGENTS.md).
- [ ] Open PR against `trunk`; branch name ends in `-DOTCOM-16875`. Body references DOTCOM-16875, the closed AP PR, and the onboarding SDD amendment.

## Implementation Notes

Shipped on branch `post-type-sync-DOTCOM-16875` as PR [#31](https://github.com/Automattic/fosse/pull/31). Split into two commits:

1. `Add cross-network Post_Types projector for activitypub_support_post_types` — projector class, fosse.php registration, PHPUnit tests, this notes.md.
2. `SDD: amend onboarding-setup-ux to write AP options directly` — the four onboarding-setup-ux/*.md edits.

Deviations / decisions made during implementation:

- **Class name settled on `Post_Types`** (matches `Object_Type` — user choice, despite the known minor confusion with `Activitypub\Post_Types` in the bundled plugin; different namespaces).
- **`DEFAULT_TYPES` constant**, not `DEFAULT` — avoids PHP's reserved `default` keyword, which older toolchains may still trip on even where PHP 7+ nominally allows it.
- **Pivot scope extended to actor_mode** in the onboarding SDD (confirmed during brainstorm). Same reasoning applies — `pre_option_activitypub_actor_mode` would silently override AP's admin UI the same way. Dropped all `fosse_ap_*` options from the spec.
- **Also updated the "Deactivation/deletion" planned-decision entry** — the prior version leaned on `fosse_ap_*` as the cleanup surface. Post-pivot there's nothing FOSSE-owned to clean up on uninstall; AP's admin just resumes.
- **Filter callback ignores the upstream default passed in** (`unset( $types );`). The projector's contract is that AP's option wins unconditionally; any site-level post-type change belongs in AP's settings, not in AT's filter chain. Documented in the docblock.
- **`is_array` fallback rather than `(array)` cast** — `(array) 'scalar'` would return `['scalar']` and hand Atmosphere a malformed list. Explicit check + fallback to `DEFAULT_TYPES` is safer.

Follow-up / revisit triggers:

- When AP's admin UI is suppressed per DOTCOM-16808, add a FOSSE-side UI that edits `activitypub_support_post_types` directly. Still no FOSSE-owned mirror option.
- If AP ever renames `activitypub_support_post_types`, update the `AP_OPTION` constant in `Post_Types`. Single choke point by design.

# Implementation Plan: Canonical Upstream Options

Based on: [sdd/canonical-upstream-options/spec.md](./spec.md)

Ships in a single PR (`audit/canonical-upstream-options`) — the migrator and the projector deletions are coupled at the autoloader level, so splitting them would leave a window where the FOSSE option is read but never written.

## Progress

- [x] Task 1 [FOSSE]: Replace `Object_Type` projector with an AP→Atmosphere bridge
- [x] Task 2 [FOSSE]: Delete `Long_Form_Strategy` class, file, and tests
- [x] Task 3 [FOSSE]: Add `Canonical_Options_Migrator` + `admin_init` registration
- [x] Task 4 [FOSSE]: Update `fosse.php` registrations (drop Long_Form, add Migrator, refresh Object_Type comment)
- [x] Task 5 [FOSSE]: Update `Object_TypeTest` for the new option, add `Canonical_Options_MigratorTest`
- [x] Task 6 [FOSSE]: Update `AGENTS.md` upstream-policy worked examples
- [x] Task 7 [FOSSE]: Verify (`composer run-script test-php`, `composer run-script lint-php`, `pnpm run lint`, `pnpm run format:check`)

## Tasks

### Task 1: Replace `Object_Type` projector with an AP→Atmosphere bridge

- **Status**: ✅ Done (this PR)
- **File**: `src/class-object-type.php`

Rewrites the class to:

- Read `activitypub_object_type` (canonical) instead of `fosse_object_type`.
- Keep `filter_atmosphere`: when AP says `'note'`, force Atmosphere short-form true.
- Drop `filter_ap` entirely — ActivityPub already reads its own option when computing `Post::get_type()`.
- `register()` only registers the Atmosphere filter; the AP filter is no longer touched.

Verify: `Object_TypeTest` (rewritten in Task 5) passes; `class_exists` and `has_filter` checks confirm the AP filter is unregistered.

### Task 2: Delete `Long_Form_Strategy`

- **Status**: ✅ Done (this PR)
- **Files**: `src/class-long-form-strategy.php` (delete), `tests/php/Long_Form_StrategyTest.php` (delete)

Atmosphere reads `atmosphere_long_form_composition` directly via its own seed-from-option filter at priority 1 (`bundled/atmosphere/includes/class-atmosphere.php:75`). FOSSE keeping a parallel projector serves no purpose post-canonicalization.

The FOSSE-default-different-from-upstream concern (`'teaser-thread'` vs Atmosphere's `'link-card'`) moves to the migrator's fresh-install seed step (Task 3).

### Task 3: Add `Canonical_Options_Migrator`

- **Status**: ✅ Done (this PR)
- **File**: `src/class-canonical-options-migrator.php` (new)

`register()` hooks `maybe_migrate` onto `admin_init`. `maybe_migrate` short-circuits on the `fosse_canonical_options_migrated` flag, then runs `migrate_object_type()` and `migrate_long_form_strategy()` and sets the flag.

Migration semantics per spec § Migration. The fresh-install seed only fires when both the legacy FOSSE option AND the canonical Atmosphere option are unset — a site that already configured Atmosphere standalone before installing FOSSE keeps its choice.

### Task 4: Update `fosse.php`

- **Status**: ✅ Done (this PR)
- **File**: `fosse.php`

Two registration blocks change:

- Object_Type comment: reframes the block as a bridge driven by the canonical AP option, not a parallel FOSSE projector.
- Long_Form_Strategy block → Canonical_Options_Migrator block: same `init`-hook pattern, `class_exists` guard, calls `register()`.

### Task 5: Tests

- **Status**: ✅ Done (this PR)
- **Files**: `tests/php/Object_TypeTest.php` (rewrite), `tests/php/Canonical_Options_MigratorTest.php` (new)

`Object_TypeTest` covers default pass-through, `note` forcing, `wordpress-post-format` pass-through, unknown-value pass-through, legacy `fosse_object_type` no longer authoritative, and AP-side filter unregistered.

`Canonical_Options_MigratorTest` covers each migration branch (note → AP, pass-through values dropped, long-form moved, unknown long-form dropped, fresh-install seed, no-overwrite of existing canonical, flag set after migration, idempotent on re-run, hook attached on register).

### Task 6: Update `AGENTS.md`

- **Status**: ✅ Done (this PR)
- **File**: `AGENTS.md`

Both worked examples in the "Upstream contribution policy" section now describe the canonical-upstream pattern. The original examples remain referenced via PR/SDD links so the original-context decisions stay traceable.

### Task 7: Verify

- **Status**: ✅ Done (this PR)

```
composer run-script test-php       # full suite green (310 tests, 755 assertions)
composer run-script lint-php       # PHPCS clean
pnpm run lint                      # ESLint clean
pnpm run format:check              # Prettier clean
```

CI runs the full matrix on push.

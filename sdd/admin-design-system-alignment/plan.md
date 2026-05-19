# Implementation Plan: Server-rendered admin design-system alignment

Based on: [sdd/admin-design-system-alignment/spec.md](./spec.md)

Ships in a single PR. The changes are coupled around the same CSS primitives
and should be reviewed together so Settings and Status stay visually coherent.

## Progress

- [x] Task 1 [FOSSE]: Add SDD docs and roadmap entry
- [x] Task 2 [FOSSE]: Add failing Playwright coverage for non-wizard admin visual constraints
- [x] Task 3 [FOSSE]: Align non-wizard admin CSS tokens, page shell, cards, and fields
- [x] Task 4 [FOSSE]: Run targeted and full verification

## Tasks

### Task 1: SDD docs and roadmap entry

- **Status**: ✅ Done (#158)
- **Files**:
  - `sdd/admin-design-system-alignment/spec.md`
  - `sdd/admin-design-system-alignment/plan.md`
  - `sdd/roadmap.md`

Add this SDD as a public-safe follow-up to `admin-ux-polish`. Do not link to
non-public discussion URLs; refer to the source material as `pbjpUB-zL-p2`.

Verify: `pnpm run format:check` includes the new Markdown files without
Prettier churn.

### Task 2: Playwright coverage

- **Status**: ✅ Done (#158)
- **File**: `tests/e2e/status-page.spec.ts`

Update the existing Status-page polish coverage so it asserts:

- The Status page has no horizontal overflow.
- The FOSSE Status heading is compact (`font-size <= 24px`).
- The status summary and provider cards use `border-radius <= 4px`.
- The status summary and provider cards have no custom `box-shadow`.
- The Status page still exposes the expected connection-management and wizard
  links.

Run:

```bash
pnpm run test:e2e -- tests/e2e/status-page.spec.ts
```

Expected before Task 3: fail on the stricter radius/shadow/title assertions.
Observed RED run: `FOSSE Status` heading failed at `32px` against the new
`<= 24px` assertion.

### Task 3: CSS alignment

- **Status**: ✅ Done (#158)
- **File**: `src/Admin/assets/css/admin.css`

Make the smallest CSS-only alignment pass:

- Keep the shared `--fosse-ui-*` variables compatible with the wizard.
- Add non-wizard `.fosse-admin-page` overrides for compact radius and no shadow.
- Reduce non-wizard page title sizing to wp-admin scale.
- Keep Settings and Status cards visually quiet: compact border radius, no
  decorative shadow, restrained section spacing.
- Keep existing responsive behavior and overflow protections intact.

Run:

```bash
pnpm run test:e2e -- tests/e2e/status-page.spec.ts
```

Expected after implementation: pass.
Observed GREEN run: `pnpm run test:e2e -- tests/e2e/status-page.spec.ts`
passed all 5 Status-page tests.

### Task 4: Verification

- **Status**: ✅ Done (#158)

Run the cheap local gates before pushing:

```bash
composer run-script test-php
composer run-script lint-php
pnpm run lint
pnpm run format:check
pnpm run test:e2e -- tests/e2e/status-page.spec.ts
```

Observed:

```bash
composer run-script test-php       # OK (650 tests, 1471 assertions)
composer run-script lint-php       # PHPCS clean (63 files)
pnpm run lint                      # ESLint clean (React detection warning only)
pnpm run format:check              # Prettier clean
pnpm run test:e2e -- tests/e2e/status-page.spec.ts # 5 passed
pnpm run test:e2e -- tests/e2e/onboarding-wizard.spec.ts # 22 passed
```

Note: the first sandboxed PHP runs could not create PHP temp files. The full
PHP checks were rerun outside the sandbox. `composer dump-autoload` was also
needed because the local Composer classmap predated the trunk commit that added
`Automattic\Fosse\Photo_Post`. The first attempt to run the Status and wizard
e2e specs in parallel collided on Playground's fixed `9400` port; rerunning the
Status spec by itself passed.

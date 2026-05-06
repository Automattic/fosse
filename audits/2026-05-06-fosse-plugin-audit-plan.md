# FOSSE Current-Trunk Deep Audit Plan

**Audit date:** 2026-05-06
**Target checkout:** `origin/trunk` at `1437a14 fix: remove Bluesky auto-publish toggle from Settings UI (#101)`
**Repository:** `/Users/kraft/.codex/worktrees/3528/fosse`
**Excluded from direct review:** `bundled/activitypub/`, `bundled/atmosphere/`, generated dependency directories.

## Operating Assumptions

-   Audit is read-only except for creating audit artifacts under `audits/`.
-   Bundled plugins are excluded from direct security/code-quality findings, but their APIs and observable behavior may be inspected as reference material for FOSSE integration claims.
-   GitHub/open PR state is out of scope; the audit target is current local `origin/trunk`.
-   WordPress design review covers existing wp-admin surfaces and missing editor/front-end/posting affordances needed for a photoblog workflow.
-   `/Users/kraft/code/wpcom-a8c-themes/blurt` is read-only; it may be inspected, and local commands may be run only if they do not modify the theme.
-   Pixelfed compatibility is treated as a first-class ActivityPub target for the photoblog assessment.

## Required Outputs

-   `audits/2026-05-06-fosse-plugin-audit-plan.md` - this execution plan.
-   `audits/2026-05-06-fosse-plugin-audit-report.md` - synthesized findings and backlog.
-   Agent notes in `audits/2026-05-06-agent-notes/` when a sub-audit produces useful raw evidence.

## Agent Team

### Agent A: Security, Privacy, and Trust Boundaries

Scope:

-   `fosse.php`
-   `src/**/*.php`, excluding `src/Bundled/` only when behavior is purely wrapper bootstrap
-   `src/Admin/templates/*.php`
-   `bin/build-zip.sh`
-   `.github/workflows/*.yml`
-   `composer.json`, `package.json`

Questions:

-   Are nonce, capability, escaping, sanitization, redirect, option-write, and hook-boundary patterns correct?
-   Can bundled-backend bootstrap or standalone plugin detection produce unsafe class loading, state drift, or surprising activation behavior?
-   Are network credentials, OAuth setup, handles, DIDs, and connection states exposed or mutated safely?
-   Are release/build paths safe enough for a plugin distributed as a zip?

Output:

-   Confirmed issues only, with file/line evidence, severity, impact, and minimal fix.
-   Explicit "not found in code" notes for high-risk vectors checked but not present.

### Agent B: WordPress Coding and Admin UX Standards

Scope:

-   `src/Admin/**/*.php`
-   `src/Admin/assets/**/*`
-   `src/Admin/templates/*.php`
-   `fosse.php`
-   `.phpcs.xml.dist`, `eslint.config.mjs`, package scripts

Questions:

-   Does the plugin follow WordPress/WPCS conventions beyond mechanical linting?
-   Are admin menus, notices, settings forms, nonces, capability checks, escaping, translation, and enqueue patterns idiomatic?
-   Does the admin UI match modern WordPress admin design expectations for progressive disclosure, connection status, error recovery, and accessible controls?
-   Are there missing REST/editor/block patterns that would make the UX feel outdated or brittle?

Output:

-   Confirmed code-standard defects, UX/design gaps, and adoption opportunities.
-   Distinguish "bug", "standards gap", and "product/design opportunity".

### Agent C: QA Strategy and Test Quality

Scope:

-   `tests/php/**/*.php`
-   `tests/js/**/*.js`
-   `tests/e2e/**/*.ts`
-   `tests/e2e/mu-plugins/*.php`
-   `phpunit.xml.dist`, `jest.config.js`, `playwright.config.ts`
-   CI workflows

Questions:

-   Do unit/e2e tests exercise real behavior rather than mocked plumbing?
-   Do tests cover happy paths, sad paths, permissions, invalid input, disconnected providers, network absence, and shared-state cleanup?
-   Are tests brittle because they depend on ordering, single-worker assumptions, reused servers, hidden fixture state, or upstream UI internals?
-   Which missing test layers would most improve confidence: integration tests, REST tests, accessibility checks, visual regression, Pixelfed compatibility fixtures, build-zip smoke tests?

Output:

-   Test defects and coverage gaps ranked by risk.
-   Concrete proposed tests with target files and scenarios.

### Agent D: Federation, Network Semantics, and Surface Area

Scope:

-   `src/class-object-type.php`
-   `src/class-long-form-strategy.php`
-   `src/class-post-types.php`
-   `src/class-reactions-label.php`
-   provider classes and status formatting
-   relevant upstream reference APIs in `bundled/activitypub/` and `bundled/atmosphere/`
-   SDD docs for implemented federation behavior

Questions:

-   Does FOSSE project post types, object types, reactions, and long-form strategy in line with ActivityPub and atproto/Atmosphere expectations?
-   Does it expose enough surface area for downstream extension without overfitting to bundled implementations?
-   Are there unresolved adoption surfaces: media attachment semantics, alt text, image collections, galleries, comments/replies, mentions, tags, privacy, update/delete, retries, error visibility?
-   Are there places where correctness should move upstream rather than remain in FOSSE?

Output:

-   Integration issues, missing extension points, and upstream-vs-FOSSE ownership recommendations.

### Agent E: Photoblog and Blurt/Pixelfed Workflow

Scope:

-   FOSSE admin and projection code
-   FOSSE tests and SDD docs
-   `/Users/kraft/code/wpcom-a8c-themes/blurt`
-   Pixelfed-facing ActivityPub expectations as observable from FOSSE/ActivityPub behavior

Questions:

-   If starting a photoblog with FOSSE, what is missing for image-first publishing?
-   How easy is it to add the missing pieces as a plugin/theme extension versus needing FOSSE changes?
-   Does Blurt's UI make image-first posting natural enough for Pixelfed to ingest useful objects?
-   What metadata shape would Pixelfed likely need: primary image attachments, alt text, object type, summary/content, tags, sensitive media, collection ordering?

Output:

-   Photoblogger workflow assessment, missing product surface, and implementation difficulty by layer.

## Execution Steps

1. Confirm the worktree is clean and anchored to `origin/trunk`.
2. Dispatch Agents A-E in parallel with strict read-only scopes.
3. In the main session, inspect source and tests independently enough to validate and merge agent findings.
4. Run cheap local verification:
    - `composer run-script lint-php`
    - `composer run-script test-php`
    - `pnpm run format:check`
    - `pnpm run lint`
    - `pnpm test`
5. Run e2e only if dependencies and browser/runtime are already available or can run without disruptive setup:
    - `pnpm run test:e2e`
6. Synthesize all findings into `audits/2026-05-06-fosse-plugin-audit-report.md`.
7. Final sanity check:
    - No direct findings against `bundled/`.
    - Every defect cites files/lines or is labeled as an opportunity/gap.
    - Tests gaps distinguish missing coverage from bad existing tests.
    - Photoblog assessment separates FOSSE, Blurt, upstream ActivityPub, upstream Atmosphere, and Pixelfed concerns.
    - Report has a prioritized backlog suitable for follow-up planning.

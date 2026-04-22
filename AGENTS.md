# FOSSE — Agent Instructions

## Project Overview

FOSSE is a WordPress plugin bringing Social Web (ActivityPub-adjacent) features to WordPress sites. This repository is a single standalone plugin, not a monorepo.

-   **Repository:** `Automattic/fosse`
-   **Main branch:** `trunk`
-   **Plugin header:** `fosse.php`

## Tech Stack

| Component            | Version / Tool                                                                                 |
| -------------------- | ---------------------------------------------------------------------------------------------- |
| PHP                  | >=8.2                                                                                          |
| WordPress            | >=6.9                                                                                          |
| PHPUnit              | ^9.6 \|\| ^11.0 (polyfills via `yoast/phpunit-polyfills`)                                      |
| Test harness         | `automattic/wordbless` (dbless engine; no MySQL needed)                                        |
| PHP Coding Standards | `automattic/jetpack-codesniffer` (ruleset: `Jetpack`) — installed in `tools/`, runs on PHP 8.4 |
| JS Coding Standards  | `@wordpress/eslint-plugin` + `@wordpress/prettier-config`                                      |
| JS Tests             | Jest (jsdom)                                                                                   |
| E2E                  | Playwright against WordPress Playground (`@wp-playground/cli`)                                 |
| Package Manager (JS) | pnpm (via Corepack)                                                                            |
| CI                   | GitHub Actions (`.github/workflows/`)                                                          |

## Directory Structure

```
fosse/
├── fosse.php                  # Plugin main file + header
├── src/                       # Plugin source (PHP) — classmap autoloaded
├── bin/
│   └── build-zip.sh           # Builds build/fosse.zip (composer build-zip)
├── bundled/                   # Vendored release builds of wordpress-activitypub
│   ├── activitypub/           #   and wordpress-atmosphere. Refreshed via
│   └── atmosphere/            #   tools/sync-bundled.sh. Do not edit by hand.
├── tests/
│   ├── php/                   # PHPUnit tests (WorDBless, *Test.php suffix)
│   │   └── bootstrap.php
│   ├── js/                    # Jest tests (*.test.js)
│   └── e2e/                   # Playwright specs + Playground blueprint
│       └── blueprint.json
├── tools/                     # Isolated composer project for PHPCS (PHP 8.4+)
│   ├── composer.json
│   ├── sync-bundled.sh        # Refresh bundled/ from upstream checkouts
│   └── bundled-excludes.txt   # Rsync exclude list for sync-bundled.sh
├── sdd/                       # Spec-Driven Development docs (per-feature)
├── .github/
│   ├── workflows/             # tests.yml, linting.yml, e2e.yml, build-zip.yml
│   └── dependabot.yml
├── .phpcs.xml.dist            # Jetpack ruleset, text-domain: fosse
├── phpunit.xml.dist
├── playwright.config.ts
├── eslint.config.mjs
├── jest.config.js
├── composer.json              # Plugin runtime + phpunit/wordbless dev deps
├── package.json               # JS dev deps + scripts
└── AGENTS.md                  # this file
```

## Commands

### First-time setup

```bash
corepack enable
composer install
composer install --working-dir=tools
pnpm install
pnpm exec playwright install --with-deps chromium   # first e2e run only
```

### PHP tests

```bash
composer run-script test-php
```

Runs PHPUnit against WordPress booted via WorDBless (dbless engine — no database).

### PHP linting

```bash
composer run-script lint-php       # check
composer run-script fix-php        # auto-fix
```

Uses the `Jetpack` PHPCS ruleset from `automattic/jetpack-codesniffer` installed in `tools/`. PHPCS requires PHP 8.2+; if your local PHP is older, run it in CI or bump your local PHP.

### JS tests & linting

```bash
pnpm test                          # Jest
pnpm run lint                      # ESLint
pnpm run lint:fix                  # ESLint --fix
pnpm run format                    # Prettier --write
pnpm run format:check              # Prettier --check
```

### E2E tests

```bash
pnpm run test:e2e
```

Boots WordPress Playground on `127.0.0.1:9400` via the blueprint at `tests/e2e/blueprint.json`, mounts the repo as the `fosse` plugin, and runs Playwright specs from `tests/e2e/`.

### Build plugin zip

```bash
composer run-script build-zip
```

Produces `build/fosse.zip` — a drop-in plugin bundle containing `fosse.php`, `src/`, and a production (`--no-dev`) `vendor/`. Set `FOSSE_VERSION` to override the `Version:` header stamped into the staged `fosse.php` (e.g. `FOSSE_VERSION=0.1.0 composer build-zip`). CI (`.github/workflows/build-zip.yml`) runs the same script to attach the zip to every published release and to refresh a rolling `latest-trunk` prerelease (tag + release, not a stable build) on each push to `trunk`.

### Refresh bundled federation plugins

```bash
./tools/sync-bundled.sh
```

Re-vendors `bundled/activitypub/` and `bundled/atmosphere/` from local upstream checkouts. Configure sources via env vars:

-   `FOSSE_AP_SOURCE` — path to the wordpress-activitypub checkout (default: `~/code/wordpress-activitypub`)
-   `FOSSE_ATMO_SOURCE` — path to the wordpress-atmosphere checkout (default: `~/code/wordpress-atmosphere`)

The script runs `composer install --no-dev --optimize-autoloader` inside the Atmosphere source before rsyncing so the vendored copy is self-contained. Bundling the federation backends is a short-term bootstrap; long-term we expect to drop this in favor of a cleaner distribution approach.

## Code Conventions

This project follows **WordPress Coding Standards (WPCS)** for all PHP code, enforced via the Jetpack PHPCS ruleset.

### PHP

-   **Jetpack ruleset** (WordPress-Extra + VariableAnalysis + PHPCompatibilityWP + selected MediaWiki sniffs).
-   **Tabs for indentation.**
-   **Yoda conditions** (`if ( null === $var )`).
-   **PHPDoc on public/protected methods** with `@param`, `@return`.
-   **Text domain:** `fosse`.
-   **Namespace:** `Automattic\Fosse\…` (classmap autoload from `src/`).
-   **Files in `tests/php/`** are namespaced `Automattic\Fosse\Tests\…` via PSR-4 (so `*Test.php` naming is fine; `WordPress.Files.FileName` is relaxed there).

### JavaScript / TypeScript

-   `@wordpress/eslint-plugin` recommended config.
-   Prettier formatting (`@wordpress/prettier-config`).
-   Tabs for indentation; single quotes; spaces inside parens (WordPress style).

### Tests

-   PHP: extend `\WorDBless\BaseTestCase`. Use `@before`/`#[Before]` and `@after`/`#[After]` (not `setUp`/`tearDown` directly). Suffix files with `Test.php`.
-   E2E: Playwright `test(...)` blocks under `tests/e2e/*.spec.ts`.

### Commits

-   Imperative mood ("Add X", not "Added X" / "Adds X").
-   Component prefix when helpful: `Tests: add smoke test for X`.

## Before Pushing

Run the lint suite at minimum before pushing any branch or opening a PR:

```bash
composer run-script lint-php       # PHPCS (Jetpack ruleset)
pnpm run format:check              # Prettier
pnpm run lint                      # ESLint
```

The full CI matrix runs on push, but catching formatting and style failures locally saves a round trip through GitHub Actions — and avoids re-triggering the Copilot PR review bot (and its review-points budget) on every retry push. PHPUnit and E2E can wait for CI; the linters are cheap and should be clean before the first push.

## CI Matrix

-   `.github/workflows/tests.yml` runs PHPUnit across PHP 8.2/8.3/8.4/8.5 × WP 6.9/trunk. Trunk rows are `continue-on-error`. WP 7.0 covers via the `trunk` row until 7.0 releases, then it gets added as its own column.
-   `.github/workflows/linting.yml` runs PHPCS (PHP 8.4) and ESLint/Prettier (Node 20). Path filters skip unaffected jobs on PRs.
-   `.github/workflows/e2e.yml` runs Playwright against Playground.
-   `.github/workflows/build-zip.yml` builds `fosse.zip` in a `contents: read` job, then publishes via separate `contents: write` jobs: pushes to `trunk` refresh the rolling `latest-trunk` prerelease; published releases get the zip attached directly.

## Common Pitfalls

1. **Lint deps live in `tools/composer.json`, not root.** `automattic/jetpack-codesniffer` pins recent dependencies that can conflict with plugin-runtime deps; keeping it isolated in `tools/` avoids resolver churn when we add new runtime requirements.
2. **WorDBless copies its `db.php` drop-in via a composer post-install hook.** If tests suddenly fail with wpdb errors, re-run `composer install`.
3. **`wordpress/` is a Composer-managed directory (`roots/wordpress`).** Never edit files inside it — `composer install` will overwrite them.
4. **PHPUnit runs with `failOnWarning` and `failOnRisky`.** Output during tests also fails them. Keep tests quiet.
5. **Playground mounts the repo root as the plugin directory.** The blueprint expects `fosse.php` to be at repo root; don't move it without updating `tests/e2e/blueprint.json` and `playwright.config.ts`.
6. **`pnpm install --frozen-lockfile` in CI** means you must commit `pnpm-lock.yaml` after adding/bumping JS deps.
7. **`bundled/` is vendored upstream code.** Excluded from PHPCS, PHPUnit, ESLint, Prettier, Jest, and the composer classmap. Don't re-enable those checks for it. Refresh via `tools/sync-bundled.sh`; never hand-edit files inside `bundled/`.
8. **Bundled-plugin activation runs on `init`, not `plugins_loaded`.** ActivityPub's `activate()` calls `flush_rewrite_rules()`, which needs `$wp_rewrite` (initialized on `init`). `fosse.php` defers the first-load bootstrap accordingly; don't move it earlier without accounting for that.
9. **Commit `composer.lock` alongside every `composer.json` change.** `bin/build-zip.sh` runs `composer validate --no-check-all --no-check-publish` before install and fails hard on drift. For metadata-only edits (PHP floor, `exclude-from-classmap`, autoload paths), regenerate with `composer update --lock` rather than a full `composer update`. The earlier call to untrack the lock for PHP-matrix flexibility went away when we consolidated on PHP 8.2.

## Upstream contribution policy

Rule of thumb: **post-type-agnostic correctness goes upstream; FOSSE-shape-specific behavior stays in FOSSE.** If a fix or new hook is useful to any site running `wordpress-activitypub` or `wordpress-atmosphere` on its own, land it in that repo — not in `bundled/`, not as a FOSSE shim. FOSSE then consumes it via `tools/sync-bundled.sh`.

Worked example from the Bluesky-native-publishing epic:

-   **Upstream** — Atmosphere's `atmosphere_is_short_form_post` discriminator and ActivityPub's `activitypub_post_object_type` filter on `Post::get_type()`. Both describe a universal notion ("is this a short-form post / what AP object type should it become?") and are valuable to any consumer of those plugins.
-   **FOSSE** — the `Automattic\Fosse\Object_Type` projector that reads the `fosse_object_type` option and drives both upstream filters in lockstep. This only makes sense inside FOSSE's "publish once, reach everywhere" model where AP and atproto must agree on the shape of a given post.

See PR #18 (DOTCOM-16812) for the full decision record.

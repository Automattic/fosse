# FOSSE — Agent Instructions

## Project Overview

FOSSE is a WordPress plugin bringing Social Web (ActivityPub-adjacent) features to WordPress sites. This repository is a single standalone plugin, not a monorepo.

-   **Repository:** `Automattic/fosse`
-   **Main branch:** `trunk`
-   **Plugin header:** `fosse.php`

## Tech Stack

| Component            | Version / Tool                                                                                 |
| -------------------- | ---------------------------------------------------------------------------------------------- |
| PHP                  | >=7.4                                                                                          |
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
├── tests/
│   ├── php/                   # PHPUnit tests (WorDBless, *Test.php suffix)
│   │   └── bootstrap.php
│   ├── js/                    # Jest tests (*.test.js)
│   └── e2e/                   # Playwright specs + Playground blueprint
│       └── blueprint.json
├── tools/                     # Isolated composer project for PHPCS (PHP 8.4+)
│   └── composer.json
├── .github/
│   ├── workflows/             # tests.yml, linting.yml, e2e.yml
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

## Code Conventions

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
-   No conventional-commit prefixes.

## CI Matrix

-   `.github/workflows/tests.yml` runs PHPUnit across PHP 7.4/8.0/8.1/8.2/8.3/8.4/8.5 × WP 6.9/7.0/trunk. Trunk rows are `continue-on-error`.
-   `.github/workflows/linting.yml` runs PHPCS (PHP 8.4) and ESLint/Prettier (Node 20). Path filters skip unaffected jobs on PRs.
-   `.github/workflows/e2e.yml` runs Playwright against Playground.

## Common Pitfalls

1. **Do not put `automattic/jetpack-codesniffer` in the root `composer.json`.** It requires PHP 8.2+; the root must install on PHP 7.4 for the test matrix. Lint deps live in `tools/composer.json`.
2. **WorDBless copies its `db.php` drop-in via a composer post-install hook.** If tests suddenly fail with wpdb errors, re-run `composer install`.
3. **`wordpress/` is a Composer-managed directory (`roots/wordpress`).** Never edit files inside it — `composer install` will overwrite them.
4. **PHPUnit runs with `failOnWarning` and `failOnRisky`.** Output during tests also fails them. Keep tests quiet.
5. **Playground mounts the repo root as the plugin directory.** The blueprint expects `fosse.php` to be at repo root; don't move it without updating `tests/e2e/blueprint.json` and `playwright.config.ts`.
6. **`pnpm install --frozen-lockfile` in CI** means you must commit `pnpm-lock.yaml` after adding/bumping JS deps.

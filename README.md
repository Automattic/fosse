# FOSSE

FOSSE is a WordPress plugin that simplifies Social Web publishing. It gives site owners one place to configure how WordPress posts are represented across federated networks, then routes publishing through the right backend for each protocol.

It bundles [wordpress-activitypub](https://github.com/Automattic/wordpress-activitypub) and [wordpress-atmosphere](https://github.com/Automattic/wordpress-atmosphere) so a site can publish to ActivityPub networks such as Mastodon and AT Protocol networks such as Bluesky from one WordPress plugin, without managing separate plugin surfaces for each network.

Repository: <https://github.com/Automattic/fosse>

## Requirements

-   PHP 8.2+
-   WordPress 6.9+
-   Composer
-   Node.js 20+ with Corepack / pnpm

## Use

Install and activate FOSSE like a normal WordPress plugin. For a distributable build, run:

```bash
composer run-script build-zip
```

That creates `build/fosse.zip`, which can be uploaded or unpacked into `wp-content/plugins/`.

After activation, go to **FOSSE** in wp-admin:

-   **Setup** configures ActivityPub actor mode, federated post types, and Bluesky connection.
-   **Status** shows the available providers and connection health.
-   The first-run wizard walks through the basic ActivityPub and Bluesky setup.

## Local Setup

```bash
corepack enable
composer install
composer install --working-dir=tools
pnpm install
```

For the first end-to-end test run, install Chromium:

```bash
pnpm exec playwright install --with-deps chromium
```

## Test

```bash
composer run-script test-php     # PHPUnit via WorDBless
pnpm test                        # Jest
pnpm run test:e2e                # Playwright + WordPress Playground
```

Before pushing, run the lint checks:

```bash
composer run-script lint-php
pnpm run format:check
pnpm run lint
```

## Notes

-   `src/` contains FOSSE's glue code and admin UI.
-   `bundled/` contains vendored upstream plugin builds; do not edit it directly.
-   See [CONTRIBUTING.md](./CONTRIBUTING.md) and [AGENTS.md](./AGENTS.md) for workflow, coding standards, CI details, and upstream contribution policy.

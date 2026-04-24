# Contributing to FOSSE

FOSSE is a WordPress plugin that bundles [`wordpress-activitypub`](https://github.com/Automattic/wordpress-activitypub) and [`wordpress-atmosphere`](https://github.com/Automattic/wordpress-atmosphere) so a site gets ActivityPub (Fediverse / Mastodon) and AT Protocol (Bluesky) federation out of the box. PRs from outside Automattic are welcome.

The canonical reference for project mechanics — tech stack, directory layout, commands, coding standards, CI — lives in [`AGENTS.md`](./AGENTS.md). This file explains how we work; `AGENTS.md` explains what to run.

---

## For any contributor

### Orientation

-   Main branch: `trunk`. PRs target `trunk` unless you're stacking on an explicit base branch (see SDD workflow below).
-   Tech stack, directory layout, namespaces, and commands: see [`AGENTS.md`](./AGENTS.md).
-   User-facing behavior is driven by the two bundled backends. FOSSE's own code (under `src/`) glues them together and adds cross-network behavior like the `fosse_object_type` projector.

### Local dev environment

See the **Commands** section of [`AGENTS.md`](./AGENTS.md#commands) for the full setup. Short version:

```bash
corepack enable
composer install
composer install --working-dir=tools
pnpm install
pnpm exec playwright install --with-deps chromium   # first e2e run only
```

PHPCS lives in `tools/` (isolated from plugin runtime deps) and should be installed and run with PHP 8.4 to match `tools/composer.json`'s platform pin and CI. PHPUnit runs against WordPress booted via WorDBless — no MySQL required. E2E uses Playwright against WordPress Playground.

### PR expectations

-   **Keep PRs small and scoped.** One concern per PR. If you find yourself writing "and also fixes…" in the description, split it.
-   **Run the lint suite before pushing.** The CI matrix runs the full thing on push, but local lint saves a round trip (and doesn't burn through the Copilot review-bot budget on retry pushes):

    ```bash
    composer run-script lint-php       # PHPCS (Jetpack ruleset)
    pnpm run format:check              # Prettier
    pnpm run lint                      # ESLint
    ```

    PHPUnit and E2E can wait for CI; the linters should be clean on the first push. See [`AGENTS.md`'s "Before Pushing"](./AGENTS.md#before-pushing) for the rationale.

-   **Never hand-edit `bundled/`.** That directory is vendored release builds of the two upstream plugins. It's excluded from PHPCS, PHPUnit, ESLint, Prettier, Jest, and the composer classmap. Refresh it via `tools/sync-bundled.sh` — never by editing files in place. See [Upstream-first policy](#upstream-first-policy) below for where fixes actually belong.
-   **Commit `composer.lock` and `pnpm-lock.yaml`** alongside every dependency bump. The build script validates lock drift and CI installs with `--frozen-lockfile`.

### Commit messages

-   Imperative mood (`Add X`, not `Added X` / `Adds X`).
-   Component prefix when it clarifies scope: `Tests: add smoke test for X`, `Docs: note WPCS convention`.
-   Conventional-commit prefixes (`feat:`, `fix:`, `docs:`, `chore:`) are welcome but not required. The current repo uses a mix; either style passes review.
-   Don't use `@` notation (`@todo`, `@since`, `@someone`) outside code blocks or inline code. GitHub, Slack, and Linear all interpret it as a user mention.
-   Subject line under ~75 chars. Body paragraphs for the "why" when it isn't obvious from the diff.

Some contributors have a local `commit-msg` hook (from a personal dotfile setup) that enforces conventional-commit prefixes. That's a local convenience, not a project requirement — the repo itself doesn't ship a hook and doesn't reject non-prefixed commits.

### Upstream-first policy

Behavior that isn't FOSSE-specific belongs in the upstream plugin, not in FOSSE:

-   Anything useful to sites running `wordpress-activitypub` or `wordpress-atmosphere` standalone → land it in the upstream repo, then pull it in via `tools/sync-bundled.sh`.
-   Behavior that only makes sense under FOSSE's "publish once, reach everywhere" model → stays in FOSSE's `src/`.

The full rule, rationale, and a worked example live in [`AGENTS.md`'s "Upstream contribution policy"](./AGENTS.md#upstream-contribution-policy).

**For external contributors:** if you're fixing something in `bundled/activitypub/` or `bundled/atmosphere/`, the fix path is the upstream repo ([`Automattic/wordpress-activitypub`](https://github.com/Automattic/wordpress-activitypub) or [`Automattic/wordpress-atmosphere`](https://github.com/Automattic/wordpress-atmosphere)). A FOSSE patch that edits `bundled/` directly will get bounced — it would be clobbered on the next sync anyway.

### Opening a PR

-   No `.github/PULL_REQUEST_TEMPLATE.md` exists. Use a `## Summary` + `## Test plan` body — match the shape of recent merged PRs.
-   Target `trunk`.
-   CI runs PHPUnit across PHP 8.2/8.3/8.4/8.5 × WP 6.9/trunk, PHPCS, ESLint, Prettier, Playwright, and the build-zip script. Red CI blocks merge.

---

## For Automatticians

Everything above applies. The rest of this section covers the internal workflow that external contributors don't need and can't fully reproduce anyway.

### SDD workflow

FOSSE uses Spec-Driven Development for any non-trivial feature. Each feature/epic gets a folder under `sdd/<feature>/` containing:

-   `requirements.md` — what we're solving and why (output of brainstorming).
-   `spec.md` — the design: APIs, data shapes, edge cases.
-   `plan.md` — ordered task list with `Status` fields and a top-of-file `## Progress` checklist. This is the authoritative record of what's shipped — not git log, not Linear.
-   `implementation-notes.md` — deviations between spec and what we actually built.

The workflow is driven by the Automattic SDD plugin in the internal `automattic-claude-code-plugins` marketplace. External contributors won't have it — that's fine. The resulting `sdd/<feature>/` docs are written as standalone reading material; you can follow along without running the plugin.

Plan status conventions (for new plans from Nov 2025 forward — older ones don't need retrofitting) live in [`AGENTS.md`'s "SDD plan status tracking"](./AGENTS.md#sdd-plan-status-tracking).

### Stack-PR pattern for epics

For multi-task SDD epics we've been using:

1.  **SDD docs PR** — `sdd/<feature>/{requirements,spec,plan}.md` in one PR off `trunk`. Example: [#18](https://github.com/Automattic/fosse/pull/18).
2.  **Implementation PRs** — each task (or closely-related task cluster) stacked on top of the SDD branch. Example: [#21](https://github.com/Automattic/fosse/pull/21) stacked on #18.
3.  **Janitorial / independent work** — goes straight to `trunk`, not stacked, even if it originated inside an epic. If a policy doc or refactor stands on its own, keep it off the stack so it can merge independently. Example: [#23](https://github.com/Automattic/fosse/pull/23) — upstream-first policy doc, trunk-based because it's valuable even if the SDD plan it came from gets reshaped.

The guiding question: _would this change still make sense if the SDD plan it came from got thrown out?_ If yes, trunk-based. If no, stack it.

### Linear integration

-   Issues live in the **Dotcom** team under the **Radical Month: FOSSE** project.
-   Branch names **end** with the Linear issue ID — e.g. `docs/contributing-guide-DOTCOM-16795`. Linear auto-associates the branch on push. Putting the ID at the end is non-negotiable; Linear's matcher anchors there.
-   PR bodies use `Closes DOTCOM-<id>` when the PR fully resolves the issue, or `See DOTCOM-<id>` when more work remains. Don't use `Fixes DOTCOM-<id> (partial)` — Linear auto-closes on `Closes`/`Fixes`/`Resolves` regardless of trailing text.
-   PR state for human review: **Needs Review**.

### P2

[`fossep2.wordpress.com`](https://fossep2.wordpress.com) is where we draft discussion posts, decision records, and epic-level status updates. GitHub PR descriptions cover per-change decisions; the P2 covers cross-PR narrative.

### Local upstream checkouts

`tools/sync-bundled.sh` re-vendors `bundled/` from local upstream checkouts. Defaults:

-   `FOSSE_AP_SOURCE` → `~/code/wordpress-activitypub`
-   `FOSSE_ATMO_SOURCE` → `~/code/wordpress-atmosphere`

Override via env vars if your checkouts live elsewhere. The script runs `composer install --no-dev --optimize-autoloader` inside the Atmosphere source before rsync, so the vendored copy is self-contained.

### Claude Code setup

-   Public marketplace + `superpowers` plugin are declared in `.claude/settings.json` so external contributors using Claude Code pick them up automatically.
-   Internal contributors should additionally install the `automattic-claude-code-plugins` marketplace (internal-only; not declared in `settings.json` because FOSSE is a public repo). That's where the SDD plugin and a few other internal-only skills live.
-   Some skill references inside `sdd/<feature>/plan.md` assume both marketplaces are present. External contributors can ignore those skill hints — the plan text itself is self-contained.

### Optional: Linear MCP

The committed `.claude/settings.json` auto-allows Linear **read-only** tool calls (`list_*`, `get_*`, `search_*`) via glob patterns so Claude can check ticket status, read issues, and follow cross-references without prompting on every call. Write operations (`save_*`, `create_*`, `delete_*`) still prompt. The globs also auto-cover new read-only tools added upstream.

Installing the Linear MCP is optional. The FOSSE Linear project lives in Automattic's workspace, so only Automatticians can actually read the issues — but the allowlist is harmless if the tools aren't installed (the rules simply don't match anything). If you are an Automattician, installing the Linear MCP makes Claude noticeably more useful for planning work in this repo; see Linear's [MCP docs](https://linear.app/docs/mcp) for setup.

---

## Questions

Open an issue. For internal folks, Slack `#fosse` works too.

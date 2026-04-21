# FOSSE — Copilot Instructions

## Vendored code under `bundled/`

`bundled/activitypub/` and `bundled/atmosphere/` are vendored release
builds of the upstream `wordpress-activitypub` and `wordpress-atmosphere`
plugins. They are refreshed verbatim by `tools/sync-bundled.sh` from
local upstream checkouts — **never edit these files by hand.**

When reviewing or suggesting changes in this repo:

-   Do not propose fixes to files under `bundled/`. Any real issue
    belongs in the upstream repo (Automattic/wordpress-activitypub or
    Automattic/wordpress-atmosphere) and will flow back here on the
    next sync.
-   Typos, code-style nits, and portability concerns in `bundled/` are
    out of scope for FOSSE PRs.
-   FOSSE's own code lives at the repo root (`fosse.php`) and under
    `src/`. Focus review there.

## Plugin context

FOSSE is a WordPress plugin; conventions follow Jetpack's PHPCS ruleset
(tabs, Yoda conditions, WordPress-Extra). See `AGENTS.md` for the
longer version.

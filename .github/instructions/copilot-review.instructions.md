---
applyTo: 'bundled/**'
---

# Copilot code review: skip bundled upstream plugins

Files under `bundled/activitypub/` and `bundled/atmosphere/` are vendored
verbatim from the upstream `Automattic/wordpress-activitypub` and
`Automattic/wordpress-atmosphere` repositories. They are refreshed by
`tools/sync-bundled.sh` — **never edited by hand in this repo.**

When reviewing a FOSSE PR:

-   Do not flag issues in files under `bundled/`. Any real problem belongs
    in the upstream repo and will flow back here on the next sync.
-   Typos, style nits, portability concerns, and refactor suggestions for
    `bundled/` are out of scope for FOSSE PRs.
-   FOSSE's own code lives at the repo root (`fosse.php`) and under
    `src/`. Focus review there.

`.gitattributes` also marks this tree as `linguist-generated` to reinforce
the skip. See `AGENTS.md` Common Pitfalls for the longer version.

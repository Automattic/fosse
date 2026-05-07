# SDD roadmap

Index of every Spec-Driven Development doc in this repo. The first stop for "what's been designed, what's shipped, and what's in flight."

Each row points at a directory under `sdd/`. The directory holds the long-form work (`spec.md` / `requirements.md` / `plan.md` / `implementation.md` / `implementation-notes.md` as appropriate); this table is just the index. Status reflects implementation, not whether the SDD doc itself is merged — for that, look at the PR column.

Status legend:
- **shipped** — implementation's primary deliverables are live on trunk
- **in-progress** — SDD merged; implementation is still landing in pieces
- **planning** — SDD doc itself isn't merged yet (see PR)

## On trunk

| SDD | Purpose | Status | Linear | PR(s) |
|-----|---------|--------|--------|-------|
| [bluesky-handle-setup](./bluesky-handle-setup/) | Claim your site domain as your Bluesky handle from inside WP admin (well-known route + DNS fallback + resolver verification). | in-progress | [DOTCOM-16801](https://linear.app/a8c/issue/DOTCOM-16801) | [#35](https://github.com/Automattic/fosse/pull/35) |
| [bluesky-native-publishing](./bluesky-native-publishing/) | Short-form WP posts publish to Bluesky as native posts (no link card); long-form keeps the teaser. Cross-network projector via `fosse_object_type`. | shipped | [DOTCOM-16795](https://linear.app/a8c/issue/DOTCOM-16795) | [#18](https://github.com/Automattic/fosse/pull/18), [#21](https://github.com/Automattic/fosse/pull/21) |
| [bundled-backends](./bundled-backends/) | Vendor `wordpress-activitypub` and `wordpress-atmosphere` into FOSSE and auto-load at bootstrap. The "rough starting point" before FOSSE owns its own UI. | shipped | [DOTCOM-16799](https://linear.app/a8c/issue/DOTCOM-16799) | [#44](https://github.com/Automattic/fosse/pull/44) and earlier |
| [destination-first-onboarding-wizard](./destination-first-onboarding-wizard/) | Revise the first-run wizard so Bluesky is a first-class destination, starting from "Where should your posts appear?". | shipped | (iterates [onboarding-setup-ux](./onboarding-setup-ux/)) | [#88](https://github.com/Automattic/fosse/pull/88) |
| [fosse-metrics-strategy](./fosse-metrics-strategy/) | Strategy + implementation for FOSSE usage metrics: cohorts A/B/C, layered tiers, recorder + Tracks/MC channel architecture, full event taxonomy. | planning | [DOTCOM-16879](https://linear.app/a8c/issue/DOTCOM-16879) (sub-issues DOTCOM-17027 → 17035) | [#41](https://github.com/Automattic/fosse/pull/41) |
| [long-form-bluesky-strategy](./long-form-bluesky-strategy/) | Replace the long-form link-card with a teaser-thread strategy that reads better on Bluesky while keeping WP canonical. | in-progress | [DOTCOM-16810](https://linear.app/a8c/issue/DOTCOM-16810) ([DOTCOM-16795](https://linear.app/a8c/issue/DOTCOM-16795) epic) | several |
| [onboarding-setup-ux](./onboarding-setup-ux/) | Unified FOSSE admin: Setup + Status pages, bundled-plugin admin entries hidden, extensible Connection_Provider abstraction. | in-progress | [DOTCOM-16793](https://linear.app/a8c/issue/DOTCOM-16793) (sub-epics 16800/16801/16802/16803) | several |
| [post-type-sync](./post-type-sync/) | Project AP's `activitypub_support_post_types` into Atmosphere via FOSSE's `Post_Types` projector. AP option stays canonical. | shipped | [DOTCOM-16875](https://linear.app/a8c/issue/DOTCOM-16875) | [#31](https://github.com/Automattic/fosse/pull/31) |
| [settings-page-scoped-actions](./settings-page-scoped-actions/) | Settings page splits shared federation settings from provider connect/disconnect actions; one save button scope. | shipped | — | [#83](https://github.com/Automattic/fosse/pull/83) |
| [unified-reactions-display](./unified-reactions-display/) | Surface ActivityPub and Bluesky reactions side by side on a single WP post via the bundled `activitypub/reactions` block. | in-progress | [DOTCOM-16894](https://linear.app/a8c/issue/DOTCOM-16894) | several |

## In flight (SDD docs not yet merged)

| SDD (proposed) | Purpose | Linear | PR |
|----------------|---------|--------|-----|
| `sdd/posting-ui/` | FOSSE-native posting UI (composer epic — defaults to `post_format = status`, enforces 300 graphemes). | [DOTCOM-16794](https://linear.app/a8c/issue/DOTCOM-16794) | [#90](https://github.com/Automattic/fosse/pull/90) |
| `sdd/deactivation-lifecycle/` | What happens to FOSSE's menu state and bundled-plugin handoff on deactivate / delete / standalone-coexistence. | [DOTCOM-16865](https://linear.app/a8c/issue/DOTCOM-16865) | [#92](https://github.com/Automattic/fosse/pull/92) |
| `sdd/bundled-backends-migration/` | Migration successor to `bundled-backends/`; replaces the bootstrap-shim with the next-gen loader. | [DOTCOM-16826](https://linear.app/a8c/issue/DOTCOM-16826) | [#93](https://github.com/Automattic/fosse/pull/93) |

## Maintaining this file

When you open a new SDD, add a row in the appropriate table. When an in-flight SDD merges, move it from "In flight" to "On trunk." When implementation ships, flip its status from `planning` or `in-progress` to `shipped`. The per-SDD `plan.md`'s `## Progress` checklist (per AGENTS.md) is still the source of truth for task-level status; this file just rolls it up.

The Linear "Radical Month: FOSSE" project ([b5435621](https://linear.app/a8c/project/radical-month-fosse-b5435621)) is where most of these SDDs live as parent epics; check there for the full work breakdown.

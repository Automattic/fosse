# SDD roadmap

Index of every Spec-Driven Development doc in this repo. The first stop for "what's been designed, what's shipped, and what's in flight."

Each row points at a directory under `sdd/`. The directory holds the long-form work (`spec.md` / `requirements.md` / `plan.md` / `implementation.md` / `implementation-notes.md` as appropriate); this table is just the index. Status reflects implementation, not whether the SDD doc itself is merged — for that, look at the PR column.

Status legend (also stamped as `status:` frontmatter on each SDD's `spec.md` or `plan.md`):
- **shipped** — implementation's primary deliverables are live on trunk; the SDD is still cross-referenced from active work.
- **in-progress** — SDD merged; implementation is still landing in pieces.
- **planning** — SDD doc itself isn't merged yet (see PR).
- **archived** — shipped, complete, and historical reference. Listed in the [Archived](#archived) section below; no follow-up work expected.

## On trunk

| SDD | Purpose | Status | Linear | PR(s) |
|-----|---------|--------|--------|-------|
| [bluesky-handle-setup](./bluesky-handle-setup/) | Claim your site domain as your Bluesky handle from inside WP admin (well-known route + direct `updateHandle` from the OAuth-authorized client). | in-progress | [DOTCOM-16801](https://linear.app/a8c/issue/DOTCOM-16801) | [#35](https://github.com/Automattic/fosse/pull/35), [#98](https://github.com/Automattic/fosse/pull/98) |
| [bluesky-native-publishing](./bluesky-native-publishing/) | Short-form WP posts publish to Bluesky as native posts (no link card); long-form keeps the teaser. Cross-network projector via `fosse_object_type`. | shipped | [DOTCOM-16795](https://linear.app/a8c/issue/DOTCOM-16795) | [#18](https://github.com/Automattic/fosse/pull/18), [#21](https://github.com/Automattic/fosse/pull/21) |
| [fosse-metrics-strategy](./fosse-metrics-strategy/) | Strategy + implementation for FOSSE usage metrics: cohorts A/B/C, layered tiers, recorder + Tracks/MC channel architecture, full event taxonomy. Phase 1 (Recorder, Schema, channel interfaces, in-memory test channels) is shipped; Phases 2–8 still planning. | in-progress | [DOTCOM-16879](https://linear.app/a8c/issue/DOTCOM-16879) (sub-issues DOTCOM-17027 → 17035) | [#41](https://github.com/Automattic/fosse/pull/41) (SDD), [#105](https://github.com/Automattic/fosse/pull/105) (Phase 1 recorder), 215409-ghe-Automattic/wpcom (Phase 2 wpcom Tracks channel + cohort enrichment) |
| [long-form-bluesky-strategy](./long-form-bluesky-strategy/) | Replace the long-form link-card with a teaser-thread strategy that reads better on Bluesky while keeping WP canonical. Projector behavior was later folded into [canonical-upstream-options](./canonical-upstream-options/). | in-progress | [DOTCOM-16810](https://linear.app/a8c/issue/DOTCOM-16810) ([DOTCOM-16795](https://linear.app/a8c/issue/DOTCOM-16795) epic) | several |
| [onboarding-setup-ux](./onboarding-setup-ux/) | Unified FOSSE admin: Setup + Status pages, bundled-plugin admin entries hidden, extensible Connection_Provider abstraction. | in-progress | [DOTCOM-16793](https://linear.app/a8c/issue/DOTCOM-16793) (sub-epics 16800/16801/16802/16803) | several |

## In flight (SDD docs not yet merged)

| SDD (proposed) | Purpose | Linear | PR |
|----------------|---------|--------|-----|
| `sdd/posting-ui/` | FOSSE-native posting UI (composer epic — defaults to `post_format = status`, enforces 300 graphemes). | [DOTCOM-16794](https://linear.app/a8c/issue/DOTCOM-16794) | [#90](https://github.com/Automattic/fosse/pull/90) |
| `sdd/deactivation-lifecycle/` | What happens to FOSSE's menu state and bundled-plugin handoff on deactivate / delete / standalone-coexistence. | [DOTCOM-16865](https://linear.app/a8c/issue/DOTCOM-16865) | [#92](https://github.com/Automattic/fosse/pull/92) |
| `sdd/bundled-backends-migration/` | Migration successor to `bundled-backends/`; replaces the bootstrap-shim with the next-gen loader. | [DOTCOM-16826](https://linear.app/a8c/issue/DOTCOM-16826) | [#93](https://github.com/Automattic/fosse/pull/93) |
| [harden-provider-registration](./harden-provider-registration/) | Defer `Provider_Loader::boot()` to `plugins_loaded` and make it idempotent so standalone provider plugins can register on `fosse_register_providers` without depending on plugin-load order. | [DOTCOM-17104](https://linear.app/a8c/issue/DOTCOM-17104) | — |

## Archived

Shipped SDDs whose primary deliverables landed and whose surface isn't expected to need further iteration. Kept in `sdd/` for cross-references and institutional memory; `status: archived` is stamped in each SDD's frontmatter.

| SDD | Purpose | Linear | PR(s) |
|-----|---------|--------|-------|
| [admin-ux-polish](./admin-ux-polish/) | Wizard/Status accessibility + UX pass: fieldsets, row-headers, Dashicons aria-hidden, WCAG-AA contrast, memoized `get_status()`, rewritten "no providers" copy. | — | [#110](https://github.com/Automattic/fosse/pull/110) |
| [bundled-backends](./bundled-backends/) | Vendor `wordpress-activitypub` and `wordpress-atmosphere` into FOSSE and auto-load at bootstrap. The "rough starting point" before FOSSE owns its own UI. Successor SDD: [`bundled-backends-migration/`](#in-flight-sdd-docs-not-yet-merged). | [DOTCOM-16799](https://linear.app/a8c/issue/DOTCOM-16799) | [#44](https://github.com/Automattic/fosse/pull/44) and earlier |
| [canonical-upstream-options](./canonical-upstream-options/) | Retire FOSSE's parallel `fosse_object_type` / `fosse_long_form_strategy` options in favor of bridging directly off Atmosphere/AP's canonical options. Adds `Canonical_Options_Migrator` to seed and migrate. | — | [#109](https://github.com/Automattic/fosse/pull/109) |
| [destination-first-onboarding-wizard](./destination-first-onboarding-wizard/) | Revise the first-run wizard so Bluesky is a first-class destination, starting from "Where should your posts appear?". | (iterates [onboarding-setup-ux](./onboarding-setup-ux/)) | [#88](https://github.com/Automattic/fosse/pull/88) |
| [post-type-sync](./post-type-sync/) | Project AP's `activitypub_support_post_types` into Atmosphere via FOSSE's `Post_Types` projector. AP option stays canonical. | [DOTCOM-16875](https://linear.app/a8c/issue/DOTCOM-16875) | [#31](https://github.com/Automattic/fosse/pull/31) |
| [settings-page-scoped-actions](./settings-page-scoped-actions/) | Settings page splits shared federation settings from provider connect/disconnect actions; one save button scope. | — | [#83](https://github.com/Automattic/fosse/pull/83) |
| [unified-reactions-display](./unified-reactions-display/) | Surface ActivityPub and Bluesky reactions side by side on a single WP post via the bundled `activitypub/reactions` block. | [DOTCOM-16894](https://linear.app/a8c/issue/DOTCOM-16894) | [#40](https://github.com/Automattic/fosse/pull/40) |

## Maintaining this file

When you open a new SDD, add a row in the appropriate table and stamp `status: planning` (or `in-progress` once it merges) on its `spec.md` / `plan.md` frontmatter. When an in-flight SDD merges, move it from "In flight" to "On trunk." When implementation ships, flip both the table status and the frontmatter to `shipped`. When a shipped SDD is complete with no follow-up work expected, flip it to `archived` and move it to the [Archived](#archived) section.

The per-SDD `plan.md`'s `## Progress` checklist (per AGENTS.md) is still the source of truth for task-level status; this file just rolls it up.

The Linear "Radical Month: FOSSE" project ([b5435621](https://linear.app/a8c/project/radical-month-fosse-b5435621)) is where most of these SDDs live as parent epics; check there for the full work breakdown.

# reconcile-tracking — Spec

Drafted: 2026-04-29

## Purpose

Reconcile FOSSE project tracking across three surfaces:

- **Linear** — source of truth, read by stakeholders. *Dotcom* team, *Radical Month: FOSSE* project. Issue IDs `DOTCOM-\d+`.
- **GitHub** (`Automattic/fosse`) — where real work happens (PRs, public issues).
- **fossep2** (`fossep2.wordpress.com`) — discussion, decision records, epic-level narrative.

The skill flags drift between surfaces and (in `--fix` mode) walks the user through corrective actions one item at a time.

GitHub issues are treated as a public-facing surface, not a complete project tracker. The skill validates existing GH issues and ensures cross-references when both a GH issue and a Linear issue cover the same work, but does not require 1:1 mapping in either direction.

## Scope

### What the skill considers

- **Default window:** items currently open + activity within the last 30 days, plus all `sdd/<feature>/` folders regardless of date.
- **`--since <YYYY-MM-DD>`:** custom window.
- **`--all`:** entire project lifetime (full audit; expected to surface stale findings).
- **`--only gh|p2|sdd`:** narrow to one surface. Combinable with the other flags.

SDD-tracked work is always read in full, regardless of date window — the multi-PR linkage encoded in `sdd/<feature>/plan.md` is the most valuable thing the skill verifies, and its accuracy doesn't decay with time.

### Modes

- **Default (read-only):** runs all detection rules, prints a chat-only report, makes no writes.
- **`--fix`:** prints the report, then walks each `[drift]` and `[review]` finding interactively via `AskUserQuestion`. Actions that change state always require explicit confirmation.

`--fix` is idempotent across runs: already-corrected items don't reappear on the next run.

## Skill identity & invocation

- **Location:** `.claude/skills/reconcile-tracking/SKILL.md` in this repo.
- **Format:** standard Claude Code skill (frontmatter + instructions). Project-local; not distributed as a plugin yet.
- **Slash invocation:** `/reconcile-tracking`.
- **Required tools:** Bash (for `gh`, `git`), Read, Grep, Glob, Edit, AskUserQuestion (for `--fix`), and the context-a8c MCP (`github`, `linear`, `wpcom`, `mgs` providers).

### Invocation surface

```
/reconcile-tracking                           # default window + always-include SDD
/reconcile-tracking --since 2026-01-01        # custom window
/reconcile-tracking --all                     # project lifetime
/reconcile-tracking --fix                     # interactive corrective walkthrough
/reconcile-tracking --only gh|p2|sdd          # narrow to one surface
```

Flags combinable: `/reconcile-tracking --all --fix`, `/reconcile-tracking --only p2 --fix`.

## Data sources & matching

### Per surface

**GitHub (`Automattic/fosse`)** — via `gh` CLI.

- Open PRs (title, number, body, branch, author).
- Open issues (title, number, body, labels, age).
- PRs merged or closed-unmerged within the window.
- `git log` within the window for SDD checks.
- Falls back to context-a8c `github` provider only for cross-PR comment search or other data `gh` can't easily express.

**Linear** — via context-a8c `linear` provider.

- All issues in the *Dotcom* team's *Radical Month: FOSSE* project (filter `list_issues` to that project, then `get_issue` for full body and comments on items the report needs to dig into).
- Comments scanned for `fossep2.wordpress.com/...` URLs (the reciprocal back-link signal).

**P2 (fossep2)** — via context-a8c `mgs` for discovery + `wpcom` for full reads.

- `mgs` query restricted to fossep2 to find posts within the window.
- `wpcom` to fetch each post's full body and comment thread.
- Comments scanned for `linear.app/a8c/issue/DOTCOM-...` URLs and `DOTCOM-\d+` mentions.

**SDD / codebase** — local file ops only.

- Read every `sdd/<feature>/plan.md`. Parse the `## Progress` checklist and per-task `**Status**:` fields.
- Resolve `✅ Done (<ref>)` refs: `#N` → `gh pr view N`; raw SHA → `git show`.
- Grep `src/` and `tests/` for `TODO`/`FIXME`/`@todo` lines mentioning `DOTCOM-\d+`.

### Matching keys

| Edge | Primary key | Fallback |
|---|---|---|
| GH PR → Linear issue | `Closes \|See DOTCOM-\d+` in PR body | branch name suffix `-DOTCOM-\d+` |
| GH issue → Linear issue | `DOTCOM-\d+` in body | Linear issue body/comments referencing `/issues/<N>` |
| P2 post → Linear issue | `DOTCOM-\d+` in post body or comments | Linear issue body/comments referencing the post URL |
| SDD `plan.md` task → PR | `(<ref>)` literal in Status field | — |
| SDD feature folder → Linear | `DOTCOM-\d+` in `requirements.md` or `spec.md` | epic-title heuristic match |

## Drift checks

Each finding is one of three severities:

- `[drift]` — the data clearly disagrees; a fix is needed.
- `[review]` — looks wrong but requires judgment.
- `[info]` — clean state worth surfacing.

### GitHub PRs ↔ Linear

| ID | Check | Severity |
|---|---|---|
| `PR-NO-REF` | Open PR body has no `Closes/See DOTCOM-\d+` | drift |
| `PR-CLOSED-OPEN` | Merged PR with `Closes DOTCOM-X`, but Linear DOTCOM-X still open | drift |
| `PR-DONE-NO-MERGE` | Linear issue marked Done; linked PR not merged or doesn't exist | drift |
| `PR-BRANCH-MISMATCH` | Active branch doesn't end with `-DOTCOM-\d+` (Linear won't auto-associate) | drift |
| `PR-PARTIAL-FIXES` | PR body uses `Fixes DOTCOM-X (partial)` (auto-closes anyway) | drift |
| `LINEAR-IN-FLIGHT-NO-PR` | Linear status implies in-flight (e.g. *In Progress*, *In Review*) but no PR or branch found | review |

### GitHub issues ↔ Linear

| ID | Check | Severity |
|---|---|---|
| `GHI-STALE-VALID` | Open GH issue >90 days old with no activity — still real? | review |
| `GHI-LINEAR-NOT-XLINKED` | Linear and GH issue clearly cover the same thing but neither references the other | drift |
| `GHI-LINEAR-CLOSED` | GH issue references a Linear that's closed → has the GH issue actually been resolved? | review |

### P2 (fossep2) ↔ Linear

| ID | Check | Severity |
|---|---|---|
| `P2-SETTLED-NO-LINK` | P2 post >14d old with no recent comments and no Linear back-link comment | review |
| `P2-LINEAR-MISSING-BACKLINK` | Linear issue references a fossep2 URL but that P2 post has no reciprocal comment | drift |
| `P2-LINK-BROKEN` | P2 post has a Linear back-link, but the linked issue is wrong project / archived / clearly stale | review |

### SDD / codebase ↔ Linear

| ID | Check | Severity |
|---|---|---|
| `SDD-DONE-BAD-REF` | `plan.md` task marked `✅ Done (<ref>)` but `<ref>` doesn't resolve to a merged PR | drift |
| `SDD-INPROGRESS-COLD` | `plan.md` task marked `In progress` for >14d with no commits on a matching branch | review |
| `SDD-NO-LINEAR` | `sdd/<feature>/` folder with no `DOTCOM-\d+` mentioned anywhere in its docs | review |
| `CODE-TODO-CLOSED` | `TODO`/`FIXME` in `src/` or `tests/` names a `DOTCOM-\d+` whose Linear issue is closed | drift |

### Threshold defaults

| Threshold | Default |
|---|---|
| Settled-P2 inactivity (days) | 14 |
| SDD in-progress cold (days) | 14 |
| Stale GH issue (days) | 90 |
| Default window (days) | 30 |

Hardcoded in v1; if real-world usage shows different values fit better, expose as flags later.

## Report format

Chat-only markdown, structured for fast skim. Counts in the header so the reader can decide whether to keep reading. Each finding fits one line where possible; the trailing imperative phrase is the suggested next step (and matches what `--fix` will offer for that check).

```
# FOSSE Tracking Reconciliation — 2026-04-29
Window: 2026-03-30 → 2026-04-29 (--since default 30d)
Scope: GH PRs + GH issues + P2 + SDD
Findings: 14 (4 drift, 7 review, 3 info)

## GitHub PRs ↔ Linear (3 drift, 1 review)
- [drift] PR-NO-REF: PR 41 "Add foo widget" → body has no DOTCOM ref. Add `See DOTCOM-NNNN` to body.
- [drift] PR-CLOSED-OPEN: PR 34 (merged) closed DOTCOM-16812; issue still "In Review" → mark Done.
- [drift] PR-BRANCH-MISMATCH: branch `feature/foo-widget` (PR 41) doesn't end with `-DOTCOM-\d+` → rename manually.
- [review] LINEAR-IN-FLIGHT-NO-PR: DOTCOM-16847 "Implement bar" is "In Progress" (12d) but no branch/PR found.

## GitHub issues ↔ Linear (1 drift, 2 review)
- [drift] GHI-LINEAR-NOT-XLINKED: GH issue 12 "Plugin breaks on PHP 8.5" ↔ DOTCOM-16910 — neither references the other.
- [review] GHI-STALE-VALID: GH issue 7 (94d, 0 comments) — still real?
- [review] GHI-LINEAR-CLOSED: GH issue 9 references DOTCOM-16500 (Done 30d ago) — was the GH issue resolved?

## P2 ↔ Linear (0 drift, 3 review)
- [review] P2-SETTLED-NO-LINK: "Bluesky long-form: teaser-thread vs link-card" (posted 18d ago, last comment 16d) — no Linear back-link.
- [review] P2-SETTLED-NO-LINK: "Onboarding flow Q1 retro" (22d / 21d) — no Linear back-link.
- [review] P2-LINK-BROKEN: "FOSSE January planning" links to DOTCOM-16001 (archived).

## SDD / codebase ↔ Linear (0 drift, 1 review, 3 info)
- [review] SDD-INPROGRESS-COLD: sdd/post-type-sync/plan.md task 3 "Wire projector" — In progress 19d, no commits on branch.
- [info] sdd/long-form-bluesky-strategy/plan.md: 7/7 tasks Done, all refs resolve to merged PRs.
- [info] sdd/onboarding-setup-ux/plan.md: 4/9 tasks Done, all refs valid.
- [info] sdd/bluesky-native-publishing/plan.md: 12/12 tasks Done, all refs valid.

---
14 findings. Re-run with `--fix` to walk drift and review items interactively.
```

Format rules:

- Counts in the header and per-section headers.
- Drift items always cite the Linear ID, GH number, or P2 title verbatim — no synthesized names.
- `[info]` rows appear *only* for clean SDD plans (highest-value surface to confirm clean). Other surfaces don't get `[info]` rows, to keep the report short.
- Per CLAUDE.md, the report avoids `#N` GitHub-style notation outside cross-link contexts (uses "PR 41", "GH issue 12") so the report itself doesn't auto-linkify when pasted into other surfaces.

## `--fix` walkthrough

`--fix` runs the audit, prints the report, then walks every `[drift]` and `[review]` item in order: drift first, then review; within each severity, GitHub → P2 → SDD. `[info]` items are skipped.

For each item, the skill stops with `AskUserQuestion`. Universal choices on every prompt: `skip`, `stop` (exit cleanly without further items), plus check-specific options.

### Per-check actions

| Check | Default action offered | Tool |
|---|---|---|
| `PR-NO-REF` | "Which Linear issue?" → free text → `gh pr edit <N>` to append `See DOTCOM-X` | gh |
| `PR-CLOSED-OPEN` | Set Linear DOTCOM-X to *Done* | linear MCP write |
| `PR-DONE-NO-MERGE` | No auto action — flag with context | — |
| `PR-BRANCH-MISMATCH` | No auto action — flag for manual rename | — |
| `PR-PARTIAL-FIXES` | Edit PR body to drop `(partial)` → confirm before write | gh |
| `LINEAR-IN-FLIGHT-NO-PR` | "Still working / drop status / leave" → if drop, update Linear status | linear MCP write |
| `GHI-STALE-VALID` | "Still valid?" → if no, close GH issue with comment | gh |
| `GHI-LINEAR-NOT-XLINKED` | Add reciprocal links: edit GH issue body + add comment to Linear | gh + linear MCP |
| `GHI-LINEAR-CLOSED` | "GH issue actually resolved?" → if yes, close GH | gh |
| `P2-SETTLED-NO-LINK` | "Tracked in Linear? (paste DOTCOM-id, 'create', or 'no action')" → if id, post back-link comment with one-line decision summary; if 'create', skill drafts a Linear issue (write still requires confirmation) | wpcom + linear MCP |
| `P2-LINEAR-MISSING-BACKLINK` | Compose back-link comment incl. decision summary, confirm body, post | wpcom MCP |
| `P2-LINK-BROKEN` | No auto action — flag for human review | — |
| `SDD-DONE-BAD-REF` | Prompt for correct ref → `Edit` `plan.md` | local |
| `SDD-INPROGRESS-COLD` | "Still in progress / abandoned / actually done" → update `plan.md` status | local |
| `SDD-NO-LINEAR` | "Link to existing DOTCOM-X / create new / mark intentional" → update SDD docs and (if create) draft Linear issue | local + linear MCP |
| `CODE-TODO-CLOSED` | "Remove TODO / update reference / leave" → if remove, `Edit` the file | local |

### Hard rules baked into `--fix`

1. **Never post a comment (GH or P2) without showing the exact body and getting explicit confirmation first.** Honors the project-wide rule from CLAUDE.md: never post comments without explicit approval.
2. **Linear status writes still go through the MCP confirmation gate.** The committed `.claude/settings.json` allows Linear read-only calls but prompts for `save_*`. The skill shows the proposed change before invoking the write, so the prompt is informative.
3. **Branch renames are out of scope.** `PR-BRANCH-MISMATCH` is detected and reported but `--fix` offers no action.
4. **No history-rewriting operations.** No `git push --force`, `--force-with-lease`, `git rebase`, `git reset --hard`, or `--amend`. Per CLAUDE.md, those are never automatic.
5. **`stop` exits cleanly.** No partial-state warning; rerun resumes by re-detecting current drift.
6. **`--fix` is idempotent.** Already-fixed items don't reappear on rerun.

### Decision-summary template (P2 back-link comments)

For `P2-SETTLED-NO-LINK` and `P2-LINEAR-MISSING-BACKLINK`, the skill asks: *"What was the outcome? (one line, becomes part of the back-link comment)"* and composes:

```
Tracking in [DOTCOM-NNNN](https://linear.app/a8c/issue/DOTCOM-NNNN). Decision: <user-supplied one-liner>.
```

The user confirms the exact body before the comment posts.

## Out of scope (v1)

- **Branch rename automation.** Detected, never fixed.
- **Posting unsolicited P2 announcements.** The skill never publishes a "here's what we reconciled" post; if narrative output is wanted later, that's an additive change.
- **Saved report files.** Output is chat-only. Per-run history can be added later if useful.
- **Cross-team Linear visibility.** Scope is limited to the *Radical Month: FOSSE* project under the *Dotcom* team.
- **`sdd/roadmaps/` index reconciliation.** FOSSE doesn't use roadmap files yet; if it adopts them, a follow-up check (e.g. "feature folder with no roadmap entry") becomes worth adding.
- **Severity tuning flags.** Day thresholds (14, 14, 90, 30) are hardcoded; expose as flags only if real usage shows them wrong.

## Future work

- `--only` narrowing per drift category (not just per surface): e.g. `--only PR-NO-REF`.
- Comparing two report runs to surface "new since last run" diffs.
- Promotion path: when this skill stabilizes, extract from `.claude/skills/reconcile-tracking/` into a standalone marketplace plugin so other Automattic projects can adopt it (and rename `Radical Month: FOSSE` → configurable Linear-project pointer).
- Reading `sdd/roadmaps/` once FOSSE adopts that convention.

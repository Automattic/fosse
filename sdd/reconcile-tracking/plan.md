# Implementation Plan: reconcile-tracking

Based on: `sdd/reconcile-tracking/spec.md`

For agentic execution: this plan can be executed via `/superpowers:subagent-driven-development` (recommended — fresh subagent per task) or `/superpowers:executing-plans` (inline batch). Manual execution is also fine; tasks are sized for one focused session each.

**Goal:** Build a project-local Claude Code skill (`/reconcile-tracking`) that audits drift between Linear, GitHub, fossep2, and FOSSE's SDD docs, with an optional `--fix` mode for interactive corrections.

**Architecture:** Single prompt-only skill at `.claude/skills/reconcile-tracking/SKILL.md`. The skill instructs Claude to call `gh` (GitHub) and the context-a8c MCP (`linear`, `wpcom`, `mgs` providers), apply detection rules, render a chat-only markdown report, and — under `--fix` — walk each finding via `AskUserQuestion`. No helper scripts; no plugin packaging.

**Tech Stack:** Claude Code skill format (frontmatter + markdown body), `gh` CLI, `git` CLI, context-a8c MCP, native Claude Code tools (Read, Grep, Glob, Edit, Bash, AskUserQuestion).

## Progress

- [ ] Task 1: Scaffold SKILL.md with frontmatter and section skeleton
- [ ] Task 2: Implement invocation parsing + scope resolution
- [ ] Task 3: Implement data-fetching instructions for all four surfaces
- [ ] Task 4: Implement drift detection rules
- [ ] Task 5: Implement report rendering (read-only mode complete)
- [ ] Task 6: Implement `--fix` interactive walkthrough
- [ ] Task 7: Verify on real FOSSE state
- [ ] Task 8: Document and finalize

## Tasks

### Task 1: Scaffold SKILL.md with frontmatter and section skeleton

- **Status**: Not started
- **Files**: `.claude/skills/reconcile-tracking/SKILL.md` (create)
- **Do**:
  1. Create the skill directory: `mkdir -p .claude/skills/reconcile-tracking`.
  2. Create `SKILL.md` with this frontmatter:
     ```yaml
     ---
     name: reconcile-tracking
     description: Reconcile FOSSE project tracking across Linear (source of truth), GitHub (Automattic/fosse), and the fossep2 P2. Reports drift between surfaces — open PRs missing Linear refs, settled P2 posts without back-link comments, SDD plan tasks marked Done with no merged PR, and similar. --fix walks each item interactively. Read-only by default.
     ---
     ```
  3. Add the body skeleton with these top-level section headers (content stubbed; later tasks fill them in):
     - `# Reconcile Tracking`
     - `## Purpose` — one-paragraph summary echoing the spec.
     - `## Invocation` — how to parse args and resolve scope (filled in Task 2).
     - `## Data fetching` — per-surface fetch procedures (Task 3).
     - `## Drift detection` — per-check rules (Task 4).
     - `## Report rendering` — chat-only output format (Task 5).
     - `## --fix walkthrough` — interactive action loop (Task 6).
     - `## Hard rules` — comment confirmation, no history rewrites, etc. (filled in Task 6).
  4. Under each empty section, write a single line: `_To be implemented in Task N._` so the file is valid markdown and the next-step intent is obvious.
- **Verify**:
  - `ls .claude/skills/reconcile-tracking/SKILL.md` succeeds.
  - `head -5 .claude/skills/reconcile-tracking/SKILL.md` shows the frontmatter delimited by `---`.
  - Inside a fresh Claude Code conversation in this repo, `/reconcile-tracking` is offered as an available slash command (the skill is discovered).
- **Depends on**: none

### Task 2: Implement invocation parsing + scope resolution

- **Status**: Not started
- **Files**: `.claude/skills/reconcile-tracking/SKILL.md` (modify `## Invocation` section)
- **Do**:
  1. Replace the `## Invocation` stub with instructions for Claude to parse these flags from the user's invocation arguments:
     - `--since <YYYY-MM-DD>` — sets the lower bound of the activity window.
     - `--all` — sets the window to project lifetime (no lower bound).
     - `--only <gh|p2|sdd>` — narrows the scope to one surface.
     - `--fix` — enables the interactive walkthrough after the report.
  2. Default behavior (no flags): window = last 30 days, scope = all surfaces, mode = read-only.
  3. Document the resolution rules:
     - If both `--since` and `--all` are passed → `--all` wins; warn briefly.
     - `--only` is mutually exclusive only with itself; combinable with `--since`/`--all`/`--fix`.
     - SDD scans run regardless of date window (per spec).
  4. Include a worked example block in the skill text so Claude has a pattern to follow:
     ```
     User invocation: /reconcile-tracking --since 2026-01-01 --only p2 --fix
     Resolved:
       - Window: 2026-01-01 → today
       - Scope: P2 only
       - Mode: --fix (interactive after report)
     ```
  5. Add a "first action" instruction: before any data fetching, Claude must echo back the resolved scope so the user can confirm or `Ctrl-C` if wrong.
- **Verify**:
  - `grep -A 30 '## Invocation' .claude/skills/reconcile-tracking/SKILL.md` shows the parsing rules.
  - In a manual run (`/reconcile-tracking --since 2026-04-01 --only sdd`), the skill's first message contains the resolved scope summary.
- **Depends on**: Task 1

### Task 3: Implement data-fetching instructions for all four surfaces

- **Status**: Not started
- **Files**: `.claude/skills/reconcile-tracking/SKILL.md` (modify `## Data fetching` section)
- **Do**:
  1. Add a `### GitHub` subsection. Document the exact `gh` commands the skill should run (the skill is a prompt — these are *instructions to Claude*, not shell scripts):
     - Open PRs: `gh pr list --state open --json number,title,body,headRefName,author,createdAt --limit 200`
     - Open issues: `gh issue list --state open --json number,title,body,labels,createdAt --limit 200`
     - Merged PRs in window: `gh pr list --state merged --search "merged:>=<since>" --json number,title,body,headRefName,mergedAt --limit 500`
     - Closed-unmerged PRs in window: `gh pr list --state closed --search "closed:>=<since> -is:merged" --json number,title,body,headRefName --limit 200`
     - Recent commits: `git log --since=<since> --pretty=format:'%H %s' --no-merges`
     - For each result, extract `DOTCOM-\d+` from `body` and from the trailing segment of `headRefName`.
  2. Add a `### Linear` subsection. Document the context-a8c calls:
     - `mcp__plugin_context-a8c_context-a8c__context-a8c-load-provider` with `provider: "linear"`.
     - List FOSSE issues: `execute-tool` with `provider: "linear"`, `tool: "list_issues"`, params filtering to *Dotcom* team's *Radical Month: FOSSE* project.
     - Fetch full bodies/comments for any issue that's a candidate for cross-reference (e.g. status = Done with a closed-unmerged-PR match): `tool: "get_issue"`.
     - Scan comments for `fossep2.wordpress.com/...` URLs and `github.com/Automattic/fosse/(issues|pull)/\d+` URLs.
  3. Add a `### P2 (fossep2)` subsection:
     - First call: `load-provider` with `provider: "mgs"` and run a search restricted to `site:fossep2.wordpress.com` for the date window.
     - For each result, fetch the full post and comments via `wpcom` provider tools (verify exact tool names at first invocation; document the verified names back into SKILL.md).
     - Scan post bodies + comments for `linear.app/a8c/issue/DOTCOM-\d+` and bare `DOTCOM-\d+` mentions.
  4. Add a `### SDD / codebase` subsection:
     - List SDD folders: `Glob` for `sdd/*/plan.md`.
     - For each `plan.md`: `Read` the file; parse the `## Progress` checklist and per-task `**Status**:` field.
     - For each `✅ Done (<ref>)` value: if `<ref>` looks like `#N`, run `gh pr view N --json state,mergedAt`; if it's a 7+ char hex SHA, run `git show --no-patch --format=%s <sha>` to confirm it exists.
     - `Grep` for `TODO|FIXME|@todo` lines mentioning `DOTCOM-\d+` across `src/` and `tests/`.
  5. Document a "fail soft" rule: if any provider tool errors out (MCP not loaded, network failure, etc.), the skill prints an `[error]` row in the relevant report section and continues with the other surfaces — never aborts the whole run.
- **Verify**:
  - `grep '### GitHub\|### Linear\|### P2\|### SDD' .claude/skills/reconcile-tracking/SKILL.md` returns 4 lines.
  - Each subsection shows at least one concrete tool call or command.
  - The `gh` commands as written can be copy-pasted into a terminal and execute (no syntax errors).
- **Depends on**: Task 2

### Task 4: Implement drift detection rules

- **Status**: Not started
- **Files**: `.claude/skills/reconcile-tracking/SKILL.md` (modify `## Drift detection` section)
- **Do**:
  1. For each of the 16 checks in the spec, write a labeled subsection like:
     ```
     ### PR-NO-REF
     **Severity**: drift
     **Trigger**: For each open PR (from data fetched in Task 3), if neither the PR body nor the head branch name contains `DOTCOM-\d+`, emit a finding.
     **Finding payload**: { check: "PR-NO-REF", pr_number, pr_title, suggested_action: "Add `See DOTCOM-NNNN` to PR body." }
     ```
  2. Repeat for: `PR-CLOSED-OPEN`, `PR-DONE-NO-MERGE`, `PR-BRANCH-MISMATCH`, `PR-PARTIAL-FIXES`, `LINEAR-IN-FLIGHT-NO-PR`, `GHI-STALE-VALID`, `GHI-LINEAR-NOT-XLINKED`, `GHI-LINEAR-CLOSED`, `P2-SETTLED-NO-LINK`, `P2-LINEAR-MISSING-BACKLINK`, `P2-LINK-BROKEN`, `SDD-DONE-BAD-REF`, `SDD-INPROGRESS-COLD`, `SDD-NO-LINEAR`, `CODE-TODO-CLOSED`. Use the spec's catalog (sections "GitHub PRs ↔ Linear" through "SDD / codebase ↔ Linear") as the canonical text — copy/paste the trigger language verbatim, then add the `**Finding payload**` line.
  3. At the top of the section, document the threshold defaults:
     - Settled-P2 inactivity: 14 days.
     - SDD in-progress cold: 14 days.
     - Stale GH issue: 90 days.
     - Default window: 30 days.
  4. Document the matching keys table (copy from spec's "Matching keys" — primary key + fallback per edge).
  5. For `GHI-LINEAR-NOT-XLINKED`, document the heuristic explicitly: "Same thing" means a non-trivial substring of the GH issue title appears in a Linear issue title (case-insensitive, ignoring filler words), OR an explicit comment mentions the other ID. The skill flags candidates and shows side-by-side titles for human judgment.
  6. Add a "noise reduction" rule: if a check would fire on a PR/issue/post that's *also* covered by a higher-severity check on the same item, skip the lower one. Example: if `PR-CLOSED-OPEN` fires on PR 34, don't also fire `PR-NO-REF` even though the body might lack a ref. Avoids duplicate findings on the same item.
- **Verify**:
  - `grep -c '^### [A-Z][A-Z]*-' .claude/skills/reconcile-tracking/SKILL.md` returns at least 16 (one heading per check).
  - Each check subsection has all four labels: Severity, Trigger, Finding payload, (and Severity matches the spec catalog).
  - The threshold defaults block is present and matches the spec's values.
- **Depends on**: Task 3

### Task 5: Implement report rendering (read-only mode complete)

- **Status**: Not started
- **Files**: `.claude/skills/reconcile-tracking/SKILL.md` (modify `## Report rendering` section)
- **Do**:
  1. Document the report header format (counts at top):
     ```
     # FOSSE Tracking Reconciliation — <YYYY-MM-DD>
     Window: <since> → <today> (<flag-summary>)
     Scope: <surface list>
     Findings: <total> (<drift count> drift, <review count> review, <info count> info)
     ```
  2. Document the per-section format. Group findings by surface (GitHub PRs ↔ Linear, GitHub issues ↔ Linear, P2 ↔ Linear, SDD / codebase ↔ Linear). Each section heading includes its own counts:
     ```
     ## GitHub PRs ↔ Linear (<drift count> drift, <review count> review)
     ```
  3. Document the per-finding line template — single line where possible:
     ```
     - [<severity>] <CHECK-ID>: <one-line description with cited identifiers> → <suggested next step>.
     ```
  4. Document the `[info]` rule: only SDD plans that pass all their checks emit an `[info]` row (one per `plan.md`). Other surfaces do not.
  5. Document the formatting rules from the spec:
     - Use "PR 41" / "GH issue 12" / "DOTCOM-16847" / "fossep2 post titled X" — never `#N` notation outside genuine cross-link contexts. (References CLAUDE.md.)
     - Drift items always cite the literal Linear ID, GH number, or P2 title — never invent names.
     - The trailing imperative phrase after `→` matches what `--fix` will offer for that check, so the report doubles as a preview.
  6. Add an example output block (copy the example from `spec.md`'s "Report format" section verbatim, since it captures the format precisely).
  7. Document the trailer line:
     ```
     ---
     <total> findings. Re-run with `--fix` to walk drift and review items interactively.
     ```
     (When the run was already invoked with `--fix`, replace the trailer with `Starting --fix walkthrough below…` to lead into Task 6's behavior.)
- **Verify**:
  - `grep -A 5 '## Report rendering' .claude/skills/reconcile-tracking/SKILL.md` shows the header template.
  - The example output block is present and matches the spec's example.
  - In a manual run with no flags on this repo's current state, the rendered report matches the documented format (counts in header, sections per surface, single-line findings, format rules followed). Capture any deviations as inputs to Task 7.
- **Depends on**: Task 4

### Task 6: Implement `--fix` interactive walkthrough

- **Status**: Not started
- **Files**: `.claude/skills/reconcile-tracking/SKILL.md` (modify `## --fix walkthrough` and `## Hard rules` sections)
- **Do**:
  1. Document the walkthrough order: drift first (in surface order GH PRs → GH issues → P2 → SDD), then review (same surface order). `[info]` items skipped.
  2. Per-check action table — for each check ID, copy the entry from the spec's "Per-check actions" table (Section 5 of the spec). Each entry has columns: check ID, default action offered, tool. Do not paraphrase — copy verbatim so the skill and spec stay in lock-step.
  3. Document the universal `AskUserQuestion` choices that appear on every prompt: `skip`, `stop`, plus check-specific options.
  4. For each check that has a state-changing default action, document the *exact prompt body* the skill will compose and show before invoking the write. Example for `PR-NO-REF`:
     ```
     Proposed change to PR <N>:
     ----
     <existing PR body>

     See DOTCOM-<id>
     ----
     Confirm? (yes / edit / skip / stop)
     ```
  5. Document the back-link comment template (copy from spec's "Decision-summary template"):
     ```
     Tracking in [DOTCOM-NNNN](https://linear.app/a8c/issue/DOTCOM-NNNN). Decision: <user-supplied one-liner>.
     ```
  6. Fill in `## Hard rules` with the six hard rules from the spec verbatim:
     1. Never post a comment (GH or P2) without showing the exact body and getting explicit confirmation.
     2. Linear status writes still go through the MCP confirmation gate.
     3. Branch renames are out of scope (`PR-BRANCH-MISMATCH` detected, never auto-fixed).
     4. No history-rewriting operations.
     5. `stop` exits cleanly; no partial-state warning.
     6. `--fix` is idempotent across runs.
  7. Add an "after walkthrough" rule: when the loop completes (all items resolved or skipped, or user `stop`s), the skill prints a summary line: `Walkthrough complete. <N> items applied, <M> skipped, <K> unaddressed.` No commit, no push, no further action.
- **Verify**:
  - `grep -c '\*\*Default action offered\*\*\|^| \`[A-Z]' .claude/skills/reconcile-tracking/SKILL.md` shows at least 16 entries (one per check).
  - The `## Hard rules` section contains exactly six numbered rules matching the spec.
  - Manual run with `--fix` against this repo's current state walks at least one drift item, shows the proposed action body before any write, and exits cleanly when the user types `stop` mid-walk.
- **Depends on**: Task 5

### Task 7: Verify on real FOSSE state

- **Status**: Not started
- **Files**: `.claude/skills/reconcile-tracking/SKILL.md` (potentially modify based on findings)
- **Do**:
  1. In a fresh Claude Code conversation in this repo (so the skill's behavior isn't biased by the implementation conversation's context), run `/reconcile-tracking` with no arguments. Capture the full report.
  2. Manually validate at least three findings against the underlying data: pick one drift, one review, and one info (if any). For each: open the cited PR/issue/post, confirm the finding is correct.
  3. Run `/reconcile-tracking --only sdd`. Confirm the SDD plan checks behave correctly across all five existing FOSSE SDD folders: `bluesky-native-publishing`, `bundled-backends`, `long-form-bluesky-strategy`, `onboarding-setup-ux`, `post-type-sync`.
  4. Run `/reconcile-tracking --since 2026-04-01 --only gh`. Confirm the window narrows correctly and only PR/issue findings appear.
  5. Run `/reconcile-tracking --fix` and walk through one drift item end to end, confirming: proposed action body shown before write, `AskUserQuestion` choices include skip/stop, `stop` exits cleanly without partial state.
  6. For any deviation between observed behavior and the spec, edit `SKILL.md` to fix the instructions. Document each correction in `sdd/reconcile-tracking/implementation-notes.md` (create the file if it doesn't exist) with a one-line entry per deviation.
- **Verify**:
  - At least one full report has been captured and reviewed for correctness.
  - All four invocation modes (no-args, `--only sdd`, `--since + --only gh`, `--fix`) have been exercised.
  - `sdd/reconcile-tracking/implementation-notes.md` exists; if zero deviations were found, it states "No deviations between spec and implementation."
- **Depends on**: Task 6

### Task 8: Document and finalize

- **Status**: Not started
- **Files**: `sdd/reconcile-tracking/plan.md` (modify Progress checklist), `CONTRIBUTING.md` (modify), `sdd/reconcile-tracking/implementation-notes.md` (modify if needed)
- **Do**:
  1. In `CONTRIBUTING.md`, under the "For Automatticians" → "Linear integration" or "P2" subsection (whichever flows better), add a one-line mention:
     ```
     -   The `/reconcile-tracking` skill (project-local at `.claude/skills/reconcile-tracking/`) audits Linear ↔ GitHub ↔ P2 drift and walks corrective actions in `--fix` mode.
     ```
     Place it under the existing Linear integration bullets. No standalone heading — the skill is a tool, not a workflow stage.
  2. In this `plan.md`, mark all eight tasks `✅ Done (<ref>)` with the merged PR number, and check off each `## Progress` entry. (This step is the meta-step — by definition it's the last commit and references its own PR.)
  3. If `implementation-notes.md` was created in Task 7, do a final review pass — collapse redundant entries, ensure each deviation is actionable for a future maintainer.
- **Verify**:
  - `grep reconcile-tracking CONTRIBUTING.md` returns at least one match.
  - `## Progress` in this plan has all 8 boxes checked.
  - All 8 tasks have `Status: ✅ Done (<ref>)`.
- **Depends on**: Task 7

## Notes for the implementer

- **The skill is a markdown file, not code.** "Implementing" each task means writing prompt instructions that Claude will follow when the slash command runs. Show concrete examples (commands, tool names, prompt templates) inside the skill text — Claude follows examples better than abstractions.
- **The spec is the canonical reference.** When in doubt about exact wording, severity classification, or a check's trigger, copy from `spec.md` rather than paraphrasing. Drift between the spec and the skill creates exactly the kind of bug this skill is designed to detect, which would be embarrassing.
- **Test in a fresh conversation.** Bias from the implementation conversation can mask bugs. Start a new Claude Code session in the repo to validate.
- **The skill graduates out later.** Per the spec's "Future work" section, this is destined for a marketplace plugin once it stabilizes. Avoid hardcoding FOSSE-specific details outside of obviously-FOSSE-bound bits (Linear team/project names, repo slug). When you have a choice between a parameterized phrasing and a hardcoded one, parameterize — it pays off at extraction time.

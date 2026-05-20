# Claude feedback on `2026-05-06-fosse-plugin-audit-plan.md`

Reviewed: 2026-05-06. Reviewer: Claude (Opus 4.7).

Overall: a solid plan. Scope is clearly bounded, the five-agent split is orthogonal enough to avoid double-counting, and the requirement that every defect cite file/line is the right bar. Spot-checks against the source confirmed the four most consequential findings the plan ultimately produced (escaping in `settings_errors('atmosphere')`, wizard nested-array sanitize gap, AP/Bluesky object-type desync, Atmosphere long-form override). The issues below are about gaps in the _plan_, not the report's correctness.

## Issues with the plan itself

### 1. No fallback for the verification step (this actually bit)

Step 4 commits to `composer run-script lint-php`, `composer run-script test-php`, `pnpm run format:check`, `pnpm run lint`, `pnpm test`, and step 5 to `pnpm run test:e2e`. The plan does not say what to do if the toolchain is missing. In execution every one of those failed (`php`, `composer`, `pnpm`, `npm`, `corepack` not on PATH; `vendor/`, `node_modules/` absent). The report flags this honestly but several findings ("PHPUnit fails on warnings", "test would mask state across specs", "smoke test is the only JS coverage") were never empirically re-validated. The plan should have either:

-   Required an environment bootstrap step (install PHP via brew, run `composer install`, `pnpm install`) and said "abort if bootstrap fails," or
-   Explicitly downgraded to "static + source-contract review" up front so the reader knows runtime invariants are inferred.

Recommendation for next round: have the agent run `command -v php composer pnpm` first and ask the user to bootstrap before continuing rather than producing a partially-grounded report.

### 2. No severity rubric

Findings are labeled "P1/P2", "P2", "P2" with no definition of what those mean, and one finding is double-labeled "P1/P2" (the escaping bug). For a security-adjacent finding whose exploitability hinges on whether upstream OAuth error text can carry HTML, the unresolved severity is the interesting question — saying "P1/P2" punts on it. The plan should fix a rubric (e.g., P0 = exploitable now / P1 = exploitable under a plausible upstream change / P2 = defense-in-depth) and require each finding to pick one.

### 3. Bundled-vs-FOSSE ownership boundary is fuzzy

The plan says bundled code is "excluded from direct review" but "may be inspected as reference material." Agents D and E both inspect bundled extensively. This is fine in principle, but the plan never says: when an integration finding implicates both, where does it land? Some report items drift here — e.g., the photoblog backlog blends FOSSE work, Blurt theme work, and upstream Atmosphere work into a single numbered list. The plan should require each finding to declare an owner (FOSSE / Blurt / activitypub / atmosphere / upstream protocol) so triage can fan it out cleanly.

### 4. No supply-chain / dependency questions

`composer.json` and `package.json` are in Agent A's scope, but the questions only ask about build-zip and workflow safety. There's no question like "are any pinned dep versions known-vulnerable?" or "does pnpm-lock.yaml drift from package.json?" The report has nothing on this either — it's not that codex looked and found nothing, it's that the plan didn't ask.

### 5. No question about existing `phpcs:ignore` / `eslint-disable` suppressions

Each suppression is a load-bearing assumption ("caller verifies the nonce", "this redirect is intentional"). Spot-checking each one is exactly the kind of audit-time work that lint can't do. The plan should make Agent A or B enumerate every suppression in scoped files and confirm the suppressed rule's invariant still holds. (`src/Admin/class-bluesky-provider.php` alone has several.)

### 6. No "is this work-in-progress?" gate

Several findings (the long-form override with no FOSSE-side UI, the "no public photoblog mode") read like in-progress product gaps, not defects. Without a check against the SDD docs / open Linear work / the project's own backlog, the audit can't distinguish "missing on purpose because it's queued" from "missing because it was overlooked." The plan explicitly puts GitHub/PR state out of scope, which is fine, but it should at least require a pass over `sdd/` to see if the gap is already tracked. The new `sdd/wpcom-simple-rollout/` and `sdd/reconcile-tracking/` directories sitting untracked in the parent repo are an example: they may already cover work the audit recommends.

### 7. Photoblog/Blurt scope creep

Agent E's scope includes `/Users/kraft/code/wpcom-a8c-themes/blurt`, but the plan doesn't say where Blurt findings get filed. Backlog item 10 ("Add a FOSSE/Blurt photoblog mode or compatibility layer") jumps directly from audit observation to product proposal that spans two repos. The plan should have separated "Blurt issues to file against Blurt", "FOSSE features to file against FOSSE", and "joint design questions" — and probably should have downgraded photoblog from a peer agent to an appendix, since it's the one section that requires product-direction input rather than code review.

### 8. Minor: sanity-check list at step 7 doesn't include "verification was actually run"

The final sanity check is good but doesn't include "did we actually execute the verification commands or are we shipping a paper audit?" That's the bullet that would have caught the verification gap before publish.

## What the plan got right

-   Scoping bundled out of direct findings while letting agents read it for contracts is the right call.
-   The five agents are genuinely orthogonal — minimal overlap in evidence between the security, standards, QA, federation, and photoblog notes.
-   Requiring "explicit not-found notes for high-risk vectors checked but not present" (Agent A output spec) is exactly what makes a security audit re-readable later. The security note delivers on that well.
-   The execution steps document the worktree anchor and commit SHA, which makes the audit reproducible.

## Issues with execution that the plan should prevent next time

-   Verification commands skipped silently, partially undermining several test-quality findings.
-   The escaping finding's exploitability never gets resolved — does upstream Atmosphere/Bluesky error text actually carry attacker-controlled HTML? That's a 30-minute follow-up the plan should have required.
-   The `status-page.spec.ts` cleanup finding is structurally correct (it diverges from the documented helper) but the report doesn't say whether the test currently passes anyway. "Fragile" vs "broken" matters for triage and the audit doesn't disambiguate.

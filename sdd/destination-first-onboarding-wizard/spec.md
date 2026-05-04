# Spec: Destination-First Onboarding Wizard

## Goal

Revise the first-run onboarding wizard so Bluesky is a first-class setup
destination instead of a final optional add-on. The wizard should start from the
user-facing question "Where should your posts appear?" while preserving the
current implementation constraint that ActivityPub/fediverse support remains the
baseline backend for this iteration.

This is an iteration on `sdd/onboarding-setup-ux/`, not a replacement for the
overall onboarding architecture.

## Current Constraints

- FOSSE bundles and loads ActivityPub and Atmosphere automatically.
- The current wizard requires a registered ActivityPub provider before it can
  render.
- The shared post-type setting is ActivityPub's
  `activitypub_support_post_types` option.
- FOSSE projects that ActivityPub option into Atmosphere via
  `atmosphere_syncable_post_types`.
- Because of that projection, clearing or bypassing ActivityPub post types would
  also affect Bluesky publishing today.

True Bluesky-only setup, or making fediverse publishing an opt-in backend, needs
a separate architecture decision where FOSSE owns destination enablement and
projects into ActivityPub and Atmosphere independently.

## Proposed Flow

### Step 1: Destinations

Question: "Where should your WordPress posts appear?"

Use a clean destination-card layout: two large selectable cards in a centered
wizard column. The card UI should stay simple and readable, with a restrained
accent on the selected/recommended card. Avoid decorative hero treatment,
marketing-style imagery, and dense protocol explanation.

Choices:

1. **Fediverse + Bluesky**
   - Recommended/default option.
   - Copy: "Let people follow your site from Mastodon-compatible apps and also
     publish eligible posts to Bluesky."
   - Sets a wizard-local intention to show the Bluesky connect step.

2. **Fediverse only**
   - Copy: "Let people follow your site from Mastodon-compatible apps. You can
     connect Bluesky later from FOSSE Settings."
   - Skips the Bluesky connect step.

ActivityPub remains enabled in all choices for this iteration. Do not offer a
"Bluesky only" option until FOSSE owns independent destination settings.

### Step 2: Identity

Question: "Who should people follow?"

Choices map to the existing `activitypub_actor_mode` values:

- **Me** -> `actor`
- **My site** -> `blog`
- **Both** -> `actor_blog`

This is the current Appearance step, reframed after the destination decision.
Keep the live fediverse preview and inline Site Handle field from the current
wizard. Reorder cards so the current default/recommended option appears first,
or add an explicit "Recommended" cue.

### Step 3: Content

Question: "What should publish?"

Continue saving to `activitypub_support_post_types`, with the existing empty
selection guard. Prefer a clearer visual grouping:

- Primary/common types first, usually Posts and Pages.
- Custom or unusual public post types under a secondary "Other content types"
  grouping when present.

The copy should state that the selection applies to the destinations chosen in
step 1. Since ActivityPub remains required, avoid implying Bluesky can publish
when fediverse publishing is disabled.

### Step 4: Bluesky

Render this step only when step 1 selected **Fediverse + Bluesky**.

States stay provider-backed:

- Unavailable: render skip-only notice.
- Disconnected: show handle form and sign-up/domain-handle help.
- Connected: show confirmation summary and finish action.

Action hierarchy should make Bluesky first-class:

- "Connect Bluesky" is the primary action in the main footer/action area.
- "Skip Bluesky for now" is secondary.
- Avoid a layout where the connect button is inside the card while the footer
  primary action skips the step.

When step 1 did not select Bluesky, the wizard should route directly from
Content to Review.

### Step 5: Review

Replace the current Complete-only framing with a review/confirmation screen that
summarizes:

- Destinations: "Fediverse + Bluesky" or "Fediverse only".
- Identity: selected actor mode plus resolved handle(s).
- Content: selected post types.
- Bluesky: connected account, not connected, or skipped.

Primary CTA remains the publish CTA resolved from selected post types.
Secondary actions remain Status Dashboard and Settings. The "Run wizard again"
link can stay subtle.

## State Model

Persist the destination intent in a wizard-owned option:
`fosse_onboarding_destination`.

Store it with autoload disabled. The value needs to survive normal wizard
navigation, refreshes, back/forward flows, and the final Review summary, but it
must not be treated as a publishing enablement setting.

Allowed values:

- `fediverse_bluesky`
- `fediverse_only`

This is not a publishing enablement setting in this iteration. It is wizard flow
state and completion-summary state only. Publishing behavior continues to be
driven by existing ActivityPub and Atmosphere settings.

Saving should follow the current per-step pattern: validate the submitted value,
write the option, and redirect to the next step. If the option is missing or
invalid on a later step, fall back to `fediverse_bluesky`.

## Non-Goals

- No Bluesky-only setup.
- No fediverse disable switch.
- No replacement of the `activitypub_support_post_types` source-of-truth
  decision.
- No new destination routing engine.
- No React/admin SPA rewrite.

## Test Coverage

Expected tests:

- PHPUnit: destination step saves and validates the destination choice.
- PHPUnit: destination choice controls whether the Bluesky step is next after
  Content.
- PHPUnit: completion summary reflects the selected destination and Bluesky
  status.
- PHPUnit: invalid or missing destination falls back to the recommended
  `fediverse_bluesky` path.
- Playwright: first step renders destination cards and defaults/recommends
  Fediverse + Bluesky.
- Playwright: Fediverse + Bluesky path reaches the Bluesky connect step.
- Playwright: Fediverse-only path skips the Bluesky connect step and lands on
  Review/Complete.
- Playwright: disconnected Bluesky path presents Connect as the primary action
  and Skip as secondary.

## Open Decisions

1. Exact label for the default path: "Fediverse + Bluesky" is clear but
   protocol-heavy. "Mastodon-compatible apps + Bluesky" is more concrete but
   longer.
2. Whether the stored destination intent should be surfaced after completion
   for analytics or future settings hints. It should not affect publishing
   behavior until a separate destination-enable architecture exists.

---
status: shipped
---

# Spec: Server-rendered admin design-system alignment

## Problem

FOSSE's Settings and Status screens are intentionally PHP-rendered WordPress
admin pages. They already have a custom "Gutenberg-like" visual layer, but that
layer has grown into a small parallel design language: large page titles,
custom card radius/shadow choices, provider-section variants, and local color
tokens that are not clearly tied back to WordPress admin or WPDS conventions.

The WPDS adoption guidance (`pbjpUB-zL-p2`) recommends using shared WordPress UI
packages and tokens where they fit. FOSSE should move toward that direction
without paying the cost of a React rewrite or a new build pipeline for this
small polish pass.

## Goals

- Keep the Settings and Status pages server-rendered in PHP.
- Preserve the existing information architecture and provider abstraction.
- Align non-wizard admin styling with restrained WordPress admin conventions.
- Treat FOSSE CSS variables as local aliases over WordPress/admin visual
  primitives, not an independent design system.
- Add regression coverage for the visual constraints that motivated this pass.
- Record the React/UI-package migration path as a future option, not present
  scope.

## Non-goals

- No React conversion.
- No `@wordpress/build`, `@wordpress/ui`, `@wordpress/components`,
  `@wordpress/admin-ui`, or `@wordpress/dataviews` dependency changes.
- No wizard redesign. The wizard keeps its current flow, richer card treatment,
  and easter egg. Shared CSS may still need to remain compatible with it.
- No new admin pages, tabs, or navigation changes.
- No changes to provider connection behavior.

## Decisions

### Keep PHP rendering for this pass

The existing `sdd/onboarding-setup-ux/` decision still holds: the current admin
surface is mostly settings forms, read-only status, and redirect-based OAuth.
React would add build and maintenance cost without improving the immediate
experience.

### Align by tokens and primitives first

The safest improvement is to tighten the existing CSS primitives:

- Keep `--fosse-ui-*` variables, but document them as aliases over WordPress
  admin/WPDS-facing concepts with stable fallbacks.
- Override non-wizard admin radius/shadow choices so Settings and Status feel
  closer to wp-admin cards and panels.
- Normalize field, card, section, and action spacing across Settings and Status.
- Avoid changes that rework the wizard's visual identity.

### Use tests for durable constraints, not pixel snapshots

This work should not rely on screenshot diffs. Playwright should assert stable
layout and high-level visual constraints: no horizontal overflow, compact admin
heading scale, restrained card radius, no decorative gradients, and no box
shadow on the non-wizard admin cards.

## Future React path

If FOSSE later needs a more interactive admin UI, that should be a separate
SDD. Likely follow-up direction:

- Adopt WordPress build tooling before adding React-admin source.
- Use `@wordpress/admin-ui` for page shells and navigation.
- Use stable `@wordpress/ui` / `@wordpress/components` controls where the
  package documentation marks them suitable.
- Use `@wordpress/icons` instead of Dashicons for React-rendered controls.
- Consider `@wordpress/dataviews` only if provider diagnostics become tabular,
  filterable, or list-heavy.

## Acceptance criteria

- Settings and Status remain functional server-rendered admin pages.
- Wizard behavior and visual personality remain intentionally unchanged.
- Non-wizard FOSSE admin cards use compact radius and no custom shadow.
- Settings/Status page titles use wp-admin-scale typography rather than
  hero-scale type.
- Playwright coverage fails on the pre-change visual treatment and passes after
  the CSS alignment.
- Public docs avoid linking to non-public discussion URLs; they refer to the
  source guidance only as `pbjpUB-zL-p2`.

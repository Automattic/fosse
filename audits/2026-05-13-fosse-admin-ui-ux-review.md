# FOSSE Admin UI/UX Review Baseline

Date: 2026-05-13
Local preview URL: http://127.0.0.1:9400

## Screens Reviewed

-   Status page.
-   Onboarding wizard destination step.
-   Onboarding wizard identity step.
-   Onboarding wizard Bluesky step in disconnected and connected states.

## Blocked Screens

-   Settings page.
-   Onboarding wizard content step.

Both blocked screens hit a local preview fatal before Composer autoload was refreshed: `Automattic\Fosse\Admin\Post_Type_Chooser` was missing from the local Composer classmap while `src/Admin/class-post-type-chooser.php` existed in the working tree.

This is treated as local setup, not a product regression, unless it reproduces after a fresh Composer autoload generation.

## Initial Observations

-   Reviewed Status and wizard screens did not show horizontal overflow at 1280px or 390px.
-   Status shows the provider count, but when only one of two providers is connected it lacks a visible action to manage the disconnected provider.
-   The wizard's connected Bluesky state repeats the same confirmation in the title, description, and success notice.
-   The wizard mobile progress indicator uses all step labels at narrow widths, which pushes the first question lower than necessary.

## Agreed PR Breakdown

1. Review record and local preview prep.
2. Status page actionability.
3. Settings page information architecture and copy.
4. Wizard flow, copy, and mobile progress.
5. Shared visual and accessibility token polish.

## Local Preview Prep

Run `composer dump-autoload` or `composer install` before the next visual pass so `Post_Type_Chooser` resolves locally. Do not commit changes under `vendor/`.

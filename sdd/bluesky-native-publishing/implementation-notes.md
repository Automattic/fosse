# Implementation Notes — Bluesky Native Publishing

## Deviations from Spec

### `Object_Type` class ships as `src/class-object-type.php`, not `src/Object_Type.php`
- **Spec said**: The plan's Task 5 Files list reads `src/Object_Type.php`.
- **Implementation does**: File is `src/class-object-type.php` (class name `Object_Type` unchanged).
- **Reason**: The Jetpack PHPCS ruleset enforces `WordPress.Files.FileName.InvalidClassFileName` — class files must be `class-<kebab-case>.php`. `Object_Type.php` fails that sniff. The existing `src/Bundled/Bootstrap.php` looks like it breaks the same rule but doesn't trigger errors because the `.phpcs.xml.dist` exclude-pattern `*/bundled/*` case-insensitively matches `src/Bundled/` — an incidental escape that doesn't apply to files at the `src/` root.
- **Impact**: None at runtime (classmap autoload finds the class regardless of filename). Matches WordPress core's `class-wp-post.php` convention. If we ever add more top-level classes to `src/`, they should follow the same pattern.

### Task 6 mu-plugin mounted via `playwright.config.ts`, not blueprint `cp`
- **Spec said**: Blueprint would register the mu-plugin (`blueprint.json` — "modify only if a mu-plugin or fixture must be added").
- **Implementation does**: `playwright.config.ts` passes a second `--mount tests/e2e/mu-plugins:/wordpress/wp-content/mu-plugins` flag to `wp-playground-cli server`. `blueprint.json` only adds `WP_DEBUG` consts and the `fosse_object_type=note` site option.
- **Reason**: Playwright's local webServer uses `reuseExistingServer: !CI`, and blueprint steps (including `cp`) only execute on a fresh Playground boot. Editing the mu-plugin source during iteration left the old copy in `wp-content/mu-plugins/` until the server was killed. A live mount reflects edits immediately on every request.
- **Impact**: Faster iteration locally (no server restart after mu-plugin edits). Behaves identically in CI where the server always boots fresh. Anyone adding another test-only mu-plugin should drop it alongside `fosse-bsky-capture.php` — no wiring needed.

### Task 6 intercepts at `transition_post_status`, not `Atmosphere\API::apply_writes`
- **Spec said**: Option (b) "request interception — mu-plugin filtering `Atmosphere\API` to capture `applyWrites` payload to disk."
- **Implementation does**: Mu-plugin hooks `transition_post_status` at priority 5 and calls `Atmosphere\Transformer\Post::transform()` directly, dumping the record to `uploads/fosse-bsky-capture.json`. Never touches `API::apply_writes`.
- **Reason**: Atmosphere's publish path goes `transition_post_status` → cron → `Publisher::publish` → `API::apply_writes` → `wp_remote_request`. Intercepting at `apply_writes` requires waiting for cron and faking the full OAuth/DPoP/encryption stack before the code reaches a mockable boundary. Hooking `transition_post_status` captures the transformed record synchronously during the publish request with zero OAuth mocking.
- **Impact**: Test runs in ~7s and doesn't depend on cron scheduling. Payload shape is identical — we're calling the same transformer Atmosphere's publisher would call. What the test *doesn't* prove is that the payload actually reaches `apply_writes` unmolested (could in theory be mutated by Atmosphere code between transform and the HTTP call), but no such mutation exists in current Atmosphere code and adding it would break the transformer's test coverage upstream.

## Notes

- Test file kept as `tests/php/Object_TypeTest.php` because `.phpcs.xml.dist` relaxes `WordPress.Files.FileName` under `tests/php/` (per existing project convention).
- Test's `@before` annotation added alongside the `#[Before]` attribute to satisfy `Jetpack.PHPUnit.Attributes.AttributeFoundMissingAnnotation`.
- Task 6 sets `fosse_object_type=note` in the blueprint to force short-form via the Task 5 projector rather than rely on an untitled post. This makes the e2e test a true end-to-end validation of the full stack (projector → upstream filter → short-form composition).

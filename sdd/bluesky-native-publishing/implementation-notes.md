# Implementation Notes — Bluesky Native Publishing

## Deviations from Spec

### `Object_Type` class ships as `src/class-object-type.php`, not `src/Object_Type.php`
- **Spec said**: The plan's Task 5 Files list reads `src/Object_Type.php`.
- **Implementation does**: File is `src/class-object-type.php` (class name `Object_Type` unchanged).
- **Reason**: The Jetpack PHPCS ruleset enforces `WordPress.Files.FileName.InvalidClassFileName` — class files must be `class-<kebab-case>.php`. `Object_Type.php` fails that sniff. The existing `src/Bundled/Bootstrap.php` looks like it breaks the same rule but doesn't trigger errors because the `.phpcs.xml.dist` exclude-pattern `*/bundled/*` case-insensitively matches `src/Bundled/` — an incidental escape that doesn't apply to files at the `src/` root.
- **Impact**: None at runtime (classmap autoload finds the class regardless of filename). Matches WordPress core's `class-wp-post.php` convention. If we ever add more top-level classes to `src/`, they should follow the same pattern.

## Notes

- Test file kept as `tests/php/Object_TypeTest.php` because `.phpcs.xml.dist` relaxes `WordPress.Files.FileName` under `tests/php/` (per existing project convention).
- Test's `@before` annotation added alongside the `#[Before]` attribute to satisfy `Jetpack.PHPUnit.Attributes.AttributeFoundMissingAnnotation`.

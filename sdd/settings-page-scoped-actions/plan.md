# Settings Page Scoped Actions Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make the FOSSE Settings page clearly distinguish shared federation settings from provider connection actions.

**Architecture:** Keep one unified settings form for General, ActivityPub, and connected Bluesky publishing settings, then render provider connection state/actions in a separate Connections group after that form. The save button remains scoped to the unified settings form; Bluesky connect/disconnect remains scoped to its own admin-post form. ActivityPub gets a read-only connection row so users can see why it has no connect button.

**Tech Stack:** PHP 8.2+, WordPress admin templates, WorDBless PHPUnit tests, Playwright e2e tests, Jetpack PHPCS, WordPress admin CSS.

---

## Progress

- [x] Task 1: Add failing PHPUnit coverage for settings/actions grouping
- [x] Task 2: Restructure settings template and ActivityPub connection markup
- [x] Task 3: Update Bluesky connection markup and provider tests
- [x] Task 4: Add CSS for grouped settings/actions layout
- [x] Task 5: Update Playwright coverage for visual grouping
- [x] Task 6: Run verification and record implementation notes

## Tasks

### Task 1: Add failing PHPUnit coverage for settings/actions grouping

- **Status**: ✅ Done (this commit)
- **Files**:
  - Modify: `tests/php/Admin/Setup_PageTest.php`
  - Modify: `tests/php/Admin/AP_ProviderTest.php`
- **Do**:
  1. Add a PHPUnit test to `tests/php/Admin/Setup_PageTest.php` after `test_render_emits_unified_form_with_save_action_and_nonce()`.

```php
	/**
	 * The page groups shared settings separately from provider connection
	 * actions so the Save button does not look like an ActivityPub-only action.
	 */
	public function test_render_groups_settings_form_before_connections(): void {
		$this->become_admin();

		$output = $this->capture_render();

		$this->assertStringContainsString( 'id="fosse-federation-settings"', $output );
		$this->assertStringContainsString( 'id="fosse-settings-actions"', $output );
		$this->assertStringContainsString( 'id="fosse-connections"', $output );
		$this->assertStringContainsString( 'id="fosse-provider-activitypub-connection"', $output );

		$form_position        = strpos( $output, 'id="fosse-settings"' );
		$save_position        = strpos( $output, 'id="fosse-settings-actions"' );
		$form_end_position    = strpos( $output, '</form>', (int) $save_position );
		$connections_position = strpos( $output, 'id="fosse-connections"' );

		$this->assertIsInt( $form_position );
		$this->assertIsInt( $save_position );
		$this->assertIsInt( $form_end_position );
		$this->assertIsInt( $connections_position );
		$this->assertGreaterThan( $form_position, $save_position );
		$this->assertGreaterThan( $save_position, $form_end_position );
		$this->assertGreaterThan( $form_end_position, $connections_position );
	}
```

  2. Add a PHPUnit test to `tests/php/Admin/AP_ProviderTest.php` near the existing render tests.

```php
	/**
	 * ActivityPub has no OAuth flow, but the Settings page still shows why it
	 * appears connected in the separate Connections group.
	 */
	public function test_render_connection_actions_explains_automatic_connection(): void {
		ob_start();
		$this->provider->render_connection_actions();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'id="fosse-provider-activitypub-connection"', $output );
		$this->assertStringContainsString( 'ActivityPub', $output );
		$this->assertStringContainsString( 'Connected automatically', $output );
		$this->assertStringNotContainsString( '<form', $output );
	}
```

  3. Run the targeted tests and verify they fail before implementation.

```bash
composer run-script test-php -- --filter 'Setup_PageTest|AP_ProviderTest'
```

Expected: failures for missing `fosse-federation-settings`, `fosse-settings-actions`, `fosse-connections`, `fosse-provider-activitypub-connection`, and ActivityPub connection copy.

- **Verify**: Tests fail for the new expected markup only.
- **Depends on**: none

### Task 2: Restructure settings template and ActivityPub connection markup

- **Status**: ✅ Done (this commit)
- **Files**:
  - Modify: `src/Admin/templates/setup-page.php`
  - Modify: `src/Admin/class-ap-provider.php`
- **Do**:
  1. In `src/Admin/templates/setup-page.php`, wrap the unified settings form in a visible federation settings panel. Preserve the existing `form id="fosse-settings"` and save action fields.

```php
		<div class="fosse-settings-panel" id="fosse-federation-settings">
			<form id="fosse-settings" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="<?php echo esc_attr( \Automattic\Fosse\Admin\Setup_Page::SAVE_ACTION ); ?>" />
				<input type="hidden" name="_wpnonce" value="<?php echo esc_attr( $save_nonce ); ?>" />

				<h2><?php esc_html_e( 'Federation settings', 'fosse' ); ?></h2>
```

  2. Change the General section wrapper in `setup-page.php` from a provider card to an in-form settings subsection.

```php
				<div class="fosse-settings-section" id="fosse-section-general">
					<h3><?php esc_html_e( 'General', 'fosse' ); ?></h3>
```

  3. Move the save button into an explicit settings-form footer immediately before the closing `</form>`.

```php
				<div class="fosse-settings-actions" id="fosse-settings-actions">
					<?php submit_button( __( 'Save settings', 'fosse' ), 'primary', 'submit', false ); ?>
				</div>
			</form>
		</div>
```

  4. After the federation settings panel, render a separate Connections panel and call each provider's connection renderer inside it.

```php
		<div class="fosse-settings-panel" id="fosse-connections">
			<h2><?php esc_html_e( 'Connections', 'fosse' ); ?></h2>

			<?php
			foreach ( $providers as $provider ) {
				if ( $provider->is_available() ) {
					$provider->render_connection_actions();
				}
			}
			?>
		</div>
```

  5. In `src/Admin/class-ap-provider.php`, change the setup section wrapper from `fosse-provider-section` to `fosse-settings-section` and demote its heading to `h3`.

```php
		<div class="fosse-settings-section" id="fosse-provider-activitypub">
			<h3><?php esc_html_e( 'ActivityPub', 'fosse' ); ?></h3>
```

  6. Replace the no-op `render_connection_actions()` body in `src/Admin/class-ap-provider.php` with read-only connection markup.

```php
	public function render_connection_actions(): void {
		?>
		<div class="fosse-connection-section" id="fosse-provider-activitypub-connection">
			<h3><?php esc_html_e( 'ActivityPub', 'fosse' ); ?></h3>
			<p>
				<strong><?php esc_html_e( 'Connected automatically', 'fosse' ); ?></strong>
			</p>
			<p class="description">
				<?php esc_html_e( 'ActivityPub is available because FOSSE loaded the ActivityPub backend for this site.', 'fosse' ); ?>
			</p>
		</div>
		<?php
	}
```

  7. Update the method docblock above `render_connection_actions()` in `class-ap-provider.php` to describe the read-only connection row.

```php
	/**
	 * Render ActivityPub's read-only connection state.
	 *
	 * ActivityPub has no OAuth connect/disconnect action in FOSSE. Showing a
	 * read-only row in the Connections group keeps the page structure parallel
	 * with Bluesky and explains why there is no ActivityPub button.
	 *
	 * @return void
	 */
```

  8. Run targeted PHPUnit.

```bash
composer run-script test-php -- --filter 'Setup_PageTest|AP_ProviderTest'
```

Expected: the tests added in Task 1 pass.

- **Verify**: Settings grouping tests and AP provider render tests pass.
- **Depends on**: Task 1

### Task 3: Update Bluesky connection markup and provider tests

- **Status**: ✅ Done (this commit)
- **Files**:
  - Modify: `src/Admin/class-bluesky-provider.php`
  - Modify: `tests/php/Admin/Bluesky_ProviderTest.php`
- **Do**:
  1. In `src/Admin/class-bluesky-provider.php`, change the connected-state setup wrapper to the in-form settings section class and demote its heading.

```php
		<div class="fosse-settings-section" id="fosse-provider-bluesky-settings">
			<h3><?php esc_html_e( 'Bluesky publishing', 'fosse' ); ?></h3>
```

  2. Change the connection panel wrapper from a provider card to a connection section while preserving the `id="fosse-provider-bluesky"` anchor used by status-page reconnect links.

```php
		<div class="fosse-connection-section" id="fosse-provider-bluesky">
			<h3><?php esc_html_e( 'Bluesky', 'fosse' ); ?></h3>
```

  3. Keep the connect and disconnect forms inside the Bluesky connection section. Do not move either form into `#fosse-settings`.
  4. In `tests/php/Admin/Bluesky_ProviderTest.php`, keep `test_render_connection_actions_has_anchor_id()` and add assertions that the section uses the connection class and the user-facing heading is Bluesky.

```php
		$this->assertStringContainsString( 'class="fosse-connection-section"', $output );
		$this->assertStringContainsString( '<h3>Bluesky</h3>', $output );
```

  5. If any existing test asserts the old `Bluesky connection` heading, update it to assert `Bluesky` for the connection section and `Bluesky publishing` for the connected in-form settings section.
  6. Run targeted PHPUnit.

```bash
composer run-script test-php -- --filter Bluesky_ProviderTest
```

Expected: Bluesky provider tests pass, and `id="fosse-provider-bluesky"` remains present.

- **Verify**: Bluesky provider tests pass; the reconnect fragment target remains stable.
- **Depends on**: Task 2

### Task 4: Add CSS for grouped settings/actions layout

- **Status**: ✅ Done (this commit)
- **Files**:
  - Modify: `src/Admin/assets/css/admin.css`
- **Do**:
  1. Replace the provider setup section comment and CSS with scoped panel, settings-section, connection-section, and footer styles. Keep `.fosse-provider-section` available for existing status-page or legacy consumers until a search confirms it is unused on the Settings page.

```css
/* Settings page panels */
.fosse-settings-panel,
.fosse-provider-section {
	background: #fff;
	border: 1px solid #c3c4c7;
	border-radius: 4px;
	padding: 16px 24px;
	margin: 16px 0;
}

.fosse-settings-panel h2,
.fosse-provider-section h2 {
	margin-top: 0;
}

.fosse-settings-section,
.fosse-connection-section {
	border-top: 1px solid #dcdcde;
	margin-top: 16px;
	padding-top: 16px;
}

.fosse-settings-section:first-of-type,
.fosse-connection-section:first-of-type {
	border-top: 0;
	margin-top: 0;
	padding-top: 0;
}

.fosse-settings-section h3,
.fosse-connection-section h3 {
	font-size: 1.1em;
	margin: 0 0 12px;
}

.fosse-settings-actions {
	border-top: 1px solid #dcdcde;
	margin-top: 16px;
	padding-top: 16px;
}

.fosse-settings-actions .button {
	margin: 0;
}
```

  2. Search for remaining `.fosse-provider-section` usage.

```bash
rg "fosse-provider-section|fosse-settings-section|fosse-connection-section" src/Admin tests -n
```

Expected: Settings form sections use `fosse-settings-section`; connection sections use `fosse-connection-section`; `.fosse-provider-section` remains only where a standalone panel is still intended.

  3. Run PHPCS on the PHP files touched so far and Prettier check on the CSS file.

```bash
composer run-script lint-php -- src/Admin/templates/setup-page.php src/Admin/class-ap-provider.php src/Admin/class-bluesky-provider.php tests/php/Admin/Setup_PageTest.php tests/php/Admin/AP_ProviderTest.php tests/php/Admin/Bluesky_ProviderTest.php
pnpm run format:check -- src/Admin/assets/css/admin.css
```

Expected: both commands pass.

- **Verify**: CSS names match rendered markup; lint/format checks pass.
- **Depends on**: Task 3

### Task 5: Update Playwright coverage for visual grouping

- **Status**: ✅ Done (this commit)
- **Files**:
  - Modify: `tests/e2e/bluesky-provider.spec.ts`
- **Do**:
  1. In the disconnected-state test, replace the old heading assertion with scoped group assertions.

```ts
		const federationSettings = page.locator( '#fosse-federation-settings' );
		const connections = page.locator( '#fosse-connections' );
		const blueskyConnection = connections.locator( '#fosse-provider-bluesky' );

		await expect(
			federationSettings.getByRole( 'heading', {
				name: 'Federation settings',
			} )
		).toBeVisible();
		await expect(
			federationSettings.getByRole( 'button', { name: 'Save settings' } )
		).toBeVisible();
		await expect(
			connections.getByRole( 'heading', { name: 'Connections' } )
		).toBeVisible();
		await expect(
			connections.locator( '#fosse-provider-activitypub-connection' )
		).toContainText( 'Connected automatically' );
		await expect(
			blueskyConnection.locator( '#fosse_bluesky_handle' )
		).toBeVisible();
		await expect(
			blueskyConnection.getByRole( 'button', { name: 'Connect Bluesky' } )
		).toBeVisible();
```

  2. In the connected-state test, scope the auto-publish checkbox to `#fosse-federation-settings` and the connection details to `#fosse-connections`.

```ts
		const federationSettings = page.locator( '#fosse-federation-settings' );
		const connections = page.locator( '#fosse-connections' );
		const blueskyConnection = connections.locator( '#fosse-provider-bluesky' );
		const blueskySettings = federationSettings.locator(
			'#fosse-provider-bluesky-settings'
		);

		await expect(
			blueskyConnection.getByRole( 'button', {
				name: 'Disconnect Bluesky',
			} )
		).toBeVisible();
		await expect( blueskyConnection ).toContainText( 'alice.bsky.social' );
		await expect(
			blueskySettings.locator( 'input[name="atmosphere_auto_publish"]' )
		).not.toBeChecked();
```

  3. Run the focused e2e spec.

```bash
pnpm run test:e2e -- tests/e2e/bluesky-provider.spec.ts
```

Expected: the Bluesky provider e2e spec passes and confirms save/connect actions are in different groups.

- **Verify**: Playwright proves the visible grouping on disconnected and connected Bluesky states.
- **Depends on**: Task 4

### Task 6: Run verification and record implementation notes

- **Status**: ✅ Done (this commit)
- **Files**:
  - Create: `sdd/settings-page-scoped-actions/implementation-notes.md`
  - Modify: `sdd/settings-page-scoped-actions/plan.md`
- **Do**:
  1. Run targeted verification first.

```bash
composer run-script test-php -- --filter 'Setup_PageTest|AP_ProviderTest|Bluesky_ProviderTest'
pnpm run test:e2e -- tests/e2e/bluesky-provider.spec.ts
```

Expected: both commands pass.

  2. Run repo-level lint checks.

```bash
composer run-script lint-php
pnpm run format:check
pnpm run lint
```

Expected: all commands pass.

  3. Write `sdd/settings-page-scoped-actions/implementation-notes.md` with this structure and fill it with the actual command results.

```markdown
# Settings Page Scoped Actions Implementation Notes

## Verification

- `composer run-script test-php -- --filter 'Setup_PageTest|AP_ProviderTest|Bluesky_ProviderTest'`: passed
- `pnpm run test:e2e -- tests/e2e/bluesky-provider.spec.ts`: passed
- `composer run-script lint-php`: passed
- `pnpm run format:check`: passed
- `pnpm run lint`: passed

## Deviations

- None.

## Follow-ups

- None.
```

  4. Update this plan's Progress checklist and per-task `**Status**:` fields as each task ships. Use `✅ Done (<commit-or-PR-ref>)` only after the task's Verify steps pass.
  5. Commit the implementation work.

```bash
git add src/Admin/templates/setup-page.php src/Admin/class-ap-provider.php src/Admin/class-bluesky-provider.php src/Admin/assets/css/admin.css tests/php/Admin/Setup_PageTest.php tests/php/Admin/AP_ProviderTest.php tests/php/Admin/Bluesky_ProviderTest.php tests/e2e/bluesky-provider.spec.ts sdd/settings-page-scoped-actions/plan.md sdd/settings-page-scoped-actions/implementation-notes.md
git commit -m "fix: clarify FOSSE settings action scope"
```

- **Verify**: Plan statuses match completed work; implementation notes contain real verification results.
- **Depends on**: Task 5

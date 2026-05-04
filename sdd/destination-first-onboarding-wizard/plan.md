# Destination-First Onboarding Wizard Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Convert the first-run wizard from a welcome-first flow into a destination-first flow where Bluesky is a first-class setup choice.

**Architecture:** Keep the wizard PHP-rendered and option-backed. Add a wizard-owned destination-intent option that controls flow and summary copy only; publishing behavior still uses ActivityPub's existing post-type option and the current Atmosphere projection. Use the existing per-step save pattern, provider-backed Bluesky status, and PHPUnit/Playwright coverage.

**Tech Stack:** PHP 8.2+, WordPress admin pages, WorDBless PHPUnit, Playwright E2E, native WordPress admin CSS, small existing vanilla JS for Appearance preview only.

---

## Progress

- [ ] Task 1: Add destination state and save routing
- [ ] Task 2: Render the destination-card first step
- [ ] Task 3: Update progress, identity copy, and content grouping
- [ ] Task 4: Make the Bluesky step first-class in action hierarchy
- [ ] Task 5: Update review summary and completion behavior
- [ ] Task 6: Update Playwright coverage
- [ ] Task 7: Final verification and SDD status cleanup

## File Structure

- Modify `src/Admin/class-onboarding-wizard.php`
  - Add destination constants and helpers.
  - Replace the Welcome step with a Destinations step.
  - Render dynamic progress with Review included.
  - Route Content directly to Review when Bluesky was not selected.
  - Rework Bluesky disconnected actions so Connect is primary and Skip is secondary.
  - Add destination and Bluesky-skipped states to the Review summary.
- Modify `src/Admin/assets/css/admin.css`
  - Add destination-card styles.
  - Add responsive rules for destination cards, progress, action rows, and the Bluesky form.
  - Keep the visual treatment restrained and consistent with WordPress admin.
- Modify `tests/php/Admin/Onboarding_WizardTest.php`
  - Cover destination state, invalid fallback, routing, rendering, reset cleanup, Review summary, and Bluesky action hierarchy.
- Modify `tests/e2e/onboarding-wizard.spec.ts`
  - Update first-step expectations.
  - Cover the two destination-card paths.
  - Cover the new Bluesky action hierarchy.
- Modify `sdd/destination-first-onboarding-wizard/plan.md`
  - Keep task statuses in sync as implementation progresses.

## Implementation Tasks

### Task 1: Add Destination State And Save Routing

- **Status**: Not started

**Files:**
- Modify: `src/Admin/class-onboarding-wizard.php`
- Modify: `tests/php/Admin/Onboarding_WizardTest.php`

- [ ] **Step 1: Add failing PHPUnit tests for destination persistence**

Add these tests near the existing `handle_save` tests in `tests/php/Admin/Onboarding_WizardTest.php`:

```php
	// --- handle_save: destinations step ---

	/**
	 * Saving the destinations step stores the wizard destination intent.
	 */
	public function test_handle_save_destinations_stores_destination(): void {
		$this->simulate_save_request(
			'destinations',
			array( 'fosse_onboarding_destination' => 'fediverse_only' )
		);

		try {
			Onboarding_Wizard::handle_save();
		} catch ( RedirectFired $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- redirect is expected.
			unset( $e );
		}

		$this->assertSame( 'fediverse_only', get_option( 'fosse_onboarding_destination' ) );
	}

	/**
	 * Invalid destination submissions fall back to the recommended path.
	 */
	public function test_handle_save_destinations_invalid_falls_back_to_default(): void {
		$this->simulate_save_request(
			'destinations',
			array( 'fosse_onboarding_destination' => 'not-a-destination' )
		);

		try {
			Onboarding_Wizard::handle_save();
		} catch ( RedirectFired $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- redirect is expected.
			unset( $e );
		}

		$this->assertSame( 'fediverse_bluesky', get_option( 'fosse_onboarding_destination' ) );
	}
```

Also update `set_up_state()` to clear the new option:

```php
		delete_option( Onboarding_Wizard::DESTINATION_OPTION );
```

- [ ] **Step 2: Run the focused PHPUnit tests and verify they fail**

Run:

```bash
composer run-script test-php -- --filter Onboarding_WizardTest
```

Expected: fail because `Onboarding_Wizard::DESTINATION_OPTION` and `destinations` handling do not exist.

- [ ] **Step 3: Add destination constants and helpers**

In `src/Admin/class-onboarding-wizard.php`, update the class-level step list docblock so Step 1 is `Destinations` rather than `Welcome`, and add this public option constant after `COMPLETED_OPTION`:

```php
	/**
	 * Option key storing the wizard's destination intent.
	 *
	 * This controls onboarding flow and Review-summary wording only. It does
	 * not enable or disable publishing destinations.
	 *
	 * @var string
	 */
	public const DESTINATION_OPTION = 'fosse_onboarding_destination';
```

Add these private constants near `STEPS`:

```php
	/**
	 * Destination intent that includes the Bluesky connection step.
	 *
	 * @var string
	 */
	private const DESTINATION_FEDIVERSE_BLUESKY = 'fediverse_bluesky';

	/**
	 * Destination intent that skips the Bluesky connection step.
	 *
	 * @var string
	 */
	private const DESTINATION_FEDIVERSE_ONLY = 'fediverse_only';

	/**
	 * Valid destination values.
	 *
	 * @var string[]
	 */
	private const DESTINATIONS = array(
		self::DESTINATION_FEDIVERSE_BLUESKY,
		self::DESTINATION_FEDIVERSE_ONLY,
	);
```

Replace the current `STEPS` constant with:

```php
	private const STEPS = array( 'destinations', 'appearance', 'content', 'bluesky', 'complete' );
```

Add these helpers after `get_current_step()` or near the other step helpers:

```php
	/**
	 * Get the saved destination intent, falling back to the recommended path.
	 *
	 * @return string
	 */
	private static function get_destination(): string {
		$destination = (string) get_option( self::DESTINATION_OPTION, self::DESTINATION_FEDIVERSE_BLUESKY );

		return in_array( $destination, self::DESTINATIONS, true )
			? $destination
			: self::DESTINATION_FEDIVERSE_BLUESKY;
	}

	/**
	 * Whether the saved destination intent includes Bluesky setup.
	 *
	 * @return bool
	 */
	private static function destination_includes_bluesky(): bool {
		return self::DESTINATION_FEDIVERSE_BLUESKY === self::get_destination();
	}

	/**
	 * Human label for the saved destination intent.
	 *
	 * @param string $destination Destination value.
	 * @return string
	 */
	private static function get_destination_label( string $destination ): string {
		return self::DESTINATION_FEDIVERSE_ONLY === $destination
			? __( 'Fediverse only', 'fosse' )
			: __( 'Fediverse + Bluesky', 'fosse' );
	}
```

Update `get_current_step()` so the default and legacy welcome slug both resolve to `destinations`:

```php
	private static function get_current_step(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only navigation, no state change.
		$step = sanitize_text_field( wp_unslash( $_GET['step'] ?? 'destinations' ) );

		if ( 'welcome' === $step ) {
			return 'destinations';
		}

		if ( in_array( $step, self::STEPS, true ) ) {
			return $step;
		}

		return 'destinations';
	}
```

- [ ] **Step 4: Save the destination step**

In `handle_save()`, add this block before the existing `appearance` block:

```php
			if ( 'destinations' === $step ) {
				$destination = sanitize_text_field( wp_unslash( $_POST['fosse_onboarding_destination'] ?? '' ) );
				if ( ! in_array( $destination, self::DESTINATIONS, true ) ) {
					$destination = self::DESTINATION_FEDIVERSE_BLUESKY;
				}

				update_option( self::DESTINATION_OPTION, $destination, false );
				self::redirect_to_step( 'appearance' );
			}
```

In `handle_reset()`, delete the destination option before redirecting:

```php
		delete_option( self::DESTINATION_OPTION );
```

Update the final fallback redirect in `handle_save()` from `welcome` to `destinations` so malformed saves land on the real first step:

```php
		self::redirect_to_step( 'destinations' );
```

- [ ] **Step 5: Run focused PHPUnit tests and verify they pass**

Run:

```bash
composer run-script test-php -- --filter Onboarding_WizardTest
```

Expected: pass for the new destination persistence tests and no regressions in the focused class.

- [ ] **Step 6: Commit Task 1**

Run:

```bash
git add src/Admin/class-onboarding-wizard.php tests/php/Admin/Onboarding_WizardTest.php
git commit -m "Wizard: add destination intent state"
```

### Task 2: Render The Destination-Card First Step

- **Status**: Not started

**Files:**
- Modify: `src/Admin/class-onboarding-wizard.php`
- Modify: `src/Admin/assets/css/admin.css`
- Modify: `tests/php/Admin/Onboarding_WizardTest.php`

- [ ] **Step 1: Add failing render tests for the new first step**

Add these tests near the existing render tests:

```php
	/**
	 * The default wizard screen is the destination-selection step.
	 */
	public function test_render_default_step_shows_destination_cards(): void {
		$output = $this->render_wizard_step( '' );

		$this->assertStringContainsString( 'Where should your WordPress posts appear?', $output );
		$this->assertStringContainsString( 'Fediverse + Bluesky', $output );
		$this->assertStringContainsString( 'Fediverse only', $output );
		$this->assertStringContainsString( 'name="fosse_onboarding_destination"', $output );
		$this->assertStringNotContainsString( 'Welcome to FOSSE', $output );
	}

	/**
	 * The legacy welcome slug resolves to the destination-selection step.
	 */
	public function test_render_legacy_welcome_step_shows_destination_cards(): void {
		$output = $this->render_wizard_step( 'welcome' );

		$this->assertStringContainsString( 'Where should your WordPress posts appear?', $output );
		$this->assertStringContainsString( 'Fediverse + Bluesky', $output );
	}
```

Update `render_wizard_step()` so an empty step omits the `step` query arg:

```php
	private function render_wizard_step( string $step ): string {
		$this->become_admin();

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- test setup.
		$_GET = array( 'page' => 'fosse-wizard' );
		if ( '' !== $step ) {
			$_GET['step'] = $step;
		}

		ob_start();
		try {
			Onboarding_Wizard::render();
		} finally {
			$output = ob_get_clean();
		}

		return (string) $output;
	}
```

- [ ] **Step 2: Run the focused render tests and verify they fail**

Run:

```bash
composer run-script test-php -- --filter "test_render_default_step_shows_destination_cards|test_render_legacy_welcome_step_shows_destination_cards"
```

Expected: fail because the wizard still renders the old Welcome step.

- [ ] **Step 3: Switch render dispatch from welcome to destinations**

In `render()`, replace the default branch with a destination branch:

```php
				case 'destinations':
					self::render_step_destinations();
					break;
```

Keep the final `default` branch but point it to destinations:

```php
				default:
					self::render_step_destinations();
					break;
```

- [ ] **Step 4: Replace `render_step_welcome()` with `render_step_destinations()`**

Rename `render_step_welcome()` to `render_step_destinations()` and replace its body with:

```php
	private static function render_step_destinations(): void {
		self::render_progress( 'destinations' );

		$current_destination = self::get_destination();
		$nonce               = wp_create_nonce( 'fosse_wizard' );

		$destinations = array(
			self::DESTINATION_FEDIVERSE_BLUESKY => array(
				'badge' => __( 'Recommended', 'fosse' ),
				'title' => __( 'Fediverse + Bluesky', 'fosse' ),
				'desc'  => __( 'Let people follow your site from Mastodon-compatible apps and publish eligible posts to Bluesky.', 'fosse' ),
			),
			self::DESTINATION_FEDIVERSE_ONLY => array(
				'badge' => __( 'Later', 'fosse' ),
				'title' => __( 'Fediverse only', 'fosse' ),
				'desc'  => __( 'Set up social web following now. Connect Bluesky later from FOSSE Settings.', 'fosse' ),
			),
		);
		?>
		<h1 class="fosse-wizard__title"><?php esc_html_e( 'Where should your WordPress posts appear?', 'fosse' ); ?></h1>
		<p class="fosse-wizard__description">
			<?php esc_html_e( 'Choose the destinations FOSSE should help you set up. You can change this later from FOSSE Settings.', 'fosse' ); ?>
		</p>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="fosse_wizard_save" />
			<input type="hidden" name="fosse_wizard_step" value="destinations" />
			<input type="hidden" name="_wpnonce" value="<?php echo esc_attr( $nonce ); ?>" />

			<div class="fosse-wizard__card">
				<div class="fosse-destination-cards">
					<?php foreach ( $destinations as $value => $destination ) : ?>
						<label class="fosse-destination-card">
							<input
								type="radio"
								name="fosse_onboarding_destination"
								value="<?php echo esc_attr( $value ); ?>"
								class="fosse-destination-card__input"
								<?php checked( $value, $current_destination ); ?>
							/>
							<span class="fosse-destination-card__badge"><?php echo esc_html( $destination['badge'] ); ?></span>
							<span class="fosse-destination-card__title"><?php echo esc_html( $destination['title'] ); ?></span>
							<span class="fosse-destination-card__desc"><?php echo esc_html( $destination['desc'] ); ?></span>
							<span class="fosse-destination-card__check">
								<span class="dashicons dashicons-yes-alt"></span>
							</span>
						</label>
					<?php endforeach; ?>
				</div>
			</div>

			<div class="fosse-wizard__actions fosse-wizard__actions--center">
				<div class="fosse-wizard__actions-column">
					<?php submit_button( __( 'Continue', 'fosse' ), 'primary large', 'submit', false ); ?>
					<a href="<?php echo esc_url( self::get_skip_url() ); ?>" class="fosse-wizard__skip">
						<?php esc_html_e( 'Skip setup', 'fosse' ); ?>
					</a>
				</div>
			</div>
		</form>
		<?php
	}
```

- [ ] **Step 5: Add destination-card CSS**

Append near the wizard card styles in `src/Admin/assets/css/admin.css`, and remove obsolete `.fosse-welcome-features` rules once no markup references them:

```css
/* Destination cards */
.fosse-destination-cards {
	display: grid;
	grid-template-columns: repeat(2, minmax(0, 1fr));
	gap: 16px;
}

.fosse-destination-card {
	position: relative;
	display: flex;
	flex-direction: column;
	min-height: 140px;
	padding: 18px 20px;
	border: 2px solid #dcdcde;
	border-radius: 8px;
	background: #fff;
	cursor: pointer;
	transition:
		border-color 0.15s ease,
		background-color 0.15s ease;
}

.fosse-destination-card:hover {
	border-color: #a7aaad;
}

.fosse-destination-card__input {
	position: absolute;
	opacity: 0;
	pointer-events: none;
}

.fosse-destination-card:has(.fosse-destination-card__input:checked) {
	border-color: #3858e9;
	background: #f7f9ff;
}

.fosse-destination-card:has(.fosse-destination-card__input:focus-visible),
.fosse-destination-card:focus-within {
	border-color: #3858e9;
	outline: 2px solid #3858e9;
	outline-offset: 2px;
}

.fosse-destination-card__badge {
	align-self: flex-start;
	margin-bottom: 10px;
	font-size: 11px;
	font-weight: 600;
	text-transform: uppercase;
	color: #3858e9;
}

.fosse-destination-card__title {
	margin-bottom: 6px;
	font-size: 17px;
	font-weight: 600;
	color: #1e1e1e;
}

.fosse-destination-card__desc {
	font-size: 13px;
	line-height: 1.5;
	color: #646970;
}

.fosse-destination-card__check {
	position: absolute;
	right: 14px;
	top: 14px;
	color: #dcdcde;
}

.fosse-destination-card:has(.fosse-destination-card__input:checked)
	.fosse-destination-card__check {
	color: #3858e9;
}
```

- [ ] **Step 6: Run focused tests**

Run:

```bash
composer run-script test-php -- --filter "test_render_default_step_shows_destination_cards|test_render_legacy_welcome_step_shows_destination_cards"
```

Expected: pass.

- [ ] **Step 7: Commit Task 2**

Run:

```bash
git add src/Admin/class-onboarding-wizard.php src/Admin/assets/css/admin.css tests/php/Admin/Onboarding_WizardTest.php
git commit -m "Wizard: add destination-card first step"
```

### Task 3: Update Progress, Identity Copy, And Content Grouping

- **Status**: Not started

**Files:**
- Modify: `src/Admin/class-onboarding-wizard.php`
- Modify: `src/Admin/assets/css/admin.css`
- Modify: `tests/php/Admin/Onboarding_WizardTest.php`

- [ ] **Step 1: Add failing tests for progress and identity copy**

Add these tests near the progress and appearance render tests:

```php
	/**
	 * Progress reflects the destination-first flow and labels the final step Review.
	 */
	public function test_progress_uses_destination_first_labels(): void {
		$output = $this->render_wizard_step( 'destinations' );

		$this->assertStringContainsString( 'Destinations', $output );
		$this->assertStringContainsString( 'Identity', $output );
		$this->assertStringContainsString( 'Content', $output );
		$this->assertStringContainsString( 'Bluesky', $output );
		$this->assertStringContainsString( 'Review', $output );
		$this->assertStringNotContainsString( '>Welcome<', $output );
	}

	/**
	 * The identity step leads with the follow decision and default card first.
	 */
	public function test_identity_step_uses_follow_question_and_actor_first(): void {
		$output = $this->render_wizard_step( 'appearance' );

		$this->assertStringContainsString( 'Who should people follow?', $output );
		$this->assertMatchesRegularExpression(
			'/fosse-mode-card__title">As you<.*fosse-mode-card__title">As your site</s',
			$output,
			'The default author-profile option should render before the site-profile option.'
		);
	}
```

- [ ] **Step 2: Run focused tests and verify they fail**

Run:

```bash
composer run-script test-php -- --filter "test_progress_uses_destination_first_labels|test_identity_step_uses_follow_question_and_actor_first"
```

Expected: fail because progress still uses the old labels and the Appearance heading/order is unchanged.

- [ ] **Step 3: Make progress dynamic and include Review**

Replace `render_progress()` with:

```php
	private static function render_progress( string $current_step ): void {
		$labels = array(
			'destinations' => __( 'Destinations', 'fosse' ),
			'appearance'   => __( 'Identity', 'fosse' ),
			'content'      => __( 'Content', 'fosse' ),
			'bluesky'      => __( 'Bluesky', 'fosse' ),
			'complete'     => __( 'Review', 'fosse' ),
		);

		$step_keys = array_keys( $labels );
		if ( ! self::destination_includes_bluesky() ) {
			$step_keys = array_values(
				array_filter(
					$step_keys,
					static function ( string $step ): bool {
						return 'bluesky' !== $step;
					}
				)
			);
		}

		$current_i = array_search( $current_step, $step_keys, true );

		if ( false === $current_i ) {
			return;
		}

		?>
		<ol class="fosse-wizard__progress" aria-label="<?php esc_attr_e( 'Setup progress', 'fosse' ); ?>">
			<?php foreach ( $step_keys as $i => $key ) : ?>
				<?php
				$is_complete = $i < $current_i;
				$is_active   = $i === $current_i;
				$classes     = 'fosse-wizard__progress-step';
				if ( $is_complete ) {
					$classes .= ' is-complete';
				}
				if ( $is_active ) {
					$classes .= ' is-active';
				}
				?>
				<?php if ( $i > 0 ) : ?>
					<li class="fosse-wizard__progress-line<?php echo $is_complete ? ' is-complete' : ''; ?>" aria-hidden="true"></li>
				<?php endif; ?>
				<li class="<?php echo esc_attr( $classes ); ?>"<?php echo $is_active ? ' aria-current="step"' : ''; ?>>
					<span class="fosse-wizard__progress-dot" aria-hidden="true"></span>
					<?php echo esc_html( $labels[ $key ] ); ?>
				</li>
			<?php endforeach; ?>
		</ol>
		<?php
	}
```

- [ ] **Step 4: Update progress CSS for `ol` and responsive behavior**

Modify the progress CSS:

```css
.fosse-wizard__progress {
	display: flex;
	align-items: center;
	gap: 8px;
	margin: 0 0 32px;
	padding: 0;
	list-style: none;
}
```

Add this responsive block near the end of the wizard CSS:

```css
@media (max-width: 782px) {
	.fosse-wizard {
		max-width: none;
		margin: 0;
	}

	.fosse-wizard__progress {
		flex-wrap: wrap;
		row-gap: 10px;
	}

	.fosse-wizard__progress-line {
		display: none;
	}

	.fosse-wizard__card {
		padding: 24px;
	}

	.fosse-destination-cards {
		grid-template-columns: 1fr;
	}

	.fosse-wizard__actions,
	.fosse-wizard__actions-primary,
	.fosse-bluesky-form__controls {
		align-items: stretch;
		flex-direction: column;
	}

	.fosse-wizard__actions {
		gap: 12px;
	}

	.fosse-wizard__actions .button,
	.fosse-wizard__actions input[type="submit"] {
		text-align: center;
	}
}
```

- [ ] **Step 5: Reframe Appearance as Identity and reorder cards**

In `render_step_appearance()`, change the heading and description:

```php
		<h1 class="fosse-wizard__title"><?php esc_html_e( 'Who should people follow?', 'fosse' ); ?></h1>
		<p class="fosse-wizard__description">
			<?php esc_html_e( 'Choose the identity people follow from social apps. This affects who appears as the publisher when your selected content is shared.', 'fosse' ); ?>
		</p>
```

Reorder the `$modes` array so `actor` appears first, then `blog`, then `actor_blog`. Use the existing descriptions, moved into this order.

- [ ] **Step 6: Group common and other post types**

In `render_step_content()`, replace the single `foreach ( $all_post_types as $pt )` block with:

```php
					<?php
					$primary_order = array( 'post', 'page' );
					$primary_types = array();
					$other_types   = $all_post_types;
					foreach ( $primary_order as $type_name ) {
						if ( isset( $all_post_types[ $type_name ] ) ) {
							$primary_types[ $type_name ] = $all_post_types[ $type_name ];
							unset( $other_types[ $type_name ] );
						}
					}

					$groups = array(
						'primary' => array(
							'label' => __( 'Common content types', 'fosse' ),
							'types' => $primary_types,
						),
						'other'   => array(
							'label' => __( 'Other content types', 'fosse' ),
							'types' => $other_types,
						),
					);
					foreach ( $groups as $group ) :
						if ( empty( $group['types'] ) ) {
							continue;
						}
						?>
						<div class="fosse-post-types__group">
							<div class="fosse-post-types__group-label"><?php echo esc_html( $group['label'] ); ?></div>
							<?php foreach ( $group['types'] as $pt ) : ?>
								<label class="fosse-post-type-item">
									<input
										type="checkbox"
										name="activitypub_support_post_types[]"
										value="<?php echo esc_attr( $pt->name ); ?>"
										<?php checked( in_array( $pt->name, $post_types, true ) ); ?>
									/>
									<span class="fosse-post-type-item__label">
										<?php echo esc_html( $pt->label ); ?>
									</span>
								</label>
							<?php endforeach; ?>
						</div>
					<?php endforeach; ?>
```

Add CSS:

```css
.fosse-post-types__group {
	display: flex;
	flex-direction: column;
	gap: 12px;
}

.fosse-post-types__group + .fosse-post-types__group {
	margin-top: 20px;
}

.fosse-post-types__group-label {
	font-size: 11px;
	font-weight: 600;
	text-transform: uppercase;
	color: #646970;
}
```

- [ ] **Step 7: Run focused PHPUnit tests**

Run:

```bash
composer run-script test-php -- --filter Onboarding_WizardTest
```

Expected: pass.

- [ ] **Step 8: Commit Task 3**

Run:

```bash
git add src/Admin/class-onboarding-wizard.php src/Admin/assets/css/admin.css tests/php/Admin/Onboarding_WizardTest.php
git commit -m "Wizard: clarify progress identity and content steps"
```

### Task 4: Make The Bluesky Step First-Class In Action Hierarchy

- **Status**: Not started

**Files:**
- Modify: `src/Admin/class-onboarding-wizard.php`
- Modify: `tests/php/Admin/Onboarding_WizardTest.php`
- Modify: `tests/e2e/onboarding-wizard.spec.ts`

- [ ] **Step 1: Add failing PHPUnit tests for Bluesky routing and actions**

Add these tests near the Bluesky render tests:

```php
	/**
	 * The disconnected Bluesky step makes Connect the primary footer action.
	 */
	public function test_render_bluesky_step_disconnected_connect_is_primary_footer_action(): void {
		update_option( Onboarding_Wizard::DESTINATION_OPTION, 'fediverse_bluesky' );

		$output = $this->render_wizard_step( 'bluesky' );

		$this->assertMatchesRegularExpression(
			'/<button[^>]*form="fosse-wizard-bluesky-connect-form"[^>]*class="[^"]*button-primary[^"]*"[^>]*>\s*Connect Bluesky\s*<\/button>/i',
			$output,
			'Connect Bluesky should be the primary footer action.'
		);
		$this->assertStringContainsString( 'Skip Bluesky for now', $output );
	}

	/**
	 * A fediverse-only destination does not render the Bluesky connect form.
	 */
	public function test_render_bluesky_step_fediverse_only_redirects_away(): void {
		update_option( Onboarding_Wizard::DESTINATION_OPTION, 'fediverse_only' );

		$captured = null;
		add_filter(
			'wp_redirect',
			static function ( $location ) use ( &$captured ) {
				$captured = (string) $location;
				throw new RedirectFired( 'redirect' );
			},
			9
		);

		try {
			$this->render_wizard_step( 'bluesky' );
		} catch ( RedirectFired $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- redirect is expected.
			unset( $e );
		}

		$this->assertNotNull( $captured );
		$this->assertStringContainsString( 'step=content', $captured );
	}
```

- [ ] **Step 2: Run focused tests and verify they fail**

Run:

```bash
composer run-script test-php -- --filter "test_render_bluesky_step_disconnected_connect_is_primary_footer_action|test_render_bluesky_step_fediverse_only_redirects_away"
```

Expected: fail because the current disconnected form keeps Connect inside the card and the Bluesky step renders for every destination.

- [ ] **Step 3: Redirect away from Bluesky when not selected**

At the start of `render_step_bluesky()`, before `self::render_progress( 'bluesky' )` or any status reads, add:

```php
		if ( ! self::destination_includes_bluesky() ) {
			self::redirect_to_step( 'content' );
		}
```

- [ ] **Step 4: Move Connect Bluesky into the footer action row**

In the disconnected branch of `render_step_bluesky()`, add a form id:

```php
					<form id="fosse-wizard-bluesky-connect-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
```

Remove the inline `submit_button( __( 'Connect Bluesky', 'fosse' ), ... )` from `.fosse-bluesky-form__controls`. Leave the input in the form.

Before the final action row, create a reusable complete URL:

```php
			$complete_url = wp_nonce_url( admin_url( 'admin-post.php?action=fosse_wizard_complete' ), 'fosse_wizard_complete' );
```

Replace the footer action block with this shape:

```php
			<div class="fosse-wizard__actions">
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=fosse-wizard&step=content' ) ); ?>" class="button">
					&larr; <?php esc_html_e( 'Back', 'fosse' ); ?>
				</a>
				<div class="fosse-wizard__actions-primary">
					<?php if ( $status['available'] && ! $is_connected ) : ?>
						<a href="<?php echo esc_url( $complete_url ); ?>" class="button">
							<?php esc_html_e( 'Skip Bluesky for now', 'fosse' ); ?>
						</a>
						<button type="submit" form="fosse-wizard-bluesky-connect-form" class="button button-primary">
							<?php esc_html_e( 'Connect Bluesky', 'fosse' ); ?>
						</button>
					<?php else : ?>
						<a href="<?php echo esc_url( $complete_url ); ?>" class="button button-primary">
							<?php echo esc_html( $is_connected ? __( 'Finish setup', 'fosse' ) : __( 'Skip for now', 'fosse' ) ); ?>
						</a>
					<?php endif; ?>
				</div>
			</div>
```

- [ ] **Step 5: Run focused PHPUnit tests**

Run:

```bash
composer run-script test-php -- --filter Onboarding_WizardTest
```

Expected: pass.

- [ ] **Step 6: Commit Task 4**

Run:

```bash
git add src/Admin/class-onboarding-wizard.php tests/php/Admin/Onboarding_WizardTest.php
git commit -m "Wizard: make Bluesky connect the primary action"
```

### Task 5: Update Review Summary And Completion Behavior

- **Status**: Not started

**Files:**
- Modify: `src/Admin/class-onboarding-wizard.php`
- Modify: `tests/php/Admin/Onboarding_WizardTest.php`

- [ ] **Step 1: Add failing tests for content routing and Review summary**

Add these tests near the completion tests:

```php
	/**
	 * Saving content on the fediverse-only path completes the wizard and skips Bluesky.
	 */
	public function test_handle_save_content_fediverse_only_redirects_to_complete(): void {
		update_option( Onboarding_Wizard::DESTINATION_OPTION, 'fediverse_only' );

		$captured = null;
		$this->simulate_save_request( 'content', array( 'activitypub_support_post_types' => array( 'post' ) ) );
		add_filter(
			'wp_redirect',
			static function ( $location ) use ( &$captured ) {
				$captured = (string) $location;
				throw new RedirectFired( 'redirect' );
			},
			9
		);

		try {
			Onboarding_Wizard::handle_save();
		} catch ( RedirectFired $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- redirect is expected.
			unset( $e );
		}

		$this->assertTrue( Onboarding_Wizard::is_complete() );
		$this->assertStringContainsString( 'step=complete', (string) $captured );
		$this->assertStringNotContainsString( 'step=bluesky', (string) $captured );
	}

	/**
	 * The Review summary includes the selected destination and skipped Bluesky state.
	 */
	public function test_complete_summary_shows_fediverse_only_destination_and_skipped_bluesky(): void {
		Onboarding_Wizard::mark_complete();
		update_option( Onboarding_Wizard::DESTINATION_OPTION, 'fediverse_only' );

		$output = $this->render_wizard_step( 'complete' );

		$this->assertStringContainsString( 'Destinations', $output );
		$this->assertStringContainsString( 'Fediverse only', $output );
		$this->assertStringContainsString( 'Skipped', $output );
	}

	/**
	 * Reset clears destination intent along with completion state.
	 */
	public function test_handle_reset_clears_destination_intent(): void {
		Onboarding_Wizard::mark_complete();
		update_option( Onboarding_Wizard::DESTINATION_OPTION, 'fediverse_only' );
		$this->simulate_reset_request();

		try {
			Onboarding_Wizard::handle_reset();
		} catch ( RedirectFired $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- redirect is expected.
			unset( $e );
		}

		$this->assertFalse( get_option( Onboarding_Wizard::DESTINATION_OPTION ) );
	}
```

- [ ] **Step 2: Run focused tests and verify they fail**

Run:

```bash
composer run-script test-php -- --filter "test_handle_save_content_fediverse_only_redirects_to_complete|test_complete_summary_shows_fediverse_only_destination_and_skipped_bluesky|test_handle_reset_clears_destination_intent"
```

Expected: fail because content always redirects to Bluesky and the completion summary has no destination row.

- [ ] **Step 3: Route content save based on destination**

In the `content` block inside `handle_save()`, replace:

```php
				update_option( 'activitypub_support_post_types', $post_types );
				self::redirect_to_step( 'bluesky' );
```

with:

```php
				update_option( 'activitypub_support_post_types', $post_types );

				if ( self::destination_includes_bluesky() ) {
					self::redirect_to_step( 'bluesky' );
				}

				self::mark_complete();
				self::redirect_to_step( 'complete' );
```

- [ ] **Step 4: Add Review progress and destination summary**

In `render_step_complete()`, after the `is_complete()` guard, call:

```php
			self::render_progress( 'complete' );
```

Also update the incomplete direct-visit guard to redirect to `destinations` instead of the legacy `welcome` step:

```php
			self::redirect_to_step( 'destinations' );
```

Add destination data near the existing `$actor_mode` line:

```php
			$destination       = self::get_destination();
			$includes_bluesky  = self::DESTINATION_FEDIVERSE_BLUESKY === $destination;
			$destination_label = self::get_destination_label( $destination );
```

Replace the Bluesky summary initialization with:

```php
			$bluesky_summary = $includes_bluesky ? __( 'Not connected', 'fosse' ) : __( 'Skipped', 'fosse' );
			if ( ! $bluesky['available'] && $includes_bluesky ) {
				$bluesky_summary = __( 'Unavailable', 'fosse' );
			}
			if ( $includes_bluesky && $bluesky['connected'] ) {
				$bluesky_summary = $bluesky['handle']
					? sprintf(
						/* translators: %s: Bluesky handle. */
						__( 'Connected as %s', 'fosse' ),
						$bluesky['handle']
					)
					: __( 'Connected', 'fosse' );
			}
```

Add a destination row at the top of the summary table:

```php
					<tr>
						<td class="fosse-summary__label"><?php esc_html_e( 'Destinations', 'fosse' ); ?></td>
						<td class="fosse-summary__value"><?php echo esc_html( $destination_label ); ?></td>
					</tr>
```

Update the completion description:

```php
					<?php esc_html_e( 'Review your setup, then publish from WordPress when you are ready.', 'fosse' ); ?>
```

Update CTA help to depend on destination:

```php
			<p class="fosse-wizard__cta-help">
				<?php
				echo esc_html(
					$includes_bluesky
						? __( 'Your post will reach followers across Mastodon-compatible apps and Bluesky if connected.', 'fosse' )
						: __( 'Your post will reach followers across Mastodon-compatible apps.', 'fosse' )
				);
				?>
			</p>
```

- [ ] **Step 5: Run focused PHPUnit tests**

Run:

```bash
composer run-script test-php -- --filter Onboarding_WizardTest
```

Expected: pass.

- [ ] **Step 6: Commit Task 5**

Run:

```bash
git add src/Admin/class-onboarding-wizard.php tests/php/Admin/Onboarding_WizardTest.php
git commit -m "Wizard: summarize destination-first setup"
```

### Task 6: Update Playwright Coverage

- **Status**: Not started

**Files:**
- Modify: `tests/e2e/onboarding-wizard.spec.ts`

- [ ] **Step 1: Update first-step E2E expectations**

Replace the initial welcome expectations with destination expectations:

```ts
test( 'Wizard page loads without errors', async ( { page } ) => {
	const response = await page.goto( '/wp-admin/admin.php?page=fosse-wizard' );
	expect( response?.status() ).toBeLessThan( 400 );

	await expect(
		page.locator( 'text=/Fatal error|Parse error|Uncaught .*Error/i' )
	).toHaveCount( 0 );
	await expect( page.locator( '#error-page' ) ).toHaveCount( 0 );

	await expect(
		page.locator( '.fosse-wizard__title', {
			hasText: 'Where should your WordPress posts appear?',
		} )
	).toBeVisible();
} );

test( 'Destination step shows two destination cards', async ( { page } ) => {
	await page.goto( '/wp-admin/admin.php?page=fosse-wizard' );

	await expect( page.locator( '.fosse-destination-card' ) ).toHaveCount( 2 );
	await expect( page.locator( '.fosse-destination-card', { hasText: 'Fediverse + Bluesky' } ) ).toBeVisible();
	await expect( page.locator( '.fosse-destination-card', { hasText: 'Fediverse only' } ) ).toBeVisible();
} );
```

- [ ] **Step 2: Update navigation E2E test**

Replace `Get Started navigates to appearance step` with:

```ts
test( 'Destination selection navigates to identity step', async ( { page } ) => {
	await page.goto( '/wp-admin/admin.php?page=fosse-wizard' );

	await page.getByRole( 'button', { name: 'Continue' } ).click();
	await expect( page ).toHaveURL( /step=appearance/ );
	await expect(
		page.locator( '.fosse-wizard__title', {
			hasText: 'Who should people follow?',
		} )
	).toBeVisible();
} );
```

- [ ] **Step 3: Add Fediverse-only skip path E2E test**

Add this test before the existing Bluesky step tests:

```ts
test( 'Fediverse-only path skips the Bluesky connect step', async ( { page } ) => {
	await page.goto( '/wp-admin/admin.php?page=fosse-wizard' );

	await page
		.locator( '.fosse-destination-card', { hasText: 'Fediverse only' } )
		.click();
	await page.getByRole( 'button', { name: 'Continue' } ).click();

	await expect( page ).toHaveURL( /step=appearance/ );
	await page
		.locator( '.fosse-mode-card', {
			has: page.locator( '.fosse-mode-card__title', {
				hasText: /^As you$/,
			} ),
		} )
		.click();
	await page.click( 'input[type="submit"]' );

	await expect( page ).toHaveURL( /step=content/ );
	await page.click( 'input[type="submit"]' );

	await expect( page ).toHaveURL( /step=complete/ );
	await expect(
		page.locator( '.fosse-summary__label', { hasText: 'Destinations' } )
	).toBeVisible();
	await expect( page.locator( '.fosse-summary' ) ).toContainText(
		'Fediverse only'
	);
	await expect( page.locator( '.fosse-summary' ) ).toContainText( 'Skipped' );
} );
```

- [ ] **Step 4: Update Bluesky action hierarchy E2E test**

In `Bluesky step shows connect form`, add:

```ts
	await expect(
		page.getByRole( 'button', { name: 'Connect Bluesky' } )
	).toHaveClass( /button-primary/ );
	await expect(
		page.getByRole( 'link', { name: 'Skip Bluesky for now' } )
	).toBeVisible();
```

- [ ] **Step 5: Run Playwright wizard spec**

Run:

```bash
pnpm exec playwright test tests/e2e/onboarding-wizard.spec.ts
```

Expected: pass.

- [ ] **Step 6: Commit Task 6**

Run:

```bash
git add tests/e2e/onboarding-wizard.spec.ts
git commit -m "Tests: update onboarding wizard e2e flow"
```

### Task 7: Final Verification And SDD Status Cleanup

- **Status**: Not started

**Files:**
- Modify: `sdd/destination-first-onboarding-wizard/plan.md`

- [ ] **Step 1: Run full focused verification**

Run:

```bash
composer run-script test-php -- --filter Onboarding_WizardTest
pnpm exec playwright test tests/e2e/onboarding-wizard.spec.ts
git diff --check
```

Expected: PHPUnit passes, Playwright wizard spec passes, and `git diff --check` produces no output.

- [ ] **Step 2: Run cheap pre-push checks**

Run:

```bash
composer run-script lint-php
pnpm run format:check
pnpm run lint
```

Expected: all three commands pass.

- [ ] **Step 3: Update SDD statuses**

In `sdd/destination-first-onboarding-wizard/plan.md`, set each completed task status to:

```markdown
- **Status**: ✅ Done (<commit-or-pr-ref>)
```

Update the `## Progress` checklist so each completed task is checked.

- [ ] **Step 4: Commit SDD status update**

Run:

```bash
git add sdd/destination-first-onboarding-wizard/plan.md
git commit -m "SDD: mark destination-first wizard plan progress"
```

- [ ] **Step 5: Final branch review**

Run:

```bash
git status --short --branch
git log --oneline origin/trunk..HEAD
git diff --stat origin/trunk..HEAD
```

Expected: clean worktree, commits only for the destination-first wizard work, and changed files limited to the wizard implementation, wizard tests, CSS, and SDD plan.

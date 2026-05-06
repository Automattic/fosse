<?php
/**
 * ActivityPub connection provider.
 *
 * @package Automattic\Fosse
 */

namespace Automattic\Fosse\Admin;

/**
 * Integrates the bundled ActivityPub plugin into FOSSE's admin UI.
 *
 * Self-registers on 'fosse_register_providers'. Reads and writes AP's stored
 * options directly so both surfaces (FOSSE's Settings page and the native AP
 * settings page) stay in sync.
 */
class AP_Provider implements Connection_Provider {

	/**
	 * Allowed actor mode values.
	 *
	 * @var string[]
	 */
	public const ACTOR_MODES = array( 'actor', 'blog', 'actor_blog' );

	/**
	 * Hook into fosse_register_providers to self-register.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( 'fosse_register_providers', array( static::class, 'register_provider' ) );
	}

	/**
	 * Register this provider if the AP plugin is available.
	 *
	 * @return void
	 */
	public static function register_provider(): void {
		$provider = new self();
		if ( $provider->is_available() ) {
			Connection_Provider_Registry::register( $provider );
		}
	}

	/**
	 * Get the provider slug.
	 *
	 * @return string
	 */
	public function get_slug(): string {
		return 'activitypub';
	}

	/**
	 * Get the provider display name.
	 *
	 * @return string
	 */
	public function get_name(): string {
		return 'ActivityPub';
	}

	/**
	 * Check if the AP plugin is loaded.
	 *
	 * @return bool
	 */
	public function is_available(): bool {
		return class_exists( '\Activitypub\Activitypub' );
	}

	/**
	 * Get current AP connection status.
	 *
	 * AP is "connected" whenever the plugin is active — there's no external
	 * auth step. Status includes actor mode, supported post types, and the
	 * separate user / blog fediverse handles surfaced for the active mode.
	 *
	 * Both `user_address` and `blog_address` are populated independently of
	 * mode where the underlying actor exists; callers decide which to show
	 * based on `actor_mode`. The legacy `address` key is kept for backwards
	 * compatibility and prefers the user handle in `actor_blog` mode.
	 *
	 * @return array<string, mixed>
	 */
	public function get_status(): array {
		if ( null !== $this->status_cache ) {
			return $this->status_cache;
		}

		$actor_mode = get_option( 'activitypub_actor_mode', 'actor' );
		$post_types = get_option( 'activitypub_support_post_types', array( 'post' ) );
		// Gate handle resolution by mode. Constructing AP's actor models is
		// not free — it dispatches the `activitypub_construct_model_actor`
		// filter and runs whatever third-party code is hooked there. There's
		// no point paying that cost for a handle the caller can't render in
		// the current mode (e.g. the blog actor in `actor` mode).
		$user_address = $this->mode_includes_user( $actor_mode ) ? $this->get_user_address() : '';
		$blog_address = $this->mode_includes_blog( $actor_mode ) ? $this->get_blog_address() : '';

		$this->status_cache = array(
			'connected'    => true,
			'actor_mode'   => $actor_mode,
			'post_types'   => $post_types,
			'user_address' => $user_address,
			'blog_address' => $blog_address,
			'address'      => $this->resolve_legacy_address( $actor_mode, $user_address, $blog_address ),
		);

		return $this->status_cache;
	}

	/**
	 * Per-request memo for {@see self::get_status()}. The Status page
	 * renders each provider's status twice in the same request — once
	 * filtering on `connected`, once inside `render_status_card()` —
	 * and AP's actor-handle resolution dispatches the
	 * `activitypub_construct_model_actor` filter, which third-party
	 * code can hang arbitrary work off of. Cache the result so the
	 * second call is a free array return.
	 *
	 * @var array<string, mixed>|null
	 */
	private ?array $status_cache = null;

	/**
	 * Render the AP-specific fields inside the unified Settings form.
	 *
	 * Renders only AP-specific rows (site handle, fediverse address
	 * displays). Cross-protocol fields (post types, actor mode) live in
	 * the General section rendered by Setup_Page.
	 *
	 * @return void
	 */
	public function render_setup_section(): void {
		$actor_mode = get_option( 'activitypub_actor_mode', 'actor' );
		$shows_blog = $this->mode_includes_blog( $actor_mode );
		$shows_user = $this->mode_includes_user( $actor_mode );
		// Defer handle resolution until after mode checks so we don't
		// construct the user actor in `blog` mode or the blog actor in
		// `actor` mode — those constructions dispatch
		// `activitypub_construct_model_actor` and aren't free.
		$user_address    = $shows_user ? $this->get_user_address() : '';
		$blog_address    = $shows_blog ? $this->get_blog_address() : '';
		$blog_identifier = (string) get_option( 'activitypub_blog_identifier', '' );
		// Show the dynamic default as a placeholder rather than pre-filling
		// the input with `value="..."`. Saving the form when the user never
		// touched the field would otherwise freeze a literal copy of the
		// then-current default into the option, breaking AP's normal flow
		// (where an unset option resolves to `Blog::get_default_username()`
		// at read time and follows host changes / collision shifts).
		$blog_identifier_placeholder = '';
		if ( '' === $blog_identifier && class_exists( '\Activitypub\Model\Blog' ) ) {
			$blog_identifier_placeholder = (string) \Activitypub\Model\Blog::get_default_username();
		}
		?>
		<div class="fosse-settings-section" id="fosse-provider-activitypub">
			<h3><?php esc_html_e( 'ActivityPub', 'fosse' ); ?></h3>

			<table class="form-table">
				<?php if ( $shows_blog ) : ?>
					<tr>
						<th scope="row">
							<label for="fosse-activitypub-blog-identifier"><?php esc_html_e( 'Site Handle', 'fosse' ); ?></label>
						</th>
						<td>
							<input
								type="text"
								id="fosse-activitypub-blog-identifier"
								name="activitypub_blog_identifier"
								class="regular-text"
								value="<?php echo esc_attr( $blog_identifier ); ?>"
								placeholder="<?php echo esc_attr( $blog_identifier_placeholder ); ?>"
								aria-describedby="fosse-activitypub-blog-identifier-desc"
							/>
							<p id="fosse-activitypub-blog-identifier-desc" class="description">
								<?php esc_html_e( 'The username people use to follow your site from the fediverse. Cannot match an existing author login or nicename.', 'fosse' ); ?>
							</p>
						</td>
					</tr>
				<?php endif; ?>

				<?php if ( $shows_user && $user_address ) : ?>
					<tr>
						<th scope="row"><?php esc_html_e( 'Your fediverse address', 'fosse' ); ?></th>
						<td><code><?php echo esc_html( '@' . $user_address ); ?></code></td>
					</tr>
				<?php endif; ?>

				<?php if ( $shows_blog && $blog_address ) : ?>
					<tr>
						<th scope="row"><?php esc_html_e( 'Site fediverse address', 'fosse' ); ?></th>
						<td><code><?php echo esc_html( '@' . $blog_address ); ?></code></td>
					</tr>
				<?php endif; ?>
			</table>

			<p>
				<a href="<?php echo esc_url( admin_url( 'options-general.php?page=activitypub' ) ); ?>">
					<?php esc_html_e( 'Show advanced ActivityPub settings', 'fosse' ); ?>
				</a>
			</p>
		</div>
		<?php
	}

	/**
	 * Render ActivityPub's read-only connection state.
	 *
	 * ActivityPub has no OAuth connect/disconnect action in FOSSE. Showing a
	 * read-only row in the Connections group keeps the page structure parallel
	 * with Bluesky and explains why there is no ActivityPub button.
	 *
	 * @return void
	 */
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

	/**
	 * Render the AP status card on the FOSSE Status page.
	 *
	 * @return void
	 */
	public function render_status_card(): void {
		$status     = $this->get_status();
		$mode_label = $this->get_actor_mode_label( $status['actor_mode'] );
		$post_types = array_map(
			static function ( $pt_name ) {
				$pt = get_post_type_object( $pt_name );
				return $pt ? $pt->label : $pt_name;
			},
			$status['post_types']
		);
		?>
		<div class="fosse-status-card">
			<h2>
				<span
					class="fosse-status-indicator <?php echo $status['connected'] ? 'connected' : 'disconnected'; ?>"
					role="img"
					aria-label="<?php echo esc_attr( $status['connected'] ? __( 'Connected', 'fosse' ) : __( 'Disconnected', 'fosse' ) ); ?>"
				></span>
				<?php esc_html_e( 'ActivityPub', 'fosse' ); ?>
			</h2>

			<table class="widefat striped fosse-status-card__table">
				<tbody>
					<tr>
						<td class="fosse-status-card__label"><?php esc_html_e( 'Actor Mode', 'fosse' ); ?></td>
						<td class="fosse-status-card__value"><?php echo esc_html( $mode_label ); ?></td>
					</tr>
					<tr>
						<td class="fosse-status-card__label"><?php esc_html_e( 'Post Types', 'fosse' ); ?></td>
						<td class="fosse-status-card__value"><?php echo esc_html( implode( ', ', $post_types ) ); ?></td>
					</tr>
					<?php if ( $this->mode_includes_user( $status['actor_mode'] ) && ! empty( $status['user_address'] ) ) : ?>
						<tr>
							<td class="fosse-status-card__label"><?php esc_html_e( 'Your fediverse address', 'fosse' ); ?></td>
							<td class="fosse-status-card__value">
								<code class="fosse-status-card__token fosse-status-card__token--ap-address">
									<?php
									echo Status_Formatter::ap_address( '@' . $status['user_address'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Status_Formatter::ap_address() escapes input and returns safe HTML with <wbr>.
									?>
								</code>
							</td>
						</tr>
					<?php endif; ?>
					<?php if ( $this->mode_includes_blog( $status['actor_mode'] ) && ! empty( $status['blog_address'] ) ) : ?>
						<tr>
							<td class="fosse-status-card__label"><?php esc_html_e( 'Site fediverse address', 'fosse' ); ?></td>
							<td class="fosse-status-card__value">
								<code class="fosse-status-card__token fosse-status-card__token--ap-address">
									<?php
									echo Status_Formatter::ap_address( '@' . $status['blog_address'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Status_Formatter::ap_address() escapes input and returns safe HTML with <wbr>.
									?>
								</code>
							</td>
						</tr>
					<?php endif; ?>
					<?php $this->render_follower_count_row(); ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	/**
	 * Register hooks for this provider.
	 *
	 * Settings save is centralized in {@see Setup_Page::handle_save()};
	 * AP only registers protocol-specific hooks here.
	 *
	 * @return void
	 */
	public function register_hooks(): void {
		add_filter( 'activitypub_default_blog_username', array( static::class, 'filter_default_blog_username' ) );
	}

	/**
	 * Persist AP-side settings from the unified save submission.
	 *
	 * Validates actor mode and the optional site handle. The post-types
	 * option is owned by {@see Setup_Page::handle_save()} since FOSSE
	 * treats it as a cross-protocol General setting; this method only
	 * touches AP-specific options.
	 *
	 * @param array<string, mixed> $post_data Raw, slashed POST payload.
	 * @return bool False when the site handle was rejected, otherwise true.
	 */
	public function save_settings( array $post_data ): bool {
		$ok = true;

		// Sanitize actor mode against allowlist.
		$mode = isset( $post_data['activitypub_actor_mode'] ) ? sanitize_text_field( wp_unslash( $post_data['activitypub_actor_mode'] ) ) : '';
		if ( in_array( $mode, self::ACTOR_MODES, true ) ) {
			update_option( 'activitypub_actor_mode', $mode );
		} else {
			add_settings_error( 'fosse', 'fosse_invalid_mode', __( 'Invalid actor mode. Setting was not changed.', 'fosse' ), 'error' );
			$ok = false;
		}

		// Site Handle: only persist when the field was submitted with a non-
		// empty value. Empty submissions preserve any existing stored value
		// rather than reverting to AP's default. The actual sanitization
		// (collision rejection, slug canonicalization) runs inside
		// `update_option` via AP's `sanitize_option_activitypub_blog_identifier`
		// filter — calling `\Activitypub\Sanitize::blog_identifier` directly
		// here would double-fire it and double-emit any collision notice.
		if ( array_key_exists( 'activitypub_blog_identifier', $post_data ) ) {
			// Coerce to string defensively: a malformed POST that submits the
			// field as an array would otherwise warn under sanitize_text_field
			// (and trip phpunit's failOnWarning). is_string() guards the raw
			// value before unslash and sanitize, satisfying both the PHPCS
			// sanitization sniff and the runtime warning path.
			$raw_input = is_string( $post_data['activitypub_blog_identifier'] )
				? sanitize_text_field( wp_unslash( $post_data['activitypub_blog_identifier'] ) )
				: '';
			$raw       = trim( $raw_input );
			if ( '' !== $raw ) {
				// Snapshot the queue length, not the codes. AP's sanitizer
				// reuses a constant code (`activitypub_blog_identifier`) for
				// every collision rejection — comparing by code would mask a
				// fresh rejection if any error with that code happened to be
				// on the queue already. Settings errors are append-only, so
				// `array_slice` from the pre-update count reliably captures
				// only the entries this `update_option` call appended.
				$ap_error_count_before = count( get_settings_errors( 'activitypub_blog_identifier' ) );

				update_option( 'activitypub_blog_identifier', $raw );

				// AP's sanitizer raises settings errors under its own group
				// when the input collides with an existing user_login /
				// user_nicename. The FOSSE Settings page renders
				// `settings_errors( 'fosse' )` only, so without this re-tag
				// the user would see a generic "saved" success notice while
				// their requested handle silently fell back to the default.
				$ap_errors_after = get_settings_errors( 'activitypub_blog_identifier' );
				$new_ap_errors   = array_slice( $ap_errors_after, $ap_error_count_before );
				foreach ( $new_ap_errors as $ap_error ) {
					$ok = false;
					add_settings_error(
						'fosse',
						$ap_error['code'],
						$ap_error['message'],
						$ap_error['type']
					);
				}
			}
		}

		return $ok;
	}

	/**
	 * Get the current user's fediverse address.
	 *
	 * Always queries AP's user model, regardless of actor mode. Returns
	 * an empty string when the user model is unavailable or the current
	 * user can't have an actor (e.g. logged-out callers, subscribers
	 * filtered out by `user_can_activitypub`).
	 *
	 * @return string `user@host` form, or empty string.
	 */
	public function get_user_address(): string {
		if ( ! class_exists( '\Activitypub\Model\User' ) ) {
			return '';
		}

		$user = \Activitypub\Model\User::from_wp_user( get_current_user_id() );
		if ( ! $user || is_wp_error( $user ) ) {
			return '';
		}

		return (string) $user->get_webfinger();
	}

	/**
	 * Get the site (blog) fediverse address.
	 *
	 * Always queries AP's blog model, regardless of actor mode. Returns
	 * an empty string when the blog model is unavailable. Callers that
	 * only render the blog identity in `blog` / `actor_blog` modes are
	 * responsible for that mode check — this helper does not gate.
	 *
	 * @return string `blog@host` form, or empty string.
	 */
	public function get_blog_address(): string {
		if ( ! class_exists( '\Activitypub\Model\Blog' ) ) {
			return '';
		}

		$blog = new \Activitypub\Model\Blog();
		return (string) $blog->get_webfinger();
	}

	/**
	 * Resolve a single legacy address for `get_status()['address']`.
	 *
	 * Preserves the pre-split shape: blog mode → blog handle, actor mode
	 * → user handle, actor_blog → user handle (or blog as a fallback).
	 *
	 * @param string $mode         Actor mode value.
	 * @param string $user_address Pre-resolved user handle.
	 * @param string $blog_address Pre-resolved blog handle.
	 * @return string
	 */
	private function resolve_legacy_address( string $mode, string $user_address, string $blog_address ): string {
		if ( 'blog' === $mode ) {
			return $blog_address;
		}

		if ( '' !== $user_address ) {
			return $user_address;
		}

		if ( 'actor_blog' === $mode ) {
			return $blog_address;
		}

		return '';
	}

	/**
	 * Filter callback for `activitypub_default_blog_username`.
	 *
	 * Replaces AP's built-in default (the full site host with `www.`
	 * stripped) with the host's first label, so a Jurassic Ninja site
	 * like `increasing-king-tuna.jurassic.ninja` defaults to
	 * `increasing-king-tuna` rather than the full hostname. Only the
	 * default is filtered — once a site owner saves a value to
	 * `activitypub_blog_identifier`, AP's `Blog::get_preferred_username()`
	 * uses that and never asks for a default again.
	 *
	 * Collisions with existing `user_login` / `user_nicename` values are
	 * resolved by appending a numeric suffix (`-1`, `-2`, …). AP's own
	 * sanitizer also rejects collisions, but enforcing it here means the
	 * proposed default that surfaces in admin forms is already collision-
	 * free instead of a value that would be rewritten on save.
	 *
	 * @param mixed $host Default username supplied by AP (the site host).
	 * @return string
	 */
	public static function filter_default_blog_username( $host ): string {
		if ( ! is_string( $host ) || '' === $host ) {
			return is_string( $host ) ? $host : '';
		}

		$first_label = strtok( $host, '.' );
		if ( ! is_string( $first_label ) || '' === $first_label ) {
			$first_label = $host;
		}

		$candidate = sanitize_title( $first_label );
		if ( '' === $candidate ) {
			$candidate = sanitize_title( $host );
		}
		if ( '' === $candidate ) {
			return $host;
		}

		return self::resolve_blog_username_collision( $candidate );
	}

	/**
	 * Append a numeric suffix to the candidate until it stops colliding
	 * with an existing `user_login` or `user_nicename`.
	 *
	 * Bails after 100 attempts with the last candidate so a degenerate
	 * install with thousands of `foo-N` users can't spin forever.
	 *
	 * @param string $candidate Initial sanitized username.
	 * @return string
	 */
	private static function resolve_blog_username_collision( string $candidate ): string {
		if ( ! self::blog_username_in_use( $candidate ) ) {
			return $candidate;
		}

		$base = $candidate;
		for ( $suffix = 1; $suffix <= 100; $suffix++ ) {
			$next = $base . '-' . $suffix;
			if ( ! self::blog_username_in_use( $next ) ) {
				return $next;
			}
		}

		return $base . '-' . 100;
	}

	/**
	 * Check whether a candidate string matches an existing user.
	 *
	 * Uses exact `get_user_by()` lookups against `login` and `slug`
	 * (`user_nicename`) — the same fields AP's `Sanitize::blog_identifier`
	 * checks before accepting a site handle.
	 *
	 * @param string $candidate Candidate username.
	 * @return bool
	 */
	private static function blog_username_in_use( string $candidate ): bool {
		if ( '' === $candidate ) {
			return false;
		}

		if ( get_user_by( 'login', $candidate ) ) {
			return true;
		}

		if ( get_user_by( 'slug', $candidate ) ) {
			return true;
		}

		return false;
	}

	/**
	 * Whether the given actor mode publishes from per-author profiles.
	 *
	 * @param string $mode Actor mode value.
	 * @return bool
	 */
	public function mode_includes_user( string $mode ): bool {
		return 'actor' === $mode || 'actor_blog' === $mode;
	}

	/**
	 * Whether the given actor mode publishes from a single blog profile.
	 *
	 * @param string $mode Actor mode value.
	 * @return bool
	 */
	public function mode_includes_blog( string $mode ): bool {
		return 'blog' === $mode || 'actor_blog' === $mode;
	}

	/**
	 * Get a human-readable label for an actor mode value.
	 *
	 * @param string $mode The actor mode value.
	 * @return string
	 */
	private function get_actor_mode_label( string $mode ): string {
		$labels = array(
			'actor'      => __( 'Author profiles', 'fosse' ),
			'blog'       => __( 'Blog profile', 'fosse' ),
			'actor_blog' => __( 'Both', 'fosse' ),
		);

		return $labels[ $mode ] ?? $mode;
	}

	/**
	 * Render the follower count row if the AP Followers API is available.
	 *
	 * Uses the blog actor ID in blog mode, the current user in actor mode,
	 * and shows both in actor_blog mode.
	 *
	 * @return void
	 */
	private function render_follower_count_row(): void {
		if ( ! class_exists( '\Activitypub\Collection\Followers' ) ) {
			return;
		}

		$mode        = get_option( 'activitypub_actor_mode', 'actor' );
		$has_blog_id = defined( '\Activitypub\Collection\Actors::BLOG_USER_ID' );

		if ( 'blog' === $mode ) {
			if ( ! $has_blog_id ) {
				return;
			}
			$count = \Activitypub\Collection\Followers::count( \Activitypub\Collection\Actors::BLOG_USER_ID );
			?>
			<tr>
				<td class="fosse-status-card__label"><?php esc_html_e( 'Followers', 'fosse' ); ?></td>
				<td class="fosse-status-card__value"><?php echo esc_html( number_format_i18n( $count ) ); ?></td>
			</tr>
			<?php
		} elseif ( 'actor_blog' === $mode && $has_blog_id ) {
			$user_count = \Activitypub\Collection\Followers::count( get_current_user_id() );
			$blog_count = \Activitypub\Collection\Followers::count( \Activitypub\Collection\Actors::BLOG_USER_ID );
			?>
			<tr>
				<td class="fosse-status-card__label"><?php esc_html_e( 'Followers', 'fosse' ); ?></td>
				<td class="fosse-status-card__value">
					<?php
					printf(
						/* translators: 1: author follower count, 2: blog follower count */
						esc_html__( 'Your followers: %1$s, Blog: %2$s', 'fosse' ),
						esc_html( number_format_i18n( $user_count ) ),
						esc_html( number_format_i18n( $blog_count ) )
					);
					?>
				</td>
			</tr>
			<?php
		} else {
			$count = \Activitypub\Collection\Followers::count( get_current_user_id() );
			?>
			<tr>
				<td class="fosse-status-card__label"><?php esc_html_e( 'Your Followers', 'fosse' ); ?></td>
				<td class="fosse-status-card__value"><?php echo esc_html( number_format_i18n( $count ) ); ?></td>
			</tr>
			<?php
		}
	}
}

<?php
/**
 * Plugins-screen row that explains what happens when FOSSE is deactivated
 * while a standalone ActivityPub or Atmosphere plugin is active.
 *
 * @package Automattic\Fosse
 */

namespace Automattic\Fosse\Admin;

/**
 * Renders a small descriptive row beneath FOSSE on the Plugins screen.
 *
 * The original SDD draft proposed a post-deactivation admin notice, but no
 * FOSSE code runs once FOSSE is deactivated, so the notice could not fire on
 * the "next admin page load" the spec promised. This pre-deactivation row is
 * the FOSSE-owned, actually-renderable equivalent: while FOSSE is active and
 * a standalone backend is also active, the user sees what handoff to expect
 * before they click Deactivate. See `sdd/deactivation-lifecycle/spec.md` for
 * the design pivot rationale.
 */
class Standalone_Handoff_Notice {

	/**
	 * Known canonical plugin paths for the standalone backends FOSSE bundles.
	 */
	private const STANDALONE_AP   = 'activitypub/activitypub.php';
	private const STANDALONE_ATMO = 'atmosphere/atmosphere.php';

	/**
	 * FOSSE plugin file relative path (from `WP_PLUGIN_DIR`). Resolved once at
	 * registration via `plugin_basename( FOSSE_PLUGIN_FILE )` so the hook name
	 * matches the actual install location — mu-plugins, drop-ins, symlinks.
	 * Never hard-coded to `fosse/fosse.php`.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_action(
			'after_plugin_row_' . plugin_basename( dirname( __DIR__, 2 ) . '/fosse.php' ),
			array( static::class, 'render' ),
			10,
			2
		);
	}

	/**
	 * `after_plugin_row_*` callback.
	 *
	 * Bails on Network Admin: the Plugins list there represents network-wide
	 * state, but resolving "what federation continues if FOSSE is network-
	 * deactivated" accurately requires walking every site in the network.
	 * Network-wide cleanup is deferred (DOTCOM-17177) and the per-site Plugins
	 * screen is where the row's audience lives anyway.
	 *
	 * @param string $plugin_file Plugin file relative to WP_PLUGIN_DIR.
	 * @param array  $plugin_data Plugin header data.
	 * @return void
	 */
	public static function render( string $plugin_file, array $plugin_data = array() ): void { // phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable
		if ( function_exists( 'is_network_admin' ) && is_network_admin() ) {
			return;
		}

		echo self::render_for_active_plugins( self::active_plugins() ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- render_for_active_plugins returns pre-escaped HTML.
	}

	/**
	 * Build the row HTML for a given active-plugins list.
	 *
	 * Pure function: takes the active list explicitly so tests don't have to
	 * mutate WordPress' `active_plugins` option. Returns an empty string when
	 * the row should not render (no capability, no standalone active).
	 *
	 * @param string[] $active_plugins Plugin paths (`folder/file.php`) currently active.
	 * @return string HTML for the row, or empty string when nothing to render.
	 */
	public static function render_for_active_plugins( array $active_plugins ): string {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return '';
		}

		$has_ap   = in_array( self::STANDALONE_AP, $active_plugins, true );
		$has_atmo = in_array( self::STANDALONE_ATMO, $active_plugins, true );

		if ( ! $has_ap && ! $has_atmo ) {
			return '';
		}

		$message = self::compose_message( $has_ap, $has_atmo );

		// Match WP core's update-row markup so the row picks up consistent
		// width and styling on the Plugins screen. Column count is queried
		// dynamically because the Plugins screen runs with a variable number
		// of columns (auto-updates column, third-party additions via
		// `manage_plugins_columns`); hard-coding `colspan="4"` would leave
		// the row narrower or wider than the surrounding table.
		return sprintf(
			'<tr class="plugin-update-tr active fosse-handoff-row"><td colspan="%d" class="plugin-update colspanchange"><div class="update-message notice inline notice-info notice-alt"><p>%s</p></div></td></tr>',
			(int) self::plugin_list_colspan(),
			esc_html( $message )
		);
	}

	/**
	 * Compose the user-facing message for the active standalone backends.
	 *
	 * @param bool $has_ap   Standalone ActivityPub is active.
	 * @param bool $has_atmo Standalone Atmosphere is active.
	 * @return string
	 */
	private static function compose_message( bool $has_ap, bool $has_atmo ): string {
		if ( $has_ap && $has_atmo ) {
			return __( 'Federation will continue via the standalone ActivityPub and Atmosphere plugins if you deactivate FOSSE.', 'fosse' );
		}

		if ( $has_ap ) {
			return __( 'Federation will continue via the standalone ActivityPub plugin if you deactivate FOSSE.', 'fosse' );
		}

		return __( 'Federation will continue via the standalone Atmosphere plugin if you deactivate FOSSE.', 'fosse' );
	}

	/**
	 * Resolve the active-plugins list, merging per-site activations with
	 * network-active plugins on multisite.
	 *
	 * Per-site `active_plugins` is a list of plugin paths. Multisite's
	 * `active_sitewide_plugins` is a `[ path => timestamp ]` map keyed by
	 * plugin path. WordPress core's `is_plugin_active()` checks both stores;
	 * doing the same here means the handoff row correctly fires when standalone
	 * AP or Atmosphere is network-activated alongside a per-site FOSSE install.
	 *
	 * @return string[] Unique list of `folder/file.php` plugin paths.
	 */
	private static function active_plugins(): array {
		$per_site = get_option( 'active_plugins', array() );
		$per_site = is_array( $per_site ) ? $per_site : array();

		$network_active = array();
		if ( is_multisite() ) {
			$sitewide = get_site_option( 'active_sitewide_plugins', array() );
			if ( is_array( $sitewide ) ) {
				$network_active = array_keys( $sitewide );
			}
		}

		$merged = array_unique( array_merge( $per_site, $network_active ) );

		return array_values( array_filter( array_map( 'strval', $merged ) ) );
	}

	/**
	 * Number of columns to span beneath the FOSSE plugin row.
	 *
	 * Falls back to 4 when WP's column-header API is unavailable (e.g. the
	 * hook fires in a non-screen context). Matches the pattern WP core uses
	 * in `WP_Plugins_List_Table::single_row()` for update rows.
	 *
	 * @return int
	 */
	private static function plugin_list_colspan(): int {
		$default = 4;

		if ( ! function_exists( 'get_current_screen' ) || ! function_exists( 'get_column_headers' ) ) {
			return $default;
		}

		$screen = get_current_screen();
		if ( null === $screen ) {
			return $default;
		}

		$columns = get_column_headers( $screen );
		if ( ! is_array( $columns ) || empty( $columns ) ) {
			return $default;
		}

		return count( $columns );
	}
}

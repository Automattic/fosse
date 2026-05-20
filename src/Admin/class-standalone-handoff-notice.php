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
	 * Hook the row render to `after_plugin_row_<FOSSE basename>`.
	 *
	 * Caller supplies the FOSSE plugin file's absolute path so the basename
	 * is computed against the real install location (mu-plugins, drop-ins,
	 * symlinks). Never hard-code `fosse/fosse.php`.
	 *
	 * @param string $fosse_plugin_file Absolute path to fosse.php.
	 * @return void
	 */
	public static function register( string $fosse_plugin_file ): void {
		add_action(
			'after_plugin_row_' . plugin_basename( $fosse_plugin_file ),
			array( static::class, 'render' ),
			10,
			2
		);
	}

	/**
	 * `after_plugin_row_*` callback.
	 *
	 * @param string $plugin_file Plugin file relative to WP_PLUGIN_DIR.
	 * @param array  $plugin_data Plugin header data.
	 * @return void
	 */
	public static function render( string $plugin_file, array $plugin_data = array() ): void { // phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable
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
		// width and styling on the Plugins screen.
		return sprintf(
			'<tr class="plugin-update-tr active fosse-handoff-row"><td colspan="4" class="plugin-update colspanchange"><div class="update-message notice inline notice-info notice-alt"><p>%s</p></div></td></tr>',
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
	 * Resolve the current active-plugins list.
	 *
	 * `get_option( 'active_plugins' )` returns plugin paths in the same
	 * `folder/file.php` shape used by `is_plugin_active()`, which is what
	 * we compare against in {@see self::render_for_active_plugins()}.
	 *
	 * @return string[]
	 */
	private static function active_plugins(): array {
		$active = get_option( 'active_plugins', array() );

		return is_array( $active ) ? array_values( array_filter( array_map( 'strval', $active ) ) ) : array();
	}
}

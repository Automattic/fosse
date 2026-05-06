<?php
/**
 * Plugin Name: FOSSE
 * Plugin URI:  https://github.com/Automattic/fosse
 * Description: Social Web
 * Version:     0.0.1
 * Requires at least: 6.9
 * Tested up to: 7.0
 * Requires PHP: 8.2
 * Author:      Automattic, kraftbj, ryancowles
 * Author URI:  https://automattic.com
 * License:     GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: fosse
 *
 * @package Fosse
 */

defined( 'ABSPATH' ) || exit;

if ( file_exists( __DIR__ . '/vendor/autoload_packages.php' ) ) {
	require_once __DIR__ . '/vendor/autoload_packages.php';
}

/*
 * wp.com Simple load contract.
 *
 * On wp.com Simple, FOSSE is included by `wp-content/mu-plugins/fosse-loader.php`
 * at `plugins_loaded` priority 8 — one tick before the platform's
 * `wpcom-activitypub-load.php` (priority 9). Bundled ActivityPub defines
 * `ACTIVITYPUB_PLUGIN_DIR` during its own boot, which trips the
 * `wpcom_activitypub_is_loaded()` early-bail and suppresses the platform AP load.
 *
 * The skip-when-standalone checks below (`ACTIVITYPUB_PLUGIN_VERSION` /
 * `ATMOSPHERE_VERSION`) MUST stay intact: if the wp.com loader ever defined
 * those constants itself, FOSSE would silently skip its own bundle and the
 * rollout would no-op. See DOTCOM-16981.
 */

/*
 * Bundled federation backends.
 *
 * FOSSE ships release-build copies of wordpress-activitypub and
 * wordpress-atmosphere so users get Mastodon + Bluesky federation out
 * of the box. We skip the bundled copy when the standalone plugin is
 * either already loaded (its constants are defined) OR present on
 * disk at the canonical plugin path — so if the user activates the
 * standalone later in the same request, WP's plugin_sandbox_scrape
 * doesn't redeclare classes we already loaded.
 *
 * This is a short-term bootstrap; FOSSE's own UI will replace the
 * bundled plugins' admin surface in a later iteration.
 */
$fosse_loaded_bundled_ap   = false;
$fosse_loaded_bundled_atmo = false;

$fosse_standalone_ap_present = defined( 'ACTIVITYPUB_PLUGIN_VERSION' )
	|| ( defined( 'WP_PLUGIN_DIR' ) && file_exists( WP_PLUGIN_DIR . '/activitypub/activitypub.php' ) );

if ( ! $fosse_standalone_ap_present && file_exists( __DIR__ . '/bundled/activitypub/activitypub.php' ) ) {
	require_once __DIR__ . '/bundled/activitypub/activitypub.php';
	$fosse_loaded_bundled_ap = true;
}

$fosse_standalone_atmo_present = defined( 'ATMOSPHERE_VERSION' )
	|| ( defined( 'WP_PLUGIN_DIR' ) && file_exists( WP_PLUGIN_DIR . '/atmosphere/atmosphere.php' ) );

if ( ! $fosse_standalone_atmo_present && file_exists( __DIR__ . '/bundled/atmosphere/atmosphere.php' ) ) {
	require_once __DIR__ . '/bundled/atmosphere/atmosphere.php';
	$fosse_loaded_bundled_atmo = true;
}

unset( $fosse_standalone_ap_present, $fosse_standalone_atmo_present );

/*
 * First-load bootstrap for the bundled backends.
 *
 * Bundled plugins never go through the WP plugins screen, so their
 * register_activation_hook callbacks never fire. Run the upstream
 * activate() routines once per distinct upstream version to seed
 * options, flush rewrites, and generate any needed identifiers.
 *
 * Hooked on `init` (priority 20) rather than `plugins_loaded` because
 * AP's activate() calls flush_rewrite_rules(), which requires the
 * $wp_rewrite global — initialized on `init`.
 */
add_action(
	'init',
	static function () use ( $fosse_loaded_bundled_ap, $fosse_loaded_bundled_atmo ) {
		// Degrade cleanly if FOSSE's own composer autoload is missing (e.g.
		// a bare clone without vendor/); bundled plugins still load, just
		// without the first-run activation shim.
		if ( ! class_exists( \Automattic\Fosse\Bundled\Bootstrap::class ) ) {
			return;
		}

		if ( $fosse_loaded_bundled_ap && class_exists( '\Activitypub\Activitypub' ) && defined( 'ACTIVITYPUB_PLUGIN_VERSION' ) ) {
			\Automattic\Fosse\Bundled\Bootstrap::maybe_run(
				'fosse_bundled_ap_bootstrapped',
				ACTIVITYPUB_PLUGIN_VERSION,
				static function () {
					\Activitypub\Activitypub::activate( false );
				}
			);
		}

		if ( $fosse_loaded_bundled_atmo && function_exists( '\Atmosphere\activate' ) && defined( 'ATMOSPHERE_VERSION' ) ) {
			\Automattic\Fosse\Bundled\Bootstrap::maybe_run(
				'fosse_bundled_atmosphere_bootstrapped',
				ATMOSPHERE_VERSION,
				'\Atmosphere\activate'
			);
		}
	},
	20
);

/*
 * ActivityPub actor-mode lock enforcement.
 *
 * When the host defines `ACTIVITYPUB_SINGLE_USER_MODE`,
 * `ACTIVITYPUB_DISABLE_USER`, or `ACTIVITYPUB_DISABLE_BLOG_USER`, any
 * write to `activitypub_actor_mode` (admin form, REST `/wp/v2/settings`,
 * or direct options.php POST) is coerced to the forced mode, and an
 * `admin_init` repair pass rewrites the stored value when it disagrees
 * with the forced mode. The repair runs on admin requests only — not
 * on frontend page views — so a high-traffic spike on a corrupted
 * install doesn't multiply into write attempts on every request.
 * Together these keep what bundled AP serves on read aligned with
 * what's actually in the database, so removing the lock later never
 * surfaces a stale value as the new active mode. Degrades cleanly if
 * FOSSE's own composer autoload is missing — the `class_exists` guard
 * skips registration entirely.
 */
if ( class_exists( \Automattic\Fosse\Admin\Actor_Mode_Lock::class ) ) {
	\Automattic\Fosse\Admin\Actor_Mode_Lock::register_hooks();
}

/*
 * Cross-network object-type bridge.
 *
 * Bridges ActivityPub's `activitypub_object_type` option onto Atmosphere's
 * `atmosphere_is_short_form_post` filter so a `'note'` choice in AP's
 * settings also forces Atmosphere short-form. The option is owned by
 * ActivityPub end-to-end; FOSSE no longer keeps a parallel option (see
 * `sdd/canonical-upstream-options/`). Hooked at default priority 10 so
 * the filter callback runs before Atmosphere's transition_post_status
 * handler schedules its outbound work. Degrades cleanly if FOSSE's own
 * composer autoload is missing — same posture as the bundled-bootstrap
 * shim above.
 */
add_action(
	'init',
	static function () {
		if ( ! class_exists( \Automattic\Fosse\Object_Type::class ) ) {
			return;
		}
		\Automattic\Fosse\Object_Type::register();
	}
);

/*
 * Cross-network post-type projector.
 *
 * Feeds ActivityPub's stored `activitypub_support_post_types` option into
 * Atmosphere's `atmosphere_syncable_post_types` filter so the post types a
 * user selects in AP's settings also federate via Atmosphere. Intentionally
 * one-way: AP's option is the single source of truth, so FOSSE does not own
 * a parallel option. Same degradation posture as the Object_Type block.
 */
add_action(
	'init',
	static function () {
		if ( ! class_exists( \Automattic\Fosse\Post_Types::class ) ) {
			return;
		}
		\Automattic\Fosse\Post_Types::register();
	}
);

/*
 * One-time migration of FOSSE-side projector options to canonical
 * upstream options (`fosse_object_type` → `activitypub_object_type`,
 * `fosse_long_form_strategy` → `atmosphere_long_form_composition`).
 *
 * Replaces the long-form `fosse_long_form_strategy` projector entirely
 * and the AP-side half of the object-type projector. Runs at most once
 * per site, gated on a flag option, on `init` priority 5 so the
 * migration completes before the projector callbacks (priority 10) and
 * before any post publish path queries the canonical option. Also
 * seeds Atmosphere's long-form composition with FOSSE's preferred
 * default (`'teaser-thread'`) for fresh installs that have neither
 * option set, preserving today's behavior for new sites.
 *
 * Registration is deferred to `plugins_loaded` (not the surrounding
 * `init` callback) so the migrator's own `add_action('init', ..., 5)`
 * lands on the priority-5 slot of the same `init` cycle. Registering
 * from inside an `init`-default-priority callback would miss the
 * priority-5 slot in the active iteration and the migration would
 * never run on first activation. See `sdd/canonical-upstream-options/`.
 */
add_action(
	'plugins_loaded',
	static function () {
		if ( ! class_exists( \Automattic\Fosse\Canonical_Options_Migrator::class ) ) {
			return;
		}
		\Automattic\Fosse\Canonical_Options_Migrator::register();
	}
);

/*
 * Reactions block relabel.
 *
 * Overlays a FOSSE-flavored title and description onto the bundled
 * activitypub/reactions block via register_block_type_args. The
 * block's server-side render is already protocol-agnostic and
 * aggregates ActivityPub plus Bluesky reactions; the relabel makes
 * the inserter UI wording match what the block actually shows. The
 * register() method itself guards on the AP class_exists check so
 * the filter is never registered on hosts without ActivityPub.
 */
add_action(
	'init',
	static function () {
		if ( ! class_exists( \Automattic\Fosse\Reactions_Label::class ) ) {
			return;
		}
		\Automattic\Fosse\Reactions_Label::register();
	}
);

/*
 * Provider bootstrap.
 *
 * Providers self-register on the 'fosse_register_providers' action fired
 * by Provider_Loader::boot(). This runs unconditionally so provider hooks
 * (option-projection filters, etc.) are active on every request — admin,
 * REST, WebFinger, cron.
 */
if ( class_exists( \Automattic\Fosse\Provider_Loader::class ) ) {
	\Automattic\Fosse\Admin\AP_Provider::init();
	\Automattic\Fosse\Admin\Bluesky_Provider::init();

	\Automattic\Fosse\Provider_Loader::boot();
}

/*
 * Activation redirect.
 *
 * Persists a one-shot signal in the options table on first activation
 * so the admin-init handler in Menu can redirect to the onboarding
 * wizard. Stored with autoload `false` and consumed on the first
 * qualifying admin request. Survives indefinitely if no admin request
 * ever runs (transients TTLed out and could leave the wizard never
 * reached on slow-to-visit installs).
 */
register_activation_hook(
	__FILE__,
	static function () {
		if ( ! class_exists( \Automattic\Fosse\Admin\Onboarding_Wizard::class ) ) {
			error_log( 'FOSSE: Onboarding_Wizard class unavailable on activation; skipping redirect signal.' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- intentional plugin diagnostics; only fires when autoload is broken.
			return;
		}

		$option = \Automattic\Fosse\Admin\Onboarding_Wizard::REDIRECT_OPTION;
		if ( ! update_option( $option, 1, false ) && ! get_option( $option ) ) {
			error_log( 'FOSSE: Failed to persist activation redirect signal (' . $option . ').' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- intentional plugin diagnostics; only fires when the option write actually failed.
		}
	}
);

/*
 * Admin UI: FOSSE setup and status pages.
 *
 * Menu registration, bundled-menu suppression, and CSS enqueue.
 * Provider hooks are already registered above.
 */
if ( is_admin() && class_exists( \Automattic\Fosse\Admin\Menu::class ) ) {
	\Automattic\Fosse\Admin\Menu::register();
}

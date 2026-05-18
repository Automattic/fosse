<?php
/**
 * Plugin Name: FOSSE
 * Plugin URI:  https://github.com/Automattic/fosse
 * Description: Social Web
 * Version:     0.1.1
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

/*
 * Load sentinel. Defined unconditionally so embedders (e.g. wp.com's
 * `wp-content/mu-plugins/fosse-loader.php`) can detect that FOSSE has
 * been required without probing internal classes. Mirrors the
 * `ACTIVITYPUB_PLUGIN_VERSION` / `ATMOSPHERE_VERSION` pattern of the
 * bundled backends. Keep in sync with the `Version:` header above.
 */
define( 'FOSSE_VERSION', '0.1.1' );

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
 * `sdd/canonical-upstream-options/`). Registered on `init` so the filter
 * is in place before Atmosphere queries it during `transition_post_status`
 * later in the request lifecycle. Degrades cleanly if FOSSE's own
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
 * Length-based Bluesky short-form bridge.
 *
 * When a long-form post's rendered body fits inside a single Bluesky
 * record (300 chars), force `atmosphere_is_short_form_post` to true so
 * Atmosphere publishes the body natively (no title prefix, no permalink,
 * no link-card embed) instead of attaching a card to a post whose URL is
 * already in the visible text. Opt back into the long-form path via the
 * `fosse_bsky_link_card_when_post_fits` filter. See `DOTCOM-17097`.
 * Same registration posture as the `Object_Type` bridge above.
 */
add_action(
	'init',
	static function () {
		if ( ! class_exists( \Automattic\Fosse\Bsky_Short_Form_Fit::class ) ) {
			return;
		}
		\Automattic\Fosse\Bsky_Short_Form_Fit::register();
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
 * Self-thread comment suppressor.
 *
 * Stops Atmosphere's `Reaction_Sync` from inserting our own teaser-thread
 * follow-up chunks as WordPress comments when the cron walks our own
 * `listRecords`. Registers a callback on Atmosphere's
 * `atmosphere_should_sync_reply` filter. See `DOTCOM-17098`.
 */
add_action(
	'init',
	static function () {
		if ( ! class_exists( \Automattic\Fosse\Self_Thread_Comment_Filter::class ) ) {
			return;
		}
		\Automattic\Fosse\Self_Thread_Comment_Filter::register();
	}
);

/*
 * Async publish-path metrics subscriber.
 *
 * Listens to bundled ActivityPub's outbox dispatch hooks and bundled
 * Atmosphere's `atmosphere_publish_post_result` hook (added upstream in
 * wordpress-atmosphere PR 56; subscriber is dormant until that lands and
 * gets resynced). Emits `fosse_post_published` + `fosse_publish_result`
 * per the metrics SDD. See `sdd/fosse-metrics-strategy/` and
 * `DOTCOM-17031`. Same degradation posture as the projectors above.
 */
add_action(
	'init',
	static function () {
		if ( ! class_exists( \Automattic\Fosse\Metrics\Publish_Events::class ) ) {
			return;
		}
		\Automattic\Fosse\Metrics\Publish_Events::register();
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
 * Metrics: search-indexing watcher.
 *
 * Emits `fosse_search_indexing_disabled_post_active` when a site flips
 * the federation gate (`blog_public`) off while FOSSE is active. Each
 * host wires the active-determination via the
 * `fosse_metrics_is_active_for_site` filter; the default is `false` so
 * pure-self-host checkouts emit nothing.
 */
add_action(
	'init',
	static function () {
		if ( ! class_exists( \Automattic\Fosse\Metrics\Search_Indexing_Watcher::class ) ) {
			return;
		}
		\Automattic\Fosse\Metrics\Search_Indexing_Watcher::register();
	}
);

/*
 * Provider bootstrap.
 *
 * Providers self-register on the `fosse_register_providers` action fired
 * by Provider_Loader::boot(). Deferred to `plugins_loaded` priority 20 so
 * standalone provider plugins can hook the action from their plugin main
 * file (cleanest) or any `plugins_loaded` priority < 20 without depending
 * on WordPress' alphabetical plugin load order. Priority 20 (not 10)
 * leaves a margin above WordPress' default `add_action` priority so an
 * add-on that defers to `plugins_loaded` without specifying a priority
 * still wins the race.
 *
 * The callback is a named global function (not an inline closure) so
 * PHPUnit can drive the exact production code path — asserting the
 * action binding and exercising the body — instead of testing a
 * closure that the test can never reach by reference.
 */
if ( ! function_exists( 'fosse_boot_providers' ) ) {
	/**
	 * Initialize bundled providers and run the registry boot.
	 *
	 * Wired to `plugins_loaded` priority 20 (see the docblock above).
	 *
	 * @return void
	 */
	function fosse_boot_providers(): void {
		if ( ! class_exists( \Automattic\Fosse\Provider_Loader::class ) ) {
			return;
		}

		\Automattic\Fosse\Admin\AP_Provider::init();
		\Automattic\Fosse\Admin\Bluesky_Provider::init();

		\Automattic\Fosse\Provider_Loader::boot();
	}
}

add_action( 'plugins_loaded', 'fosse_boot_providers', 20 );

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

/*
 * Per-user settings-notice plumbing.
 *
 * Replaces WP core's site-global `settings_errors` transient with a
 * per-user one so admin notices ("Your Bluesky handle is now …",
 * connect/disconnect feedback, settings-saved banners) don't leak
 * across users on multi-admin installs. Hooks `consume()` on
 * `admin_init` priority 1 so the merge into `$wp_settings_errors`
 * runs before any page calls `settings_errors()` to render.
 */
if ( is_admin() && class_exists( \Automattic\Fosse\Admin\User_Notices::class ) ) {
	\Automattic\Fosse\Admin\User_Notices::register();
}

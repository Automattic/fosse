<?php
/**
 * Plugin Name: FOSSE
 * Plugin URI:  https://github.com/Automattic/fosse
 * Description: Social Web
 * Version:     0.1.3-alpha
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
define( 'FOSSE_VERSION', '0.1.3-alpha' );

if ( file_exists( __DIR__ . '/vendor/autoload_packages.php' ) ) {
	require_once __DIR__ . '/vendor/autoload_packages.php';
} else {
	/*
	 * Composer autoload missing. The rest of `fosse.php` already degrades
	 * cleanly via `class_exists` guards (no menu, no projectors, no
	 * provider boot), but that leaves an admin staring at silence. Surface
	 * a `manage_options`-only notice so the operator knows the deploy is
	 * incomplete instead of assuming FOSSE is broken or inactive.
	 */
	add_action(
		'admin_notices',
		static function () {
			if ( ! current_user_can( 'manage_options' ) ) {
				return;
			}
			?>
			<div class="notice notice-error">
				<p>
					<strong><?php esc_html_e( 'FOSSE is missing its Composer dependencies.', 'fosse' ); ?></strong>
					<?php
					esc_html_e(
						'Run `composer install` inside the FOSSE plugin directory, or redeploy a release build that includes the `vendor/` directory. Most FOSSE features are disabled until this is resolved.',
						'fosse'
					);
					?>
				</p>
			</div>
			<?php
		}
	);
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
 * already loaded (its constants are defined), active under any folder
 * name (scanned from `active_plugins` / `active_sitewide_plugins`), or
 * present on disk at the canonical plugin path — so if the standalone
 * loads later in the same request, WP's plugin_sandbox_scrape doesn't
 * redeclare classes we already loaded. See `fosse_detect_standalone()`.
 *
 * This is a short-term bootstrap; FOSSE's own UI will replace the
 * bundled plugins' admin surface in a later iteration.
 */
$fosse_loaded_bundled_ap   = false;
$fosse_loaded_bundled_atmo = false;

if ( ! function_exists( 'fosse_request_is_plugin_activation' ) ) {
	/**
	 * Whether the current request is a WordPress plugin-activation submission.
	 *
	 * Restricted to admin requests targeting `wp-admin/plugins.php` (or its
	 * network counterpart) with an `activate`/`activate-selected` action.
	 * Used only to decide whether `fosse_detect_standalone()` should
	 * consult the `$_REQUEST` activation payload — the nonce itself is
	 * verified later by `wp-admin/plugins.php`. A frontend `?plugin=…`
	 * query string is not enough to trip this guard, so an anonymous
	 * request can't spoof bundled-backend suppression on public routes.
	 *
	 * @return bool
	 */
	function fosse_request_is_plugin_activation(): bool {
		if ( ! is_admin() ) {
			return false;
		}

		$script = isset( $_SERVER['SCRIPT_NAME'] ) && is_string( $_SERVER['SCRIPT_NAME'] )
			? sanitize_text_field( wp_unslash( $_SERVER['SCRIPT_NAME'] ) )
			: '';
		if ( '' === $script ) {
			return false;
		}
		if ( ! str_ends_with( $script, '/wp-admin/plugins.php' )
			&& ! str_ends_with( $script, '/wp-admin/network/plugins.php' ) ) {
			return false;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- only inspected to gate the activation-target scan; nonce verified by wp-admin/plugins.php itself.
		$action = isset( $_REQUEST['action'] ) && is_string( $_REQUEST['action'] )
			? sanitize_text_field( wp_unslash( $_REQUEST['action'] ) )
			: '';

		return in_array( $action, array( 'activate', 'activate-selected' ), true );
	}
}

if ( ! function_exists( 'fosse_detect_standalone' ) ) {
	/**
	 * Detect whether a standalone copy of a bundled backend is present, and how.
	 *
	 * Returns one of four states so callers can both suppress the bundled
	 * copy *and* tell the difference between a healthy standalone and one
	 * whose files are on disk but never load:
	 *
	 *  - `'loaded'`   — the standalone already defined its version constant
	 *                   (it is loading or has loaded this request).
	 *  - `'active'`   — an `active_plugins` (or network) entry resolves to the
	 *                   standalone main file; it will load this request.
	 *  - `'inactive'` — the standalone files exist on disk at the canonical
	 *                   plugin path but no active-plugins entry points at
	 *                   them, so the standalone will NOT load.
	 *  - `''`         — no standalone detected; the bundled copy should load.
	 *
	 * Both the canonical-path check and the `active_plugins` scan run because
	 * a standalone installed under a non-canonical folder name (e.g. a GitHub
	 * clone at `wordpress-activitypub/`) is invisible to the canonical-path
	 * check yet still loads — and, sorting after `fosse/` in `active_plugins`,
	 * loads second and fatals on "Cannot redeclare". Scanning the active list
	 * for any entry whose path ends in the main filename catches that case.
	 *
	 * @param string $version_constant Version constant the standalone defines on
	 *                                 load (e.g. `ACTIVITYPUB_PLUGIN_VERSION`).
	 * @param string $main_file        Standalone main file relative to the plugins
	 *                                 dir at its canonical name
	 *                                 (e.g. `activitypub/activitypub.php`).
	 * @return string One of `'loaded'`, `'active'`, `'inactive'`, or `''`.
	 */
	function fosse_detect_standalone( string $version_constant, string $main_file ): string {
		if ( defined( $version_constant ) ) {
			return 'loaded';
		}

		// Any active-plugins entry whose path ends in the main filename
		// (e.g. `wordpress-activitypub/activitypub.php`) is a standalone
		// that will load this request, regardless of its folder name.
		$basename = '/' . basename( $main_file );

		$active = (array) get_option( 'active_plugins', array() );

		if ( is_multisite() ) {
			// Network-active plugins are stored as path => activation-timestamp.
			$active = array_merge( $active, array_keys( (array) get_site_option( 'active_sitewide_plugins', array() ) ) );
		}

		foreach ( $active as $plugin ) {
			if ( is_string( $plugin ) && str_ends_with( $plugin, $basename ) ) {
				return 'active';
			}
		}

		// Same-request activation: WordPress sandbox-includes the target
		// plugin's main file BEFORE adding it to active_plugins. If a user
		// is mid-activation of a non-canonical standalone copy (e.g. a
		// GitHub clone at `wordpress-activitypub/activitypub.php`), the
		// active_plugins scan above misses it AND the file_exists check
		// below misses it (wrong folder), so the bundled copy would load
		// first and the standalone's sandbox include would fatal on
		// "Cannot redeclare". Scan the activation-request payload itself
		// for any plugin path ending in the main filename and treat it
		// the same as `'active'`.
		//
		// Tight gating: only consult the request payload when we're on
		// `wp-admin/plugins.php` (or its network counterpart) AND the
		// `action` is one of the activation actions. The nonce itself
		// isn't verified yet at plugin-load time, but the action+page
		// pairing already restricts spoofed-request impact to "render
		// the plugins screen without bundled AP/Atmosphere for that one
		// request" — `wp-admin/plugins.php` doesn't need them. A
		// frontend `?plugin=…` query string cannot trip this path, which
		// is the spoof scenario worth preventing (per-request federation
		// suppression on public routes).
		if ( fosse_request_is_plugin_activation() ) {
			// phpcs:disable WordPress.Security.NonceVerification.Recommended -- pre-load context, see fosse_request_is_plugin_activation() guard above.
			$activation_targets = array();
			if ( isset( $_REQUEST['plugin'] ) && is_string( $_REQUEST['plugin'] ) ) {
				$activation_targets[] = wp_unslash( $_REQUEST['plugin'] );
			}
			if ( isset( $_REQUEST['checked'] ) && is_array( $_REQUEST['checked'] ) ) {
				foreach ( $_REQUEST['checked'] as $checked ) {
					if ( is_string( $checked ) ) {
						$activation_targets[] = wp_unslash( $checked );
					}
				}
			}
			// phpcs:enable WordPress.Security.NonceVerification.Recommended

			foreach ( $activation_targets as $target ) {
				if ( str_ends_with( $target, $basename ) ) {
					return 'active';
				}
			}
		}

		// Files on disk at the canonical path but not in any active list:
		// suppress the bundled copy (a later same-request activation would
		// otherwise redeclare), but flag the resulting federation outage.
		if ( defined( 'WP_PLUGIN_DIR' ) && file_exists( WP_PLUGIN_DIR . '/' . $main_file ) ) {
			return 'inactive';
		}

		return '';
	}
}

$fosse_standalone_ap_state   = fosse_detect_standalone( 'ACTIVITYPUB_PLUGIN_VERSION', 'activitypub/activitypub.php' );
$fosse_standalone_atmo_state = fosse_detect_standalone( 'ATMOSPHERE_VERSION', 'atmosphere/atmosphere.php' );

if ( '' === $fosse_standalone_ap_state && file_exists( __DIR__ . '/bundled/activitypub/activitypub.php' ) ) {
	require_once __DIR__ . '/bundled/activitypub/activitypub.php';
	$fosse_loaded_bundled_ap = true;
}

if ( '' === $fosse_standalone_atmo_state && file_exists( __DIR__ . '/bundled/atmosphere/atmosphere.php' ) ) {
	require_once __DIR__ . '/bundled/atmosphere/atmosphere.php';
	$fosse_loaded_bundled_atmo = true;
}

/*
 * Inactive-standalone federation-outage notice.
 *
 * When suppression is due to on-disk files that are NOT actually active
 * (`'inactive'`), the standalone never loads, the bundled copy was
 * suppressed to avoid a redeclaration fatal, and all federation goes
 * dark with no signal. Surface a `manage_options`-only notice so the
 * operator can either activate or remove the dormant standalone. An
 * `'active'` or `'loaded'` standalone is the healthy coexistence path
 * and stays silent. Mirrors the vendor-autoload-missing notice above.
 */
$fosse_inactive_standalones = array();
if ( 'inactive' === $fosse_standalone_ap_state ) {
	$fosse_inactive_standalones[] = 'ActivityPub';
}
if ( 'inactive' === $fosse_standalone_atmo_state ) {
	$fosse_inactive_standalones[] = 'Atmosphere';
}

if ( ! empty( $fosse_inactive_standalones ) ) {
	add_action(
		'admin_notices',
		static function () use ( $fosse_inactive_standalones ) {
			if ( ! current_user_can( 'manage_options' ) ) {
				return;
			}
			$names = implode( ', ', $fosse_inactive_standalones );
			?>
			<div class="notice notice-warning">
				<p>
					<strong><?php esc_html_e( 'FOSSE federation is disabled by a deactivated plugin.', 'fosse' ); ?></strong>
					<?php
					printf(
						/* translators: %s: comma-separated list of plugin names, e.g. "ActivityPub, Atmosphere". */
						esc_html__(
							'FOSSE detected %s installed but deactivated. To avoid a fatal conflict, FOSSE is not loading its bundled copy, so federation is currently off. Either activate the standalone plugin, or delete its files to let FOSSE provide federation.',
							'fosse'
						),
						esc_html( $names )
					);
					?>
				</p>
			</div>
			<?php
		}
	);
}

unset( $fosse_standalone_ap_state, $fosse_standalone_atmo_state, $fosse_inactive_standalones );

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
 * Deactivation lifecycle for the bundled backends.
 *
 * The first-load bootstrap above replays the bundled plugins' activate()
 * routines, but because bundled plugins never go through the plugins
 * screen their register_deactivation_hook callbacks never fire either.
 * Without this, deactivating FOSSE leaves AP's recurring cron events and
 * Atmosphere's one-shot `atmosphere_revoke_refresh_token` event (with its
 * encrypted refresh-token ciphertext) orphaned in `wp_options['cron']`,
 * queued for callbacks the now-inactive plugin no longer registers.
 *
 * Ownership of bundled backend cleanup is decided from PERSISTED state
 * (`fosse_bundled_*_bootstrapped` options) rather than from whether the
 * bundled file loaded this request. A prior bootstrap creates state we
 * still own even if a later same-site state ("inactive standalone files
 * appeared on disk") stopped us from loading the bundled copy this
 * request. We also clear the `fosse_bundled_*_bootstrapped` flags so
 * re-activating FOSSE re-runs the activation shim (re-seeding options
 * and flushing rewrites) rather than assuming the prior bootstrap still
 * holds. Callable names verified against the bundled mains:
 * `\Activitypub\Activitypub::deactivate()` and `\Atmosphere\deactivate()`.
 *
 * On a network-wide deactivation we iterate every site (with
 * `number => 0` so large networks aren't truncated) and call each
 * backend's per-site deactivate routine inside `switch_to_blog()`. This
 * avoids AP's own network loop on top of ours.
 */
register_deactivation_hook(
	__FILE__,
	static function ( $network_wide ) {
		// Decide cleanup ownership from PERSISTED state, not from whether
		// the bundled file loaded this request. A prior request may have
		// bootstrapped bundled AP/Atmosphere; if a canonical standalone
		// directory was later installed but left deactivated,
		// `fosse_detect_standalone()` now returns `'inactive'` and we
		// stopped loading the bundled copy — but we still own the cron
		// state and `fosse_bundled_*_bootstrapped` flags that prior boot
		// created. Reading the persisted option closes that lifecycle gap.
		$cleanup = static function () {
			if ( get_option( 'fosse_bundled_ap_bootstrapped', false ) !== false
				&& class_exists( '\Activitypub\Activitypub' ) ) {
				// Per-site mode here: the outer loop (when present)
				// already iterates sites, so we never double-loop. Pass
				// false explicitly to match.
				\Activitypub\Activitypub::deactivate( false );
				delete_option( 'fosse_bundled_ap_bootstrapped' );
			}

			if ( get_option( 'fosse_bundled_atmosphere_bootstrapped', false ) !== false
				&& function_exists( '\Atmosphere\deactivate' ) ) {
				\Atmosphere\deactivate();
				delete_option( 'fosse_bundled_atmosphere_bootstrapped' );
			}
		};

		// Per-site (non-network) deactivation hits the current blog only.
		if ( ! $network_wide || ! is_multisite() || ! function_exists( 'get_sites' ) ) {
			$cleanup();
			return;
		}

		// Network-wide deactivation must visit every site that
		// bootstrapped a bundled backend. `get_sites()` defaults to
		// `number => 100`, which would silently truncate cleanup on
		// large networks; pass `number => 0` to disable the limit.
		// The AP/Atmosphere deactivate() routines are idempotent and
		// cheap when the cron queue is already clear, so per-site
		// iteration only adds cost where it's load-bearing.
		$sites = get_sites(
			array(
				'fields' => 'ids',
				'number' => 0,
			)
		);
		foreach ( $sites as $site_id ) {
			switch_to_blog( (int) $site_id );
			try {
				$cleanup();
			} finally {
				restore_current_blog();
			}
		}
	}
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
 * Photo-post detection + AP federation-shape projector.
 *
 * Detects when a WP post is shaped like a photo post (post format
 * `image`/`gallery`, or block content that boils down to "image
 * block + optional caption paragraph," or a featured image with
 * minimal body) and forces the outbound ActivityPub envelope into
 * the shape Pixelfed and other IG-style photo clients render
 * natively — `type: Note` with caption-only content and dimensionally
 * complete image attachments. See `DOTCOM-17143`. Same registration
 * posture as the bridges above.
 */
add_action(
	'init',
	static function () {
		if ( ! class_exists( \Automattic\Fosse\Photo_Post::class ) ) {
			return;
		}
		\Automattic\Fosse\Photo_Post::register();
	}
);

/*
 * Photo-post AT Protocol federation-shape projector.
 *
 * Sibling of the AP-side Photo_Post projector. Routes a photo-shaped
 * WP post into Atmosphere's short-form path and replaces the default
 * external link card with a native `app.bsky.embed.images` embed so
 * Flashes / Pinksky render it as a native photo post. See
 * `DOTCOM-17143` and `class-photo-post-atmosphere.php`.
 */
add_action(
	'init',
	static function () {
		if ( ! class_exists( \Automattic\Fosse\Photo_Post_Atmosphere::class ) ) {
			return;
		}
		\Automattic\Fosse\Photo_Post_Atmosphere::register();
	}
);

/*
 * Blurhash placeholder encoder + AP attachment injector, plus the
 * `wp fosse blurhash …` WP-CLI backfill surface.
 *
 * Runtime path: computes a Blurhash string at upload time
 * (cron-scheduled off `wp_generate_attachment_metadata` so the upload
 * UI isn't blocked) and adds the result to outbound ActivityPub
 * `attachment[].blurhash` via the `activitypub_attachment` filter,
 * so Pixelfed and Mastodon paint the colored-blur preview while the
 * full image loads. Sites without GD just skip silently — federation
 * is unaffected. See `DOTCOM-17159` and `class-blurhash.php`.
 *
 * The CLI surface (`Blurhash_CLI`) is gated on `WP_CLI` *before* the
 * `class_exists` autoload probe so the CLI file is never read on web
 * requests — keeps the registration overhead on a normal page load
 * to a single passed-through `class_exists` check.
 *
 * Same degradation posture as the projectors above — if FOSSE's
 * autoload is missing entirely, both classes silently skip.
 *
 * Deferral: newer ActivityPub ships this exact implementation natively
 * (FOSSE's encoder upstreamed — same hooks, same injected `blurhash`
 * member, its own `_activitypub_blurhash` meta key). Running both would
 * double the cron encode work and meta rows for identical output, so
 * when AP's class is present FOSSE registers only the hand-off bridge,
 * which lazily copies FOSSE-era hashes into AP's store the first time
 * each attachment federates ({@see Automattic\Fosse\Blurhash_Handoff}).
 * Backfills then belong to AP's own `wp activitypub blurhash` command.
 */
add_action(
	'init',
	static function () {
		if ( class_exists( \Automattic\Fosse\Blurhash_Handoff::class )
			&& \Automattic\Fosse\Blurhash_Handoff::should_defer() ) {
			\Automattic\Fosse\Blurhash_Handoff::register();
			return;
		}

		if ( ! class_exists( \Automattic\Fosse\Blurhash::class ) ) {
			return;
		}
		\Automattic\Fosse\Blurhash::register();

		if ( defined( 'WP_CLI' ) && WP_CLI && class_exists( \Automattic\Fosse\Blurhash_CLI::class ) ) {
			\Automattic\Fosse\Blurhash_CLI::register();
		}
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
 *
 * Deliberately skips the signal on a network-wide activation: the
 * redirect is a per-site, single-admin onboarding nudge, and WordPress
 * always returns the network admin to the network plugins screen after a
 * network activate — there is no single site to send them to, and setting
 * the option on the network admin's current site would fire the wizard on
 * an arbitrary site the admin may never visit. Per-site activations (the
 * common path) keep the redirect. Honoring $network_wide here also avoids
 * a misleading redirect for the multisite operator who network-activates
 * and then configures each site individually.
 */
register_activation_hook(
	__FILE__,
	static function ( $network_wide ) {
		if ( $network_wide ) {
			return;
		}

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

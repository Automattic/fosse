<?php
/**
 * Readiness probe for FOSSE's federation backends.
 *
 * @package Automattic\Fosse
 */

namespace Automattic\Fosse;

/**
 * Reports whether each federation backend (ActivityPub, Atmosphere) is
 * present, active, recent enough, and exposes the surface FOSSE consumes.
 *
 * This class does not register hooks, write options, or change loaders. It
 * is a pure read of the runtime: callers (admin notices, status page,
 * setup wizard, future `Requires Plugins` cutover) ask `*_status()` and
 * render the answer. The bundled vs. standalone load decision still lives
 * in `fosse.php`.
 *
 * Detection model:
 *
 *   - "Source: bundled" — the loaded copy was required from
 *     `<fosse>/bundled/<slug>/`. FOSSE controls the version on disk via
 *     `tools/sync-bundled.sh`; the version constant on the bundle is the
 *     last upstream tag, which can lag the actual code. We trust the bundle.
 *   - "Source: standalone" — the loaded copy was required from
 *     `WP_PLUGIN_DIR/<slug>/`. The constant reflects the released version
 *     and is enforced against the bundled-version floor (see the
 *     minimum-version policy below).
 *   - "Source: none" — the backend's version constant is undefined, which
 *     means neither copy is loaded.
 *   - "Source: unknown" — the version constant IS defined but the loaded
 *     copy resolves to a path that is neither `<fosse>/bundled/` nor under
 *     `WP_PLUGIN_DIR` (an mu-plugin, platform shim, or symlink outside the
 *     plugins dir). FOSSE can't vouch for such a loader's surface, so it is
 *     reported `incompatible` rather than trusted. An unresolvable path
 *     falls back to `standalone` (floor-enforced), not `unknown`.
 *
 * Minimum-version policy:
 *
 *   The floor a *standalone* backend must meet is the version FOSSE currently
 *   bundles and tests against (the reference implementation): a standalone
 *   older than the bundle is not guaranteed to carry the hooks/filters FOSSE
 *   relies on, so the probe conservatively reports it `too_old`. `too_old`
 *   therefore means "older than what FOSSE ships", not "known-incompatible".
 *
 *   The floor is read from the bundled plugin's own `Version:` header at
 *   runtime — see {@see self::min_activitypub_version()} — NOT stored as a
 *   duplicate constant. `tools/sync-bundled.sh` overwrites those headers on
 *   every resync, so the floor tracks the bundle automatically with no code
 *   change and no drift. (A `bundled` source is trusted regardless of its
 *   version anyway — see the detection model above — so the floor only ever
 *   gates a `standalone` source.)
 */
class Backend_Readiness {

	public const STATUS_OK           = 'ok';
	public const STATUS_MISSING      = 'missing';
	public const STATUS_TOO_OLD      = 'too_old';
	public const STATUS_INCOMPATIBLE = 'incompatible';

	public const SOURCE_BUNDLED    = 'bundled';
	public const SOURCE_STANDALONE = 'standalone';
	public const SOURCE_NONE       = 'none';
	public const SOURCE_UNKNOWN    = 'unknown';

	/**
	 * Bundled plugin main files (relative to this class) whose `Version:`
	 * header is the single source of truth for each backend's standalone
	 * floor. Refreshed on disk by `tools/sync-bundled.sh` on every resync.
	 *
	 * @var array<string, string>
	 */
	private const BUNDLED_MAIN_FILES = array(
		'activitypub' => '/../bundled/activitypub/activitypub.php',
		'atmosphere'  => '/../bundled/atmosphere/atmosphere.php',
	);

	/**
	 * Request-scoped memo of each backend's bundled `Version:` header.
	 *
	 * @var array<string, string>
	 */
	private static $bundled_versions = array();

	/**
	 * Minimum ActivityPub version FOSSE supports as a standalone install:
	 * the version FOSSE currently bundles, read from the bundled plugin's
	 * `Version:` header so it never drifts from what actually ships.
	 *
	 * @return string
	 */
	public static function min_activitypub_version(): string {
		return self::bundled_version( 'activitypub' );
	}

	/**
	 * Minimum Atmosphere version FOSSE supports as a standalone install: the
	 * currently-bundled version, read from its `Version:` header.
	 *
	 * @return string
	 */
	public static function min_atmosphere_version(): string {
		return self::bundled_version( 'atmosphere' );
	}

	/**
	 * Read (and memoize) the `Version:` header of a bundled backend's main
	 * file. Returns '' when the file is unreadable — an impossible-in-practice
	 * broken-deploy case where the whole probe is moot; an empty floor lets
	 * every standalone pass rather than fabricating a version.
	 *
	 * @param string $slug Backend slug (`activitypub` or `atmosphere`).
	 * @return string
	 */
	private static function bundled_version( string $slug ): string {
		if ( isset( self::$bundled_versions[ $slug ] ) ) {
			return self::$bundled_versions[ $slug ];
		}

		$relative = self::BUNDLED_MAIN_FILES[ $slug ] ?? '';
		$version  = '';

		if ( '' !== $relative && function_exists( 'get_file_data' ) ) {
			$file = __DIR__ . $relative;
			if ( is_readable( $file ) ) {
				$data    = get_file_data( $file, array( 'Version' => 'Version' ) );
				$version = isset( $data['Version'] ) ? (string) $data['Version'] : '';
			}
		}

		self::$bundled_versions[ $slug ] = $version;

		return $version;
	}

	/**
	 * Readiness of the ActivityPub backend.
	 *
	 * @return array{
	 *     slug:               string,
	 *     status:             string,
	 *     source:             string,
	 *     installed_version:  string|null,
	 *     required_version:   string,
	 * }
	 */
	public static function activitypub_status(): array {
		return self::evaluate(
			'activitypub',
			defined( 'ACTIVITYPUB_PLUGIN_VERSION' ) ? ACTIVITYPUB_PLUGIN_VERSION : null,
			defined( 'ACTIVITYPUB_PLUGIN_DIR' ) ? ACTIVITYPUB_PLUGIN_DIR : null,
			self::min_activitypub_version()
		);
	}

	/**
	 * Readiness of the Atmosphere backend.
	 *
	 * @return array{
	 *     slug:               string,
	 *     status:             string,
	 *     source:             string,
	 *     installed_version:  string|null,
	 *     required_version:   string,
	 * }
	 */
	public static function atmosphere_status(): array {
		return self::evaluate(
			'atmosphere',
			defined( 'ATMOSPHERE_VERSION' ) ? ATMOSPHERE_VERSION : null,
			defined( 'ATMOSPHERE_PLUGIN_DIR' ) ? ATMOSPHERE_PLUGIN_DIR : null,
			self::min_atmosphere_version()
		);
	}

	/**
	 * Aggregate status across both backends.
	 *
	 * @return array<string, array<string, mixed>> Keyed by slug.
	 */
	public static function all(): array {
		return array(
			'activitypub' => self::activitypub_status(),
			'atmosphere'  => self::atmosphere_status(),
		);
	}

	/**
	 * Whether the two backends together expose everything FOSSE needs.
	 *
	 * Returns true when both report `STATUS_OK`. Callers that want to
	 * degrade per-feature (e.g. AP works, Atmosphere doesn't) should
	 * look at the individual `*_status()` results instead.
	 */
	public static function is_ready(): bool {
		foreach ( self::all() as $report ) {
			if ( self::STATUS_OK !== $report['status'] ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Build a single backend's status report.
	 *
	 * @param string      $slug        Plugin slug (`activitypub` / `atmosphere`).
	 * @param string|null $version     Loaded plugin's `*_VERSION` constant, or null.
	 * @param string|null $plugin_dir  Loaded plugin's `*_PLUGIN_DIR` constant, or null.
	 * @param string      $min_version Required minimum version.
	 */
	private static function evaluate( string $slug, ?string $version, ?string $plugin_dir, string $min_version ): array {
		if ( null === $version ) {
			return array(
				'slug'              => $slug,
				'status'            => self::STATUS_MISSING,
				'source'            => self::SOURCE_NONE,
				'installed_version' => null,
				'required_version'  => $min_version,
			);
		}

		$source = self::resolve_source( $plugin_dir );

		if ( self::SOURCE_BUNDLED === $source ) {
			return array(
				'slug'              => $slug,
				'status'            => self::STATUS_OK,
				'source'            => $source,
				'installed_version' => $version,
				'required_version'  => $min_version,
			);
		}

		/*
		 * A backend whose version constant is defined but whose loaded path
		 * is neither the bundled tree nor anywhere under WP_PLUGIN_DIR is an
		 * unrecognized loader (mu-plugin, platform shim, symlink outside the
		 * plugins dir). FOSSE can't vouch for the surface such a copy exposes,
		 * so it is reported incompatible rather than silently OK.
		 */
		if ( self::SOURCE_UNKNOWN === $source ) {
			return array(
				'slug'              => $slug,
				'status'            => self::STATUS_INCOMPATIBLE,
				'source'            => $source,
				'installed_version' => $version,
				'required_version'  => $min_version,
			);
		}

		$status = version_compare( $version, $min_version, '>=' )
			? self::STATUS_OK
			: self::STATUS_TOO_OLD;

		return array(
			'slug'              => $slug,
			'status'            => $status,
			'source'            => $source,
			'installed_version' => $version,
			'required_version'  => $min_version,
		);
	}

	/**
	 * Decide whether the loaded plugin came from FOSSE's bundled tree or
	 * from a standalone install at the canonical WP plugins path.
	 *
	 * @param string|null $plugin_dir Loaded plugin's `*_PLUGIN_DIR` constant, or null.
	 */
	private static function resolve_source( ?string $plugin_dir ): string {
		/*
		 * No dir constant to classify against — assume a standalone install
		 * and enforce the version floor (conservative; also the shape the
		 * unit tests exercise with synthetic paths).
		 */
		if ( null === $plugin_dir ) {
			return self::SOURCE_STANDALONE;
		}

		$loaded = self::canonical( $plugin_dir );

		/*
		 * Unresolvable path (e.g. realpath() fails): fall back to standalone
		 * so the version floor still applies rather than flipping to a hard
		 * incompatible on a transient FS hiccup.
		 */
		if ( null === $loaded ) {
			return self::SOURCE_STANDALONE;
		}

		$bundled = self::canonical( __DIR__ . '/../bundled' );
		if ( null !== $bundled && self::path_within( $loaded, $bundled ) ) {
			return self::SOURCE_BUNDLED;
		}

		// Under WP_PLUGIN_DIR (canonical or non-canonical folder) — a real standalone, floor-enforced.
		$plugins = \defined( 'WP_PLUGIN_DIR' ) ? self::canonical( WP_PLUGIN_DIR ) : null;
		if ( null !== $plugins && self::path_within( $loaded, $plugins ) ) {
			return self::SOURCE_STANDALONE;
		}

		// Resolvable but outside both the bundle and the plugins dir: an unrecognized loader.
		return self::SOURCE_UNKNOWN;
	}

	/**
	 * Whether `$child` is `$parent` itself or a descendant of it, comparing
	 * separator-normalized paths so the prefix test holds on Windows
	 * (`realpath()` returns backslashes there).
	 *
	 * @param string $child  Canonical child path.
	 * @param string $parent Canonical parent path.
	 * @return bool
	 */
	private static function path_within( string $child, string $parent ): bool {
		$child  = \wp_normalize_path( $child );
		$parent = \wp_normalize_path( $parent );

		return $child === $parent || str_starts_with( $child, $parent . '/' );
	}

	/**
	 * Resolve a path to its canonical, trailing-slash-free form when the
	 * filesystem can resolve it; return null otherwise so the caller can
	 * fall back safely.
	 *
	 * @param string $path Path to canonicalize.
	 */
	private static function canonical( string $path ): ?string {
		$real = realpath( $path );
		return false === $real ? null : rtrim( $real, '/\\' );
	}
}

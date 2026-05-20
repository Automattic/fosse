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
 *     and is enforced against `MIN_*_VERSION`.
 *   - "Source: none" — the backend's version constant is undefined, which
 *     means neither copy is loaded.
 *
 * Minimum-version policy:
 *
 *   `MIN_ACTIVITYPUB_VERSION` and `MIN_ATMOSPHERE_VERSION` point at the
 *   first upstream release that contains the surface FOSSE relies on. They
 *   are deliberately *ahead* of today's WordPress.org-released versions —
 *   FOSSE's `Blurhash` injector needs the `toot:blurhash` JSON-LD context
 *   term (upstream PR 3327) and `Photo_Post_Atmosphere` needs the
 *   `atmosphere_post_embed` filter (upstream PR 72), and neither is in a
 *   tagged release yet. See `audits/2026-05-20-backend-dependency-delta.md`.
 *
 *   When upstream cuts releases that contain those PRs, bump the
 *   constants to the real release numbers. The constants live here so the
 *   bump is a one-line change with no scattered version literals.
 */
class Backend_Readiness {

	/**
	 * Minimum ActivityPub version FOSSE supports as a standalone install.
	 *
	 * Placeholder until upstream cuts a release containing
	 * https://github.com/Automattic/wordpress-activitypub/pull/3327
	 * (the `toot:blurhash` JSON-LD `@context` term). The current
	 * WordPress.org stable is `8.3.0`, which predates that PR.
	 */
	public const MIN_ACTIVITYPUB_VERSION = '8.4.0';

	/**
	 * Minimum Atmosphere version FOSSE supports as a standalone install.
	 *
	 * Placeholder until upstream cuts a release containing
	 * https://github.com/Automattic/wordpress-atmosphere/pull/72
	 * (the `atmosphere_post_embed` filter and `Post::upload_image_blob()`
	 * helper). The current WordPress.org stable is `1.0.0`, which predates
	 * that PR.
	 */
	public const MIN_ATMOSPHERE_VERSION = '1.1.0';

	public const STATUS_OK           = 'ok';
	public const STATUS_MISSING      = 'missing';
	public const STATUS_TOO_OLD      = 'too_old';
	public const STATUS_INCOMPATIBLE = 'incompatible';

	public const SOURCE_BUNDLED    = 'bundled';
	public const SOURCE_STANDALONE = 'standalone';
	public const SOURCE_NONE       = 'none';

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
			self::MIN_ACTIVITYPUB_VERSION
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
			self::MIN_ATMOSPHERE_VERSION
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
		if ( null === $plugin_dir ) {
			return self::SOURCE_STANDALONE;
		}

		$loaded  = self::canonical( $plugin_dir );
		$bundled = self::canonical( __DIR__ . '/../bundled' );

		if ( null === $loaded || null === $bundled ) {
			return self::SOURCE_STANDALONE;
		}

		return str_starts_with( $loaded, $bundled . '/' )
			? self::SOURCE_BUNDLED
			: self::SOURCE_STANDALONE;
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

<?php
/**
 * Test stub for ActivityPub's native Blurhash class.
 *
 * Newer ActivityPub ships `\Activitypub\Blurhash` (FOSSE's encoder
 * upstreamed). The bundled copy in this checkout may predate it, so the
 * hand-off tests load this minimal stand-in — guarded so that once the
 * bundled ActivityPub carries the real class, the stub silently steps
 * aside and the same tests exercise the genuine implementation.
 *
 * The class is declared in the test namespace and aliased to
 * `\Activitypub\Blurhash` at require time, NOT declared in the
 * `Activitypub` namespace directly. The jetpack-autoloader manifest
 * generator merges the root package's `autoload-dev` even on
 * `--no-dev` installs (AutoloadGenerator::parseAutoloadsType), and
 * `--optimize-autoloader` scans `tests/php/` into the production
 * classmap. A literal `Activitypub\Blurhash` declaration here ends up
 * shadowing bundled AP's real class at runtime — AP hooks
 * `Blurhash::init`, the stub has no `init()`, and activation fatals
 * (this took down the Playground e2e suite). `class_alias()` is
 * runtime-only and invisible to classmap scanners, so the alias can
 * never leak into an autoloader manifest.
 *
 * @package Automattic\Fosse
 */

namespace Automattic\Fosse\Tests\Fixtures;

/**
 * Minimal mirror of AP's Blurhash storage surface.
 */
class Activitypub_Blurhash_Stub {

	/**
	 * AP's attachment postmeta key — matches the real class.
	 *
	 * @var string
	 */
	public const META_KEY = '_activitypub_blurhash';

	/**
	 * Read a stored blurhash, or null when absent.
	 *
	 * @param int $attachment_id Attachment post ID.
	 * @return string|null
	 */
	public static function get( int $attachment_id ): ?string {
		$value = \get_post_meta( $attachment_id, self::META_KEY, true );
		if ( ! \is_string( $value ) || '' === $value ) {
			return null;
		}
		return $value;
	}

	/**
	 * Persist a blurhash on the attachment.
	 *
	 * @param int    $attachment_id Attachment post ID.
	 * @param string $hash          Encoded blurhash string.
	 * @return void
	 */
	public static function set( int $attachment_id, string $hash ): void {
		\update_post_meta( $attachment_id, self::META_KEY, $hash );
	}
}

if ( ! \class_exists( '\Activitypub\Blurhash' ) ) {
	\class_alias( Activitypub_Blurhash_Stub::class, '\Activitypub\Blurhash' );
}

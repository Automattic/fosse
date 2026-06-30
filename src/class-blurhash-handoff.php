<?php
/**
 * Hand-off bridge from FOSSE's blurhash store to ActivityPub's native one.
 *
 * @package Automattic\Fosse
 */

namespace Automattic\Fosse;

/**
 * Lazily migrates FOSSE-era blurhashes to ActivityPub's native encoder.
 *
 * ActivityPub upstreamed FOSSE's blurhash implementation (same hooks,
 * same injected `blurhash` member) under its own postmeta key
 * (`_activitypub_blurhash`). When that native encoder is present FOSSE
 * stops registering its own pipeline ({@see Blurhash}) — but images
 * encoded before the switch hold their hash under FOSSE's
 * `_fosse_blurhash` key, which AP's injector can't see. Rather than a
 * bulk meta migration (heavy on large libraries) or letting AP
 * re-encode everything (wasted CPU for identical output), this bridge
 * copies the existing hash across the first time each attachment
 * federates:
 *
 * - AP's injector runs at priority 10 and wins whenever it already has
 *   a hash; the bridge at priority 20 only acts when the outbound
 *   attachment left AP's injector empty-handed.
 * - On a hit, the FOSSE-era hash is written to AP's key (so AP's own
 *   `get()` finds it on every subsequent render and this bridge
 *   becomes a no-op for that attachment) and injected into the
 *   in-flight payload so the current delivery keeps its placeholder.
 *
 * FOSSE's `_fosse_blurhash` rows are left in place — they're tiny,
 * deleting them per-render would add a write to a hot path, and a
 * future cleanup can drop them wholesale once the fleet has converged.
 */
class Blurhash_Handoff {

	/**
	 * Postmeta key holding FOSSE-era blurhashes, from before ActivityPub
	 * shipped its own native encoder. Read-only now: FOSSE's encoder was
	 * removed once AP absorbed it, so nothing writes this key anymore —
	 * the bridge only migrates values written by older FOSSE versions.
	 *
	 * @var string
	 */
	public const LEGACY_META_KEY = '_fosse_blurhash';

	/**
	 * Upper bound on a legacy hash length, in characters. A 4×4 component
	 * grid produces a 30-character hash; the 9×9 grid the spec supports
	 * tops out at 99. We cap a little above that as a defense against
	 * postmeta poisoning — anyone with `edit_post_meta` on an attachment
	 * could otherwise stash arbitrary bytes we would then federate into
	 * AP's store and the outbound envelope.
	 *
	 * @var int
	 */
	private const MAX_HASH_LENGTH = 128;

	/**
	 * Whether FOSSE should defer to ActivityPub's native blurhash encoder.
	 *
	 * True when the loaded ActivityPub (bundled or standalone) ships its
	 * own `Blurhash` class — the upstreamed copy of FOSSE's encoder.
	 * Checked at `init`, well after the plugin load that registers AP's
	 * autoloader, so the class probe is reliable.
	 *
	 * @return bool
	 */
	public static function should_defer(): bool {
		return \class_exists( '\Activitypub\Blurhash' );
	}

	/**
	 * Hook the hand-off bridge.
	 *
	 * Priority 20 is load-bearing: AP's native injector registers at the
	 * default 10, and the bridge must only fill gaps it leaves.
	 *
	 * @return void
	 */
	public static function register(): void {
		\add_filter( 'activitypub_attachment', array( self::class, 'backfill_native_blurhash' ), 20, 2 );
	}

	/**
	 * Copy a FOSSE-era blurhash into AP's store when AP has none.
	 *
	 * @param mixed $attachment    The attachment array as built by bundled AP.
	 * @param mixed $attachment_id The attachment post ID (loosely typed upstream).
	 * @return mixed
	 */
	public static function backfill_native_blurhash( $attachment, $attachment_id ) {
		if ( ! \is_array( $attachment ) ) {
			return $attachment;
		}
		if ( 'Image' !== ( $attachment['type'] ?? '' ) ) {
			return $attachment;
		}
		if ( ! empty( $attachment['blurhash'] ) ) {
			// AP's native injector (or another subscriber) already
			// provided one — nothing to hand off.
			return $attachment;
		}
		if ( ! self::should_defer() ) {
			// Defensive: the bridge is only registered when AP's class
			// exists, but a mid-request unload is cheap to guard.
			return $attachment;
		}

		// The read validates the stored value against the base83 shape,
		// so a poisoned row reads as absent here too.
		$hash = self::read_legacy_hash( (int) $attachment_id );
		if ( null === $hash ) {
			return $attachment;
		}

		\Activitypub\Blurhash::set( (int) $attachment_id, $hash );
		$attachment['blurhash'] = $hash;

		return $attachment;
	}

	/**
	 * Read the legacy FOSSE-era blurhash stored on an attachment, or null
	 * when no usable value is present. Empty/whitespace/non-string values
	 * are treated as absent, as are values that fail
	 * {@see self::is_well_formed_hash()} — so postmeta poisoning never
	 * crosses into AP's store or the federation envelope.
	 *
	 * Inlined from FOSSE's removed `Blurhash` encoder so the bridge is
	 * self-contained: the encoder is gone, but the rows it wrote live on
	 * until a future bulk cleanup.
	 *
	 * @param int $attachment_id Attachment post ID.
	 * @return string|null
	 */
	private static function read_legacy_hash( int $attachment_id ): ?string {
		$value = \get_post_meta( $attachment_id, self::LEGACY_META_KEY, true );
		if ( ! \is_string( $value ) ) {
			return null;
		}
		$value = \trim( $value );
		if ( '' === $value ) {
			return null;
		}
		return self::is_well_formed_hash( $value ) ? $value : null;
	}

	/**
	 * Validate a stored hash against the blurhash spec's character set
	 * and our length bound. Treats any out-of-bounds value as absent
	 * rather than coercing it — no blurhash is better than a wrong one.
	 * The base83 alphabet is defined by the spec at
	 * {@link https://github.com/woltapp/blurhash/blob/master/Algorithm.md#base-83}.
	 *
	 * @param string $hash Candidate hash string.
	 * @return bool
	 */
	private static function is_well_formed_hash( string $hash ): bool {
		$length = \strlen( $hash );
		if ( $length < 6 || $length > self::MAX_HASH_LENGTH ) {
			return false;
		}
		return 1 === \preg_match( '/\A[0-9A-Za-z#$%*+,\-.:;=?@\[\]\^_{|}~]+\z/', $hash );
	}
}

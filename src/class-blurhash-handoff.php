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

		// FOSSE's get() validates the stored value against the base83
		// shape, so a poisoned row reads as absent here too.
		$hash = Blurhash::get( (int) $attachment_id );
		if ( null === $hash ) {
			return $attachment;
		}

		\Activitypub\Blurhash::set( (int) $attachment_id, $hash );
		$attachment['blurhash'] = $hash;

		return $attachment;
	}
}

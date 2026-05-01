<?php
/**
 * Status-card token formatting helpers.
 *
 * @package Automattic\Fosse
 */

namespace Automattic\Fosse\Admin;

/**
 * Renders identifier tokens (DIDs, URLs, handles, fediverse addresses) for the
 * Status page with sensible word-break opportunities baked in.
 *
 * Status cards live inside a CSS grid. Without break hints the longest token
 * dictates a card's min-content width, which forces the grid to overflow.
 * Each helper here returns escaped HTML with `<wbr>` markers inserted at
 * separator boundaries (`:`, `/`, `.`, `@`) so the browser can wrap mid-token
 * at the most readable point. DID and URL tokens still need
 * `overflow-wrap: anywhere` (CSS) as a last resort because their identifier
 * segments can be a long unbroken run with no separators.
 */
class Status_Formatter {

	/**
	 * Format a Bluesky DID for display.
	 *
	 * Splits on `:` so `did:plc:abc123…` can wrap after `did:` and the
	 * method segment instead of breaking mid-identifier.
	 *
	 * @param string $did Raw DID (e.g. `did:plc:abc123`).
	 * @return string Escaped HTML safe to echo, with `<wbr>` after each `:`.
	 */
	public static function did( string $did ): string {
		return self::join_with_wbr( ':', $did );
	}

	/**
	 * Format a URL for display.
	 *
	 * Allows the browser to break after the scheme (`https://`), and before
	 * each `/`, `?`, `#`, or `&` in the rest of the URL. The path separators
	 * read more naturally as the start of the next segment, so the `<wbr>`
	 * is placed before them.
	 *
	 * @param string $url Raw URL.
	 * @return string Escaped HTML safe to echo, with `<wbr>` at sensible boundaries.
	 */
	public static function url( string $url ): string {
		$escaped = esc_html( $url );

		// Allow break right after the scheme (e.g. `https://`). Done first so
		// the `<wbr>` we insert here doesn't get re-broken by the path-level
		// pass below.
		$escaped = (string) preg_replace( '~(://)~', '$1<wbr>', $escaped, 1 );

		// Allow breaks before path/query/fragment/parameter separators in
		// the remainder, after the scheme marker we just inserted (so the
		// `://` itself isn't re-broken).
		$marker = '://<wbr>';
		$pos    = strpos( $escaped, $marker );
		if ( false !== $pos ) {
			$prefix  = substr( $escaped, 0, $pos + strlen( $marker ) );
			$rest    = substr( $escaped, $pos + strlen( $marker ) );
			$rest    = (string) preg_replace( '~([/?\#&])~', '<wbr>$1', $rest );
			$escaped = $prefix . $rest;
		} else {
			$escaped = (string) preg_replace( '~([/?\#&])~', '<wbr>$1', $escaped );
		}

		return $escaped;
	}

	/**
	 * Format an AT Protocol / fediverse handle for display.
	 *
	 * Handles are domain-shaped (`alice.bsky.social`); inserting `<wbr>` after
	 * each `.` lets the browser wrap on label boundaries.
	 *
	 * @param string $handle Raw handle.
	 * @return string Escaped HTML safe to echo, with `<wbr>` after each `.`.
	 */
	public static function handle( string $handle ): string {
		return self::join_with_wbr( '.', $handle );
	}

	/**
	 * Format an ActivityPub fediverse address for display (`@user@host.example`).
	 *
	 * The leading `@` is preserved verbatim. The local part stays intact, and
	 * the host part is broken on its dots so a long host label can wrap.
	 *
	 * @param string $address Raw address with or without a leading `@`.
	 * @return string Escaped HTML safe to echo, with `<wbr>` between the local part and host, and after each `.` in the host.
	 */
	public static function ap_address( string $address ): string {
		$leading_at = '';
		if ( '' !== $address && '@' === $address[0] ) {
			$leading_at = '@';
			$address    = substr( $address, 1 );
		}

		$at_pos = strpos( $address, '@' );
		if ( false === $at_pos ) {
			return esc_html( $leading_at . $address );
		}

		$local = substr( $address, 0, $at_pos );
		$host  = substr( $address, $at_pos + 1 );

		return esc_html( $leading_at . $local ) . '<wbr>@' . self::handle( $host );
	}

	/**
	 * Escape each segment between `$separator` and rejoin with `<wbr>` after
	 * the separator. Preserves a trailing empty segment if the input ends in
	 * the separator.
	 *
	 * @param string $separator Single-character separator.
	 * @param string $value     Raw value.
	 * @return string Escaped HTML with `<wbr>` after each separator.
	 */
	private static function join_with_wbr( string $separator, string $value ): string {
		$parts   = explode( $separator, $value );
		$escaped = array_map( 'esc_html', $parts );

		return implode( $separator . '<wbr>', $escaped );
	}
}

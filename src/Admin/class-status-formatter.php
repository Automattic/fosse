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
	 * Format a URL for display as inline text.
	 *
	 * Allows the browser to break after the scheme (`https://`), and before
	 * each `/`, `?`, `#`, or `&` in the rest of the URL. The path separators
	 * read more naturally as the start of the next segment, so the `<wbr>`
	 * is placed before them.
	 *
	 * Returns escaped HTML suitable for **text contexts** (`<code>`, `<span>`,
	 * `<td>`). Does NOT validate URL syntax — never feed the result into
	 * `href=`, `src=`, or innerHTML. Use `esc_url()` for those.
	 *
	 * @param string $url Raw URL.
	 * @return string Escaped HTML safe to echo into text content, with `<wbr>` at sensible boundaries.
	 */
	public static function url( string $url ): string {
		// Place `<wbr>` markers BEFORE escaping so the regex operates on the
		// raw URL where `/`, `?`, `#`, and `&` mean what they look like.
		// Earlier versions ran the regex on the post-`esc_html` string, which
		// broke numeric character references (`'` -> `&#039;`): the regex
		// matched the `&` and the `#` independently and inserted `<wbr>`
		// markers inside the entity, leaving a literal `&#039;` on screen
		// instead of `'`. Marker-then-escape keeps the entity intact.
		//
		// `~~~FOSSE_WBR~~~` is a placeholder built from characters `esc_html`
		// leaves alone (no `<>&"'`). Any literal occurrence in the input
		// would render as an extra `<wbr>` (harmless — `<wbr>` is empty).
		$placeholder = '~~~FOSSE_WBR~~~';

		// Allow break right after the scheme (e.g. `https://`).
		$marked = (string) preg_replace( '~(://)~', '$1' . $placeholder, $url, 1 );

		// Allow breaks before path/query/fragment/parameter separators in
		// the remainder, after the first `://` (so the scheme's slashes
		// aren't re-broken). A second `://` further down (e.g. an OAuth-
		// shaped `?next=https://...`) will pick up extra `<wbr>` markers
		// between its trailing `/`s — harmless because `<wbr>` renders empty.
		$boundary = '://' . $placeholder;
		$pos      = strpos( $marked, $boundary );
		if ( false !== $pos ) {
			$skip   = $pos + strlen( $boundary );
			$prefix = substr( $marked, 0, $skip );
			$rest   = substr( $marked, $skip );
			$rest   = (string) preg_replace( '~([/?#&])~', $placeholder . '$1', $rest );
			$marked = $prefix . $rest;
		} else {
			$marked = (string) preg_replace( '~([/?#&])~', $placeholder . '$1', $marked );
		}

		return str_replace( $placeholder, '<wbr>', esc_html( $marked ) );
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
	 * The leading `@` is preserved verbatim if present, otherwise the address
	 * renders without one. The local part stays intact, and the host part is
	 * broken on its dots so a long host label can wrap.
	 *
	 * Contract: an address is "local@host" with one separating `@`. The first
	 * `@` AFTER the optional leading `@` is treated as the local/host
	 * separator; any subsequent `@` characters stay in the host part as
	 * literal text. Inputs without an `@` after the optional leading one
	 * (e.g. `@nodomain`, `@user@`) render with no host break — safe but
	 * cosmetically wrong, since they aren't well-formed addresses anyway.
	 *
	 * Webfinger-shaped inputs from the AP plugin do NOT carry a leading `@`;
	 * the call site at `AP_Provider::render_status_card()` prepends it.
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

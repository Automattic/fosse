<?php
/**
 * Bucketing helpers for FOSSE metrics property values.
 *
 * @package Automattic\Fosse
 */

namespace Automattic\Fosse\Metrics;

/**
 * Centralized low-cardinality bucket producers.
 *
 * Per `sdd/fosse-metrics-strategy/implementation.md` § Engagement,
 * `days_since_*` bucketing belongs in the recorder, not at call sites.
 * Same posture for any other low-cardinality property — the call site
 * passes raw counts/timestamps; this class returns the bucket label
 * the schema allows.
 *
 * Buckets are intentionally string labels (not enums) so dashboards
 * read cleanly without per-value lookup tables.
 */
final class Buckets {

	/**
	 * Bucket a federated-post-types count.
	 *
	 * Boundaries chosen for the Cohort A → step 5 funnel: the modal
	 * value is 1 (default `post`), so `'1'` and `'2-3'` carry most of
	 * the signal. `'4-5'` and `'6+'` exist to keep the long tail
	 * legible without per-N noise.
	 *
	 * @param int $count Raw count of federated post types.
	 * @return string `'1'|'2-3'|'4-5'|'6+'|'0'`.
	 */
	public static function post_types_count( int $count ): string {
		if ( $count <= 0 ) {
			return '0';
		}
		if ( 1 === $count ) {
			return '1';
		}
		if ( $count <= 3 ) {
			return '2-3';
		}
		if ( $count <= 5 ) {
			return '4-5';
		}
		return '6+';
	}

	/**
	 * Bucket "days elapsed since publish" for engagement events.
	 *
	 * Mirrors the funnel-spec's `0-1 / 1-7 / 7-14 / 14+` windows
	 * (see `implementation.md` § Engagement).
	 *
	 * @param int $days Whole days since the originating publish.
	 * @return string `'0-1'|'1-7'|'7-14'|'14+'`.
	 */
	public static function days_since_publish( int $days ): string {
		return self::days_window( $days );
	}

	/**
	 * Bucket "days elapsed since interaction" for author-engaged events.
	 *
	 * @param int $days Whole days since the inbound interaction.
	 * @return string `'0-1'|'1-7'|'7-14'|'14+'`.
	 */
	public static function days_since_interaction( int $days ): string {
		return self::days_window( $days );
	}

	/**
	 * Shared `0-1 / 1-7 / 7-14 / 14+` bucketer.
	 *
	 * @param int $days Whole-day count.
	 * @return string
	 */
	private static function days_window( int $days ): string {
		if ( $days < 0 ) {
			$days = 0;
		}
		if ( $days <= 1 ) {
			return '0-1';
		}
		if ( $days <= 7 ) {
			return '1-7';
		}
		if ( $days <= 14 ) {
			return '7-14';
		}
		return '14+';
	}
}

<?php
/**
 * Tests for the metrics Buckets helpers.
 *
 * @package Automattic\Fosse
 */

namespace Automattic\Fosse\Tests\Metrics;

use Automattic\Fosse\Metrics\Buckets;
use PHPUnit\Framework\Attributes\DataProvider;
use WorDBless\BaseTestCase;

/**
 * Locks bucket boundaries so dashboards built on these labels don't
 * silently shift when a future change adjusts the bucketer.
 */
class BucketsTest extends BaseTestCase {

	/**
	 * Post-types-count bucket boundaries.
	 *
	 * @return array<string, array{int, string}>
	 */
	public static function post_types_cases(): array {
		return array(
			'zero'  => array( 0, '0' ),
			'one'   => array( 1, '1' ),
			'two'   => array( 2, '2-3' ),
			'three' => array( 3, '2-3' ),
			'four'  => array( 4, '4-5' ),
			'five'  => array( 5, '4-5' ),
			'six'   => array( 6, '6+' ),
			'huge'  => array( 50, '6+' ),
		);
	}

	/**
	 * Each post-types-count maps to the documented bucket label.
	 *
	 * @dataProvider post_types_cases
	 *
	 * @param int    $count    Raw count of federated post types.
	 * @param string $expected Expected bucket label.
	 */
	#[DataProvider( 'post_types_cases' )]
	public function test_post_types_count_buckets( int $count, string $expected ): void {
		$this->assertSame( $expected, Buckets::post_types_count( $count ) );
	}

	/**
	 * Day-window bucket boundaries (used by both publish and interaction).
	 *
	 * @return array<string, array{int, string}>
	 */
	public static function day_window_cases(): array {
		return array(
			'negative'    => array( -1, '0-1' ),
			'zero'        => array( 0, '0-1' ),
			'one'         => array( 1, '0-1' ),
			'two'         => array( 2, '1-7' ),
			'seven'       => array( 7, '1-7' ),
			'eight'       => array( 8, '7-14' ),
			'fourteen'    => array( 14, '7-14' ),
			'fifteen'     => array( 15, '14+' ),
			'one_hundred' => array( 100, '14+' ),
		);
	}

	/**
	 * Each day count maps to the publish-window bucket.
	 *
	 * @dataProvider day_window_cases
	 *
	 * @param int    $days     Whole days since publish.
	 * @param string $expected Expected bucket label.
	 */
	#[DataProvider( 'day_window_cases' )]
	public function test_days_since_publish_buckets( int $days, string $expected ): void {
		$this->assertSame( $expected, Buckets::days_since_publish( $days ) );
	}

	/**
	 * Each day count maps to the interaction-window bucket.
	 *
	 * @dataProvider day_window_cases
	 *
	 * @param int    $days     Whole days since interaction.
	 * @param string $expected Expected bucket label.
	 */
	#[DataProvider( 'day_window_cases' )]
	public function test_days_since_interaction_buckets( int $days, string $expected ): void {
		$this->assertSame( $expected, Buckets::days_since_interaction( $days ) );
	}
}

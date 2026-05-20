<?php
/**
 * Minimal WP_CLI shim for unit tests.
 *
 * @package Automattic\Fosse\Tests
 *
 * phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase
 */

if ( class_exists( '\\WP_CLI', false ) ) {
	return;
}

/**
 * Drop-in replacement for the WP_CLI runtime that records calls
 * into in-process buffers so tests can assert against them. Throws
 * on `error()` so tests can catch the non-zero-exit contract.
 *
 * Only the surface that {@see \Automattic\Fosse\Blurhash_CLI} touches
 * is implemented — keeps the shim minimal and easy to audit.
 */
class WP_CLI {

	/**
	 * Recorded `log()` lines in call order.
	 *
	 * @var array<int, string>
	 */
	private static array $logs = array();

	/**
	 * Recorded `warning()` lines in call order.
	 *
	 * @var array<int, string>
	 */
	private static array $warnings = array();

	/**
	 * Recorded `success()` lines in call order.
	 *
	 * @var array<int, string>
	 */
	private static array $successes = array();

	/**
	 * Recorded `add_command()` invocations as `[name, callable]` pairs.
	 *
	 * @var array<int, array{0:string, 1:mixed}>
	 */
	private static array $commands = array();

	/**
	 * Record a log line.
	 *
	 * @param string $message Log message.
	 */
	public static function log( string $message ): void {
		self::$logs[] = $message;
	}

	/**
	 * Record a warning line.
	 *
	 * @param string $message Warning message.
	 */
	public static function warning( string $message ): void {
		self::$warnings[] = $message;
	}

	/**
	 * Record a success line.
	 *
	 * @param string $message Success message.
	 */
	public static function success( string $message ): void {
		self::$successes[] = $message;
	}

	/**
	 * Real WP_CLI calls `exit(1)` here. We throw instead so tests
	 * can assert the non-zero-exit contract without aborting PHPUnit.
	 *
	 * @param string $message Error message.
	 * @throws \RuntimeException Always, so tests can catch and assert.
	 */
	public static function error( string $message ): void {
		throw new \RuntimeException( $message );
	}

	/**
	 * Record an add_command call.
	 *
	 * @param string $name     Command name.
	 * @param mixed  $callable Command class name or callable.
	 */
	public static function add_command( string $name, $callable ): void {
		self::$commands[] = array( $name, $callable );
	}

	/**
	 * Reset all buffers between tests.
	 */
	public static function reset(): void {
		self::$logs      = array();
		self::$warnings  = array();
		self::$successes = array();
		self::$commands  = array();
	}

	/**
	 * All recorded log lines.
	 *
	 * @return array<int, string>
	 */
	public static function logs(): array {
		return self::$logs;
	}

	/**
	 * All recorded warning lines.
	 *
	 * @return array<int, string>
	 */
	public static function warnings(): array {
		return self::$warnings;
	}

	/**
	 * All recorded success lines.
	 *
	 * @return array<int, string>
	 */
	public static function successes(): array {
		return self::$successes;
	}

	/**
	 * The most recent success line, or null if none recorded.
	 *
	 * @return string|null
	 */
	public static function last_success(): ?string {
		$last = end( self::$successes );
		return false === $last ? null : $last;
	}

	/**
	 * All recorded add_command pairs.
	 *
	 * @return array<int, array{0:string, 1:mixed}>
	 */
	public static function commands(): array {
		return self::$commands;
	}
}

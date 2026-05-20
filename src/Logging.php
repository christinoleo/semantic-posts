<?php
/**
 * Centralized log formatter.
 *
 * Single entry point for all plugin log lines so the prefix and JSON shape
 * stay consistent. The `info` level is gated behind the `SEMANTIC_POSTS_VERBOSE`
 * constant so the steady-state debug.log is not flooded — error/warn always emit.
 *
 * @package SemanticPosts
 */

declare( strict_types=1 );

namespace SemanticPosts;

/**
 * Centralized log formatter. See file header for level gating.
 */
final class Logging {

	/**
	 * Emit an ERROR-level line. Always written regardless of SEMANTIC_POSTS_VERBOSE.
	 *
	 * @param string              $message Human-readable summary.
	 * @param array<string,mixed> $context Structured context appended as JSON.
	 */
	public static function error( string $message, array $context = array() ): void {
		self::emit( 'ERROR', $message, $context );
	}

	/**
	 * Emit a WARN-level line. Always written.
	 *
	 * @param string              $message Human-readable summary.
	 * @param array<string,mixed> $context Structured context appended as JSON.
	 */
	public static function warn( string $message, array $context = array() ): void {
		self::emit( 'WARN', $message, $context );
	}

	/**
	 * Emit an INFO-level line. Suppressed unless SEMANTIC_POSTS_VERBOSE is truthy.
	 *
	 * @param string              $message Human-readable summary.
	 * @param array<string,mixed> $context Structured context appended as JSON.
	 */
	public static function info( string $message, array $context = array() ): void {
		if ( ! defined( 'SEMANTIC_POSTS_VERBOSE' ) || ! SEMANTIC_POSTS_VERBOSE ) {
			return;
		}
		self::emit( 'INFO', $message, $context );
	}

	/**
	 * Render and write a single log line. JSON context is omitted when empty so
	 * trailing whitespace/braces don't pollute grep output.
	 *
	 * @param string              $level   Upper-case level tag.
	 * @param string              $message Message body.
	 * @param array<string,mixed> $context Context payload.
	 */
	private static function emit( string $level, string $message, array $context ): void {
		$line = sprintf( '[SemanticPosts][%s] %s', $level, $message );
		if ( ! empty( $context ) ) {
			$encoded = wp_json_encode( $context );
			if ( false !== $encoded ) {
				$line .= ' ' . $encoded;
			}
		}
		error_log( $line ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
	}
}

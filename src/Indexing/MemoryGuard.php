<?php
/**
 * Memory-budget guard for the cron tick.
 *
 * Reference env (PRD §9.5) sets WP_MEMORY_LIMIT=256M. We halt at 80% (~205 MB)
 * to leave headroom for the final state persist + WP shutdown housekeeping
 * (transient cleanups, shutdown actions registered by other plugins).
 *
 * Time and memory functions are injected so tests can drive deterministic
 * scenarios without polluting global state.
 *
 * @package SemanticPosts\Indexing
 */

declare( strict_types=1 );

namespace SemanticPosts\Indexing;

class MemoryGuard {

	/**
	 * Halt when current usage exceeds this fraction of the limit.
	 */
	public const THRESHOLD = 0.80;

	/**
	 * Reads current memory usage in bytes.
	 *
	 * @var callable():int
	 */
	private $usage_reader;

	/**
	 * Configured memory limit in bytes. Pinned at construction so successive
	 * calls don't re-parse the ini value.
	 *
	 * @var int
	 */
	private int $limit_bytes;

	/**
	 * @param int|null      $limit_bytes  Override for testing; null = read from WP_MEMORY_LIMIT / ini.
	 * @param callable|null $usage_reader Override for testing; null = memory_get_usage.
	 */
	public function __construct( ?int $limit_bytes = null, ?callable $usage_reader = null ) {
		$this->limit_bytes  = $limit_bytes ?? $this->detect_limit_bytes();
		$this->usage_reader = $usage_reader ?? static fn(): int => memory_get_usage();
	}

	/**
	 * True when current memory usage has crossed THRESHOLD * limit.
	 */
	public function should_halt(): bool {
		if ( $this->limit_bytes <= 0 ) {
			// Unlimited memory configured (-1) — never halt on memory.
			return false;
		}
		$usage = (int) ( $this->usage_reader )();
		return $usage >= (int) ( self::THRESHOLD * $this->limit_bytes );
	}

	/**
	 * @return int Effective memory limit in bytes; -1 means unlimited.
	 */
	public function limit_bytes(): int {
		return $this->limit_bytes;
	}

	/**
	 * Resolve the effective memory limit. Prefers WP_MEMORY_LIMIT (the value WP
	 * actually enforces during admin/cron) over ini's memory_limit.
	 */
	private function detect_limit_bytes(): int {
		$limit = defined( 'WP_MEMORY_LIMIT' ) ? (string) WP_MEMORY_LIMIT : '';
		if ( '' === $limit ) {
			$limit = (string) ini_get( 'memory_limit' );
		}
		return $this->parse_size( $limit );
	}

	/**
	 * Parse `256M` / `1G` / `512K` / `-1` / `1048576` into bytes.
	 *
	 * @param string $size Raw memory_limit-style string.
	 */
	private function parse_size( string $size ): int {
		$size = trim( $size );
		if ( '' === $size ) {
			return 0;
		}
		if ( '-1' === $size ) {
			return -1;
		}
		$unit = strtoupper( substr( $size, -1 ) );
		$num  = (float) $size;
		switch ( $unit ) {
			case 'G':
				return (int) ( $num * 1024 * 1024 * 1024 );
			case 'M':
				return (int) ( $num * 1024 * 1024 );
			case 'K':
				return (int) ( $num * 1024 );
			default:
				return (int) $num;
		}
	}
}

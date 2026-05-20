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
	 * Resolve the effective memory limit.
	 *
	 * Reads `ini_get('memory_limit')` first because, by the time this code runs
	 * (admin / cron / WP-CLI), WP has already raised the limit via
	 * `wp_raise_memory_limit('admin')` from the default 40 MB `WP_MEMORY_LIMIT`
	 * (frontend cap) to `WP_MAX_MEMORY_LIMIT` (256 MB default for admin) or
	 * higher under WP-CLI. Reading the constant directly would short-circuit
	 * the tick on every host that uses WP defaults — see the v0.1.3 incident.
	 *
	 * Falls back to `WP_MAX_MEMORY_LIMIT` then `WP_MEMORY_LIMIT` when ini is
	 * unset.
	 */
	private function detect_limit_bytes(): int {
		$ini = (string) ini_get( 'memory_limit' );
		if ( '' !== $ini && '0' !== $ini ) {
			return $this->parse_size( $ini );
		}
		if ( defined( 'WP_MAX_MEMORY_LIMIT' ) ) {
			return $this->parse_size( (string) WP_MAX_MEMORY_LIMIT );
		}
		if ( defined( 'WP_MEMORY_LIMIT' ) ) {
			return $this->parse_size( (string) WP_MEMORY_LIMIT );
		}
		return 0;
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

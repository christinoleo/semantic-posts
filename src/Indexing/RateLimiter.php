<?php
/**
 * Enforce a minimum gap between consecutive Provider::embed calls.
 *
 * OpenAI's default rate limit on the embeddings endpoint is generous (3500 RPM
 * for new accounts) but we throttle to 1 req/sec in-process to:
 *   1. Stay polite to shared hosts (many will throttle outbound HTTP themselves).
 *   2. Keep cron-tick CPU usage low — back-to-back HTTPS handshakes saturate
 *      a 1 vCPU reference host.
 *
 * Time and sleep are injected so unit tests can advance the clock without
 * actually blocking.
 *
 * @package SemanticPosts\Indexing
 */

declare( strict_types=1 );

namespace SemanticPosts\Indexing;

class RateLimiter {

	/**
	 * Minimum gap between adjacent calls, in seconds.
	 */
	public const MIN_GAP_SECONDS = 1.0;

	/**
	 * Returns the current time in seconds (float).
	 *
	 * @var callable():float
	 */
	private $clock;

	/**
	 * Sleeps for the given number of seconds (float). Default uses `usleep` for
	 * sub-second resolution.
	 *
	 * @var callable(float):void
	 */
	private $sleeper;

	/**
	 * @var float|null Wall-clock timestamp of the most recent `wait()` return.
	 */
	private ?float $last_call_at = null;

	/**
	 * @param callable():float|null     $clock   Override for test injection.
	 * @param callable(float):void|null $sleeper Override for test injection.
	 */
	public function __construct( ?callable $clock = null, ?callable $sleeper = null ) {
		$this->clock   = $clock ?? static fn(): float => microtime( true );
		$this->sleeper = $sleeper ?? static function ( float $seconds ): void {
			if ( $seconds > 0.0 ) {
				usleep( (int) ( $seconds * 1_000_000 ) );
			}
		};
	}

	/**
	 * Block (or microsleep) until at least MIN_GAP_SECONDS have elapsed since
	 * the previous wait(). First call returns immediately.
	 */
	public function wait(): void {
		$now = ( $this->clock )();

		if ( null !== $this->last_call_at ) {
			$elapsed = $now - $this->last_call_at;
			$gap     = self::MIN_GAP_SECONDS - $elapsed;
			if ( $gap > 0.0 ) {
				( $this->sleeper )( $gap );
				$now += $gap;
			}
		}

		$this->last_call_at = $now;
	}
}

<?php
/**
 * @package SemanticPosts\Tests
 */

declare( strict_types=1 );

namespace SemanticPosts\Tests\Indexing;

use PHPUnit\Framework\TestCase;
use SemanticPosts\Indexing\RateLimiter;

final class RateLimiterTest extends TestCase {

	public function test_first_call_does_not_sleep(): void {
		$now    = 1000.0;
		$slept  = array();
		$rl     = new RateLimiter(
			static function () use ( &$now ): float {
				return $now;
			},
			static function ( float $seconds ) use ( &$slept ): void {
				$slept[] = $seconds;
			}
		);

		$rl->wait();
		$this->assertSame( array(), $slept );
	}

	public function test_second_call_sleeps_when_under_one_second_elapsed(): void {
		$now   = 1000.0;
		$slept = array();
		$rl    = new RateLimiter(
			static function () use ( &$now ): float {
				return $now;
			},
			static function ( float $seconds ) use ( &$slept ): void {
				$slept[] = $seconds;
			}
		);

		$rl->wait();
		// Only 0.3 seconds elapsed.
		$now = 1000.3;
		$rl->wait();

		$this->assertCount( 1, $slept );
		$this->assertEqualsWithDelta( 0.7, $slept[0], 1e-9 );
	}

	public function test_no_sleep_when_more_than_one_second_elapsed(): void {
		$now   = 1000.0;
		$slept = array();
		$rl    = new RateLimiter(
			static function () use ( &$now ): float {
				return $now;
			},
			static function ( float $seconds ) use ( &$slept ): void {
				$slept[] = $seconds;
			}
		);

		$rl->wait();
		$now = 1002.5; // 2.5s elapsed.
		$rl->wait();

		$this->assertSame( array(), $slept );
	}

	public function test_third_call_resumes_after_second_sleep_completes(): void {
		$now   = 1000.0;
		$slept = array();
		$rl    = new RateLimiter(
			static function () use ( &$now ): float {
				return $now;
			},
			static function ( float $seconds ) use ( &$slept ): void {
				$slept[] = $seconds;
			}
		);

		$rl->wait(); // first call, no sleep
		$now = 1000.3;
		$rl->wait(); // sleeps 0.7
		// After the sleep, the limiter thinks now = 1000.3 + 0.7 = 1001.0.
		// Real wall-clock advanced to 1001.2 → only 0.2s since last_call_at.
		$now = 1001.2;
		$rl->wait();

		$this->assertCount( 2, $slept );
		$this->assertEqualsWithDelta( 0.7, $slept[0], 1e-9 );
		$this->assertEqualsWithDelta( 0.8, $slept[1], 1e-9 );
	}
}

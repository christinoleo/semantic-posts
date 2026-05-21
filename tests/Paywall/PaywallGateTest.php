<?php
/**
 * @package SemanticPosts\Tests
 */

declare( strict_types=1 );

namespace SemanticPosts\Tests\Paywall;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use SemanticPosts\Paywall\PaywallGate;

final class PaywallGateTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_limit_returns_default_when_filter_unset(): void {
		Functions\when( 'apply_filters' )->returnArg( 2 );
		$gate = new PaywallGate();
		$this->assertSame( PaywallGate::FREE_POST_LIMIT, $gate->limit() );
	}

	public function test_limit_filter_overrides_default(): void {
		Functions\when( 'apply_filters' )->alias(
			static function ( string $tag, $value ) {
				if ( 'semantic_posts_free_post_limit' === $tag ) {
					return 500;
				}
				return $value;
			}
		);
		$gate = new PaywallGate();
		$this->assertSame( 500, $gate->limit() );
	}

	public function test_limit_rejects_non_positive_filter_value(): void {
		Functions\when( 'apply_filters' )->alias(
			static function ( string $tag, $value ) {
				if ( 'semantic_posts_free_post_limit' === $tag ) {
					return -10;
				}
				return $value;
			}
		);
		$gate = new PaywallGate();
		$this->assertSame( PaywallGate::FREE_POST_LIMIT, $gate->limit() );
	}

	public function test_is_paying_false_when_sp_fs_missing(): void {
		Functions\when( 'apply_filters' )->returnArg( 2 );
		$gate = new PaywallGate();
		$this->assertFalse( $gate->is_paying() );
	}

	public function test_is_locked_under_limit_does_not_lock_even_when_not_paying(): void {
		Functions\when( 'apply_filters' )->returnArg( 2 );
		$gate = new PaywallGate();
		$this->assertFalse( $gate->is_locked( 50 ) );
		$this->assertFalse( $gate->is_locked( 199 ) );
	}

	public function test_is_locked_at_or_above_limit_when_not_paying(): void {
		Functions\when( 'apply_filters' )->returnArg( 2 );
		$gate = new PaywallGate();
		$this->assertTrue( $gate->is_locked( 200 ) );
		$this->assertTrue( $gate->is_locked( 201 ) );
		$this->assertTrue( $gate->is_locked( 10000 ) );
	}
}

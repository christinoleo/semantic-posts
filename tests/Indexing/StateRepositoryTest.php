<?php
/**
 * @package SemanticPosts\Tests
 */

declare( strict_types=1 );

namespace SemanticPosts\Tests\Indexing;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use SemanticPosts\Indexing\StateRepository;

final class StateRepositoryTest extends TestCase {

	/** @var array<string,mixed> */
	private array $store = array();

	/** @var array<string,bool> */
	private array $existed = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->store   = array();
		$this->existed = array();

		Functions\when( 'get_option' )->alias(
			function ( $key, $default = false ) {
				if ( ! array_key_exists( $key, $this->store ) ) {
					return $default;
				}
				return $this->store[ $key ];
			}
		);
		Functions\when( 'add_option' )->alias(
			function ( $key, $value /* , $deprecated, $autoload */ ) {
				if ( array_key_exists( $key, $this->store ) ) {
					return false;
				}
				$this->store[ $key ] = $value;
				$this->existed[ $key ] = true;
				return true;
			}
		);
		Functions\when( 'update_option' )->alias(
			function ( $key, $value ) {
				$this->store[ $key ] = $value;
				return true;
			}
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_read_returns_defaults_when_option_absent(): void {
		$state = ( new StateRepository() )->read();
		$this->assertSame( 0, $state['metrics']['succeeded'] );
		$this->assertSame( 0, $state['metrics']['failed'] );
		$this->assertSame( 0, $state['metrics']['retried'] );
		$this->assertSame( array(), $state['failed_posts'] );
	}

	public function test_read_merges_stored_partial_state_into_defaults(): void {
		$this->store[ StateRepository::OPTION_NAME ] = array(
			'metrics' => array( 'succeeded' => 42 ),
		);
		$state = ( new StateRepository() )->read();
		$this->assertSame( 42, $state['metrics']['succeeded'] );
		// Other counters keep their defaults.
		$this->assertSame( 0, $state['metrics']['failed'] );
	}

	public function test_mark_post_failed_writes_to_failed_posts_and_bumps_counter(): void {
		$repo = new StateRepository();
		$repo->mark_post_failed( 123 );

		$state = $repo->read();
		$this->assertArrayHasKey( '123', $state['failed_posts'] );
		$this->assertSame( 1, $state['metrics']['failed'] );
	}

	public function test_record_success_bumps_succeeded(): void {
		$repo = new StateRepository();
		$repo->record_success();
		$repo->record_success();

		$this->assertSame( 2, $repo->read()['metrics']['succeeded'] );
	}

	public function test_record_retry_bumps_retried(): void {
		$repo = new StateRepository();
		$repo->record_retry();
		$this->assertSame( 1, $repo->read()['metrics']['retried'] );
	}

	public function test_garbage_stored_value_does_not_crash(): void {
		$this->store[ StateRepository::OPTION_NAME ] = 'a string, not an array';
		$state                                       = ( new StateRepository() )->read();
		$this->assertSame( 0, $state['metrics']['succeeded'] );
	}
}

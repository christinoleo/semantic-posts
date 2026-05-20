<?php
/**
 * @package SemanticPosts\Tests
 */

declare( strict_types=1 );

namespace SemanticPosts\Tests;

use Brain\Monkey;
use Brain\Monkey\Actions;
use PHPUnit\Framework\TestCase;
use SemanticPosts\Bootstrap;

final class BootstrapTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		// Reset Bootstrap singleton between tests via reflection — it's intentionally
		// not test-aware so we don't add prod-only seams.
		$ref = new \ReflectionClass( Bootstrap::class );
		$prop = $ref->getProperty( 'instance' );
		$prop->setAccessible( true );
		$prop->setValue( null, null );
		parent::tearDown();
	}

	public function test_instance_returns_singleton(): void {
		$a = Bootstrap::instance();
		$b = Bootstrap::instance();
		$this->assertSame( $a, $b );
	}

	public function test_register_hooks_attaches_init_action_for_textdomain(): void {
		$added = array();
		Actions\expectAdded( 'init' )
			->once()
			->whenHappen( static function ( $callback ) use ( &$added ) {
				$added[] = $callback;
			} );

		Bootstrap::instance()->registerHooks();

		$this->assertCount( 1, $added, 'Expected exactly one init action registration.' );
		$this->assertIsArray( $added[0], 'init callback should be array($this, method).' );
		$this->assertSame( 'load_textdomain', $added[0][1] );
	}

	public function test_register_hooks_is_idempotent(): void {
		$call_count = 0;
		Actions\expectAdded( 'init' )
			->once()
			->whenHappen( static function () use ( &$call_count ) {
				++$call_count;
			} );

		$boot = Bootstrap::instance();
		$boot->registerHooks();
		$boot->registerHooks();
		$boot->registerHooks();

		$this->assertSame( 1, $call_count, 'registerHooks must be idempotent.' );
	}
}

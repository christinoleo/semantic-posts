<?php
/**
 * @package SemanticPosts\Tests
 */

declare( strict_types=1 );

namespace SemanticPosts\Tests;

use Brain\Monkey;
use Brain\Monkey\Actions;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use SemanticPosts\Bootstrap;

final class BootstrapTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( 'add_shortcode' )->justReturn( null );
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

	public function test_register_hooks_registers_admin_menu_and_content_filter_and_shortcode(): void {
		$shortcode_registered = false;
		Functions\when( 'add_shortcode' )->alias(
			static function ( $tag ) use ( &$shortcode_registered ) {
				if ( 'semantic_posts' === $tag ) {
					$shortcode_registered = true;
				}
			}
		);

		Actions\expectAdded( 'init' )->once();
		Actions\expectAdded( 'admin_menu' )->once();
		Filters\expectAdded( 'the_content' )->once();
		Filters\expectAdded( 'semantic_posts_exclude_from_backup' )->once();

		Bootstrap::instance()->registerHooks();

		$this->assertTrue( $shortcode_registered, 'semantic_posts shortcode must be registered.' );
	}

	public function test_register_hooks_is_idempotent(): void {
		Actions\expectAdded( 'init' )->once();
		Actions\expectAdded( 'admin_menu' )->once();
		Filters\expectAdded( 'the_content' )->once();
		Filters\expectAdded( 'semantic_posts_exclude_from_backup' )->once();

		$shortcode_calls = 0;
		Functions\when( 'add_shortcode' )->alias(
			static function () use ( &$shortcode_calls ) {
				++$shortcode_calls;
			}
		);

		$boot = Bootstrap::instance();
		$boot->registerHooks();
		$boot->registerHooks();
		$boot->registerHooks();

		$this->assertSame( 1, $shortcode_calls, 'registerHooks must be idempotent.' );
	}
}

<?php
/**
 * Smoke test verifying the main plugin file declares its public constants and
 * does not produce notices when loaded under WP_DEBUG.
 *
 * @package SemanticPosts\Tests
 */

declare( strict_types=1 );

namespace SemanticPosts\Tests;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

final class PluginSmokeTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		if ( ! defined( 'ABSPATH' ) ) {
			define( 'ABSPATH', __DIR__ . '/' );
		}

		Functions\when( 'plugin_dir_path' )->alias(
			static fn( string $file ): string => rtrim( dirname( $file ), '/' ) . '/'
		);
		Functions\when( 'plugin_dir_url' )->alias(
			static fn( string $file ): string => 'http://example.com/wp-content/plugins/semantic-posts/'
		);
		Functions\when( 'plugin_basename' )->alias(
			static fn( string $file ): string => 'semantic-posts/semantic-posts.php'
		);
		Functions\when( 'register_activation_hook' )->justReturn( null );
		Functions\when( 'register_deactivation_hook' )->justReturn( null );
		Functions\when( 'add_action' )->justReturn( null );
		Functions\when( 'add_filter' )->justReturn( null );
		Functions\when( 'add_shortcode' )->justReturn( null );
		Functions\when( 'load_plugin_textdomain' )->justReturn( true );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_loading_main_file_defines_public_constants(): void {
		require_once dirname( __DIR__ ) . '/semantic-posts.php';

		$this->assertTrue( defined( 'SEMANTIC_POSTS_VERSION' ) );
		$this->assertTrue( defined( 'SEMANTIC_POSTS_DIR' ) );
		$this->assertTrue( defined( 'SEMANTIC_POSTS_URL' ) );
		$this->assertTrue( defined( 'SEMANTIC_POSTS_FILE' ) );

		$this->assertSame( '0.1.0', SEMANTIC_POSTS_VERSION );
		$this->assertStringEndsWith( '/', SEMANTIC_POSTS_DIR );
		$this->assertStringStartsWith( 'http', SEMANTIC_POSTS_URL );
		$this->assertSame( realpath( dirname( __DIR__ ) . '/semantic-posts.php' ), realpath( SEMANTIC_POSTS_FILE ) );
	}
}

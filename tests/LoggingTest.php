<?php
/**
 * @package SemanticPosts\Tests
 */

declare( strict_types=1 );

namespace SemanticPosts\Tests;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use SemanticPosts\Logging;

final class LoggingTest extends TestCase {

	/** @var string[] */
	private array $log_lines = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->log_lines = array();

		Functions\when( 'wp_json_encode' )->alias(
			static fn( $value ) => json_encode( $value )
		);

		// Capture error_log output by redirecting via ini_set + tmpfile.
		$this->capture_error_log();
	}

	protected function tearDown(): void {
		ini_restore( 'error_log' );
		Monkey\tearDown();
		parent::tearDown();
	}

	private function capture_error_log(): void {
		$tmp = tempnam( sys_get_temp_dir(), 'sp-log-' );
		$this->assertNotFalse( $tmp );
		ini_set( 'error_log', $tmp );
		$this->log_file = $tmp;
	}

	private function read_captured(): string {
		return file_get_contents( $this->log_file ) ?: '';
	}

	private string $log_file = '';

	public function test_error_emits_with_prefix_and_level(): void {
		Logging::error( 'API call failed' );
		$captured = $this->read_captured();
		$this->assertStringContainsString( '[SemanticPosts][ERROR] API call failed', $captured );
	}

	public function test_warn_emits_with_prefix_and_level(): void {
		Logging::warn( 'retrying request' );
		$captured = $this->read_captured();
		$this->assertStringContainsString( '[SemanticPosts][WARN] retrying request', $captured );
	}

	public function test_info_is_suppressed_when_verbose_constant_undefined(): void {
		// SEMANTIC_POSTS_VERBOSE intentionally not defined.
		Logging::info( 'this should not appear' );
		$this->assertSame( '', $this->read_captured() );
	}

	public function test_info_is_suppressed_when_verbose_constant_false(): void {
		if ( ! defined( 'SEMANTIC_POSTS_VERBOSE' ) ) {
			define( 'SEMANTIC_POSTS_VERBOSE', false );
		}
		Logging::info( 'should not appear either' );
		$this->assertSame( '', $this->read_captured() );
	}

	public function test_error_appends_json_context_when_provided(): void {
		Logging::error( 'failed', array( 'post_id' => 42, 'status' => 503 ) );
		$captured = $this->read_captured();
		$this->assertStringContainsString( '[SemanticPosts][ERROR] failed', $captured );
		$this->assertStringContainsString( '"post_id":42', $captured );
		$this->assertStringContainsString( '"status":503', $captured );
	}

	public function test_empty_context_does_not_append_braces(): void {
		Logging::warn( 'no context' );
		$captured = trim( $this->read_captured() );
		$this->assertStringEndsWith( '[SemanticPosts][WARN] no context', $captured );
	}
}

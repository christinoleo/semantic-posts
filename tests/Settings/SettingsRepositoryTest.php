<?php
/**
 * @package SemanticPosts\Tests
 */

declare( strict_types=1 );

namespace SemanticPosts\Tests\Settings;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use SemanticPosts\Settings\SettingsRepository;

final class SettingsRepositoryTest extends TestCase {

	private array $option_store = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->option_store = array();

		Functions\when( 'get_option' )->alias(
			function ( $key, $default = false ) {
				return $this->option_store[ $key ] ?? $default;
			}
		);
		Functions\when( 'update_option' )->alias(
			function ( $key, $value ) {
				$this->option_store[ $key ] = $value;
				return true;
			}
		);
		Functions\when( 'sanitize_key' )->alias(
			static fn( $k ) => strtolower( (string) preg_replace( '/[^a-z0-9_\-]/i', '', (string) $k ) )
		);
		Functions\when( 'sanitize_text_field' )->alias(
			static fn( $v ) => trim( (string) preg_replace( '/\s+/u', ' ', (string) $v ) )
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_defaults_apply_when_no_option_stored(): void {
		$repo = new SettingsRepository();
		$this->assertSame( array( 'post' ), $repo->post_types() );
		$this->assertSame( SettingsRepository::MODE_AUTO_INJECT, $repo->display_mode() );
	}

	public function test_save_persists_sanitized_values(): void {
		$repo  = new SettingsRepository();
		$saved = $repo->save(
			array(
				'post_types'   => array( 'post', 'page' ),
				'display_mode' => SettingsRepository::MODE_SHORTCODE,
			)
		);

		$this->assertSame( array( 'post', 'page' ), $saved['post_types'] );
		$this->assertSame( SettingsRepository::MODE_SHORTCODE, $saved['display_mode'] );
		$this->assertSame( $saved, $repo->all() );
	}

	public function test_invalid_display_mode_falls_back_to_default(): void {
		$repo = new SettingsRepository();
		$this->option_store[ SettingsRepository::OPTION_NAME ] = array(
			'post_types'   => array( 'post' ),
			'display_mode' => 'totally-bogus',
		);
		$this->assertSame( SettingsRepository::MODE_AUTO_INJECT, $repo->display_mode() );
	}

	public function test_empty_post_types_falls_back_to_default(): void {
		$repo  = new SettingsRepository();
		$saved = $repo->save( array( 'post_types' => array() ) );
		$this->assertSame( array( 'post' ), $saved['post_types'] );
	}

	public function test_non_string_post_types_are_dropped(): void {
		$repo  = new SettingsRepository();
		$saved = $repo->save(
			array(
				'post_types' => array( 'post', 42, null, 'page', array( 'nested' ) ),
			)
		);
		$this->assertSame( array( 'post', 'page' ), $saved['post_types'] );
	}

	public function test_duplicate_post_types_are_collapsed(): void {
		$repo  = new SettingsRepository();
		$saved = $repo->save(
			array(
				'post_types' => array( 'post', 'post', 'page', 'post' ),
			)
		);
		$this->assertSame( array( 'post', 'page' ), $saved['post_types'] );
	}

	public function test_covers_post_type_reflects_stored_settings(): void {
		$repo = new SettingsRepository();
		$repo->save( array( 'post_types' => array( 'post' ) ) );
		$this->assertTrue( $repo->covers_post_type( 'post' ) );
		$this->assertFalse( $repo->covers_post_type( 'page' ) );
	}

	public function test_stored_garbage_does_not_crash_reads(): void {
		$this->option_store[ SettingsRepository::OPTION_NAME ] = 'a string, not an array';
		$repo = new SettingsRepository();
		$this->assertSame( array( 'post' ), $repo->post_types() );
		$this->assertSame( SettingsRepository::MODE_AUTO_INJECT, $repo->display_mode() );
	}
}

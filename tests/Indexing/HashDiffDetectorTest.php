<?php
/**
 * @package SemanticPosts\Tests
 */

declare( strict_types=1 );

namespace SemanticPosts\Tests\Indexing;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use SemanticPosts\Indexing\HashDiffDetector;

final class HashDiffDetectorTest extends TestCase {

	/** @var array<int,array<string,mixed>> */
	private array $meta = array();

	/** @var array<int,array<string,int>> */
	private array $deleted = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->meta    = array();
		$this->deleted = array();

		Functions\when( 'update_post_meta' )->alias(
			function ( $post_id, $key, $value ) {
				$this->meta[ (int) $post_id ][ (string) $key ] = $value;
				return true;
			}
		);
		Functions\when( 'delete_post_meta' )->alias(
			function ( $post_id, $key ) {
				unset( $this->meta[ (int) $post_id ][ (string) $key ] );
				$this->deleted[ (int) $post_id ][ (string) $key ] = ( $this->deleted[ (int) $post_id ][ (string) $key ] ?? 0 ) + 1;
				return true;
			}
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_write_hash_persists_text_hash(): void {
		( new HashDiffDetector() )->write_hash( 7, 'abc123' );
		$this->assertSame( 'abc123', $this->meta[7]['_sp_text_hash'] );
	}

	public function test_clear_dirty_deletes_dirty_meta(): void {
		// Seed dirty flag first.
		$this->meta[7]['_sp_dirty'] = '1';

		( new HashDiffDetector() )->clear_dirty( 7 );

		$this->assertArrayNotHasKey( '_sp_dirty', $this->meta[7] ?? array() );
		$this->assertSame( 1, $this->deleted[7]['_sp_dirty'] );
	}

	public function test_clear_dirty_is_idempotent_when_already_clean(): void {
		// No _sp_dirty seeded — clear_dirty should be a no-op (delete missing meta).
		( new HashDiffDetector() )->clear_dirty( 11 );
		$this->assertSame( 1, $this->deleted[11]['_sp_dirty'] );
	}
}

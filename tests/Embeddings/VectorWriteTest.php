<?php
/**
 * Coverage for the AR-10 single-writer helpers added to Vector for TB-06.
 *
 * @package SemanticPosts\Tests
 */

declare( strict_types=1 );

namespace SemanticPosts\Tests\Embeddings;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use SemanticPosts\Embeddings\Vector;

final class VectorWriteTest extends TestCase {

	/** @var array<int,array<string,string>> */
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
				$this->meta[ (int) $post_id ][ (string) $key ] = (string) $value;
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

	public function test_write_embedding_persists_encoded_vector(): void {
		Vector::write_embedding( 42, array( 0.1, 0.2, 0.3 ) );

		$encoded = $this->meta[42]['_sp_embedding'] ?? null;
		$this->assertNotNull( $encoded );

		// Encoded value must roundtrip via decode() into the same dimensions.
		$decoded = Vector::decode( $encoded );
		$this->assertSame( 3, $decoded->getSize() );
		$this->assertEqualsWithDelta( 0.1, $decoded[0], 1e-6 );
		$this->assertEqualsWithDelta( 0.2, $decoded[1], 1e-6 );
		$this->assertEqualsWithDelta( 0.3, $decoded[2], 1e-6 );
	}

	public function test_delete_embedding_removes_postmeta(): void {
		$this->meta[42]['_sp_embedding'] = 'whatever';
		Vector::delete_embedding( 42 );

		$this->assertArrayNotHasKey( '_sp_embedding', $this->meta[42] ?? array() );
		$this->assertSame( 1, $this->deleted[42]['_sp_embedding'] );
	}

	public function test_postmeta_key_constant_matches_owner_map(): void {
		// AR-10 owner map literally references '_sp_embedding'. If the constant
		// drifts, the sniff's KEY_OWNERS will still pass but the runtime write
		// will mismatch — pin both via this test.
		$this->assertSame( '_sp_embedding', Vector::POSTMETA_KEY );
	}
}

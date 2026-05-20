<?php
/**
 * @package SemanticPosts\Tests
 */

declare( strict_types=1 );

namespace SemanticPosts\Tests\Embeddings;

use LengthException;
use PHPUnit\Framework\TestCase;
use SemanticPosts\Embeddings\Vector;
use SplFixedArray;

final class VectorTest extends TestCase {

	public function test_encode_decode_roundtrip_preserves_values_within_float32_tolerance(): void {
		$input = array( 0.0, 1.0, -1.0, 0.5, -0.25, 3.14159, -2.71828 );

		$encoded = Vector::encode( $input );
		$decoded = Vector::decode( $encoded );

		$this->assertSame( count( $input ), $decoded->getSize() );
		foreach ( $input as $i => $expected ) {
			$this->assertEqualsWithDelta( $expected, $decoded[ $i ], 1e-6, "Element {$i} drift exceeds tolerance" );
		}
	}

	public function test_encode_yields_base64_payload(): void {
		$encoded = Vector::encode( array( 1.0, 2.0, 3.0 ) );
		$this->assertSame( base64_encode( base64_decode( $encoded, true ) ), $encoded );
	}

	public function test_decode_returns_empty_fixed_array_for_garbage_input(): void {
		$decoded = Vector::decode( '!!!not-base64!!!' );
		$this->assertSame( 0, $decoded->getSize() );
	}

	public function test_decode_returns_empty_for_byte_length_not_multiple_of_4(): void {
		// 5 raw bytes -> not a valid packed-float32 stream.
		$decoded = Vector::decode( base64_encode( 'abcde' ) );
		$this->assertSame( 0, $decoded->getSize() );
	}

	public function test_dot_identical_unit_vectors_equals_one(): void {
		$v   = SplFixedArray::fromArray( array( 1.0, 0.0, 0.0 ) );
		$dot = Vector::dot( $v, $v );
		$this->assertEqualsWithDelta( 1.0, $dot, 1e-9 );
	}

	public function test_dot_orthogonal_unit_vectors_equals_zero(): void {
		$a   = SplFixedArray::fromArray( array( 1.0, 0.0, 0.0 ) );
		$b   = SplFixedArray::fromArray( array( 0.0, 1.0, 0.0 ) );
		$dot = Vector::dot( $a, $b );
		$this->assertEqualsWithDelta( 0.0, $dot, 1e-9 );
	}

	public function test_dot_opposite_unit_vectors_equals_minus_one(): void {
		$a   = SplFixedArray::fromArray( array( 1.0, 0.0, 0.0 ) );
		$b   = SplFixedArray::fromArray( array( -1.0, 0.0, 0.0 ) );
		$dot = Vector::dot( $a, $b );
		$this->assertEqualsWithDelta( -1.0, $dot, 1e-9 );
	}

	public function test_dot_arbitrary_vectors_matches_hand_calculation(): void {
		$a = SplFixedArray::fromArray( array( 2.0, 3.0, 5.0 ) );
		$b = SplFixedArray::fromArray( array( -1.0, 4.0, 0.5 ) );
		// 2*-1 + 3*4 + 5*0.5 = -2 + 12 + 2.5 = 12.5
		$this->assertEqualsWithDelta( 12.5, Vector::dot( $a, $b ), 1e-9 );
	}

	public function test_dot_throws_on_dimension_mismatch(): void {
		$a = SplFixedArray::fromArray( array( 1.0, 2.0 ) );
		$b = SplFixedArray::fromArray( array( 1.0, 2.0, 3.0 ) );

		$this->expectException( LengthException::class );
		$this->expectExceptionMessage( 'dimension mismatch' );
		Vector::dot( $a, $b );
	}

	public function test_roundtrip_preserves_1536_dim_random_vector(): void {
		// Mimic OpenAI text-embedding-3-small dimensionality (1536). Use a fixed seed
		// so the test is deterministic.
		mt_srand( 42 );
		$input = array();
		for ( $i = 0; $i < 1536; $i++ ) {
			$input[] = ( mt_rand() / mt_getrandmax() ) * 2.0 - 1.0;
		}

		$decoded = Vector::decode( Vector::encode( $input ) );
		$this->assertSame( 1536, $decoded->getSize() );

		// Compute dot(input, decoded) and assert it is close to dot(input, input)
		// — a single drift check is cheaper than per-element asserts at 1536 dims.
		$ref_dot = 0.0;
		$rt_dot  = 0.0;
		foreach ( $input as $i => $value ) {
			$ref_dot += $value * $value;
			$rt_dot  += $value * $decoded[ $i ];
		}
		$this->assertEqualsWithDelta( $ref_dot, $rt_dot, 1e-3, 'Roundtrip dot drift exceeds tolerance at 1536-dim.' );
	}
}

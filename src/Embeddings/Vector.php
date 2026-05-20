<?php
/**
 * Embedding vector codec + cosine compute.
 *
 * Storage format (ADR-0003): float32, packed little-endian, base64 encoded for
 * postmeta safety. 1536-dimension OpenAI embeddings serialize to ~8 KB of
 * base64 text per post, which fits comfortably in WP postmeta.
 *
 * @package SemanticPosts\Embeddings
 */

declare( strict_types=1 );

namespace SemanticPosts\Embeddings;

use LengthException;
use SplFixedArray;

/**
 * Embedding vector codec + cosine compute. See class file header for storage format.
 */
final class Vector {

	public const POSTMETA_KEY = '_sp_embedding';

	/**
	 * Pack format: little-endian 32-bit float (`g` per `pack()`).
	 */
	private const PACK_FORMAT = 'g*';

	/**
	 * Unpack format: little-endian 32-bit float, repeated.
	 */
	private const UNPACK_FORMAT = 'g*';

	/**
	 * Each float32 occupies 4 bytes.
	 */
	private const FLOAT32_SIZE = 4;

	/**
	 * Encode a float vector to a base64-encoded packed-float32 string.
	 *
	 * @param float[] $floats Vector components.
	 * @return string Base64-encoded payload.
	 */
	public static function encode( array $floats ): string {
		// `pack('g*', ...)` consumes the entire variadic list and produces 4 bytes per float.
		$packed = pack( self::PACK_FORMAT, ...array_values( $floats ) );
		return base64_encode( $packed ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	}

	/**
	 * Decode a base64-encoded packed-float32 string into an SplFixedArray.
	 *
	 * SplFixedArray is used over a regular array because it is roughly 5x more
	 * memory-efficient for numeric data — critical for the indexing path where
	 * many vectors are decoded per tick.
	 *
	 * @param string $b64 Base64-encoded payload produced by encode().
	 * @return SplFixedArray<int,float>
	 */
	public static function decode( string $b64 ): SplFixedArray {
		$packed = base64_decode( $b64, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		if ( false === $packed ) {
			return new SplFixedArray( 0 );
		}

		$byte_length = strlen( $packed );
		if ( 0 !== $byte_length % self::FLOAT32_SIZE ) {
			return new SplFixedArray( 0 );
		}

		$dim    = intdiv( $byte_length, self::FLOAT32_SIZE );
		$values = unpack( self::UNPACK_FORMAT, $packed );
		if ( false === $values ) {
			return new SplFixedArray( 0 );
		}

		$fixed = new SplFixedArray( $dim );
		// `unpack` returns 1-indexed array.
		for ( $i = 0; $i < $dim; $i++ ) {
			$fixed[ $i ] = $values[ $i + 1 ];
		}
		return $fixed;
	}

	/**
	 * Compute the dot product of two equal-length vectors.
	 *
	 * Hot path — implemented as an explicit `for` loop because array_sum + array_map
	 * allocates an intermediate array per call (measured in the spike to be ~3x
	 * slower than this loop on 1536-dim vectors).
	 *
	 * @param SplFixedArray<int,float> $a First vector.
	 * @param SplFixedArray<int,float> $b Second vector.
	 * @return float
	 * @throws LengthException When the vectors have different lengths.
	 */
	public static function dot( SplFixedArray $a, SplFixedArray $b ): float {
		$len = $a->getSize();
		if ( $len !== $b->getSize() ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- exception messages go to logs, not HTML.
			throw new LengthException(
				sprintf( 'Vector::dot dimension mismatch: %d vs %d.', $len, $b->getSize() )
			);
		}

		$sum = 0.0;
		for ( $i = 0; $i < $len; $i++ ) {
			$sum += $a[ $i ] * $b[ $i ];
		}
		return $sum;
	}

	/**
	 * Single-writer entry for `_sp_embedding` postmeta. AR-10 invariant — no
	 * other code in the plugin calls `update_post_meta` with this key.
	 *
	 * @param int     $post_id Target post.
	 * @param float[] $floats  Vector to persist.
	 */
	public static function write_embedding( int $post_id, array $floats ): void {
		update_post_meta( $post_id, '_sp_embedding', self::encode( $floats ) );
	}

	/**
	 * Single-writer entry for deleting `_sp_embedding` postmeta. Used by the
	 * trash / status-away cleanup paths in TB-07.
	 *
	 * @param int $post_id Target post.
	 */
	public static function delete_embedding( int $post_id ): void {
		delete_post_meta( $post_id, '_sp_embedding' );
	}
}

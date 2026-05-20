<?php
/**
 * Single-writer owner of `_sp_text_hash` and `_sp_dirty` postmeta (AR-10).
 *
 * This slice (TB-06) ships only the write-side helpers EmbedJob needs after
 * a successful embed: store the just-embedded hash, clear the dirty flag.
 * TB-07 extends with `detect( WP_Post )` for the save_post hook + the
 * autosave / trash cleanup paths.
 *
 * @package SemanticPosts\Indexing
 */

declare( strict_types=1 );

namespace SemanticPosts\Indexing;

class HashDiffDetector {

	public const META_HASH  = '_sp_text_hash';
	public const META_DIRTY = '_sp_dirty';

	/**
	 * Persist the indexable-text hash that was just embedded. Future save_post
	 * events compare against this value to decide whether re-indexing is needed.
	 *
	 * @param int    $post_id Target post.
	 * @param string $hash    md5 of the indexable text that produced the embedding.
	 */
	public function write_hash( int $post_id, string $hash ): void {
		update_post_meta( $post_id, '_sp_text_hash', $hash );
	}

	/**
	 * Clear the dirty flag after a successful embed. Idempotent — deleting a
	 * missing meta row is a no-op in WP.
	 *
	 * @param int $post_id Target post.
	 */
	public function clear_dirty( int $post_id ): void {
		delete_post_meta( $post_id, '_sp_dirty' );
	}
}

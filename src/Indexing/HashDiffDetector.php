<?php
/**
 * Single-writer owner of `_sp_text_hash` and `_sp_dirty` postmeta (AR-10).
 *
 * Two flows live here:
 *   - `detect( WP_Post )` (TB-07): called from save_post — if the new indexable
 *     text differs from the previous, sets _sp_dirty=1 and updates the hash.
 *   - `write_hash` / `clear_dirty` (TB-06): called from EmbedJob after a
 *     successful provider call to record the just-embedded hash and drop
 *     the dirty flag.
 *
 * Note: `detect` writes the hash on diff so that an in-flight EmbedJob still
 * compares correctly even if save_post fires again before the job lands. The
 * post stays dirty (`_sp_dirty=1`) until EmbedJob succeeds.
 *
 * @package SemanticPosts\Indexing
 */

declare( strict_types=1 );

namespace SemanticPosts\Indexing;

use SemanticPosts\Embeddings\IndexableTextBuilder;
use WP_Post;

class HashDiffDetector {

	public const META_HASH  = '_sp_text_hash';
	public const META_DIRTY = '_sp_dirty';

	/** @var IndexableTextBuilder */
	private IndexableTextBuilder $builder;

	/**
	 * @param IndexableTextBuilder|null $builder Optional builder injection (test seam).
	 */
	public function __construct( ?IndexableTextBuilder $builder = null ) {
		$this->builder = $builder ?? new IndexableTextBuilder();
	}

	/**
	 * Compare the post's current indexable text against the stored hash.
	 *
	 * @param  WP_Post $post Source post.
	 * @return bool    True if a change was detected and the post was marked
	 *                 dirty; false if the hash was unchanged.
	 */
	public function detect( WP_Post $post ): bool {
		$text     = $this->builder->build( $post );
		$new_hash = md5( $text );
		$old_hash = (string) get_post_meta( $post->ID, '_sp_text_hash', true );

		if ( $new_hash === $old_hash ) {
			return false;
		}

		update_post_meta( $post->ID, '_sp_text_hash', $new_hash );
		update_post_meta( $post->ID, '_sp_dirty', 1 );
		return true;
	}

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
	 * Explicitly mark a post dirty. Used by Retry-failed (TB-13) to re-enqueue
	 * posts that previously exhausted their retry budget.
	 *
	 * @param int $post_id Target post.
	 */
	public function mark_dirty( int $post_id ): void {
		update_post_meta( $post_id, '_sp_dirty', 1 );
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

	/**
	 * Delete both hash + dirty flag — called from CleanupRouter on trash.
	 *
	 * @param int $post_id Target post.
	 */
	public function purge( int $post_id ): void {
		delete_post_meta( $post_id, '_sp_text_hash' );
		delete_post_meta( $post_id, '_sp_dirty' );
	}
}

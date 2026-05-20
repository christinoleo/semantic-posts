<?php
/**
 * Cleanup orchestrator for posts leaving the "indexed" set.
 *
 * Triggered by trash, password-add, or status-away-from-publish. Removes the
 * post's own `_sp_*` artifacts AND fixes up other posts that referenced it
 * in their `_sp_related` (so widgets don't render dangling links).
 *
 * Order of operations matters:
 *   1. Read the inbound list FIRST (we're about to delete it).
 *   2. Delete the post's own _sp_embedding / _sp_related / _sp_inbound /
 *      _sp_text_hash / _sp_dirty via the AR-10 owner classes.
 *   3. For each post in the inbound list, NeighborStore::remove_neighbor
 *      drops this post from their _sp_related row.
 *
 * @package SemanticPosts\Indexing
 */

declare( strict_types=1 );

namespace SemanticPosts\Indexing;

use SemanticPosts\Crawler\NeighborStore;
use SemanticPosts\Embeddings\Vector;

class CleanupRouter {

	/** @var NeighborStore */
	private NeighborStore $neighbors;

	/** @var HashDiffDetector */
	private HashDiffDetector $hash;

	/**
	 * @param NeighborStore    $neighbors AR-10 owner of _sp_related + _sp_inbound.
	 * @param HashDiffDetector $hash      AR-10 owner of _sp_text_hash + _sp_dirty.
	 */
	public function __construct( NeighborStore $neighbors, HashDiffDetector $hash ) {
		$this->neighbors = $neighbors;
		$this->hash      = $hash;
	}

	/**
	 * Remove every plugin artifact for `$post_id` and invalidate dependents.
	 *
	 * @param int $post_id Post leaving the indexed set.
	 */
	public function cleanup( int $post_id ): void {
		// Read inbound BEFORE deleting it; we'll use the list to fix dependents.
		$inbound = $this->neighbors->read_inbound( $post_id );

		Vector::delete_embedding( $post_id );
		$this->neighbors->delete_for_post( $post_id );
		$this->hash->purge( $post_id );

		foreach ( $inbound as $dependent_id ) {
			if ( $dependent_id === $post_id ) {
				continue;
			}
			$this->neighbors->remove_neighbor( $dependent_id, $post_id );
		}
	}
}

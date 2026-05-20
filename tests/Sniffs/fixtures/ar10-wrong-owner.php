<?php
/**
 * AR-10 fixture: writes `_sp_embedding` from a class that is NOT `Vector`.
 *
 * Expected: SemanticPosts.PostMeta.SingleWriter.WrongOwner
 */

namespace SemanticPosts\Domain;

class NotTheVectorOwner {
	public function bad_write( int $post_id, string $blob ): void {
		update_post_meta( $post_id, '_sp_embedding', $blob );
	}
}
